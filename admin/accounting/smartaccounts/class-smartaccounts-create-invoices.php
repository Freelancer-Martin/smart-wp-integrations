<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Logi Smart Accounts saatmiskatse ajalukku.
 */
function swi_sa_log_send_history( int $order_id, string $status, string $message ): void {
    $history = get_option( 'swi_sa_send_history', [] );
    if ( ! is_array( $history ) ) {
        $history = [];
    }
    array_unshift( $history, [
        'time'     => current_time( 'Y-m-d H:i:s' ),
        'order_id' => $order_id,
        'status'   => $status,
        'message'  => $message,
    ] );
    update_option( 'swi_sa_send_history', array_slice( $history, 0, 50 ) );
}

/**
 * Smart Accounts arve loomine ja haldus WooCommerce orderist.
 */
class SWI_SmartAccounts_Create_Invoices {

    public function __construct() {
        $status = get_option( 'swi_smartaccounts_order_status', 'wc-completed' );
        $hook   = 'woocommerce_order_status_' . ( str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status );
        add_action( $hook, [ $this, 'auto_send_order' ], 20, 1 );

        add_action( 'wp_ajax_swi_sa_sync_check',    [ $this, 'handle_sync_check' ] );
        add_action( 'wp_ajax_swi_sa_sync_resend',   [ $this, 'handle_sync_resend' ] );
        add_action( 'wp_ajax_swi_sa_manual_send',   [ $this, 'handle_manual_send' ] );
        add_action( 'wp_ajax_swi_sa_clear_history', [ $this, 'handle_clear_history' ] );
        add_action( 'wp_ajax_swi_sa_bulk_send',     [ $this, 'handle_bulk_send' ] );
        add_action( 'wp_ajax_swi_sa_order_send',    [ $this, 'handle_order_send' ] );

        // Tellimuste nimekirja SA staatus kolumn — HPOS + legacy
        add_filter( 'manage_woocommerce_page_wc-orders_columns',  [ $this, 'add_order_column' ] );
        add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ $this, 'render_order_column' ], 10, 2 );
        add_filter( 'manage_edit-shop_order_columns',             [ $this, 'add_order_column' ] );
        add_action( 'manage_shop_order_posts_custom_column',      [ $this, 'render_order_column' ], 10, 2 );

        add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ] );
        add_action( 'admin_notices',  [ $this, 'show_stuck_notice' ] );
    }

    /* ─── WC orders kolumn ─── */

    public function add_order_column( array $cols ): array {
        $new   = [];
        $added = false;
        foreach ( $cols as $key => $label ) {
            $new[ $key ] = $label;
            if ( $key === 'order_status' ) {
                $new['swi_smartaccounts'] = 'SA';
                $added = true;
            }
        }
        if ( ! $added ) {
            $new['swi_smartaccounts'] = 'SA';
        }
        return $new;
    }

    public function render_order_column( string $column, $order_or_id ): void {
        if ( $column !== 'swi_smartaccounts' ) return;
        $order = is_a( $order_or_id, 'WC_Order' ) ? $order_or_id : wc_get_order( (int) $order_or_id );
        if ( ! $order ) return;
        $sent  = $order->get_meta( '_swi_sent_smartaccounts' );
        $retry = (int) $order->get_meta( '_swi_smartaccounts_retry_count' );
        if ( $sent ) {
            $label = esc_attr( date( 'd.m.Y H:i', strtotime( $sent ) ) );
            echo '<span title="Saadetud: ' . $label . '" style="color:#16a34a;font-size:15px;cursor:default;">✓</span>';
        } elseif ( $retry >= 3 ) {
            echo '<span title="Saatmine ebaõnnestus (3/3 katset)" style="color:#dc2626;font-size:15px;cursor:default;">✗</span>';
        } else {
            echo '<span style="color:#9ca3af;font-size:15px;cursor:default;">—</span>';
        }
    }

    /* ─── Meta-box orderi lehel ─── */

    public function register_meta_box(): void {
        foreach ( [ 'shop_order', 'wc-order' ] as $screen ) {
            add_meta_box(
                'swi-sa-metabox',
                'Smart Accounts',
                [ $this, 'render_meta_box' ],
                $screen,
                'side',
                'default'
            );
        }
    }

    public function render_meta_box( $post_or_order ): void {
        $order_id = is_a( $post_or_order, 'WC_Order' ) ? $post_or_order->get_id() : $post_or_order->ID;
        $order    = wc_get_order( $order_id );
        if ( ! $order ) return;

        $sent     = $order->get_meta( '_swi_sent_smartaccounts' );
        $retry    = (int) $order->get_meta( '_swi_smartaccounts_retry_count' );
        $retry_on = $order->get_meta( '_swi_smartaccounts_retry' );
        $nonce    = wp_create_nonce( 'my_nonce' );
        $prefix   = get_option( 'swi_smartaccounts_prefix', 'SA' );
        $inv_no   = $prefix . $order_id;
        ?>
        <div id="swi-sa-metabox-<?php echo $order_id; ?>" style="font-size:12.5px;line-height:1.6;">
        <?php if ( $sent ) : ?>
            <p style="margin:0 0 6px;color:#16a34a;">✓ <strong>Saadetud</strong></p>
            <p style="margin:0 0 8px;color:#6b7280;">Arve: <?php echo esc_html( $inv_no ); ?><br>
               Aeg: <?php echo esc_html( date( 'd.m.Y H:i', strtotime( $sent ) ) ); ?></p>
        <?php elseif ( $retry >= 3 ) : ?>
            <p style="margin:0 0 6px;color:#dc2626;">✗ <strong>Saatmine ebaõnnestus</strong></p>
            <p style="margin:0 0 8px;color:#6b7280;"><?php echo $retry; ?>/3 katset tehtud</p>
        <?php elseif ( $retry_on ) : ?>
            <p style="margin:0 0 6px;color:#d97706;">⟳ <strong>Järjekorras</strong></p>
            <p style="margin:0 0 8px;color:#6b7280;">Katse <?php echo $retry; ?>/3 — proovitakse uuesti</p>
        <?php else : ?>
            <p style="margin:0 0 8px;color:#6b7280;">Pole saadetud</p>
        <?php endif; ?>
        <?php if ( get_option( 'swi_smartaccounts_enable' ) === 'yes' ) : ?>
            <button type="button"
                    class="button button-small swi-sa-order-send-btn"
                    data-id="<?php echo $order_id; ?>"
                    data-nonce="<?php echo $nonce; ?>"
                    style="width:100%;">
                <?php echo $sent ? 'Saada uuesti' : 'Saada Smart Accountsi'; ?>
            </button>
            <span id="swi-sa-order-result-<?php echo $order_id; ?>" style="display:block;margin-top:6px;font-size:11.5px;"></span>
        <?php endif; ?>
        </div>
        <script>
        (function(){
            var btn = document.querySelector('.swi-sa-order-send-btn[data-id="<?php echo $order_id; ?>"]');
            if (!btn) return;
            btn.addEventListener('click', function(){
                btn.disabled = true; btn.textContent = 'Saadan...';
                var res = document.getElementById('swi-sa-order-result-<?php echo $order_id; ?>');
                jQuery.post(ajaxurl, {
                    action: 'swi_sa_order_send',
                    security: btn.dataset.nonce,
                    order_id: btn.dataset.id
                }, function(r){
                    btn.disabled = false;
                    if (r.success) {
                        btn.textContent = 'Saada uuesti';
                        res.innerHTML = '<span style="color:#16a34a">✓ ' + (r.data.message||'Saadetud') + '</span>';
                    } else {
                        btn.textContent = 'Proovi uuesti';
                        res.innerHTML = '<span style="color:#dc2626">⚠ ' + ((r.data&&r.data.error)||'Viga') + '</span>';
                    }
                }).fail(function(){ btn.disabled=false; btn.textContent='Proovi uuesti'; res.textContent='Ühendus katkes.'; });
            });
        })();
        </script>
        <?php
    }

    /* ─── Admin notice: kinni jäänud orderid ─── */

    public function show_stuck_notice(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;
        if ( get_option( 'swi_smartaccounts_enable' ) !== 'yes' ) return;

        $stuck = wc_get_orders( [
            'limit'      => 5,
            'meta_query' => [
                'relation' => 'AND',
                [ 'key' => '_swi_smartaccounts_retry_count', 'value' => 3, 'compare' => '>=', 'type' => 'NUMERIC' ],
                [ 'key' => '_swi_sent_smartaccounts', 'compare' => 'NOT EXISTS' ],
            ],
        ] );

        if ( empty( $stuck ) ) return;

        $ids = implode( ', ', array_map( fn( $o ) => '#' . $o->get_id(), $stuck ) );
        $url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=smart_wp_integrations&swi_tab=smartaccounts' );
        echo '<div class="notice notice-error is-dismissible"><p>'
            . '<strong>Smart Accounts:</strong> ' . count( $stuck ) . ' tellimust ei saanud saata (orderid: ' . esc_html( $ids ) . '). '
            . '<a href="' . esc_url( $url ) . '">Vaata Tööriistad paneelist</a>.'
            . '</p></div>';
    }

    /* ─── Automaatne saatmine ─── */

    public function auto_send_order( int $order_id ): void {
        if ( get_option( 'swi_smartaccounts_enable' ) !== 'yes' ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        if ( $order->get_meta( '_swi_sent_smartaccounts' ) ) return;

        $payload = $this->build_payload( $order );
        if ( ! $payload ) return;

        $res = LocalApiClient::sendEncryptedOrder( $payload, 'smartaccounts' );

        if ( isset( $res['status'] ) && in_array( $res['status'], [ 'ok', 'queued' ], true ) ) {
            $order->update_meta_data( '_swi_sent_smartaccounts', current_time( 'mysql' ) );
            $order->delete_meta_data( '_swi_smartaccounts_retry' );
            $order->delete_meta_data( '_swi_smartaccounts_retry_count' );
            $order->save();
            $order->add_order_note( 'Smart Accounts: arve edastatud automaatselt.' );
            swi_sa_log_send_history( $order_id, 'ok', 'Automaatne saatmine: ' . $payload['number'] );
        } else {
            $count = (int) $order->get_meta( '_swi_smartaccounts_retry_count' );
            $order->update_meta_data( '_swi_smartaccounts_retry', '1' );
            $order->update_meta_data( '_swi_smartaccounts_retry_count', $count + 1 );
            $order->save();
            $msg = $this->humanize_error( $res );
            swi_sa_log_send_history( $order_id, 'error', 'Automaatne saatmine ebaõnnestus: ' . $msg );
        }
    }

    /* ─── AJAX: meta-box "Saada" nupp ─── */

    public function handle_order_send(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        }

        $order_id = (int) ( $_POST['order_id'] ?? 0 );
        $order    = $order_id ? wc_get_order( $order_id ) : null;
        if ( ! $order ) {
            wp_send_json_error( [ 'error' => 'Orderit ei leitud.' ] );
        }

        $order->delete_meta_data( '_swi_sent_smartaccounts' );
        $order->delete_meta_data( '_swi_smartaccounts_retry' );
        $order->delete_meta_data( '_swi_smartaccounts_retry_count' );
        $order->save();

        $payload = $this->build_payload( $order );
        if ( ! $payload ) {
            wp_send_json_error( [ 'error' => 'Payload ehitamine ebaõnnestus (pole tooteid?).' ] );
        }

        $res = LocalApiClient::sendEncryptedOrder( $payload, 'smartaccounts' );

        if ( isset( $res['status'] ) && in_array( $res['status'], [ 'ok', 'queued' ], true ) ) {
            $order->update_meta_data( '_swi_sent_smartaccounts', current_time( 'mysql' ) );
            $order->save();
            $order->add_order_note( 'Smart Accounts: arve edastatud käsitsi.' );
            swi_sa_log_send_history( $order_id, 'ok', 'Käsitsi saatmine: ' . $payload['number'] );
            wp_send_json_success( [ 'message' => 'Arve ' . esc_html( $payload['number'] ) . ' edastatud.' ] );
        } else {
            $msg = $this->humanize_error( $res );
            swi_sa_log_send_history( $order_id, 'error', 'Käsitsi saatmine ebaõnnestus: ' . $msg );
            wp_send_json_error( [ 'error' => $msg ] );
        }
    }

    /* ─── AJAX: sünkroniseerimise kontroll ─── */

    public function handle_sync_check(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        }

        $prefix  = get_option( 'swi_smartaccounts_prefix', 'SA' );
        $status  = get_option( 'swi_smartaccounts_order_status', 'wc-completed' );
        $orders  = wc_get_orders( [ 'limit' => 100, 'status' => $status ] );

        $base_url = LocalApiClient::get_base_url_public();
        $lic_key  = get_option( 'swi_license_key', '' );

        $resp = wp_remote_get( trailingslashit( $base_url ) . 'api/smartaccounts/invoices?per_page=500', [
            'timeout' => 20,
            'headers' => [
                'X-License-Token' => $lic_key,
                'Accept'          => 'application/json',
            ],
        ] );

        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( [ 'error' => 'Vaheserveri viga: ' . $resp->get_error_message() ] );
        }

        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code !== 200 ) {
            wp_send_json_error( [ 'error' => 'Vaheserveri viga HTTP ' . $code ] );
        }

        $body     = json_decode( wp_remote_retrieve_body( $resp ), true );
        $invoices = $body['data'] ?? $body ?? [];
        $sa_nos   = [];

        foreach ( (array) $invoices as $inv ) {
            $no = $inv['invoiceNumber'] ?? $inv['number'] ?? $inv['referenceNumber'] ?? $inv['referenceNo'] ?? null;
            if ( $no ) {
                $sa_nos[] = (string) $no;
            }
        }

        $rows = [];
        foreach ( $orders as $order ) {
            $inv_no  = $prefix . $order->get_id();
            $in_sa   = in_array( $inv_no, $sa_nos, true );
            $sent_at = $order->get_meta( '_swi_sent_smartaccounts' );
            $rows[]  = [
                'order_id'   => $order->get_id(),
                'inv_no'     => $inv_no,
                'total'      => wc_price( $order->get_total() ),
                'in_sa'      => $in_sa,
                'meta_sent'  => $sent_at ? date( 'd.m.Y H:i', strtotime( $sent_at ) ) : null,
            ];
        }

        $missing = array_filter( $rows, fn( $r ) => ! $r['in_sa'] );
        wp_send_json_success( [ 'rows' => $rows, 'missing_count' => count( $missing ) ] );
    }

    /* ─── AJAX: saadetamata arve uuesti saatmine (sync paneelist) ─── */

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

        $order->delete_meta_data( '_swi_sent_smartaccounts' );
        $order->save();

        $payload = $this->build_payload( $order );
        if ( ! $payload ) {
            wp_send_json_error( [ 'error' => 'Payload ehitamine ebaõnnestus.' ] );
        }

        $res = LocalApiClient::sendEncryptedOrder( $payload, 'smartaccounts' );

        if ( isset( $res['status'] ) && in_array( $res['status'], [ 'ok', 'queued' ], true ) ) {
            $order->update_meta_data( '_swi_sent_smartaccounts', current_time( 'mysql' ) );
            $order->save();
            swi_sa_log_send_history( $order_id, 'ok', 'Sync uuesti saatmine: ' . $payload['number'] );
            wp_send_json_success( [ 'message' => 'Arve ' . esc_html( $payload['number'] ) . ' edastatud.' ] );
        } else {
            $msg = $this->humanize_error( $res );
            swi_sa_log_send_history( $order_id, 'error', 'Sync uuesti saatmine ebaõnnestus: ' . $msg );
            wp_send_json_error( [ 'error' => $msg ] );
        }
    }

    /* ─── AJAX: käsitsi saatmine (Tööriistad paneelist) ─── */

    public function handle_manual_send(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        }

        $order_id     = (int) ( $_POST['order_id'] ?? 0 );
        $preview_only = ! empty( $_POST['preview_only'] );
        $order        = $order_id ? wc_get_order( $order_id ) : null;
        if ( ! $order ) {
            wp_send_json_error( [ 'error' => 'Orderit ei leitud.' ] );
        }

        $payload = $this->build_payload( $order );
        if ( ! $payload ) {
            wp_send_json_error( [ 'error' => 'Payload ehitamine ebaõnnestus (pole tooteid?).' ] );
        }

        if ( $preview_only ) {
            wp_send_json_success( [ 'payload' => $payload ] );
        }

        $order->delete_meta_data( '_swi_sent_smartaccounts' );
        $order->save();

        $res = LocalApiClient::sendEncryptedOrder( $payload, 'smartaccounts' );

        if ( isset( $res['status'] ) && in_array( $res['status'], [ 'ok', 'queued' ], true ) ) {
            $order->update_meta_data( '_swi_sent_smartaccounts', current_time( 'mysql' ) );
            $order->save();
            $order->add_order_note( 'Smart Accounts: arve edastatud käsitsi tööriistad paneelist.' );
            swi_sa_log_send_history( $order_id, 'ok', 'Käsitsi saatmine: ' . $payload['number'] );
            wp_send_json_success( [ 'message' => 'Arve ' . esc_html( $payload['number'] ) . ' edastatud edukalt.' ] );
        } else {
            $msg = $this->humanize_error( $res );
            swi_sa_log_send_history( $order_id, 'error', 'Käsitsi saatmine ebaõnnestus: ' . $msg );
            wp_send_json_error( [ 'error' => $msg ] );
        }
    }

    /* ─── AJAX: sünkroniseeri kõik puuduvad ─── */

    public function handle_bulk_send(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        }

        $status   = get_option( 'swi_smartaccounts_order_status', 'wc-completed' );
        $all      = wc_get_orders( [ 'limit' => 200, 'status' => $status ] );
        $orders   = array_slice( array_filter( $all, fn( $o ) => ! $o->get_meta( '_swi_sent_smartaccounts' ) ), 0, 50 );

        // Debug: kui 0 orderit, tagasta info miks
        if ( empty( $orders ) ) {
            $all_statuses = array_unique( array_map( fn( $o ) => $o->get_status(), wc_get_orders( [ 'limit' => 10 ] ) ) );
            wp_send_json_success( [
                'sent'    => 0, 'failed' => 0, 'total' => 0,
                'message' => 'Kõik arved on juba saadetud.',
                '_debug'  => [
                    'queried_status' => $status,
                    'all_found'      => count( $all ),
                    'order_statuses' => $all_statuses,
                ],
            ] );
        }

        $sent = 0; $failed = 0; $errors = [];

        foreach ( $orders as $order ) {
            $payload = $this->build_payload( $order );
            if ( ! $payload ) { $failed++; continue; }

            $res = LocalApiClient::sendEncryptedOrder( $payload, 'smartaccounts' );

            if ( isset( $res['status'] ) && in_array( $res['status'], [ 'ok', 'queued' ], true ) ) {
                $order->update_meta_data( '_swi_sent_smartaccounts', current_time( 'mysql' ) );
                $order->save();
                swi_sa_log_send_history( $order->get_id(), 'ok', 'Bulk saatmine: ' . $payload['number'] );
                $sent++;
            } else {
                $msg = $this->humanize_error( $res );
                swi_sa_log_send_history( $order->get_id(), 'error', 'Bulk saatmine ebaõnnestus: ' . $msg );
                $errors[] = '#' . $order->get_id() . ': ' . $msg;
                $failed++;
            }
        }

        $total = count( $orders );
        wp_send_json_success( [
            'sent'    => $sent,
            'failed'  => $failed,
            'total'   => $total,
            'errors'  => $errors,
            'message' => "Saadetud: {$sent}/{$total}. Ebaõnnestunud: {$failed}.",
        ] );
    }

    /* ─── AJAX: kustuta ajalugu ─── */

    public function handle_clear_history(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        }
        delete_option( 'swi_sa_send_history' );
        wp_send_json_success( [ 'message' => 'Ajalugu kustutatud.' ] );
    }

    /* ─── Payload builder ─── */

    public function build_payload( \WC_Order $order ): ?array {
        $prefix = get_option( 'swi_smartaccounts_prefix', 'SA' );

        $items = [];
        foreach ( $order->get_items() as $item ) {
            /** @var \WC_Order_Item_Product $item */
            $product  = $item->get_product();
            $sku      = $product ? $product->get_sku() : '';
            $qty      = (float) $item->get_quantity();
            $total    = (float) $item->get_total();
            $tax      = (float) $item->get_total_tax();
            $vat_pct  = ( $total > 0 ) ? round( $tax / $total * 100, 2 ) : 0;

            $items[] = [
                'sku'        => $sku ?: ( 'ITEM-' . $item->get_product_id() ),
                'name'       => $item->get_name(),
                'quantity'   => $qty,
                'total'      => $total,
                'vat_pct'    => $vat_pct,
                'product_id' => $item->get_product_id(),
            ];
        }

        foreach ( $order->get_items( 'shipping' ) as $ship ) {
            $ship_total = (float) $ship->get_total();
            if ( $ship_total > 0 ) {
                $ship_tax = (float) $ship->get_total_tax();
                $vat_pct  = ( $ship_total > 0 ) ? round( $ship_tax / $ship_total * 100, 2 ) : 0;
                $items[]  = [
                    'sku'      => 'TRANSPORT',
                    'name'     => $ship->get_name() ?: 'Tarne',
                    'quantity' => 1.0,
                    'total'    => $ship_total,
                    'vat_pct'  => $vat_pct,
                ];
            }
        }

        if ( empty( $items ) ) return null;

        $created_at = $order->get_date_created();
        $date       = $created_at ? $created_at->format( 'Y-m-d' ) : current_time( 'Y-m-d' );
        $deadline   = (int) get_option( 'swi_smartaccounts_payment_days', 14 );
        $due        = date( 'Y-m-d', strtotime( '+' . $deadline . ' days', strtotime( $date ) ) );

        return [
            'id'            => $order->get_id(),
            'number'        => $prefix . $order->get_id(),
            'date_created'  => $date,
            'due_date'      => $due,
            'currency'      => $order->get_currency() ?: 'EUR',
            'total'         => (float) $order->get_total(),
            'customer_note' => $order->get_customer_note(),
            'first_name'    => $order->get_billing_first_name(),
            'last_name'     => $order->get_billing_last_name(),
            'company'       => $order->get_billing_company(),
            'email'         => $order->get_billing_email(),
            'phone'         => $order->get_billing_phone(),
            'billing'       => [
                'address_1' => $order->get_billing_address_1(),
                'address_2' => $order->get_billing_address_2(),
                'city'      => $order->get_billing_city(),
                'postcode'  => $order->get_billing_postcode(),
                'country'   => $order->get_billing_country(),
            ],
            'meta_data'     => [
                'company_reg_no' => $order->get_meta( '_billing_reg_no' ) ?: $order->get_meta( 'billing_reg_no' ) ?: '',
                'company_vat_no' => $order->get_meta( '_billing_vat_no' ) ?: $order->get_meta( 'billing_vat_no' ) ?: '',
            ],
            'items'         => $items,
        ];
    }

    /* ─── Private helpers ─── */

    private function humanize_error( array $res ): string {
        $msg = $res['response']['result']['message']
            ?? $res['response']['message']
            ?? $res['message']
            ?? '';

        if ( ! $msg || $msg === 'Handler returned failure' ) {
            $msg = $res['response']['result']['body'] ?? $msg;
        }

        $map = [
            'already exists'          => 'Arve on Smart Accountsis juba olemas (korduvnumber).',
            'duplicate'               => 'Arve on Smart Accountsis juba olemas (korduvnumber).',
            'apikey'                  => 'Smart Accounts API võti on vigane.',
            'signature'               => 'Smart Accounts allkiri on vigane — kontrolli Secret võtit.',
            'not found'               => 'Ressurssi ei leitud Smart Accountsis.',
            'Handler returned failure'=> 'Smart Accounts keeldus arvet vastu võtmast.',
            'clientId'                => 'Kliendi ID puudub Smart Accountsis.',
        ];

        foreach ( $map as $key => $friendly ) {
            if ( stripos( (string) $msg, $key ) !== false ) {
                return $friendly;
            }
        }

        return $msg ?: 'Tundmatu viga Smart Accounts API-lt.';
    }
}
