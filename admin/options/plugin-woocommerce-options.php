<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_filter( 'woocommerce_get_settings_pages', function( $settings ) {
    if ( ! class_exists( 'WC_Settings_Page' ) ) return $settings;

    class WC_Settings_Smart_WP_Integration extends WC_Settings_Page {

        private $opt_payment_map  = 'smart_wp_integtaion_payment_map';
        private $opt_shipping_map = 'smart_wp_integtaion_shipping_map';
        private $opt_tax_map      = 'smart_wp_integtaion_tax_map';
        private $opt_country_map  = 'smart_wp_integtaion_country_map';
        private $proxy_error      = null;

        public function __construct() {
            $this->id    = 'smart_wp_integration';
            $this->label = __( 'Smart WP', 'smart-wp-integration' );
            parent::__construct();

            add_action( 'woocommerce_admin_field_swi_country_map',  [ $this, 'field_country_map' ] );
            add_action( 'woocommerce_admin_field_swi_payment_map',  [ $this, 'field_payment_map' ] );
            add_action( 'woocommerce_admin_field_swi_tax_map',      [ $this, 'field_tax_map' ] );
            add_action( 'woocommerce_admin_field_swi_shipping_map', [ $this, 'field_shipping_map' ] );
            add_action( 'woocommerce_update_options', [ $this, 'save' ] );
        }

        /* ─── SETTINGS ARRAYS (salvestamine) ─── */
        public function get_settings( $section = '' ): array {
            $statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
            return array_merge(
                $this->settings_connection(),
                $this->settings_merit( $statuses ),
                $this->settings_simplebooks( $statuses ),
                $this->settings_smartaccounts( $statuses ),
                [
                    [ 'type' => 'swi_country_map',  'id' => $this->opt_country_map ],
                    [ 'type' => 'swi_payment_map',  'id' => $this->opt_payment_map ],
                    [ 'type' => 'swi_tax_map',      'id' => $this->opt_tax_map ],
                    [ 'type' => 'swi_shipping_map', 'id' => $this->opt_shipping_map ],
                ]
            );
        }

        private function settings_connection(): array {
            return [
                [ 'type' => 'title', 'id' => 'swi_conn_section' ],
                [ 'name' => 'Vaheserveri URL',    'type' => 'text', 'id' => 'smart_wp_integration_server_url',  'default' => '', 'desc' => 'nt https://sinudomeen.ee' ],
                [ 'name' => 'Litsentsi võti',     'type' => 'text', 'id' => 'smart_wp_integtaion_license_text', 'default' => '', 'desc' => 'Kopeeri litsentsi lehelt' ],
                [ 'name' => 'Krüptovõti (HEX)',   'type' => 'text', 'id' => 'smart_wp_integtaion_crypto_text',  'default' => '', 'desc' => '64-märgiline HEX' ],
                [ 'type' => 'sectionend', 'id' => 'swi_conn_section' ],
            ];
        }

        private function settings_merit( array $statuses ): array {
            $client = new MeritServersDataClient();
            $departments = [];
            try { $departments = $client->getDepartments() ?? []; } catch (\Exception $e) {}

            $dept_opts = [ '' => '— vali osakond —' ];
            foreach ( (array) $departments as $code ) { $dept_opts[$code] = $code; }

            return [
                [ 'type' => 'title', 'id' => 'swi_merit_section' ],
                [ 'name' => 'Luba Merit Aktiva',          'type' => 'checkbox', 'id' => 'smart_wp_integtaion_enable',           'default' => 'no' ],
                [ 'name' => 'Arve eesliides',             'type' => 'text',     'id' => 'smart_wp_integtaion_arve_eesliides',    'default' => 'WP' ],
                [ 'name' => 'Maksetähtaeg (päevades)',    'type' => 'text',     'id' => 'smart_wp_integtaion_maksetahtaeg',      'default' => '14' ],
                [ 'name' => 'Saada tellimused staatuses', 'type' => 'select',   'id' => 'smart_wp_integtaion_invoice_status',    'options' => $statuses, 'default' => 'wc-completed' ],
                [ 'name' => 'Käibemaksumäär',            'type' => 'select',   'id' => 'smart_wp_integtaion_maksumaar',         'options' => [
                    '973a4395-665f-47a6-a5b6-5384dd24f8d0' => '0%',
                    '6b618baa-680b-4606-9ad9-eff0beb27344' => '9%',
                    'fd050f9b-f376-40fb-aee5-af2d1f04970f' => '13%',
                    'b9b25735-6a15-4d4e-8720-25b254ae3d21' => '20%',
                    '307000b4-f1f2-4bc7-a110-24cb18d77212' => '22%',
                    '1e420e04-3dd7-46a5-b71f-0490779c2638' => '24%',
                ], 'default' => '1e420e04-3dd7-46a5-b71f-0490779c2638' ],
                [ 'name' => 'Arve ridade tüüp', 'type' => 'select', 'id' => 'smart_wp_integtaion_arve_ridade_tyyp', 'options' => [ 1 => 'LaoKaup', 2 => 'Teenus', 3 => 'Kaup' ], 'default' => 1 ],
                [ 'name' => 'Osakond',          'type' => 'select', 'id' => 'smart_wp_integtaion_deparment',         'options' => $dept_opts, 'default' => '' ],
                [ 'type' => 'sectionend', 'id' => 'swi_merit_section' ],
            ];
        }

        private function settings_simplebooks( array $statuses ): array {
            return [
                [ 'type' => 'title', 'id' => 'swi_sb_section' ],
                [ 'name' => 'Luba Simplebooks',           'type' => 'checkbox', 'id' => 'swi_simplebooks_enable',        'default' => 'no' ],
                [ 'name' => 'Arve eesliides',             'type' => 'text',     'id' => 'swi_simplebooks_prefix',        'default' => 'SB' ],
                [ 'name' => 'Saada tellimused staatuses', 'type' => 'select',   'id' => 'swi_simplebooks_order_status',  'options' => $statuses, 'default' => 'wc-completed' ],
                [ 'type' => 'sectionend', 'id' => 'swi_sb_section' ],
            ];
        }

        private function settings_smartaccounts( array $statuses ): array {
            return [
                [ 'type' => 'title', 'id' => 'swi_sa_section' ],
                [ 'name' => 'Luba Smart Accounts',        'type' => 'checkbox', 'id' => 'swi_smartaccounts_enable',       'default' => 'no' ],
                [ 'name' => 'Arve eesliides',             'type' => 'text',     'id' => 'swi_smartaccounts_prefix',       'default' => 'SA' ],
                [ 'name' => 'Saada tellimused staatuses', 'type' => 'select',   'id' => 'swi_smartaccounts_order_status', 'options' => $statuses, 'default' => 'wc-completed' ],
                [ 'type' => 'sectionend', 'id' => 'swi_sa_section' ],
            ];
        }

        /* ─── OUTPUT ─── */
        public function output() {
            $statuses        = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
            $conn_settings   = $this->settings_connection();
            $merit_settings  = $this->settings_merit( $statuses );
            $sb_settings     = $this->settings_simplebooks( $statuses );
            $sa_settings     = $this->settings_smartaccounts( $statuses );

            if ( class_exists( 'LocalApiClient' ) ) {
                $this->proxy_error = LocalApiClient::pingServer();
            }

            // sidebar navigation structure
            $nav = [
                [ 'section' => 'Üldine', 'items' => [
                    [ 'key' => 'connection', 'icon' => '🔌', 'label' => 'Ühendus' ],
                ]],
                [ 'section' => 'Merit Aktiva', 'items' => [
                    [ 'key' => 'merit-general',   'icon' => '⚙', 'label' => 'Üldseaded' ],
                    [ 'key' => 'merit-countries', 'icon' => '🌍', 'label' => 'Riigid' ],
                    [ 'key' => 'merit-payments',  'icon' => '💳', 'label' => 'Maksed' ],
                    [ 'key' => 'merit-taxes',     'icon' => '📋', 'label' => 'Maksud' ],
                    [ 'key' => 'merit-shipping',  'icon' => '🚚', 'label' => 'Tarne' ],
                ]],
                [ 'section' => 'Simplebooks', 'items' => [
                    [ 'key' => 'sb-general', 'icon' => '⚙', 'label' => 'Üldseaded' ],
                ]],
                [ 'section' => 'Smart Accounts', 'items' => [
                    [ 'key' => 'sa-general', 'icon' => '⚙', 'label' => 'Üldseaded' ],
                ]],
            ];

            $merit_on = get_option('smart_wp_integtaion_enable') === 'yes';
            $sb_on    = get_option('swi_simplebooks_enable') === 'yes';
            $sa_on    = get_option('swi_smartaccounts_enable') === 'yes';
            ?>
            <style>
                :root {
                    --swi-brand:      #16a34a;
                    --swi-brand-dark: #15803d;
                    --swi-dark:       #111827;
                    --swi-dark2:      #1f2937;
                    --swi-muted:      #9ca3af;
                    --swi-text:       #111827;
                    --swi-border:     #e5e7eb;
                    --swi-light:      #f9fafb;
                }
                #wpbody-content { padding-bottom: 0 !important; }
                .woocommerce-page #wpbody .wrap { margin: 0 !important; padding: 0 !important; }

                .swi-frame {
                    display: flex; flex-direction: column;
                    height: calc(100vh - 32px);
                    background: var(--swi-light);
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    font-size: 13px;
                    color: var(--swi-text);
                    margin-left: -20px;
                }

                /* HEADER */
                .swi-header {
                    display: flex; align-items: center; justify-content: space-between;
                    background: var(--swi-dark); padding: 0 24px; height: 54px;
                    flex-shrink: 0; border-bottom: 2px solid var(--swi-brand);
                }
                .swi-logo { display:flex; align-items:center; gap:10px; color:#fff; font-size:15px; font-weight:700; }
                .swi-logo-icon { width:30px; height:30px; background:var(--swi-brand); border-radius:7px; display:flex; align-items:center; justify-content:center; font-size:16px; }
                .swi-logo-sub { font-size:10px; font-weight:400; color:var(--swi-muted); margin-top:1px; }
                .swi-header-right { display:flex; align-items:center; gap:14px; }
                .swi-status { display:flex; align-items:center; gap:6px; font-size:11.5px; color:var(--swi-muted); }
                .swi-dot { width:7px; height:7px; border-radius:50%; }
                .swi-dot.ok  { background:var(--swi-brand); box-shadow:0 0 0 2px rgba(22,163,74,.25); }
                .swi-dot.err { background:#ef4444; box-shadow:0 0 0 2px rgba(239,68,68,.25); }
                .swi-save-btn {
                    background:var(--swi-brand); color:#fff !important; border:none; border-radius:7px;
                    padding:8px 20px; font-size:13px; font-weight:600; cursor:pointer; transition:background .15s;
                }
                .swi-save-btn:hover { background:var(--swi-brand-dark) !important; }

                /* BODY */
                .swi-body { display:flex; flex:1; overflow:hidden; }

                /* SIDEBAR */
                .swi-sidebar { width:210px; background:var(--swi-dark); flex-shrink:0; overflow-y:auto; padding:12px 0 24px; }
                .swi-nav-group { margin-top:8px; }
                .swi-nav-group-label {
                    padding: 14px 16px 5px;
                    font-size: 9.5px; font-weight: 700; letter-spacing: .12em;
                    text-transform: uppercase; color: #4b5563;
                    display: flex; align-items: center; justify-content: space-between;
                }
                .swi-nav-group-label .swi-sys-badge {
                    font-size: 8px; padding: 1px 5px; border-radius: 10px; letter-spacing: 0;
                    text-transform: none; font-weight: 600;
                }
                .swi-sys-badge.on  { background: rgba(22,163,74,.18); color: #4ade80; }
                .swi-sys-badge.off { background: rgba(107,114,128,.15); color: #6b7280; }

                .swi-nav-item {
                    display: flex; align-items: center; gap: 9px;
                    padding: 8px 18px;
                    color: #9ca3af; cursor: pointer;
                    transition: background .1s, color .1s;
                    border-left: 3px solid transparent;
                    font-size: 12.5px; font-weight: 500;
                    user-select: none;
                }
                .swi-nav-item:hover { background: rgba(255,255,255,.05); color: #e5e7eb; }
                .swi-nav-item.active { background: rgba(22,163,74,.12); color: #fff; border-left-color: var(--swi-brand); font-weight:600; }
                .swi-nav-icon { font-size:13px; width:18px; text-align:center; }

                /* CONTENT */
                .swi-content { flex:1; overflow-y:auto; padding:28px 32px; }
                .swi-panel { display:none; }
                .swi-panel.active { display:block; }

                .swi-section-title { font-size:15px; font-weight:700; color:var(--swi-text); margin:0 0 4px; }
                .swi-section-desc  { font-size:12px; color:#6b7280; margin:0 0 20px; line-height:1.5; }

                .swi-card {
                    background:#fff; border:1px solid var(--swi-border);
                    border-radius:10px; padding:22px 24px; margin-bottom:18px;
                }
                .swi-card-header {
                    display:flex; align-items:center; justify-content:space-between;
                    margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid var(--swi-border);
                }
                .swi-card-title { font-size:13px; font-weight:700; color:var(--swi-text); }

                .swi-alert {
                    display:flex; align-items:flex-start; gap:10px;
                    padding:12px 14px; border-radius:8px; margin-bottom:16px;
                    font-size:12.5px; line-height:1.5;
                }
                .swi-alert.err  { background:rgba(239,68,68,.07);  border:1px solid rgba(239,68,68,.2);  color:#991b1b; }
                .swi-alert.ok   { background:rgba(22,163,74,.06);   border:1px solid rgba(22,163,74,.2);  color:#14532d; }
                .swi-alert.info { background:rgba(59,130,246,.06);  border:1px solid rgba(59,130,246,.2); color:#1e3a5f; }

                .swi-coming-soon {
                    text-align:center; padding:48px 24px;
                    color:#9ca3af; font-size:13px;
                }
                .swi-coming-soon .swi-cs-icon { font-size:36px; margin-bottom:12px; }
                .swi-coming-soon h3 { font-size:14px; font-weight:600; color:#6b7280; margin:0 0 6px; }
                .swi-coming-soon p  { margin:0; font-size:12px; }

                /* WC form-table override */
                .swi-card .form-table { margin:0; }
                .swi-card .form-table th { width:200px; padding:10px 0; font-size:12.5px; font-weight:600; color:#374151; vertical-align:top; }
                .swi-card .form-table td { padding:8px 0; vertical-align:top; }
                .swi-card .form-table input[type="text"],
                .swi-card .form-table input[type="url"],
                .swi-card .form-table select {
                    border:1px solid var(--swi-border); border-radius:6px;
                    padding:7px 10px; font-size:13px; min-width:280px; background:#fff;
                    transition:border-color .12s;
                }
                .swi-card .form-table input:focus,
                .swi-card .form-table select:focus { outline:none; border-color:var(--swi-brand); box-shadow:0 0 0 3px rgba(22,163,74,.1); }
                .swi-card .description { color:#9ca3af; font-size:11.5px; margin-top:4px; display:block; }
                .woocommerce-save-button { display:none !important; }

                /* Tables */
                .swi-card table.widefat { border:1px solid var(--swi-border); border-radius:8px; overflow:hidden; border-collapse:separate; border-spacing:0; width:100%; }
                .swi-card table.widefat thead th { background:var(--swi-light); padding:8px 12px; font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#6b7280; border-bottom:1px solid var(--swi-border); }
                .swi-card table.widefat td { padding:7px 12px; border-bottom:1px solid var(--swi-border); vertical-align:middle; }
                .swi-card table.widefat tr:last-child td { border-bottom:none; }
                .swi-card table.widefat tr:nth-child(even) td { background:#fafafa; }
                .swi-card table.widefat input[type="text"],
                .swi-card table.widefat select { border:1px solid var(--swi-border); border-radius:5px; padding:5px 8px; font-size:12.5px; width:100%; }

                /* Toggle */
                .swi-toggle-wrap { display:flex; align-items:center; gap:10px; padding-top:6px; }
                .swi-toggle-track { width:38px; height:21px; background:#d1d5db; border-radius:100px; cursor:pointer; position:relative; transition:background .15s; flex-shrink:0; }
                .swi-toggle-track.on { background:var(--swi-brand); }
                .swi-toggle-thumb { position:absolute; top:2px; left:2px; width:17px; height:17px; border-radius:50%; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.2); transition:left .15s; }
                .swi-toggle-track.on .swi-toggle-thumb { left:19px; }
                .swi-toggle-label { font-size:13px; color:var(--swi-text); }
            </style>

            <div class="swi-frame">

                <!-- HEADER -->
                <div class="swi-header">
                    <div class="swi-logo">
                        <div class="swi-logo-icon">🔗</div>
                        <div>
                            Smart WP Integration
                            <div class="swi-logo-sub">Merit Aktiva · Simplebooks · Smart Accounts</div>
                        </div>
                    </div>
                    <div class="swi-header-right">
                        <div class="swi-status">
                            <div class="swi-dot <?php echo $this->proxy_error ? 'err' : 'ok'; ?>"></div>
                            <?php echo $this->proxy_error ? 'Server ei vasta' : 'Server ühendatud'; ?>
                        </div>
                        <button type="submit" name="save" value="Save changes" class="swi-save-btn">Salvesta</button>
                    </div>
                </div>

                <!-- BODY -->
                <div class="swi-body">

                    <!-- SIDEBAR -->
                    <nav class="swi-sidebar">
                        <?php
                        $first = true;
                        $system_badges = [
                            'Merit Aktiva'  => $merit_on,
                            'Simplebooks'   => $sb_on,
                            'Smart Accounts'=> $sa_on,
                        ];
                        foreach ( $nav as $group ) :
                            $badge_key = $group['section'];
                            $has_badge = isset($system_badges[$badge_key]);
                            $is_on     = $has_badge ? $system_badges[$badge_key] : null;
                        ?>
                        <div class="swi-nav-group">
                            <div class="swi-nav-group-label">
                                <?php echo esc_html($group['section']); ?>
                                <?php if ($has_badge) : ?>
                                    <span class="swi-sys-badge <?php echo $is_on ? 'on' : 'off'; ?>">
                                        <?php echo $is_on ? 'aktiivne' : 'väljas'; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php foreach ( $group['items'] as $i => $item ) :
                                $is_first_ever = $first;
                                $first = false;
                            ?>
                            <div class="swi-nav-item <?php echo $is_first_ever ? 'active' : ''; ?>"
                                 data-panel="<?php echo esc_attr($item['key']); ?>"
                                 onclick="swiNav('<?php echo esc_js($item['key']); ?>', this)">
                                <span class="swi-nav-icon"><?php echo $item['icon']; ?></span>
                                <?php echo esc_html($item['label']); ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>
                    </nav>

                    <!-- CONTENT -->
                    <div class="swi-content">

                        <?php if ($this->proxy_error) : ?>
                        <div class="swi-alert err">⚠ <div><strong>Vaheserver ei ole kättesaadav.</strong><br><?php echo esc_html($this->proxy_error); ?></div></div>
                        <?php endif; ?>

                        <?php if ( isset($_GET['settings-updated']) ) : ?>
                        <div class="swi-alert ok">✓ <div><strong>Seaded on salvestatud.</strong></div></div>
                        <?php endif; ?>

                        <!-- ── ÜHENDUS ── -->
                        <div class="swi-panel active" id="swi-panel-connection">
                            <div class="swi-section-title">Vaheserveri ühendus</div>
                            <div class="swi-section-desc">Laravel vaheserveri URL, litsentsi võti ja krüptovõti. Kopeeri võtmed rakenduse litsentsi lehelt. Kõik arvestussüsteemid kasutavad sama ühendust.</div>
                            <div class="swi-card">
                                <?php woocommerce_admin_fields( $conn_settings ); ?>
                            </div>
                        </div>

                        <!-- ── MERIT AKTIVA — Üldseaded ── -->
                        <div class="swi-panel" id="swi-panel-merit-general">
                            <div class="swi-section-title">Merit Aktiva – Üldseaded</div>
                            <div class="swi-section-desc">Merit Aktiva API võtmed seadista Laravel rakenduses (<a href="#" style="color:var(--swi-brand)">license/settings?product_name=Merit+Aktiva</a>). Siin seadista WooCommerce käitumine.</div>
                            <div class="swi-card">
                                <?php woocommerce_admin_fields( $merit_settings ); ?>
                            </div>
                        </div>

                        <!-- ── MERIT — Riigid ── -->
                        <div class="swi-panel" id="swi-panel-merit-countries">
                            <div class="swi-section-title">Merit Aktiva – Riigi VAT seadistused</div>
                            <div class="swi-section-desc">Seo riik Merit Aktiva VAT koodiga. Vaikimisi riik kasutatakse kui ostja riiki ei tuvastata.</div>
                            <div class="swi-card">
                                <?php $this->field_country_map( [ 'id' => $this->opt_country_map ] ); ?>
                            </div>
                        </div>

                        <!-- ── MERIT — Maksed ── -->
                        <div class="swi-panel" id="swi-panel-merit-payments">
                            <div class="swi-section-title">Merit Aktiva – Maksemeetodite kaardistus</div>
                            <div class="swi-section-desc">Seo WooCommerce maksemeetodid Merit Aktiva pearaamatu kontodega.</div>
                            <div class="swi-card">
                                <?php $this->field_payment_map( [ 'id' => $this->opt_payment_map ] ); ?>
                            </div>
                        </div>

                        <!-- ── MERIT — Maksud ── -->
                        <div class="swi-panel" id="swi-panel-merit-taxes">
                            <div class="swi-section-title">Merit Aktiva – Maksude kaardistus</div>
                            <div class="swi-section-desc">Seo WooCommerce maksumäärad Merit Aktiva maksu ID-dega.</div>
                            <div class="swi-card">
                                <?php $this->field_tax_map( [ 'id' => $this->opt_tax_map ] ); ?>
                            </div>
                        </div>

                        <!-- ── MERIT — Tarne ── -->
                        <div class="swi-panel" id="swi-panel-merit-shipping">
                            <div class="swi-section-title">Merit Aktiva – Tarnemeetodite kaardistus</div>
                            <div class="swi-section-desc">Seo tarne-teenused Merit Aktiva artikli koodidega.</div>
                            <div class="swi-card">
                                <?php $this->field_shipping_map( [ 'id' => $this->opt_shipping_map ] ); ?>
                            </div>
                        </div>

                        <!-- ── SIMPLEBOOKS — Üldseaded ── -->
                        <div class="swi-panel" id="swi-panel-sb-general">
                            <div class="swi-section-title">Simplebooks – Üldseaded</div>
                            <div class="swi-section-desc">Simplebooks API võti seadista Laravel rakenduses. Siin luba integratsioon ja vali tellimuste staatus.</div>
                            <div class="swi-card">
                                <?php woocommerce_admin_fields( $sb_settings ); ?>
                            </div>
                            <div class="swi-alert info">
                                ℹ <div>Simplebooks ei vaja keerulisi kaardistusi. Orderid edastatakse automaatselt kui litsentsi seadetes on Simplebooks API võti lisatud.</div>
                            </div>
                        </div>

                        <!-- ── SMART ACCOUNTS — Üldseaded ── -->
                        <div class="swi-panel" id="swi-panel-sa-general">
                            <div class="swi-section-title">Smart Accounts – Üldseaded</div>
                            <div class="swi-section-desc">Smart Accounts Client ID ja Secret seadista Laravel rakenduses. Siin luba integratsioon ja vali tellimuste staatus.</div>
                            <div class="swi-card">
                                <?php woocommerce_admin_fields( $sa_settings ); ?>
                            </div>
                            <div class="swi-alert info">
                                ℹ <div>Smart Accounts ei vaja keerulisi kaardistusi. Orderid edastatakse automaatselt kui litsentsi seadetes on Smart Accounts API seadistused lisatud.</div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <script>
            function swiNav(key, el) {
                document.querySelectorAll('.swi-nav-item').forEach(function(n){ n.classList.remove('active'); });
                document.querySelectorAll('.swi-panel').forEach(function(p){ p.classList.remove('active'); });
                el.classList.add('active');
                var p = document.getElementById('swi-panel-' + key);
                if (p) p.classList.add('active');
            }
            document.addEventListener('DOMContentLoaded', function(){
                // Convert all enable checkboxes to toggles
                ['smart_wp_integtaion_enable','swi_simplebooks_enable','swi_smartaccounts_enable'].forEach(function(id){
                    var cb = document.getElementById(id);
                    if (!cb) return;
                    cb.style.display = 'none';
                    var wrap = document.createElement('div');
                    wrap.className = 'swi-toggle-wrap';
                    var track = document.createElement('div');
                    track.className = 'swi-toggle-track' + (cb.checked ? ' on' : '');
                    track.innerHTML = '<div class="swi-toggle-thumb"></div>';
                    track.onclick = function(){ cb.checked = !cb.checked; track.classList.toggle('on', cb.checked); };
                    var label = document.createElement('span');
                    label.className = 'swi-toggle-label';
                    label.textContent = cb.checked ? 'Lubatud' : 'Keelatud';
                    track.onclick = function(){
                        cb.checked = !cb.checked;
                        track.classList.toggle('on', cb.checked);
                        label.textContent = cb.checked ? 'Lubatud' : 'Keelatud';
                    };
                    wrap.appendChild(track);
                    wrap.appendChild(label);
                    cb.parentElement.insertBefore(wrap, cb);
                });
            });
            </script>
            <?php
        }

        /* ─── SAVE ─── */
        public function save() {
            woocommerce_update_options( $this->get_settings() );

            $maps = [
                $this->opt_payment_map  => isset($_POST[$this->opt_payment_map])  ? (array)$_POST[$this->opt_payment_map]  : [],
                $this->opt_shipping_map => isset($_POST[$this->opt_shipping_map]) ? (array)$_POST[$this->opt_shipping_map] : [],
                $this->opt_tax_map      => isset($_POST[$this->opt_tax_map])      ? (array)$_POST[$this->opt_tax_map]      : [],
                $this->opt_country_map  => isset($_POST[$this->opt_country_map])  ? (array)$_POST[$this->opt_country_map]  : [],
            ];
            foreach ( $maps as $key => $val ) {
                $clean = $this->deep_sanitize($val);
                $clean = array_filter($clean, function($row){
                    if (is_array($row)) { foreach($row as $v){ if((string)$v!=='') return true; } return false; }
                    return (string)$row !== '';
                });
                update_option($key, $clean, false);
            }
        }

        private function deep_sanitize( $arr ): array {
            $out = [];
            foreach ( (array)$arr as $k => $v ) {
                $key = is_scalar($k) ? sanitize_key(wp_unslash($k)) : '';
                $out[$key] = is_array($v) ? $this->deep_sanitize($v) : (is_scalar($v) ? sanitize_text_field(wp_unslash($v)) : '');
            }
            return $out;
        }

        /* ─── FIELD RENDERERS ─── */

        public function field_country_map( $value ) {
            $key = $value['id']; $stored = (array)get_option($key,[]);
            $countries = new WC_Countries();
            echo '<table class="widefat" style="margin:0;"><thead><tr><th>Riik</th><th>VAT kood</th><th>Nimetus</th><th>Vaikimisi?</th></tr></thead><tbody>';
            if (empty($stored)) $stored = [uniqid('row_') => ['country'=>'','vat_code'=>'','name'=>'','default'=>'']];
            foreach ($stored as $rk => $row) {
                echo '<tr><td><select name="'.esc_attr($key).'['.esc_attr($rk).'][country]" style="min-width:160px"><option value="">— riik —</option>';
                foreach ($countries->get_countries() as $code => $label)
                    printf('<option value="%s" %s>%s</option>', esc_attr($code), selected(($row['country']??'')===$code,true,false), esc_html($label));
                echo '</select></td>';
                printf('<td><input type="text" name="%1$s[%2$s][vat_code]" value="%3$s"></td>', esc_attr($key), esc_attr($rk), esc_attr($row['vat_code']??''));
                printf('<td><input type="text" name="%1$s[%2$s][name]" value="%3$s"></td>', esc_attr($key), esc_attr($rk), esc_attr($row['name']??''));
                echo '<td><select name="'.esc_attr($key).'['.esc_attr($rk).'][default]"><option value="">Ei</option>
                    <option value="yes" '.selected(($row['default']??'')==='yes',true,false).'>Jah</option></select></td></tr>';
            }
            $new = uniqid('new_');
            echo '<tr><td><select name="'.esc_attr($key).'['.esc_attr($new).'][country]" style="min-width:160px"><option value="">— lisa uus —</option>';
            foreach ($countries->get_countries() as $code => $label)
                printf('<option value="%s">%s</option>', esc_attr($code), esc_html($label));
            echo '</select></td>';
            printf('<td><input type="text" name="%1$s[%2$s][vat_code]" value=""></td>', esc_attr($key), esc_attr($new));
            printf('<td><input type="text" name="%1$s[%2$s][name]" value=""></td>', esc_attr($key), esc_attr($new));
            echo '<td><select name="'.esc_attr($key).'['.esc_attr($new).'][default]"><option value="">Ei</option><option value="yes">Jah</option></select></td></tr>';
            echo '</tbody></table>';
        }

        public function field_payment_map( $value ) {
            $key = $value['id']; $stored = (array)get_option($key,[]);
            $methods = [];
            if (function_exists('WC') && WC()->payment_gateways())
                foreach ((array)WC()->payment_gateways()->get_available_payment_gateways() as $id => $g)
                    $methods[$id] = $g->get_title();
            foreach (['bacs'=>'Pangaülekanne','cheque'=>'Tšekk','cod'=>'Sularaha','montonio_bank'=>'Montonio – pank','card_payment'=>'Kaardimakse'] as $k=>$v)
                if (!isset($methods[$k])) $methods[$k] = $v;
            echo '<table class="widefat" style="margin:0;"><thead><tr><th>Maksemeetod</th><th>Merit konto/kood</th></tr></thead><tbody>';
            foreach ($methods as $id => $title)
                printf('<tr><td>%s <code style="opacity:.6;font-size:.85em">(%s)</code></td><td><input type="text" name="%s[%s]" value="%s" placeholder="nt 1000"></td></tr>',
                    esc_html($title), esc_html($id), esc_attr($key), esc_attr($id), esc_attr($stored[$id]??''));
            echo '</tbody></table>';
        }

        public function field_tax_map( $value ) {
            $key = $value['id'];
            $stored = (array)get_option($key, ['std20'=>['id'=>'20','rate'=>'20','name'=>'20%','is_default'=>''],'zero'=>['id'=>'0','rate'=>'0','name'=>'0%','is_default'=>'yes']]);
            echo '<table class="widefat" style="margin:0;"><thead><tr><th>Maksu ID</th><th>%</th><th>Nimetus</th><th>Vaikimisi?</th></tr></thead><tbody>';
            foreach ($stored as $k => $row) {
                if (str_starts_with((string)$k,'_')) continue;
                $rk = esc_attr($k);
                echo '<tr>';
                printf('<td><input type="text" name="%1$s[%2$s][id]" value="%3$s"></td>', esc_attr($key), $rk, esc_attr($row['id']??''));
                printf('<td><input type="text" name="%1$s[%2$s][rate]" value="%3$s"></td>', esc_attr($key), $rk, esc_attr($row['rate']??''));
                printf('<td><input type="text" name="%1$s[%2$s][name]" value="%3$s"></td>', esc_attr($key), $rk, esc_attr($row['name']??''));
                echo '<td><select name="'.esc_attr($key).'['.$rk.'][is_default]"><option value="">Ei</option>
                    <option value="yes" '.selected(($row['is_default']??'')==='yes',true,false).'>Jah</option></select></td></tr>';
            }
            $new = uniqid('new_');
            echo '<tr>';
            printf('<td><input type="text" name="%1$s[%2$s][id]" value=""></td>', esc_attr($key), esc_attr($new));
            printf('<td><input type="text" name="%1$s[%2$s][rate]" value=""></td>', esc_attr($key), esc_attr($new));
            printf('<td><input type="text" name="%1$s[%2$s][name]" value=""></td>', esc_attr($key), esc_attr($new));
            echo '<td><select name="'.esc_attr($key).'['.esc_attr($new).'][is_default]"><option value="">Ei</option><option value="yes">Jah</option></select></td></tr>';
            echo '</tbody></table>';
        }

        public function field_shipping_map( $value ) {
            $key = $value['id']; $stored = (array)get_option($key,[]);
            $services = [
                'montonio_smartpost_parcel_machines' => 'Montonio – Smartpost pakiautomaat',
                'montonio_omniva_parcel_machines'    => 'Montonio – Omniva pakiautomaat',
                'montonio_omniva_courier'            => 'Montonio – Omniva kuller',
                'montonio_dpd_parcel_machines'       => 'Montonio – DPD pakiautomaat',
                'montonio_dpd_courier'               => 'Montonio – DPD kuller',
                'montonio_venipak_courier'           => 'Montonio – Venipak kuller',
                'itella_smartpost'                   => 'Smartpost',
                'itella_omniva_parcel'               => 'Omniva pakk',
                'itella_omniva'                      => 'Omniva',
                'itella_venipak'                     => 'Venipak',
                'itella_dpd_parcel'                  => 'DPD pakk',
                'pickup_location'                    => 'Kohapeal järeletulek',
            ];
            echo '<table class="widefat" style="margin:0;"><thead><tr><th>Teenus</th><th>Merit artikli kood</th></tr></thead><tbody>';
            foreach ($services as $slug => $title)
                printf('<tr><td>%s<br><code style="opacity:.55;font-size:.85em">%s</code></td><td><input type="text" name="%s[%s]" value="%s" placeholder="nt TRANSPORT_01"></td></tr>',
                    esc_html($title), esc_html($slug), esc_attr($key), esc_attr($slug), esc_attr($stored[$slug]??''));
            echo '</tbody></table>';
        }
    }

    $settings[] = new WC_Settings_Smart_WP_Integration();
    return $settings;
});
