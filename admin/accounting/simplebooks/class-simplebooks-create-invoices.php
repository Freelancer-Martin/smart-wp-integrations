<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Simplebooks arve loomine WooCommerce orderist.
 * Saadab orderi andmed vaheserveri kaudu Simplebooks API-le.
 */
class SWI_Simplebooks_Create_Invoices {

    public function __construct() {
        $status = get_option( 'swi_simplebooks_order_status', 'wc-completed' );
        $hook   = 'woocommerce_order_status_' . ltrim( $status, 'wc-' );
        add_action( $hook, [ $this, 'auto_send_order' ], 20, 1 );
    }

    /**
     * Käivitatakse kui order jõuab seadistatud staatusesse.
     */
    public function auto_send_order( int $order_id ): void {
        if ( get_option( 'swi_simplebooks_enable' ) !== 'yes' ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        if ( $order->get_meta( '_swi_sent_simplebooks' ) ) {
            return;
        }

        $payload = $this->build_payload( $order );
        if ( ! $payload ) {
            return;
        }

        $res = LocalApiClient::sendEncryptedOrder( $payload, 'simplebooks' );

        if ( isset( $res['status'] ) && in_array( $res['status'], [ 'ok', 'queued' ], true ) ) {
            $order->update_meta_data( '_swi_sent_simplebooks', current_time( 'mysql' ) );
            $order->save();
            $order->add_order_note( 'Simplebooks: arve edastatud.' );
        } else {
            $msg = $res['message'] ?? wp_json_encode( $res );
            $order->add_order_note( 'Simplebooks: edastamine ebaõnnestus — ' . $msg );
            error_log( 'SWI Simplebooks auto-send failed order ' . $order_id . ': ' . wp_json_encode( $res ) );
            $order->update_meta_data( '_swi_simplebooks_retry', '1' );
            $order->update_meta_data( '_swi_simplebooks_retry_count', 0 );
            $order->save();
        }
    }

    /**
     * Ehitab Simplebooks payload WooCommerce orderist.
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
            $tax_pct   = ( $subtotal > 0 ) ? round( $tax_total / $subtotal * 100, 2 ) : 0;

            $items[] = [
                'article_id'     => $sku ?: 'ITEM',
                'name'           => $item->get_name(),
                'unit'           => 'tk',
                'amount'         => $qty,
                'price_per_unit' => $qty > 0 ? round( $subtotal / $qty, 4 ) : 0,
                'vat'            => $tax_pct,
            ];
        }

        // Tarnekulud
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

        if ( empty( $items ) ) {
            return null;
        }

        $created_at = $order->get_date_created();
        $date       = $created_at ? $created_at->format( 'Y-m-d' ) : current_time( 'Y-m-d' );
        $deadline   = (int) get_option( 'smart_wp_integtaion_maksetahtaeg', 14 );
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
