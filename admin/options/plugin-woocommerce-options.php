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
            $client = new MeritServersDataClient();
            $depts  = []; try { $depts = (array)$client->getDepartments(); } catch (\Exception $e) {}
            $dopts  = [ '' => '— vali osakond —' ];
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
                [ 'name' => 'Osakond', 'type' => 'select', 'id' => 'smart_wp_integtaion_deparment', 'options' => $dopts, 'default' => '' ],
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
                        <button type="submit" name="save" value="Save changes" class="swi-save-btn">Salvesta</button>
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
                            ['key'=>'merit-general',   'icon'=>'⚙',  'label'=>'Üldseaded'],
                            ['key'=>'merit-countries', 'icon'=>'🌍', 'label'=>'Riigid'],
                            ['key'=>'merit-payments',  'icon'=>'💳', 'label'=>'Maksed'],
                            ['key'=>'merit-taxes',     'icon'=>'📋', 'label'=>'Maksud'],
                            ['key'=>'merit-shipping',  'icon'=>'🚚', 'label'=>'Tarne'],
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
                            <div class="swi-section-title">Vaheserveri ühendus</div>
                            <div class="swi-section-desc">Kehtib kõigile süsteemidele. Kopeeri võtmed Laravel rakenduse litsentsi lehelt.</div>
                            <div class="swi-card" style="max-width:860px; margin-bottom:28px;">
                                <?php woocommerce_admin_fields( $conn_s ); ?>
                            </div>
                            <div class="swi-section-title">Merit Aktiva – Üldseaded</div>
                            <div class="swi-section-desc">Merit Aktiva API võtmed (API ID + API Key) seadista Laravel rakenduses jaotises <em>Litsentsid → Seadista → Merit Aktiva</em>.</div>
                            <div class="swi-card">
                                <?php $this->render_toggle('smart_wp_integtaion_enable', 'Luba Merit Aktiva', $merit_on); ?>
                                <?php woocommerce_admin_fields($merit_s); ?>
                            </div>
                            <div class="swi-section-title" style="margin-top:24px;">Sünkroniseerimine</div>
                            <div class="swi-section-desc">Võrdle WooCommerce tellimusi Merit Aktiva arvetega. Puuduvaid arveid saad siit uuesti saata.</div>
                            <div class="swi-card" style="max-width:860px;">
                                <button type="button" id="swi-sync-btn" class="button button-secondary">Kontrolli sünkroniseerimist</button>
                                <span id="swi-sync-spinner" style="display:none;margin-left:10px;">Laen...</span>
                                <div id="swi-sync-result" style="margin-top:16px;"></div>
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
                            <div class="swi-card"><?php $this->field_tax_map(['id'=>$this->opt_tax_map]); ?></div>
                        </div>
                        <div class="swi-panel" id="swi-panel-merit-shipping">
                            <div class="swi-section-title">Merit Aktiva – Tarnemeetodite kaardistus</div>
                            <div class="swi-section-desc">Seo tarne-teenused Merit Aktiva artikli koodidega.</div>
                            <div class="swi-card"><?php $this->field_shipping_map(['id'=>$this->opt_shipping_map]); ?></div>
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
                                        var friendly = raw
                                            .replace('Korduv arve number.', 'Arve on Meriti juba olemas — kustuta see Meritist enne uuesti saatmist.')
                                            .replace('Ridade summa ei võrdu arve summaga.', 'Arve summa ei klapi — kontrolli kaupade hindu WooCommerce-is.')
                                            .trim() || 'Merit Aktiva tagastas vea. Vaata logifaili täpsema info saamiseks.';
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
            ] as $field ) {
                $val = sanitize_text_field( wp_unslash( $_POST[ $field ] ?? 'no' ) );
                update_option( $field, $val === 'yes' ? 'yes' : 'no' );
            }

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
            $key = $value['id'];
            $stored = (array)get_option($key,['std20'=>['id'=>'20','rate'=>'20','name'=>'20%','is_default'=>''],'zero'=>['id'=>'0','rate'=>'0','name'=>'0%','is_default'=>'yes']]);
            echo '<table class="widefat" style="margin:0;"><thead><tr><th>Maksu ID</th><th>%</th><th>Nimetus</th><th>Vaikimisi?</th></tr></thead><tbody>';
            foreach ($stored as $k => $row) {
                if (str_starts_with((string)$k,'_')) continue;
                $rk = esc_attr($k);
                echo '<tr>';
                printf('<td><input type="text" name="%1$s[%2$s][id]" value="%3$s"></td>',esc_attr($key),$rk,esc_attr($row['id']??''));
                printf('<td><input type="text" name="%1$s[%2$s][rate]" value="%3$s"></td>',esc_attr($key),$rk,esc_attr($row['rate']??''));
                printf('<td><input type="text" name="%1$s[%2$s][name]" value="%3$s"></td>',esc_attr($key),$rk,esc_attr($row['name']??''));
                echo '<td><select name="'.esc_attr($key).'['.$rk.'][is_default]"><option value="">Ei</option><option value="yes" '.selected(($row['is_default']??'')==='yes',true,false).'>Jah</option></select></td></tr>';
            }
            $new = uniqid('n_');
            echo '<tr>';
            printf('<td><input type="text" name="%1$s[%2$s][id]" value=""></td>',esc_attr($key),esc_attr($new));
            printf('<td><input type="text" name="%1$s[%2$s][rate]" value=""></td>',esc_attr($key),esc_attr($new));
            printf('<td><input type="text" name="%1$s[%2$s][name]" value=""></td>',esc_attr($key),esc_attr($new));
            echo '<td><select name="'.esc_attr($key).'['.esc_attr($new).'][is_default]"><option value="">Ei</option><option value="yes">Jah</option></select></td></tr>';
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
