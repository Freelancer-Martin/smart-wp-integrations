<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Merit Aktiva arve loomine WooCommerce orderist.
 *
 * Klass vastutab kolme asja eest:
 * 1. WooCommerce orderite filtreerimine (millised pole veel Meriti saadetud)
 * 2. Merit API formaadis payload ehitamine iga orderi jaoks
 * 3. Krüpteeritud andmete saatmine vaheserveri (Laravel) kaudu
 *
 * Klass EI suhtle Merit API-ga otse — kõik läheb läbi LocalApiClient::sendEncryptedOrder(),
 * mis krüpteerib payload AES-256-GCM-iga ja saadab Laravel-i vaheserverisse.
 *
 * @package Smart_Wp_Integrations
 */
class My_Simple_Ajax_Plugin {

    /** @var string Merit Aktiva maksumäära UUID (konfigureeritav seadetes) */
    private $tax_field;

    /** @var int Maksetähtaeg päevades (nt 14) */
    private $payment_deadline;

    /** @var string Ettevõtte registreerimisnumber (lisatakse ärikliendi andmetele) */
    private $regNo;

    /** @var string Arve numbri eesliide, nt 'WC' → arve number 'WC1234' */
    private $arve_eesliides;

    /**
     * WC orderi staatus, mille juures arve automaatselt saadetakse, nt 'wc-completed'.
     *
     * @var string
     */
    private $order_Status;

    /**
     * Merit arve rea tüüp (1 = teenus, 2 = toode).
     * Mõjutab Merit Aktiva laosaldode käsitlust.
     *
     * @var int
     */
    private $arve_ridade_tyyp;

    /** @var string Merit Aktiva osakonna kood (DepartmentCode) */
    private $department_code;

    /**
     * Merit AccountingDoc tüüp (1 = müügiarve, 2 = kreeditarve jne).
     *
     * @var int
     */
    private $AccountingDoc;

    /**
     * Registreerib kõik vajalikud WordPressi ja WooCommerce hookid ning laeb seaded.
     *
     * AJAX-hookide paar (wp_ajax_ + wp_ajax_nopriv_) on vajalik, kuna mõned päringud
     * tulevad frontend-ist (nopriv = sisselogimata kasutaja), teised adminilt.
     * woocommerce_order_status_changed hook on see, mis käivitab automaatse saatmise
     * reaalajas, ilma et admin peaks midagi tegema.
     */
    public function __construct() {
        add_action( 'wp_enqueue_scripts',   [ $this, 'enqueue_scripts' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        add_action( 'wp_ajax_my_custom_action',        [ $this, 'handle_ajax' ] );
        add_action( 'wp_ajax_nopriv_my_custom_action', [ $this, 'handle_ajax' ] );
        add_action( 'wp_ajax_send_invoice_action',        [ $this, 'send_invoice_to_customer' ] );
        add_action( 'wp_ajax_nopriv_send_invoice_action', [ $this, 'send_invoice_to_customer' ] );
        add_action( 'wp_ajax_swi_merit_sync_check',  [ $this, 'handle_sync_check' ] );
        add_action( 'wp_ajax_swi_merit_sync_resend', [ $this, 'handle_sync_resend' ] );

        // Feature 1: Merit UUID laadimine
        add_action( 'wp_ajax_swi_merit_load_vatcodes', [ $this, 'handle_load_vatcodes' ] );

        // Feature 6: Arve eelvaade
        add_action( 'wp_ajax_swi_merit_preview_invoice', [ $this, 'handle_preview_invoice' ] );

        // Feature 7: Saatmise ajalugu — kustuta
        add_action( 'wp_ajax_swi_clear_history', [ $this, 'handle_clear_history' ] );

        // Automaatne hook — saadab orderi serverisse kui staatus muutub
        add_action( 'woocommerce_order_status_changed', [ $this, 'auto_send_order' ], 10, 3 );

        // Kõik seaded loetakse üks kord konstruktoris, mitte iga meetodi kutsumise ajal
        $this->tax_field        = get_option( 'smart_wp_integtaion_maksumaar' );
        $this->payment_deadline = get_option( 'smart_wp_integtaion_maksetahtaeg' );
        $this->regNo            = get_option( 'regno' );
        $this->arve_eesliides   = get_option( 'smart_wp_integtaion_arve_eesliides' );
        $this->order_Status     = get_option( 'smart_wp_integtaion_invoice_status' );
        $this->arve_ridade_tyyp = get_option( 'smart_wp_integtaion_arve_ridade_tyyp' );
        $this->department_code  = get_option( 'smart_wp_integtaion_deparment' );
        $this->AccountingDoc    = get_option( 'smart_wp_integtaion_AccountingDoc' );
    }

    /**
     * Laeb admin-lehel JS-faili ja annab ajaxurl ning nonce JavaScripti kätte.
     *
     * wp_localize_script on WordPressi õige tee PHP muutujate JS-sse edastamiseks —
     * alternatiiv inline-skriptile, aga turvalisem ja cacheable.
     */
    public function enqueue_scripts() {
        wp_enqueue_script( 'my-simple-ajax', plugin_dir_url( __FILE__ ) . '../../js/smart-wp-integrations-admin.js', [ 'jquery' ], '1.0', true );
        wp_localize_script( 'my-simple-ajax', 'MyAjax', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'my_nonce' ),
        ] );
    }

    /**
     * Automaatne saatmine — käivitub kui WooCommerce tellimuse staatus muutub.
     *
     * Hook 'woocommerce_order_status_changed' annab kolm parameetrit: ID, vana ja uus staatus.
     * 'wc-' prefiksi lisamine on vajalik, kuna get_option tagastab kuju 'wc-completed',
     * aga WC annab new_status kujul 'completed' (ilma prefiksita).
     * '_swi_sent_merit' meta kontroll on kriitilise tähtsusega — vältib topelt arve saatmist
     * kui staatus muutub mitu korda (nt completed → processing → completed).
     *
     * @param int    $order_id  WooCommerce tellimuse ID.
     * @param string $old_status Eelmine staatus ilma 'wc-' prefiksita.
     * @param string $new_status Uus staatus ilma 'wc-' prefiksita.
     */
    public function auto_send_order( int $order_id, string $old_status, string $new_status ): void {
        if ( get_option( 'smart_wp_integtaion_enable' ) !== 'yes' ) {
            return;
        }

        $configured = get_option( 'smart_wp_integtaion_invoice_status', 'wc-completed' );
        if ( 'wc-' . $new_status !== $configured ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // HPOS-ühilduv meta-lugemine: get_meta() töötab nii vana postmeta kui uue wc_orders_meta tabeliga
        if ( $order->get_meta( '_swi_sent_merit' ) ) {
            return;
        }

        $payload = $this->build_payload_for_order( $order );
        if ( ! $payload ) {
            return;
        }

        $res = LocalApiClient::sendEncryptedOrder( $payload, 'merit' );

        if ( isset( $res['status'] ) && in_array( $res['status'], [ 'ok', 'queued' ], true ) ) {
            $order->update_meta_data( '_swi_sent_merit', current_time( 'mysql' ) );
            $order->delete_meta_data( '_swi_merit_retry' );
            $order->delete_meta_data( '_swi_merit_retry_count' );
            $order->save();
            $order->add_order_note( 'Merit Aktiva: arve edastatud (' . $res['status'] . ').' );
            swi_log_send_history( $order_id, 'ok', $res['message'] ?? $res['status'] );
        } else {
            $msg = swi_humanize_merit_error( $res );
            $order->add_order_note( 'Merit Aktiva: edastamine ebaõnnestus — ' . $msg );
            error_log( 'SWI Merit auto-send failed order ' . $order_id . ': ' . wp_json_encode( $res ) );
            // Märgi retry — cron üritab uuesti iga 10 min, max 3 korda
            $order->update_meta_data( '_swi_merit_retry', '1' );
            $order->update_meta_data( '_swi_merit_retry_count', 0 );
            $order->save();
            // E-mail teavitus adminile kui seadetes lubatud
            if ( get_option( 'swi_merit_email_notify' ) === 'yes' ) {
                $admin_email = get_option( 'admin_email' );
                wp_mail(
                    $admin_email,
                    'Merit Aktiva: arve saatmine ebaõnnestus #' . $order_id,
                    'Tellimus #' . $order_id . ' edastamine Merit Aktivasse ebaõnnestus.' . "\n\n" . 'Viga: ' . $msg . "\n\nKontrolli: " . admin_url( 'admin.php?page=wc-settings&tab=smart_wp_integration' )
                );
            }
            swi_log_send_history( $order_id, 'error', $msg );
        }
    }

    /**
     * Tagastab nende WooCommerce orderite ID-d, mis vastavad seadistatud staatusele
     * ega ole veel Merit Aktivasse saadetud.
     *
     * Kasutab kahte paralleelset kontrollimeetodit:
     * 1. '_swi_sent_merit' meta-lipp (kiire, ei sõltu Merit API kättesaadavusest)
     * 2. Merit serveri tegelike arvete nimekiri (täpsem, aga aeglasem)
     * Mõlema kontrolli eesmärk on vältida duplikaatarve tekkimist Meriti poolel.
     *
     * @return array Saatmata orderite ID-de massiiv.
     */
    public function Smart_WP_Filter_Woocommerce_Merit_aktiva_Invoices(): array {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return [];
        }

        $orders               = wc_get_orders( [ 'limit' => -1 ] );
        $merit_server_invoices = new MeritServersDataClient();
        $results              = $merit_server_invoices->get_all_invoices();
        $filtered             = [];

        foreach ( $orders as $order ) {
            if ( 'wc-' . $order->get_status() !== $this->order_Status ) {
                continue;
            }
            // Esmane kontroll: meta-flag — kiire ja ei sõltu Merit API kättesaadavusest
            if ( $order->get_meta( '_swi_sent_merit' ) ) {
                continue;
            }
            // Teisene kontroll: võrdle Merit serveri arvete nimekirjaga
            // InvoiceNo formaat: eesliide + WC order ID (nt 'WC1234')
            $exists = false;
            if ( is_array( $results ) ) {
                foreach ( $results as $invoice ) {
                    if ( $this->arve_eesliides . $order->get_id() === $invoice->InvoiceNo ) {
                        $exists = true;
                        break;
                    }
                }
            }
            if ( ! $exists ) {
                $filtered[] = $order->get_id();
            }
        }

        return $filtered;
    }

    /**
     * Ehitab Merit Aktiva API formaadis payload ühele WooCommerce orderile.
     *
     * Payload struktuur vastab Merit Aktiva REST API nõuetele:
     * - Customer: kliendi andmed (eraisik vs ettevõte mõjutab välju)
     * - InvoiceRow: iga toode ja tarnekulud eraldi reana
     * - TaxAmount: käibemaks grupeerituna UUID järgi (eri riikidel eri maksumäär)
     *
     * Riigipõhine käibemaksu loogika: kui klient on teisest riigist, otsitakse
     * country_map seadistest vastav VAT UUID. Vaikeseade rakendub kui vastet ei leita.
     *
     * Osakonna kaardistus (kategooria → Merit DepartmentCode): esimene ostukorvi
     * toote kategooria, millel on seadistatud osakond, määrab kogu arve osakonna.
     *
     * @param \WC_Order $order WooCommerce tellimuse objekt.
     * @return array|null Merit API payload või null kui orderi andmed on vigased.
     */
    public function build_payload_for_order( \WC_Order $order ): ?array {
        $country_settings = get_option( 'smart_wp_integtaion_country_map', [] );
        $payment_map      = get_option( 'smart_wp_integtaion_payment_map', [] );

        // Riigi tuvastamine: eelistame arveaadressi, fallback tarneaadressile
        $country = $order->get_billing_country() ?: $order->get_shipping_country();
        if ( empty( $country ) ) {
            // Kui mõlemat pole, kasuta riiki mis on märgitud vaikimisi riigiks
            foreach ( $country_settings as $cfg ) {
                if ( ! empty( $cfg['default'] ) ) { $country = $cfg['country'] ?? ''; break; }
            }
        }

        $vat_code = $this->tax_field;
        $vat_name = null;
        $is_default_country = false;
        // Otsi riigipõhine VAT UUID seadistest — võimaldab eri riikidele eri maksumäära
        if ( ! empty( $country ) && ! empty( $country_settings ) ) {
            foreach ( $country_settings as $row ) {
                if ( ( $row['country'] ?? '' ) === $country ) {
                    $vat_code           = ! empty( $row['vat_code'] ) ? $row['vat_code'] : $vat_code;
                    $vat_name           = $row['name'] ?? null;
                    $is_default_country = ! empty( $row['default'] );
                    break;
                }
            }
        }

        // Maksemeetodi kaardistus WooCommerce payment_method → Merit konto kood
        $wc_method     = $order->get_payment_method();
        $merit_account = $payment_map[ $wc_method ] ?? null;
        $is_company    = ! empty( $order->get_billing_company() );

        // NotTDCustomer = true tähendab eraisik (ei ole käibemaksukohustuslane)
        $customer = [
            'Name'            => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'NotTDCustomer'   => ! $is_company,
            'CurrencyCode'    => $order->get_currency(),
            'PaymentDeadLine' => (int) $this->payment_deadline,
            'OverDueCharge'   => 0,
            'RefNoBase'       => 1,
            'Address'         => $order->get_billing_address_1(),
            'CountryCode'     => $order->get_billing_country() ?: 'EE',
            'City'            => $order->get_billing_city(),
            'PostalCode'      => $order->get_billing_postcode(),
            'Email'           => $order->get_billing_email(),
        ];
        // RegNo ja VatRegNo on Merit API-s kohustuslikud väljad ainult äriklientidel
        if ( $is_company ) {
            $customer['RegNo']    = $this->regNo ?: '';
            $customer['VatRegNo'] = '';
        }
        $phone = $order->get_billing_phone();
        if ( $phone ) {
            // Merit aktsepteerib maksimaalselt 20 tähemärki telefoninumbris
            $customer['PhoneNo'] = substr( $phone, 0, 20 );
        }
        $county = $order->get_billing_state();
        if ( $county ) {
            $customer['County'] = $county;
        }

        $rows = $this->create_invoice_items_array( $order, $vat_code );

        // TotalAmount = kõigi ridade kogusumma ilma käibemaksuta (neto)
        $total_amount = 0.0;
        foreach ( $rows as $row ) {
            $total_amount += (float) $row['Price'] * (float) $row['Quantity'];
        }
        $total_amount = round( $total_amount, 2 );

        // Merit nõuab TaxAmount massiivi kus iga UUID kohta eraldi käibemaksu summa.
        // Grupeerime käibemaksud UUID järgi, kuna ühel arvel võib olla mitu maksumäära
        // (nt Eesti tooted 22% + nullmääraga tooted).
        $tax_by_uuid = [];
        foreach ( $order->get_items() as $item ) {
            $item_tax = round( (float) $item->get_total_tax(), 2 );
            if ( $item_tax <= 0 ) continue;
            $uuid = $vat_code ?: $this->resolve_vat_uuid( $this->item_tax_rate( $item ) );
            $tax_by_uuid[ $uuid ] = ( $tax_by_uuid[ $uuid ] ?? 0.0 ) + $item_tax;
        }
        foreach ( $order->get_shipping_methods() as $shipping_item ) {
            $ship_tax = round( (float) $shipping_item->get_total_tax(), 2 );
            if ( $ship_tax <= 0 ) continue;
            $ship_total = (float) $shipping_item->get_total();
            // Tarne protsent arvutatakse dünaamiliselt, kuna tarne maksumäär võib erineda toote omast
            $ship_rate  = $ship_total > 0 ? round( $ship_tax / $ship_total * 100, 2 ) : 0.0;
            $uuid = $vat_code ?: $this->resolve_vat_uuid( $ship_rate );
            $tax_by_uuid[ $uuid ] = ( $tax_by_uuid[ $uuid ] ?? 0.0 ) + $ship_tax;
        }
        // Tagavaravõimalus: kui ühelgi real pole käibemaksu (nt 0% orderid), kasuta WC kogusummat
        if ( empty( array_filter( $tax_by_uuid ) ) ) {
            $tax_by_uuid[ $vat_code ?: $this->tax_field ] = round( (float) $order->get_total_tax(), 2 );
        }
        $tax_amount_arr = [];
        foreach ( $tax_by_uuid as $uuid => $amount ) {
            $tax_amount_arr[] = [ 'TaxId' => $uuid, 'Amount' => round( $amount, 2 ) ];
        }

        // Arve kuupäev = tellimuse loomise kuupäev; tähtaeg = loomise kuupäev + maksetähtaeg
        $doc_date = $order->get_date_created()
            ? $order->get_date_created()->date( 'Ymd' )
            : gmdate( 'Ymd' );
        $due_date = gmdate( 'Ymd', strtotime( '+' . max( 1, (int) $this->payment_deadline ) . ' days' ) );

        // Kategooria → osakond kaardistus: esimene leitud kategooria osakond võidab
        $dept_map  = (array) get_option( 'swi_category_dept_map', [] );
        $dept_code = $this->department_code ?: '';
        if ( ! empty( $dept_map ) ) {
            foreach ( $order->get_items() as $item ) {
                $product = $item->get_product();
                if ( ! $product ) {
                    continue;
                }
                $cats = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'slugs' ] );
                if ( is_wp_error( $cats ) ) {
                    continue;
                }
                foreach ( $cats as $cat_slug ) {
                    if ( ! empty( $dept_map[ $cat_slug ] ) ) {
                        // break 2 väljub mõlemast foreach-tsüklist korraga — esimene vaste määrab osakonna
                        $dept_code = $dept_map[ $cat_slug ];
                        break 2;
                    }
                }
            }
        }

        $payload = [
            'Customer'       => $customer,
            'DocDate'        => $doc_date,
            'DueDate'        => $due_date,
            'InvoiceNo'      => $this->arve_eesliides . $order->get_id(),
            'DepartmentCode' => $dept_code,
            'InvoiceRow'     => $rows,
            'TotalAmount'    => $total_amount,
            'RoundingAmount' => 0.0,
            'TaxAmount'      => $tax_amount_arr,
        ];

        // PaymentMethod lisatakse ainult kui kaardistus seadistatud — muidu Merit kasutab vaikimisi
        if ( ! empty( $merit_account ) ) {
            $payload['PaymentMethod'] = $merit_account;
        }

        return $payload;
    }

    /**
     * Ehitab payloadid kõigile filtreerimata (saatmata) orderitele käsitsi saatmiseks.
     *
     * Mõeldud handle_ajax() jaoks, kus admin vajutab "Saada kõik" nuppu.
     * Erineb auto_send_order()-ist selle poolest, et töötleb korraga mitu orderit.
     *
     * @return array Payloadide massiiv või veateade massiivina.
     */
    public function create_invoice(): array {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return [ 'error' => 'WooCommerce ei ole aktiivne!' ];
        }

        $order_ids = $this->Smart_WP_Filter_Woocommerce_Merit_aktiva_Invoices();
        if ( empty( $order_ids ) ) {
            return [ 'error' => 'Ühtegi uut orderit ei leitud.' ];
        }

        $invoiceArray = [];
        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) continue;
            $payload = $this->build_payload_for_order( $order );
            if ( $payload ) $invoiceArray[] = $payload;
        }

        return $invoiceArray;
    }

    /**
     * Leiab Merit VAT UUID vastavalt käibemaksu protsendile.
     *
     * Kui seadetes on tax_map konfigureeritud, otsitakse sealt protsendile vastav UUID.
     * Tolerants 0.01% on vajalik ujukomaarvude võrdlemisel (nt 22.0 vs 21.999999...).
     * Tagavarana kasutatakse vaikimisi UUID-d (is_default_country = 'yes').
     *
     * @param float $rate_pct Käibemaksu protsent (nt 22.0).
     * @return string Merit Aktiva VAT UUID.
     */
    private function resolve_vat_uuid( float $rate_pct ): string {
        $tax_map      = get_option( 'smart_wp_integtaion_tax_map', [] );
        $default_uuid = $this->tax_field;
        foreach ( $tax_map as $row ) {
            if ( ! empty( $row['is_default'] ) && $row['is_default'] === 'yes' && ! empty( $row['uuid'] ) ) {
                $default_uuid = $row['uuid'];
            }
        }
        foreach ( $tax_map as $row ) {
            if ( isset( $row['rate'] ) && abs( (float) $row['rate'] - $rate_pct ) < 0.01 && ! empty( $row['uuid'] ) ) {
                return $row['uuid'];
            }
        }
        return $default_uuid;
    }

    /**
     * Arvutab ühe tellimuseridame tegeliku käibemaksu protsendi.
     *
     * WooCommerce salvestab käibemaksu absoluutsummana, mitte protsendina.
     * Vajalik selleks, et leida õige Merit VAT UUID resolve_vat_uuid() kaudu.
     *
     * @param \WC_Order_Item_Product $item Tellimuseridame objekt.
     * @return float Käibemaksu protsent (nt 22.0).
     */
    private function item_tax_rate( \WC_Order_Item_Product $item ): float {
        $total     = (float) $item->get_total();
        $total_tax = (float) $item->get_total_tax();
        if ( $total <= 0 ) return 0.0;
        return round( $total_tax / $total * 100, 2 );
    }

    /**
     * Loob Merit API 'InvoiceRow' massiivi kõigi toodete ja tarnekuludega.
     *
     * Iga WooCommerce tellimuserida muudetakse Merit formaati:
     * - Price on ühiku hind ilma käibemaksuta (neto), arvutatud kogusummast jagades kogusega
     * - SKU pikkus piiratud 20 tähemärgiga (Merit API piirang)
     * - Tarne lisatakse eraldi reana, kasutades shipping_map konfiguratsiooni kaubakoodi
     *
     * Tarnekulude kaubakood tuleb shipping_map seadistest (meetodi ID → Merit kood).
     * Kui kaardistust pole, kasutatakse vaikimisi 'TRANSPORT'.
     *
     * @param \WC_Order  $order            WooCommerce tellimuse objekt.
     * @param string|null $vat_code_override Kui antud, kasutatakse seda UUID kõigi ridade jaoks.
     * @return array Merit API InvoiceRow massiiv.
     */
    public function create_invoice_items_array( \WC_Order $order, ?string $vat_code_override = null ): array {
        $payload_arrays = [];

        foreach ( $order->get_items() as $item ) {
            $product  = $item->get_product();
            $sku      = $product ? $product->get_sku() : '';
            $qty      = max( 1, (int) $item->get_quantity() );
            // Ühikuhind = kogusumma / kogus (neto, käibemaksuta) — 4 kümnendkohta täpsuse säilitamiseks
            $price_ex = round( (float) $item->get_total() / $qty, 4 );
            $tax_uuid = $vat_code_override ?: $this->resolve_vat_uuid( $this->item_tax_rate( $item ) );

            $payload_arrays[] = [
                'Item'           => [
                    'Code'        => $sku ? substr( $sku, 0, 20 ) : null,
                    'Description' => $item->get_name(),
                    'Type'        => (int) $this->arve_ridade_tyyp,
                    'UOMName'     => 'tk',
                ],
                'Quantity'       => (float) $qty,
                'Price'          => $price_ex,
                'DiscountPct'    => 0,
                'DiscountAmount' => 0,
                'TaxId'          => $tax_uuid,
                'LocationCode'   => '1',
            ];
        }

        $shipping_map = get_option( 'smart_wp_integtaion_shipping_map', [] );
        foreach ( $order->get_shipping_methods() as $shipping_item ) {
            $shipping_total = (float) $shipping_item->get_total();
            if ( $shipping_total <= 0 ) continue;
            $ship_tax    = (float) $shipping_item->get_total_tax();
            $ship_rate   = $shipping_total > 0 ? round( $ship_tax / $shipping_total * 100, 2 ) : 0.0;
            $tax_uuid    = $vat_code_override ?: $this->resolve_vat_uuid( $ship_rate );
            $method_id   = $shipping_item->get_method_id();
            // Shipping_map võimaldab eri tarnija meetodeid Merit-is eri kaubakoodi alla panna
            $ship_code   = ! empty( $shipping_map[ $method_id ] ) ? substr( $shipping_map[ $method_id ], 0, 20 ) : 'TRANSPORT';

            $payload_arrays[] = [
                'Item'           => [
                    'Code'        => $ship_code,
                    'Description' => $shipping_item->get_name(),
                    'Type'        => 1,
                    'UOMName'     => 'tk',
                ],
                'Quantity'       => 1,
                'Price'          => $shipping_total,
                'DiscountPct'    => 0,
                'DiscountAmount' => 0,
                'TaxId'          => $tax_uuid,
                'LocationCode'   => '1',
            ];
        }

        return $payload_arrays;
    }

    /**
     * AJAX handler: saada kõik saatmata orderid Meriti käsitsi.
     *
     * Kutsutakse admin-lehelt "Saada arved" nupuga. Ebaõnnestunud orderid märgitakse
     * retry-ks, et cron saaks neid hiljem automaatselt uuesti proovida.
     */
    public function handle_ajax(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            wp_send_json_error( [ 'error' => 'WooCommerce ei ole aktiivne!' ] );
        }
        if ( get_option( 'smart_wp_integtaion_enable' ) !== 'yes' ) {
            wp_send_json_error( [ 'error' => 'Merit Aktiva integratsioon on keelatud.' ] );
        }

        $order_ids = $this->Smart_WP_Filter_Woocommerce_Merit_aktiva_Invoices();

        if ( empty( $order_ids ) ) {
            wp_send_json_error( [ 'error' => 'Ühtegi saatmata orderit ei leitud.' ] );
        }

        $results = [];
        $errors  = [];

        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) continue;
            $payload = $this->build_payload_for_order( $order );
            if ( ! $payload ) continue;

            $res = LocalApiClient::sendEncryptedOrder( $payload, 'merit' );
            if ( in_array( $res['status'] ?? '', [ 'ok', 'queued' ], true ) ) {
                $order->update_meta_data( '_swi_sent_merit', current_time( 'mysql' ) );
                $order->save();
                $order->add_order_note( 'Merit Aktiva: arve edastatud käsitsi (' . $res['status'] . ').' );
                swi_log_send_history( $order_id, 'ok', $res['message'] ?? $res['status'] );
                $results[] = $res;
            } else {
                $msg = swi_humanize_merit_error( $res );
                $order->add_order_note( 'Merit Aktiva: käsitsi edastamine ebaõnnestus — ' . $msg );
                $order->update_meta_data( '_swi_merit_retry', '1' );
                $order->update_meta_data( '_swi_merit_retry_count', 0 );
                $order->save();
                swi_log_send_history( $order_id, 'error', $msg );
                $errors[] = $res;
            }
        }

        if ( ! empty( $errors ) ) {
            wp_send_json_error( [ 'message' => 'Mõned orderid ei õnnestunud saata', 'errors' => $errors, 'success' => $results ] );
        }

        wp_send_json_success( $results );
    }

    /**
     * AJAX handler: saada Merit arved e-mailiga klientidele.
     *
     * Küsib kõik arved Merit serverist ja saadab igale arvele e-kirja.
     * Praegu on merit_send_invoice_by_email() mitteimplementeeritud (vt proxy klass).
     */
    public function send_invoice_to_customer(): void {
        check_ajax_referer( 'my_nonce', 'security' );

        if ( ! class_exists( 'WooCommerce' ) ) {
            wp_send_json_error( [ 'error' => 'WooCommerce ei ole aktiivne!' ] );
        }

        $client  = new MeritServersDataClient();
        $results = $client->get_all_invoices();

        if ( is_array( $results ) ) {
            foreach ( $results as $invoice ) {
                if ( ! empty( $invoice->SIHId ) ) {
                    $client->merit_send_invoice_by_email( $invoice->SIHId );
                }
            }
        }

        wp_send_json_success( 'Emailid on saadetud' );
    }

    /**
     * AJAX handler: sünkroonimise kontroll — võrdleb WC ordereid Merit serveri arvetega.
     *
     * Tagastab tabeli igast orderist koos info sellega, kas arve on Meriti juba olemas
     * (in_merit) ja millal see lokaalselt saadetuna märgiti (meta_sent). Võimaldab
     * administraatoril avastada lahknevusi ilma Merit admin-paneeli avamata.
     *
     * Merit API lubab max 3 kuu perioodi — seetõttu kasutame get_all_invoices_for_sync(12),
     * aga tegelikkuses tagastatakse ainult viimased 3 kuud.
     */
    public function handle_sync_check(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        }

        // Küsi kõik Merit arved viimasest 12 kuust
        $client    = new MeritServersDataClient();
        $merit_nos = [];
        try {
            $invoices = $client->get_all_invoices_for_sync( 12 );
            foreach ( $invoices as $inv ) {
                $no = is_array( $inv ) ? ( $inv['InvoiceNo'] ?? null ) : ( $inv->InvoiceNo ?? null );
                if ( $no ) $merit_nos[] = $no;
            }
        } catch ( RuntimeException $e ) {
            wp_send_json_error( [ 'error' => 'Merit API viga: ' . $e->getMessage() ] );
        }

        // Kõik WC orderid konfigureeritava staatusega
        $status  = ltrim( get_option( 'smart_wp_integtaion_invoice_status', 'wc-completed' ), 'wc-' );
        $prefix  = get_option( 'smart_wp_integtaion_arve_eesliides', '' );
        $orders  = wc_get_orders( [ 'status' => $status, 'limit' => -1, 'orderby' => 'date', 'order' => 'DESC' ] );

        $rows = [];
        foreach ( $orders as $order ) {
            $inv_no   = $prefix . $order->get_id();
            // in_array strict mode tagab et ei juhtu tüübist sõltuv võrdlusviga
            $in_merit = in_array( $inv_no, $merit_nos, true );
            $meta     = $order->get_meta( '_swi_sent_merit' );
            $rows[]   = [
                'order_id'   => $order->get_id(),
                'invoice_no' => $inv_no,
                'date'       => $order->get_date_created() ? $order->get_date_created()->date( 'd.m.Y' ) : '-',
                'total'      => wc_price( $order->get_total() ),
                'in_merit'   => $in_merit,
                'meta_sent'  => $meta ? date( 'd.m.Y H:i', strtotime( $meta ) ) : '',
            ];
        }

        wp_send_json_success( [ 'rows' => $rows, 'merit_count' => count( $merit_nos ) ] );
    }

    /**
     * AJAX handler: laeb Merit Aktiva VAT koodid (UUID-d ja maksumäärad) seadete lehele.
     *
     * Võimaldab administraatoril valida õiged UUID-d otse Merit serverist,
     * mitte sisestada neid käsitsi. Tulemused kuvatakse seadete lehel valikmenüüna.
     *
     * @since 1.0.0 (Feature 1)
     */
    public function handle_load_vatcodes(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        }
        $client = new MeritServersDataClient();
        try {
            $vatcodes = $client->get_vatcodes();
        } catch ( RuntimeException $e ) {
            wp_send_json_error( [ 'error' => $e->getMessage() ] );
            return;
        }
        wp_send_json_success( [ 'vatcodes' => $vatcodes ] );
    }

    /**
     * AJAX handler: tagastab arve eelvaate JSON-ina ilma Meriti saatmata.
     *
     * Võimaldab administraatoril kontrollida, milline payload Merit API-le läheks,
     * enne tegelikku saatmist. Kasulik arenduse ja vigade otsimise ajal.
     *
     * @since 1.0.0 (Feature 6)
     */
    public function handle_preview_invoice(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        }
        $order_id = absint( $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) {
            wp_send_json_error( [ 'error' => 'Order ID puudub.' ] );
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'error' => 'Orderit ei leitud.' ] );
        }
        $payload = $this->build_payload_for_order( $order );
        if ( ! $payload ) {
            wp_send_json_error( [ 'error' => 'Payload ehitus ebaõnnestus.' ] );
        }
        wp_send_json_success( [ 'payload' => $payload ] );
    }

    /**
     * AJAX handler: kustutab saatmise ajalugu wp_options tabelist.
     *
     * swi_send_history on serialiseeritud massiiv wp_options tabelis (mitte eraldi tabel),
     * seetõttu piisab delete_option() kutsumisest kogu ajaloo kustutamiseks.
     *
     * @since 1.0.0 (Feature 7)
     */
    public function handle_clear_history(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        }
        delete_option( 'swi_send_history' );
        wp_send_json_success( [ 'message' => 'Ajalugu kustutatud.' ] );
    }

    /**
     * AJAX handler: saada üks konkreetne order uuesti Merit Aktivasse.
     *
     * Kasutatakse sünkroonimise lehel kui üksik order on lahknevuses.
     * Kustutab esmalt vana '_swi_sent_merit' lippi, et lubada uuesti saatmine —
     * ilma selleta blokeeriks auto_send_order() või handle_ajax() selle orderi.
     */
    public function handle_sync_resend(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        }
        if ( get_option( 'smart_wp_integtaion_enable' ) !== 'yes' ) {
            wp_send_json_error( [ 'error' => 'Merit Aktiva integratsioon on keelatud.' ] );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) wp_send_json_error( [ 'error' => 'Order ID puudub.' ] );

        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_send_json_error( [ 'error' => 'Orderit ei leitud.' ] );

        // Kustuta vana sent-lipp et lubada uuesti saatmine, isegi kui order on varem edukalt saadetud
        $order->delete_meta_data( '_swi_sent_merit' );
        $order->save();

        $payload = $this->build_payload_for_order( $order );
        if ( ! $payload ) wp_send_json_error( [ 'error' => 'Payload ehitus ebaõnnestus.' ] );

        $res = LocalApiClient::sendEncryptedOrder( $payload, 'merit' );
        if ( in_array( $res['status'] ?? '', [ 'ok', 'queued' ], true ) ) {
            $order->update_meta_data( '_swi_sent_merit', current_time( 'mysql' ) );
            $order->save();
            $order->add_order_note( 'Merit Aktiva: arve uuesti edastatud (sync).' );
            wp_send_json_success( [ 'message' => 'Edastatud.' ] );
        } else {
            $order->add_order_note( 'Merit Aktiva: uuesti edastamine ebaõnnestus — ' . wp_json_encode( $res ) );
            wp_send_json_error( $res );
        }
    }
}

// Instantseerib klassi kohe faili laadimisel — hookid registreeritakse konstruktoris
$test = new My_Simple_Ajax_Plugin();

/**
 * Tõlgib tuntud Merit API veakoodid inimloetavaks eesti keelde.
 *
 * Merit Aktiva API tagastab veakoodid mitmest erinevast kohast vastuse struktuuris
 * (merit_message, body, message) sõltuvalt vealiigist. See funktsioon otsib kõigist
 * kohtadest ja tagastab esimese osalise tekstivaste abil leitud sõbraliku sõnumi.
 *
 * Kui teadaolevat veakoodi ei leita, tagastatakse toorveateade sellisena nagu see saadi.
 *
 * @param array $res LocalApiClient::sendEncryptedOrder() tagastus.
 * @return string Inimloetav eestikeelne veateade.
 */
function swi_humanize_merit_error( array $res ): string {
    $merit_msg = $res['response']['result']['merit_message']
        ?? $res['response']['result']['body']
        ?? $res['merit_message']
        ?? '';
    $raw = $merit_msg ?: ( $res['message'] ?? '' );

    $map = [
        'Korduv arve number'                => 'Arve on Meriti juba olemas (korduvnumber).',
        'Ridade summa ei võrdu arve summaga' => 'Arve ridade summa ei klapi arvesummaga — kontrolli toodete hindasid.',
        'Periood liiga pikk'                 => 'Valitud ajaperiood on liiga pikk (Merit lubab max 3 kuud).',
        'kaubakoodi liiga pikk'              => 'Toote SKU kood on liiga pikk (max 20 tähemärki).',
        'Handler returned failure'           => 'Merit keeldus arvet vastu võtmast.',
        'HTTP 422'                           => 'Merit keeldus arvet vastu võtmast (andmeviga).',
        'HTTP 400'                           => 'Merit tagastas vigase päringu (400).',
        'HTTP 500'                           => 'Merit server viga — proovi hiljem uuesti.',
        'timed out'                          => 'Ühendus Merit serveriga aegus — proovi uuesti.',
        'cURL error'                         => 'Võrguühenduse viga Merit serveriga.',
    ];

    foreach ( $map as $key => $friendly ) {
        if ( str_contains( $raw, $key ) ) {
            return $friendly;
        }
    }

    return $raw ?: 'Tundmatu viga Merit API-lt.';
}

/**
 * Lisa kirje saatmise ajalukku wp_options tabelisse.
 *
 * Kasutab wp_options tavalise andmetabeli asemel, kuna see ei nõua eraldi tabelit
 * ja sobib väikese mahu (~50 kirjet) jaoks. array_slice piirab ajalugu 50 kirjele,
 * et wp_options ei paisuks lõputult. autoload=false väldib tabeli laadimist igal
 * lehelaadimisел — ajalugu loetakse ainult siis kui seda tegelikult vajatakse.
 *
 * @param int    $order_id WooCommerce tellimuse ID.
 * @param string $status   'ok' kui edukas, 'error' kui ebaõnnestus.
 * @param string $message  Kirjeldus (salvestatakse max 200 märki).
 */
function swi_log_send_history( int $order_id, string $status, string $message ): void {
    $history = (array) get_option( 'swi_send_history', [] );
    // array_unshift lisab uusima kirje massiivi algusesse (viimane esmalt järjekord)
    array_unshift( $history, [
        'time'     => current_time( 'mysql' ),
        'order_id' => $order_id,
        'status'   => $status,
        'message'  => substr( $message, 0, 200 ),
    ] );
    update_option( 'swi_send_history', array_slice( $history, 0, 50 ), false );
}
