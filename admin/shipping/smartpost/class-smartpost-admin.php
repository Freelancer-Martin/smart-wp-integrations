<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SWI_Smartpost_Admin {

    const BARCODE_META = '_swi_smartpost_barcode';

    public function __construct() {
        add_action( 'woocommerce_admin_order_data_after_shipping_address', [ $this, 'show_parcel_info' ] );
        add_action( 'wp_ajax_swi_smartpost_flush_cache',                   [ $this, 'handle_flush_cache' ] );
        add_action( 'wp_ajax_swi_sp_manual_send',                          [ $this, 'ajax_send' ] );
        add_action( 'wp_ajax_swi_sp_get_label',                            [ $this, 'ajax_label' ] );

        if ( get_option( 'swi_smartpost_auto_send' ) === 'yes' ) {
            add_action( 'woocommerce_order_status_processing', [ $this, 'maybe_auto_send' ] );
            add_action( 'woocommerce_order_status_completed',  [ $this, 'maybe_auto_send' ] );
        }

        if ( get_option( 'swi_smartpost_add_tracking' ) === 'yes' ) {
            add_action( 'woocommerce_email_order_details', [ $this, 'add_tracking_to_email' ], 10, 4 );
        }
    }

    private function order_uses_smartpost( WC_Order $order ): bool {
        foreach ( $order->get_shipping_methods() as $method ) {
            if ( strpos( $method->get_method_id(), 'swi_smartpost' ) !== false ) {
                return true;
            }
        }
        return false;
    }

    private function do_send( WC_Order $order ) {
        $order_id = $order->get_id();

        if ( $order->get_meta( self::BARCODE_META ) ) {
            return new WP_Error( 'already_sent', 'Pakiandmed on juba saadetud.' );
        }

        $pup_code = $order->get_meta( '_swi_smartpost_pup_code' );
        if ( ! $pup_code ) {
            return new WP_Error( 'no_pup', 'Pakiautomaat pole valitud.' );
        }

        $license_key = get_option( 'swi_smartpost_license_key', '' );
        if ( ! $license_key ) {
            return new WP_Error( 'no_license', 'Smartpost litsentsi võti puudub seadistustes.' );
        }

        $firstname = $order->get_billing_first_name();
        $lastname  = $order->get_billing_last_name();
        if ( $order->get_shipping_first_name() && $order->get_shipping_last_name() ) {
            $firstname = $order->get_shipping_first_name();
            $lastname  = $order->get_shipping_last_name();
        }

        $payload = [
            'order_id' => $order_id,
            'pup_code' => $pup_code,
            'sender'   => [
                'name'  => get_option( 'swi_smartpost_sender_name',  '' ),
                'phone' => get_option( 'swi_smartpost_sender_phone', '' ),
                'email' => get_option( 'swi_smartpost_sender_email', '' ),
            ],
            'recipient' => [
                'name'  => trim( $firstname . ' ' . $lastname ),
                'phone' => $order->get_billing_phone(),
                'email' => $order->get_billing_email(),
            ],
        ];

        $base_url = rtrim( get_option( 'smart_wp_integration_server_url', '' ), '/' ) ?: 'http://172.168.10.105';
        $resp = wp_remote_post( $base_url . '/api/smartpost/parcel/shipment', [
            'timeout' => 15,
            'headers' => [
                'Content-Type'    => 'application/json',
                'Accept'          => 'application/json',
                'X-License-Token' => $license_key,
            ],
            'body' => wp_json_encode( $payload ),
        ] );

        if ( is_wp_error( $resp ) ) {
            return $resp;
        }

        $code = wp_remote_retrieve_response_code( $resp );
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );

        if ( $code !== 200 || empty( $body['barcode'] ) ) {
            $msg = $body['error'] ?? ( 'Smartpost API viga (HTTP ' . $code . ')' );
            return new WP_Error( 'api_error', $msg );
        }

        $barcode = sanitize_text_field( $body['barcode'] );
        $order->update_meta_data( self::BARCODE_META, $barcode );
        $order->save();

        if ( get_option( 'swi_smartpost_send_label_copy' ) === 'yes' ) {
            $copy_email = get_option( 'swi_smartpost_label_email', '' );
            if ( $copy_email ) {
                $this->send_label_email( $order_id, $barcode, $copy_email, $license_key, $base_url );
            }
        }

        return $barcode;
    }

    private function send_label_email( int $order_id, string $barcode, string $to, string $license_key, string $base_url ): void {
        $size = get_option( 'swi_smartpost_label_size', 'A4' );
        $resp = wp_remote_get(
            $base_url . '/api/smartpost/parcel/label?barcode=' . urlencode( $barcode ) . '&order_id=' . $order_id . '&size=' . urlencode( $size ),
            [
                'timeout' => 30,
                'headers' => [
                    'Accept'          => 'application/pdf',
                    'X-License-Token' => $license_key,
                ],
            ]
        );

        if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
            return;
        }

        $pdf  = wp_remote_retrieve_body( $resp );
        $dest = sys_get_temp_dir() . '/pakisilt_sp_' . $order_id . '_' . uniqid() . '.pdf';
        file_put_contents( $dest, $pdf ); // phpcs:ignore WordPress.WP.AlternativeFunctions

        wp_mail(
            $to,
            'Pakisilt tellimusele #' . $order_id,
            'Lisatud on pakisilt tellimusele #' . $order_id . '. Jälgimiskood: ' . $barcode,
            [],
            [ $dest ]
        );

        @unlink( $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
    }

    public function maybe_auto_send( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order || ! $this->order_uses_smartpost( $order ) ) return;
        if ( $order->get_meta( self::BARCODE_META ) ) return;

        $result = $this->do_send( $order );
        if ( is_wp_error( $result ) ) {
            $order->add_order_note( 'Smartpost pakiautomaat — automaatne saatmine ebaõnnestus: ' . $result->get_error_message() );
        }
    }

    public function add_tracking_to_email( WC_Order $order, bool $sent_to_admin, bool $plain_text, object $email ): void {
        if ( $email->id !== 'customer_completed_order' ) return;

        $barcode = $order->get_meta( self::BARCODE_META );
        if ( ! $barcode || ! $this->order_uses_smartpost( $order ) ) return;

        $url = 'https://itella.ee/en/private-customer/parcel-tracking/?trackingCode=' . rawurlencode( $barcode );

        if ( $plain_text ) {
            echo "\nSinu pakk on teel. Jälgimiskood: " . $barcode . "\nJälgi pakki: " . $url . "\n";
        } else {
            echo '<p style="margin:16px 0;">Sinu pakk on teel — <strong>Smartpost pakiautomaat</strong>.<br>'
                . 'Jälgimiskood: <strong>' . esc_html( $barcode ) . '</strong> &mdash; '
                . '<a href="' . esc_url( $url ) . '">Jälgi pakki</a></p>';
        }
    }

    public function show_parcel_info( WC_Order $order ): void {
        $pup_code = $order->get_meta( '_swi_smartpost_pup_code' );
        $loc_name = $order->get_meta( '_swi_smartpost_location_name' );
        if ( ! $pup_code ) return;

        $barcode  = $order->get_meta( self::BARCODE_META );
        $order_id = $order->get_id();
        $nonce    = wp_create_nonce( 'swi_sp_admin_nonce' );
        ?>
        <div style="margin-top:12px;padding:10px 12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;">
            <strong style="font-size:12px;color:#15803d;">📦 Smartpost pakiautomaat</strong><br>
            <span style="font-size:13px;color:#111827;"><?php echo esc_html( $loc_name ?: $pup_code ); ?></span>
            <span style="font-size:11px;color:#6b7280;margin-left:6px;">(<?php echo esc_html( $pup_code ); ?>)</span>
            <?php if ( $barcode ) : ?>
            <div style="margin-top:6px;">
                <span style="font-size:12px;color:#6b7280;">Vöötkood: <?php echo esc_html( $barcode ); ?></span>
                &nbsp;
                <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=swi_sp_get_label&order_id=' . $order_id . '&nonce=' . $nonce ) ); ?>"
                   target="_blank" class="button button-small">📄 Prindi pakisilt</a>
            </div>
            <?php else : ?>
            <div style="margin-top:6px;">
                <button type="button" class="button button-small"
                        id="swi-sp-send-btn-<?php echo esc_attr( $order_id ); ?>"
                        onclick="swiSpSend(<?php echo (int) $order_id; ?>, '<?php echo esc_js( $nonce ); ?>')">
                    📤 Saada serverisse
                </button>
                <span id="swi-sp-send-result-<?php echo esc_attr( $order_id ); ?>" style="margin-left:8px;font-size:13px;"></span>
            </div>
            <script>
            function swiSpSend(orderId, nonce) {
                if ( ! confirm('Saata pakiandmed Smartpost serverisse?') ) return;
                var btn = document.getElementById('swi-sp-send-btn-' + orderId);
                var res = document.getElementById('swi-sp-send-result-' + orderId);
                btn.disabled = true;
                res.textContent = 'Saadan...';
                res.style.color = '#6b7280';
                jQuery.post(ajaxurl, {action: 'swi_sp_manual_send', order_id: orderId, nonce: nonce}, function(r) {
                    btn.disabled = false;
                    if (r.success) {
                        res.style.color = '#16a34a';
                        res.textContent = '✓ Saadetud. Vöötkood: ' + (r.data.barcode || '');
                        setTimeout(function(){ location.reload(); }, 1500);
                    } else {
                        res.style.color = '#dc2626';
                        res.textContent = '✗ ' + (r.data && r.data.error ? r.data.error : 'Viga');
                    }
                }).fail(function() {
                    btn.disabled = false;
                    res.style.color = '#dc2626';
                    res.textContent = '✗ Serveri viga';
                });
            }
            </script>
            <?php endif; ?>
        </div>
        <?php
    }

    public function ajax_send(): void {
        check_ajax_referer( 'swi_sp_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $order    = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'error' => 'Tellimus ei leitud.' ] );
        }

        $result = $this->do_send( $order );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'error' => $result->get_error_message() ] );
        }

        wp_send_json_success( [ 'barcode' => $result ] );
    }

    public function ajax_label(): void {
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['nonce'] ?? '' ) ), 'swi_sp_admin_nonce' ) ) {
            wp_die( 'Vigane nonce.' );
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Puuduvad õigused.' );
        }

        $order_id = absint( $_GET['order_id'] ?? 0 );
        $order    = wc_get_order( $order_id );
        if ( ! $order ) wp_die( 'Tellimus ei leitud.' );

        $barcode     = $order->get_meta( self::BARCODE_META );
        $license_key = get_option( 'swi_smartpost_license_key', '' );
        if ( ! $barcode || ! $license_key ) wp_die( 'Vöötkood või litsentsi võti puudub.' );

        $size     = get_option( 'swi_smartpost_label_size', 'A4' );
        $base_url = rtrim( get_option( 'smart_wp_integration_server_url', '' ), '/' ) ?: 'http://172.168.10.105';
        $resp = wp_remote_get(
            $base_url . '/api/smartpost/parcel/label?barcode=' . urlencode( $barcode ) . '&order_id=' . $order_id . '&size=' . urlencode( $size ),
            [
                'timeout' => 30,
                'headers' => [
                    'Accept'          => 'application/pdf',
                    'X-License-Token' => $license_key,
                ],
            ]
        );

        if ( is_wp_error( $resp ) ) {
            wp_die( $resp->get_error_message() );
        }

        $pdf  = wp_remote_retrieve_body( $resp );
        $code = wp_remote_retrieve_response_code( $resp );

        if ( $code !== 200 || empty( $pdf ) ) {
            wp_die( 'Pakisildi laadimine ebaõnnestus (HTTP ' . $code . ').' );
        }

        $new_status = get_option( 'swi_smartpost_status_after_label', '' );
        if ( $new_status && ! $order->has_status( $new_status ) ) {
            $order->update_status( $new_status );
        }

        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: attachment; filename="pakisilt_sp_' . $order_id . '.pdf"' );
        header( 'Content-Length: ' . strlen( $pdf ) );
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    public function handle_flush_cache(): void {
        check_ajax_referer( 'my_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
        }
        SWI_Smartpost_Shipping::flush_cache();
        wp_send_json_success( [ 'message' => 'Pakiautomaatide cache tühjendatud.' ] );
    }
}
