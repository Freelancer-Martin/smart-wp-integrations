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
        $hook   = 'woocommerce_order_status_' . ( str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status );
        add_action( $hook, [ $this, 'auto_send_order' ], 20, 1 );

        // AJAX
        add_action( 'wp_ajax_swi_sb_sync_check',    [ $this, 'handle_sync_check' ] );
        add_action( 'wp_ajax_swi_sb_sync_resend',   [ $this, 'handle_sync_resend' ] );
        add_action( 'wp_ajax_swi_sb_manual_send',   [ $this, 'handle_manual_send' ] );
        add_action( 'wp_ajax_swi_sb_clear_history', [ $this, 'handle_clear_history' ] );
        add_action( 'wp_ajax_swi_sb_bulk_send',     [ $this, 'handle_bulk_send' ] );
        add_action( 'wp_ajax_swi_sb_order_send',    [ $this, 'handle_order_send' ] );

        // Kolumn on kombineeritud — vt class-swi-order-column.php

        // Orderi lehel meta-box
        add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ] );

        // Admin notice kinni jäänud orderite kohta
        add_action( 'admin_notices', [ $this, 'show_stuck_notice' ] );
    }

    /* ─── WC orders kolumn ─── */

    public function add_order_column( array $cols ): array {
        $new  = [];
        $added = false;
        foreach ( $cols as $key => $label ) {
            $new[ $key ] = $label;
            if ( $key === 'order_status' ) {
                $new['swi_simplebooks'] = 'Simplebooks';
                $added = true;
            }
        }
        if ( ! $added ) {
            $new['swi_simplebooks'] = 'Simplebooks';
        }
        return $new;
    }

    public function render_order_column( string $column, $order_or_id ): void {
        if ( $column !== 'swi_simplebooks' ) return;
        $order = is_a( $order_or_id, 'WC_Order' ) ? $order_or_id : wc_get_order( (int) $order_or_id );
        if ( ! $order ) return;
        $sent  = $order->get_meta( '_swi_sent_simplebooks' );
        $retry = (int) $order->get_meta( '_swi_simplebooks_retry_count' );
        $nonce = wp_create_nonce( 'swi_sb_order_send' );
        if ( $sent ) {
            $label = esc_attr( date( 'd.m.Y H:i', strtotime( $sent ) ) );
            echo '<span title="Saadetud: ' . $label . '" style="color:#16a34a;font-size:13px;cursor:default;">✓ ' . date( 'd.m.y', strtotime( $sent ) ) . '</span>';
        } elseif ( $retry >= 3 ) {
            echo '<button class="button button-small swi-sb-col-send" data-id="' . esc_attr( $order->get_id() ) . '" data-nonce="' . $nonce . '" style="color:#dc2626;" title="Ebaõnnestus — proovi uuesti">↺ Saada</button>';
        } else {
            echo '<button class="button button-small swi-sb-col-send" data-id="' . esc_attr( $order->get_id() ) . '" data-nonce="' . $nonce . '">Saada</button>';
        }
    }

    public function render_col_send_script(): void {
        $screen = get_current_screen();
        if ( ! $screen || ! str_contains( $screen->id, 'order' ) ) return;
        ?>
        <script>
        jQuery(function($){
            $(document).on('click', '.swi-sb-col-send', function(){
                var btn = $(this), id = btn.data('id'), nonce = btn.data('nonce');
                btn.prop('disabled', true).text('...');
                $.post(ajaxurl, {action:'swi_sb_order_send', order_id:id, nonce:nonce}, function(r){
                    if (r.success) {
                        btn.replaceWith('<span style="color:#16a34a;font-size:13px;">✓ ' + (new Date().toLocaleDateString('et-EE',{day:'2-digit',month:'2-digit',year:'2-digit'})) + '</span>');
                    } else {
                        btn.prop('disabled', false).text('↺ Viga').css('color','#dc2626');
                        alert(r.data && r.data.message ? r.data.message : 'Viga');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /* ─── Meta-box orderi lehel ─── */

    public function register_meta_box(): void {
        foreach ( [ 'shop_order', 'wc-order' ] as $screen ) {
            add_meta_box(
                'swi-sb-metabox',
                'Simplebooks',
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

        $sent      = $order->get_meta( '_swi_sent_simplebooks' );
        $retry     = (int) $order->get_meta( '_swi_simplebooks_retry_count' );
        $retry_on  = $order->get_meta( '_swi_simplebooks_retry' );
        $nonce     = wp_create_nonce( 'my_nonce' );
        $prefix    = get_option( 'swi_simplebooks_prefix', 'SB' );
        $inv_no    = $prefix . $order_id;
        ?>
        <div id="swi-sb-metabox-<?php echo $order_id; ?>" style="font-size:12.5px;line-height:1.6;">
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
        <?php if ( get_option( 'swi_simplebooks_enable' ) === 'yes' ) : ?>
            <button type="button"
                    class="button button-small swi-sb-order-send-btn"
                    data-id="<?php echo $order_id; ?>"
                    data-nonce="<?php echo $nonce; ?>"
                    style="width:100%;">
                <?php echo $sent ? 'Saada uuesti' : 'Saada Simplebooks\'i'; ?>
            </button>
            <span id="swi-sb-order-result-<?php echo $order_id; ?>" style="display:block;margin-top:6px;font-size:11.5px;"></span>
        <?php endif; ?>
        </div>
        <script>
        (function(){
            var btn = document.querySelector('.swi-sb-order-send-btn[data-id="<?php echo $order_id; ?>"]');
            if (!btn) return;
            btn.addEventListener('click', function(){
                btn.disabled = true; btn.textContent = 'Saadan...';
                var res = document.getElementById('swi-sb-order-result-<?php echo $order_id; ?>');
                jQuery.post(ajaxurl, {
                    action: 'swi_sb_order_send',
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
        if ( get_option( 'swi_simplebooks_enable' ) !== 'yes' ) return;

        $stuck = wc_get_orders( [
            'limit'      => 5,
            'meta_query' => [
                'relation' => 'AND',
                [ 'key' => '_swi_simplebooks_retry_count', 'value' => 3, 'compare' => '>=', 'type' => 'NUMERIC' ],
                [ 'key' => '_swi_sent_simplebooks', 'compare' => 'NOT EXISTS' ],
            ],
        ] );

        if ( empty( $stuck ) ) return;

        $ids = implode( ', ', array_map( fn( $o ) => '#' . $o->get_id(), $stuck ) );
        $url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=smart_wp_integrations&swi_tab=simplebooks' );
        echo '<div class="notice notice-error is-dismissible"><p>'
            . '<strong>Smart WP Integrations:</strong> '
            . count( $stuck ) . ' Simplebooks arvet ei õnnestunud saata (3/3 katset läbi): '
            . esc_html( $ids ) . '. '
            . '<a href="' . esc_url( $url ) . '">Vaata Tööriistad paneeli →</a>'
            . '</p></div>';
    }

    /* ─── AJAX: meta-box order send ─── */

    public function handle_order_send(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        }

        $order_id = (int) ( $_POST['order_id'] ?? 0 );
        $order    = $order_id ? wc_get_order( $order_id ) : null;
        if ( ! $order ) wp_send_json_error( [ 'error' => 'Orderit ei leitud.' ] );

        $order->delete_meta_data( '_swi_sent_simplebooks' );
        $order->save();

        $payload = $this->build_payload( $order );
        if ( ! $payload ) wp_send_json_error( [ 'error' => 'Payload ehitamine ebaõnnestus.' ] );

        $res = LocalApiClient::sendEncryptedOrder( $payload, 'simplebooks' );

        if ( isset( $res['status'] ) && in_array( $res['status'], [ 'ok', 'queued' ], true ) ) {
            $order->update_meta_data( '_swi_sent_simplebooks', current_time( 'mysql' ) );
            $order->delete_meta_data( '_swi_simplebooks_retry' );
            $order->delete_meta_data( '_swi_simplebooks_retry_count' );
            $order->save();
            $order->add_order_note( 'Simplebooks: arve edastatud orderi lehelt.' );
            swi_sb_log_send_history( $order_id, 'ok', 'Orderi leht: ' . $payload['number'] );
            wp_send_json_success( [ 'message' => $payload['number'] . ' edastatud.' ] );
        } else {
            $msg = $this->humanize_error( $res );
            swi_sb_log_send_history( $order_id, 'error', 'Orderi leht: ' . $msg );
            wp_send_json_error( [ 'error' => $msg ] );
        }
    }

    /* ─── AJAX: bulk send kõik saadetamata orderid ─── */

    public function handle_bulk_send(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        }

        if ( get_option( 'swi_simplebooks_enable' ) !== 'yes' ) {
            wp_send_json_error( [ 'error' => 'Simplebooks pole lubatud.' ] );
        }

        $status = ltrim( get_option( 'swi_simplebooks_order_status', 'wc-completed' ), 'wc-' );
        $all    = wc_get_orders( [ 'status' => $status, 'limit' => 200 ] );
        $orders = array_slice( array_filter( $all, fn( $o ) => ! $o->get_meta( '_swi_sent_simplebooks' ) ), 0, 50 );

        if ( empty( $orders ) ) {
            wp_send_json_success( [ 'sent' => 0, 'failed' => 0, 'total' => 0, 'message' => 'Kõik arved on juba saadetud.' ] );
        }

        $sent = $failed = 0;
        $errors = [];

        foreach ( $orders as $order ) {
            $payload = $this->build_payload( $order );
            if ( ! $payload ) { $failed++; continue; }

            $res = LocalApiClient::sendEncryptedOrder( $payload, 'simplebooks' );

            if ( isset( $res['status'] ) && in_array( $res['status'], [ 'ok', 'queued' ], true ) ) {
                $order->update_meta_data( '_swi_sent_simplebooks', current_time( 'mysql' ) );
                $order->save();
                $order->add_order_note( 'Simplebooks: arve edastatud hulga sünkroniseerimisega.' );
                swi_sb_log_send_history( $order->get_id(), 'ok', 'Hulga saatmine: ' . $payload['number'] );
                $sent++;
            } else {
                $msg = $this->humanize_error( $res );
                swi_sb_log_send_history( $order->get_id(), 'error', 'Hulga saatmine ebaõnnestus: ' . $msg );
                $errors[] = '#' . $order->get_id() . ': ' . $msg;
                $failed++;
            }
        }

        wp_send_json_success( [
            'sent'    => $sent,
            'failed'  => $failed,
            'total'   => count( $orders ),
            'errors'  => $errors,
            'message' => $sent . ' arvet edastatud' . ( $failed ? ', ' . $failed . ' ebaõnnestus' : '.' ),
        ] );
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
            // Simplebooks list tagastab 'invoices' (väike, mitmus), mitte 'Invoice'
            $no = $inv['invoices']['number'] ?? $inv['Invoice']['number'] ?? $inv['number'] ?? null;
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
