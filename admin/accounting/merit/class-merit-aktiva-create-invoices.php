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
        add_action( 'wp_ajax_swi_clear_history',     [ $this, 'handle_clear_history' ] );
        add_action( 'wp_ajax_swi_merit_order_send',  [ $this, 'handle_order_send' ] );

        // Käsitsi nupud: makse märkimine, kreediitarve, arve staatus/kustutamine
        add_action( 'wp_ajax_swi_merit_send_payment',   [ $this, 'handle_send_payment' ] );
        add_action( 'wp_ajax_swi_merit_create_credit',  [ $this, 'handle_create_credit_invoice' ] );
        add_action( 'wp_ajax_swi_merit_invoice_status', [ $this, 'handle_invoice_status' ] );
        add_action( 'wp_ajax_swi_merit_delete_invoice', [ $this, 'handle_delete_merit_invoice' ] );

        // Meriti laod: laadimine seadetes + toote ladu (Toote andmed → Laoseis)
        add_action( 'wp_ajax_swi_merit_load_locations', [ $this, 'handle_load_locations' ] );
        add_action( 'woocommerce_product_options_inventory_product_data', [ $this, 'render_product_location_field' ] );
        add_action( 'woocommerce_admin_process_product_object', [ $this, 'save_product_location_field' ] );

        // Meriti makseviisid (Maksete kaardistuse valikud)
        add_action( 'wp_ajax_swi_merit_load_payment_types', [ $this, 'handle_load_payment_types' ] );

        // Arve PDF tellimuse "täidetud" e-kirjale (seade: Üldseaded → Arve PDF e-kirjale)
        add_filter( 'woocommerce_email_attachments', [ $this, 'attach_merit_pdf' ], 10, 4 );

        // Massiline saatmine tellimuste nimekirjast (vana + HPOS)
        add_filter( 'bulk_actions-edit-shop_order', [ $this, 'add_bulk_action' ] );
        add_filter( 'bulk_actions-woocommerce_page_wc-orders', [ $this, 'add_bulk_action' ] );
        add_filter( 'handle_bulk_actions-edit-shop_order', [ $this, 'handle_bulk_action' ], 10, 3 );
        add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', [ $this, 'handle_bulk_action' ], 10, 3 );
        add_action( 'admin_notices', [ $this, 'show_bulk_action_notice' ] );

        // Admin-teade: tellimused, mida ei õnnestunud Meritisse saata (nagu Smart Accountsil)
        add_action( 'admin_notices', [ $this, 'show_failed_notice' ] );

        // Laoseisu sünkroonimine Meritist (seade: Laod → Laoseisu sünkroonimine), iga tund
        add_action( 'wp_ajax_swi_merit_sync_stock', [ $this, 'handle_sync_stock' ] );
        add_action( 'swi_merit_stock_cron', [ $this, 'sync_stock_from_merit' ] );
        // Ajastamine (WP-Cron + Action Scheduler varu): swi_ensure_background_jobs() põhifailis

        // Automaatne hook — saadab orderi serverisse kui staatus muutub
        add_action( 'woocommerce_order_status_changed', [ $this, 'auto_send_order' ], 10, 3 );

        // Cron: registreeri 10-minutiline intervall ja ajakava
        add_filter( 'cron_schedules', [ $this, 'add_cron_interval' ] );
        add_action( 'swi_merit_cron_send', [ $this, 'cron_send_orders' ] );

        // Kõik seaded loetakse üks kord konstruktoris, mitte iga meetodi kutsumise ajal
        $this->tax_field        = get_option( 'smart_wp_integtaion_maksumaar' );
        $this->payment_deadline = get_option( 'smart_wp_integtaion_maksetahtaeg' );
        $this->arve_eesliides   = get_option( 'smart_wp_integtaion_arve_eesliides' );
        $this->order_Status     = get_option( 'smart_wp_integtaion_invoice_status' );
        $this->arve_ridade_tyyp = get_option( 'smart_wp_integtaion_arve_ridade_tyyp' );
        $this->department_code  = get_option( 'smart_wp_integtaion_deparment' );
        // Vaikeladu: seadistamata = "1" (varasem käitumine), tühi = ladu ei saadeta
        $this->location_code      = (string) get_option( 'swi_merit_location_code', '1' );
        $this->shipping_tax_field = (string) get_option( 'swi_merit_shipping_maksumaar', '' );
    }

    /** Ridade ja tellimuse summa suurim lubatud vahe (sendi-ümardus); suurem vahe = rida puudu. */
    const MAX_ROUNDING = 0.05;

    /** Vaikeladu (Meriti LocationCode); tühi = ladu ei saadeta. */
    private $location_code = '1';

    /** Transpordiridade Meriti maksu UUID; tühi = sama loogika mis toodetel. */
    private $shipping_tax_field = '';

    /** Viimane build_payload_for_order() vea põhjus (null-tagastuse selgitus kasutajale). */
    public $last_error = '';

    /** Eelvaade: ära loo Meritis kliente/tooteid, ainult koosta payload. */
    public $dry_run = false;

    /**
     * Tellimuse Meriti arve number: saatmisel salvestatud number (_swi_merit_invoice_no), muidu
     * praegune eesliide + tellimuse ID. Nii jääb seos arvega alles ka pärast eesliite muutmist.
     */
    public function invoice_no_for( \WC_Order $order ): string {
        $stored = (string) $order->get_meta( '_swi_merit_invoice_no' );
        return $stored !== '' ? $stored : $this->arve_eesliides . $order->get_id();
    }

    /** Rea KM summa hoitakse ajutises võtmes '_tax' kuni TaxAmount kokkuvõtte koostamiseni. */
    const ROW_TAX_KEY = '_tax';

    /**
     * Normaliseerib nime: eemaldab algus-/lõputühikud ja asendab järjestikused tühikud ühega.
     * Ilma selleta tekitaks "John  Lennon" (topelttühik) Meritis eraldi kliendikaardi.
     */
    private static function clean_name( string $name ): string {
        return trim( (string) preg_replace( '/\s+/u', ' ', $name ) );
    }

    /**
     * Salvestab payloadi koostamise vea: tellimuse märkus + saatmise ajalugu (sama teadet ei korrata,
     * et cron iga 10 min järel märkusi ei kuhjaks) ja tagastab null.
     */
    private function fail_build( \WC_Order $order, string $message ): ?array {
        $this->last_error = $message;
        if ( $this->dry_run ) {
            return null;
        }
        if ( $order->get_meta( '_swi_merit_build_error' ) !== $message ) {
            $order->update_meta_data( '_swi_merit_build_error', $message );
            $order->save();
            $order->add_order_note( 'Merit Aktiva: arvet ei saadetud — ' . $message );
            swi_log_send_history( $order->get_id(), 'error', $message );
        }
        return null;
    }

    /** Tellimuse lisaväli: uus võti '_billing_x', vana võti 'billing_x' (Standard Books jt). */
    private static function order_field( \WC_Order $order, string $key ): string {
        $v = $order->get_meta( '_billing_' . $key );
        if ( $v === '' || $v === null ) {
            $v = $order->get_meta( 'billing_' . $key );
        }
        return trim( (string) $v );
    }

    /**
     * Leiab tootele Meriti lao: toode → põhitoode (variatsioon) → kategooria (+ ülemkategooriad) → vaikeladu.
     */
    private function resolve_location_code( $product ): string {
        if ( ! $product ) {
            return $this->location_code;
        }
        $own = (string) $product->get_meta( '_swi_merit_location_code' );
        if ( $own !== '' ) {
            return $own;
        }

        $product_id = $product->get_id();
        if ( $product->get_parent_id() ) {
            $parent = wc_get_product( $product->get_parent_id() );
            if ( $parent ) {
                $parent_loc = (string) $parent->get_meta( '_swi_merit_location_code' );
                if ( $parent_loc !== '' ) {
                    return $parent_loc;
                }
                $product_id = $parent->get_id();
            }
        }

        $cat_map = (array) get_option( 'swi_category_location_map', [] );
        if ( $cat_map ) {
            $terms = get_the_terms( $product_id, 'product_cat' );
            if ( is_array( $terms ) ) {
                // Kõige sügavam (täpsem) kategooria enne, siis selle ülemkategooriad
                usort( $terms, function ( $a, $b ) {
                    return count( get_ancestors( $b->term_id, 'product_cat' ) ) - count( get_ancestors( $a->term_id, 'product_cat' ) );
                } );
                foreach ( $terms as $term ) {
                    $chain = array_merge( [ $term->term_id ], get_ancestors( $term->term_id, 'product_cat' ) );
                    foreach ( $chain as $term_id ) {
                        $t = get_term( $term_id, 'product_cat' );
                        if ( $t && ! is_wp_error( $t ) && ! empty( $cat_map[ $t->slug ] ) ) {
                            return (string) $cat_map[ $t->slug ];
                        }
                    }
                }
            }
        }

        return $this->location_code;
    }

    /**
     * Kaubatüüp ja ladu arvereale. Laokaup (1): LocationCode + Item.DefLocationCode (Merit nõuab
     * mitme lao korral uue kauba loomisel). Teenus (2) / kaup (3): ladu ei saadeta.
     */
    private function apply_item_type_and_location( array $row ): array {
        // Transport, tasud ja summaga tagastus on alati teenus: neil pole laoseisu ega omahinda
        $type = ! empty( $row['_service'] ) ? 2 : ( (int) $this->arve_ridade_tyyp ?: 1 );
        unset( $row['_service'] );
        $row['Item']['Type'] = $type;
        $location = isset( $row['LocationCode'] ) ? (string) $row['LocationCode'] : '';
        if ( $type === 1 && $location !== '' ) {
            $row['LocationCode']            = $location;
            $row['Item']['DefLocationCode'] = $location;
        } else {
            unset( $row['LocationCode'], $row['Item']['DefLocationCode'] );
        }
        // ItemCostAmount jääb kreediitarve tooteridadele alati: Merit kasutab olemasoleva tootekaardi
        // tüüpi (mitte meie saadetut) ja nõuab laokauba krediteerimisel omahinda
        // ("Laokaubal … peab olema määratud omahind"). Teenuse ridadel seda pole.
        if ( $type === 2 ) {
            unset( $row['ItemCostAmount'] );
        }
        return $row;
    }

    /**
     * Kreeditarve rea omahind (ItemCostAmount, positiivne summa). Allikad: WooCommerce'i omahind
     * (COGS), levinud omahinna pluginate väljad, muidu müügihind. Filter võimaldab muuta.
     */
    private function credit_row_cost_amount( $item, $product, float $unit_price, float $qty ): float {
        $unit_cost = 0.0;
        if ( $product ) {
            if ( method_exists( $product, 'get_cogs_value' ) ) {
                $unit_cost = (float) $product->get_cogs_value();
            }
            foreach ( [ '_wc_cog_cost', '_alg_wc_cog_cost', '_purchase_price' ] as $meta_key ) {
                if ( $unit_cost > 0 ) break;
                $unit_cost = (float) $product->get_meta( $meta_key );
            }
        }
        if ( $unit_cost <= 0 ) {
            $unit_cost = abs( $unit_price );
        }
        $amount = round( abs( $qty ) * $unit_cost, 2 );
        return (float) apply_filters( 'swi_merit_credit_row_cost_price', $amount, $item, $product );
    }

    /**
     * Arve päise KM kokkuvõte maksutüüpide kaupa ridade '_tax' väärtustest. Ümardusvahe tegeliku
     * KM-iga lisatakse põhimaksutüübile, et kokkuvõtte summa võrduks alati tellimuse KM-iga.
     * Eemaldab ridadelt ajutise '_tax' võtme (viide).
     */
    private function build_tax_amounts( array &$rows, string $main_tax_id, float $total_tax ): array {
        $by_tax = [];
        foreach ( $rows as &$row ) {
            $id = $row['TaxId'] ?: $main_tax_id;
            $by_tax[ $id ] = ( $by_tax[ $id ] ?? 0.0 ) + (float) ( $row[ self::ROW_TAX_KEY ] ?? 0 );
            unset( $row[ self::ROW_TAX_KEY ] );
        }
        unset( $row );
        if ( ! isset( $by_tax[ $main_tax_id ] ) ) {
            $by_tax = [ $main_tax_id => 0.0 ] + $by_tax;
        }
        $by_tax = array_map( function ( $v ) { return round( $v, 2 ); }, $by_tax );
        $by_tax[ $main_tax_id ] = round( $by_tax[ $main_tax_id ] + ( $total_tax - array_sum( $by_tax ) ), 2 );

        $result = [];
        foreach ( $by_tax as $id => $amount ) {
            $result[] = [ 'TaxId' => $id, 'Amount' => $amount ];
        }
        return $result;
    }

    /**
     * KM, mille Merit arveridade maksude järgi arvutaks: sum(rea neto × maksu %).
     * Maksuprotsendid tulevad Meritist laaditud vahemälust; kui mõnda rea maksu seal pole
     * (maksumäärasid pole laaditud), tagastab null ja kontroll jäetakse vahele.
     *
     * @return array|null ['total' => float, 'names' => string[]]
     */
    private function expected_merit_tax( array $rows ): ?array {
        $pct = $names = [];
        foreach ( (array) get_option( 'swi_merit_taxes_cache', [] ) as $t ) {
            if ( ! empty( $t['Id'] ) ) {
                $pct[ $t['Id'] ]   = (float) ( $t['TaxPct'] ?? 0 );
                $names[ $t['Id'] ] = (string) ( $t['Name'] ?: $t['Code'] );
            }
        }
        $total = 0.0;
        $used  = [];
        foreach ( $rows as $row ) {
            $id = (string) ( $row['TaxId'] ?? '' );
            if ( ! isset( $pct[ $id ] ) ) {
                return null;
            }
            $total      += round( (float) $row['Price'] * (float) $row['Quantity'], 2 ) * $pct[ $id ] / 100;
            $used[ $id ] = $names[ $id ];
        }
        return [ 'total' => round( $total, 2 ), 'names' => array_values( $used ) ];
    }

    /** Ridade neto summa nii, nagu Merit seda arvutab (iga rida ümardatud 2 kohta). */
    private static function rows_net_total( array $rows ): float {
        $sum = 0.0;
        foreach ( $rows as $row ) {
            $sum += round( (float) $row['Price'] * (float) $row['Quantity'], 2 );
        }
        return round( $sum, 2 );
    }

    /**
     * Leiab eraisiku Meriti CustomerId: tellimuse meta → kasutaja meta → Meriti otsing täpse nime järgi
     * (või uue kaardi loomine). Ilma Id-ta seoks Merit kõik eraisikud esimese eraisiku kaardiga.
     *
     * @return string|null CustomerId; null kui nimi puudub või eelvaade.
     * @throws \Exception Meriti/vaheserveri vea korral.
     */
    private function resolve_private_customer_id( \WC_Order $order, array $customer ): ?string {
        $cached = (string) $order->get_meta( '_swi_merit_customer_id' );
        if ( $cached !== '' ) {
            return $cached;
        }
        $user_id = $order->get_customer_id();
        if ( $user_id ) {
            $user_cached = (string) get_user_meta( $user_id, '_swi_merit_customer_id', true );
            if ( $user_cached !== '' ) {
                return $user_cached;
            }
        }
        if ( $customer['Name'] === '' || $this->dry_run ) {
            return null;
        }

        $client = new MeritServersDataClient();
        $res    = $client->resolve_customer( [
            'name'             => $customer['Name'],
            'email'            => $customer['Email'] ?? '',
            'country_code'     => $customer['CountryCode'] ?? 'EE',
            'address'          => $customer['Address'] ?? null,
            'city'             => $customer['City'] ?? null,
            'county'           => $customer['County'] ?? null,
            'postal_code'      => $customer['PostalCode'] ?? null,
            'phone'            => $customer['PhoneNo'] ?? null,
            'currency_code'    => $customer['CurrencyCode'] ?? 'EUR',
            'payment_deadline' => $customer['PaymentDeadLine'] ?? 7,
        ] );
        $id = (string) ( $res['customer_id'] ?? '' );
        if ( $id === '' ) {
            throw new \RuntimeException( 'Merit ei tagastanud kliendi ID-d.' );
        }

        $order->update_meta_data( '_swi_merit_customer_id', $id );
        $order->save();
        if ( ! empty( $res['created'] ) ) {
            $order->add_order_note( 'Merit Aktiva: loodi uus kliendikaart (' . $customer['Name'] . ').' );
        }
        return $id;
    }

    /**
     * Laeb admin-lehel JS-faili ja annab ajaxurl ning nonce JavaScripti kätte.
     *
     * wp_localize_script on WordPressi õige tee PHP muutujate JS-sse edastamiseks —
     * alternatiiv inline-skriptile, aga turvalisem ja cacheable.
     */
    public function add_cron_interval( array $schedules ): array {
        if ( ! isset( $schedules['swi_10min'] ) ) {
            $schedules['swi_10min'] = [ 'interval' => 600, 'display' => 'Iga 10 minuti järel' ];
        }
        return $schedules;
    }

    public function cron_send_orders(): void {
        if ( get_option( 'smart_wp_integtaion_enable' ) !== 'yes' ) return;

        $status = get_option( 'smart_wp_integtaion_invoice_status', 'wc-completed' );

        // Saadame ainult tellimusi, mis on loodud pärast selle kontrolli esimest käivitust —
        // muidu saadaks plugin uues poes kõik vanad tellimused korraga Meritisse.
        $since = (int) get_option( 'swi_merit_sweep_since', 0 );
        if ( ! $since ) {
            $since = time();
            update_option( 'swi_merit_sweep_since', $since, false );
        }

        $orders = wc_get_orders( [ 'type' => 'shop_order',
            'limit'        => 20,
            'status'       => $status,
            'orderby'      => 'id',
            'order'        => 'ASC',
            // Värskeid (alla 2 min) ei puutu — kassa auto-saatmine võib veel käia
            'date_created' => $since . '...' . ( time() - 120 ),
            'meta_query'   => [
                'relation' => 'AND',
                [ 'key' => '_swi_sent_merit', 'compare' => 'NOT EXISTS' ],
                [ 'key' => '_swi_merit_deleted', 'compare' => 'NOT EXISTS' ],   // arve kustutati teadlikult
                [ 'key' => '_swi_merit_retry', 'compare' => 'NOT EXISTS' ],     // korduskatse cron tegeleb
                [ 'key' => '_swi_merit_send_error', 'compare' => 'NOT EXISTS' ], // loobutud — admin-teade
            ],
        ] );

        foreach ( $orders as $order ) {
            if ( $order->get_meta( '_swi_sent_merit' ) ) continue;

            $payload = $this->build_payload_for_order( $order );
            if ( ! $payload ) continue;

            $res = LocalApiClient::sendEncryptedOrder( $payload, 'merit' );
            if ( isset( $res['status'] ) && in_array( $res['status'], [ 'ok', 'queued' ], true ) ) {
                $order->update_meta_data( '_swi_sent_merit', current_time( 'mysql' ) );
                $order->delete_meta_data( '_swi_merit_retry' );
                $order->delete_meta_data( '_swi_merit_retry_count' );
                $order->save();
                $order->add_order_note( 'Merit Aktiva: arve edastatud cron-iga (' . $res['status'] . ').' );
                swi_log_send_history( $order->get_id(), 'ok', 'Cron: ' . ( $res['message'] ?? $res['status'] ) );
            } else {
                $count = (int) $order->get_meta( '_swi_merit_retry_count' ) + 1;
                $order->update_meta_data( '_swi_merit_retry', '1' );
                $order->update_meta_data( '_swi_merit_retry_count', $count );
                $order->save();
                swi_merit_set_send_error( $order, swi_humanize_merit_error( $res ) );
                swi_log_send_history( $order->get_id(), 'error', 'Cron ebaõnnestus (katse ' . $count . '): ' . ( $res['message'] ?? 'Tundmatu viga' ) );
            }

            usleep( 300000 );
        }
    }

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
        // Diagnostika: iga vaikne väljumine kirjutab põhjuse debug.log-i (ainult WP_DEBUG korral),
        // et oleks alati näha, miks tellimus automaatselt Meritisse ei läinud.
        $skip = function ( string $why ) use ( $order_id, $old_status, $new_status ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( "SWI Merit auto-send #{$order_id} ({$old_status} → {$new_status}) vahele: {$why}" );
            }
        };

        if ( get_option( 'smart_wp_integtaion_enable' ) !== 'yes' ) {
            $skip( 'Merit Aktiva pole lubatud' );
            return;
        }

        $configured = get_option( 'smart_wp_integtaion_invoice_status', 'wc-completed' );
        if ( 'wc-' . $new_status !== $configured ) {
            $skip( "staatus wc-{$new_status} ≠ seadistatud {$configured}" );
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            $skip( 'tellimust ei leitud' );
            return;
        }

        // HPOS-ühilduv meta-lugemine: get_meta() töötab nii vana postmeta kui uue wc_orders_meta tabeliga
        if ( $order->get_meta( '_swi_sent_merit' ) ) {
            $skip( 'juba saadetud' );
            return;
        }

        $payload = $this->build_payload_for_order( $order );
        if ( ! $payload ) {
            $skip( 'payload puudub — ' . ( $this->last_error ?: 'tundmatu põhjus' ) );
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
            swi_merit_set_send_error( $order, $msg );
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

        $orders  = wc_get_orders( [ 'type' => 'shop_order', 'limit' => -1 ] );
        $results = [];
        try {
            $merit_server_invoices = new MeritServersDataClient();
            $results               = $merit_server_invoices->get_all_invoices();
        } catch ( \Exception $e ) {
            // Merit API pole kättesaadav — jätka ainult meta-flag kontrolliga
        }
        $filtered = [];

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
                    if ( $this->invoice_no_for( $order ) === $invoice->InvoiceNo ) {
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
    /**
     * Loob Meriti kliendikaardi registreeritud WC kasutajale, kui see veel puudub
     * ('_swi_merit_customer_id' user meta).
     *
     * Külalisostjaid teadlikult ei sync'ita — pole usaldusväärset võtit (kasutaja ID),
     * mille järgi korduskülastusel sama kirjet ära tunda, ja Merit nõuab unikaalset Name't,
     * mistõttu korduvad külalisostud looksid Meritisse müra või ebaõnnestuksid nime konfliktiga.
     *
     * @param \WC_Order $order    WooCommerce tellimus.
     * @param array     $customer Juba koostatud Merit Customer massiiv (Name, CountryCode jne).
     */
    private function maybe_sync_customer_to_merit( \WC_Order $order, array $customer ): void {
        $user_id = $order->get_customer_id();
        if ( ! $user_id || get_user_meta( $user_id, '_swi_merit_customer_id', true ) ) {
            return;
        }
        if ( empty( $customer['Name'] ) || empty( $customer['CountryCode'] ) ) {
            return;
        }

        try {
            $client = new MeritServersDataClient();
            $result = $client->sync_customer( [
                'name'            => $customer['Name'],
                'country_code'    => $customer['CountryCode'],
                'not_td_customer' => $customer['NotTDCustomer'] ?? true,
                'reg_no'          => $customer['RegNo'] ?? null,
                'vat_reg_no'      => $customer['VatRegNo'] ?? null,
                'currency_code'   => $customer['CurrencyCode'] ?? 'EUR',
                'address'         => $customer['Address'] ?? null,
                'city'            => $customer['City'] ?? null,
                'postal_code'     => $customer['PostalCode'] ?? null,
                'phone'           => $customer['PhoneNo'] ?? null,
                'email'           => $customer['Email'] ?? null,
            ] );
            $merit_id = $result['merit_response']['Id'] ?? null;
            if ( $merit_id ) {
                update_user_meta( $user_id, '_swi_merit_customer_id', $merit_id );
            }
        } catch ( \Exception $e ) {
            // Vaikimisi ignoreeri — vt meetodi docblock, sync ebaõnnestumine ei tohi takistada arvet
        }
    }

    public function build_payload_for_order( \WC_Order $order ): ?array {
        $country_settings = get_option( 'smart_wp_integtaion_country_map', [] );

        // Riigi tuvastamine: eelistame arveaadressi, fallback tarneaadressile
        $country = $order->get_billing_country() ?: $order->get_shipping_country();
        if ( empty( $country ) ) {
            // Kui mõlemat pole, kasuta riiki mis on märgitud vaikimisi riigiks
            foreach ( $country_settings as $cfg ) {
                if ( ! empty( $cfg['default'] ) ) { $country = $cfg['country'] ?? ''; break; }
            }
        }

        // Maksutüüp ridadele: vaikimisi leitakse iga rea KM määra järgi Maksude kaardistusest.
        // Riigi seadistus (nt OSS) sunnib kõik read ühe maksu alla ainult mitte-vaikimisi riigil.
        $vat_code = null;
        if ( ! empty( $country ) && ! empty( $country_settings ) ) {
            foreach ( $country_settings as $row ) {
                if ( ( $row['country'] ?? '' ) === $country ) {
                    if ( empty( $row['default'] ) && self::is_guid( $row['vat_code'] ?? '' ) ) {
                        $vat_code = $row['vat_code'];
                    }
                    break;
                }
            }
        }
        $this->last_error = '';

        // Ettevõte: ettevõtte nimi või registrikood tellimusel (checkout väljad _billing_reg_no / _billing_vat_no)
        $company_name = self::clean_name( (string) $order->get_billing_company() );
        $reg_no       = self::order_field( $order, 'reg_no' );
        $vat_no       = self::order_field( $order, 'vat_no' );
        $is_company   = $company_name !== '' || $reg_no !== '';

        // EL-i KMKR-iga ettevõte teisest liikmesriigist, KM-ita tellimus → pöördmaksustamise maks
        $eu_reverse_tax = (string) get_option( 'swi_merit_tax_eu_reverse', '' );
        if ( $is_company && $vat_no !== '' && self::is_guid( $eu_reverse_tax ) && (float) $order->get_total_tax() == 0.0 ) {
            $eu       = WC()->countries ? WC()->countries->get_european_union_countries() : [];
            $base_cc  = WC()->countries ? WC()->countries->get_base_country() : 'EE';
            if ( $country && $country !== $base_cc && in_array( $country, $eu, true ) ) {
                $vat_code = $eu_reverse_tax;
            }
        }
        $person_name  = self::clean_name( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

        // NotTDCustomer = true tähendab eraisik (ei ole käibemaksukohustuslane)
        $customer = [
            'Name'            => ( $is_company && $company_name !== '' ) ? $company_name : $person_name,
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
        // RegNo ja VatRegNo on Merit API-s kohustuslikud väljad ainult äriklientidel.
        // Võetakse tellimuselt — mitte poe enda registrikoodi seadest ('regno').
        if ( $is_company ) {
            $customer['RegNo']    = $reg_no;
            $customer['VatRegNo'] = $vat_no;
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

        if ( $is_company ) {
            // Ettevõtte seob Merit registrikoodi järgi; registreeritud kasutajale luuakse kaart ka ette
            if ( ! $this->dry_run ) {
                $this->maybe_sync_customer_to_merit( $order, $customer );
            }
        } else {
            // Eraisik: leiame/loome kliendikaardi ise ja saadame arve kindla Id-ga — muidu seob
            // Merit kõik eraisikud esimese eraisiku kaardiga
            try {
                $customer_id = $this->resolve_private_customer_id( $order, $customer );
            } catch ( \Exception $e ) {
                return $this->fail_build( $order, 'Kliendi leidmine/loomine Meritis ebaõnnestus — ' . $e->getMessage() );
            }
            if ( $customer_id ) {
                $customer = [ 'Id' => $customer_id ] + $customer;
            }
        }

        $rows = $this->create_invoice_items_array( $order, $vat_code );

        // TotalAmount = tellimuse summa ilma KM-ita. Ridade ja selle vahe (sendi-ümardus) läheb
        // RoundingAmount'i; suurem vahe tähendab, et mõni tellimuse osa jäi arveridadelt välja.
        $order_total_tax = round( (float) $order->get_total_tax(), 2 );
        $total_amount    = round( (float) $order->get_total() - $order_total_tax, 2 );
        $rounding_amount = round( $total_amount - self::rows_net_total( $rows ), 2 );
        if ( abs( $rounding_amount ) > self::MAX_ROUNDING ) {
            return $this->fail_build( $order, sprintf(
                'Arveridade summa (%s) ei võrdu tellimuse summaga ilma KM-ita (%s), vahe %s. Mõni tellimuse osa (toode, tasu, transport või allahindlus) ei jõudnud arveridadele.',
                number_format( $total_amount - $rounding_amount, 2, ',', '' ),
                number_format( $total_amount, 2, ',', '' ),
                number_format( $rounding_amount, 2, ',', '' )
            ) );
        }

        // Merit arvutab KM-i ise arverea maksu (TaxId) järgi, meie saadetud summast sõltumata. Kui valitud
        // maks annaks teise KM-i kui tellimusel (nt pood maksudeta, aga maks 13%), tuleks Meritis arve
        // teise summaga kui klient maksis — sellist arvet ei saada.
        $merit_tax = $this->expected_merit_tax( $rows );
        if ( $merit_tax !== null && abs( $merit_tax['total'] - $order_total_tax ) > self::MAX_ROUNDING ) {
            return $this->fail_build( $order, sprintf(
                'Tellimusel on KM %s €, aga Meriti maks (%s) annaks arvele KM %s € — arve summa ei klapiks tellimusega. Kontrolli WooCommerce\'i maksuseadeid ja plugina Maksude kaardistust (või "Maks KM-ita ridadele").',
                number_format( $order_total_tax, 2, ',', '' ),
                implode( ', ', $merit_tax['names'] ),
                number_format( $merit_tax['total'], 2, ',', '' )
            ) );
        }

        // Merit nõuab TaxAmount massiivi, kus iga maksu UUID kohta on eraldi KM summa
        // (ühel arvel võib olla mitu määra, nt tooted 24% + transport teise määraga).
        $tax_amount_arr = $this->build_tax_amounts( $rows, $vat_code ?: $this->default_tax_id(), $order_total_tax );

        if ( $order->get_meta( '_swi_merit_build_error' ) && ! $this->dry_run ) {
            $order->delete_meta_data( '_swi_merit_build_error' );
            $order->save();
        }

        // Arve kuupäev = tellimuse loomise kuupäev; tähtaeg = loomise kuupäev + maksetähtaeg
        $doc_date = $order->get_date_created()
            ? $order->get_date_created()->date( 'Ymd' )
            : gmdate( 'Ymd' );
        $due_date = gmdate( 'Ymd', strtotime( $doc_date . ' +' . max( 1, (int) $this->payment_deadline ) . ' days' ) );

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
            'InvoiceNo'      => $this->invoice_no_for( $order ),
            'DepartmentCode' => $dept_code,
            'InvoiceRow'     => $rows,
            'TotalAmount'    => $total_amount,
            'RoundingAmount' => $rounding_amount,
            'TaxAmount'      => $tax_amount_arr,
        ];

        // Makstud tellimus → arve luuakse Meritis kohe tasutuna (makseviis valitud Maksete kaardistuses)
        $payment = $this->payment_object_for_order( $order );
        if ( $payment ) {
            $payload['Payment'] = $payment;
        }

        // Arve number kinnitatakse tellimusele: kordussaatmine, nupud, PDF ja kreediitarve kasutavad
        // sama numbrit ka siis, kui eesliidet hiljem muudetakse (eelvaade numbrit ei salvesta)
        if ( ! $this->dry_run && $order->get_meta( '_swi_merit_invoice_no' ) === '' ) {
            $order->update_meta_data( '_swi_merit_invoice_no', $payload['InvoiceNo'] );
            $order->save();
        }
        if ( ! $this->dry_run && $order->get_meta( '_swi_merit_deleted' ) ) {
            $order->delete_meta_data( '_swi_merit_deleted' );
            $order->save();
        }

        return $payload;
    }

    /**
     * Ehitab Merit API kreediitarve payloadi WC refundi põhjal.
     *
     * Taaskasutab build_payload_for_order() kliendi/osakonna/makseviisi loogikat, aga InvoiceRow/
     * TotalAmount/TaxAmount võetakse refundi pealt — WooCommerce salvestab refundi ridade summad
     * juba negatiivsena, ja Merit API käsitleb negatiivse kogusega sendinvoice päringut
     * automaatselt kreediitarvena (vt api.merit.ee "Create Credit Invoice").
     *
     * @param \WC_Order        $order  Algne WooCommerce tellimus.
     * @param \WC_Order_Refund $refund Konkreetne refund, mille kohta kreediitarve luuakse.
     * @return array|null Merit API payload või null kui baaspayload ei õnnestunud.
     */
    public function build_credit_payload_for_refund( \WC_Order $order, \WC_Order_Refund $refund ): ?array {
        $base = $this->build_payload_for_order( $order );
        if ( ! $base ) {
            return null;
        }

        $main_tax_id = $this->default_tax_id();
        $rows        = $this->create_invoice_items_array( $refund );

        if ( empty( $rows ) ) {
            // Ainult summaga tagastus (ilma ridadeta): üks krediteeritav rida. Neto ja KM jagatakse
            // algse tellimuse KM-i suhte järgi, kuna refundil endal maksuinfot pole.
            $gross = round( abs( (float) $refund->get_amount() ), 2 );
            if ( $gross <= 0 ) {
                return null;
            }
            $order_tax = (float) $order->get_total_tax();
            $order_net = (float) $order->get_total() - $order_tax;
            $ratio     = $order_net > 0 ? $order_tax / $order_net : 0.0;
            $net       = round( $gross / ( 1 + $ratio ), 2 );

            $rows = [ $this->apply_item_type_and_location( [
                'Item'           => [
                    'Code'        => 'REFUND',
                    'Description' => 'Tagastus' . ( $refund->get_reason() ? ': ' . $refund->get_reason() : '' ),
                    'UOMName'     => 'tk',
                ],
                'Quantity'       => -1.0,
                'Price'          => $net,
                'DiscountPct'    => 0,
                'DiscountAmount' => 0,
                'TaxId'          => $main_tax_id,
                'LocationCode'   => $this->location_code,
                self::ROW_TAX_KEY => -round( $gross - $net, 2 ),
                '_service'       => true,
            ] ) ];
            $total_tax = -round( $gross - $net, 2 );
        } else {
            $total_tax = 0.0;
            foreach ( $rows as $row ) {
                $total_tax += (float) ( $row[ self::ROW_TAX_KEY ] ?? 0 );
            }
            $total_tax = round( $total_tax, 2 );
        }

        $total_amount   = self::rows_net_total( $rows );
        $tax_amount_arr = $this->build_tax_amounts( $rows, $main_tax_id, $total_tax );

        // Kreediitarve number peab Meriti jaoks olema unikaalne — algse arve numbrile lisame refundi ID
        $base['InvoiceNo']   = $this->invoice_no_for( $order ) . '-C' . $refund->get_id();
        $base['DocDate']     = $refund->get_date_created() ? $refund->get_date_created()->date( 'Ymd' ) : gmdate( 'Ymd' );
        $base['DueDate']     = $base['DocDate'];
        $base['InvoiceRow']  = $rows;
        $base['TotalAmount']    = $total_amount;
        $base['RoundingAmount'] = 0.0;
        $base['TaxAmount']      = $tax_amount_arr;
        // Algse arve makse ei kuulu kreeditarvele
        unset( $base['Payment'] );

        return $base;
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
        foreach ( (array) get_option( 'smart_wp_integtaion_tax_map', [] ) as $row ) {
            if ( isset( $row['rate'] ) && $row['rate'] !== '' && abs( (float) $row['rate'] - $rate_pct ) < 0.01
                && self::is_guid( $row['uuid'] ?? '' ) ) {
                return $row['uuid'];
            }
        }
        // KM-ita rida (nt eksport väljapoole EL-i), mille määra kaardistuses pole → 0% maks seadest
        $zero = (string) get_option( 'swi_merit_tax_zero', '' );
        if ( $rate_pct < 0.01 && self::is_guid( $zero ) ) {
            return $zero;
        }
        return $this->default_tax_id();
    }

    /**
     * Vaikimisi maks: Maksude kaardistuse "Vaikimisi" rida. Varuna vana üldseade
     * (smart_wp_integtaion_maksumaar — väli eemaldatud, väärtus jääb vanadele paigaldustele).
     * Vigased (mitte-GUID) väärtused jäetakse vahele — Merit nõuab TaxId GUID-i.
     */
    private function default_tax_id(): string {
        foreach ( (array) get_option( 'smart_wp_integtaion_tax_map', [] ) as $row ) {
            if ( ( $row['is_default'] ?? '' ) === 'yes' && self::is_guid( $row['uuid'] ?? '' ) ) {
                return $row['uuid'];
            }
        }
        return (string) $this->tax_field;
    }

    private static function is_guid( $value ): bool {
        return is_string( $value ) && (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value );
    }

    /**
     * Meriti makseobjekt arve loomisel (sendinvoice Payment), kui tellimus on makstud ja selle
     * makseviis on seotud Meriti makseviisiga. Merit viitab makseviisile NIME järgi, seega
     * arvestatakse ainult Meritist laaditud makseviise (vanad konto koodid nt "1000" jäetakse välja).
     */
    private function payment_object_for_order( \WC_Order $order ): ?array {
        $map  = (array) get_option( 'smart_wp_integtaion_payment_map', [] );
        $name = trim( (string) ( $map[ $order->get_payment_method() ] ?? '' ) );
        if ( $name === '' ) {
            return null;
        }
        $types = (array) get_option( 'swi_merit_payment_types_cache', [] );
        if ( ! in_array( $name, array_column( $types, 'Name' ), true ) ) {
            return null;
        }
        if ( ! $order->is_paid() && ! $order->get_date_paid() ) {
            return null;
        }
        $date = $order->get_date_paid() ?: $order->get_date_created();
        return [
            'PaymentMethod' => $name,
            'PaidAmount'    => round( (float) $order->get_total(), 2 ),
            'PaymDate'      => $date ? $date->date( 'YmdHi' ) : gmdate( 'YmdHi' ),
        ];
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
        // abs(): refundi real on summad negatiivsed
        $total     = abs( (float) $item->get_total() );
        $total_tax = abs( (float) $item->get_total_tax() );
        if ( $total <= 0 ) return 0.0;
        return round( $total_tax / $total * 100, 2 );
    }

    /**
     * Loob tootele Meriti tootekaardi, kui see puudub ('_swi_merit_item_id' product meta).
     *
     * Käivitub arve koostamise käigus (lazy sync) — seega sama usaldusväärne/nähtav tegevuspunkt
     * mis juba olemasolev arve saatmine, mitte eraldi uus automaathook toote salvestamisel.
     * Ebaõnnestumine ei tohi takistada arve saatmist: Merit aktsepteerib ka tundmatut koodi,
     * lihtsalt ei seo seda laoseisuga.
     *
     * @param \WC_Product $product WooCommerce toode.
     */
    private function maybe_sync_item_to_merit( \WC_Product $product ): void {
        if ( $product->get_meta( '_swi_merit_item_id' ) ) {
            return;
        }
        $sku = $product->get_sku();
        if ( ! $sku ) {
            return; // Merit Item 'Code' on kohustuslik — SKU-ta toodet pole mõtet sync'ida
        }

        try {
            $client = new MeritServersDataClient();
            $result = $client->sync_item( [
                'code'        => substr( $sku, 0, 20 ),
                'description' => substr( $product->get_name(), 0, 100 ),
                'type'        => (int) $this->arve_ridade_tyyp,
                'usage'       => 1,
            ] );
            $item_id = $result['merit_response'][0]['ItemId'] ?? null;
            if ( $item_id ) {
                $product->update_meta_data( '_swi_merit_item_id', $item_id );
                $product->save();
            }
        } catch ( \Exception $e ) {
            // Vaikimisi ignoreeri — vt meetodi docblock
        }
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
     * Kogus ($qty) säilitab WooCommerce'i originaalmärgi — tavatellimusel on see alati ≥1,
     * aga refundi real on WooCommerce kogus juba negatiivne (nt -1). See on tahtlik: Merit
     * API kreediitarve nõuab negatiivset kogust positiivse ühikuhinnaga (vt api.merit.ee
     * "Create Credit Invoice"), mitte positiivset kogust negatiivse hinnaga.
     *
     * @param \WC_Abstract_Order $order     WooCommerce tellimuse (või refundi) objekt. WC_Order_Refund
     *                                       jagab WC_Order'iga ühist baasklassi WC_Abstract_Order ja
     *                                       selle read on WooCommerce's juba negatiivse summaga —
     *                                       seetõttu saab seda meetodit taaskasutada kreediitarve ridade ehitamiseks.
     * @param string|null $vat_code_override Kui antud, kasutatakse seda UUID kõigi ridade jaoks.
     * @return array Merit API InvoiceRow massiiv.
     */
    public function create_invoice_items_array( \WC_Abstract_Order $order, ?string $vat_code_override = null ): array {
        $payload_arrays = [];

        foreach ( $order->get_items() as $item ) {
            $product  = $item->get_product();
            $sku      = $product ? $product->get_sku() : '';
            $qty_raw  = (int) $item->get_quantity();
            // Kogus 0: refundis ainult rea summa (WooCommerce'i "Refund amount" on lukus, summa
            // sisestatakse rea tagastussumma väljale). Kreediitarvel peab kogus olema negatiivne ja
            // hind positiivne → -1 × summa. Tavatellimusel jääb varasem käitumine (1).
            if ( $qty_raw !== 0 ) {
                $qty = $qty_raw;
            } else {
                $qty = ( $order instanceof \WC_Order_Refund || (float) $item->get_total() < 0 ) ? -1 : 1;
            }
            // Ühikuhind = kogusumma / kogus (neto, käibemaksuta) — 4 kümnendkohta täpsuse säilitamiseks
            $price_ex = round( (float) $item->get_total() / $qty, 4 );
            $tax_uuid = $vat_code_override ?: $this->resolve_vat_uuid( $this->item_tax_rate( $item ) );

            // Lazy toote sync: ainult päris tellimuse (mitte refundi) ridade ehitamisel,
            // ja ainult kui toodet ei ole veel Meriti tootekataloogis
            if ( $order instanceof \WC_Order && $product && ! $this->dry_run ) {
                $this->maybe_sync_item_to_merit( $product );
            }

            $row = [
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
                'LocationCode'   => $this->resolve_location_code( $product ),
                self::ROW_TAX_KEY => (float) $item->get_total_tax(),
            ];
            // Laokauba krediteerimisel nõuab Merit omahinda (ItemCostAmount); eemaldatakse mitte-laokaubal
            if ( $order instanceof \WC_Order_Refund ) {
                $row['ItemCostAmount'] = $this->credit_row_cost_amount( $item, $product, $price_ex, $qty );
            }
            $payload_arrays[] = $row;
        }

        $shipping_map = get_option( 'smart_wp_integtaion_shipping_map', [] );
        foreach ( $order->get_shipping_methods() as $shipping_item ) {
            $shipping_total = (float) $shipping_item->get_total();
            if ( $shipping_total <= 0 ) continue;
            $ship_tax    = (float) $shipping_item->get_total_tax();
            $ship_rate   = $shipping_total > 0 ? round( $ship_tax / $shipping_total * 100, 2 ) : 0.0;
            // Transpordil võib olla oma maksutüüp (seade), muidu sama loogika mis toodetel
            $tax_uuid    = $this->shipping_tax_field ?: ( $vat_code_override ?: $this->resolve_vat_uuid( $ship_rate ) );
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
                'LocationCode'   => $this->location_code,
                self::ROW_TAX_KEY => $ship_tax,
                '_service'       => true,
            ];
        }

        // Tasulised lisandused (nt Advanced Product Fields'i soovikaart) lähevad WooCommerce'is
        // 'fee' tüüpi tellimuseridadena ja ei sisaldu get_items()-is — vajavad eraldi Merit rida,
        // vastasel juhul jääb see summa arvelt täielikult välja.
        foreach ( $order->get_fees() as $fee_item ) {
            $fee_total = (float) $fee_item->get_total();
            if ( $fee_total == 0.0 ) continue;
            $fee_tax  = (float) $fee_item->get_total_tax();
            $fee_rate = $fee_total > 0 ? round( $fee_tax / $fee_total * 100, 2 ) : 0.0;
            $tax_uuid = $vat_code_override ?: $this->resolve_vat_uuid( $fee_rate );

            $payload_arrays[] = [
                'Item'           => [
                    'Code'        => 'FEE',
                    'Description' => $fee_item->get_name(),
                    'Type'        => (int) $this->arve_ridade_tyyp,
                    'UOMName'     => 'tk',
                ],
                'Quantity'       => 1,
                'Price'          => round( $fee_total, 4 ),
                'DiscountPct'    => 0,
                'DiscountAmount' => 0,
                'TaxId'          => $tax_uuid,
                'LocationCode'   => $this->location_code,
                self::ROW_TAX_KEY => $fee_tax,
                '_service'       => true,
            ];
        }

        // Kaubatüüp kõigile ridadele; ladu (+ Item.DefLocationCode) ainult laokaubal
        return array_map( [ $this, 'apply_item_type_and_location' ], $payload_arrays );
    }

    /**
     * AJAX handler: saada kõik saatmata orderid Meriti käsitsi.
     *
     * Kutsutakse admin-lehelt "Saada arved" nupuga. Ebaõnnestunud orderid märgitakse
     * retry-ks, et cron saaks neid hiljem automaatselt uuesti proovida.
     */
    public function handle_order_send(): void {
        if ( ! check_ajax_referer( 'swi_merit_order_send', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Vigane nonce.' ] );
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Pole luba.' ] );
        }
        $order_id = (int) ( $_POST['order_id'] ?? 0 );
        $order    = $order_id ? wc_get_order( $order_id ) : null;
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => 'Orderit ei leitud.' ] );
        }
        $payload = $this->build_payload_for_order( $order );
        if ( ! $payload ) {
            wp_send_json_error( [ 'message' => $this->last_error ?: 'Payload loomine ebaõnnestus.' ] );
        }
        $res = LocalApiClient::sendEncryptedOrder( $payload, 'merit' );
        if ( in_array( $res['status'] ?? '', [ 'ok', 'queued' ], true ) ) {
            $order->update_meta_data( '_swi_sent_merit', current_time( 'mysql' ) );
            $order->delete_meta_data( '_swi_merit_retry' );
            $order->save();
            wp_send_json_success( [ 'message' => 'Saadetud.' ] );
        } else {
            wp_send_json_error( [ 'message' => $res['message'] ?? 'Saatmine ebaõnnestus.' ] );
        }
    }

    /**
     * AJAX handler: märgi Meriti arve tasutuks ("Märgi makstuks Meritis" nupp).
     *
     * Käsitsi nupp, mitte automaatne hook — makse saatmine Meritisse on raamatupidamisele
     * nähtav tegevus ja jääb teadlikult admini otsustada, mitte käivituda iga order-staatuse
     * muutuse peale iseseisvalt.
     */
    public function handle_send_payment(): void {
        if ( ! check_ajax_referer( 'swi_merit_send_payment', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Vigane nonce.' ] );
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Pole luba.' ] );
        }
        $order_id = (int) ( $_POST['order_id'] ?? 0 );
        $order    = $order_id ? wc_get_order( $order_id ) : null;
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => 'Orderit ei leitud.' ] );
        }
        if ( ! $order->get_meta( '_swi_sent_merit' ) ) {
            wp_send_json_error( [ 'message' => 'Arve pole veel Meritisse saadetud.' ] );
        }
        $paid_in_invoice = $this->payment_object_for_order( $order );
        if ( $paid_in_invoice ) {
            wp_send_json_error( [ 'message' => 'Arve saadeti Meritisse juba tasutuna (makseviis "' . $paid_in_invoice['PaymentMethod'] . '") — eraldi makset pole vaja.' ] );
        }

        $bank_id = (string) get_option( 'swi_merit_payment_bank_id', '' );
        if ( $bank_id === '' ) {
            wp_send_json_error( [ 'message' => 'Vali Meriti seadetes (Osakonnad → Makse pangakonto) pangakonto, kuhu makse registreeritakse.' ] );
        }

        $invoice_no = $this->invoice_no_for( $order );

        try {
            $client = new MeritServersDataClient();
            $client->send_payment(
                $invoice_no,
                (float) $order->get_total(),
                gmdate( 'Y-m-d' ),
                trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                $bank_id
            );
        } catch ( \Exception $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }

        $order->update_meta_data( '_swi_merit_payment_sent', current_time( 'mysql' ) );
        $order->add_order_note( 'Merit Aktiva: makse märgitud arvele ' . $invoice_no . '.' );
        $order->save();

        wp_send_json_success( [ 'message' => 'Makse märgitud.' ] );
    }

    /**
     * AJAX handler: loo Meritis kreediitarve viimase veel krediteerimata refundi kohta
     * ("Loo kreediitarve" nupp).
     */
    public function handle_create_credit_invoice(): void {
        if ( ! check_ajax_referer( 'swi_merit_create_credit', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Vigane nonce.' ] );
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Pole luba.' ] );
        }
        $order_id = (int) ( $_POST['order_id'] ?? 0 );
        $order    = $order_id ? wc_get_order( $order_id ) : null;
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => 'Orderit ei leitud.' ] );
        }
        if ( ! $order->get_meta( '_swi_sent_merit' ) ) {
            wp_send_json_error( [ 'message' => 'Algne arve pole veel Meritisse saadetud.' ] );
        }

        $refund = null;
        foreach ( array_reverse( $order->get_refunds() ) as $candidate ) {
            if ( ! $candidate->get_meta( '_swi_merit_credit_sent' ) ) {
                $refund = $candidate;
                break;
            }
        }
        if ( ! $refund ) {
            wp_send_json_error( [ 'message' => 'Ühtegi krediteerimata refundi ei leitud.' ] );
        }

        $payload = $this->build_credit_payload_for_refund( $order, $refund );
        if ( ! $payload ) {
            wp_send_json_error( [ 'message' => $this->last_error ?: 'Kreediitarve payloadi loomine ebaõnnestus.' ] );
        }

        $res = LocalApiClient::sendEncryptedOrder( $payload, 'merit' );
        if ( in_array( $res['status'] ?? '', [ 'ok', 'queued' ], true ) ) {
            $refund->update_meta_data( '_swi_merit_credit_sent', current_time( 'mysql' ) );
            $refund->save();
            $order->add_order_note( 'Merit Aktiva: kreediitarve ' . $payload['InvoiceNo'] . ' loodud.' );
            wp_send_json_success( [ 'message' => 'Kreediitarve loodud (' . $payload['InvoiceNo'] . ').' ] );
        } else {
            // Meriti enda põhjus (nt "Laokaubal … peab olema määratud omahind"), mitte üldine teade
            $msg = swi_humanize_merit_error( $res );
            $order->add_order_note( 'Merit Aktiva: kreediitarve ' . $payload['InvoiceNo'] . ' loomine ebaõnnestus — ' . $msg );
            wp_send_json_error( [ 'message' => $msg ] );
        }
    }

    /**
     * AJAX handler: küsi Meriti arve tegelik staatus (tasutud/tasumata, summa) —
     * puhtalt lugemispäring, ei muuda midagi.
     */
    public function handle_invoice_status(): void {
        if ( ! check_ajax_referer( 'swi_merit_invoice_status', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Vigane nonce.' ] );
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Pole luba.' ] );
        }
        $order_id = (int) ( $_POST['order_id'] ?? 0 );
        $order    = $order_id ? wc_get_order( $order_id ) : null;
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => 'Orderit ei leitud.' ] );
        }

        $invoice_no = $this->invoice_no_for( $order );

        try {
            $client = new MeritServersDataClient();
            $data   = $client->get_invoice_details( $invoice_no );
        } catch ( \Exception $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }

        $header = $data['invoice']['Header'] ?? $data['summary'] ?? null;
        if ( ! $header ) {
            wp_send_json_error( [ 'message' => 'Arvet ei leitud Meritist.' ] );
        }

        wp_send_json_success( [
            'invoice_no' => $header['InvoiceNo'] ?? $invoice_no,
            'paid'   => ! empty( $header['Paid'] ),
            'total'  => $header['TotalSum'] ?? $header['TotalAmount'] ?? null,
            'paidAmount' => $header['PaidAmount'] ?? null,
        ] );
    }

    /**
     * AJAX handler: kustuta Meriti arve ("Kustuta Meriti arve" nupp — JS küsib enne confirm()'iga).
     *
     * Kustutab ainult Meriti-poolse kirje, ei puutu WC tellimust — eemaldab '_swi_sent_merit' lipu,
     * et admin saaks vajadusel arve uuesti saata.
     */
    public function handle_delete_merit_invoice(): void {
        if ( ! check_ajax_referer( 'swi_merit_delete_invoice', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Vigane nonce.' ] );
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Pole luba.' ] );
        }
        $order_id = (int) ( $_POST['order_id'] ?? 0 );
        $order    = $order_id ? wc_get_order( $order_id ) : null;
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => 'Orderit ei leitud.' ] );
        }

        $invoice_no = $this->invoice_no_for( $order );

        try {
            $client = new MeritServersDataClient();
            $client->delete_invoice( $invoice_no );
        } catch ( \Exception $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }

        $order->delete_meta_data( '_swi_sent_merit' );
        $order->delete_meta_data( '_swi_merit_payment_sent' );
        // Teadlikult kustutatud arvet automaatne kontroll tagasi ei saada — ainult käsitsi
        $order->update_meta_data( '_swi_merit_deleted', current_time( 'mysql' ) );
        $order->add_order_note( 'Merit Aktiva: arve ' . $invoice_no . ' kustutatud Meritist. Automaatselt uuesti ei saadeta — vajadusel saada käsitsi.' );
        $order->save();

        wp_send_json_success( [ 'message' => 'Arve kustutatud Meritist.' ] );
    }

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
        try {
            $results = $client->get_all_invoices();
        } catch ( \Exception $e ) {
            wp_send_json_error( [ 'error' => 'Merit API viga: ' . $e->getMessage() ] );
            return;
        }

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

        // Võrreldavad tellimused: seadistatud staatuses tellimused + kõik juba Meritisse saadetud
        // tellimused (viimased 12 kuud) sõltumata praegusest staatusest — muidu kaoks staatuse seade
        // muutmisel nimekirjast tellimus, mille arve Meritis kustutati.
        // NB: ltrim($s, 'wc-') eemaldaks kõik algustähed w/c/- ('wc-completed' → 'ompleted').
        $status  = preg_replace( '/^wc-/', '', (string) get_option( 'smart_wp_integtaion_invoice_status', 'wc-completed' ) );
        $prefix  = get_option( 'smart_wp_integtaion_arve_eesliides', '' );
        $by_id   = [];
        foreach ( wc_get_orders( [ 'type' => 'shop_order', 'status' => $status, 'limit' => -1, 'orderby' => 'date', 'order' => 'DESC' ] ) as $o ) {
            $by_id[ $o->get_id() ] = $o;
        }
        $sent = wc_get_orders( [ 'type' => 'shop_order',
            'limit'        => -1,
            'status'       => array_keys( wc_get_order_statuses() ),
            'date_created' => '>' . strtotime( '-12 months' ),
            'meta_query'   => [
                'relation' => 'OR',
                [ 'key' => '_swi_sent_merit', 'compare' => 'EXISTS' ],
                [ 'key' => '_swi_merit_invoice_no', 'compare' => 'EXISTS' ],
            ],
        ] );
        foreach ( $sent as $o ) {
            $by_id[ $o->get_id() ] = $o;
        }
        krsort( $by_id );
        $orders = array_values( $by_id );

        $rows = [];
        foreach ( $orders as $order ) {
            $inv_no   = (string) $order->get_meta( '_swi_merit_invoice_no' ) ?: $prefix . $order->get_id();
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
    /**
     * AJAX: laadib Meriti laod vahemällu ('swi_merit_locations_cache') seadete lehe valikute jaoks.
     */
    public function handle_load_locations(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        }
        try {
            $locations = ( new MeritServersDataClient() )->get_locations();
        } catch ( \Exception $e ) {
            wp_send_json_error( [ 'error' => $e->getMessage() ] );
        }
        if ( empty( $locations ) ) {
            wp_send_json_error( [ 'error' => 'Meritis ei leitud ühtegi aktiivset ladu.' ] );
        }
        wp_send_json_success( [ 'locations' => $locations ] );
    }

    /** Toote ladu Meritis (Toote andmed → Laoseis). Tühi = kategooria või vaikeladu. */
    public function render_product_location_field(): void {
        global $product_object;
        $options = [ '' => '— kategooria või vaikeladu —' ];
        foreach ( (array) get_option( 'swi_merit_locations_cache', [] ) as $loc ) {
            $options[ $loc['Code'] ] = $loc['Code'] . ( $loc['Name'] !== '' ? ' — ' . $loc['Name'] : '' );
        }
        $value = $product_object ? (string) $product_object->get_meta( '_swi_merit_location_code' ) : '';
        if ( $value !== '' && ! isset( $options[ $value ] ) ) {
            $options[ $value ] = $value;
        }
        echo '<div class="options_group">';
        woocommerce_wp_select( [
            'id'          => '_swi_merit_location_code',
            'label'       => 'Merit Aktiva ladu',
            'options'     => $options,
            'value'       => $value,
            'desc_tip'    => true,
            'description' => 'Ladu, kust see toode Meriti arvel maha läheb. Kasutatakse ainult laokauba puhul.',
        ] );
        echo '</div>';
    }

    public function save_product_location_field( \WC_Product $product ): void {
        if ( ! isset( $_POST['_swi_merit_location_code'] ) ) {
            return;
        }
        $code = sanitize_text_field( wp_unslash( $_POST['_swi_merit_location_code'] ) );
        if ( $code === '' ) {
            $product->delete_meta_data( '_swi_merit_location_code' );
        } else {
            $product->update_meta_data( '_swi_merit_location_code', $code );
        }
    }

    /** AJAX: laadib Meriti müügi makseviisid vahemällu. */
    public function handle_load_payment_types(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        }
        try {
            $types = ( new MeritServersDataClient() )->get_payment_types();
        } catch ( \Exception $e ) {
            wp_send_json_error( [ 'error' => $e->getMessage() ] );
        }
        if ( empty( $types ) ) {
            wp_send_json_error( [ 'error' => 'Meritis ei leitud ühtegi müügi makseviisi.' ] );
        }
        wp_send_json_success( [ 'payment_types' => $types ] );
    }

    /**
     * Lisab Meriti arve PDF-i tellimuse "täidetud" e-kirjale.
     *
     * WooCommerce saadab e-kirja enne 'woocommerce_order_status_changed' hooki, millega arve
     * automaatselt saadetakse — seepärast saadetakse arve vajadusel siin enne PDF-i pärimist.
     * PDF salvestatakse ajutisse kausta (mitte uploads, mis on avalik) ja kustutatakse päringu lõpus.
     * Viga ei tohi e-kirja saatmist takistada.
     */
    public function attach_merit_pdf( $attachments, $email_id, $order, $email = null ) {
        if ( $email_id !== 'customer_completed_order' || ! ( $order instanceof \WC_Order ) ) {
            return $attachments;
        }
        if ( get_option( 'swi_merit_pdf_email' ) !== 'yes' || get_option( 'smart_wp_integtaion_enable' ) !== 'yes' ) {
            return $attachments;
        }
        if ( ! $order->get_meta( '_swi_sent_merit' ) ) {
            $this->auto_send_order( $order->get_id(), '', $order->get_status() );
            $order = wc_get_order( $order->get_id() );
            if ( ! $order || ! $order->get_meta( '_swi_sent_merit' ) ) {
                return $attachments;
            }
        }

        try {
            $pdf = ( new MeritServersDataClient() )->get_invoice_pdf( $this->invoice_no_for( $order ) );
        } catch ( \Exception $e ) {
            $order->add_order_note( 'Merit Aktiva: arve PDF-i ei õnnestunud e-kirjale lisada — ' . $e->getMessage() );
            return $attachments;
        }
        $content = base64_decode( (string) ( $pdf['file_content'] ?? '' ), true );
        if ( ! $content ) {
            return $attachments;
        }

        $name = sanitize_file_name( (string) ( $pdf['file_name'] ?? '' ) ) ?: ( 'arve-' . $order->get_id() . '.pdf' );
        if ( substr( strtolower( $name ), -4 ) !== '.pdf' ) {
            $name .= '.pdf';
        }
        $dir = trailingslashit( get_temp_dir() ) . 'swi-merit-' . wp_generate_password( 12, false );
        if ( ! wp_mkdir_p( $dir ) || file_put_contents( $dir . '/' . $name, $content ) === false ) {
            return $attachments;
        }
        $path = $dir . '/' . $name;
        register_shutdown_function( function () use ( $path, $dir ) {
            @unlink( $path );
            @rmdir( $dir );
        } );

        $attachments   = (array) $attachments;
        $attachments[] = $path;
        return $attachments;
    }

    public function add_bulk_action( $actions ) {
        if ( get_option( 'smart_wp_integtaion_enable' ) === 'yes' ) {
            $actions['swi_merit_send'] = 'Saada Merit Aktivasse';
        }
        return $actions;
    }

    /**
     * Massiline saatmine: saadab valitud tellimused, mis pole veel Meritisse läinud.
     * Juba saadetud tellimused jäetakse vahele (topeltarve kaitse).
     */
    public function handle_bulk_action( $redirect_url, $action, $ids ) {
        if ( $action !== 'swi_merit_send' || ! current_user_can( 'manage_woocommerce' ) ) {
            return $redirect_url;
        }
        $ok = $fail = $skip = 0;
        foreach ( array_map( 'absint', (array) $ids ) as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) continue;
            if ( $order->get_meta( '_swi_sent_merit' ) ) { $skip++; continue; }

            $payload = $this->build_payload_for_order( $order );
            if ( ! $payload ) { $fail++; continue; } // põhjus on juba tellimuse märkustes (fail_build)

            $res = LocalApiClient::sendEncryptedOrder( $payload, 'merit' );
            if ( in_array( $res['status'] ?? '', [ 'ok', 'queued' ], true ) ) {
                $order->update_meta_data( '_swi_sent_merit', current_time( 'mysql' ) );
                $order->delete_meta_data( '_swi_merit_retry' );
                $order->delete_meta_data( '_swi_merit_retry_count' );
                $order->save();
                $order->add_order_note( 'Merit Aktiva: arve edastatud massilise saatmisega (' . $res['status'] . ').' );
                swi_log_send_history( $order_id, 'ok', 'Massiline saatmine: ' . ( $res['message'] ?? $res['status'] ) );
                $ok++;
            } else {
                $msg = swi_humanize_merit_error( $res );
                $order->add_order_note( 'Merit Aktiva: edastamine ebaõnnestus — ' . $msg );
                swi_merit_set_send_error( $order, $msg );
                swi_log_send_history( $order_id, 'error', 'Massiline saatmine: ' . $msg );
                $fail++;
            }
            usleep( 300000 ); // Meriti päringulimiit
        }
        return add_query_arg( [ 'swi_merit_bulk' => $ok . '-' . $fail . '-' . $skip ], $redirect_url );
    }

    /**
     * Admin-teade tellimustest, mida ei õnnestunud Meritisse saata: valideerimisviga
     * (_swi_merit_build_error, nt KM ei klapi) või saatmisviga (_swi_merit_send_error).
     * Kaob, kui arve on saadetud (_swi_sent_merit).
     */
    public function show_failed_notice(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) || get_option( 'smart_wp_integtaion_enable' ) !== 'yes' ) {
            return;
        }
        $ids = wc_get_orders( [ 'type' => 'shop_order',
            'limit'      => 50,
            'return'     => 'ids',
            'meta_query' => [
                'relation' => 'AND',
                [ 'key' => '_swi_sent_merit', 'compare' => 'NOT EXISTS' ],
                [
                    'relation' => 'OR',
                    [ 'key' => '_swi_merit_build_error', 'compare' => 'EXISTS' ],
                    [ 'key' => '_swi_merit_send_error', 'compare' => 'EXISTS' ],
                ],
            ],
        ] );
        if ( empty( $ids ) ) {
            return;
        }

        $items = [];
        foreach ( array_slice( $ids, 0, 5 ) as $id ) {
            $order = wc_get_order( $id );
            if ( ! $order ) continue;
            $why = (string) ( $order->get_meta( '_swi_merit_build_error' ) ?: $order->get_meta( '_swi_merit_send_error' ) );
            if ( mb_strlen( $why ) > 110 ) $why = mb_substr( $why, 0, 107 ) . '…';
            $items[] = '<li><a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . (int) $id . '</a> — ' . esc_html( $why ) . '</li>';
        }
        $more = count( $ids ) > 5 ? '<li>… ja veel ' . ( count( $ids ) - 5 ) . '</li>' : '';
        $url  = admin_url( 'admin.php?page=wc-settings&tab=smart_wp_integration' );

        echo '<div class="notice notice-error"><p><strong>Merit Aktiva:</strong> '
            . count( $ids ) . ' tellimust ei saanud saata. Paranda põhjus ja saada uuesti (tellimuse Meriti kastist, massilise toiminguga või '
            . '<a href="' . esc_url( $url ) . '">Merit Aktiva → Üldseaded → Sünkroniseerimine</a>).</p>'
            . '<ul style="margin:0 0 8px 18px;list-style:disc;">' . implode( '', $items ) . $more . '</ul></div>';
    }

    public function show_bulk_action_notice(): void {
        if ( empty( $_GET['swi_merit_bulk'] ) ) {
            return;
        }
        $parts = array_map( 'absint', explode( '-', sanitize_text_field( wp_unslash( $_GET['swi_merit_bulk'] ) ) ) );
        [ $ok, $fail, $skip ] = array_pad( $parts, 3, 0 );
        $class = $fail ? 'notice-warning' : 'notice-success';
        printf(
            '<div class="notice %s is-dismissible"><p>Merit Aktiva: saadetud %d, ebaõnnestus %d, juba saadetud %d.%s</p></div>',
            esc_attr( $class ), $ok, $fail, $skip,
            $fail ? ' Põhjused leiad tellimuse märkustest.' : ''
        );
    }

    /** AJAX: käivitab laoseisu sünkroonimise kohe (Laod paneeli nupp). */
    public function handle_sync_stock(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        }
        $res = $this->sync_stock_from_merit();
        if ( ! empty( $res['error'] ) ) {
            wp_send_json_error( [ 'error' => $res['error'] ] );
        }
        wp_send_json_success( $res );
    }

    /**
     * Uuendab WooCommerce'i laoseisu Meriti laoseisu järgi (toote SKU = Meriti kaubakood).
     *
     * Ainult tooted, millel on laoarvestus sisse lülitatud ja SKU olemas. Iga toote laoseis võetakse
     * tema enda laost (toode → kategooria → vaikeladu), sama loogikaga kui arveridadel. Toodet, mida
     * Meriti laoaruandes pole, ei puudutata.
     *
     * @return array ['updated' => int, 'checked' => int, 'error' => string|null]
     */
    public function sync_stock_from_merit(): array {
        $client  = new MeritServersDataClient();
        $reports = [];
        $updated = $checked = 0;

        $products = wc_get_products( [ 'limit' => -1, 'type' => [ 'simple', 'variation' ], 'return' => 'objects' ] );
        foreach ( $products as $product ) {
            if ( ! $product->managing_stock() || ! $product->get_sku() ) continue;

            $loc = $this->resolve_location_code( $product );
            if ( ! array_key_exists( $loc, $reports ) ) {
                try {
                    $reports[ $loc ] = $client->get_stock( $loc );
                } catch ( \Exception $e ) {
                    update_option( 'swi_merit_stock_last_sync', current_time( 'mysql' ) . ' — viga: ' . $e->getMessage(), false );
                    return [ 'updated' => $updated, 'checked' => $checked, 'error' => $e->getMessage() ];
                }
            }

            $code = substr( $product->get_sku(), 0, 20 );
            if ( ! array_key_exists( $code, $reports[ $loc ] ) ) continue;
            $checked++;

            $qty = (int) floor( (float) $reports[ $loc ][ $code ] );
            if ( (int) $product->get_stock_quantity() !== $qty ) {
                wc_update_product_stock( $product, $qty, 'set' );
                $updated++;
            }
        }

        update_option( 'swi_merit_stock_last_sync', current_time( 'mysql' ) . " — kontrollitud {$checked}, uuendatud {$updated}", false );
        return [ 'updated' => $updated, 'checked' => $checked, 'error' => null ];
    }

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
        // Eelvaade ei tohi Meritis midagi luua (kliendikaart, tootekaart)
        $this->dry_run = true;
        $payload = $this->build_payload_for_order( $order );
        if ( ! $payload ) {
            wp_send_json_error( [ 'error' => $this->last_error ?: 'Payload ehitus ebaõnnestus.' ] );
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
        if ( ! $payload ) wp_send_json_error( [ 'error' => $this->last_error ?: 'Payload ehitus ebaõnnestus.' ] );

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
/**
 * Jätab tellimusele Meritisse saatmise vea põhjuse (admin-teate jaoks). Kustub mõjult, kui
 * arve on saadetud — teade näitab ainult saatmata tellimusi.
 */
function swi_merit_set_send_error( \WC_Order $order, string $message ): void {
    $order->update_meta_data( '_swi_merit_send_error', $message !== '' ? $message : 'Tundmatu viga' );
    $order->save();
}

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
