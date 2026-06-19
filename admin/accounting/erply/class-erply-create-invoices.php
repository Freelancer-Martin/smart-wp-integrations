<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function swi_erply_log_history( int $order_id, string $status, string $message ): void {
    $history = get_option( 'swi_erply_send_history', [] );
    if ( ! is_array( $history ) ) $history = [];
    array_unshift( $history, [
        'time'     => current_time( 'Y-m-d H:i:s' ),
        'order_id' => $order_id,
        'status'   => $status,
        'message'  => $message,
    ] );
    update_option( 'swi_erply_send_history', array_slice( $history, 0, 50 ) );
}

class SWI_Erply_Create_Invoices {

    public function __construct() {
        $status = get_option( 'swi_erply_order_status', 'wc-completed' );
        $hook   = 'woocommerce_order_status_' . ( str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status );
        add_action( $hook, [ $this, 'auto_send_order' ], 20, 1 );

        add_action( 'wp_ajax_swi_erply_bulk_send',        [ $this, 'handle_bulk_send' ] );
        add_action( 'wp_ajax_swi_erply_order_send',       [ $this, 'handle_order_send' ] );
        add_action( 'wp_ajax_swi_erply_sync_check',       [ $this, 'handle_sync_check' ] );
        add_action( 'wp_ajax_swi_erply_reset_sent',       [ $this, 'handle_reset_sent' ] );
        add_action( 'wp_ajax_swi_erply_reset_single',     [ $this, 'handle_reset_single' ] );
        add_action( 'wp_ajax_swi_erply_preview',          [ $this, 'handle_preview' ] );
        add_action( 'wp_ajax_swi_erply_connection_test',  [ $this, 'handle_connection_test' ] );
        add_action( 'wp_ajax_swi_erply_clear_history',    [ $this, 'handle_clear_history' ] );
        add_action( 'wp_ajax_swi_rik_lookup',             [ $this, 'handle_rik_lookup' ] );
        add_action( 'wp_ajax_nopriv_swi_rik_lookup',      [ $this, 'handle_rik_lookup' ] );

        add_action( 'woocommerce_after_order_notes', [ $this, 'checkout_fields' ] );
        add_filter( 'woocommerce_checkout_fields',   [ $this, 'register_checkout_fields' ] );
        add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'save_checkout_fields' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_rik_script' ] );

        // Cron: registreeri 10-minutiline intervall ja ajakava
        add_filter( 'cron_schedules', [ $this, 'add_cron_interval' ] );
        add_action( 'swi_erply_cron_send', [ $this, 'cron_send_orders' ] );
        if ( ! wp_next_scheduled( 'swi_erply_cron_send' ) ) {
            wp_schedule_event( time(), 'swi_10min', 'swi_erply_cron_send' );
        }
    }

    public function add_cron_interval( array $schedules ): array {
        $schedules['swi_10min'] = [
            'interval' => 600,
            'display'  => 'Iga 10 minuti järel',
        ];
        return $schedules;
    }

    /* ─── Cron: saada ükshaaval kõik saadetamata tellimused ─── */

    public function cron_send_orders(): void {
        if ( get_option( 'swi_erply_enable' ) !== 'yes' ) return;

        $status = get_option( 'swi_erply_order_status', 'wc-completed' );
        $orders = wc_get_orders( [
            'limit'   => 20,
            'status'  => $status,
            'orderby' => 'id',
            'order'   => 'ASC',
        ] );

        foreach ( $orders as $order ) {
            if ( $order->get_meta( '_swi_sent_erply' ) ) continue;

            $payload = $this->build_payload( $order );
            if ( ! $payload ) continue;

            $res = LocalApiClient::sendEncryptedOrder( $payload, 'erply' );
            if ( isset( $res['status'] ) && in_array( $res['status'], [ 'ok', 'queued' ], true ) ) {
                $order->update_meta_data( '_swi_sent_erply', current_time( 'mysql' ) );
                $inv_id = $res['response']['result']['invoiceID'] ?? null;
                if ( $inv_id ) $order->update_meta_data( '_swi_erply_invoice_id', $inv_id );
                $order->save();
                swi_erply_log_history( $order->get_id(), 'ok', 'Cron saatmine, Erply ID: ' . ( $inv_id ?: '?' ) );
            } else {
                $msg = $res['response']['result']['message'] ?? $res['message'] ?? 'Tundmatu viga';
                swi_erply_log_history( $order->get_id(), 'error', 'Cron saatmine ebaõnnestus: ' . $msg );
            }

            // Paus tellimuste vahel et mitte Erply API-t üle koormata
            usleep( 300000 ); // 0.3s
        }
    }

    /* ─── Automaatne saatmine ─── */

    public function auto_send_order( int $order_id ): void {
        if ( get_option( 'swi_erply_enable' ) !== 'yes' ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_meta( '_swi_sent_erply' ) ) return;

        $payload = $this->build_payload( $order );
        if ( ! $payload ) return;

        $res = LocalApiClient::sendEncryptedOrder( $payload, 'erply' );
        if ( isset( $res['status'] ) && in_array( $res['status'], [ 'ok', 'queued' ], true ) ) {
            $order->update_meta_data( '_swi_sent_erply', current_time( 'mysql' ) );
            $inv_id = $res['response']['result']['invoiceID'] ?? null;
            if ( $inv_id ) {
                $order->update_meta_data( '_swi_erply_invoice_id', $inv_id );
            }
            $order->save();
            $order->add_order_note( 'Erply: arve edastatud automaatselt.' );
            swi_erply_log_history( $order_id, 'ok', 'Automaatne saatmine, Erply ID: ' . ( $inv_id ?: '?' ) );
        } else {
            $msg = $res['response']['result']['message'] ?? $res['message'] ?? 'Tundmatu viga';
            swi_erply_log_history( $order_id, 'error', 'Automaatne saatmine ebaõnnestus: ' . $msg );
        }
    }

    /* ─── AJAX: bulk send ─── */

    public function handle_bulk_send(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        }

        $status = get_option( 'swi_erply_order_status', 'wc-completed' );
        $all    = wc_get_orders( [ 'limit' => 200, 'status' => $status, 'orderby' => 'id', 'order' => 'ASC' ] );
        $orders = array_slice( array_filter( $all, fn( $o ) => ! $o->get_meta( '_swi_sent_erply' ) ), 0, 50 );

        if ( empty( $orders ) ) {
            wp_send_json_success( [ 'sent' => 0, 'failed' => 0, 'total' => 0, 'message' => 'Kõik arved on juba saadetud.' ] );
        }

        $sent = 0; $failed = 0; $errors = [];
        foreach ( $orders as $order ) {
            $payload = $this->build_payload( $order );
            if ( ! $payload ) { $failed++; continue; }

            $res = LocalApiClient::sendEncryptedOrder( $payload, 'erply' );
            if ( isset( $res['status'] ) && in_array( $res['status'], [ 'ok', 'queued' ], true ) ) {
                $order->update_meta_data( '_swi_sent_erply', current_time( 'mysql' ) );
                $inv_id = $res['response']['result']['invoiceID'] ?? null;
                if ( $inv_id ) {
                    $order->update_meta_data( '_swi_erply_invoice_id', $inv_id );
                }
                $order->save();
                swi_erply_log_history( $order->get_id(), 'ok', 'Bulk saatmine, Erply ID: ' . ( $inv_id ?: '?' ) );
                $sent++;
            } else {
                $msg      = $res['response']['result']['message'] ?? $res['message'] ?? 'Tundmatu viga';
                $errors[] = '#' . $order->get_id() . ': ' . $msg;
                swi_erply_log_history( $order->get_id(), 'error', 'Bulk saatmine ebaõnnestus: ' . $msg );
                $failed++;
            }
        }

        wp_send_json_success( [
            'sent'    => $sent,
            'failed'  => $failed,
            'total'   => count( $orders ),
            'errors'  => $errors,
            'message' => "Saadetud: {$sent}/" . count( $orders ) . ". Ebaõnnestunud: {$failed}.",
        ] );
    }

    /* ─── AJAX: üks order ─── */

    public function handle_order_send(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        }

        $order_id = (int) ( $_POST['order_id'] ?? 0 );
        $order    = $order_id ? wc_get_order( $order_id ) : null;
        if ( ! $order ) wp_send_json_error( [ 'error' => 'Orderit ei leitud.' ] );

        $payload = $this->build_payload( $order );
        if ( ! $payload ) wp_send_json_error( [ 'error' => 'Payload ehitamine ebaõnnestus.' ] );

        $res = LocalApiClient::sendEncryptedOrder( $payload, 'erply' );
        if ( isset( $res['status'] ) && in_array( $res['status'], [ 'ok', 'queued' ], true ) ) {
            $order->update_meta_data( '_swi_sent_erply', current_time( 'mysql' ) );
            $inv_id = $res['response']['result']['invoiceID'] ?? null;
            if ( $inv_id ) $order->update_meta_data( '_swi_erply_invoice_id', $inv_id );
            $order->save();
            $order->add_order_note( 'Erply: arve edastatud käsitsi.' );
            swi_erply_log_history( $order_id, 'ok', 'Käsitsi saatmine, Erply ID: ' . ( $inv_id ?: '?' ) );
            wp_send_json_success( [ 'message' => 'Arve edastatud Erplysse.' ] );
        } else {
            $msg = $res['response']['result']['message'] ?? $res['message'] ?? 'Tundmatu viga';
            swi_erply_log_history( $order_id, 'error', 'Käsitsi saatmine ebaõnnestus: ' . $msg );
            wp_send_json_error( [ 'error' => $msg ] );
        }
    }

    /* ─── AJAX: sync check ─── */

    public function handle_sync_check(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        }

        $status = get_option( 'swi_erply_order_status', 'wc-completed' );
        $orders = wc_get_orders( [ 'limit' => 100, 'status' => $status ] );
        $rows   = [];

        foreach ( $orders as $order ) {
            $sent_at = $order->get_meta( '_swi_sent_erply' );
            $inv_id  = $order->get_meta( '_swi_erply_invoice_id' );
            $rows[]  = [
                'order_id'   => $order->get_id(),
                'inv_id'     => $inv_id ?: null,
                'total_html' => wc_price( $order->get_total() ),
                'in_erply'   => ! empty( $sent_at ) || ! empty( $inv_id ),
                'meta_sent'  => $sent_at ? date( 'd.m.Y H:i', strtotime( $sent_at ) ) : null,
            ];
        }

        $missing = array_filter( $rows, fn( $r ) => ! $r['in_erply'] );
        wp_send_json_success( [ 'rows' => $rows, 'missing_count' => count( $missing ) ] );
    }

    /* ─── AJAX: reset all ─── */

    public function handle_reset_sent(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        }
        global $wpdb;
        $d1 = (int) $wpdb->delete( $wpdb->prefix . 'wc_orders_meta', [ 'meta_key' => '_swi_sent_erply' ] );
        $d2 = (int) $wpdb->delete( $wpdb->prefix . 'wc_orders_meta', [ 'meta_key' => '_swi_erply_invoice_id' ] );
        wp_send_json_success( [ 'message' => ( $d1 + $d2 ) . ' kirjet eemaldatud.' ] );
    }

    /* ─── AJAX: reset single ─── */

    public function handle_reset_single(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        }
        $order_id = (int) ( $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) wp_send_json_error( [ 'error' => 'order_id puudub.' ] );
        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_send_json_error( [ 'error' => 'Tellimust ei leitud.' ] );
        $order->delete_meta_data( '_swi_sent_erply' );
        $order->delete_meta_data( '_swi_erply_invoice_id' );
        $order->save();

        $api_url = get_option( 'smart_wp_integration_server_url', '' );
        $lic_key = get_option( 'swi_erply_license_key', '' );
        if ( $api_url && $lic_key ) {
            wp_remote_post( trailingslashit( $api_url ) . 'api/erply/reset-reference', [
                'headers' => [ 'X-License-Token' => $lic_key, 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [ 'reference_no' => (string) $order_id ] ),
                'timeout' => 10,
            ] );
        }

        wp_send_json_success( [ 'message' => 'Tellimus #' . $order_id . ' märgitud puuduvaks.' ] );
    }

    /* ─── AJAX: RIK äriregistri otsing ─── */

    public function handle_rik_lookup(): void {
        check_ajax_referer( 'swi_rik_nonce', 'security' );
        $reg_code = preg_replace( '/\D/', '', $_POST['reg_code'] ?? '' );
        if ( ! $reg_code || strlen( $reg_code ) < 7 || strlen( $reg_code ) > 8 ) {
            wp_send_json_error( [ 'error' => 'Vigane registrikood.' ] );
        }

        $api_url = get_option( 'smart_wp_integration_server_url', '' );
        $lic_key = get_option( 'smart_wp_integtaion_license_text', '' );

        if ( ! $api_url || ! $lic_key ) {
            wp_send_json_error( [ 'error' => 'API seaded puuduvad.' ] );
        }

        $resp = wp_remote_get( trailingslashit( $api_url ) . 'api/rik/company?reg_code=' . urlencode( $reg_code ), [
            'headers' => [ 'X-License-Token' => $lic_key, 'Accept' => 'application/json' ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( [ 'error' => $resp->get_error_message() ] );
        }

        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( empty( $data['ok'] ) ) {
            wp_send_json_error( [ 'error' => $data['error'] ?? 'Ettevõtet ei leitud.' ] );
        }

        wp_send_json_success( [
            'name'    => $data['name']    ?? '',
            'vat'     => $data['vat']     ?? '',
            'address' => $data['address'] ?? '',
        ] );
    }

    /* ─── Checkout: lisa registrikoodi ja KM-nr väljad ─── */

    public function register_checkout_fields( array $fields ): array {
        $fields['billing']['billing_reg_no'] = [
            'label'    => __( 'Registrikood', 'smart-wp-integrations' ),
            'type'     => 'text',
            'required' => false,
            'class'    => [ 'form-row-first' ],
            'priority' => 110,
        ];
        $fields['billing']['billing_vat_no'] = [
            'label'    => __( 'KMKR nr', 'smart-wp-integrations' ),
            'type'     => 'text',
            'required' => false,
            'class'    => [ 'form-row-last' ],
            'priority' => 120,
        ];
        return $fields;
    }

    public function checkout_fields(): void {}

    public function save_checkout_fields( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        if ( ! empty( $_POST['billing_reg_no'] ) ) {
            $order->update_meta_data( '_billing_reg_no', sanitize_text_field( $_POST['billing_reg_no'] ) );
        }
        if ( ! empty( $_POST['billing_vat_no'] ) ) {
            $order->update_meta_data( '_billing_vat_no', sanitize_text_field( $_POST['billing_vat_no'] ) );
        }
        $order->save();
    }

    /* ─── AJAX: arve JSON eelvaade ─── */

    public function handle_preview(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        }
        $order_id = absint( $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) wp_send_json_error( [ 'error' => 'Order ID puudub.' ] );
        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_send_json_error( [ 'error' => 'Orderit ei leitud.' ] );
        $payload = $this->build_payload( $order );
        if ( ! $payload ) wp_send_json_error( [ 'error' => 'Payload ehitus ebaõnnestus.' ] );
        wp_send_json_success( [ 'payload' => $payload ] );
    }

    /* ─── AJAX: ühenduse test ─── */

    public function handle_connection_test(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        }
        $api_url = get_option( 'smart_wp_integration_server_url', '' );
        $lic_key = get_option( 'swi_erply_license_key', '' );
        if ( ! $api_url || ! $lic_key ) {
            wp_send_json_error( [ 'error' => 'Vaheserveri URL või litsentsi võti puudub.' ] );
        }
        $resp = wp_remote_get( trailingslashit( $api_url ) . 'api/erply/test', [
            'headers' => [ 'X-License-Token' => $lic_key, 'Accept' => 'application/json' ],
            'timeout' => 15,
        ] );
        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( [ 'error' => $resp->get_error_message() ] );
        }
        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( ! empty( $data['ok'] ) ) {
            wp_send_json_success( [ 'message' => $data['message'] ?? 'Ühendus toimib.' ] );
        } else {
            wp_send_json_error( [ 'error' => $data['error'] ?? 'Erply autentimine ebaõnnestus.' ] );
        }
    }

    /* ─── AJAX: kustuta ajalugu ─── */

    public function handle_clear_history(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        }
        delete_option( 'swi_erply_send_history' );
        wp_send_json_success( [ 'message' => 'Ajalugu kustutatud.' ] );
    }

    /* ─── Enqueue RIK checkout JS ─── */

    public function enqueue_rik_script(): void {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
        wp_enqueue_script(
            'swi-rik-checkout',
            plugin_dir_url( __FILE__ ) . 'swi-rik-checkout.js',
            [ 'jquery' ],
            '1.0.0',
            true
        );
        wp_localize_script( 'swi-rik-checkout', 'swiRik', [
            'ajaxurl'  => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'swi_rik_nonce' ),
        ] );
    }

    /* ─── Payload builder ─── */

    public function build_payload( \WC_Order $order ): ?array {
        $items = [];
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $items[] = [
                'product_id' => $item->get_product_id(),
                'name'       => $item->get_name(),
                'sku'        => $product ? $product->get_sku() : '',
                'quantity'   => (float) $item->get_quantity(),
                'price'      => $item->get_total() > 0 ? round( (float) $item->get_total() / (float) $item->get_quantity(), 4 ) : 0,
                'total'      => (float) $item->get_total(),
            ];
        }
        foreach ( $order->get_items( 'shipping' ) as $ship ) {
            $ship_total = (float) $ship->get_total();
            if ( $ship_total > 0 ) {
                $items[] = [
                    'product_id' => 0,
                    'name'       => $ship->get_name() ?: 'Transport',
                    'quantity'   => 1.0,
                    'price'      => $ship_total,
                    'total'      => $ship_total,
                ];
            }
        }
        if ( empty( $items ) ) return null;

        return [
            'id'         => $order->get_id(),
            'number'     => $order->get_id(),
            'email'      => $order->get_billing_email(),
            'phone'      => $order->get_billing_phone(),
            'billing'    => [
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'company'    => $order->get_billing_company(),
                'address_1'  => $order->get_billing_address_1(),
                'city'       => $order->get_billing_city(),
                'postcode'   => $order->get_billing_postcode(),
                'country'    => $order->get_billing_country(),
                'email'      => $order->get_billing_email(),
                'phone'      => $order->get_billing_phone(),
                'reg_code'   => $order->get_meta( '_billing_reg_no' ) ?: '',
            ],
            'currency'   => $order->get_currency() ?: 'EUR',
            'total'      => (float) $order->get_total(),
            'items'      => $items,
        ];
    }
}
