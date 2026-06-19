<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SWI_Smartpost_Shipping extends WC_Shipping_Method {

    const ITELLA_API    = 'https://delivery.plugins.itella.com/api/locations';
    const CACHE_KEY     = 'swi_smartpost_locations_';
    const CACHE_TTL     = 43200; // 12h
    const ALLOWED_TYPES = [ 'SMARTPOST', 'LOCKER' ];

    public function __construct( $instance_id = 0 ) {
        $this->id                 = 'swi_smartpost';
        $this->instance_id        = absint( $instance_id );
        $this->method_title       = __( 'Smartpost pakiautomaat', 'smart-wp-integrations' );
        $this->method_description = __( 'Itella Smartpost pakiautomaadid Eestis, Lätis ja Leedus.', 'smart-wp-integrations' );
        $this->supports           = [ 'shipping-zones', 'instance-settings' ];
        $this->title              = $this->get_option( 'title', 'Smartpost pakiautomaat' );

        $this->init();
    }

    public function init(): void {
        $this->init_form_fields();
        $this->init_settings();
        $this->title = $this->get_option( 'title', 'Smartpost pakiautomaat' );
        add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
        add_action( 'woocommerce_after_shipping_rate',                   [ $this, 'render_parcel_select' ], 10, 2 );
        add_action( 'woocommerce_checkout_process',                      [ $this, 'validate_parcel_selection' ] );
        add_action( 'woocommerce_checkout_update_order_meta',            [ $this, 'save_parcel_selection' ] );
        add_action( 'wp_enqueue_scripts',                                [ $this, 'enqueue_scripts' ] );
    }

    public function init_form_fields(): void {
        $this->instance_form_fields = [
            'title' => [
                'title'   => __( 'Pealkiri', 'smart-wp-integrations' ),
                'type'    => 'text',
                'default' => 'Smartpost pakiautomaat',
            ],
            'cost' => [
                'title'       => __( 'Hind (€)', 'smart-wp-integrations' ),
                'type'        => 'price',
                'default'     => '3.99',
                'description' => __( 'Kohaletoimetamise hind. Sisesta 0 tasuta saatmiseks.', 'smart-wp-integrations' ),
            ],
            'free_min_amount' => [
                'title'       => __( 'Tasuta saatmise lävi (€)', 'smart-wp-integrations' ),
                'type'        => 'price',
                'default'     => '',
                'description' => __( 'Jäta tühjaks kui ei kasuta. Tellimused üle selle summa saavad tasuta saatmise.', 'smart-wp-integrations' ),
            ],
            'countries' => [
                'title'   => __( 'Riigid', 'smart-wp-integrations' ),
                'type'    => 'multiselect',
                'options' => [ 'EE' => 'Eesti', 'LV' => 'Läti', 'LT' => 'Leedu' ],
                'default' => [ 'EE' ],
                'class'   => 'chosen_select',
            ],
        ];
    }

    public function calculate_shipping( $package = [] ): void {
        $cost            = (float) $this->get_option( 'cost', 3.99 );
        $free_min_amount = (float) $this->get_option( 'free_min_amount', 0 );

        if ( $free_min_amount > 0 && $package['cart_subtotal'] >= $free_min_amount ) {
            $cost = 0;
        }

        $this->add_rate( [
            'id'    => $this->get_rate_id(),
            'label' => $this->title,
            'cost'  => $cost,
        ] );
    }

    /* ─── Parcel machine dropdown after shipping rate ─── */

    public function render_parcel_select( WC_Shipping_Rate $rate, int $index ): void {
        if ( $rate->get_method_id() !== $this->id ) return;

        $country   = WC()->customer ? WC()->customer->get_shipping_country() : 'EE';
        $countries = (array) $this->get_option( 'countries', [ 'EE' ] );
        if ( ! in_array( $country, $countries, true ) ) {
            $country = $countries[0] ?? 'EE';
        }

        $locations = $this->get_locations( $country );
        if ( empty( $locations ) ) return;

        $selected = WC()->session ? WC()->session->get( 'swi_smartpost_pup_code', '' ) : '';
        ?>
        <tr class="swi-smartpost-row" style="display:none;" id="swi-smartpost-row-<?php echo esc_attr( $rate->get_id() ); ?>">
            <td colspan="2" style="padding:8px 0 12px 24px;">
                <select name="swi_smartpost_pup_code"
                        id="swi_smartpost_pup_code"
                        class="swi-smartpost-select"
                        style="max-width:420px;width:100%;">
                    <option value="">— Vali pakiautomaat —</option>
                    <?php foreach ( $locations as $loc ) :
                        $pup  = esc_attr( $loc['pupCode'] ?? '' );
                        $name = esc_html( $loc['_display'] ?? $pup );
                    ?>
                    <option value="<?php echo $pup; ?>" <?php selected( $selected, $pup ); ?>>
                        <?php echo $name; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">
                    <?php esc_html_e( 'Vali soovitud pakiautomaat', 'smart-wp-integrations' ); ?>
                </p>
            </td>
        </tr>
        <?php
    }

    public function validate_parcel_selection(): void {
        $chosen = WC()->session ? WC()->session->get( 'chosen_shipping_methods', [] ) : [];
        $using  = false;
        foreach ( $chosen as $method ) {
            if ( strpos( $method, $this->id ) !== false ) { $using = true; break; }
        }
        if ( ! $using ) return;

        $pup = sanitize_text_field( wp_unslash( $_POST['swi_smartpost_pup_code'] ?? '' ) );
        if ( ! $pup ) {
            wc_add_notice( __( 'Palun vali Smartpost pakiautomaat.', 'smart-wp-integrations' ), 'error' );
        } else {
            if ( WC()->session ) WC()->session->set( 'swi_smartpost_pup_code', $pup );
        }
    }

    public function save_parcel_selection( int $order_id ): void {
        $pup  = sanitize_text_field( wp_unslash( $_POST['swi_smartpost_pup_code'] ?? '' ) );
        $name = sanitize_text_field( wp_unslash( $_POST['swi_smartpost_location_name'] ?? '' ) );
        if ( $pup ) {
            update_post_meta( $order_id, '_swi_smartpost_pup_code',       $pup );
            update_post_meta( $order_id, '_swi_smartpost_location_name',  $name );
        }
    }

    public function enqueue_scripts(): void {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
        ?>
        <script>
        jQuery(function($){
            function swiToggleSelect(){
                var chosen = $('input[name^="shipping_method"]:checked').val() || '';
                if(chosen.indexOf('swi_smartpost') !== -1){
                    $('.swi-smartpost-row').show();
                } else {
                    $('.swi-smartpost-row').hide();
                }
            }
            $(document).on('change','input[name^="shipping_method"]', swiToggleSelect);
            $(document.body).on('updated_checkout', swiToggleSelect);
            swiToggleSelect();

            // Save location name as hidden field for order meta
            $(document).on('change','#swi_smartpost_pup_code', function(){
                var name = $(this).find('option:selected').text().trim();
                $('input[name="swi_smartpost_location_name"]').remove();
                $('<input type="hidden" name="swi_smartpost_location_name">').val(name).appendTo('form.checkout');
            });
        });
        </script>
        <?php
    }

    /* ─── Itella API ─── */

    public function get_locations( string $country ): array {
        $cache_key = self::CACHE_KEY . strtolower( $country );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return $cached;

        $types = implode( '&types=', self::ALLOWED_TYPES );
        $url   = self::ITELLA_API . '?countryCode=' . urlencode( $country ) . '&types=' . $types;

        $resp = wp_remote_get( $url, [ 'timeout' => 15, 'headers' => [ 'Accept' => 'application/json' ] ] );
        if ( is_wp_error( $resp ) ) return [];

        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        $raw  = $body['locations'] ?? [];
        if ( empty( $raw ) ) return [];

        $locations = [];
        foreach ( $raw as $loc ) {
            $pupCode = $loc['pupCode'] ?? '';
            if ( ! $pupCode ) continue;

            $name = $loc['publicName']['et']
                 ?? $loc['publicName']['en']
                 ?? $loc['locationName']['et']
                 ?? $loc['locationName']['en']
                 ?? $pupCode;

            $city = $loc['address']['et']['municipality']
                 ?? $loc['address']['en']['municipality']
                 ?? '';

            $locations[] = [
                'pupCode'  => $pupCode,
                'name'     => $name,
                'city'     => $city,
                '_display' => $city ? $city . ' – ' . $name : $name,
            ];
        }

        usort( $locations, fn( $a, $b ) => strcmp( $a['_display'], $b['_display'] ) );

        set_transient( $cache_key, $locations, self::CACHE_TTL );
        return $locations;
    }

    public static function flush_cache(): void {
        delete_transient( self::CACHE_KEY . 'ee' );
        delete_transient( self::CACHE_KEY . 'lv' );
        delete_transient( self::CACHE_KEY . 'lt' );
    }
}
