<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function swi_stdb_log_history( int $order_id, string $status, string $message ): void {
    $history = get_option( 'swi_stdb_send_history', [] );
    if ( ! is_array( $history ) ) $history = [];
    array_unshift( $history, [
        'time'     => current_time( 'Y-m-d H:i:s' ),
        'order_id' => $order_id,
        'status'   => $status,
        'message'  => $message,
    ] );
    update_option( 'swi_stdb_send_history', array_slice( $history, 0, 50 ) );
}

class SWI_StandardBooks_Create_Invoices {

    public function __construct() {
        $status = get_option( 'swi_stdb_order_status', 'wc-completed' );
        $hook   = 'woocommerce_order_status_' . ( str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status );
        add_action( $hook, [ $this, 'auto_send_order' ], 20, 1 );

        add_action( 'wp_ajax_swi_stdb_bulk_send',       [ $this, 'handle_bulk_send' ] );
        add_action( 'wp_ajax_swi_stdb_order_send',      [ $this, 'handle_order_send' ] );
        add_action( 'wp_ajax_swi_stdb_sync_check',      [ $this, 'handle_sync_check' ] );
        add_action( 'wp_ajax_swi_stdb_reset_sent',      [ $this, 'handle_reset_sent' ] );
        add_action( 'wp_ajax_swi_stdb_reset_single',    [ $this, 'handle_reset_single' ] );
        add_action( 'wp_ajax_swi_stdb_preview',         [ $this, 'handle_preview' ] );
        add_action( 'wp_ajax_swi_stdb_connection_test', [ $this, 'handle_connection_test' ] );
        add_action( 'wp_ajax_swi_stdb_clear_history',   [ $this, 'handle_clear_history' ] );

        add_filter( 'cron_schedules', [ $this, 'add_cron_interval' ] );
        add_action( 'swi_stdb_cron_send', [ $this, 'cron_send_orders' ] );
        if ( ! wp_next_scheduled( 'swi_stdb_cron_send' ) ) {
            wp_schedule_event( time(), 'swi_10min', 'swi_stdb_cron_send' );
        }
    }

    public function add_cron_interval( array $schedules ): array {
        if ( ! isset( $schedules['swi_10min'] ) ) {
            $schedules['swi_10min'] = [ 'interval' => 600, 'display' => 'Iga 10 minuti järel' ];
        }
        return $schedules;
    }

    /* ─── Automaatne saatmine ─── */

    public function auto_send_order( int $order_id ): void {
        if ( get_option( 'swi_stdb_enable' ) !== 'yes' ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_meta( '_swi_sent_stdb' ) ) return;

        $payload = $this->build_payload( $order );
        if ( ! $payload ) return;

        $res = LocalApiClient::sendEncryptedOrder( $payload, 'standard_books' );
        if ( isset( $res['status'] ) && in_array( $res['status'], [ 'ok', 'queued' ], true ) ) {
            $order->update_meta_data( '_swi_sent_stdb', current_time( 'mysql' ) );
            $inv_id = $res['response']['result']['invoiceID'] ?? null;
            if ( $inv_id ) $order->update_meta_data( '_swi_stdb_invoice_id', $inv_id );
            $order->save();
            swi_stdb_log_history( $order_id, 'ok', 'Automaatne saatmine, SB ID: ' . ( $inv_id ?: '?' ) );
        } else {
            $msg = $res['response']['result']['message'] ?? $res['message'] ?? 'Tundmatu viga';
            swi_stdb_log_history( $order_id, 'error', 'Saatmine ebaõnnestus: ' . $msg );
        }
    }

    /* ─── Cron ─── */

    public function cron_send_orders(): void {
        if ( get_option( 'swi_stdb_enable' ) !== 'yes' ) return;

        $status = get_option( 'swi_stdb_order_status', 'wc-completed' );
        $orders = wc_get_orders( [ 'limit' => 20, 'status' => $status, 'orderby' => 'id', 'order' => 'ASC' ] );

        foreach ( $orders as $order ) {
            if ( $order->get_meta( '_swi_sent_stdb' ) ) continue;
            $payload = $this->build_payload( $order );
            if ( ! $payload ) continue;

            $res = LocalApiClient::sendEncryptedOrder( $payload, 'standard_books' );
            if ( isset( $res['status'] ) && in_array( $res['status'], [ 'ok', 'queued' ], true ) ) {
                $order->update_meta_data( '_swi_sent_stdb', current_time( 'mysql' ) );
                $inv_id = $res['response']['result']['invoiceID'] ?? null;
                if ( $inv_id ) $order->update_meta_data( '_swi_stdb_invoice_id', $inv_id );
                $order->save();
                swi_stdb_log_history( $order->get_id(), 'ok', 'Cron, SB ID: ' . ( $inv_id ?: '?' ) );
            } else {
                $msg = $res['response']['result']['message'] ?? $res['message'] ?? 'Viga';
                swi_stdb_log_history( $order->get_id(), 'error', 'Cron ebaõnnestus: ' . $msg );
            }
            usleep( 300000 );
        }
    }

    /* ─── AJAX: bulk send ─── */

    public function handle_bulk_send(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );

        $status = get_option( 'swi_stdb_order_status', 'wc-completed' );
        $all    = wc_get_orders( [ 'limit' => 200, 'status' => $status, 'orderby' => 'id', 'order' => 'ASC' ] );
        $orders = array_slice( array_filter( $all, fn( $o ) => ! $o->get_meta( '_swi_sent_stdb' ) ), 0, 50 );

        if ( empty( $orders ) ) {
            wp_send_json_success( [ 'sent' => 0, 'failed' => 0, 'total' => 0, 'message' => 'Kõik arved on juba saadetud.' ] );
        }

        $sent = 0; $failed = 0; $errors = [];
        foreach ( $orders as $order ) {
            $payload = $this->build_payload( $order );
            if ( ! $payload ) { $failed++; continue; }

            $res = LocalApiClient::sendEncryptedOrder( $payload, 'standard_books' );
            if ( isset( $res['status'] ) && in_array( $res['status'], [ 'ok', 'queued' ], true ) ) {
                $order->update_meta_data( '_swi_sent_stdb', current_time( 'mysql' ) );
                $inv_id = $res['response']['result']['invoiceID'] ?? null;
                if ( $inv_id ) $order->update_meta_data( '_swi_stdb_invoice_id', $inv_id );
                $order->save();
                swi_stdb_log_history( $order->get_id(), 'ok', 'Bulk, SB ID: ' . ( $inv_id ?: '?' ) );
                $sent++;
            } else {
                $msg = $res['response']['result']['message'] ?? $res['message'] ?? 'Viga';
                $errors[] = '#' . $order->get_id() . ': ' . $msg;
                swi_stdb_log_history( $order->get_id(), 'error', 'Bulk ebaõnnestus: ' . $msg );
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
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );

        $order_id = (int) ( $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) wp_send_json_error( [ 'error' => 'order_id puudub.' ] );
        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_send_json_error( [ 'error' => 'Tellimust ei leitud.' ] );

        $payload = $this->build_payload( $order );
        if ( ! $payload ) wp_send_json_error( [ 'error' => 'Payload ehitamine ebaõnnestus.' ] );

        $res = LocalApiClient::sendEncryptedOrder( $payload, 'standard_books' );
        if ( isset( $res['status'] ) && in_array( $res['status'], [ 'ok', 'queued' ], true ) ) {
            $order->update_meta_data( '_swi_sent_stdb', current_time( 'mysql' ) );
            $inv_id = $res['response']['result']['invoiceID'] ?? null;
            if ( $inv_id ) $order->update_meta_data( '_swi_stdb_invoice_id', $inv_id );
            $order->save();
            swi_stdb_log_history( $order_id, 'ok', 'Käsitsi, SB ID: ' . ( $inv_id ?: '?' ) );
            wp_send_json_success( [ 'message' => 'Arve saadetud. SB ID: ' . ( $inv_id ?: '?' ) ] );
        } else {
            $msg = $res['response']['result']['message'] ?? $res['message'] ?? 'Tundmatu viga';
            swi_stdb_log_history( $order_id, 'error', 'Käsitsi ebaõnnestus: ' . $msg );
            wp_send_json_error( [ 'error' => $msg ] );
        }
    }

    /* ─── AJAX: sync check ─── */

    public function handle_sync_check(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );

        $status = get_option( 'swi_stdb_order_status', 'wc-completed' );
        $orders = wc_get_orders( [ 'limit' => 100, 'status' => $status ] );
        $rows   = [];

        foreach ( $orders as $order ) {
            $sent_at = $order->get_meta( '_swi_sent_stdb' );
            $inv_id  = $order->get_meta( '_swi_stdb_invoice_id' );
            $rows[]  = [
                'order_id'   => $order->get_id(),
                'inv_id'     => $inv_id ?: null,
                'total_html' => wc_price( $order->get_total() ),
                'in_stdb'    => ! empty( $sent_at ) || ! empty( $inv_id ),
                'meta_sent'  => $sent_at ? date( 'd.m.Y H:i', strtotime( $sent_at ) ) : null,
            ];
        }

        $missing = array_filter( $rows, fn( $r ) => ! $r['in_stdb'] );
        wp_send_json_success( [ 'rows' => $rows, 'missing_count' => count( $missing ) ] );
    }

    /* ─── AJAX: reset all ─── */

    public function handle_reset_sent(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        global $wpdb;
        $d1 = (int) $wpdb->delete( $wpdb->prefix . 'wc_orders_meta', [ 'meta_key' => '_swi_sent_stdb' ] );
        $d2 = (int) $wpdb->delete( $wpdb->prefix . 'wc_orders_meta', [ 'meta_key' => '_swi_stdb_invoice_id' ] );
        wp_send_json_success( [ 'message' => ( $d1 + $d2 ) . ' kirjet eemaldatud.' ] );
    }

    /* ─── AJAX: reset single ─── */

    public function handle_reset_single(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );

        $order_id = (int) ( $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) wp_send_json_error( [ 'error' => 'order_id puudub.' ] );
        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_send_json_error( [ 'error' => 'Tellimust ei leitud.' ] );

        $order->delete_meta_data( '_swi_sent_stdb' );
        $order->delete_meta_data( '_swi_stdb_invoice_id' );
        $order->save();

        $api_url = get_option( 'smart_wp_integration_server_url', '' );
        $lic_key = get_option( 'swi_stdb_license_key', '' );
        if ( $api_url && $lic_key ) {
            wp_remote_post( trailingslashit( $api_url ) . 'api/standard-books/reset-reference', [
                'headers' => [ 'X-License-Token' => $lic_key, 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [ 'reference_no' => (string) $order_id ] ),
                'timeout' => 5,
            ] );
        }

        wp_send_json_success( [ 'message' => 'Märgis eemaldatud.' ] );
    }

    /* ─── AJAX: eelvaade ─── */

    public function handle_preview(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );

        $order_id = (int) ( $_POST['order_id'] ?? 0 );
        $order    = $order_id ? wc_get_order( $order_id ) : null;
        if ( ! $order ) wp_send_json_error( [ 'error' => 'Tellimust ei leitud.' ] );

        wp_send_json_success( [ 'payload' => $this->build_payload( $order ) ] );
    }

    /* ─── AJAX: ühenduse test ─── */

    public function handle_connection_test(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );

        $api_url = get_option( 'smart_wp_integration_server_url', '' );
        $lic_key = get_option( 'swi_stdb_license_key', '' );
        if ( ! $api_url || ! $lic_key ) {
            wp_send_json_error( [ 'error' => 'API URL või litsentsi võti puudub seadistustes.' ] );
        }

        $resp = wp_remote_get( trailingslashit( $api_url ) . 'api/standard-books/test', [
            'headers' => [ 'X-License-Token' => $lic_key, 'Accept' => 'application/json' ],
            'timeout' => 10,
        ] );

        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( [ 'error' => $resp->get_error_message() ] );
        }

        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( ! empty( $data['ok'] ) ) {
            wp_send_json_success( [ 'message' => $data['message'] ?? 'Ühendus toimib.' ] );
        } else {
            wp_send_json_error( [ 'error' => $data['error'] ?? 'Ühendus ebaõnnestus.' ] );
        }
    }

    /* ─── AJAX: kustuta ajalugu ─── */

    public function handle_clear_history(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        update_option( 'swi_stdb_send_history', [] );
        wp_send_json_success( [ 'message' => 'Ajalugu kustutatud.' ] );
    }

    /* ─── Payload ─── */

    private function build_payload( \WC_Order $order ): ?array {
        return [
            'id'             => $order->get_id(),
            'number'         => $order->get_order_number(),
            'currency'       => $order->get_currency(),
            'customer_note'  => $order->get_customer_note(),
            'shipping_total' => $order->get_shipping_total(),
            'billing'        => [
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'company'    => $order->get_billing_company(),
                'email'      => $order->get_billing_email(),
                'phone'      => $order->get_billing_phone(),
                'address_1'  => $order->get_billing_address_1(),
                'address_2'  => $order->get_billing_address_2(),
                'city'       => $order->get_billing_city(),
                'country'    => $order->get_billing_country(),
                'reg_no'     => $order->get_meta( 'billing_reg_no' ),
                'vat_no'     => $order->get_meta( 'billing_vat_no' ),
            ],
            'items'          => array_map( function ( $item ) {
                $product = $item->get_product();
                $qty     = $item->get_quantity();
                $total   = (float) $item->get_total();
                return [
                    'name'       => $item->get_name(),
                    'sku'        => $product ? $product->get_sku() : '',
                    'product_id' => $item->get_product_id(),
                    'quantity'   => $qty,
                    'price'      => $qty > 0 ? round( $total / $qty, 4 ) : 0,
                    'total'      => $total,
                ];
            }, array_values( $order->get_items() ) ),
        ];
    }
}
