<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SWI_Smartpost_Admin {

    public function __construct() {
        add_action( 'woocommerce_admin_order_data_after_shipping_address', [ $this, 'show_parcel_info' ] );
        add_action( 'wp_ajax_swi_smartpost_flush_cache',                   [ $this, 'handle_flush_cache' ] );
    }

    public function show_parcel_info( WC_Order $order ): void {
        $pup_code = $order->get_meta( '_swi_smartpost_pup_code' );
        $loc_name = $order->get_meta( '_swi_smartpost_location_name' );
        if ( ! $pup_code ) return;
        ?>
        <div style="margin-top:12px;padding:10px 12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;">
            <strong style="font-size:12px;color:#15803d;">📦 Smartpost pakiautomaat</strong><br>
            <span style="font-size:13px;color:#111827;"><?php echo esc_html( $loc_name ?: $pup_code ); ?></span>
            <span style="font-size:11px;color:#6b7280;margin-left:6px;">(<?php echo esc_html( $pup_code ); ?>)</span>
        </div>
        <?php
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
