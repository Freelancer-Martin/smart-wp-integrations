<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Simplebooks arve loomine WooCommerce orderist.
 *
 * Simplebooks on Eesti raamatupidamistarkvara, mis kasutab erinevalt Merit Aktivast
 * lihtsamat X-Simplebooks-Token autentimist. Plugin saadab andmed samuti läbi
 * Laravel-i vaheserveri — LocalApiClient::sendEncryptedOrder() krüpteerib payload
 * AES-256-GCM-iga enne edastamist.
 *
 * Klass registreerib WC staatusepõhise hooki ja haldab retry-lipud eraldi
 * '_swi_sent_simplebooks' ja '_swi_simplebooks_retry' meta-võtmete all,
 * et Merit ja Simplebooks saatmised ei segaks teineteist.
 *
 * @package Smart_Wp_Integrations
 */
class SWI_Simplebooks_Create_Invoices {

    /**
     * Registreerib WooCommerce automaatse saatmise hooki konfigureeritavale staatusele.
     *
     * Hooki nimi konstrueeritakse dünaamiliselt seadistest loetud staatuse põhjal —
     * nt 'wc-completed' → hook 'woocommerce_order_status_completed'.
     * ltrim eemaldab 'wc-' prefiksi, mida WooCommerce oma hookides ei kasuta.
     * Prioriteet 20 tagab, et see käivitub pärast WC standardseid hookisid.
     */
    public function __construct() {
        $status = get_option( 'swi_simplebooks_order_status', 'wc-completed' );
        $hook   = 'woocommerce_order_status_' . ltrim( $status, 'wc-' );
        add_action( $hook, [ $this, 'auto_send_order' ], 20, 1 );
    }

    /**
     * Saadab WooCommerce orderi automaatselt Simplebooks'i kui staatus muutub.
     *
     * '_swi_sent_simplebooks' meta-flag väldib topelt saatmist kui hook käivitub
     * mitu korda (nt admin muudab staatust käsitsi edasi-tagasi).
     * Ebaõnnestumisel märgitakse order retry-ks — Simplebooks'il puudub Merit
     * Aktiva sarnane eraldi cron, seega retry tuleb lisada eraldi kui vaja.
     *
     * @param int $order_id WooCommerce tellimuse ID.
     */
    public function auto_send_order( int $order_id ): void {
        if ( get_option( 'swi_simplebooks_enable' ) !== 'yes' ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // HPOS-ühilduv: get_meta() töötab nii vana postmeta kui uue wc_orders_meta tabeliga
        if ( $order->get_meta( '_swi_sent_simplebooks' ) ) {
            return;
        }

        $payload = $this->build_payload( $order );
        if ( ! $payload ) {
            return;
        }

        // 'simplebooks' parameeter suunab Laravel-is SimplebooksHandler-i, mitte MeritHandler-i
        $res = LocalApiClient::sendEncryptedOrder( $payload, 'simplebooks' );

        if ( isset( $res['status'] ) && in_array( $res['status'], [ 'ok', 'queued' ], true ) ) {
            $order->update_meta_data( '_swi_sent_simplebooks', current_time( 'mysql' ) );
            $order->save();
            $order->add_order_note( 'Simplebooks: arve edastatud.' );
        } else {
            $msg = $res['message'] ?? wp_json_encode( $res );
            $order->add_order_note( 'Simplebooks: edastamine ebaõnnestus — ' . $msg );
            error_log( 'SWI Simplebooks auto-send failed order ' . $order_id . ': ' . wp_json_encode( $res ) );
            // Märgi retry hilisemaks uuesti proovimiseks
            $order->update_meta_data( '_swi_simplebooks_retry', '1' );
            $order->update_meta_data( '_swi_simplebooks_retry_count', 0 );
            $order->save();
        }
    }

    /**
     * Ehitab Simplebooks API formaadis payload WooCommerce orderist.
     *
     * Simplebooks payload on lihtsam kui Merit Aktiva oma — ei nõua UUID-põhiseid
     * maksumäärasid ega eraldiseisvat TaxAmount massiivi. Käibemaks esitatakse
     * protsendina iga rea juures, mitte globaalsete UUID-dena.
     *
     * Arve number formaat: konfigureeritav eesliide + WC order ID (nt 'SB1234').
     * reg_no ja vat_no loetakse WC orderi meta-väljadest, mida saab lisada
     * näiteks WooCommerce Checkout Fields pluginaga.
     *
     * @param \WC_Order $order WooCommerce tellimuse objekt.
     * @return array|null Simplebooks payload või null kui orderil pole ridasi.
     */
    public function build_payload( \WC_Order $order ): ?array {
        $prefix = get_option( 'swi_simplebooks_prefix', 'SB' );

        $billing = [
            'first_name' => $order->get_billing_first_name(),
            'last_name'  => $order->get_billing_last_name(),
            'company'    => $order->get_billing_company(),
            'email'      => $order->get_billing_email(),
            'phone'      => $order->get_billing_phone(),
            'address'    => $order->get_billing_address_1(),
            'city'       => $order->get_billing_city(),
            'postcode'   => $order->get_billing_postcode(),
            'country'    => $order->get_billing_country(),
            // Proovitakse mõlemat meta-formaati (alajoone ja ilma) ühilduvuse tagamiseks
            'reg_no'     => $order->get_meta( '_billing_reg_no' ) ?: $order->get_meta( 'billing_reg_no' ) ?: '',
            'vat_no'     => $order->get_meta( '_billing_vat_no' )  ?: $order->get_meta( 'billing_vat_no' )  ?: '',
        ];

        $items = [];
        foreach ( $order->get_items() as $item ) {
            /** @var \WC_Order_Item_Product $item */
            $product   = $item->get_product();
            $sku       = $product ? $product->get_sku() : '';
            $tax_total = (float) $item->get_total_tax();
            $subtotal  = (float) $item->get_total();
            $qty       = (int) $item->get_quantity();
            // Käibemaks arvutatakse protsendina rea netosummast, kuna Simplebooks nõuab seda nii
            $tax_pct   = ( $subtotal > 0 ) ? round( $tax_total / $subtotal * 100, 2 ) : 0;

            $items[] = [
                'article_id'     => $sku ?: 'ITEM',
                'name'           => $item->get_name(),
                'unit'           => 'tk',
                'amount'         => $qty,
                // Ühikuhind = kogusumma / kogus, 4 kümnendkohta täpsuse säilitamiseks
                'price_per_unit' => $qty > 0 ? round( $subtotal / $qty, 4 ) : 0,
                'vat'            => $tax_pct,
            ];
        }

        // Tarnekulud lisatakse eraldi reana ainult kui tarnehind > 0
        foreach ( $order->get_items( 'shipping' ) as $ship ) {
            $ship_total = (float) $ship->get_total();
            if ( $ship_total > 0 ) {
                $ship_tax  = (float) $ship->get_total_tax();
                $ship_vat  = ( $ship_total > 0 ) ? round( $ship_tax / $ship_total * 100, 2 ) : 0;
                $items[] = [
                    'article_id'     => 'TRANSPORT',
                    'name'           => $ship->get_name() ?: 'Tarne',
                    'unit'           => 'tk',
                    'amount'         => 1,
                    'price_per_unit' => $ship_total,
                    'vat'            => $ship_vat,
                ];
            }
        }

        // Kui orderil pole ühtegi rida, pole mõtet arvet luua
        if ( empty( $items ) ) {
            return null;
        }

        $created_at = $order->get_date_created();
        $date       = $created_at ? $created_at->format( 'Y-m-d' ) : current_time( 'Y-m-d' );
        $deadline   = (int) get_option( 'smart_wp_integtaion_maksetahtaeg', 14 );
        // Maksetähtaeg arvutatakse arve kuupäevast, mitte tänasest päevast
        $due        = date( 'Y-m-d', strtotime( '+' . $deadline . ' days', strtotime( $date ) ) );

        return [
            'order_id' => $order->get_id(),
            'number'   => $prefix . $order->get_id(),
            'date'     => $date,
            'due'      => $due,
            'currency' => $order->get_currency() ?: 'EUR',
            'total'    => (float) $order->get_total(),
            'total_tax'=> (float) $order->get_total_tax(),
            'billing'  => $billing,
            'items'    => $items,
        ];
    }
}
