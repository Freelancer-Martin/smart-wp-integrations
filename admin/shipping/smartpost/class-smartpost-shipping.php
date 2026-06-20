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
        $this->method_description = __( 'Itella Smartpost pakiautomaadid. Seadista Smart WP Integration seadetes.', 'smart-wp-integrations' );
        $this->supports           = [ 'shipping-zones' ];
        $this->title              = get_option( 'swi_smartpost_title', 'Smartpost pakiautomaat' ) ?: 'Smartpost pakiautomaat';

        $this->init();
    }

    public function init(): void {
        add_action( 'woocommerce_after_shipping_rate',        [ $this, 'render_parcel_select' ], 10, 2 );
        add_action( 'woocommerce_checkout_process',           [ $this, 'validate_parcel_selection' ] );
        add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'save_parcel_selection' ] );
        add_action( 'wp_footer',                              [ $this, 'enqueue_scripts' ] );
        // Block checkout salvestamine
        add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'save_block_parcel_selection' ], 10, 2 );
    }

    public function calculate_shipping( $package = [] ): void {
        if ( get_option( 'swi_smartpost_enable' ) !== 'yes' ) return;

        $countries    = (array) get_option( 'swi_smartpost_countries', [ 'EE' ] );
        $dest_country = $package['destination']['country'] ?? '';
        if ( $dest_country && ! in_array( $dest_country, $countries, true ) ) return;

        $cost      = (float) get_option( 'swi_smartpost_cost', '3.99' );
        $free_min  = (float) get_option( 'swi_smartpost_free_min', '' );
        $cart_subtotal = $package['cart_subtotal'] ?? 0;

        if ( $free_min > 0 && $cart_subtotal >= $free_min ) {
            $cost = 0;
        }

        $this->add_rate( [
            'id'    => $this->get_rate_id(),
            'label' => get_option( 'swi_smartpost_title', 'Smartpost pakiautomaat' ) ?: 'Smartpost pakiautomaat',
            'cost'  => $cost,
        ] );
    }

    /* ─── Parcel machine dropdown after shipping rate ─── */

    public function render_parcel_select( WC_Shipping_Rate $rate, int $index ): void {
        if ( $rate->get_method_id() !== $this->id ) return;

        $countries = (array) get_option( 'swi_smartpost_countries', [ 'EE' ] );
        $country   = WC()->customer ? WC()->customer->get_shipping_country() : 'EE';
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
            update_post_meta( $order_id, '_swi_smartpost_pup_code',      $pup );
            update_post_meta( $order_id, '_swi_smartpost_location_name', $name );
        }
    }

    public function save_block_parcel_selection( \WC_Order $order, \WP_REST_Request $request ): void {
        $extensions = $request->get_param( 'extensions' );
        $pup  = sanitize_text_field( $extensions['swi_smartpost']['pup_code']      ?? '' );
        $name = sanitize_text_field( $extensions['swi_smartpost']['location_name'] ?? '' );
        if ( $pup ) {
            $order->update_meta_data( '_swi_smartpost_pup_code',      $pup );
            $order->update_meta_data( '_swi_smartpost_location_name', $name );
        }
    }

    public function enqueue_scripts(): void {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;

        // Laadi asukohad EE jaoks (block checkout vajab neid JS-is JSON-ina)
        $locations = $this->get_locations( 'EE' );
        $loc_json  = wp_json_encode( $locations );
        ?>
        <script>
        (function(){
            var SWI_LOCATIONS = <?php echo $loc_json; ?>;

            /* ── Block checkout ── */
            var blockRoot = document.querySelector('.wp-block-woocommerce-checkout, .wc-block-checkout');
            if (blockRoot) {
                swiInitBlock();
                return;
            }

            /* ── Classic checkout ── */
            if (typeof jQuery === 'undefined') return;
            jQuery(function($){
                function swiToggleSelect(){
                    var chosen = $('input[name^="shipping_method"]:checked').val() || '';
                    if (chosen.indexOf('swi_smartpost') !== -1) {
                        $('.swi-smartpost-row').show();
                    } else {
                        $('.swi-smartpost-row').hide();
                    }
                }
                $(document).on('change','input[name^="shipping_method"]', swiToggleSelect);
                $(document.body).on('updated_checkout', swiToggleSelect);
                swiToggleSelect();

                $(document).on('change','#swi_smartpost_pup_code', function(){
                    var name = $(this).find('option:selected').text().trim();
                    $('input[name="swi_smartpost_location_name"]').remove();
                    $('<input type="hidden" name="swi_smartpost_location_name">').val(name).appendTo('form.checkout');
                });
            });

            /* ── Block checkout init ── */
            function swiInitBlock() {
                var injected = false;

                function buildSelect() {
                    var wrap = document.createElement('div');
                    wrap.id = 'swi-sp-block-wrap';
                    wrap.style.cssText = 'margin:10px 0 4px;padding:10px;background:#f9f9f9;border:1px solid #e5e7eb;border-radius:4px;';

                    var label = document.createElement('label');
                    label.htmlFor = 'swi-sp-block-sel';
                    label.textContent = 'Vali pakiautomaat';
                    label.style.cssText = 'display:block;font-weight:600;margin-bottom:6px;font-size:13px;';

                    var sel = document.createElement('select');
                    sel.id = 'swi-sp-block-sel';
                    sel.style.cssText = 'width:100%;max-width:420px;padding:6px 8px;border:1px solid #ccc;border-radius:3px;font-size:13px;';

                    var def = document.createElement('option');
                    def.value = ''; def.textContent = '— Vali pakiautomaat —';
                    sel.appendChild(def);

                    SWI_LOCATIONS.forEach(function(loc){
                        var opt = document.createElement('option');
                        opt.value = loc.pupCode;
                        opt.textContent = loc._display || loc.name || loc.pupCode;
                        sel.appendChild(opt);
                    });

                    sel.addEventListener('change', function(){
                        var code = sel.value;
                        var name = sel.options[sel.selectedIndex]?.text || '';
                        // Salvesta WC blocks store kaudu
                        if (window.wp && window.wp.data) {
                            try {
                                window.wp.data.dispatch('wc/store/checkout').__internalSetExtensionData('swi_smartpost', { pup_code: code, location_name: name }, true);
                            } catch(e) {
                                // Vanem WC API
                                try { window.wp.data.dispatch('wc/store/checkout').setExtensionData('swi_smartpost', { pup_code: code, location_name: name }); } catch(e2){}
                            }
                        }
                    });

                    wrap.appendChild(label);
                    wrap.appendChild(sel);
                    return wrap;
                }

                function tryInject() {
                    if (injected) {
                        // Uuenda nähtavust
                        var wrap = document.getElementById('swi-sp-block-wrap');
                        var isSmartpost = isSmartpostSelected();
                        if (wrap) wrap.style.display = isSmartpost ? 'block' : 'none';
                        return;
                    }

                    // Leia Smartpost saatmisviisi label
                    var labels = document.querySelectorAll('.wc-block-components-radio-control__option-layout, .wc-block-components-shipping-rates-control__package .wc-block-components-radio-control__label');
                    var targetEl = null;
                    labels.forEach(function(el){
                        if (el.textContent && el.textContent.toLowerCase().indexOf('smartpost') !== -1) {
                            targetEl = el.closest('.wc-block-components-radio-control__option') || el.parentElement;
                        }
                    });

                    if (!targetEl) return;

                    var sel = buildSelect();
                    sel.style.display = isSmartpostSelected() ? 'block' : 'none';
                    targetEl.appendChild(sel);
                    injected = true;
                }

                function isSmartpostSelected() {
                    var inputs = document.querySelectorAll('input[type="radio"][id*="shipping"]');
                    var found = false;
                    inputs.forEach(function(inp){
                        if (inp.checked && inp.value && inp.value.indexOf('swi_smartpost') !== -1) found = true;
                        // WC blocks kasutab label tekstist
                        var label = document.querySelector('label[for="' + inp.id + '"]');
                        if (inp.checked && label && label.textContent.toLowerCase().indexOf('smartpost') !== -1) found = true;
                    });
                    return found;
                }

                // MutationObserver — vaata DOM muutusi
                var obs = new MutationObserver(function(){ tryInject(); });
                obs.observe(document.body, { childList: true, subtree: true });
                tryInject();

                // Kliki kuulaja saatmisviisi valikul
                document.addEventListener('change', function(e){
                    if (e.target && e.target.name && e.target.name.indexOf('radio-control') !== -1) {
                        setTimeout(tryInject, 50);
                    }
                });
            }
        })();
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
