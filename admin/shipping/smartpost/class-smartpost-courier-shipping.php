<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SWI_Smartpost_Courier_Shipping extends WC_Shipping_Method {

    const PICKUP_TIME_META = '_swi_spc_pickup_time';
    const BARCODE_META     = '_swi_spc_barcode';

    public function __construct( $instance_id = 0 ) {
        $this->id                 = 'swi_smartpost_courier';
        $this->instance_id        = absint( $instance_id );
        $this->method_title       = __( 'Smartpost kuller', 'smart-wp-integrations' );
        $this->method_description = __( 'Itella Smartpost kulleriteenus. Seadista Smart WP Integration seadetes.', 'smart-wp-integrations' );
        $this->supports           = [ 'shipping-zones' ];
        $this->title              = get_option( 'swi_spc_title', 'Smartpost kuller' ) ?: 'Smartpost kuller';
        $this->init();
    }

    public function init(): void {
        add_action( 'woocommerce_review_order_after_shipping',        [ $this, 'render_pickup_time' ] );
        add_action( 'woocommerce_after_checkout_validation',          [ $this, 'validate_pickup_time' ] );
        add_action( 'woocommerce_checkout_update_order_meta',         [ $this, 'save_pickup_time' ] );
        add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'save_block_pickup_time' ], 10, 2 );
    }

    public function calculate_shipping( $package = [] ): void {
        if ( get_option( 'swi_spc_enable' ) !== 'yes' ) return;

        $countries    = (array) get_option( 'swi_spc_countries', [ 'EE' ] );
        $dest_country = strtoupper( $package['destination']['country'] ?? 'EE' );
        if ( $dest_country && ! in_array( $dest_country, $countries, true ) ) return;

        $prices      = json_decode( get_option( 'swi_spc_prices', '{}' ), true ) ?: [];
        $cc_prices   = $prices[ $dest_country ] ?? [];
        $cart_weight = WC()->cart ? (float) WC()->cart->get_cart_contents_weight() : 0;

        if ( $cart_weight <= 2 )       $sz = 'xs';
        elseif ( $cart_weight <= 5 )   $sz = 's';
        elseif ( $cart_weight <= 10 )  $sz = 'm';
        elseif ( $cart_weight <= 20 )  $sz = 'l';
        else                           $sz = 'xl';

        $cost = isset( $cc_prices[ $sz ] ) && $cc_prices[ $sz ] !== ''
            ? (float) $cc_prices[ $sz ]
            : 4.99;

        $free_min = isset( $cc_prices['free'] ) && $cc_prices['free'] !== ''
            ? (float) $cc_prices['free']
            : 0;

        if ( $free_min > 0 && ( (float) ( $package['cart_subtotal'] ?? 0 ) ) >= $free_min ) {
            $cost = 0;
        }

        $this->add_rate( [
            'id'    => $this->get_rate_id(),
            'label' => get_option( 'swi_spc_title', 'Smartpost kuller' ) ?: 'Smartpost kuller',
            'cost'  => $cost,
        ] );
    }

    public static function get_pickup_times(): array {
        return [
            '1' => __( 'Igal ajal', 'smart-wp-integrations' ),
            '2' => __( '09:00 – 17:00', 'smart-wp-integrations' ),
            '3' => __( '17:00 – 21:00', 'smart-wp-integrations' ),
        ];
    }

    public function render_pickup_time(): void {
        $chosen = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods', [] ) : [];
        $active = false;
        foreach ( $chosen as $method ) {
            if ( strpos( $method, $this->id ) !== false ) { $active = true; break; }
        }
        if ( ! $active ) return;

        $current = WC()->session ? WC()->session->get( 'swi_spc_pickup_time', '' ) : '';
        $times   = self::get_pickup_times();
        ?>
        <tr class="swi-spc-time-row">
            <th><?php esc_html_e( 'Sobiv kohaletoimetamise aeg', 'smart-wp-integrations' ); ?></th>
            <td>
                <select name="swi_spc_pickup_time" id="swi_spc_pickup_time">
                    <option value=""><?php esc_html_e( '— Vali ajavahemik —', 'smart-wp-integrations' ); ?></option>
                    <?php foreach ( $times as $k => $v ) : ?>
                    <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $current, $k ); ?>><?php echo esc_html( $v ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <?php
    }

    public function validate_pickup_time(): void {
        $chosen = (array) ( $_POST['shipping_method'] ?? [] );
        $using  = false;
        foreach ( $chosen as $method ) {
            if ( strpos( (string) $method, $this->id ) !== false ) { $using = true; break; }
        }
        if ( ! $using ) return;

        $time = sanitize_text_field( wp_unslash( $_POST['swi_spc_pickup_time'] ?? '' ) );
        if ( ! $time ) {
            wc_add_notice( __( 'Palun vali sobiv kohaletoimetamise ajavahemik.', 'smart-wp-integrations' ), 'error' );
        } else {
            if ( WC()->session ) WC()->session->set( 'swi_spc_pickup_time', $time );
        }
    }

    public function save_pickup_time( int $order_id ): void {
        $time = sanitize_text_field( wp_unslash( $_POST['swi_spc_pickup_time'] ?? '' ) );
        if ( $time ) {
            update_post_meta( $order_id, self::PICKUP_TIME_META, $time );
        }
    }

    public function save_block_pickup_time( \WC_Order $order, \WP_REST_Request $request ): void {
        $time = WC()->session ? sanitize_text_field( WC()->session->get( 'swi_spc_pickup_time', '' ) ) : '';
        if ( $time ) {
            $order->update_meta_data( self::PICKUP_TIME_META, $time );
        }
    }
}
