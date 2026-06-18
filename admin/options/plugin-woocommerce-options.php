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
            // Feature 5: Seadistuste eksport/import
            add_action( 'wp_ajax_swi_export_settings', [ $this, 'handle_export_settings' ] );
            add_action( 'wp_ajax_swi_import_settings', [ $this, 'handle_import_settings' ] );
        }

        public function get_settings( $section = '' ): array {
            $s = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
            return array_merge(
                $this->s_connection(),
                $this->s_merit( $s ),
                $this->s_simplebooks( $s ),
                $this->s_smartaccounts( $s ),
                [
                    [ 'type' => 'swi_country_map',  'id' => $this->opt_country_map ],
                    [ 'type' => 'swi_payment_map',  'id' => $this->opt_payment_map ],
                    [ 'type' => 'swi_tax_map',      'id' => $this->opt_tax_map ],
                    [ 'type' => 'swi_shipping_map', 'id' => $this->opt_shipping_map ],
                ]
            );
        }

        private function s_server_url(): array {
            return [
                [ 'type' => 'title', 'id' => 'swi_server' ],
                [ 'name' => 'Vaheserveri URL', 'type' => 'text', 'id' => 'smart_wp_integration_server_url', 'default' => '', 'desc' => 'nt https://sinudomeen.ee — kehtib kõigile süsteemidele' ],
                [ 'type' => 'sectionend', 'id' => 'swi_server' ],
            ];
        }

        // Alias for get_settings() compat
        private function s_connection(): array { return $this->s_server_url(); }

        private function s_merit( array $s ): array {
            $client  = new MeritServersDataClient();
            $depts   = []; try { $depts = (array)$client->getDepartments(); } catch (\Exception $e) {}
            $saved   = get_option( 'smart_wp_integtaion_deparment', '' );
            if ( $saved && ! in_array( $saved, $depts, true ) ) $depts[] = $saved;
            $dopts   = [ '' => '— vali osakond —' ];
            foreach ( $depts as $c ) { $dopts[$c] = $c; }
            return [
                [ 'type' => 'title', 'id' => 'swi_merit_conn' ],
                [ 'name' => 'Litsentsi võti',   'type' => 'text', 'id' => 'smart_wp_integtaion_license_text', 'default' => '', 'desc' => 'Merit Aktiva litsentsi võti — kopeeri rakenduse litsentsi lehelt' ],
                [ 'name' => 'Krüptovõti (HEX)', 'type' => 'text', 'id' => 'smart_wp_integtaion_crypto_text',  'default' => '', 'desc' => '64-märgiline HEX — kopeeri rakenduse litsentsi lehelt' ],
                [ 'type' => 'sectionend', 'id' => 'swi_merit_conn' ],
                [ 'type' => 'title', 'id' => 'swi_merit' ],
                [ 'name' => 'Arve eesliides',             'type' => 'text',     'id' => 'smart_wp_integtaion_arve_eesliides', 'default' => 'WP' ],
                [ 'name' => 'Maksetähtaeg (päevades)',    'type' => 'text',     'id' => 'smart_wp_integtaion_maksetahtaeg',   'default' => '14' ],
                [ 'name' => 'Saada tellimused staatuses', 'type' => 'select',   'id' => 'smart_wp_integtaion_invoice_status', 'options' => $s, 'default' => 'wc-completed' ],
                [ 'name' => 'Käibemaksumäär', 'type' => 'select', 'id' => 'smart_wp_integtaion_maksumaar', 'options' => [
                    '973a4395-665f-47a6-a5b6-5384dd24f8d0' => '0%',
                    '6b618baa-680b-4606-9ad9-eff0beb27344' => '9%',
                    'fd050f9b-f376-40fb-aee5-af2d1f04970f' => '13%',
                    'b9b25735-6a15-4d4e-8720-25b254ae3d21' => '20%',
                    '307000b4-f1f2-4bc7-a110-24cb18d77212' => '22%',
                    '1e420e04-3dd7-46a5-b71f-0490779c2638' => '24%',
                ], 'default' => '1e420e04-3dd7-46a5-b71f-0490779c2638' ],
                [ 'name' => 'Arve ridade tüüp', 'type' => 'select', 'id' => 'smart_wp_integtaion_arve_ridade_tyyp', 'options' => [1=>'LaoKaup',2=>'Teenus',3=>'Kaup'], 'default' => 1 ],
                [ 'type' => 'sectionend', 'id' => 'swi_merit' ],
            ];
        }

        private function s_simplebooks( array $s ): array {
            return [
                [ 'type' => 'title', 'id' => 'swi_sb_conn' ],
                [ 'name' => 'Litsentsi võti',   'type' => 'text', 'id' => 'swi_simplebooks_license_key', 'default' => '', 'desc' => 'Simplebooks litsentsi võti — kopeeri rakenduse litsentsi lehelt' ],
                [ 'name' => 'Krüptovõti (HEX)', 'type' => 'text', 'id' => 'swi_simplebooks_crypto_key',  'default' => '', 'desc' => '64-märgiline HEX — kopeeri rakenduse litsentsi lehelt' ],
                [ 'type' => 'sectionend', 'id' => 'swi_sb_conn' ],
                [ 'type' => 'title', 'id' => 'swi_sb' ],
                [ 'name' => 'Arve eesliides',             'type' => 'text',     'id' => 'swi_simplebooks_prefix',       'default' => 'SB' ],
                [ 'name' => 'Saada tellimused staatuses', 'type' => 'select',   'id' => 'swi_simplebooks_order_status', 'options' => $s, 'default' => 'wc-completed' ],
                [ 'type' => 'sectionend', 'id' => 'swi_sb' ],
            ];
        }

        private function s_smartaccounts( array $s ): array {
            return [
                [ 'type' => 'title', 'id' => 'swi_sa_conn' ],
                [ 'name' => 'Litsentsi võti',   'type' => 'text', 'id' => 'swi_smartaccounts_license_key', 'default' => '', 'desc' => 'Smart Accounts litsentsi võti — kopeeri rakenduse litsentsi lehelt' ],
                [ 'name' => 'Krüptovõti (HEX)', 'type' => 'text', 'id' => 'swi_smartaccounts_crypto_key',  'default' => '', 'desc' => '64-märgiline HEX — kopeeri rakenduse litsentsi lehelt' ],
                [ 'type' => 'sectionend', 'id' => 'swi_sa_conn' ],
                [ 'type' => 'title', 'id' => 'swi_sa' ],
                [ 'name' => 'Arve eesliides',             'type' => 'text',     'id' => 'swi_smartaccounts_prefix',       'default' => 'SA' ],
                [ 'name' => 'Saada tellimused staatuses', 'type' => 'select',   'id' => 'swi_smartaccounts_order_status', 'options' => $s, 'default' => 'wc-completed' ],
                [ 'type' => 'sectionend', 'id' => 'swi_sa' ],
            ];
        }

        /* ─── OUTPUT ─── */
        public function output() {
            $s              = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
            $conn_s         = $this->s_connection();
            $merit_s        = $this->s_merit( $s );
            $sb_s           = $this->s_simplebooks( $s );
            $sa_s           = $this->s_smartaccounts( $s );
            $merit_on       = get_option('smart_wp_integtaion_enable')    === 'yes';
            $sb_on          = get_option('swi_simplebooks_enable')         === 'yes';
            $sa_on          = get_option('swi_smartaccounts_enable')       === 'yes';

            if ( class_exists('LocalApiClient') ) {
                $this->proxy_error = LocalApiClient::pingServer();
            }
            ?>
            <style>
                :root {
                    --swi-brand:      #16a34a;
                    --swi-brand-dark: #15803d;
                    --swi-dark:       #111827;
                    --swi-muted:      #9ca3af;
                    --swi-text:       #111827;
                    --swi-border:     #e5e7eb;
                    --swi-light:      #f9fafb;
                    --swi-sidebar-w:  260px;
                }
                #wpbody-content { padding-bottom: 0 !important; }
                .woocommerce-page #wpbody .wrap { margin: 0 !important; padding: 0 !important; }

                .swi-frame {
                    display: flex; flex-direction: column;
                    height: calc(100vh - 32px);
                    background: var(--swi-light);
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    font-size: 13px; color: var(--swi-text);
                    margin-left: -20px;
                }

                /* ── HEADER ── */
                .swi-header {
                    display: flex; align-items: center; justify-content: space-between;
                    background: var(--swi-dark); padding: 0 24px; height: 52px;
                    flex-shrink: 0;
                }
                .swi-logo { display:flex; align-items:center; gap:10px; color:#fff; font-size:14px; font-weight:700; }
                .swi-logo-icon { width:28px; height:28px; background:var(--swi-brand); border-radius:7px; display:flex; align-items:center; justify-content:center; font-size:15px; }
                .swi-logo-sub { font-size:10px; font-weight:400; color:#4b5563; margin-top:1px; }
                .swi-header-right { display:flex; align-items:center; gap:14px; }
                .swi-status { display:flex; align-items:center; gap:6px; font-size:11.5px; color:var(--swi-muted); }
                .swi-dot { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
                .swi-dot.ok  { background:var(--swi-brand); box-shadow:0 0 0 2px rgba(22,163,74,.3); }
                .swi-dot.err { background:#ef4444; box-shadow:0 0 0 2px rgba(239,68,68,.3); }
                .swi-save-btn { background:var(--swi-brand); color:#fff !important; border:none; border-radius:7px; padding:8px 22px; font-size:13px; font-weight:600; cursor:pointer; transition:background .15s; }
                .swi-save-btn:hover { background:var(--swi-brand-dark) !important; }

                /* ── TOP TABS ── */
                .swi-tabs {
                    display: flex; align-items: stretch;
                    background: var(--swi-dark);
                    border-bottom: 1px solid #1f2937;
                    padding: 0 24px;
                    flex-shrink: 0;
                }
                .swi-tab {
                    display: flex; align-items: center; gap: 7px;
                    padding: 0 18px; height: 42px;
                    font-size: 12.5px; font-weight: 500;
                    color: #6b7280; cursor: pointer;
                    border-bottom: 2px solid transparent;
                    transition: color .12s, border-color .12s;
                    white-space: nowrap; user-select: none;
                }
                .swi-tab:hover { color: #d1d5db; }
                .swi-tab.active { color: #fff; border-bottom-color: var(--swi-brand); font-weight: 600; }
                .swi-tab-badge {
                    font-size: 9px; padding: 1px 5px; border-radius: 10px;
                    font-weight: 700; letter-spacing: .02em;
                }
                .swi-tab-badge.on  { background: rgba(22,163,74,.2);  color: #4ade80; }
                .swi-tab-badge.off { background: rgba(107,114,128,.15); color: #6b7280; }

                /* ── BODY ── */
                .swi-body { display:flex; flex:1; overflow:hidden; }

                /* ── SIDEBAR ── */
                .swi-sidebar {
                    width: var(--swi-sidebar-w);
                    background: #1a2333;
                    flex-shrink: 0; overflow-y: auto;
                    padding: 16px 0 24px;
                    border-right: 1px solid #1f2937;
                }
                .swi-sidebar-label {
                    padding: 6px 20px 8px;
                    font-size: 10px; font-weight: 700;
                    letter-spacing: .1em; text-transform: uppercase;
                    color: #374151;
                }
                .swi-nav-item {
                    display: flex; align-items: center; gap: 10px;
                    padding: 9px 20px;
                    color: #9ca3af; cursor: pointer;
                    border-left: 3px solid transparent;
                    font-size: 13px; font-weight: 500;
                    transition: background .1s, color .1s;
                    user-select: none;
                }
                .swi-nav-item:hover { background: rgba(255,255,255,.04); color: #d1d5db; }
                .swi-nav-item.active { background: rgba(22,163,74,.1); color: #fff; border-left-color: var(--swi-brand); font-weight: 600; }
                .swi-nav-icon { font-size: 15px; width: 22px; text-align: center; flex-shrink: 0; }

                /* ── CONTENT ── */
                .swi-content { flex:1; overflow-y:auto; padding:28px 32px; }

                /* Tab views */
                .swi-tabview { display:none; height:100%; }
                .swi-tabview.active { display:flex; }

                /* Connection tab has no sidebar */
                .swi-tabview.no-sidebar .swi-sidebar { display:none; }
                .swi-tabview.no-sidebar .swi-content { border-left: none; }

                /* Panels */
                .swi-panel { display:none; }
                .swi-panel.active { display:block; }

                .swi-section-title { font-size:15px; font-weight:700; color:var(--swi-text); margin:0 0 4px; }
                .swi-section-desc  { font-size:12px; color:#6b7280; margin:0 0 20px; line-height:1.5; }
                .swi-card { background:#fff; border:1px solid var(--swi-border); border-radius:10px; padding:22px 24px; margin-bottom:18px; }

                .swi-alert { display:flex; align-items:flex-start; gap:10px; padding:12px 16px; border-radius:8px; margin-bottom:16px; font-size:12.5px; line-height:1.5; }
                .swi-alert.err  { background:rgba(239,68,68,.07);  border:1px solid rgba(239,68,68,.18);  color:#991b1b; }
                .swi-alert.ok   { background:rgba(22,163,74,.06);  border:1px solid rgba(22,163,74,.18);  color:#14532d; }
                .swi-alert.info { background:rgba(59,130,246,.06); border:1px solid rgba(59,130,246,.18); color:#1e3a5f; }

                /* WC form-table override */
                .swi-card .form-table { margin:0; }
                .swi-card .form-table th { width:230px; padding:10px 0; font-size:12.5px; font-weight:600; color:#374151; vertical-align:top; }
                .swi-card .form-table td { padding:8px 0; vertical-align:top; }
                .swi-card .form-table input[type="text"],
                .swi-card .form-table input[type="url"],
                .swi-card .form-table select {
                    border:1px solid var(--swi-border); border-radius:6px;
                    padding:7px 10px; font-size:13px; min-width:420px; background:#fff;
                    transition:border-color .12s;
                }
                .swi-card .form-table input:focus,
                .swi-card .form-table select:focus { outline:none; border-color:var(--swi-brand); box-shadow:0 0 0 3px rgba(22,163,74,.1); }
                .swi-card .description { color:#9ca3af; font-size:11.5px; margin-top:4px; display:block; }
                .woocommerce-save-button { display:none !important; }

                /* Tables */
                .swi-card table.widefat { border:1px solid var(--swi-border); border-radius:8px; overflow:hidden; border-collapse:separate; border-spacing:0; width:100%; }
                .swi-card table.widefat thead th { background:var(--swi-light); padding:8px 12px; font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#6b7280; border-bottom:1px solid var(--swi-border); }
                .swi-card table.widefat td { padding:8px 12px; border-bottom:1px solid var(--swi-border); vertical-align:middle; }
                .swi-card table.widefat tr:last-child td { border-bottom:none; }
                .swi-card table.widefat tr:nth-child(even) td { background:#fafafa; }
                .swi-card table.widefat input[type="text"],
                .swi-card table.widefat select { border:1px solid var(--swi-border); border-radius:5px; padding:5px 8px; font-size:12.5px; width:100%; min-width:0; }

                /* Toggle */
                .swi-toggle-wrap { display:flex; align-items:center; gap:10px; padding-top:4px; }
                .swi-toggle-track { width:40px; height:22px; background:#d1d5db; border-radius:100px; cursor:pointer; position:relative; transition:background .15s; flex-shrink:0; }
                .swi-toggle-track.on { background:var(--swi-brand); }
                .swi-toggle-thumb { position:absolute; top:2px; left:2px; width:18px; height:18px; border-radius:50%; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.2); transition:left .15s; }
                .swi-toggle-track.on .swi-toggle-thumb { left:20px; }
                .swi-toggle-label { font-size:12.5px; color:#6b7280; }
                .swi-toggle-track.on + .swi-toggle-label { color:var(--swi-brand); font-weight:600; }
                /* WooCommerce default nav margin override */
                body.woocommerce_page_wc-settings #mainform nav { margin: 0 !important; }
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
                        <button type="submit" name="save" value="Save changes" class="swi-save-btn"
                            onclick="window.onbeforeunload=null;jQuery(window).off('beforeunload');">Salvesta</button>
                    </div>
                </div>

                <!-- TOP TABS -->
                <div class="swi-tabs">
                    <div class="swi-tab active" data-tab="merit" onclick="swiTab('merit', this)">
                        📊 Merit Aktiva
                        <span class="swi-tab-badge <?php echo $merit_on ? 'on' : 'off'; ?>"><?php echo $merit_on ? 'aktiivne' : 'väljas'; ?></span>
                    </div>
                    <div class="swi-tab" data-tab="simplebooks" onclick="swiTab('simplebooks', this)">
                        📒 Simplebooks
                        <span class="swi-tab-badge <?php echo $sb_on ? 'on' : 'off'; ?>"><?php echo $sb_on ? 'aktiivne' : 'väljas'; ?></span>
                    </div>
                    <div class="swi-tab" data-tab="smartaccounts" onclick="swiTab('smartaccounts', this)">
                        🧮 Smart Accounts
                        <span class="swi-tab-badge <?php echo $sa_on ? 'on' : 'off'; ?>"><?php echo $sa_on ? 'aktiivne' : 'väljas'; ?></span>
                    </div>
                </div>

                <!-- ══ TAB: MERIT AKTIVA ══ -->
                <div class="swi-tabview active" id="swi-tab-merit">
                    <nav class="swi-sidebar">
                        <div class="swi-sidebar-label">Merit Aktiva</div>
                        <?php
                        $merit_nav = [
                            ['key'=>'merit-general',     'icon'=>'⚙',  'label'=>'Üldseaded'],
                            ['key'=>'merit-countries',   'icon'=>'🌍', 'label'=>'Riigid'],
                            ['key'=>'merit-payments',    'icon'=>'💳', 'label'=>'Maksed'],
                            ['key'=>'merit-taxes',       'icon'=>'📋', 'label'=>'Maksud'],
                            ['key'=>'merit-shipping',    'icon'=>'🚚', 'label'=>'Tarne'],
                            ['key'=>'merit-departments', 'icon'=>'🏢', 'label'=>'Osakonnad'],
                            ['key'=>'merit-tools',       'icon'=>'🔧', 'label'=>'Tööriistad'],
                        ];
                        foreach ($merit_nav as $i => $item) : ?>
                        <div class="swi-nav-item <?php echo $i===0?'active':''; ?>"
                             data-panel="<?php echo esc_attr($item['key']); ?>"
                             onclick="swiPanel('<?php echo esc_js($item['key']); ?>', this, 'merit')">
                            <span class="swi-nav-icon"><?php echo $item['icon']; ?></span>
                            <?php echo esc_html($item['label']); ?>
                        </div>
                        <?php endforeach; ?>
                    </nav>
                    <div class="swi-content">
                        <div class="swi-panel active" id="swi-panel-merit-general">
                            <?php if ( isset($_GET['settings-updated']) ) : ?>
                            <div class="swi-alert ok">✓ <div><strong>Seaded on salvestatud.</strong></div></div>
                            <?php endif; ?>
                            <?php if ($this->proxy_error) : ?>
                            <div class="swi-alert err">⚠ <div><strong>Vaheserver ei ole kättesaadav.</strong><br><?php echo esc_html($this->proxy_error); ?></div></div>
                            <?php endif; ?>
                            <?php
                            // Feature 8: Setup wizard banner
                            $is_configured = !empty(get_option('smart_wp_integtaion_license_text')) && !empty(get_option('smart_wp_integtaion_crypto_text'));
                            if (!$is_configured) : ?>
                            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:20px;margin-bottom:24px;">
                                <h3 style="margin:0 0 8px;color:#1e40af;">👋 Tere tulemast Smart WP Integrations!</h3>
                                <p style="margin:0 0 12px;color:#1e3a8a;">Alustamiseks täida 3 sammu:</p>
                                <ol style="margin:0;color:#1e3a8a;padding-left:20px;">
                                    <li>Kopeeri <strong>Litsentsi võti</strong> ja <strong>Krüptovõti</strong> Laravel rakendusest</li>
                                    <li>Seadista <strong>Merit API võtmed</strong> Laravel rakenduses: Litsentsid → Seadista → Merit Aktiva</li>
                                    <li>Vajuta <strong>Salvesta seaded</strong></li>
                                </ol>
                            </div>
                            <?php endif; ?>
                            <?php
                            // Feature 2: Integratsiooni staatuse riba
                            $server_ok = class_exists('LocalApiClient') && LocalApiClient::pingServer() === null;
                            $last_send = get_option('swi_last_successful_send', '');
                            ?>
                            <div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
                                <span style="padding:4px 12px;border-radius:20px;font-size:12px;background:<?php echo $server_ok ? '#16a34a' : '#ef4444'; ?>;color:#fff;">● Server: <?php echo $server_ok ? 'OK' : 'Viga'; ?></span>
                                <span style="padding:4px 12px;border-radius:20px;font-size:12px;background:#e5e7eb;color:#374151;">Viimane saatmine: <?php echo $last_send ? esc_html($last_send) : '—'; ?></span>
                            </div>
                            <div class="swi-section-title">Vaheserveri ühendus</div>
                            <div class="swi-section-desc">Kehtib kõigile süsteemidele. Kopeeri võtmed Laravel rakenduse litsentsi lehelt.</div>
                            <div class="swi-card" style="max-width:860px; margin-bottom:28px;">
                                <?php woocommerce_admin_fields( $conn_s ); ?>
                            </div>
                            <div class="swi-section-title">Merit Aktiva – Üldseaded</div>
                            <div class="swi-section-desc">Merit Aktiva API võtmed (API ID + API Key) seadista Laravel rakenduses jaotises <em>Litsentsid → Seadista → Merit Aktiva</em>.</div>
                            <div class="swi-card">
                                <?php $this->render_toggle('smart_wp_integtaion_enable', 'Luba Merit Aktiva', $merit_on); ?>
                                <?php
                                // Feature 4: E-mail teavitus toggle
                                $email_notify_on = get_option('swi_merit_email_notify') === 'yes';
                                $this->render_toggle('swi_merit_email_notify', 'E-mail teavitus ebaõnnestumisel', $email_notify_on);
                                ?>
                                <?php woocommerce_admin_fields($merit_s); ?>
                            </div>
                            <div class="swi-section-title" style="margin-top:24px;">Sünkroniseerimine</div>
                            <div class="swi-section-desc">Võrdle WooCommerce tellimusi Merit Aktiva arvetega. Puuduvaid arveid saad siit uuesti saata.</div>
                            <div class="swi-card" style="max-width:860px;">
                                <button type="button" id="swi-sync-btn" class="button button-secondary">Kontrolli sünkroniseerimist</button>
                                <span id="swi-sync-spinner" style="display:none;margin-left:10px;">Laen...</span>
                                <div id="swi-sync-result" style="margin-top:16px;"></div>
                            </div>
                            <?php // Feature 6: Arve eelvaade ?>
                            <div class="swi-section-title" style="margin-top:24px;">Arve eelvaade</div>
                            <div class="swi-section-desc">Kontrolli mis andmed Meriti lähevad enne päris saatmist.</div>
                            <div class="swi-card" style="max-width:860px;">
                                <input type="number" id="swi-preview-order-id" placeholder="Tellimuse ID (nt 34)" style="width:160px;margin-right:8px;">
                                <button type="button" id="swi-preview-btn" class="button button-secondary">Näita JSON</button>
                                <pre id="swi-preview-result" style="display:none;margin-top:12px;background:#f3f4f6;padding:12px;border-radius:4px;font-size:11px;overflow:auto;max-height:400px;"></pre>
                            </div>
                        </div>
                        <div class="swi-panel" id="swi-panel-merit-countries">
                            <div class="swi-section-title">Merit Aktiva – Riigi VAT seadistused</div>
                            <div class="swi-section-desc">Seo riik Merit Aktiva VAT koodiga. Vaikimisi riik kasutatakse kui ostja riiki ei tuvastata.</div>
                            <div class="swi-card"><?php $this->field_country_map(['id'=>$this->opt_country_map]); ?></div>
                        </div>
                        <div class="swi-panel" id="swi-panel-merit-payments">
                            <div class="swi-section-title">Merit Aktiva – Maksemeetodite kaardistus</div>
                            <div class="swi-section-desc">Seo WooCommerce maksemeetodid Merit Aktiva pearaamatu kontodega.</div>
                            <div class="swi-card"><?php $this->field_payment_map(['id'=>$this->opt_payment_map]); ?></div>
                        </div>
                        <div class="swi-panel" id="swi-panel-merit-taxes">
                            <div class="swi-section-title">Merit Aktiva – Maksude kaardistus</div>
                            <div class="swi-section-desc">Seo WooCommerce maksumäärad Merit Aktiva maksu ID-dega.</div>
                            <div class="swi-card" style="max-width:860px;">
                                <p style="margin:0 0 12px;">
                                    <button type="button" id="swi-load-vatcodes-btn" class="button button-secondary">Lae Merit maksumäärad</button>
                                    <span id="swi-vatcodes-spinner" style="display:none;margin-left:8px;font-size:12px;color:#666;">Laen...</span>
                                </p>
                                <?php $this->field_tax_map(['id'=>$this->opt_tax_map]); ?>
                            </div>
                        </div>
                        <div class="swi-panel" id="swi-panel-merit-shipping">
                            <div class="swi-section-title">Merit Aktiva – Tarnemeetodite kaardistus</div>
                            <div class="swi-section-desc">Seo tarne-teenused Merit Aktiva artikli koodidega.</div>
                            <div class="swi-card"><?php $this->field_shipping_map(['id'=>$this->opt_shipping_map]); ?></div>
                        </div>

                        <?php // Feature 9: Osakonnad paneel ?>
                        <div class="swi-panel" id="swi-panel-merit-departments">
                            <div class="swi-section-title">Merit Aktiva – Osakonnad</div>
                            <div class="swi-section-desc">Seo WooCommerce tootekategooriad Merit Aktiva osakondadega.</div>
                            <?php
                            $client2   = new MeritServersDataClient();
                            $depts2    = [];
                            try { $depts2 = (array) $client2->getDepartments(); } catch (\Exception $e) {}
                            $saved_dept = get_option('smart_wp_integtaion_deparment', '');
                            if ($saved_dept && !in_array($saved_dept, $depts2, true)) $depts2[] = $saved_dept;
                            $dopts2 = ['' => '— vali osakond —'];
                            foreach ($depts2 as $dc) { $dopts2[$dc] = $dc; }
                            ?>
                            <div class="swi-card" style="max-width:860px;">
                                <table class="form-table" style="margin-bottom:16px;">
                                    <tr>
                                        <th style="width:230px;padding:10px 0;font-size:12.5px;font-weight:600;color:#374151;">Vaikimisi osakond</th>
                                        <td style="padding:8px 0;">
                                            <select name="smart_wp_integtaion_deparment" style="min-width:300px;">
                                                <?php foreach ($dopts2 as $dv => $dl) : ?>
                                                <option value="<?php echo esc_attr($dv); ?>" <?php selected($saved_dept, $dv); ?>><?php echo esc_html($dl); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <?php
                            $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
                            $dept_map_saved = (array) get_option('swi_category_dept_map', []);
                            ?>
                            <div class="swi-card" style="max-width:860px;">
                                <p style="margin:0 0 12px;font-weight:600;font-size:12.5px;">Kategooria → Osakond kaardistus</p>
                                <p style="margin:0 0 12px;font-size:12px;color:#6b7280;">Kui toote kategooria leitakse siit tabelist, kasutatakse vastavat osakonda (eirab vaikimisi osakondi).</p>
                                <table class="widefat" id="swi-dept-map-table">
                                    <thead><tr>
                                        <th>Kategooria</th>
                                        <th>Osakond</th>
                                        <th style="width:40px;"></th>
                                    </tr></thead>
                                    <tbody>
                                    <?php if (!empty($dept_map_saved)) : foreach ($dept_map_saved as $cat_slug => $dept_val) : ?>
                                    <tr>
                                        <td>
                                            <select name="swi_dept_map_cat[]" style="width:100%;">
                                                <option value="">— kategooria —</option>
                                                <?php if (!is_wp_error($categories)) foreach ($categories as $cat) : ?>
                                                <option value="<?php echo esc_attr($cat->slug); ?>" <?php selected($cat_slug, $cat->slug); ?>><?php echo esc_html($cat->name); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="swi_dept_map_dept[]" style="width:100%;">
                                                <option value="">— kasuta vaikimisi —</option>
                                                <?php foreach ($depts2 as $dc) : ?>
                                                <option value="<?php echo esc_attr($dc); ?>" <?php selected($dept_val, $dc); ?>><?php echo esc_html($dc); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td><button type="button" onclick="this.closest('tr').remove()" style="background:none;border:none;cursor:pointer;color:#b32d2e;font-size:16px;" title="Kustuta">✕</button></td>
                                    </tr>
                                    <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                                <p style="margin-top:12px;">
                                    <button type="button" id="swi-add-dept-row" class="button button-secondary">+ Lisa rida</button>
                                </p>
                                <script>
                                document.getElementById('swi-add-dept-row') && document.getElementById('swi-add-dept-row').addEventListener('click', function() {
                                    var tbody = document.querySelector('#swi-dept-map-table tbody');
                                    var cats = <?php echo wp_json_encode(!is_wp_error($categories) ? array_map(function($c){ return ['slug'=>$c->slug,'name'=>$c->name]; }, $categories) : []); ?>;
                                    var depts = <?php echo wp_json_encode(array_values($depts2)); ?>;
                                    var catOpts = '<option value="">— kategooria —</option>' + cats.map(function(c){ return '<option value="'+c.slug+'">'+c.name+'</option>'; }).join('');
                                    var deptOpts = '<option value="">— kasuta vaikimisi —</option>' + depts.map(function(d){ return '<option value="'+d+'">'+d+'</option>'; }).join('');
                                    var tr = document.createElement('tr');
                                    tr.innerHTML = '<td><select name="swi_dept_map_cat[]" style="width:100%;">'+catOpts+'</select></td>'
                                        + '<td><select name="swi_dept_map_dept[]" style="width:100%;">'+deptOpts+'</select></td>'
                                        + '<td><button type="button" onclick="this.closest(\'tr\').remove()" style="background:none;border:none;cursor:pointer;color:#b32d2e;font-size:16px;">✕</button></td>';
                                    tbody.appendChild(tr);
                                });
                                </script>
                            </div>
                        </div>

                        <?php
                        // Feature 5 + 7: Tööriistad paneel
                        $history = (array) get_option('swi_send_history', []);
                        ?>
                        <div class="swi-panel" id="swi-panel-merit-tools">
                            <div class="swi-section-title">Tööriistad</div>
                            <div class="swi-section-desc">Seadistuste eksport/import ning saatmise ajalugu.</div>

                            <?php // Feature 5: Eksport ?>
                            <div class="swi-card" style="max-width:860px;">
                                <p style="margin:0 0 8px;font-weight:600;font-size:12.5px;">Seadistuste eksport</p>
                                <p style="margin:0 0 12px;font-size:12px;color:#6b7280;">Lae alla kõik seadistused JSON failina.</p>
                                <button type="button" id="swi-export-btn" class="button button-secondary">Ekspordi seaded</button>
                            </div>

                            <?php // Feature 5: Import ?>
                            <div class="swi-card" style="max-width:860px;">
                                <p style="margin:0 0 8px;font-weight:600;font-size:12.5px;">Seadistuste import</p>
                                <p style="margin:0 0 12px;font-size:12px;color:#6b7280;">Lae üles eelnevalt eksporditud JSON fail.</p>
                                <input type="file" id="swi-import-file" accept=".json" style="margin-right:8px;">
                                <button type="button" id="swi-import-btn" class="button button-secondary">Impordi seaded</button>
                                <span id="swi-import-status" style="margin-left:10px;font-size:12px;"></span>
                            </div>

                            <?php // Feature 7: Saatmise ajalugu ?>
                            <div class="swi-section-title" style="margin-top:24px;">Saatmise ajalugu</div>
                            <div class="swi-section-desc">Viimased 50 saatmiskatset.</div>
                            <div class="swi-card" style="max-width:860px;">
                                <?php if (empty($history)) : ?>
                                <p style="color:#9ca3af;font-size:12.5px;margin:0;">Saatmisi pole veel toimunud.</p>
                                <?php else : ?>
                                <p style="margin:0 0 12px;">
                                    <button type="button" id="swi-clear-history-btn" class="button button-small" style="color:#b32d2e;">Tühista ajalugu</button>
                                </p>
                                <table class="widefat striped">
                                    <thead><tr>
                                        <th>Aeg</th>
                                        <th>Tellimus</th>
                                        <th>Staatus</th>
                                        <th>Sõnum</th>
                                    </tr></thead>
                                    <tbody>
                                    <?php foreach ($history as $entry) :
                                        $entry_status = $entry['status'] ?? '';
                                        $color = $entry_status === 'ok' ? '#14532d' : '#991b1b';
                                        $bg    = $entry_status === 'ok' ? '#dcfce7' : '#fee2e2';
                                    ?>
                                    <tr>
                                        <td style="font-size:12px;"><?php echo esc_html($entry['time'] ?? ''); ?></td>
                                        <td><a href="<?php echo esc_url(admin_url('post.php?post=' . intval($entry['order_id'] ?? 0) . '&action=edit')); ?>" target="_blank">#<?php echo intval($entry['order_id'] ?? 0); ?></a></td>
                                        <td><span style="padding:2px 8px;border-radius:10px;font-size:11px;background:<?php echo $bg; ?>;color:<?php echo $color; ?>;"><?php echo esc_html($entry_status); ?></span></td>
                                        <?php
                                        $raw_msg = $entry['message'] ?? '';
                                        // Tõlgi vana JSON-kujul sõnum inimloetavaks
                                        if (str_starts_with(trim($raw_msg), '{')) {
                                            $decoded = json_decode($raw_msg, true);
                                            if (is_array($decoded) && function_exists('swi_humanize_merit_error')) {
                                                $raw_msg = swi_humanize_merit_error($decoded);
                                            }
                                        }
                                        ?>
                                        <td style="font-size:12px;"><?php echo esc_html($raw_msg); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ══ TAB: SIMPLEBOOKS ══ -->
                <div class="swi-tabview" id="swi-tab-simplebooks">
                    <nav class="swi-sidebar">
                        <div class="swi-sidebar-label">Simplebooks</div>
                        <div class="swi-nav-item active" data-panel="sb-general" onclick="swiPanel('sb-general', this, 'simplebooks')">
                            <span class="swi-nav-icon">⚙</span> Üldseaded
                        </div>
                    </nav>
                    <div class="swi-content">
                        <div class="swi-panel active" id="swi-panel-sb-general">
                            <?php if ( isset($_GET['settings-updated']) ) : ?>
                            <div class="swi-alert ok">✓ <div><strong>Seaded on salvestatud.</strong></div></div>
                            <?php endif; ?>
                            <?php if ($this->proxy_error) : ?>
                            <div class="swi-alert err">⚠ <div><strong>Vaheserver ei ole kättesaadav.</strong><br><?php echo esc_html($this->proxy_error); ?></div></div>
                            <?php endif; ?>
                            <div class="swi-section-title">Vaheserveri ühendus</div>
                            <div class="swi-section-desc">Kehtib kõigile süsteemidele. Kopeeri võtmed Laravel rakenduse litsentsi lehelt.</div>
                            <div class="swi-card" style="max-width:860px; margin-bottom:28px;">
                                <?php woocommerce_admin_fields( $conn_s ); ?>
                            </div>
                            <div class="swi-section-title">Simplebooks – Üldseaded</div>
                            <div class="swi-section-desc">Simplebooks API võti seadista Laravel rakenduses jaotises <em>Litsentsid → Seadista → Simplebooks</em>. Plugin edastab tellimused automaatselt vaheserveri kaudu.</div>
                            <div class="swi-card" style="max-width:860px;">
                                <?php $this->render_toggle('swi_simplebooks_enable', 'Luba Simplebooks', $sb_on); ?>
                                <?php woocommerce_admin_fields($sb_s); ?>
                            </div>
                            <div class="swi-alert info">ℹ <div>Simplebooks ei vaja keerulisi kaardistusi — orderid edastatakse automaatselt kui litsentsi seadetes on Simplebooks API võti lisatud.</div></div>
                        </div>
                    </div>
                </div>

                <!-- ══ TAB: SMART ACCOUNTS ══ -->
                <div class="swi-tabview" id="swi-tab-smartaccounts">
                    <nav class="swi-sidebar">
                        <div class="swi-sidebar-label">Smart Accounts</div>
                        <div class="swi-nav-item active" data-panel="sa-general" onclick="swiPanel('sa-general', this, 'smartaccounts')">
                            <span class="swi-nav-icon">⚙</span> Üldseaded
                        </div>
                    </nav>
                    <div class="swi-content">
                        <div class="swi-panel active" id="swi-panel-sa-general">
                            <?php if ( isset($_GET['settings-updated']) ) : ?>
                            <div class="swi-alert ok">✓ <div><strong>Seaded on salvestatud.</strong></div></div>
                            <?php endif; ?>
                            <?php if ($this->proxy_error) : ?>
                            <div class="swi-alert err">⚠ <div><strong>Vaheserver ei ole kättesaadav.</strong><br><?php echo esc_html($this->proxy_error); ?></div></div>
                            <?php endif; ?>
                            <div class="swi-section-title">Vaheserveri ühendus</div>
                            <div class="swi-section-desc">Kehtib kõigile süsteemidele. Kopeeri võtmed Laravel rakenduse litsentsi lehelt.</div>
                            <div class="swi-card" style="max-width:860px; margin-bottom:28px;">
                                <?php woocommerce_admin_fields( $conn_s ); ?>
                            </div>
                            <div class="swi-section-title">Smart Accounts – Üldseaded</div>
                            <div class="swi-section-desc">Smart Accounts Client ID ja Secret seadista Laravel rakenduses jaotises <em>Litsentsid → Seadista → Smart Accounts</em>. Plugin edastab tellimused automaatselt vaheserveri kaudu.</div>
                            <div class="swi-card" style="max-width:860px;">
                                <?php $this->render_toggle('swi_smartaccounts_enable', 'Luba Smart Accounts', $sa_on); ?>
                                <?php woocommerce_admin_fields($sa_s); ?>
                            </div>
                            <div class="swi-alert info">ℹ <div>Smart Accounts ei vaja keerulisi kaardistusi — orderid edastatakse automaatselt kui litsentsi seadetes on Smart Accounts API seadistused lisatud.</div></div>
                        </div>
                    </div>
                </div>

            </div><!-- .swi-frame -->

            <script>
            function swiTab(tab, el) {
                document.querySelectorAll('.swi-tab').forEach(function(t){ t.classList.remove('active'); });
                document.querySelectorAll('.swi-tabview').forEach(function(v){ v.classList.remove('active'); });
                el.classList.add('active');
                var view = document.getElementById('swi-tab-' + tab);
                if (view) view.classList.add('active');
            }
            function swiPanel(key, el, tab) {
                var view = document.getElementById('swi-tab-' + tab);
                if (!view) return;
                view.querySelectorAll('.swi-nav-item').forEach(function(n){ n.classList.remove('active'); });
                view.querySelectorAll('.swi-panel').forEach(function(p){ p.classList.remove('active'); });
                el.classList.add('active');
                var panel = document.getElementById('swi-panel-' + key);
                if (panel) panel.classList.add('active');
            }
            function swiNotify(msg, type) {
                var n = document.createElement('div');
                n.textContent = msg;
                n.style.cssText = 'position:fixed;top:32px;right:24px;z-index:99999;padding:12px 18px;border-radius:4px;font-size:13px;font-weight:500;max-width:360px;box-shadow:0 2px 8px rgba(0,0,0,.2);'
                    + (type === 'error' ? 'background:#b32d2e;color:#fff;' : 'background:#0a6b23;color:#fff;');
                document.body.appendChild(n);
                setTimeout(function(){ n.style.transition='opacity .4s'; n.style.opacity='0'; setTimeout(function(){ n.remove(); }, 400); }, 5000);
            }

            document.addEventListener('DOMContentLoaded', function() {
                var btn = document.getElementById('swi-sync-btn');
                if (!btn) return;
                btn.addEventListener('click', function() {
                    var spinner = document.getElementById('swi-sync-spinner');
                    var result  = document.getElementById('swi-sync-result');
                    btn.disabled = true;
                    spinner.style.display = 'inline';
                    result.innerHTML = '';
                    jQuery.post(ajaxurl, {
                        action: 'swi_merit_sync_check',
                        security: MyAjax.nonce
                    }, function(resp) {
                        btn.disabled = false;
                        spinner.style.display = 'none';
                        if (!resp.success) {
                            result.innerHTML = '<div class="swi-alert err">⚠ ' + (resp.data && resp.data.error ? resp.data.error : 'Viga') + '</div>';
                            return;
                        }
                        var rows = resp.data.rows;
                        var missing = rows.filter(function(r){ return !r.in_merit; });

                        if (missing.length === 0) {
                            result.innerHTML = '';
                            swiNotify('Kõik arved on Merit Aktivaga sünkroniseeritud ✓', 'ok');
                            return;
                        }

                        var html = '<p style="margin:0 0 8px;"><span style="color:#b32d2e"><strong>' + missing.length + ' arvet</strong> puudub Meritist</span></p>';
                        html += '<table class="widefat striped" style="max-width:860px;">'
                            + '<thead><tr><th>Arve nr</th><th>Kuupäev</th><th>Summa</th><th>Saadetud</th><th></th></tr></thead><tbody>';
                        missing.forEach(function(r) {
                            html += '<tr>'
                                + '<td><a href="post.php?post=' + r.order_id + '&action=edit" target="_blank">' + r.invoice_no + '</a></td>'
                                + '<td>' + r.date + '</td>'
                                + '<td>' + r.total + '</td>'
                                + '<td style="font-size:11px;color:#666">' + (r.meta_sent || '—') + '</td>'
                                + '<td><button type="button" class="button button-small swi-resend-btn" data-id="' + r.order_id + '">Saada uuesti</button></td>'
                                + '</tr>';
                        });
                        html += '</tbody></table>';
                        result.innerHTML = html;

                        // Resend nupud
                        result.querySelectorAll('.swi-resend-btn').forEach(function(b) {
                            b.addEventListener('click', function() {
                                var orderId = this.getAttribute('data-id');
                                var row = this.closest('tr');
                                this.disabled = true;
                                this.textContent = 'Saadan...';
                                var self = this;
                                jQuery.post(ajaxurl, {
                                    action: 'swi_merit_sync_resend',
                                    security: MyAjax.nonce,
                                    order_id: orderId
                                }, function(r2) {
                                    if (r2.success) {
                                        row.cells[3].innerHTML = '<span style="color:#0a6b23">✓ Saadetud</span>';
                                        row.cells[5].innerHTML = '';
                                        swiNotify('Arve ' + orderId + ' edastatud Merit Aktivasse.', 'ok');
                                    } else {
                                        self.textContent = 'Saada uuesti';
                                        self.disabled = false;
                                        var d = r2.data || {};
                                        var raw = (d.response && d.response.result && d.response.result.merit_message)
                                            || (d.response && d.response.result && d.response.result.body)
                                            || d.merit_message || d.message || '';
                                        var meritErrors = {
                                            'Korduv arve number': 'See arve on Meriti juba olemas. Kui soovid uuesti saata, kustuta arve esmalt Merit Aktivas.',
                                            'Ridade summa ei võrdu arve summaga': 'Arve ridade kogusumma ei klapi arvele märgitud summaga. Kontrolli toodete hindu WooCommerce-is.',
                                            'Periood liiga pikk': 'Päringus valitud ajaperiood on liiga pikk — Merit lubab korraga max 3 kuud.',
                                            'kaubakoodi liiga pikk': 'Toote SKU kood on liiga pikk (max 20 tähemärki). Lühenda SKU-d WooCommerce toote seadetes.',
                                        };
                                        var friendly = raw;
                                        Object.keys(meritErrors).forEach(function(key) {
                                            if (raw.indexOf(key) !== -1) friendly = meritErrors[key];
                                        });
                                        if (friendly === raw) friendly = 'Merit Aktiva tagastas vea: ' + (raw || 'tundmatu viga') + '. Vaata logifaili täpsema info saamiseks.';
                                        swiNotify('Arve ' + orderId + ': ' + friendly, 'error');
                                    }
                                });
                            });
                        });
                    });
                });
            });

            function swiToggle(id) {
                var h = document.getElementById('swi_h_' + id);
                var t = document.getElementById('swi_t_' + id);
                var l = document.getElementById('swi_l_' + id);
                var on = h.value !== 'yes';
                h.value = on ? 'yes' : 'no';
                t.classList.toggle('on', on);
                l.textContent = on ? 'Lubatud' : 'Keelatud';
            }

            // Feature 1: Merit VAT koodide laadimine
            document.addEventListener('DOMContentLoaded', function() {
                var vatBtn = document.getElementById('swi-load-vatcodes-btn');
                if (vatBtn) {
                    vatBtn.addEventListener('click', function() {
                        var spinner = document.getElementById('swi-vatcodes-spinner');
                        vatBtn.disabled = true;
                        if (spinner) spinner.style.display = 'inline';
                        jQuery.post(ajaxurl, {
                            action: 'swi_merit_load_vatcodes',
                            security: MyAjax.nonce
                        }, function(resp) {
                            vatBtn.disabled = false;
                            if (spinner) spinner.style.display = 'none';
                            if (!resp.success) {
                                swiNotify((resp.data && resp.data.error) ? resp.data.error : 'VAT koodide laadimine ebaõnnestus', 'error');
                                return;
                            }
                            var vatcodes = resp.data.vatcodes;
                            if (!vatcodes || vatcodes.length === 0) {
                                swiNotify('Ühtegi VAT koodi ei leitud.', 'error');
                                return;
                            }
                            // Lisa iga VAT koodi jaoks rida maksukaardistuse tabelisse
                            var taxKey = '<?php echo esc_js($this->opt_tax_map); ?>';
                            var tbody = document.querySelector('#swi-panel-merit-taxes table.widefat tbody');
                            if (!tbody) { swiNotify('Maksude tabelit ei leitud.', 'error'); return; }
                            vatcodes.forEach(function(vc) {
                                if (!vc.Code && !vc.Rate) return;
                                var uid = 'vat_' + Date.now() + '_' + Math.random().toString(36).substr(2, 5);
                                var rate = vc.Rate !== undefined ? vc.Rate : '';
                                var uuid = vc.Code || '';
                                var tr = document.createElement('tr');
                                tr.innerHTML =
                                    '<td><input type="number" name="'+taxKey+'['+uid+'][rate]" value="'+rate+'" style="width:70px" min="0" max="100"></td>'
                                    + '<td><input type="text" name="'+taxKey+'['+uid+'][uuid]" value="'+uuid+'" style="width:100%" placeholder="Merit UUID"></td>'
                                    + '<td><select name="'+taxKey+'['+uid+'][is_default]"><option value="">Ei</option><option value="yes">Jah</option></select></td>'
                                    + '<td><button type="button" onclick="this.closest(\'tr\').remove()" style="background:none;border:none;cursor:pointer;color:#b32d2e;font-size:16px" title="Kustuta">✕</button></td>';
                                tbody.appendChild(tr);
                            });
                            swiNotify(vatcodes.length + ' VAT koodi lisatud tabelisse.', 'ok');
                        }).fail(function() {
                            vatBtn.disabled = false;
                            if (spinner) spinner.style.display = 'none';
                            swiNotify('Serveri viga VAT koodide laadimisel.', 'error');
                        });
                    });
                }

                // Feature 6: Arve eelvaade
                var previewBtn = document.getElementById('swi-preview-btn');
                if (previewBtn) {
                    previewBtn.addEventListener('click', function() {
                        var orderId = document.getElementById('swi-preview-order-id').value;
                        var pre = document.getElementById('swi-preview-result');
                        if (!orderId) { swiNotify('Sisesta tellimuse ID.', 'error'); return; }
                        previewBtn.disabled = true;
                        previewBtn.textContent = 'Laen...';
                        jQuery.post(ajaxurl, {
                            action: 'swi_merit_preview_invoice',
                            security: MyAjax.nonce,
                            order_id: orderId
                        }, function(resp) {
                            previewBtn.disabled = false;
                            previewBtn.textContent = 'Näita JSON';
                            if (!resp.success) {
                                swiNotify((resp.data && resp.data.error) ? resp.data.error : 'Eelvaade ebaõnnestus.', 'error');
                                return;
                            }
                            pre.style.display = 'block';
                            pre.textContent = JSON.stringify(resp.data.payload, null, 2);
                        }).fail(function() {
                            previewBtn.disabled = false;
                            previewBtn.textContent = 'Näita JSON';
                            swiNotify('Serveri viga eelvaate laadimisel.', 'error');
                        });
                    });
                }

                // Feature 5: Eksport
                var exportBtn = document.getElementById('swi-export-btn');
                if (exportBtn) {
                    exportBtn.addEventListener('click', function() {
                        exportBtn.disabled = true;
                        jQuery.post(ajaxurl, {
                            action: 'swi_export_settings',
                            security: MyAjax.nonce
                        }, function(resp) {
                            exportBtn.disabled = false;
                            if (!resp.success) {
                                swiNotify((resp.data && resp.data.error) ? resp.data.error : 'Eksport ebaõnnestus.', 'error');
                                return;
                            }
                            var blob = new Blob([resp.data.json], {type: 'application/json'});
                            var url = URL.createObjectURL(blob);
                            var a = document.createElement('a');
                            a.href = url;
                            a.download = resp.data.filename;
                            document.body.appendChild(a);
                            a.click();
                            setTimeout(function(){ URL.revokeObjectURL(url); a.remove(); }, 1000);
                            swiNotify('Seaded eksporditud.', 'ok');
                        }).fail(function() {
                            exportBtn.disabled = false;
                            swiNotify('Serveri viga ekspordil.', 'error');
                        });
                    });
                }

                // Feature 5: Import
                var importBtn = document.getElementById('swi-import-btn');
                if (importBtn) {
                    importBtn.addEventListener('click', function() {
                        var fileInput = document.getElementById('swi-import-file');
                        var statusEl  = document.getElementById('swi-import-status');
                        if (!fileInput.files || !fileInput.files[0]) {
                            swiNotify('Vali esmalt JSON fail.', 'error');
                            return;
                        }
                        var reader = new FileReader();
                        reader.onload = function(e) {
                            var json = e.target.result;
                            importBtn.disabled = true;
                            if (statusEl) statusEl.textContent = 'Impordin...';
                            jQuery.post(ajaxurl, {
                                action: 'swi_import_settings',
                                security: MyAjax.nonce,
                                json: json
                            }, function(resp) {
                                importBtn.disabled = false;
                                if (resp.success) {
                                    if (statusEl) statusEl.textContent = resp.data.message || 'Seaded imporditud.';
                                    swiNotify('Seaded imporditud. Leht laetakse uuesti...', 'ok');
                                    setTimeout(function(){ window.location.reload(); }, 2000);
                                } else {
                                    if (statusEl) statusEl.textContent = '';
                                    swiNotify((resp.data && resp.data.error) ? resp.data.error : 'Import ebaõnnestus.', 'error');
                                }
                            }).fail(function() {
                                importBtn.disabled = false;
                                if (statusEl) statusEl.textContent = '';
                                swiNotify('Serveri viga impordil.', 'error');
                            });
                        };
                        reader.readAsText(fileInput.files[0]);
                    });
                }

                // Feature 7: Kustuta ajalugu
                var clearHistBtn = document.getElementById('swi-clear-history-btn');
                if (clearHistBtn) {
                    clearHistBtn.addEventListener('click', function() {
                        if (!confirm('Kustuta kogu saatmise ajalugu?')) return;
                        clearHistBtn.disabled = true;
                        jQuery.post(ajaxurl, {
                            action: 'swi_clear_history',
                            security: MyAjax.nonce
                        }, function(resp) {
                            if (resp.success) {
                                swiNotify('Ajalugu kustutatud.', 'ok');
                                setTimeout(function(){ window.location.reload(); }, 1000);
                            } else {
                                clearHistBtn.disabled = false;
                                swiNotify('Kustutamine ebaõnnestus.', 'error');
                            }
                        }).fail(function() {
                            clearHistBtn.disabled = false;
                            swiNotify('Serveri viga kustutamisel.', 'error');
                        });
                    });
                }
            });
            </script>
            <?php
        }

        /* ─── SAVE ─── */
        public function save() {
            // Text / URL fields
            foreach ( [
                'smart_wp_integration_server_url',
                'smart_wp_integtaion_license_text',
                'smart_wp_integtaion_crypto_text',
                'smart_wp_integtaion_arve_eesliides',
                'smart_wp_integtaion_maksetahtaeg',
                'swi_simplebooks_license_key',
                'swi_simplebooks_crypto_key',
                'swi_simplebooks_prefix',
                'swi_smartaccounts_license_key',
                'swi_smartaccounts_crypto_key',
                'swi_smartaccounts_prefix',
            ] as $field ) {
                if ( isset( $_POST[ $field ] ) ) {
                    update_option( $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
                }
            }

            // Select fields
            foreach ( [
                'smart_wp_integtaion_invoice_status',
                'smart_wp_integtaion_maksumaar',
                'smart_wp_integtaion_arve_ridade_tyyp',
                'smart_wp_integtaion_deparment',
                'swi_simplebooks_order_status',
                'swi_smartaccounts_order_status',
            ] as $field ) {
                if ( isset( $_POST[ $field ] ) ) {
                    update_option( $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
                }
            }

            // Toggles — hidden input sends 'yes' or 'no' explicitly
            foreach ( [
                'smart_wp_integtaion_enable',
                'swi_simplebooks_enable',
                'swi_smartaccounts_enable',
                'swi_merit_email_notify',
            ] as $field ) {
                $val = sanitize_text_field( wp_unslash( $_POST[ $field ] ?? 'no' ) );
                update_option( $field, $val === 'yes' ? 'yes' : 'no' );
            }

            // Feature 9: Kategooria → osakond kaardistus
            $map_cats  = array_values(isset($_POST['swi_dept_map_cat'])  ? (array)$_POST['swi_dept_map_cat']  : []);
            $map_depts = array_values(isset($_POST['swi_dept_map_dept']) ? (array)$_POST['swi_dept_map_dept'] : []);
            $dept_map_clean = [];
            foreach ($map_cats as $i => $cat_slug) {
                $k = sanitize_text_field(wp_unslash((string)$cat_slug));
                $v = sanitize_text_field(wp_unslash((string)($map_depts[$i] ?? '')));
                if ($k !== '' && $v !== '') {
                    $dept_map_clean[$k] = $v;
                }
            }
            update_option('swi_category_dept_map', $dept_map_clean, false);

            // Maps (country, payment, tax, shipping)
            foreach ( [
                $this->opt_payment_map  => isset($_POST[$this->opt_payment_map])  ? (array)$_POST[$this->opt_payment_map]  : [],
                $this->opt_shipping_map => isset($_POST[$this->opt_shipping_map]) ? (array)$_POST[$this->opt_shipping_map] : [],
                $this->opt_tax_map      => isset($_POST[$this->opt_tax_map])      ? (array)$_POST[$this->opt_tax_map]      : [],
                $this->opt_country_map  => isset($_POST[$this->opt_country_map])  ? (array)$_POST[$this->opt_country_map]  : [],
            ] as $key => $val ) {
                $clean = $this->deep_sanitize( $val );
                $clean = array_filter( $clean, function( $row ) {
                    if ( is_array( $row ) ) { foreach ( $row as $v ) { if ( (string)$v !== '' ) return true; } return false; }
                    return (string)$row !== '';
                } );
                update_option( $key, $clean, false );
            }
        }

        /* ─── FEATURE 5: EXPORT/IMPORT ─── */

        /**
         * Kõik plugina option keyd mida eksportida/importida.
         */
        private function all_option_keys(): array {
            return [
                'smart_wp_integtaion_license_text',
                'smart_wp_integtaion_crypto_text',
                'smart_wp_integtaion_arve_eesliides',
                'smart_wp_integtaion_maksetahtaeg',
                'smart_wp_integtaion_invoice_status',
                'smart_wp_integtaion_maksumaar',
                'smart_wp_integtaion_arve_ridade_tyyp',
                'smart_wp_integtaion_deparment',
                'smart_wp_integtaion_enable',
                'smart_wp_integtaion_payment_map',
                'smart_wp_integtaion_shipping_map',
                'smart_wp_integtaion_tax_map',
                'smart_wp_integtaion_country_map',
                'swi_simplebooks_enable',
                'swi_simplebooks_license_key',
                'swi_simplebooks_crypto_key',
                'swi_simplebooks_order_status',
                'swi_simplebooks_prefix',
                'swi_smartaccounts_enable',
                'swi_smartaccounts_license_key',
                'swi_smartaccounts_crypto_key',
                'swi_smartaccounts_order_status',
                'swi_smartaccounts_prefix',
                'smart_wp_integration_server_url',
                'regno',
                'swi_merit_email_notify',
                'swi_category_dept_map',
            ];
        }

        public function handle_export_settings(): void {
            check_ajax_referer( 'my_nonce', 'security' );
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
            }
            $data = [];
            foreach ( $this->all_option_keys() as $key ) {
                $data[ $key ] = get_option( $key );
            }
            $json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
            wp_send_json_success( [
                'json'     => $json,
                'filename' => 'swi-settings-' . gmdate( 'Y-m-d' ) . '.json',
            ] );
        }

        public function handle_import_settings(): void {
            check_ajax_referer( 'my_nonce', 'security' );
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                wp_send_json_error( [ 'error' => 'Puuduvad õigused.' ] );
            }
            $json = isset( $_POST['json'] ) ? wp_unslash( $_POST['json'] ) : '';
            if ( empty( $json ) ) {
                wp_send_json_error( [ 'error' => 'JSON puudub.' ] );
            }
            $data = json_decode( $json, true );
            if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
                wp_send_json_error( [ 'error' => 'Vigane JSON formaat.' ] );
            }
            $allowed = array_flip( $this->all_option_keys() );
            foreach ( $data as $key => $value ) {
                if ( isset( $allowed[ $key ] ) ) {
                    update_option( $key, $value );
                }
            }
            wp_send_json_success( [ 'message' => 'Seaded imporditud.' ] );
        }

        private function render_toggle( string $name, string $label, bool $on ): void {
            $id = esc_attr( $name );
            ?>
            <table class="form-table" style="margin-bottom:4px;">
                <tr>
                    <th style="width:230px;padding:10px 0;font-size:12.5px;font-weight:600;color:#374151;"><?php echo esc_html($label); ?></th>
                    <td style="padding:8px 0;">
                        <input type="hidden" name="<?php echo $id; ?>" id="swi_h_<?php echo $id; ?>" value="<?php echo $on ? 'yes' : 'no'; ?>">
                        <div class="swi-toggle-wrap" onclick="swiToggle('<?php echo $id; ?>')" style="cursor:pointer;">
                            <div id="swi_t_<?php echo $id; ?>" class="swi-toggle-track<?php echo $on ? ' on' : ''; ?>">
                                <div class="swi-toggle-thumb"></div>
                            </div>
                            <span id="swi_l_<?php echo $id; ?>" class="swi-toggle-label"><?php echo $on ? 'Lubatud' : 'Keelatud'; ?></span>
                        </div>
                    </td>
                </tr>
            </table>
            <?php
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
            if (empty($stored)) $stored = [uniqid('r_')=>['country'=>'','vat_code'=>'','name'=>'','default'=>'']];
            foreach ($stored as $rk => $row) {
                echo '<tr><td><select name="'.esc_attr($key).'['.esc_attr($rk).'][country]"><option value="">— riik —</option>';
                foreach ($countries->get_countries() as $code => $label)
                    printf('<option value="%s" %s>%s</option>',esc_attr($code),selected(($row['country']??'')===$code,true,false),esc_html($label));
                echo '</select></td>';
                printf('<td><input type="text" name="%1$s[%2$s][vat_code]" value="%3$s"></td>',esc_attr($key),esc_attr($rk),esc_attr($row['vat_code']??''));
                printf('<td><input type="text" name="%1$s[%2$s][name]" value="%3$s"></td>',esc_attr($key),esc_attr($rk),esc_attr($row['name']??''));
                echo '<td><select name="'.esc_attr($key).'['.esc_attr($rk).'][default]"><option value="">Ei</option><option value="yes" '.selected(($row['default']??'')==='yes',true,false).'>Jah</option></select></td></tr>';
            }
            $new = uniqid('n_');
            echo '<tr><td><select name="'.esc_attr($key).'['.esc_attr($new).'][country]"><option value="">— lisa uus —</option>';
            foreach ($countries->get_countries() as $code => $label) printf('<option value="%s">%s</option>',esc_attr($code),esc_html($label));
            echo '</select></td>';
            printf('<td><input type="text" name="%1$s[%2$s][vat_code]" value=""></td>',esc_attr($key),esc_attr($new));
            printf('<td><input type="text" name="%1$s[%2$s][name]" value=""></td>',esc_attr($key),esc_attr($new));
            echo '<td><select name="'.esc_attr($key).'['.esc_attr($new).'][default]"><option value="">Ei</option><option value="yes">Jah</option></select></td></tr>';
            echo '</tbody></table>';
        }

        public function field_payment_map( $value ) {
            $key = $value['id']; $stored = (array)get_option($key,[]);
            $methods = [];
            if (function_exists('WC') && WC()->payment_gateways())
                foreach ((array)WC()->payment_gateways()->get_available_payment_gateways() as $id => $g) $methods[$id] = $g->get_title();
            foreach (['bacs'=>'Pangaülekanne','cheque'=>'Tšekk','cod'=>'Sularaha','montonio_bank'=>'Montonio – pank','card_payment'=>'Kaardimakse'] as $k=>$v)
                if (!isset($methods[$k])) $methods[$k] = $v;
            echo '<table class="widefat" style="margin:0;"><thead><tr><th>Maksemeetod</th><th>Merit konto/kood</th></tr></thead><tbody>';
            foreach ($methods as $id=>$title)
                printf('<tr><td>%s <code style="opacity:.6;font-size:.85em">(%s)</code></td><td><input type="text" name="%s[%s]" value="%s" placeholder="nt 1000"></td></tr>',
                    esc_html($title),esc_html($id),esc_attr($key),esc_attr($id),esc_attr($stored[$id]??''));
            echo '</tbody></table>';
        }

        public function field_tax_map( $value ) {
            $key    = $value['id'];
            $stored = (array) get_option( $key, [
                'r24' => [ 'rate' => '24', 'uuid' => '1e420e04-3dd7-46a5-b71f-0490779c2638', 'is_default' => '' ],
                'r9'  => [ 'rate' => '9',  'uuid' => '6b618baa-680b-4606-9ad9-eff0beb27344', 'is_default' => '' ],
                'r0'  => [ 'rate' => '0',  'uuid' => '973a4395-665f-47a6-a5b6-5384dd24f8d0', 'is_default' => 'yes' ],
            ] );
            echo '<p style="margin:0 0 8px;color:#666;font-size:12px;">Seo WooCommerce käibemaksumäär (%) Merit UUID-ga. Vaikimisi kasutatakse kui toote määr kaardistusest puudub.</p>';
            echo '<table class="widefat" style="margin:0;"><thead><tr><th style="width:80px">Määr (%)</th><th>Merit VAT UUID</th><th style="width:90px">Vaikimisi?</th><th style="width:40px"></th></tr></thead><tbody>';
            foreach ( $stored as $k => $row ) {
                if ( str_starts_with( (string) $k, '_' ) ) continue;
                $rk = esc_attr( $k );
                printf(
                    '<tr><td><input type="number" name="%1$s[%2$s][rate]" value="%3$s" style="width:70px" min="0" max="100"></td>'
                    . '<td><input type="text" name="%1$s[%2$s][uuid]" value="%4$s" style="width:100%%" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"></td>'
                    . '<td><select name="%1$s[%2$s][is_default]"><option value="">Ei</option><option value="yes" %5$s>Jah</option></select></td>'
                    . '<td><button type="button" onclick="this.closest(\'tr\').remove()" style="background:none;border:none;cursor:pointer;color:#b32d2e;font-size:16px" title="Kustuta">✕</button></td></tr>',
                    esc_attr( $key ), $rk,
                    esc_attr( $row['rate'] ?? '' ),
                    esc_attr( $row['uuid'] ?? '' ),
                    selected( ( $row['is_default'] ?? '' ) === 'yes', true, false )
                );
            }
            $new = uniqid( 'r_' );
            printf(
                '<tr><td><input type="number" name="%1$s[%2$s][rate]" value="" style="width:70px" min="0" max="100" placeholder="nt 24"></td>'
                . '<td><input type="text" name="%1$s[%2$s][uuid]" value="" style="width:100%%" placeholder="Merit UUID"></td>'
                . '<td><select name="%1$s[%2$s][is_default]"><option value="">Ei</option><option value="yes">Jah</option></select></td>'
                . '<td></td></tr>',
                esc_attr( $key ), esc_attr( $new )
            );
            echo '</tbody></table>';
        }

        public function field_shipping_map( $value ) {
            $key = $value['id']; $stored = (array)get_option($key,[]);
            $services = [
                'montonio_smartpost_parcel_machines'=>'Montonio – Smartpost pakiautomaat',
                'montonio_omniva_parcel_machines'  =>'Montonio – Omniva pakiautomaat',
                'montonio_omniva_courier'          =>'Montonio – Omniva kuller',
                'montonio_dpd_parcel_machines'     =>'Montonio – DPD pakiautomaat',
                'montonio_dpd_courier'             =>'Montonio – DPD kuller',
                'montonio_venipak_courier'         =>'Montonio – Venipak kuller',
                'itella_smartpost'                 =>'Smartpost',
                'itella_omniva_parcel'             =>'Omniva pakk',
                'itella_omniva'                    =>'Omniva',
                'itella_venipak'                   =>'Venipak',
                'itella_dpd_parcel'                =>'DPD pakk',
                'pickup_location'                  =>'Kohapeal järeletulek',
            ];
            echo '<table class="widefat" style="margin:0;"><thead><tr><th>Teenus</th><th>Merit artikli kood</th></tr></thead><tbody>';
            foreach ($services as $slug=>$title)
                printf('<tr><td>%s<br><code style="opacity:.55;font-size:.85em">%s</code></td><td><input type="text" name="%s[%s]" value="%s" placeholder="nt TRANSPORT_01"></td></tr>',
                    esc_html($title),esc_html($slug),esc_attr($key),esc_attr($slug),esc_attr($stored[$slug]??''));
            echo '</tbody></table>';
        }
    }

    $settings[] = new WC_Settings_Smart_WP_Integration();
    return $settings;
});
