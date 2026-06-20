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

        $locations    = $this->get_locations( $country );
        if ( empty( $locations ) ) return;

        $selected     = WC()->session ? WC()->session->get( 'swi_smartpost_pup_code', '' ) : '';
        $classic_only = get_option( 'swi_smartpost_mobile_classic' ) === 'yes';

        // Grupeeri linna järgi popup jaoks
        $groups = [];
        foreach ( $locations as $loc ) {
            $city = $loc['city'] ?: 'Muu';
            $groups[ $city ][] = $loc;
        }
        ksort( $groups );
        ?>
        <tr class="swi-smartpost-row" style="display:none;" id="swi-smartpost-row-<?php echo esc_attr( $rate->get_id() ); ?>">
            <td colspan="2" style="padding:8px 0 12px 24px;">
                <div class="swi-sp-wrap">
                    <!-- Desktop: tavaline select -->
                    <select name="swi_smartpost_pup_code"
                            id="swi_smartpost_pup_code"
                            class="swi-sp-select<?php echo $classic_only ? ' swi-sp-always' : ''; ?>"
                            style="max-width:420px;width:100%;">
                        <option value="">— Vali pakiautomaat —</option>
                        <?php foreach ( $locations as $loc ) :
                            $pup  = esc_attr( $loc['pupCode'] ?? '' );
                            $name = esc_html( $loc['_display'] ?? $pup );
                        ?>
                        <option value="<?php echo $pup; ?>" <?php selected( $selected, $pup ); ?>><?php echo $name; ?></option>
                        <?php endforeach; ?>
                    </select>

                    <?php if ( ! $classic_only ) : ?>
                    <!-- Mobil: popup nupp + fullscreen modal -->
                    <div class="swi-sp-mobile">
                        <button type="button" class="swi-sp-open-btn">
                            <?php echo $selected
                                ? esc_html( current( array_filter( $locations, fn($l) => $l['pupCode'] === $selected ) )['_display'] ?? 'Vali pakiautomaat' )
                                : 'Vali pakiautomaat'; ?>
                        </button>
                        <div class="swi-sp-popup" style="display:none;">
                            <div class="swi-sp-popup-header">
                                <input type="text" class="swi-sp-search" placeholder="Otsi pakiautomaati…">
                                <button type="button" class="swi-sp-close">&times;</button>
                            </div>
                            <div class="swi-sp-popup-body">
                                <?php foreach ( $groups as $city => $items ) : ?>
                                <div class="swi-sp-group">
                                    <div class="swi-sp-group-title"><?php echo esc_html( $city ); ?></div>
                                    <?php foreach ( $items as $loc ) :
                                        $pup  = esc_attr( $loc['pupCode'] ?? '' );
                                        $name = esc_html( $loc['name'] ?? $pup );
                                    ?>
                                    <div class="swi-sp-item<?php echo $selected === $loc['pupCode'] ? ' selected' : ''; ?>"
                                         data-pup="<?php echo $pup; ?>">
                                        <?php echo $name; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
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

        $classic_only = get_option( 'swi_smartpost_mobile_classic' ) === 'yes';
        $locations    = $this->get_locations( 'EE' );
        // Grupeeri linna järgi block checkout popup jaoks
        $groups = [];
        foreach ( $locations as $loc ) {
            $city = $loc['city'] ?: 'Muu';
            $groups[ $city ][] = $loc;
        }
        ksort( $groups );
        $loc_json    = wp_json_encode( $locations );
        $groups_json = wp_json_encode( $groups );
        ?>
        <style>
        /* Smartpost popup */
        @media (max-width: 768px) {
            .swi-sp-select:not(.swi-sp-always) { display: none !important; }
            .swi-sp-mobile { display: block; }
        }
        @media (min-width: 769px) {
            .swi-sp-mobile { display: none !important; }
            .swi-sp-select { display: block; }
        }
        .swi-sp-mobile { display: none; }
        .swi-sp-open-btn {
            width: 100%; max-width: 420px; padding: 9px 36px 9px 12px; text-align: left;
            border: 1px solid #ccc; border-radius: 4px; background: #fff; font-size: 14px;
            cursor: pointer; position: relative;
        }
        .swi-sp-open-btn::after {
            content: '▾'; position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
        }
        .swi-sp-popup {
            position: fixed; inset: 0; z-index: 99999; background: #fff;
            display: flex; flex-direction: column;
        }
        .swi-sp-popup-header {
            display: flex; align-items: center; gap: 8px;
            padding: 10px 12px; border-bottom: 1px solid #e5e7eb; flex-shrink: 0;
        }
        .swi-sp-search {
            flex: 1; padding: 8px 12px; border: 1px solid #d1d5db;
            border-radius: 4px; font-size: 15px;
        }
        .swi-sp-close {
            font-size: 24px; background: none; border: none;
            cursor: pointer; color: #374151; padding: 0 6px; line-height: 1;
        }
        .swi-sp-popup-body { overflow-y: auto; flex: 1; padding-bottom: 20px; }
        .swi-sp-group-title {
            font-weight: 700; font-size: 13px; padding: 10px 14px 4px;
            color: #6b7280; text-transform: uppercase; letter-spacing: .04em;
        }
        .swi-sp-item {
            padding: 12px 14px; font-size: 15px; border-bottom: 1px solid #f3f4f6;
            cursor: pointer;
        }
        .swi-sp-item:active, .swi-sp-item.selected { background: #eff6ff; color: #1d4ed8; }
        </style>
        <script>
        (function(){
            var SWI_LOCATIONS = <?php echo $loc_json; ?>;
            var SWI_GROUPS    = <?php echo $groups_json; ?>;
            var SWI_CLASSIC   = <?php echo $classic_only ? 'true' : 'false'; ?>;
            var AJAX_URL      = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

            function swiSavePup(code, name) {
                var fd = new FormData();
                fd.append('action', 'swi_sp_set_pup');
                fd.append('pup_code', code);
                fd.append('location_name', name);
                fetch(AJAX_URL, { method: 'POST', body: fd, credentials: 'same-origin' });
            }

            /* ── Popup avamine / sulgemine (kasutatakse nii classic kui block) ── */
            function swiOpenPopup(popup) {
                popup.style.display = 'flex';
                popup.querySelector('.swi-sp-search').value = '';
                popup.querySelectorAll('.swi-sp-group, .swi-sp-item').forEach(function(el){ el.style.display = ''; });
                popup.querySelector('.swi-sp-search').focus();
            }

            function swiBindPopup(wrap, onSelect) {
                var btn    = wrap.querySelector('.swi-sp-open-btn');
                var popup  = wrap.querySelector('.swi-sp-popup');
                var search = wrap.querySelector('.swi-sp-search');
                var close  = wrap.querySelector('.swi-sp-close');
                if (!btn || !popup) return;

                btn.addEventListener('click', function(){ swiOpenPopup(popup); });
                close.addEventListener('click', function(){ popup.style.display = 'none'; });

                search.addEventListener('input', function(){
                    var q = search.value.toLowerCase();
                    wrap.querySelectorAll('.swi-sp-group').forEach(function(grp){
                        var visible = 0;
                        grp.querySelectorAll('.swi-sp-item').forEach(function(item){
                            var match = !q || item.textContent.toLowerCase().indexOf(q) !== -1;
                            item.style.display = match ? '' : 'none';
                            if (match) visible++;
                        });
                        grp.style.display = visible ? '' : 'none';
                    });
                });

                wrap.querySelectorAll('.swi-sp-item').forEach(function(item){
                    item.addEventListener('click', function(){
                        var code = item.dataset.pup;
                        var name = item.textContent.trim();
                        wrap.querySelectorAll('.swi-sp-item').forEach(function(i){ i.classList.remove('selected'); });
                        item.classList.add('selected');
                        btn.textContent = name;
                        popup.style.display = 'none';
                        onSelect(code, name);
                    });
                });
            }

            function buildPopupHTML() {
                var html = '<div class="swi-sp-mobile">'
                    + '<button type="button" class="swi-sp-open-btn">— Vali pakiautomaat —</button>'
                    + '<div class="swi-sp-popup" style="display:none;">'
                    + '<div class="swi-sp-popup-header">'
                    + '<input type="text" class="swi-sp-search" placeholder="Otsi pakiautomaati…">'
                    + '<button type="button" class="swi-sp-close">&times;</button>'
                    + '</div><div class="swi-sp-popup-body">';
                Object.keys(SWI_GROUPS).sort().forEach(function(city){
                    html += '<div class="swi-sp-group"><div class="swi-sp-group-title">' + city + '</div>';
                    SWI_GROUPS[city].forEach(function(loc){
                        html += '<div class="swi-sp-item" data-pup="' + loc.pupCode + '">' + (loc.name || loc.pupCode) + '</div>';
                    });
                    html += '</div>';
                });
                html += '</div></div></div>';
                return html;
            }

            /* ── Block checkout ── */
            if (document.querySelector('.wp-block-woocommerce-checkout, .wc-block-checkout')) {
                var injected = false;

                function buildBlockWrap() {
                    var wrap = document.createElement('div');
                    wrap.id = 'swi-sp-block-wrap';
                    wrap.style.cssText = 'margin:10px 0 4px;padding:10px;background:#f9f9f9;border:1px solid #e5e7eb;border-radius:4px;';

                    var lbl = document.createElement('label');
                    lbl.textContent = 'Vali pakiautomaat';
                    lbl.style.cssText = 'display:block;font-weight:600;margin-bottom:6px;font-size:13px;';
                    wrap.appendChild(lbl);

                    // Select (desktop / klassikaline)
                    var sel = document.createElement('select');
                    sel.id = 'swi-sp-block-sel';
                    sel.className = 'swi-sp-select' + (SWI_CLASSIC ? ' swi-sp-always' : '');
                    sel.style.cssText = 'width:100%;max-width:420px;padding:6px 8px;border:1px solid #ccc;border-radius:3px;font-size:13px;';
                    var defOpt = document.createElement('option');
                    defOpt.value = ''; defOpt.textContent = '— Vali pakiautomaat —';
                    sel.appendChild(defOpt);
                    SWI_LOCATIONS.forEach(function(loc){
                        var opt = document.createElement('option');
                        opt.value = loc.pupCode;
                        opt.textContent = loc._display || loc.name || loc.pupCode;
                        sel.appendChild(opt);
                    });
                    sel.addEventListener('change', function(){
                        var name = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : '';
                        swiSavePup(sel.value, name);
                    });
                    wrap.appendChild(sel);

                    // Popup (mobil)
                    if (!SWI_CLASSIC) {
                        wrap.insertAdjacentHTML('beforeend', buildPopupHTML());
                        swiBindPopup(wrap, function(code, name){
                            sel.value = code;
                            swiSavePup(code, name);
                        });
                    }
                    return wrap;
                }

                function tryInject() {
                    var inp  = document.querySelector('input[type="radio"][value*="swi_smartpost"]');
                    if (!inp) return;
                    var wrap = document.getElementById('swi-sp-block-wrap');
                    if (!injected) {
                        var li = inp.closest('li') || inp.closest('.wc-block-components-radio-control__option') || inp.parentElement;
                        li.appendChild(buildBlockWrap());
                        injected = true;
                        wrap = document.getElementById('swi-sp-block-wrap');
                    }
                    if (wrap) wrap.style.display = (inp && inp.checked) ? 'block' : 'none';
                }

                var obs = new MutationObserver(function(){ tryInject(); });
                obs.observe(document.body, { childList: true, subtree: true });
                tryInject();
                document.addEventListener('change', function(e){
                    if (e.target && e.target.name && e.target.name.indexOf('radio-control') !== -1) setTimeout(tryInject, 50);
                });
                return;
            }

            /* ── Classic checkout ── */
            if (typeof jQuery === 'undefined') return;
            jQuery(function($){
                function swiToggle(){
                    var chosen = $('input[name^="shipping_method"]:checked').val() || '';
                    $('.swi-smartpost-row').toggle(chosen.indexOf('swi_smartpost') !== -1);
                }
                $(document).on('change', 'input[name^="shipping_method"]', swiToggle);
                $(document.body).on('updated_checkout', swiToggle);
                swiToggle();

                // Select muutus → salvesta hidden field
                $(document).on('change', '#swi_smartpost_pup_code', function(){
                    var name = $(this).find('option:selected').text().trim();
                    $('input[name="swi_smartpost_location_name"]').remove();
                    $('<input type="hidden" name="swi_smartpost_location_name">').val(name).appendTo('form.checkout');
                });

                // Popup binding (lisab automaatselt popup elemendile)
                if (!SWI_CLASSIC && document.querySelector('.swi-sp-mobile')) {
                    swiBindPopup(document.querySelector('.swi-sp-wrap'), function(code, name){
                        $('#swi_smartpost_pup_code').val(code).trigger('change');
                    });
                }
            });
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
