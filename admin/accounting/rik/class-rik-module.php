<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWI_Rik_Module {

    public function __construct() {
        // Checkout fields
        add_filter( 'woocommerce_checkout_fields', [ $this, 'register_checkout_fields' ] );
        add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'save_checkout_fields' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_rik_script' ] );

        // AJAX — priority 5 nii et see käivitub enne Erply versiooni kui mõlemad aktiivsed
        add_action( 'wp_ajax_swi_rik_lookup',        [ $this, 'handle_rik_lookup' ], 5 );
        add_action( 'wp_ajax_nopriv_swi_rik_lookup', [ $this, 'handle_rik_lookup' ], 5 );
    }

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

    public function save_checkout_fields( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        if ( ! empty( $_POST['billing_reg_no'] ) ) {
            $order->update_meta_data( '_billing_reg_no', sanitize_text_field( wp_unslash( $_POST['billing_reg_no'] ) ) );
        }
        if ( ! empty( $_POST['billing_vat_no'] ) ) {
            $order->update_meta_data( '_billing_vat_no', sanitize_text_field( wp_unslash( $_POST['billing_vat_no'] ) ) );
        }
        $order->save();
    }

    public function enqueue_rik_script(): void {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
        wp_enqueue_script(
            'swi-rik-checkout',
            plugin_dir_url( dirname( dirname( __FILE__ ) ) ) . 'erply/swi-rik-checkout.js',
            [ 'jquery' ],
            '1.0.1',
            true
        );
        wp_localize_script( 'swi-rik-checkout', 'swiRik', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'swi_rik_nonce' ),
        ] );
    }

    public function handle_rik_lookup(): void {
        check_ajax_referer( 'swi_rik_nonce', 'security' );
        $reg_code = preg_replace( '/\D/', '', $_POST['reg_code'] ?? '' );
        if ( ! $reg_code || strlen( $reg_code ) < 7 || strlen( $reg_code ) > 8 ) {
            wp_send_json_error( [ 'error' => 'Vigane registrikood.' ] );
        }

        $api_url = get_option( 'smart_wp_integration_server_url', '' );
        $lic_key = get_option( 'swi_rik_license_key', '' );

        if ( ! $api_url || ! $lic_key ) {
            wp_send_json_error( [ 'error' => 'Äriregistri mooduli litsentsi võti puudub seadistustes.' ] );
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
}
