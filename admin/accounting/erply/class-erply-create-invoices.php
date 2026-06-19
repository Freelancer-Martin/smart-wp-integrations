<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWI_Erply_Create_Invoices {

    public function __construct() {
        $status = get_option( 'swi_erply_order_status', 'wc-completed' );
        $hook   = 'woocommerce_order_status_' . ( str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status );
        add_action( $hook, [ $this, 'auto_send_order' ], 20, 1 );

        add_action( 'wp_ajax_swi_erply_bulk_send',    [ $this, 'handle_bulk_send' ] );
        add_action( 'wp_ajax_swi_erply_order_send',   [ $this, 'handle_order_send' ] );
        add_action( 'wp_ajax_swi_erply_sync_check',   [ $this, 'handle_sync_check' ] );
        add_action( 'wp_ajax_swi_erply_reset_sent',   [ $this, 'handle_reset_sent' ] );
        add_action( 'wp_ajax_swi_erply_reset_single', [ $this, 'handle_reset_single' ] );
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
                $sent++;
            } else {
                $msg      = $res['response']['result']['message'] ?? $res['message'] ?? 'Tundmatu viga';
                $errors[] = '#' . $order->get_id() . ': ' . $msg;
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
            wp_send_json_success( [ 'message' => 'Arve edastatud Erplysse.' ] );
        } else {
            $msg = $res['response']['result']['message'] ?? $res['message'] ?? 'Tundmatu viga';
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
        wp_send_json_success( [ 'message' => 'Tellimus #' . $order_id . ' märgitud puuduvaks.' ] );
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
