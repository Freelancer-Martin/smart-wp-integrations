<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SWI_Omniva_Shipping extends WC_Shipping_Method {

    const BARCODE_META = '_swi_omniva_barcode';

    public function __construct( $instance_id = 0 ) {
        $this->id                 = 'swi_omniva';
        $this->instance_id        = absint( $instance_id );
        $this->method_title       = __( 'Omniva kuller', 'smart-wp-integrations' );
        $this->method_description = __( 'Omniva kullerteenus. Seadista Smart WP Integration seadetes.', 'smart-wp-integrations' );
        $this->supports           = [ 'shipping-zones' ];
        $this->title              = get_option( 'swi_omniva_title', 'Omniva kuller' ) ?: 'Omniva kuller';
        $this->init();
    }

    public function init(): void {}

    public function calculate_shipping( $package = [] ): void {
        if ( get_option( 'swi_omniva_enable' ) !== 'yes' ) return;

        $dest   = strtoupper( $package['destination']['country'] ?? '' );
        $prices = json_decode( get_option( 'swi_omniva_prices', '{}' ), true ) ?: [];

        $row = null;
        if ( $dest && isset( $prices[ $dest ] ) && ( $prices[ $dest ]['enabled'] ?? 'no' ) === 'yes' ) {
            $row = $prices[ $dest ];
        } elseif ( isset( $prices['DEFAULT'] ) && ( $prices['DEFAULT']['enabled'] ?? 'no' ) === 'yes' ) {
            $row = $prices['DEFAULT'];
        }
        if ( ! $row ) return;

        $cart_weight = WC()->cart ? (float) WC()->cart->get_cart_contents_weight() : 0;

        if ( $cart_weight <= 2 )      $sz = 'xs';
        elseif ( $cart_weight <= 5 )  $sz = 's';
        elseif ( $cart_weight <= 10 ) $sz = 'm';
        elseif ( $cart_weight <= 20 ) $sz = 'l';
        else                          $sz = 'xl';

        $cost     = isset( $row[ $sz ] ) && $row[ $sz ] !== '' ? (float) $row[ $sz ] : 9.99;
        $free_min = isset( $row['free'] ) && $row['free'] !== '' ? (float) $row['free'] : 0;

        if ( $free_min > 0 && ( (float) ( $package['cart_subtotal'] ?? 0 ) ) >= $free_min ) {
            $cost = 0;
        }

        $this->add_rate( [
            'id'    => $this->get_rate_id(),
            'label' => get_option( 'swi_omniva_title', 'Omniva kuller' ) ?: 'Omniva kuller',
            'cost'  => $cost,
        ] );
    }
}
