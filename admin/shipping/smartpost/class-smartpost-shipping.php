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
        add_action( 'wp_ajax_swi_sp_set_pup',                [ $this, 'ajax_set_pup' ] );
        add_action( 'wp_ajax_nopriv_swi_sp_set_pup',         [ $this, 'ajax_set_pup' ] );
        add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'save_block_parcel_selection' ], 10, 2 );
    }

    public function calculate_shipping( $package = [] ): void {
        if ( get_option( 'swi_smartpost_enable' ) !== 'yes' ) return;

        $countries    = (array) get_option( 'swi_smartpost_countries', [ 'EE' ] );
        $dest_country = strtoupper( $package['destination']['country'] ?? 'EE' );
        if ( $dest_country && ! in_array( $dest_country, $countries, true ) ) return;

        // Proovi per-riik hinda; langeta vana swi_smartpost_cost peale
        $prices    = json_decode( get_option( 'swi_smartpost_prices', '{}' ), true ) ?: [];
        $cc_prices = $prices[ $dest_country ] ?? [];
        $weight    = (float) ( $package['contents_cost'] ?? 0 ); // kasuta kaalu kui saadaval
        $cart_weight = WC()->cart ? (float) WC()->cart->get_cart_contents_weight() : 0;

        // Määra suurus kaalu järgi
        if ( $cart_weight <= 2 )       $sz = 'xs';
        elseif ( $cart_weight <= 5 )   $sz = 's';
        elseif ( $cart_weight <= 10 )  $sz = 'm';
        elseif ( $cart_weight <= 20 )  $sz = 'l';
        else                           $sz = 'xl';

        $cost = isset( $cc_prices[ $sz ] ) && $cc_prices[ $sz ] !== ''
            ? (float) $cc_prices[ $sz ]
            : (float) get_option( 'swi_smartpost_cost', '3.99' );

        $free_min = isset( $cc_prices['free'] ) && $cc_prices['free'] !== ''
            ? (float) $cc_prices['free']
            : (float) get_option( 'swi_smartpost_free_min', '' );

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

    public function ajax_set_pup(): void {
        $pup  = sanitize_text_field( wp_unslash( $_POST['pup_code']       ?? '' ) );
        $name = sanitize_text_field( wp_unslash( $_POST['location_name']  ?? '' ) );
        if ( WC()->session ) {
            WC()->session->set( 'swi_smartpost_pup_code',      $pup );
            WC()->session->set( 'swi_smartpost_location_name', $name );
        }
        wp_send_json_success();
    }

    public function save_block_parcel_selection( \WC_Order $order, \WP_REST_Request $request ): void {
        $pup  = WC()->session ? sanitize_text_field( WC()->session->get( 'swi_smartpost_pup_code', '' ) ) : '';
        $name = WC()->session ? sanitize_text_field( WC()->session->get( 'swi_smartpost_location_name', '' ) ) : '';
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
                        var name = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : '';
                        // Salvesta WC sessiooni AJAX kaudu
                        var fd = new FormData();
                        fd.append('action', 'swi_sp_set_pup');
                        fd.append('pup_code', code);
                        fd.append('location_name', name);
                        fetch(typeof swi_ajax !== 'undefined' ? swi_ajax.url : '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', { method: 'POST', body: fd, credentials: 'same-origin' });
                    });

                    wrap.appendChild(label);
                    wrap.appendChild(sel);
                    return wrap;
                }

                function getSmartpostInput() {
                    return document.querySelector('input[type="radio"][value*="swi_smartpost"]');
                }

                function isSmartpostSelected() {
                    var inp = getSmartpostInput();
                    return inp && inp.checked;
                }

                function tryInject() {
                    var inp = getSmartpostInput();
                    if (!inp) return; // saatmisviis pole veel renderdatud

                    var wrap = document.getElementById('swi-sp-block-wrap');

                    if (!injected) {
                        // Leia <li> element mis sisaldab Smartpost radiobuttonit
                        var listItem = inp.closest('li') || inp.closest('.wc-block-components-radio-control__option') || inp.parentElement;
                        var select = buildSelect();
                        listItem.appendChild(select);
                        injected = true;
                        wrap = document.getElementById('swi-sp-block-wrap');
                    }

                    if (wrap) wrap.style.display = isSmartpostSelected() ? 'block' : 'none';
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
        foreach ( ['ee', 'fi', 'lv', 'lt', 'se'] as $cc ) {
            delete_transient( self::CACHE_KEY . $cc );
        }
    }
}
