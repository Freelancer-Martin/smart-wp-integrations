<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Logi Simplebooks saatmiskatse ajalukku.
 *
 * Täiesti eraldi `swi_send_history`-st (Merit Aktiva ajalugu), et mõlemad
 * süsteemid ei segaks teineteist. Hoiab max 50 viimast kirjet WP options tabelis.
 *
 * @param int    $order_id WC tellimuse ID.
 * @param string $status   'ok' | 'error'
 * @param string $message  Lühike selgitav tekst.
 */
function swi_sb_log_send_history( int $order_id, string $status, string $message ): void {
    $history = get_option( 'swi_sb_send_history', [] );
    if ( ! is_array( $history ) ) {
        $history = [];
    }
    array_unshift( $history, [
        'time'     => current_time( 'Y-m-d H:i:s' ),
        'order_id' => $order_id,
        'status'   => $status,
        'message'  => $message,
    ] );
    update_option( 'swi_sb_send_history', array_slice( $history, 0, 50 ) );
}

/**
 * Simplebooks arve loomine ja haldus WooCommerce orderist.
 *
 * Registreerib WC staatusepõhise hooki automaatseks saatmiseks ning AJAX handlerid
 * seadete lehe sünkroniseerimise kontrolli, käsitsi saatmise ja ajaloo halduse jaoks.
 *
 * @package Smart_Wp_Integrations
 */
class SWI_Simplebooks_Create_Invoices {

    /**
     * Registreerib WC hooki ja kõik AJAX endpointid.
     *
     * AJAX handlerid registreeritakse ainult admin-kontekstis (wp_ajax_ prefix).
     * WC hook konstrueeritakse staatusest dünaamiliselt (nt 'wc-completed' → hook suffix 'completed').
     */
    public function __construct() {
        $status = get_option( 'swi_simplebooks_order_status', 'wc-completed' );
        $hook   = 'woocommerce_order_status_' . ltrim( $status, 'wc-' );
        add_action( $hook, [ $this, 'auto_send_order' ], 20, 1 );

        add_action( 'wp_ajax_swi_sb_sync_check',    [ $this, 'handle_sync_check' ] );
        add_action( 'wp_ajax_swi_sb_sync_resend',   [ $this, 'handle_sync_resend' ] );
        add_action( 'wp_ajax_swi_sb_manual_send',   [ $this, 'handle_manual_send' ] );
        add_action( 'wp_ajax_swi_sb_clear_history', [ $this, 'handle_clear_history' ] );
    }

    /**
     * Automaatne saatmine kui WC tellimus jõuab konfigureeritud staatusesse.
     *
     * '_swi_sent_simplebooks' meta-flag väldib topeltsaatmist.
     * Ebaõnnestumisel märgitakse order retry jaoks '_swi_simplebooks_retry' lipuga.
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
            swi_sb_log_send_history( $order_id, 'ok', 'Arve edastatud: ' . ( $payload['number'] ?? '' ) );
        } else {
            $msg = $this->humanize_error( $res );
            $order->add_order_note( 'Simplebooks: edastamine ebaõnnestus — ' . $msg );
            error_log( 'SWI Simplebooks auto-send failed order ' . $order_id . ': ' . wp_json_encode( $res ) );
            $order->update_meta_data( '_swi_simplebooks_retry', '1' );
            $order->update_meta_data( '_swi_simplebooks_retry_count', 0 );
            $order->save();
            swi_sb_log_send_history( $order_id, 'error', $msg );
        }
    }

    /* ─── AJAX: Sünkroniseerimise kontroll ─── */

    /**
     * AJAX handler: võrdleb WC ordereid Simplebooks arvetega.
     *
     * Küsib vaheserveri kaudu Simplebooks arvete nimekirja ja võrdleb neid WC
     * tellimusõega konfigureeritud staatuses. Vastab puuduvate arvete nimekirjaga.
     */
    public function handle_sync_check(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        }

        $sb_nos = [];
        $base   = LocalApiClient::get_base_url_public();
        $token  = get_option( 'swi_simplebooks_license_key', '' );

        $resp = wp_remote_get( rtrim( $base, '/' ) . '/api/simplebooks/invoices?per_page=500', [
            'timeout' => 25,
            'headers' => [
                'X-License-Token' => $token,
                'Accept'          => 'application/json',
            ],
        ] );

        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( [ 'error' => 'Vaheserveri ühendus ebaõnnestus: ' . $resp->get_error_message() ] );
        }

        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code >= 400 ) {
            $body = json_decode( wp_remote_retrieve_body( $resp ), true );
            wp_send_json_error( [ 'error' => $body['error'] ?? 'Vaheserveri viga HTTP ' . $code ] );
        }

        $body     = json_decode( wp_remote_retrieve_body( $resp ), true );
        $invoices = $body['data'] ?? $body ?? [];
        foreach ( (array) $invoices as $inv ) {
            // Simplebooks arve struktuur: {'Invoice': {'number': 'SB123', ...}}
            $no = $inv['Invoice']['number'] ?? $inv['number'] ?? null;
            if ( $no ) {
                $sb_nos[] = (string) $no;
            }
        }

        $status = ltrim( get_option( 'swi_simplebooks_order_status', 'wc-completed' ), 'wc-' );
        $prefix = get_option( 'swi_simplebooks_prefix', 'SB' );
        $orders = wc_get_orders( [ 'status' => $status, 'limit' => -1, 'orderby' => 'date', 'order' => 'DESC' ] );

        $rows = [];
        foreach ( $orders as $order ) {
            $inv_no = $prefix . $order->get_id();
            $rows[] = [
                'order_id'   => $order->get_id(),
                'invoice_no' => $inv_no,
                'date'       => $order->get_date_created() ? $order->get_date_created()->date( 'd.m.Y' ) : '-',
                'total'      => wc_price( $order->get_total() ),
                'in_sb'      => in_array( $inv_no, $sb_nos, true ),
                'meta_sent'  => $order->get_meta( '_swi_sent_simplebooks' )
                                    ? date( 'd.m.Y H:i', strtotime( $order->get_meta( '_swi_sent_simplebooks' ) ) )
                                    : '',
            ];
        }

        wp_send_json_success( [ 'rows' => $rows, 'sb_count' => count( $sb_nos ) ] );
    }

    /**
     * AJAX handler: saadab puuduva arve uuesti Simplebooks'i sünkroniseerimise tabelist.
     */
    public function handle_sync_resend(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        }

        $order_id = (int) ( $_POST['order_id'] ?? 0 );
        $order    = $order_id ? wc_get_order( $order_id ) : null;
        if ( ! $order ) {
            wp_send_json_error( [ 'error' => 'Orderit ei leitud.' ] );
        }

        $order->delete_meta_data( '_swi_sent_simplebooks' );
        $order->save();

        $payload = $this->build_payload( $order );
        if ( ! $payload ) {
            wp_send_json_error( [ 'error' => 'Payload ehitamine ebaõnnestus (pole tooteid?).' ] );
        }

        $res = LocalApiClient::sendEncryptedOrder( $payload, 'simplebooks' );

        if ( isset( $res['status'] ) && in_array( $res['status'], [ 'ok', 'queued' ], true ) ) {
            $order->update_meta_data( '_swi_sent_simplebooks', current_time( 'mysql' ) );
            $order->save();
            $order->add_order_note( 'Simplebooks: arve edastatud käsitsi (sync).' );
            swi_sb_log_send_history( $order_id, 'ok', 'Käsitsi sünkroniseerimine: ' . $payload['number'] );
            wp_send_json_success( [ 'message' => 'Arve ' . esc_html( $payload['number'] ) . ' edastatud.' ] );
        } else {
            $msg = $this->humanize_error( $res );
            swi_sb_log_send_history( $order_id, 'error', 'Sync uuesti saatmine ebaõnnestus: ' . $msg );
            wp_send_json_error( [ 'error' => $msg ] );
        }
    }

    /**
     * AJAX handler: käsitsi saatmine tellimuse ID järgi (Tööriistad paneelist).
     */
    public function handle_manual_send(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        }

        $order_id    = (int) ( $_POST['order_id'] ?? 0 );
        $preview_only = ! empty( $_POST['preview_only'] );
        $order       = $order_id ? wc_get_order( $order_id ) : null;
        if ( ! $order ) {
            wp_send_json_error( [ 'error' => 'Orderit ei leitud.' ] );
        }

        $payload = $this->build_payload( $order );
        if ( ! $payload ) {
            wp_send_json_error( [ 'error' => 'Payload ehitamine ebaõnnestus (pole tooteid?).' ] );
        }

        // Eelvaate režiimis tagasta payload ilma saatmata
        if ( $preview_only ) {
            wp_send_json_success( [ 'payload' => $payload ] );
        }

        $order->delete_meta_data( '_swi_sent_simplebooks' );
        $order->save();

        $res = LocalApiClient::sendEncryptedOrder( $payload, 'simplebooks' );

        if ( isset( $res['status'] ) && in_array( $res['status'], [ 'ok', 'queued' ], true ) ) {
            $order->update_meta_data( '_swi_sent_simplebooks', current_time( 'mysql' ) );
            $order->save();
            $order->add_order_note( 'Simplebooks: arve edastatud käsitsi tööriistad paneelist.' );
            swi_sb_log_send_history( $order_id, 'ok', 'Käsitsi saatmine: ' . $payload['number'] );
            wp_send_json_success( [ 'message' => 'Arve ' . esc_html( $payload['number'] ) . ' edastatud edukalt.' ] );
        } else {
            $msg = $this->humanize_error( $res );
            swi_sb_log_send_history( $order_id, 'error', 'Käsitsi saatmine ebaõnnestus: ' . $msg );
            wp_send_json_error( [ 'error' => $msg ] );
        }
    }

    /**
     * AJAX handler: kustutab Simplebooks saatmise ajaloo.
     */
    public function handle_clear_history(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        }
        delete_option( 'swi_sb_send_history' );
        wp_send_json_success( [ 'message' => 'Ajalugu kustutatud.' ] );
    }

    /* ─── Payload builder ─── */

    /**
     * Ehitab Simplebooks API formaadis payload WooCommerce orderist.
     *
     * Käibemaks esitatakse protsendina iga rea juures (mitte UUID-na nagu Merit Aktivas).
     * Arve number = konfigureeritav eesliide + WC order ID (nt 'SB1234').
     *
     * @param \WC_Order $order WooCommerce tellimuse objekt.
     * @return array|null Payload või null kui orderil pole ridasi.
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

        foreach ( $order->get_items( 'shipping' ) as $ship ) {
            $ship_total = (float) $ship->get_total();
            if ( $ship_total > 0 ) {
                $ship_tax = (float) $ship->get_total_tax();
                $ship_vat = round( $ship_tax / $ship_total * 100, 2 );
                $items[]  = [
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
        $deadline   = (int) get_option( 'swi_simplebooks_payment_days', 14 );
        $due        = date( 'Y-m-d', strtotime( '+' . $deadline . ' days', strtotime( $date ) ) );

        return [
            'order_id'  => $order->get_id(),
            'number'    => $prefix . $order->get_id(),
            'date'      => $date,
            'due'       => $due,
            'currency'  => $order->get_currency() ?: 'EUR',
            'total'     => (float) $order->get_total(),
            'total_tax' => (float) $order->get_total_tax(),
            'billing'   => $billing,
            'items'     => $items,
        ];
    }

    /* ─── Private helpers ─── */

    /**
     * Teisendab Simplebooks API veateate inimkeelseks eestikeelseks sõnumiks.
     *
     * @param array $res LocalApiClient vastus.
     * @return string Inimkeelne veateade.
     */
    private function humanize_error( array $res ): string {
        $msg = $res['response']['result']['message']
            ?? $res['response']['message']
            ?? $res['message']
            ?? '';

        if ( ! $msg || $msg === 'Handler returned failure' ) {
            $msg = $res['response']['result']['body'] ?? $msg;
        }

        $map = [
            'number already exists'   => 'Arve on Simplebooksis juba olemas (korduvnumber).',
            'duplicate'               => 'Arve on Simplebooksis juba olemas (korduvnumber).',
            'token'                   => 'Simplebooks API token on vigane või aegunud.',
            'not found'               => 'Ressurssi ei leitud Simplebooksis.',
            'Handler returned failure'=> 'Simplebooks keeldus arvet vastu võtmast.',
            'missing'                 => 'Kohustuslik väli puudub Simplebooks payload-is.',
        ];

        foreach ( $map as $key => $friendly ) {
            if ( stripos( (string) $msg, $key ) !== false ) {
                return $friendly;
            }
        }

        return $msg ?: 'Tundmatu viga Simplebooks API-lt.';
    }
}
