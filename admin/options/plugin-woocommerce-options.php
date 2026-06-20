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
                $this->s_erply( $s ),
                $this->s_standard_books( $s ),
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
                [ 'name' => 'Arve eesliides',             'type' => 'text',     'id' => 'swi_simplebooks_prefix',        'default' => 'SB' ],
                [ 'name' => 'Saada tellimused staatuses', 'type' => 'select',   'id' => 'swi_simplebooks_order_status',  'options' => $s, 'default' => 'wc-completed' ],
                [ 'name' => 'Maksetähtaeg (päevades)',    'type' => 'number',   'id' => 'swi_simplebooks_payment_days',  'default' => '14', 'desc' => 'Mitu päeva on kliendil arve tasumiseks' ],
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
                [ 'name' => 'Arve eesliides',             'type' => 'text',     'id' => 'swi_smartaccounts_prefix',        'default' => 'SA' ],
                [ 'name' => 'Saada tellimused staatuses', 'type' => 'select',   'id' => 'swi_smartaccounts_order_status',  'options' => $s, 'default' => 'wc-completed' ],
                [ 'name' => 'Maksetähtaeg (päeva)',       'type' => 'number',   'id' => 'swi_smartaccounts_payment_days',  'default' => '14', 'desc' => 'päeva alates arve kuupäevast' ],
                [ 'type' => 'sectionend', 'id' => 'swi_sa' ],
            ];
        }

        private function s_erply( array $s ): array {
            return [
                [ 'type' => 'title', 'id' => 'swi_erply_conn' ],
                [ 'name' => 'Litsentsi võti',   'type' => 'text', 'id' => 'swi_erply_license_key', 'default' => '', 'desc' => 'Erply litsentsi võti — kopeeri rakenduse litsentsi lehelt' ],
                [ 'name' => 'Krüptovõti (HEX)', 'type' => 'text', 'id' => 'swi_erply_crypto_key',  'default' => '', 'desc' => '64-märgiline HEX — kopeeri rakenduse litsentsi lehelt' ],
                [ 'type' => 'sectionend', 'id' => 'swi_erply_conn' ],
                [ 'type' => 'title', 'id' => 'swi_erply' ],
                [ 'name' => 'Saada tellimused staatuses', 'type' => 'select', 'id' => 'swi_erply_order_status', 'options' => $s, 'default' => 'wc-completed' ],
                [ 'type' => 'sectionend', 'id' => 'swi_erply' ],
            ];
        }

        private function s_standard_books( array $s ): array {
            return [
                [ 'type' => 'title', 'id' => 'swi_stdb_conn' ],
                [ 'name' => 'Litsentsi võti',   'type' => 'text', 'id' => 'swi_stdb_license_key', 'default' => '', 'desc' => 'Standard Books litsentsi võti — kopeeri rakenduse litsentsi lehelt' ],
                [ 'name' => 'Krüptovõti (HEX)', 'type' => 'text', 'id' => 'swi_stdb_crypto_key',  'default' => '', 'desc' => '64-märgiline HEX — kopeeri rakenduse litsentsi lehelt' ],
                [ 'type' => 'sectionend', 'id' => 'swi_stdb_conn' ],
                [ 'type' => 'title', 'id' => 'swi_stdb' ],
                [ 'name' => 'Saada tellimused staatuses', 'type' => 'select', 'id' => 'swi_stdb_order_status', 'options' => $s, 'default' => 'wc-completed' ],
                [ 'type' => 'sectionend', 'id' => 'swi_stdb' ],
            ];
        }

        /* ─── OUTPUT ─── */
        public function output() {
            $s              = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
            $conn_s         = $this->s_connection();
            $merit_s        = $this->s_merit( $s );
            $sb_s           = $this->s_simplebooks( $s );
            $sa_s           = $this->s_smartaccounts( $s );
            $erply_s        = $this->s_erply( $s );
            $stdb_s         = $this->s_standard_books( $s );
            $merit_on       = get_option('smart_wp_integtaion_enable')    === 'yes';
            $sb_on          = get_option('swi_simplebooks_enable')         === 'yes';
            $sa_on          = get_option('swi_smartaccounts_enable')       === 'yes';
            $erply_on       = get_option('swi_erply_enable')               === 'yes';
            $stdb_on        = get_option('swi_stdb_enable')                === 'yes';
            $rik_on         = get_option('swi_rik_enable')                 === 'yes';
            $smartpost_on   = get_option('swi_smartpost_enable')           === 'yes';

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
                .swi-tabs-wrap {
                    background: var(--swi-dark);
                    border-bottom: 1px solid #1f2937;
                    flex-shrink: 0;
                }
                .swi-tabs-row {
                    display: flex; align-items: center;
                    padding: 0 12px;
                    height: 40px;
                }
                .swi-tabs-row + .swi-tabs-row {
                    border-top: 1px solid #1f2937;
                }
                .swi-tabs-label {
                    display: flex; align-items: center;
                    padding: 0 16px 0 4px;
                    font-size: 10px; font-weight: 700; letter-spacing: .08em;
                    text-transform: uppercase; color: #4b5563;
                    white-space: nowrap; flex-shrink: 0;
                    border-right: 1px solid #1f2937; margin-right: 4px;
                    height: 100%;
                }
                .swi-tab {
                    display: flex; align-items: center; gap: 6px;
                    padding: 0 13px; height: 100%;
                    font-size: 12px; font-weight: 500;
                    color: #9ca3af; cursor: pointer;
                    border-bottom: 2px solid transparent;
                    transition: color .12s, border-color .12s;
                    white-space: nowrap; user-select: none;
                    flex-shrink: 0;
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
                .swi-card { background:#fff; border:1px solid var(--swi-border); border-radius:10px; padding:22px 28px; margin-bottom:18px; max-width:980px; }

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
                            onclick="window.onbeforeunload=null;jQuery(window).off('beforeunload beforeunload.admin_settings beforeunload.wc_settings');">Salvesta</button>
                    </div>
                </div>

                <!-- TOP TABS -->
                <div class="swi-tabs-wrap">
                    <!-- Rida 1 -->
                    <div class="swi-tabs-row">
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
                        <div class="swi-tab" data-tab="erply" onclick="swiTab('erply', this)">
                            🛒 Erply
                            <span class="swi-tab-badge <?php echo $erply_on ? 'on' : 'off'; ?>"><?php echo $erply_on ? 'aktiivne' : 'väljas'; ?></span>
                        </div>
                        <div class="swi-tab" data-tab="stdb" onclick="swiTab('stdb', this)">
                            📚 Standard Books
                            <span class="swi-tab-badge <?php echo $stdb_on ? 'on' : 'off'; ?>"><?php echo $stdb_on ? 'aktiivne' : 'väljas'; ?></span>
                        </div>
                        <div class="swi-tab" data-tab="rik" onclick="swiTab('rik', this)">
                            🏢 Äriregistri moodul
                            <span class="swi-tab-badge <?php echo $rik_on ? 'on' : 'off'; ?>"><?php echo $rik_on ? 'aktiivne' : 'väljas'; ?></span>
                        </div>
                    </div>
                    <!-- Rida 2 -->
                    <div class="swi-tabs-row">
                        <div class="swi-tab" data-tab="smartpost" onclick="swiTab('smartpost', this)">
                            📦 Smartpost
                            <span class="swi-tab-badge <?php echo $smartpost_on ? 'on' : 'off'; ?>"><?php echo $smartpost_on ? 'aktiivne' : 'väljas'; ?></span>
                        </div>
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
                            <div class="swi-card" style="margin-bottom:28px;">
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
                            <div class="swi-card">
                                <button type="button" id="swi-sync-btn" class="button button-secondary">Kontrolli sünkroniseerimist</button>
                                <span id="swi-sync-spinner" style="display:none;margin-left:10px;">Laen...</span>
                                <div id="swi-sync-result" style="margin-top:16px;"></div>
                            </div>
                            <?php // Feature 6: Arve eelvaade ?>
                            <div class="swi-section-title" style="margin-top:24px;">Arve eelvaade</div>
                            <div class="swi-section-desc">Kontrolli mis andmed Meriti lähevad enne päris saatmist.</div>
                            <div class="swi-card">
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
                            <div class="swi-card">
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
                            $client2      = new MeritServersDataClient();
                            $depts2       = [];
                            $dept_api_err = '';
                            try { $depts2 = (array) $client2->getDepartments(); } catch (\Exception $e) { $dept_api_err = $e->getMessage(); }
                            $saved_dept = get_option('smart_wp_integtaion_deparment', '');
                            if ($saved_dept && !in_array($saved_dept, $depts2, true)) $depts2[] = $saved_dept;
                            ?>
                            <div class="swi-card">
                                <?php if (empty($depts2) && !$dept_api_err) : ?>
                                <div style="background:#fef9c3;border:1px solid #fde68a;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:12.5px;color:#92400e;">
                                    Merit Aktivas pole ühtegi osakonda loodud. Osakondi saad luua Merit Aktiva veebikeskkonnas: <strong>Seaded → Osakonnad</strong>. Sisesta osakonna kood käsitsi allpool.
                                </div>
                                <?php elseif ($dept_api_err) : ?>
                                <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:12.5px;color:#991b1b;">
                                    Merit API ei vastanud: <?php echo esc_html($dept_api_err); ?>
                                </div>
                                <?php endif; ?>
                                <table class="form-table" style="margin-bottom:0;">
                                    <tr>
                                        <th style="width:230px;padding:10px 0;font-size:12.5px;font-weight:600;color:#374151;">Vaikimisi osakond</th>
                                        <td style="padding:8px 0;">
                                            <?php if (!empty($depts2)) : ?>
                                            <select name="smart_wp_integtaion_deparment" style="min-width:300px;">
                                                <option value="">— kasuta vaikimisi (tühi) —</option>
                                                <?php foreach ($depts2 as $dc) : ?>
                                                <option value="<?php echo esc_attr($dc); ?>" <?php selected($saved_dept, $dc); ?>><?php echo esc_html($dc); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php else : ?>
                                            <input type="text" name="smart_wp_integtaion_deparment"
                                                value="<?php echo esc_attr($saved_dept); ?>"
                                                placeholder="nt. MYYK"
                                                style="min-width:300px;"
                                                class="regular-text">
                                            <p style="margin:4px 0 0;font-size:11px;color:#6b7280;">Sisesta osakonna kood täpselt nii nagu see Merit Aktivas on (tõstutundlik).</p>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <?php
                            $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
                            $dept_map_saved = (array) get_option('swi_category_dept_map', []);
                            ?>
                            <div class="swi-card">
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
                                            <?php if (!empty($depts2)) : ?>
                                            <select name="swi_dept_map_dept[]" style="width:100%;">
                                                <option value="">— kasuta vaikimisi —</option>
                                                <?php foreach ($depts2 as $dc) : ?>
                                                <option value="<?php echo esc_attr($dc); ?>" <?php selected($dept_val, $dc); ?>><?php echo esc_html($dc); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php else : ?>
                                            <input type="text" name="swi_dept_map_dept[]" value="<?php echo esc_attr($dept_val); ?>" placeholder="nt. MYYK" style="width:100%;">
                                            <?php endif; ?>
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
                                    var deptCell = depts.length
                                        ? '<select name="swi_dept_map_dept[]" style="width:100%;"><option value="">— kasuta vaikimisi —</option>' + depts.map(function(d){ return '<option value="'+d+'">'+d+'</option>'; }).join('') + '</select>'
                                        : '<input type="text" name="swi_dept_map_dept[]" placeholder="nt. MYYK" style="width:100%;">';
                                    var tr = document.createElement('tr');
                                    tr.innerHTML = '<td><select name="swi_dept_map_cat[]" style="width:100%;">'+catOpts+'</select></td>'
                                        + '<td>' + deptCell + '</td>'
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
                            <div class="swi-card">
                                <p style="margin:0 0 8px;font-weight:600;font-size:12.5px;">Seadistuste eksport</p>
                                <p style="margin:0 0 12px;font-size:12px;color:#6b7280;">Lae alla kõik seadistused JSON failina.</p>
                                <button type="button" id="swi-export-btn" class="button button-secondary">Ekspordi seaded</button>
                            </div>

                            <?php // Feature 5: Import ?>
                            <div class="swi-card">
                                <p style="margin:0 0 8px;font-weight:600;font-size:12.5px;">Seadistuste import</p>
                                <p style="margin:0 0 12px;font-size:12px;color:#6b7280;">Lae üles eelnevalt eksporditud JSON fail.</p>
                                <input type="file" id="swi-import-file" accept=".json" style="margin-right:8px;">
                                <button type="button" id="swi-import-btn" class="button button-secondary">Impordi seaded</button>
                                <span id="swi-import-status" style="margin-left:10px;font-size:12px;"></span>
                            </div>

                            <?php // Feature 7: Saatmise ajalugu ?>
                            <div class="swi-section-title" style="margin-top:24px;">Saatmise ajalugu</div>
                            <div class="swi-section-desc">Viimased 50 saatmiskatset.</div>
                            <div class="swi-card">
                                <?php if (empty($history)) : ?>
                                <p style="color:#9ca3af;font-size:12.5px;margin:0;">Saatmisi pole veel toimunud.</p>
                                <?php else : ?>
                                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                                    <button type="button" id="swi-clear-history-btn" class="button button-small" style="color:#b32d2e;">Tühista ajalugu</button>
                                    <span style="font-size:12px;color:#6b7280;" id="swi-history-count"><?php echo count($history); ?> kirjet</span>
                                </div>
                                <table class="widefat striped" id="swi-history-table">
                                    <thead><tr>
                                        <th>Aeg</th>
                                        <th>Tellimus</th>
                                        <th>Staatus</th>
                                        <th>Sõnum</th>
                                    </tr></thead>
                                    <tbody>
                                    <?php foreach ($history as $i => $entry) :
                                        $entry_status = $entry['status'] ?? '';
                                        $color = $entry_status === 'ok' ? '#14532d' : '#991b1b';
                                        $bg    = $entry_status === 'ok' ? '#dcfce7' : '#fee2e2';
                                        $raw_msg = $entry['message'] ?? '';
                                        if (str_starts_with(trim($raw_msg), '{')) {
                                            $decoded = json_decode($raw_msg, true);
                                            if (is_array($decoded) && function_exists('swi_humanize_merit_error')) {
                                                $raw_msg = swi_humanize_merit_error($decoded);
                                            }
                                        }
                                    ?>
                                    <tr data-row="<?php echo $i; ?>">
                                        <td style="font-size:12px;"><?php echo esc_html($entry['time'] ?? ''); ?></td>
                                        <td><a href="<?php echo esc_url(admin_url('post.php?post=' . intval($entry['order_id'] ?? 0) . '&action=edit')); ?>" target="_blank">#<?php echo intval($entry['order_id'] ?? 0); ?></a></td>
                                        <td><span style="padding:2px 8px;border-radius:10px;font-size:11px;background:<?php echo $bg; ?>;color:<?php echo $color; ?>;"><?php echo esc_html($entry_status); ?></span></td>
                                        <td style="font-size:12px;"><?php echo esc_html($raw_msg); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <div id="swi-history-pagination" style="display:flex;align-items:center;gap:4px;margin-top:12px;flex-wrap:wrap;"></div>
                                <script>
                                (function(){
                                    var PER_PAGE = 10;
                                    var rows = document.querySelectorAll('#swi-history-table tbody tr');
                                    var total = rows.length;
                                    var pages = Math.ceil(total / PER_PAGE);
                                    if (pages <= 1) return;
                                    var current = 1;
                                    function showPage(p) {
                                        current = p;
                                        rows.forEach(function(tr, i) {
                                            tr.style.display = (i >= (p-1)*PER_PAGE && i < p*PER_PAGE) ? '' : 'none';
                                        });
                                        renderPager();
                                    }
                                    function renderPager() {
                                        var nav = document.getElementById('swi-history-pagination');
                                        nav.innerHTML = '';
                                        for (var i = 1; i <= pages; i++) {
                                            var btn = document.createElement('button');
                                            btn.type = 'button';
                                            btn.textContent = i;
                                            btn.style.cssText = 'min-width:32px;padding:3px 8px;border-radius:4px;border:1px solid #d1d5db;cursor:pointer;font-size:12px;'
                                                + (i === current ? 'background:#2563eb;color:#fff;border-color:#2563eb;' : 'background:#fff;color:#374151;');
                                            (function(page){ btn.addEventListener('click', function(){ showPage(page); }); })(i);
                                            nav.appendChild(btn);
                                        }
                                    }
                                    showPage(1);
                                })();
                                </script>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ══ TAB: SIMPLEBOOKS ══ -->
                <div class="swi-tabview" id="swi-tab-simplebooks">
                    <nav class="swi-sidebar">
                        <div class="swi-sidebar-label">Simplebooks</div>
                        <?php
                        $sb_nav = [
                            ['key'=>'sb-general',  'icon'=>'⚙',  'label'=>'Üldseaded'],
                            ['key'=>'sb-history',  'icon'=>'📋', 'label'=>'Saatmise ajalugu'],
                            ['key'=>'sb-sync',     'icon'=>'🔄', 'label'=>'Sünkroniseerimine'],
                            ['key'=>'sb-tools',    'icon'=>'🔧', 'label'=>'Tööriistad'],
                        ];
                        foreach ($sb_nav as $i => $item) : ?>
                        <div class="swi-nav-item <?php echo $i===0?'active':''; ?>"
                             data-panel="<?php echo esc_attr($item['key']); ?>"
                             onclick="swiPanel('<?php echo esc_js($item['key']); ?>', this, 'simplebooks')">
                            <span class="swi-nav-icon"><?php echo $item['icon']; ?></span>
                            <?php echo esc_html($item['label']); ?>
                        </div>
                        <?php endforeach; ?>
                    </nav>
                    <div class="swi-content">

                        <!-- ── Üldseaded ── -->
                        <div class="swi-panel active" id="swi-panel-sb-general">
                            <?php if ( isset($_GET['settings-updated']) ) : ?>
                            <div class="swi-alert ok">✓ <div><strong>Seaded on salvestatud.</strong></div></div>
                            <?php endif; ?>
                            <?php if ($this->proxy_error) : ?>
                            <div class="swi-alert err">⚠ <div><strong>Vaheserver ei ole kättesaadav.</strong><br><?php echo esc_html($this->proxy_error); ?></div></div>
                            <?php endif; ?>
                            <div class="swi-section-title">Vaheserveri ühendus</div>
                            <div class="swi-section-desc">Kehtib kõigile süsteemidele. Kopeeri võtmed Laravel rakenduse litsentsi lehelt.</div>
                            <div class="swi-card" style="margin-bottom:28px;">
                                <?php woocommerce_admin_fields( $conn_s ); ?>
                            </div>
                            <div class="swi-section-title">Simplebooks – Üldseaded</div>
                            <div class="swi-section-desc">Simplebooks API võti seadista Laravel rakenduses jaotises <em>Litsentsid → Seadista → Simplebooks</em>. Plugin edastab tellimused automaatselt vaheserveri kaudu.</div>
                            <div class="swi-card">
                                <?php $this->render_toggle('swi_simplebooks_enable', 'Luba Simplebooks', $sb_on); ?>
                                <?php woocommerce_admin_fields($sb_s); ?>
                            </div>
                            <div class="swi-alert info">ℹ <div>Simplebooks ei vaja eraldi maksude kaardistust — käibemaks arvutatakse automaatselt iga toote rea pealt.</div></div>
                        </div>

                        <!-- ── Saatmise ajalugu ── -->
                        <?php $sb_history = (array) get_option('swi_sb_send_history', []); ?>
                        <div class="swi-panel" id="swi-panel-sb-history">
                            <div class="swi-section-title">Simplebooks – Saatmise ajalugu</div>
                            <div class="swi-section-desc">Viimased 50 Simplebooks saatmiskatset.</div>
                            <div class="swi-card">
                                <?php if (empty($sb_history)) : ?>
                                <p style="color:#9ca3af;font-size:12.5px;margin:0;">Saatmisi pole veel toimunud.</p>
                                <?php else : ?>
                                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                                    <button type="button" id="swi-sb-clear-history-btn" class="button button-small" style="color:#b32d2e;">Tühista ajalugu</button>
                                    <span style="font-size:12px;color:#6b7280;"><?php echo count($sb_history); ?> kirjet</span>
                                </div>
                                <table class="widefat striped" id="swi-sb-history-table">
                                    <thead><tr>
                                        <th>Aeg</th><th>Tellimus</th><th>Staatus</th><th>Sõnum</th>
                                    </tr></thead>
                                    <tbody>
                                    <?php foreach ($sb_history as $i => $entry) :
                                        $es = $entry['status'] ?? '';
                                        $col = $es === 'ok' ? '#14532d' : '#991b1b';
                                        $bg  = $es === 'ok' ? '#dcfce7' : '#fee2e2';
                                    ?>
                                    <tr data-row="<?php echo $i; ?>">
                                        <td style="font-size:12px;"><?php echo esc_html($entry['time'] ?? ''); ?></td>
                                        <td><a href="<?php echo esc_url(admin_url('post.php?post='.intval($entry['order_id']??0).'&action=edit')); ?>" target="_blank">#<?php echo intval($entry['order_id']??0); ?></a></td>
                                        <td><span style="padding:2px 8px;border-radius:10px;font-size:11px;background:<?php echo $bg; ?>;color:<?php echo $col; ?>;"><?php echo esc_html($es); ?></span></td>
                                        <td style="font-size:12px;"><?php echo esc_html($entry['message'] ?? ''); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <div id="swi-sb-history-pagination" style="display:flex;align-items:center;gap:4px;margin-top:12px;flex-wrap:wrap;"></div>
                                <script>
                                (function(){
                                    var PER=10, rows=document.querySelectorAll('#swi-sb-history-table tbody tr'), total=rows.length, pages=Math.ceil(total/PER), cur=1;
                                    if(pages<=1) return;
                                    function showPage(p){cur=p;rows.forEach(function(tr,i){tr.style.display=(i>=(p-1)*PER&&i<p*PER)?'':'none';});renderPager();}
                                    function renderPager(){var nav=document.getElementById('swi-sb-history-pagination');nav.innerHTML='';for(var i=1;i<=pages;i++){var btn=document.createElement('button');btn.type='button';btn.textContent=i;btn.style.cssText='min-width:32px;padding:3px 8px;border-radius:4px;border:1px solid #d1d5db;cursor:pointer;font-size:12px;'+(i===cur?'background:#2563eb;color:#fff;border-color:#2563eb;':'background:#fff;color:#374151;');(function(pg){btn.addEventListener('click',function(){showPage(pg);});})(i);nav.appendChild(btn);}}
                                    showPage(1);
                                })();
                                </script>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- ── Sünkroniseerimise kontroll ── -->
                        <div class="swi-panel" id="swi-panel-sb-sync">
                            <div class="swi-section-title">Simplebooks – Sünkroniseerimise kontroll</div>
                            <div class="swi-section-desc">Võrdle WooCommerce tellimusi Simplebooks arvetega. Puuduvaid arveid saad siit uuesti saata.</div>
                            <div class="swi-card">
                                <button type="button" id="swi-sb-sync-btn" class="button button-secondary">Kontrolli sünkroniseerimist</button>
                                <span id="swi-sb-sync-spinner" style="display:none;margin-left:10px;">Laen...</span>
                                <div id="swi-sb-sync-result" style="margin-top:16px;"></div>
                            </div>
                        </div>

                        <!-- ── Tööriistad ── -->
                        <div class="swi-panel" id="swi-panel-sb-tools">
                            <div class="swi-section-title">Simplebooks – Tööriistad</div>
                            <div class="swi-section-desc">Hulga saatmine, käsitsi saatmine, arve eelvaade.</div>

                            <div class="swi-card">
                                <p style="margin:0 0 8px;font-weight:600;font-size:12.5px;">Sünkroniseeri kõik</p>
                                <p style="margin:0 0 12px;font-size:12px;color:#6b7280;">Saada kõik saadetamata tellimused korraga Simplebooks'i (max 50 korraga).</p>
                                <button type="button" id="swi-sb-bulk-btn" class="button button-primary">Sünkroniseeri kõik puuduvad</button>
                                <span id="swi-sb-bulk-spinner" style="display:none;margin-left:10px;font-size:12px;">Saadan...</span>
                                <div id="swi-sb-bulk-result" style="margin-top:12px;font-size:12.5px;"></div>
                            </div>

                            <div class="swi-card">
                                <p style="margin:0 0 8px;font-weight:600;font-size:12.5px;">Käsitsi saatmine</p>
                                <p style="margin:0 0 12px;font-size:12px;color:#6b7280;">Saada tellimus Simplebooks'i, olenemata kas see on juba saadetud.</p>
                                <input type="number" id="swi-sb-manual-id" placeholder="Tellimuse ID (nt 42)" style="width:180px;margin-right:8px;">
                                <button type="button" id="swi-sb-manual-btn" class="button button-secondary">Saada Simplebooks'i</button>
                                <span id="swi-sb-manual-result" style="margin-left:10px;font-size:12px;"></span>
                            </div>

                            <div class="swi-card">
                                <p style="margin:0 0 8px;font-weight:600;font-size:12.5px;">Arve eelvaade</p>
                                <p style="margin:0 0 12px;font-size:12px;color:#6b7280;">Kuva mis andmed Simplebooks'i lähevad enne päris saatmist.</p>
                                <input type="number" id="swi-sb-preview-id" placeholder="Tellimuse ID (nt 42)" style="width:180px;margin-right:8px;">
                                <button type="button" id="swi-sb-preview-btn" class="button button-secondary">Näita JSON</button>
                                <pre id="swi-sb-preview-result" style="display:none;margin-top:12px;background:#f3f4f6;padding:12px;border-radius:4px;font-size:11px;overflow:auto;max-height:400px;"></pre>
                            </div>
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
                        <div class="swi-nav-item" data-panel="sa-history" onclick="swiPanel('sa-history', this, 'smartaccounts')">
                            <span class="swi-nav-icon">📋</span> Saatmise ajalugu
                        </div>
                        <div class="swi-nav-item" data-panel="sa-sync" onclick="swiPanel('sa-sync', this, 'smartaccounts')">
                            <span class="swi-nav-icon">🔄</span> Sünkroniseerimise kontroll
                        </div>
                        <div class="swi-nav-item" data-panel="sa-tools" onclick="swiPanel('sa-tools', this, 'smartaccounts')">
                            <span class="swi-nav-icon">🔧</span> Tööriistad
                        </div>
                    </nav>
                    <div class="swi-content">

                        <!-- ÜLDSEADED -->
                        <div class="swi-panel active" id="swi-panel-sa-general">
                            <?php if ( isset($_GET['settings-updated']) ) : ?>
                            <div class="swi-alert ok">✓ <div><strong>Seaded on salvestatud.</strong></div></div>
                            <?php endif; ?>
                            <?php if ($this->proxy_error) : ?>
                            <div class="swi-alert err">⚠ <div><strong>Vaheserver ei ole kättesaadav.</strong><br><?php echo esc_html($this->proxy_error); ?></div></div>
                            <?php endif; ?>
                            <div class="swi-section-title">Vaheserveri ühendus</div>
                            <div class="swi-section-desc">Kehtib kõigile süsteemidele. Kopeeri võtmed Laravel rakenduse litsentsi lehelt.</div>
                            <div class="swi-card" style="margin-bottom:28px;">
                                <?php woocommerce_admin_fields( $conn_s ); ?>
                            </div>
                            <div class="swi-section-title">Smart Accounts – Üldseaded</div>
                            <div class="swi-section-desc">Smart Accounts API võtmed seadista Laravel rakenduses jaotises <em>Litsentsid → Seadista → Smart Accounts</em>. Plugin edastab tellimused automaatselt vaheserveri kaudu.</div>
                            <div class="swi-card">
                                <?php $this->render_toggle('swi_smartaccounts_enable', 'Luba Smart Accounts', $sa_on); ?>
                                <?php woocommerce_admin_fields($sa_s); ?>
                            </div>
                            <div class="swi-alert info">ℹ <div>Smart Accounts API (Client ID ja Client Secret) seadistatakse Laravel vaheserveri litsentsi seadetes, mitte siia.</div></div>
                        </div>

                        <input type="hidden" id="swi_sa_nonce" value="<?php echo wp_create_nonce('my_nonce'); ?>">

                        <!-- SAATMISE AJALUGU -->
                        <div class="swi-panel" id="swi-panel-sa-history">
                            <div class="swi-section-title">Saatmise ajalugu</div>
                            <div class="swi-section-desc">Viimased 50 Smart Accounts saatmiskatset (uusim üleval).</div>
                            <div class="swi-card">
                                <?php
                                $sa_hist = get_option('swi_sa_send_history', []);
                                if (empty($sa_hist)) : ?>
                                <p style="color:#6b7280;margin:0;">Ühtegi saatmiskatset veel pole.</p>
                                <?php else : ?>
                                <div style="overflow-x:auto;">
                                <table class="swi-table" style="width:100%;border-collapse:collapse;font-size:13px;">
                                    <thead><tr>
                                        <th style="text-align:left;padding:6px 10px;border-bottom:1px solid #e5e7eb;">Aeg</th>
                                        <th style="text-align:left;padding:6px 10px;border-bottom:1px solid #e5e7eb;">Order</th>
                                        <th style="text-align:left;padding:6px 10px;border-bottom:1px solid #e5e7eb;">Staatus</th>
                                        <th style="text-align:left;padding:6px 10px;border-bottom:1px solid #e5e7eb;">Teade</th>
                                    </tr></thead>
                                    <tbody id="swi-sa-history-tbody">
                                    <?php
                                    $sa_page     = 1;
                                    $sa_per_page = 10;
                                    $sa_pages    = ceil(count($sa_hist) / $sa_per_page);
                                    $sa_slice    = array_slice($sa_hist, 0, $sa_per_page);
                                    foreach ($sa_slice as $row) :
                                        $ok = ($row['status'] === 'ok');
                                    ?>
                                    <tr>
                                        <td style="padding:5px 10px;border-bottom:1px solid #f3f4f6;"><?php echo esc_html($row['time']); ?></td>
                                        <td style="padding:5px 10px;border-bottom:1px solid #f3f4f6;">#<?php echo esc_html($row['order_id']); ?></td>
                                        <td style="padding:5px 10px;border-bottom:1px solid #f3f4f6;">
                                            <span style="color:<?php echo $ok ? '#16a34a' : '#dc2626'; ?>;font-weight:600;">
                                                <?php echo $ok ? '✓ OK' : '✗ Viga'; ?>
                                            </span>
                                        </td>
                                        <td style="padding:5px 10px;border-bottom:1px solid #f3f4f6;"><?php echo esc_html($row['message']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                                </div>
                                <?php if ($sa_pages > 1) : ?>
                                <div style="margin-top:12px;display:flex;gap:8px;align-items:center;">
                                    <button type="button" class="button" id="swi-sa-hist-prev" disabled onclick="swiSaHistPage(-1)">‹ Eelmine</button>
                                    <span id="swi-sa-hist-page">1 / <?php echo $sa_pages; ?></span>
                                    <button type="button" class="button" id="swi-sa-hist-next" onclick="swiSaHistPage(1)">Järgmine ›</button>
                                </div>
                                <script>
                                var swiSaHist = <?php echo json_encode($sa_hist); ?>;
                                var swiSaHistCur = 1, swiSaHistPer = 10;
                                var swiSaHistPages = <?php echo $sa_pages; ?>;
                                function swiSaHistPage(dir) {
                                    swiSaHistCur = Math.max(1, Math.min(swiSaHistPages, swiSaHistCur + dir));
                                    var slice = swiSaHist.slice((swiSaHistCur-1)*swiSaHistPer, swiSaHistCur*swiSaHistPer);
                                    var tbody = document.getElementById('swi-sa-history-tbody');
                                    tbody.innerHTML = slice.map(function(r){
                                        var ok = r.status === 'ok';
                                        return '<tr><td style="padding:5px 10px;border-bottom:1px solid #f3f4f6;">' + r.time +
                                            '</td><td style="padding:5px 10px;border-bottom:1px solid #f3f4f6;">#' + r.order_id +
                                            '</td><td style="padding:5px 10px;border-bottom:1px solid #f3f4f6;"><span style="color:' + (ok?'#16a34a':'#dc2626') + ';font-weight:600;">' + (ok?'✓ OK':'✗ Viga') + '</span>' +
                                            '</td><td style="padding:5px 10px;border-bottom:1px solid #f3f4f6;">' + r.message + '</td></tr>';
                                    }).join('');
                                    document.getElementById('swi-sa-hist-page').textContent = swiSaHistCur + ' / ' + swiSaHistPages;
                                    document.getElementById('swi-sa-hist-prev').disabled = swiSaHistCur <= 1;
                                    document.getElementById('swi-sa-hist-next').disabled = swiSaHistCur >= swiSaHistPages;
                                }
                                </script>
                                <?php endif; ?>
                                <div style="margin-top:14px;text-align:right;">
                                    <button type="button" class="button" id="swi-sa-clear-hist-btn"
                                        onclick="if(confirm('Kustuta kogu ajalugu?')) jQuery.post(ajaxurl, {action:'swi_sa_clear_history', security: jQuery('#swi_sa_nonce').val()}, function(r){ if(r.success) { document.getElementById('swi-sa-history-tbody').innerHTML='<tr><td colspan=4 style=padding:10px;color:#6b7280;>Ajalugu kustutatud.</td></tr>'; } });">
                                        Kustuta ajalugu
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- SÜNKRONISEERIMISE KONTROLL -->
                        <div class="swi-panel" id="swi-panel-sa-sync">
                            <div class="swi-section-title">Sünkroniseerimise kontroll</div>
                            <div class="swi-section-desc">Näitab milliseid tellimusi pole veel Smart Accountsi saadetud (plugina andmete põhjal).</div>
                            <div class="swi-card">
                                <button type="button" class="button button-primary" id="swi-sa-sync-btn">
                                    Kontrolli sünkroniseerimist
                                </button>
                                <span id="swi-sa-sync-spinner" style="display:none;margin-left:10px;">⏳ Kontrollin...</span>
                                <div id="swi-sa-sync-result" style="margin-top:16px;"></div>
                            </div>
                            <script>
                            document.addEventListener('DOMContentLoaded', function() {
                                var saBtn = document.getElementById('swi-sa-sync-btn');
                                if (!saBtn) return;
                                saBtn.addEventListener('click', function() {
                                    var spinner = document.getElementById('swi-sa-sync-spinner');
                                    var result  = document.getElementById('swi-sa-sync-result');
                                    saBtn.disabled = true;
                                    spinner.style.display = 'inline';
                                    result.innerHTML = '';
                                    jQuery.post(ajaxurl, {
                                        action: 'swi_sa_sync_check',
                                        security: jQuery('#swi_sa_nonce').val()
                                    }, function(r) {
                                        saBtn.disabled = false;
                                        spinner.style.display = 'none';
                                        if (!r.success) {
                                            result.innerHTML = '<div class="swi-alert err">⚠ <div>' + (r.data.error||'Viga') + '</div></div>';
                                            return;
                                        }
                                        var rows = r.data.rows || [];
                                        var missing = r.data.missing_count || 0;
                                        var td = 'style="padding:5px 10px;border-bottom:1px solid #f3f4f6;"';
                                        var th = 'style="text-align:left;padding:5px 10px;border-bottom:1px solid #e5e7eb;"';
                                        var html = '<div style="overflow-x:auto;margin-top:10px;"><table class="swi-table" style="width:100%;border-collapse:collapse;font-size:13px;">';
                                        html += '<thead><tr><th '+th+'>WC tellimus</th><th '+th+'>SA arvenr</th><th '+th+'>Summa</th><th '+th+'>Saadetud</th><th '+th+'>Staatus</th><th></th></tr></thead><tbody>';
                                        rows.forEach(function(row) {
                                            var sent = row.in_sa;
                                            var statusColor = sent ? '#16a34a' : '#dc2626';
                                            var statusText  = sent ? '✓ saadetud' : '— puudub';
                                            html += '<tr>';
                                            html += '<td '+td+'>' + row.inv_no + '</td>';
                                            html += '<td '+td+'>' + (row.sa_inv_no || '—') + '</td>';
                                            html += '<td '+td+'>' + row.total_html + '</td>';
                                            html += '<td '+td+'>' + (row.meta_sent||'—') + '</td>';
                                            html += '<td '+td+'><span style="color:'+statusColor+';font-weight:600;">'+statusText+'</span></td>';
                                            html += '<td '+td+'>';
                                            if (!sent) {
                                                html += '<button class="button button-small swi-sa-resend-btn" data-id="' + row.order_id + '">Saada</button>';
                                            } else {
                                                html += '<button class="button button-small swi-sa-unmark-btn" data-id="' + row.order_id + '" style="color:#dc2626;">Tühista</button>';
                                            }
                                            html += '<span id="swi-sa-resend-result-' + row.order_id + '" style="margin-left:6px;font-size:11.5px;"></span>';
                                            html += '</td>';
                                            html += '</tr>';
                                        });
                                        html += '</tbody></table></div>';
                                        if (missing > 0) html = '<div class="swi-alert err">⚠ <div>' + missing + ' arvet puudub</div></div>' + html;
                                        else html = '<div class="swi-alert ok">✓ <div>Kõik arved on sünkroniseeritud.</div></div>' + html;
                                        result.innerHTML = html;
                                        document.querySelectorAll('.swi-sa-resend-btn').forEach(function(btn) {
                                            btn.addEventListener('click', function() {
                                                var id = btn.dataset.id;
                                                btn.disabled = true; btn.textContent = '...';
                                                jQuery.post(ajaxurl, {action: 'swi_sa_sync_resend', security: jQuery('#swi_sa_nonce').val(), order_id: id}, function(r) {
                                                    btn.disabled = false;
                                                    var res = document.getElementById('swi-sa-resend-result-' + id);
                                                    if (r.success) { btn.textContent = 'Saadetud'; res.innerHTML = '<span style="color:#16a34a">✓</span>'; }
                                                    else { btn.textContent = 'Uuesti'; res.innerHTML = '<span style="color:#dc2626">✗ ' + ((r.data&&r.data.error)||'Viga') + '</span>'; }
                                                });
                                            });
                                        });
                                        document.querySelectorAll('.swi-sa-unmark-btn').forEach(function(btn) {
                                            btn.addEventListener('click', function() {
                                                var id = btn.dataset.id;
                                                btn.disabled = true; btn.textContent = '...';
                                                jQuery.post(ajaxurl, {action: 'swi_sa_reset_single', security: jQuery('#swi_sa_nonce').val(), order_id: id}, function(r) {
                                                    var res = document.getElementById('swi-sa-resend-result-' + id);
                                                    if (r.success) {
                                                        btn.textContent = 'Saada';
                                                        btn.classList.remove('swi-sa-unmark-btn');
                                                        btn.classList.add('swi-sa-resend-btn');
                                                        btn.style.color = '';
                                                        btn.disabled = false;
                                                        res.innerHTML = '<span style="color:#6b7280;font-size:11px;">märgitud puuduvaks</span>';
                                                        btn.addEventListener('click', arguments.callee);
                                                    } else {
                                                        btn.disabled = false; btn.textContent = 'Tühista';
                                                        res.innerHTML = '<span style="color:#dc2626">✗ Viga</span>';
                                                    }
                                                });
                                            });
                                        });
                                    }).fail(function() {
                                        saBtn.disabled = false;
                                        spinner.style.display = 'none';
                                        result.innerHTML = '<div class="swi-alert err">⚠ <div>AJAX päring ebaõnnestus.</div></div>';
                                    });
                                });
                            });
                            </script>
                        </div>

                        <!-- TÖÖRIISTAD -->
                        <div class="swi-panel" id="swi-panel-sa-tools">
                            <div class="swi-section-title">Tööriistad</div>

                            <div class="swi-card" style="margin-bottom:20px;">
                                <h3 style="margin:0 0 8px;font-size:14px;">Tühista saatmise märgid</h3>
                                <p style="margin:0 0 12px;color:#6b7280;font-size:13px;">Eemaldab kõik "_swi_sent_smartaccounts" märgid — kasulik kui SA-st kustutati arved ja soovid uuesti saata.</p>
                                <button type="button" class="button" id="swi-sa-reset-btn" onclick="if(!confirm('Kustutad kõik saatmise märgid. Jätka?')) return; this.disabled=true; jQuery.post(ajaxurl, {action:'swi_sa_reset_sent', security:document.getElementById('swi_sa_nonce').value}, function(r){ document.getElementById('swi-sa-reset-btn').disabled=false; document.getElementById('swi-sa-reset-result').innerHTML = r.success ? '<span style=color:#16a34a>✓ '+r.data.message+'</span>' : '<span style=color:#dc2626>✗ Viga</span>'; });">
                                    Tühista kõik saatmise märgid
                                </button>
                                <span id="swi-sa-reset-result" style="margin-left:10px;font-size:13px;"></span>
                            </div>

                            <div class="swi-card" style="margin-bottom:20px;">
                                <h3 style="margin:0 0 8px;font-size:14px;">Sünkroniseeri kõik puuduvad</h3>
                                <p style="margin:0 0 12px;color:#6b7280;font-size:13px;">Saadab kõik saadetamata tellimused Smart Accountsi (max 50 korraga).</p>
                                <button type="button" class="button button-primary" id="swi-sa-bulk-btn">
                                    Sünkroniseeri kõik puuduvad
                                </button>
                                <span id="swi-sa-bulk-spinner" style="display:none;margin-left:10px;">⏳ Saadan...</span>
                                <div id="swi-sa-bulk-result" style="margin-top:12px;font-size:13px;"></div>
                            </div>

                            <div class="swi-card" style="margin-bottom:20px;">
                                <h3 style="margin:0 0 8px;font-size:14px;">Käsitsi saatmine</h3>
                                <p style="margin:0 0 12px;color:#6b7280;font-size:13px;">Saada üks konkreetne tellimus tellimuse ID järgi.</p>
                                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                    <input type="number" id="swi-sa-manual-id" placeholder="Tellimuse ID" style="width:150px;" class="regular-text">
                                    <button type="button" class="button" id="swi-sa-manual-btn">Saada</button>
                                    <button type="button" class="button" id="swi-sa-preview-btn">Eelvaade (JSON)</button>
                                </div>
                                <div id="swi-sa-manual-result" style="margin-top:10px;font-size:13px;"></div>
                                <pre id="swi-sa-preview-result" style="display:none;margin-top:10px;background:#f9fafb;border:1px solid #e5e7eb;padding:10px;border-radius:4px;font-size:11px;overflow:auto;max-height:300px;"></pre>
                            </div>

                            <script>
                            document.addEventListener('DOMContentLoaded', function() {
                                // Bulk send
                                var saBlkBtn = document.getElementById('swi-sa-bulk-btn');
                                if (saBlkBtn) {
                                    saBlkBtn.addEventListener('click', function() {
                                        var spinner = document.getElementById('swi-sa-bulk-spinner');
                                        var result  = document.getElementById('swi-sa-bulk-result');
                                        saBlkBtn.disabled = true;
                                        spinner.style.display = 'inline';
                                        result.innerHTML = '';
                                        jQuery.post(ajaxurl, {action:'swi_sa_bulk_send', security:document.getElementById('swi_sa_nonce').value}, function(r) {
                                            saBlkBtn.disabled = false;
                                            spinner.style.display = 'none';
                                            if (r.success) {
                                                var d = r.data;
                                                var color = d.failed > 0 ? '#d97706' : '#16a34a';
                                                result.innerHTML = '<span style="color:' + color + ';font-weight:600;">' + d.message + '</span>';
                                                if (d._debug) result.innerHTML += '<br><small style="color:#6b7280;">Status: ' + d._debug.queried_status + ' | Leitud: ' + d._debug.all_found + ' | Staatused: ' + (d._debug.order_statuses||[]).join(', ') + ' | Meta märgitud: ' + (d._debug.meta_set_count||0) + '</small>';
                                                if (d.errors && d.errors.length) result.innerHTML += '<br><span style="color:#dc2626;font-size:12px;">' + d.errors.join('<br>') + '</span>';
                                            } else {
                                                result.innerHTML = '<span style="color:#dc2626">⚠ ' + ((r.data&&r.data.error)||'Viga') + '</span>';
                                            }
                                        }).fail(function(){ saBlkBtn.disabled=false; spinner.style.display='none'; result.innerHTML='<span style="color:#dc2626">AJAX viga.</span>'; });
                                    });
                                }
                                // Manual send
                                var saManBtn = document.getElementById('swi-sa-manual-btn');
                                var saPrvBtn = document.getElementById('swi-sa-preview-btn');
                                function saSendManual(preview) {
                                    var id = document.getElementById('swi-sa-manual-id').value;
                                    if (!id) { alert('Sisesta tellimuse ID'); return; }
                                    var result = document.getElementById('swi-sa-manual-result');
                                    var pre    = document.getElementById('swi-sa-preview-result');
                                    result.innerHTML = '⏳ Saadan...';
                                    pre.style.display = 'none';
                                    var data = {action:'swi_sa_manual_send', security:document.getElementById('swi_sa_nonce').value, order_id:id};
                                    if (preview) data.preview_only = 1;
                                    jQuery.post(ajaxurl, data, function(r) {
                                        if (preview && r.success) {
                                            result.innerHTML = '';
                                            pre.textContent = JSON.stringify(r.data.payload, null, 2);
                                            pre.style.display = 'block';
                                        } else if (r.success) {
                                            result.innerHTML = '<span style="color:#16a34a">✓ ' + r.data.message + '</span>';
                                        } else {
                                            result.innerHTML = '<span style="color:#dc2626">⚠ ' + ((r.data&&r.data.error)||'Viga') + '</span>';
                                        }
                                    });
                                }
                                if (saManBtn) saManBtn.addEventListener('click', function() { saSendManual(false); });
                                if (saPrvBtn) saPrvBtn.addEventListener('click', function() { saSendManual(true); });
                            });
                            </script>
                        </div>

                    </div>
                </div>

                <!-- ══ TAB: ERPLY ══ -->
                <div class="swi-tabview" id="swi-tab-erply">
                    <nav class="swi-sidebar">
                        <div class="swi-sidebar-label">Erply</div>
                        <div class="swi-nav-item active" data-panel="erply-general" onclick="swiPanel('erply-general', this, 'erply')">
                            <span class="swi-nav-icon">⚙</span> Üldseaded
                        </div>
                        <div class="swi-nav-item" data-panel="erply-history" onclick="swiPanel('erply-history', this, 'erply')">
                            <span class="swi-nav-icon">📋</span> Saatmise ajalugu
                        </div>
                        <div class="swi-nav-item" data-panel="erply-sync" onclick="swiPanel('erply-sync', this, 'erply')">
                            <span class="swi-nav-icon">🔄</span> Sünkroniseerimise kontroll
                        </div>
                        <div class="swi-nav-item" data-panel="erply-tools" onclick="swiPanel('erply-tools', this, 'erply')">
                            <span class="swi-nav-icon">🔧</span> Tööriistad
                        </div>
                    </nav>
                    <div class="swi-content">

                        <!-- ÜLDSEADED -->
                        <div class="swi-panel active" id="swi-panel-erply-general">
                            <?php if ( isset($_GET['settings-updated']) ) : ?>
                            <div class="swi-alert ok">✓ <div><strong>Seaded on salvestatud.</strong></div></div>
                            <?php endif; ?>
                            <?php if ($this->proxy_error) : ?>
                            <div class="swi-alert err">⚠ <div><strong>Vaheserver ei ole kättesaadav.</strong><br><?php echo esc_html($this->proxy_error); ?></div></div>
                            <?php endif; ?>
                            <div class="swi-section-title">Vaheserveri ühendus</div>
                            <div class="swi-section-desc">Kehtib kõigile süsteemidele.</div>
                            <div class="swi-card" style="margin-bottom:28px;">
                                <?php woocommerce_admin_fields( $conn_s ); ?>
                            </div>
                            <div class="swi-section-title">Erply – Üldseaded</div>
                            <div class="swi-section-desc">Erply API URL, kasutajanimi ja parool seadistatakse Laravel rakenduses jaotises <em>Litsentsid → Seadista → Erply</em>.</div>
                            <div class="swi-card">
                                <?php $this->render_toggle('swi_erply_enable', 'Luba Erply', $erply_on); ?>
                                <?php woocommerce_admin_fields($erply_s); ?>
                            </div>
                            <div class="swi-alert info">ℹ <div>Erply API seaded (URL, kasutajanimi, parool) konfigureeritakse Laravel vaheserveri litsentsi seadetes.</div></div>
                            <div class="swi-card">
                                <p style="margin:0 0 8px;font-weight:600;font-size:12.5px;">Ühenduse test</p>
                                <p style="margin:0 0 12px;font-size:12px;color:#6b7280;">Kontrollib ühendust Erply API-ga — autentib ja tagastab tulemuse.</p>
                                <input type="hidden" id="swi_erply_gen_nonce" value="<?php echo wp_create_nonce('my_nonce'); ?>">
                                <button type="button" id="swi-erply-test-btn" class="button button-secondary">🔌 Testi ühendust</button>
                                <span id="swi-erply-test-result" style="margin-left:12px;font-size:12.5px;"></span>
                                <script>
                                document.addEventListener('DOMContentLoaded', function() {
                                    var btn = document.getElementById('swi-erply-test-btn');
                                    if (!btn) return;
                                    btn.addEventListener('click', function() {
                                        btn.disabled = true;
                                        btn.textContent = '...';
                                        document.getElementById('swi-erply-test-result').innerHTML = '';
                                        jQuery.post(ajaxurl, {action:'swi_erply_connection_test', security:document.getElementById('swi_erply_gen_nonce').value}, function(r) {
                                            btn.disabled = false;
                                            btn.textContent = '🔌 Testi ühendust';
                                            if (r.success) {
                                                document.getElementById('swi-erply-test-result').innerHTML = '<span style="color:#16a34a;font-weight:600;">✓ ' + r.data.message + '</span>';
                                            } else {
                                                document.getElementById('swi-erply-test-result').innerHTML = '<span style="color:#dc2626;font-weight:600;">✗ ' + (r.data&&r.data.error||'Viga') + '</span>';
                                            }
                                        }).fail(function(){ btn.disabled=false; btn.textContent='🔌 Testi ühendust'; });
                                    });
                                });
                                </script>
                            </div>
                        </div>

                        <!-- SAATMISE AJALUGU -->
                        <?php $erply_hist = (array) get_option('swi_erply_send_history', []); ?>
                        <div class="swi-panel" id="swi-panel-erply-history">
                            <div class="swi-section-title">Erply – Saatmise ajalugu</div>
                            <div class="swi-section-desc">Viimased 50 Erply saatmiskatset (uusim üleval).</div>
                            <div class="swi-card">
                                <?php if (empty($erply_hist)) : ?>
                                <p style="color:#9ca3af;font-size:12.5px;margin:0;">Saatmisi pole veel toimunud.</p>
                                <?php else : ?>
                                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                                    <button type="button" id="swi-erply-clear-history-btn" class="button button-small" style="color:#b32d2e;">Tühista ajalugu</button>
                                    <span id="swi-erply-hist-count" style="font-size:12px;color:#6b7280;"><?php echo count($erply_hist); ?> kirjet</span>
                                </div>
                                <div style="overflow-x:auto;">
                                <table class="widefat" id="swi-erply-history-table">
                                    <thead><tr>
                                        <th>Aeg</th><th>Tellimus</th><th>Staatus</th><th>Teade</th>
                                    </tr></thead>
                                    <tbody>
                                    <?php foreach ($erply_hist as $i => $row) :
                                        $ok  = ($row['status'] ?? '') === 'ok';
                                        $col = $ok ? '#14532d' : '#991b1b';
                                        $bg  = $ok ? '#dcfce7' : '#fee2e2';
                                    ?>
                                    <tr data-row="<?php echo $i; ?>">
                                        <td style="font-size:12px;"><?php echo esc_html($row['time'] ?? ''); ?></td>
                                        <td><a href="<?php echo esc_url(admin_url('post.php?post='.intval($row['order_id']??0).'&action=edit')); ?>" target="_blank">#<?php echo intval($row['order_id']??0); ?></a></td>
                                        <td><span style="padding:2px 8px;border-radius:10px;font-size:11px;background:<?php echo $bg; ?>;color:<?php echo $col; ?>;"><?php echo $ok?'✓ ok':'✗ viga'; ?></span></td>
                                        <td style="font-size:12px;"><?php echo esc_html($row['message'] ?? ''); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                                </div>
                                <div id="swi-erply-hist-pager" style="display:flex;align-items:center;gap:4px;margin-top:12px;flex-wrap:wrap;"></div>
                                <script>
                                (function(){
                                    var PER=10,rows=document.querySelectorAll('#swi-erply-history-table tbody tr'),total=rows.length,pages=Math.ceil(total/PER),cur=1;
                                    if(pages<=1) return;
                                    function showPage(p){cur=p;rows.forEach(function(tr,i){tr.style.display=(i>=(p-1)*PER&&i<p*PER)?'':'none';});renderPager();}
                                    function renderPager(){var nav=document.getElementById('swi-erply-hist-pager');nav.innerHTML='';for(var i=1;i<=pages;i++){var btn=document.createElement('button');btn.type='button';btn.textContent=i;btn.style.cssText='min-width:32px;padding:3px 8px;border-radius:4px;border:1px solid #d1d5db;cursor:pointer;font-size:12px;'+(i===cur?'background:#2563eb;color:#fff;border-color:#2563eb;':'background:#fff;color:#374151;');(function(pg){btn.addEventListener('click',function(){showPage(pg);});})(i);nav.appendChild(btn);}}
                                    showPage(1);
                                })();
                                document.addEventListener('DOMContentLoaded', function() {
                                    var clrBtn = document.getElementById('swi-erply-clear-history-btn');
                                    if (clrBtn) clrBtn.addEventListener('click', function() {
                                        if (!confirm('Kustutad kogu Erply saatmise ajaloo?')) return;
                                        jQuery.post(ajaxurl, {action:'swi_erply_clear_history', security:'<?php echo wp_create_nonce('my_nonce'); ?>'}, function(r) {
                                            if (r.success) { document.querySelector('#swi-panel-erply-history .swi-card').innerHTML = '<p style="color:#9ca3af;font-size:12.5px;margin:0;">Saatmisi pole veel toimunud.</p>'; }
                                        });
                                    });
                                });
                                </script>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- SÜNKRONISEERIMISE KONTROLL -->
                        <div class="swi-panel" id="swi-panel-erply-sync">
                            <div class="swi-section-title">Sünkroniseerimise kontroll</div>
                            <div class="swi-section-desc">Kontrolli millised tellimused on Erplysse saadetud.</div>
                            <div class="swi-card">
                                <input type="hidden" id="swi_erply_nonce" value="<?php echo wp_create_nonce('my_nonce'); ?>">
                                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                    <button class="button button-secondary" id="swi-erply-sync-btn">🔄 Kontrolli</button>
                                    <button class="button button-primary" id="swi-erply-syncall-btn">⬆ Sync kõik</button>
                                    <span id="swi-erply-sync-spinner" style="display:none;margin-left:2px;">⏳</span>
                                    <span id="swi-erply-syncall-result" style="font-size:13px;"></span>
                                </div>
                                <div id="swi-erply-sync-result" style="margin-top:14px;"></div>
                            </div>
                            <script>
                            document.addEventListener('DOMContentLoaded', function() {
                                var btn = document.getElementById('swi-erply-sync-btn');
                                if (!btn) return;
                                btn.addEventListener('click', function() {
                                    btn.disabled = true;
                                    document.getElementById('swi-erply-sync-spinner').style.display = 'inline';
                                    document.getElementById('swi-erply-sync-result').innerHTML = '';
                                    jQuery.post(ajaxurl, {action:'swi_erply_sync_check', security:document.getElementById('swi_erply_nonce').value}, function(r) {
                                        btn.disabled = false;
                                        document.getElementById('swi-erply-sync-spinner').style.display = 'none';
                                        if (!r.success) { document.getElementById('swi-erply-sync-result').innerHTML = '<span style="color:#dc2626">⚠ ' + (r.data&&r.data.error||'Viga') + '</span>'; return; }
                                        var d = r.data;
                                        var html = '<p style="margin:0 0 10px;font-size:13px;color:#374151;">Leitud: <strong>' + d.rows.length + '</strong> tellimust, puudub Erplys: <strong style="color:#dc2626">' + d.missing_count + '</strong></p>';
                                        html += '<div style="overflow-x:auto;"><table class="swi-table" style="width:100%;border-collapse:collapse;font-size:13px;">';
                                        html += '<thead><tr><th style="text-align:left;padding:6px 10px;background:#f3f4f6;border-bottom:1px solid #e5e7eb;">WC tellimus</th><th style="text-align:left;padding:6px 10px;background:#f3f4f6;border-bottom:1px solid #e5e7eb;">Erply arve ID</th><th style="text-align:left;padding:6px 10px;background:#f3f4f6;border-bottom:1px solid #e5e7eb;">Summa</th><th style="text-align:left;padding:6px 10px;background:#f3f4f6;border-bottom:1px solid #e5e7eb;">Saadetud</th><th style="text-align:left;padding:6px 10px;background:#f3f4f6;border-bottom:1px solid #e5e7eb;">Staatus</th><th style="padding:6px 10px;background:#f3f4f6;border-bottom:1px solid #e5e7eb;"></th></tr></thead><tbody>';
                                        d.rows.forEach(function(row) {
                                            var status = row.in_erply
                                                ? '<span style="color:#16a34a;font-weight:600;">✓ saadetud</span>'
                                                : '<span style="color:#dc2626;font-weight:600;">— puudub</span>';
                                            var btn_html = '';
                                            if (row.in_erply) {
                                                btn_html = '<button class="button button-small swi-erply-unmark-btn" data-id="'+row.order_id+'" style="color:#dc2626;border-color:#dc2626;">Tühista</button>';
                                            } else {
                                                btn_html = '<button class="button button-small swi-erply-row-send-btn" data-id="'+row.order_id+'">Saada</button>';
                                            }
                                            html += '<tr style="border-bottom:1px solid #f3f4f6;">'
                                                + '<td style="padding:6px 10px;">#'+row.order_id+'</td>'
                                                + '<td style="padding:6px 10px;font-size:12px;color:#6b7280;">'+(row.inv_id||'—')+'</td>'
                                                + '<td style="padding:6px 10px;">'+row.total_html+'</td>'
                                                + '<td style="padding:6px 10px;font-size:12px;color:#6b7280;">'+(row.meta_sent||'—')+'</td>'
                                                + '<td style="padding:6px 10px;">'+status+'</td>'
                                                + '<td style="padding:6px 10px;">'+btn_html+'</td>'
                                                + '</tr>';
                                        });
                                        html += '</tbody></table></div>';
                                        document.getElementById('swi-erply-sync-result').innerHTML = html;
                                    }).fail(function(){ btn.disabled=false; document.getElementById('swi-erply-sync-spinner').style.display='none'; document.getElementById('swi-erply-sync-result').innerHTML='<span style="color:#dc2626">AJAX viga.</span>'; });
                                });
                                // Sync kõik nupp
                                var syncallBtn = document.getElementById('swi-erply-syncall-btn');
                                if (syncallBtn) {
                                    syncallBtn.addEventListener('click', function() {
                                        var res = document.getElementById('swi-erply-syncall-result');
                                        syncallBtn.disabled = true;
                                        res.innerHTML = '<span style="color:#6b7280">⏳ Saadan...</span>';
                                        jQuery.post(ajaxurl, {action:'swi_erply_bulk_send', security:document.getElementById('swi_erply_nonce').value}, function(r) {
                                            syncallBtn.disabled = false;
                                            if (r.success) {
                                                var d = r.data;
                                                var color = d.failed > 0 ? '#d97706' : '#16a34a';
                                                res.innerHTML = '<span style="color:'+color+';font-weight:600;">'+d.message+'</span>';
                                                if (d.errors && d.errors.length) {
                                                    res.innerHTML += '<div style="margin-top:6px;font-size:12px;color:#dc2626;">' + d.errors.join('<br>') + '</div>';
                                                }
                                            } else {
                                                res.innerHTML = '<span style="color:#dc2626;font-weight:600;">✗ ' + (r.data&&r.data.error||'Viga') + '</span>';
                                            }
                                        }).fail(function() { syncallBtn.disabled=false; res.innerHTML='<span style="color:#dc2626">AJAX viga.</span>'; });
                                    });
                                }
                                // Tühista nupp
                                jQuery(document).on('click', '.swi-erply-unmark-btn', function() {
                                    var b = jQuery(this), id = b.data('id');
                                    b.prop('disabled', true).text('...');
                                    jQuery.post(ajaxurl, {action:'swi_erply_reset_single', security:document.getElementById('swi_erply_nonce').value, order_id:id}, function(r) {
                                        if (r.success) { b.closest('tr').find('td:nth-child(5)').html('<span style="color:#dc2626;font-weight:600;">— puudub</span>'); b.replaceWith('<button class="button button-small swi-erply-row-send-btn" data-id="'+id+'">Saada</button>'); }
                                        else { b.prop('disabled', false).text('Tühista'); alert(r.data&&r.data.error||'Viga'); }
                                    });
                                });
                                // Saada nupp
                                jQuery(document).on('click', '.swi-erply-row-send-btn', function() {
                                    var b = jQuery(this), id = b.data('id');
                                    b.prop('disabled', true).text('...');
                                    jQuery.post(ajaxurl, {action:'swi_erply_order_send', security:document.getElementById('swi_erply_nonce').value, order_id:id}, function(r) {
                                        if (r.success) { b.closest('tr').find('td:nth-child(5)').html('<span style="color:#16a34a;font-weight:600;">✓ saadetud</span>'); b.replaceWith('<button class="button button-small swi-erply-unmark-btn" data-id="'+id+'" style="color:#dc2626;border-color:#dc2626;">Tühista</button>'); }
                                        else { b.prop('disabled', false).text('Saada'); alert(r.data&&r.data.error||'Viga'); }
                                    });
                                });
                            });
                            </script>
                        </div>

                        <!-- TÖÖRIISTAD -->
                        <div class="swi-panel" id="swi-panel-erply-tools">
                            <div class="swi-section-title">Tööriistad</div>
                            <input type="hidden" id="swi_erply_tools_nonce" value="<?php echo wp_create_nonce('my_nonce'); ?>">

                            <div class="swi-card" style="margin-bottom:20px;">
                                <p style="margin:0 0 8px;font-weight:600;font-size:12.5px;">Käsitsi saatmine</p>
                                <p style="margin:0 0 12px;font-size:12px;color:#6b7280;">Saada konkreetne tellimus Erplysse (ka juba saadetud).</p>
                                <input type="number" id="swi-erply-manual-id" placeholder="Tellimuse ID (nt 42)" style="width:180px;margin-right:8px;">
                                <button type="button" id="swi-erply-manual-btn" class="button button-secondary">Saada Erplysse</button>
                                <span id="swi-erply-manual-result" style="margin-left:10px;font-size:12.5px;"></span>
                            </div>

                            <div class="swi-card" style="margin-bottom:20px;">
                                <p style="margin:0 0 8px;font-weight:600;font-size:12.5px;">Arve JSON eelvaade</p>
                                <p style="margin:0 0 12px;font-size:12px;color:#6b7280;">Kuva mis andmed Erplysse läheksid enne päris saatmist.</p>
                                <input type="number" id="swi-erply-preview-id" placeholder="Tellimuse ID (nt 42)" style="width:180px;margin-right:8px;">
                                <button type="button" id="swi-erply-preview-btn" class="button button-secondary">Näita JSON</button>
                                <pre id="swi-erply-preview-result" style="display:none;margin-top:12px;background:#f3f4f6;padding:12px;border-radius:4px;font-size:11px;overflow:auto;max-height:400px;"></pre>
                            </div>

                            <div class="swi-card">
                                <p style="margin:0 0 8px;font-weight:600;font-size:12.5px;">Tühista kõik saatmise märgid</p>
                                <p style="margin:0 0 12px;font-size:12px;color:#6b7280;">Eemaldab kõik "_swi_sent_erply" märgid — kasulik kui Erplyst kustutati arved ja soovid uuesti saata.</p>
                                <button class="button" id="swi-erply-reset-btn" style="color:#dc2626;border-color:#dc2626;">⚠ Tühista saatmise märgid</button>
                                <div id="swi-erply-reset-result" style="margin-top:8px;font-size:13px;"></div>
                            </div>
                            <script>
                            document.addEventListener('DOMContentLoaded', function() {
                                var nonce = function(){ return document.getElementById('swi_erply_tools_nonce').value; };

                                // Käsitsi saatmine
                                var manBtn = document.getElementById('swi-erply-manual-btn');
                                if (manBtn) manBtn.addEventListener('click', function() {
                                    var id = document.getElementById('swi-erply-manual-id').value;
                                    var res = document.getElementById('swi-erply-manual-result');
                                    if (!id) { res.innerHTML = '<span style="color:#dc2626">Sisesta tellimuse ID.</span>'; return; }
                                    manBtn.disabled = true; manBtn.textContent = '...';
                                    jQuery.post(ajaxurl, {action:'swi_erply_order_send', security:nonce(), order_id:id}, function(r) {
                                        manBtn.disabled = false; manBtn.textContent = 'Saada Erplysse';
                                        res.innerHTML = r.success
                                            ? '<span style="color:#16a34a;font-weight:600;">✓ ' + r.data.message + '</span>'
                                            : '<span style="color:#dc2626;font-weight:600;">✗ ' + (r.data&&r.data.error||'Viga') + '</span>';
                                    }).fail(function(){ manBtn.disabled=false; manBtn.textContent='Saada Erplysse'; });
                                });

                                // Eelvaade
                                var prvBtn = document.getElementById('swi-erply-preview-btn');
                                if (prvBtn) prvBtn.addEventListener('click', function() {
                                    var id = document.getElementById('swi-erply-preview-id').value;
                                    var pre = document.getElementById('swi-erply-preview-result');
                                    if (!id) { pre.style.display='block'; pre.textContent='Sisesta tellimuse ID.'; return; }
                                    prvBtn.disabled = true; prvBtn.textContent = 'Laen...';
                                    jQuery.post(ajaxurl, {action:'swi_erply_preview', security:nonce(), order_id:id}, function(r) {
                                        prvBtn.disabled = false; prvBtn.textContent = 'Näita JSON';
                                        pre.style.display = 'block';
                                        pre.textContent = r.success ? JSON.stringify(r.data.payload, null, 2) : '✗ ' + (r.data&&r.data.error||'Viga');
                                    }).fail(function(){ prvBtn.disabled=false; prvBtn.textContent='Näita JSON'; });
                                });

                                // Reset all
                                var rstBtn = document.getElementById('swi-erply-reset-btn');
                                if (rstBtn) rstBtn.addEventListener('click', function() {
                                    if (!confirm('Kas oled kindel? Kõik Erply saatmise märgid kustutatakse.')) return;
                                    jQuery.post(ajaxurl, {action:'swi_erply_reset_sent', security:nonce()}, function(r) {
                                        document.getElementById('swi-erply-reset-result').innerHTML = r.success
                                            ? '<span style="color:#16a34a">✓ ' + r.data.message + '</span>'
                                            : '<span style="color:#dc2626">⚠ Viga</span>';
                                    });
                                });
                            });
                            </script>
                        </div>

                    </div>
                </div>

                <!-- ══ TAB: STANDARD BOOKS ══ -->
                <div class="swi-tabview" id="swi-tab-stdb">
                    <nav class="swi-sidebar">
                        <div class="swi-sidebar-label">Standard Books</div>
                        <div class="swi-nav-item active" data-panel="stdb-general" onclick="swiPanel('stdb-general', this, 'stdb')">
                            <span class="swi-nav-icon">⚙</span> Üldseaded
                        </div>
                        <div class="swi-nav-item" data-panel="stdb-history" onclick="swiPanel('stdb-history', this, 'stdb')">
                            <span class="swi-nav-icon">📋</span> Saatmise ajalugu
                        </div>
                        <div class="swi-nav-item" data-panel="stdb-sync" onclick="swiPanel('stdb-sync', this, 'stdb')">
                            <span class="swi-nav-icon">🔄</span> Sünkroniseerimise kontroll
                        </div>
                        <div class="swi-nav-item" data-panel="stdb-tools" onclick="swiPanel('stdb-tools', this, 'stdb')">
                            <span class="swi-nav-icon">🔧</span> Tööriistad
                        </div>
                    </nav>
                    <div class="swi-content">

                        <!-- ÜLDSEADED -->
                        <div class="swi-panel active" id="swi-panel-stdb-general">
                            <div class="swi-section-title">Standard Books – Üldseaded</div>
                            <div class="swi-card" style="margin-bottom:20px;">
                                <input type="hidden" id="swi_stdb_tools_nonce" value="<?php echo wp_create_nonce('my_nonce'); ?>">
                                <?php $this->render_toggle('swi_stdb_enable', 'Luba Standard Books', $stdb_on); ?>
                                <?php woocommerce_admin_fields( $stdb_s ); ?>
                            </div>
                            <div class="swi-card">
                                <p style="margin:0 0 8px;font-weight:600;font-size:12.5px;">🔌 Ühenduse test</p>
                                <p style="margin:0 0 12px;font-size:12px;color:#6b7280;">Kontrollib kas Standard Books API on kättesaadav ja autentimine töötab.</p>
                                <button type="button" id="swi-stdb-test-btn" class="button button-secondary">🔌 Testi ühendust</button>
                                <span id="swi-stdb-test-result" style="margin-left:12px;font-size:13px;"></span>
                                <script>
                                document.addEventListener('DOMContentLoaded', function() {
                                    var btn = document.getElementById('swi-stdb-test-btn');
                                    if (!btn) return;
                                    btn.addEventListener('click', function() {
                                        btn.disabled = true; btn.textContent = '⏳ Kontrollin...';
                                        var result = document.getElementById('swi-stdb-test-result');
                                        jQuery.post(ajaxurl, {action:'swi_stdb_connection_test', security:document.getElementById('swi_stdb_tools_nonce').value}, function(r) {
                                            btn.disabled = false; btn.textContent = '🔌 Testi ühendust';
                                            if (r.success) {
                                                result.innerHTML = '<span style="color:#16a34a;font-weight:600;">✓ ' + r.data.message + '</span>';
                                            } else {
                                                result.innerHTML = '<span style="color:#dc2626;font-weight:600;">✗ ' + (r.data&&r.data.error||'Viga') + '</span>';
                                            }
                                        }).fail(function(){ btn.disabled=false; btn.textContent='🔌 Testi ühendust'; });
                                    });
                                });
                                </script>
                            </div>
                        </div>

                        <!-- SAATMISE AJALUGU -->
                        <?php $stdb_hist = (array) get_option('swi_stdb_send_history', []); ?>
                        <div class="swi-panel" id="swi-panel-stdb-history">
                            <div class="swi-section-title">Standard Books – Saatmise ajalugu</div>
                            <div class="swi-section-desc">Viimased 50 Standard Books saatmiskatset (uusim üleval).</div>
                            <div class="swi-card">
                                <?php if (empty($stdb_hist)) : ?>
                                <p style="color:#9ca3af;font-size:12.5px;margin:0;">Saatmisi pole veel toimunud.</p>
                                <?php else : ?>
                                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                                    <button type="button" id="swi-stdb-clear-history-btn" class="button button-small" style="color:#b32d2e;">Tühista ajalugu</button>
                                    <span id="swi-stdb-clear-history-result" style="font-size:12px;color:#6b7280;"></span>
                                </div>
                                <table class="widefat" id="swi-stdb-history-table">
                                    <thead><tr><th>Aeg</th><th>Tellimus</th><th>Staatus</th><th>Sõnum</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($stdb_hist as $h) : ?>
                                        <tr>
                                            <td style="white-space:nowrap;"><?php echo esc_html($h['time']); ?></td>
                                            <td>#<?php echo esc_html($h['order_id']); ?></td>
                                            <td><?php echo $h['status'] === 'ok' ? '<span style="color:#16a34a;font-weight:600;">✓ ok</span>' : '<span style="color:#dc2626;font-weight:600;">✗ viga</span>'; ?></td>
                                            <td style="font-size:12px;"><?php echo esc_html($h['message']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php endif; ?>
                                <script>
                                document.addEventListener('DOMContentLoaded', function() {
                                    var clrBtn = document.getElementById('swi-stdb-clear-history-btn');
                                    if (!clrBtn) return;
                                    clrBtn.addEventListener('click', function() {
                                        clrBtn.disabled = true;
                                        jQuery.post(ajaxurl, {action:'swi_stdb_clear_history', security:document.getElementById('swi_stdb_tools_nonce').value}, function(r) {
                                            clrBtn.disabled = false;
                                            if (r.success) { document.querySelector('#swi-panel-stdb-history .swi-card').innerHTML = '<p style="color:#9ca3af;font-size:12.5px;margin:0;">Saatmisi pole veel toimunud.</p>'; }
                                        });
                                    });
                                });
                                </script>
                            </div>
                        </div>

                        <!-- SÜNKRONISEERIMINE -->
                        <div class="swi-panel" id="swi-panel-stdb-sync">
                            <div class="swi-section-title">Sünkroniseerimise kontroll</div>
                            <div class="swi-section-desc">Kontrolli millised tellimused on Standard Booksi saadetud.</div>
                            <div class="swi-card">
                                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                    <button class="button button-secondary" id="swi-stdb-sync-btn">🔄 Kontrolli</button>
                                    <button class="button button-primary" id="swi-stdb-syncall-btn">⬆ Sync kõik</button>
                                    <span id="swi-stdb-sync-spinner" style="display:none;margin-left:2px;">⏳</span>
                                    <span id="swi-stdb-syncall-result" style="font-size:13px;"></span>
                                </div>
                                <div id="swi-stdb-sync-result" style="margin-top:14px;"></div>
                            </div>
                            <script>
                            document.addEventListener('DOMContentLoaded', function() {
                                var nonce = function(){ return document.getElementById('swi_stdb_tools_nonce').value; };
                                var btn = document.getElementById('swi-stdb-sync-btn');
                                if (!btn) return;
                                btn.addEventListener('click', function() {
                                    btn.disabled = true;
                                    document.getElementById('swi-stdb-sync-spinner').style.display = 'inline';
                                    document.getElementById('swi-stdb-sync-result').innerHTML = '';
                                    jQuery.post(ajaxurl, {action:'swi_stdb_sync_check', security:nonce()}, function(r) {
                                        btn.disabled = false;
                                        document.getElementById('swi-stdb-sync-spinner').style.display = 'none';
                                        if (!r.success) { document.getElementById('swi-stdb-sync-result').innerHTML = '<span style="color:#dc2626">⚠ ' + (r.data&&r.data.error||'Viga') + '</span>'; return; }
                                        var d = r.data;
                                        var html = '<p style="margin:0 0 10px;font-size:13px;color:#374151;">Leitud: <strong>' + d.rows.length + '</strong> tellimust, puudub SB-s: <strong style="color:#dc2626">' + d.missing_count + '</strong></p>';
                                        html += '<div style="overflow-x:auto;"><table class="swi-table" style="width:100%;border-collapse:collapse;font-size:13px;">';
                                        html += '<thead><tr><th style="text-align:left;padding:6px 10px;background:#f3f4f6;border-bottom:1px solid #e5e7eb;">WC tellimus</th><th style="text-align:left;padding:6px 10px;background:#f3f4f6;border-bottom:1px solid #e5e7eb;">SB arve ID</th><th style="text-align:left;padding:6px 10px;background:#f3f4f6;border-bottom:1px solid #e5e7eb;">Summa</th><th style="text-align:left;padding:6px 10px;background:#f3f4f6;border-bottom:1px solid #e5e7eb;">Saadetud</th><th style="text-align:left;padding:6px 10px;background:#f3f4f6;border-bottom:1px solid #e5e7eb;">Staatus</th><th style="padding:6px 10px;background:#f3f4f6;border-bottom:1px solid #e5e7eb;"></th></tr></thead><tbody>';
                                        d.rows.forEach(function(row) {
                                            var status = row.in_stdb
                                                ? '<span style="color:#16a34a;font-weight:600;">✓ saadetud</span>'
                                                : '<span style="color:#dc2626;font-weight:600;">— puudub</span>';
                                            var btn_html = row.in_stdb
                                                ? '<button class="button button-small swi-stdb-unmark-btn" data-id="'+row.order_id+'" style="color:#dc2626;border-color:#dc2626;">Tühista</button>'
                                                : '<button class="button button-small swi-stdb-row-send-btn" data-id="'+row.order_id+'">Saada</button>';
                                            html += '<tr style="border-bottom:1px solid #f3f4f6;">'
                                                + '<td style="padding:6px 10px;">#'+row.order_id+'</td>'
                                                + '<td style="padding:6px 10px;font-size:12px;color:#6b7280;">'+(row.inv_id||'—')+'</td>'
                                                + '<td style="padding:6px 10px;">'+row.total_html+'</td>'
                                                + '<td style="padding:6px 10px;font-size:12px;color:#6b7280;">'+(row.meta_sent||'—')+'</td>'
                                                + '<td style="padding:6px 10px;">'+status+'</td>'
                                                + '<td style="padding:6px 10px;">'+btn_html+'</td>'
                                                + '</tr>';
                                        });
                                        html += '</tbody></table></div>';
                                        document.getElementById('swi-stdb-sync-result').innerHTML = html;
                                    }).fail(function(){ btn.disabled=false; document.getElementById('swi-stdb-sync-spinner').style.display='none'; document.getElementById('swi-stdb-sync-result').innerHTML='<span style="color:#dc2626">AJAX viga.</span>'; });
                                });
                                // Sync kõik
                                var syncallBtn = document.getElementById('swi-stdb-syncall-btn');
                                if (syncallBtn) {
                                    syncallBtn.addEventListener('click', function() {
                                        var res = document.getElementById('swi-stdb-syncall-result');
                                        syncallBtn.disabled = true;
                                        res.innerHTML = '<span style="color:#6b7280">⏳ Saadan...</span>';
                                        jQuery.post(ajaxurl, {action:'swi_stdb_bulk_send', security:nonce()}, function(r) {
                                            syncallBtn.disabled = false;
                                            if (r.success) {
                                                var d = r.data;
                                                res.innerHTML = '<span style="color:'+(d.failed>0?'#d97706':'#16a34a')+';font-weight:600;">'+d.message+'</span>';
                                                if (d.errors && d.errors.length) res.innerHTML += '<div style="margin-top:6px;font-size:12px;color:#dc2626;">'+d.errors.join('<br>')+'</div>';
                                            } else {
                                                res.innerHTML = '<span style="color:#dc2626;font-weight:600;">✗ '+(r.data&&r.data.error||'Viga')+'</span>';
                                            }
                                        }).fail(function(){ syncallBtn.disabled=false; res.innerHTML='<span style="color:#dc2626">AJAX viga.</span>'; });
                                    });
                                }
                                jQuery(document).on('click', '.swi-stdb-unmark-btn', function() {
                                    var b = jQuery(this), id = b.data('id');
                                    b.prop('disabled', true).text('...');
                                    jQuery.post(ajaxurl, {action:'swi_stdb_reset_single', security:nonce(), order_id:id}, function(r) {
                                        if (r.success) { b.closest('tr').find('td:nth-child(5)').html('<span style="color:#dc2626;font-weight:600;">— puudub</span>'); b.replaceWith('<button class="button button-small swi-stdb-row-send-btn" data-id="'+id+'">Saada</button>'); }
                                        else { b.prop('disabled', false).text('Tühista'); alert(r.data&&r.data.error||'Viga'); }
                                    });
                                });
                                jQuery(document).on('click', '.swi-stdb-row-send-btn', function() {
                                    var b = jQuery(this), id = b.data('id');
                                    b.prop('disabled', true).text('...');
                                    jQuery.post(ajaxurl, {action:'swi_stdb_order_send', security:nonce(), order_id:id}, function(r) {
                                        if (r.success) { b.closest('tr').find('td:nth-child(5)').html('<span style="color:#16a34a;font-weight:600;">✓ saadetud</span>'); b.replaceWith('<button class="button button-small swi-stdb-unmark-btn" data-id="'+id+'" style="color:#dc2626;border-color:#dc2626;">Tühista</button>'); }
                                        else { b.prop('disabled', false).text('Saada'); alert(r.data&&r.data.error||'Viga'); }
                                    });
                                });
                            });
                            </script>
                        </div>

                        <!-- TÖÖRIISTAD -->
                        <div class="swi-panel" id="swi-panel-stdb-tools">
                            <div class="swi-section-title">Standard Books – Tööriistad</div>
                            <div class="swi-card" style="margin-bottom:20px;">
                                <p style="margin:0 0 8px;font-weight:600;font-size:12.5px;">Käsitsi saatmine</p>
                                <p style="margin:0 0 12px;font-size:12px;color:#6b7280;">Saada üksik tellimus Standard Booksi.</p>
                                <input type="number" id="swi-stdb-manual-id" placeholder="Tellimuse ID" style="width:140px;margin-right:8px;" class="regular-text">
                                <button class="button" id="swi-stdb-manual-btn">Saada SB-sse</button>
                                <span id="swi-stdb-manual-result" style="margin-left:10px;font-size:13px;"></span>
                            </div>
                            <div class="swi-card" style="margin-bottom:20px;">
                                <p style="margin:0 0 8px;font-weight:600;font-size:12.5px;">JSON eelvaade</p>
                                <p style="margin:0 0 12px;font-size:12px;color:#6b7280;">Näita mis payload saadetaks ilma päriselt saatmata.</p>
                                <input type="number" id="swi-stdb-preview-id" placeholder="Tellimuse ID" style="width:140px;margin-right:8px;" class="regular-text">
                                <button type="button" id="swi-stdb-preview-btn" class="button button-secondary">Näita JSON</button>
                                <pre id="swi-stdb-preview-result" style="display:none;margin-top:12px;background:#f3f4f6;padding:12px;border-radius:4px;font-size:11px;overflow:auto;max-height:400px;"></pre>
                            </div>
                            <div class="swi-card">
                                <p style="margin:0 0 8px;font-weight:600;font-size:12.5px;">Tühista kõik saatmise märgid</p>
                                <p style="margin:0 0 12px;font-size:12px;color:#6b7280;">Eemaldab kõik "_swi_sent_stdb" märgid — kasulik kui Standard Booksist kustutati arved ja soovid uuesti saata.</p>
                                <button class="button" id="swi-stdb-reset-btn" style="color:#dc2626;border-color:#dc2626;">⚠ Tühista saatmise märgid</button>
                                <div id="swi-stdb-reset-result" style="margin-top:8px;font-size:13px;"></div>
                            </div>
                            <script>
                            document.addEventListener('DOMContentLoaded', function() {
                                var nonce = function(){ return document.getElementById('swi_stdb_tools_nonce').value; };
                                var manBtn = document.getElementById('swi-stdb-manual-btn');
                                if (manBtn) manBtn.addEventListener('click', function() {
                                    var id = document.getElementById('swi-stdb-manual-id').value;
                                    var res = document.getElementById('swi-stdb-manual-result');
                                    if (!id) { res.innerHTML = '<span style="color:#dc2626">Sisesta tellimuse ID.</span>'; return; }
                                    manBtn.disabled = true; manBtn.textContent = '...';
                                    jQuery.post(ajaxurl, {action:'swi_stdb_order_send', security:nonce(), order_id:id}, function(r) {
                                        manBtn.disabled = false; manBtn.textContent = 'Saada SB-sse';
                                        res.innerHTML = r.success
                                            ? '<span style="color:#16a34a;font-weight:600;">✓ ' + r.data.message + '</span>'
                                            : '<span style="color:#dc2626;font-weight:600;">✗ ' + (r.data&&r.data.error||'Viga') + '</span>';
                                    }).fail(function(){ manBtn.disabled=false; manBtn.textContent='Saada SB-sse'; });
                                });
                                var prvBtn = document.getElementById('swi-stdb-preview-btn');
                                if (prvBtn) prvBtn.addEventListener('click', function() {
                                    var id = document.getElementById('swi-stdb-preview-id').value;
                                    var pre = document.getElementById('swi-stdb-preview-result');
                                    if (!id) { pre.style.display='block'; pre.textContent='Sisesta tellimuse ID.'; return; }
                                    prvBtn.disabled = true; prvBtn.textContent = 'Laen...';
                                    jQuery.post(ajaxurl, {action:'swi_stdb_preview', security:nonce(), order_id:id}, function(r) {
                                        prvBtn.disabled = false; prvBtn.textContent = 'Näita JSON';
                                        pre.style.display = 'block';
                                        pre.textContent = r.success ? JSON.stringify(r.data.payload, null, 2) : '✗ ' + (r.data&&r.data.error||'Viga');
                                    }).fail(function(){ prvBtn.disabled=false; prvBtn.textContent='Näita JSON'; });
                                });
                                var rstBtn = document.getElementById('swi-stdb-reset-btn');
                                if (rstBtn) rstBtn.addEventListener('click', function() {
                                    if (!confirm('Oled kindel? Kõik Standard Books saatmise märgid eemaldatakse.')) return;
                                    rstBtn.disabled = true;
                                    jQuery.post(ajaxurl, {action:'swi_stdb_reset_sent', security:nonce()}, function(r) {
                                        rstBtn.disabled = false;
                                        document.getElementById('swi-stdb-reset-result').innerHTML = r.success
                                            ? '<span style="color:#16a34a;">✓ ' + r.data.message + '</span>'
                                            : '<span style="color:#dc2626;">✗ ' + (r.data&&r.data.error||'Viga') + '</span>';
                                    }).fail(function(){ rstBtn.disabled=false; });
                                });
                            });
                            </script>
                        </div>

                    </div>
                </div>

                <!-- ══ TAB: ÄRIREGISTRI MOODUL ══ -->
                <div class="swi-tabview" id="swi-tab-rik">
                    <nav class="swi-sidebar">
                        <div class="swi-sidebar-label">Äriregistri moodul</div>
                        <div class="swi-nav-item active" data-panel="rik-general" onclick="swiPanel('rik-general', this, 'rik')">
                            <span class="swi-nav-icon">⚙</span> Üldseaded
                        </div>
                        <div class="swi-nav-item" data-panel="rik-fields" onclick="swiPanel('rik-fields', this, 'rik')">
                            <span class="swi-nav-icon">📋</span> Kassaväljad
                        </div>
                    </nav>
                    <div class="swi-content">

                        <!-- ── Üldseaded ── -->
                        <div class="swi-panel active" id="swi-panel-rik-general">
                            <div class="swi-section-title">Äriregistri moodul – Üldseaded</div>
                            <div class="swi-section-desc">
                                Lisab kassasse registrikoodi välja. Kui ostja sisestab registrikoodi, täidetakse automaatselt ettevõtte nimi, KMKR nr ja aadress Eesti äriregistrist.
                            </div>
                            <input type="hidden" id="swi_rik_nonce" value="<?php echo wp_create_nonce('my_nonce'); ?>">
                            <div class="swi-card" style="margin-bottom:20px;">
                                <?php $this->render_toggle('swi_rik_enable', 'Luba Äriregistri moodul', $rik_on); ?>
                                <table class="form-table" style="margin-top:16px;">
                                    <tr>
                                        <th style="width:200px;"><label for="swi_rik_license_key">Litsentsi võti</label></th>
                                        <td>
                                            <input type="text" id="swi_rik_license_key" name="swi_rik_license_key"
                                                   class="regular-text"
                                                   value="<?php echo esc_attr( get_option('swi_rik_license_key', '') ); ?>"
                                                   placeholder="Kopeeri rakenduse litsentsi lehelt">
                                            <p class="description">Äriregistri mooduli litsentsi võti Smart WP rakendusest.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label for="swi_rik_crypto_key">Krüptovõti (HEX)</label></th>
                                        <td>
                                            <input type="text" id="swi_rik_crypto_key" name="swi_rik_crypto_key"
                                                   class="regular-text"
                                                   value="<?php echo esc_attr( get_option('swi_rik_crypto_key', '') ); ?>"
                                                   placeholder="64-märgiline HEX">
                                            <p class="description">64-märgiline HEX — kopeeri rakenduse litsentsi lehelt.</p>
                                        </td>
                                    </tr>
                                </table>
                                <div style="margin-top:12px;">
                                    <button type="button" id="swi-rik-test-btn" class="button button-secondary">🔌 Testi ühendust</button>
                                    <span id="swi-rik-test-result" style="margin-left:10px;font-size:13px;"></span>
                                </div>
                                <script>
                                document.getElementById('swi-rik-test-btn').addEventListener('click', function() {
                                    var btn = this;
                                    var res = document.getElementById('swi-rik-test-result');
                                    btn.disabled = true;
                                    res.textContent = 'Kontrollin...';
                                    res.style.color = '#6b7280';
                                    jQuery.post(ajaxurl, {
                                        action:   'swi_rik_test',
                                        security: document.getElementById('swi_rik_nonce').value,
                                    }, function(r) {
                                        btn.disabled = false;
                                        if (r.success) {
                                            res.textContent = '✓ Ühendus töötab — leitud: ' + (r.data.name || '');
                                            res.style.color = '#16a34a';
                                        } else {
                                            res.textContent = '✗ ' + (r.data.error || 'Viga');
                                            res.style.color = '#dc2626';
                                        }
                                    }).fail(function() {
                                        btn.disabled = false;
                                        res.textContent = '✗ Serveri viga';
                                        res.style.color = '#dc2626';
                                    });
                                });
                                </script>
                            </div>
                            <div class="swi-alert info" style="max-width:680px;">
                                ℹ <div>
                                    <strong>Kuidas töötab:</strong><br>
                                    Kassas ilmub "Registrikood" väli. Kui ostja sisestab ettevõtte registrikoodi (7–8 numbrit), päritakse automaatselt Eesti äriregistrist:<br>
                                    <ul style="margin:8px 0 0 16px;">
                                        <li>Ettevõtte nimi → täidab "Ettevõte" välja</li>
                                        <li>KMKR number → täidab "KMKR nr" välja</li>
                                        <li>Registreeritud aadress → täidab "Aadress" välja</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- ── Kassaväljad ── -->
                        <div class="swi-panel" id="swi-panel-rik-fields">
                            <div class="swi-section-title">Äriregistri moodul – Kassaväljad</div>
                            <div class="swi-section-desc">Seadista kuidas registrikoodi ja KMKR väljad kassas käituvad.</div>
                            <div class="swi-card">
                                <table class="form-table">
                                    <tr>
                                        <th style="width:200px;"><label for="swi_rik_reg_label">Registrikoodi välja silt</label></th>
                                        <td>
                                            <input type="text" id="swi_rik_reg_label" name="swi_rik_reg_label"
                                                   class="regular-text"
                                                   value="<?php echo esc_attr( get_option('swi_rik_reg_label', 'Registrikood') ); ?>"
                                                   placeholder="Registrikood">
                                            <p class="description">Vaikimisi "Registrikood". Kuvatakse kassas välja pealkirjana.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label for="swi_rik_vat_label">KMKR nr välja silt</label></th>
                                        <td>
                                            <input type="text" id="swi_rik_vat_label" name="swi_rik_vat_label"
                                                   class="regular-text"
                                                   value="<?php echo esc_attr( get_option('swi_rik_vat_label', 'KMKR nr') ); ?>"
                                                   placeholder="KMKR nr">
                                            <p class="description">Vaikimisi "KMKR nr".</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Kuva KMKR nr väli</th>
                                        <td>
                                            <?php $this->render_toggle('swi_rik_show_vat', 'Näita KMKR nr välja kassas', get_option('swi_rik_show_vat', 'yes') === 'yes'); ?>
                                            <p class="description" style="margin-top:6px;">Lülita välja kui e-pood müüb ainult eraisikutele.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Registrikood kohustuslik B2B korral</th>
                                        <td>
                                            <?php $this->render_toggle('swi_rik_reg_required', 'Nõua registrikoodi kui "Ettevõte" väli on täidetud', get_option('swi_rik_reg_required', 'no') === 'yes'); ?>
                                            <p class="description" style="margin-top:6px;">Kui sisse lülitatud, on registrikood kohustuslik äriklientidele.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Täida aadress automaatselt</th>
                                        <td>
                                            <?php $this->render_toggle('swi_rik_autofill_address', 'Täida arvelduaadress äriregistrist automaatselt', get_option('swi_rik_autofill_address', 'yes') === 'yes'); ?>
                                            <p class="description" style="margin-top:6px;">Lülita välja kui kliendid tahavad aadressi ise sisestada.</p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                    </div>
                </div>


                <!-- ══ TAB: SMARTPOST ══ -->
                <div class="swi-tabview" id="swi-tab-smartpost">
                    <nav class="swi-sidebar">
                        <div class="swi-sidebar-label">Smartpost</div>
                        <div class="swi-nav-item active" data-panel="sp-general" onclick="swiPanel('sp-general', this, 'smartpost')">
                            <span class="swi-nav-icon">⚙</span> Üldseaded
                        </div>
                        <div class="swi-nav-item" data-panel="sp-tools" onclick="swiPanel('sp-tools', this, 'smartpost')">
                            <span class="swi-nav-icon">🔧</span> Tööriistad
                        </div>
                    </nav>
                    <div class="swi-content">

                        <div class="swi-panel active" id="swi-panel-sp-general">
                            <div class="swi-section-title">Smartpost – Üldseaded</div>
                            <div class="swi-section-desc">Itella Smartpost pakiautomaadid WooCommerce kassas.</div>
                            <input type="hidden" id="swi_sp_nonce" value="<?php echo wp_create_nonce('my_nonce'); ?>">
                            <?php
                            $sp_countries = (array) get_option('swi_smartpost_countries', ['EE']);
                            $sp_prices    = json_decode( get_option('swi_smartpost_prices', '{}'), true ) ?: [];
                            $sp_cc_list   = ['EE' => 'Eesti', 'FI' => 'Soome', 'LV' => 'Läti', 'LT' => 'Leedu', 'SE' => 'Rootsi'];
                            $sp_sizes     = ['xs' => 'XS', 's' => 'S', 'm' => 'M', 'l' => 'L', 'xl' => 'XL'];
                            $wc_statuses  = wc_get_order_statuses();
                            ?>
                            <!-- Üldseaded -->
                            <div class="swi-card" style="margin-bottom:20px;">
                                <?php $this->render_toggle('swi_smartpost_enable', 'Luba Smartpost pakiautomaadid', $smartpost_on); ?>
                                <table class="form-table" style="margin-top:16px;">
                                    <tr>
                                        <th style="width:200px;"><label for="swi_smartpost_license_key">Litsentsi võti</label></th>
                                        <td><input type="text" id="swi_smartpost_license_key" name="swi_smartpost_license_key" class="regular-text" value="<?php echo esc_attr( get_option('swi_smartpost_license_key', '') ); ?>" placeholder="Kopeeri rakenduse litsentsi lehelt"></td>
                                    </tr>
                                    <tr>
                                        <th><label for="swi_smartpost_crypto_key">Krüptovõti (HEX)</label></th>
                                        <td><input type="text" id="swi_smartpost_crypto_key" name="swi_smartpost_crypto_key" class="regular-text" value="<?php echo esc_attr( get_option('swi_smartpost_crypto_key', '') ); ?>" placeholder="64-märgiline HEX"></td>
                                    </tr>
                                    <tr>
                                        <th><label for="swi_smartpost_title">Kuvatav nimi kassas</label></th>
                                        <td><input type="text" id="swi_smartpost_title" name="swi_smartpost_title" class="regular-text" value="<?php echo esc_attr( get_option('swi_smartpost_title', 'Smartpost pakiautomaat') ); ?>"></td>
                                    </tr>
                                </table>
                            </div>

                            <!-- Saatja info -->
                            <div class="swi-card" style="margin-bottom:20px;">
                                <p style="margin:0 0 12px;font-weight:600;font-size:13px;">Saatja andmed</p>
                                <table class="form-table">
                                    <tr>
                                        <th style="width:200px;"><label for="swi_smartpost_sender_name">Saatja nimi</label></th>
                                        <td><input type="text" id="swi_smartpost_sender_name" name="swi_smartpost_sender_name" class="regular-text" value="<?php echo esc_attr( get_option('swi_smartpost_sender_name', '') ); ?>"></td>
                                    </tr>
                                    <tr>
                                        <th><label for="swi_smartpost_sender_phone">Saatja telefon</label></th>
                                        <td><input type="text" id="swi_smartpost_sender_phone" name="swi_smartpost_sender_phone" class="regular-text" value="<?php echo esc_attr( get_option('swi_smartpost_sender_phone', '') ); ?>"></td>
                                    </tr>
                                    <tr>
                                        <th><label for="swi_smartpost_sender_email">Saatja email</label></th>
                                        <td><input type="email" id="swi_smartpost_sender_email" name="swi_smartpost_sender_email" class="regular-text" value="<?php echo esc_attr( get_option('swi_smartpost_sender_email', '') ); ?>"></td>
                                    </tr>
                                </table>
                            </div>

                            <!-- Pakisildi seaded -->
                            <div class="swi-card" style="margin-bottom:20px;">
                                <p style="margin:0 0 4px;font-weight:600;font-size:13px;">Pakisildi seaded</p>
                                <?php $this->render_toggle('swi_smartpost_auto_send',      'Paki andmed saadetakse automaatselt',               get_option('swi_smartpost_auto_send')      === 'yes'); ?>
                                <?php $this->render_toggle('swi_smartpost_add_tracking',   'Lisa jälgimiskood täidetud tellimuse e-mailile',    get_option('swi_smartpost_add_tracking')   === 'yes'); ?>
                                <?php $this->render_toggle('swi_smartpost_send_label_copy','Saada pakisildi koopia e-mailile',                  get_option('swi_smartpost_send_label_copy') === 'yes'); ?>
                                <?php $this->render_toggle('swi_smartpost_mobile_classic', 'Kuva telefonis klassikalist pakiautomaadi valikut', get_option('swi_smartpost_mobile_classic')  === 'yes'); ?>
                                <table class="form-table" style="margin-top:8px;">
                                    <tr>
                                        <th style="width:230px;"><label for="swi_smartpost_label_size">Pakisildi mõõt</label></th>
                                        <td>
                                            <select id="swi_smartpost_label_size" name="swi_smartpost_label_size">
                                                <?php foreach (['A4' => 'A4', 'A5' => 'A5', 'A6' => 'A6', 'THERMAL' => 'Thermal (102×210)'] as $v => $l) : ?>
                                                <option value="<?php echo $v; ?>" <?php selected( get_option('swi_smartpost_label_size', 'A4'), $v ); ?>><?php echo $l; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label for="swi_smartpost_status_after_label">Olek peale printimist</label></th>
                                        <td>
                                            <select id="swi_smartpost_status_after_label" name="swi_smartpost_status_after_label">
                                                <option value="">— Ära muuda —</option>
                                                <?php foreach ( $wc_statuses as $slug => $label ) : ?>
                                                <option value="<?php echo esc_attr($slug); ?>" <?php selected( get_option('swi_smartpost_status_after_label', ''), $slug ); ?>><?php echo esc_html($label); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label for="swi_smartpost_label_email">Koopia e-mail aadress</label></th>
                                        <td><input type="email" id="swi_smartpost_label_email" name="swi_smartpost_label_email" class="regular-text" value="<?php echo esc_attr( get_option('swi_smartpost_label_email', '') ); ?>" placeholder="email@näide.ee"></td>
                                    </tr>
                                </table>
                            </div>

                            <!-- Lubatud riigid + hinnad -->
                            <div class="swi-card" style="margin-bottom:20px;">
                                <p style="margin:0 0 12px;font-weight:600;font-size:13px;">Lubatud riigid ja hinnad</p>
                                <p style="margin:0 0 16px;font-size:12.5px;color:#6b7280;">Märgi riik aktiivseks ja sisesta hinnad (€, km-ta). Tasuta alates: ostukorvi summa millest alates saatmine on tasuta.</p>
                                <table style="border-collapse:collapse;width:100%;font-size:13px;">
                                    <thead>
                                        <tr style="background:#f9f9f9;">
                                            <th style="padding:8px 10px;text-align:left;border:1px solid #e5e7eb;width:100px;">Riik</th>
                                            <?php foreach ($sp_sizes as $sk => $sl) : ?>
                                            <th style="padding:8px 10px;text-align:center;border:1px solid #e5e7eb;"><?php echo $sl; ?></th>
                                            <?php endforeach; ?>
                                            <th style="padding:8px 10px;text-align:center;border:1px solid #e5e7eb;">Tasuta alates</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($sp_cc_list as $cc => $ccname) :
                                        $active = in_array($cc, $sp_countries, true);
                                        $prices = $sp_prices[$cc] ?? [];
                                    ?>
                                        <tr>
                                            <td style="padding:8px 10px;border:1px solid #e5e7eb;">
                                                <label><input type="checkbox" name="swi_smartpost_countries[]" value="<?php echo $cc; ?>" <?php checked($active); ?>> <?php echo $ccname; ?></label>
                                            </td>
                                            <?php foreach ($sp_sizes as $sk => $sl) : ?>
                                            <td style="padding:6px 8px;border:1px solid #e5e7eb;text-align:center;">
                                                <input type="text" name="swi_sp_price[<?php echo $cc; ?>][<?php echo $sk; ?>]"
                                                       value="<?php echo esc_attr( $prices[$sk] ?? '' ); ?>"
                                                       style="width:64px;text-align:center;" placeholder="—">
                                            </td>
                                            <?php endforeach; ?>
                                            <td style="padding:6px 8px;border:1px solid #e5e7eb;text-align:center;">
                                                <input type="text" name="swi_sp_price[<?php echo $cc; ?>][free]"
                                                       value="<?php echo esc_attr( $prices['free'] ?? '' ); ?>"
                                                       style="width:64px;text-align:center;" placeholder="—">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <p style="margin:10px 0 0;font-size:12px;color:#9ca3af;">XS ≤ 2 kg &nbsp;|&nbsp; S ≤ 5 kg &nbsp;|&nbsp; M ≤ 10 kg &nbsp;|&nbsp; L ≤ 20 kg &nbsp;|&nbsp; XL ≤ 35 kg</p>
                            </div>
                        </div>

                        <div class="swi-panel" id="swi-panel-sp-tools">
                            <div class="swi-section-title">Smartpost – Tööriistad</div>
                            <div class="swi-section-desc">Pakiautomaatide nimekiri laetakse Itella serverist ja salvestatakse 12 tunniks vahemällu.</div>
                            <div class="swi-card">
                                <p style="margin:0 0 8px;font-weight:600;font-size:12.5px;">Tühjenda pakiautomaatide cache</p>
                                <button type="button" id="swi-sp-flush-btn" class="button button-secondary">🔄 Tühjenda cache</button>
                                <span id="swi-sp-flush-result" style="margin-left:10px;font-size:13px;"></span>
                                <script>
                                document.getElementById('swi-sp-flush-btn').addEventListener('click', function(){
                                    var btn = this, res = document.getElementById('swi-sp-flush-result');
                                    btn.disabled = true; res.textContent = 'Tühjendab...'; res.style.color = '#6b7280';
                                    jQuery.post(ajaxurl, {action:'swi_smartpost_flush_cache', security:document.getElementById('swi_sp_nonce').value}, function(r){
                                        btn.disabled = false;
                                        if(r.success){ res.textContent = '✓ ' + r.data.message; res.style.color = '#16a34a'; }
                                        else { res.textContent = '✗ Viga'; res.style.color = '#dc2626'; }
                                    }).fail(function(){ btn.disabled = false; res.textContent = '✗ Serveri viga'; res.style.color = '#dc2626'; });
                                });
                                </script>
                            </div>
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

            // WooCommerce seab window.onbeforeunload kui vorm on "dirty".
            // Clearime KÕIK beforeunload handlerid iga kord kui meie vorm submitib.
            jQuery('#mainform').on('submit', function() {
                window.onbeforeunload = null;
                jQuery(window).off('beforeunload beforeunload.admin_settings beforeunload.wc_settings');
            });

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

                // Feature 7: Kustuta ajalugu (Merit)
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

                // ══ SIMPLEBOOKS JS ══

                // SB: Sünkroniseeri kõik (bulk send)
                var sbBulkBtn = document.getElementById('swi-sb-bulk-btn');
                if (sbBulkBtn) {
                    sbBulkBtn.addEventListener('click', function() {
                        if (!confirm('Saada kõik saadetamata tellimused Simplebooks\'i? Toiming võib võtta kuni 30 sekundit.')) return;
                        var spinner = document.getElementById('swi-sb-bulk-spinner');
                        var result  = document.getElementById('swi-sb-bulk-result');
                        sbBulkBtn.disabled = true;
                        spinner.style.display = 'inline';
                        result.innerHTML = '';
                        jQuery.post(ajaxurl, {
                            action: 'swi_sb_bulk_send',
                            security: MyAjax.nonce
                        }, function(resp) {
                            sbBulkBtn.disabled = false;
                            spinner.style.display = 'none';
                            if (resp.success) {
                                var d = resp.data;
                                var color = d.failed > 0 ? '#b32d2e' : '#0a6b23';
                                result.innerHTML = '<span style="color:' + color + ';font-weight:600;">' + d.message + '</span>';
                                if (d.errors && d.errors.length) {
                                    result.innerHTML += '<ul style="margin:8px 0 0;padding-left:18px;font-size:11.5px;color:#b32d2e;">'
                                        + d.errors.map(function(e){ return '<li>' + e + '</li>'; }).join('')
                                        + '</ul>';
                                }
                                if (d.sent > 0) swiNotify(d.sent + ' arvet edastatud Simplebooks\'i.', 'ok');
                            } else {
                                var msg = (resp.data && resp.data.error) ? resp.data.error : 'Tundmatu viga';
                                result.innerHTML = '<span style="color:#b32d2e">⚠ ' + msg + '</span>';
                            }
                        }).fail(function() {
                            sbBulkBtn.disabled = false;
                            spinner.style.display = 'none';
                            result.innerHTML = '<span style="color:#b32d2e">⚠ Serveri ühendus katkes.</span>';
                        });
                    });
                }

                // SB: Kustuta ajalugu
                var sbClearBtn = document.getElementById('swi-sb-clear-history-btn');
                if (sbClearBtn) {
                    sbClearBtn.addEventListener('click', function() {
                        if (!confirm('Kustuta Simplebooks saatmise ajalugu?')) return;
                        sbClearBtn.disabled = true;
                        jQuery.post(ajaxurl, {
                            action: 'swi_sb_clear_history',
                            security: MyAjax.nonce
                        }, function(resp) {
                            if (resp.success) {
                                swiNotify('Simplebooks ajalugu kustutatud.', 'ok');
                                setTimeout(function(){ window.location.reload(); }, 1000);
                            } else {
                                sbClearBtn.disabled = false;
                                swiNotify('Kustutamine ebaõnnestus.', 'error');
                            }
                        });
                    });
                }

                // SB: Sünkroniseerimise kontroll
                var sbSyncBtn = document.getElementById('swi-sb-sync-btn');
                if (sbSyncBtn) {
                    sbSyncBtn.addEventListener('click', function() {
                        var spinner = document.getElementById('swi-sb-sync-spinner');
                        var result  = document.getElementById('swi-sb-sync-result');
                        sbSyncBtn.disabled = true;
                        spinner.style.display = 'inline';
                        result.innerHTML = '';
                        jQuery.post(ajaxurl, {
                            action: 'swi_sb_sync_check',
                            security: MyAjax.nonce
                        }, function(resp) {
                            sbSyncBtn.disabled = false;
                            spinner.style.display = 'none';
                            if (!resp.success) {
                                result.innerHTML = '<div class="swi-alert err">⚠ ' + (resp.data && resp.data.error ? resp.data.error : 'Viga') + '</div>';
                                return;
                            }
                            var rows = resp.data.rows;
                            var missing = rows.filter(function(r){ return !r.in_sb; });
                            if (missing.length === 0) {
                                result.innerHTML = '';
                                swiNotify('Kõik arved on Simplebooksiga sünkroniseeritud ✓', 'ok');
                                return;
                            }
                            var html = '<p style="margin:0 0 8px;"><span style="color:#b32d2e"><strong>' + missing.length + ' arvet</strong> puudub Simplebooksist</span></p>';
                            html += '<table class="widefat striped" style="max-width:860px;"><thead><tr><th>Arve nr</th><th>Kuupäev</th><th>Summa</th><th>Saadetud</th><th></th></tr></thead><tbody>';
                            missing.forEach(function(r) {
                                html += '<tr><td><a href="post.php?post=' + r.order_id + '&action=edit" target="_blank">' + r.invoice_no + '</a></td>'
                                    + '<td>' + r.date + '</td>'
                                    + '<td>' + r.total + '</td>'
                                    + '<td style="font-size:11px;color:#666">' + (r.meta_sent || '—') + '</td>'
                                    + '<td><button type="button" class="button button-small swi-sb-resend-btn" data-id="' + r.order_id + '">Saada uuesti</button></td></tr>';
                            });
                            html += '</tbody></table>';
                            result.innerHTML = html;
                            result.querySelectorAll('.swi-sb-resend-btn').forEach(function(b) {
                                b.addEventListener('click', function() {
                                    var orderId = this.getAttribute('data-id');
                                    var row = this.closest('tr');
                                    this.disabled = true; this.textContent = 'Saadan...';
                                    var self = this;
                                    jQuery.post(ajaxurl, {
                                        action: 'swi_sb_sync_resend',
                                        security: MyAjax.nonce,
                                        order_id: orderId
                                    }, function(r2) {
                                        if (r2.success) {
                                            row.cells[3].innerHTML = '<span style="color:#0a6b23">✓ Saadetud</span>';
                                            row.cells[4].innerHTML = '';
                                            swiNotify('Arve ' + orderId + ' edastatud Simplebooks\'i.', 'ok');
                                        } else {
                                            self.textContent = 'Saada uuesti'; self.disabled = false;
                                            var msg = (r2.data && r2.data.error) ? r2.data.error : 'Tundmatu viga';
                                            swiNotify('Viga: ' + msg, 'error');
                                        }
                                    });
                                });
                            });
                        }).fail(function() {
                            sbSyncBtn.disabled = false;
                            spinner.style.display = 'none';
                            result.innerHTML = '<div class="swi-alert err">⚠ Serveri ühendus katkes.</div>';
                        });
                    });
                }

                // SB: Käsitsi saatmine
                var sbManualBtn = document.getElementById('swi-sb-manual-btn');
                if (sbManualBtn) {
                    sbManualBtn.addEventListener('click', function() {
                        var orderId = document.getElementById('swi-sb-manual-id').value;
                        var resultEl = document.getElementById('swi-sb-manual-result');
                        if (!orderId) { resultEl.textContent = 'Sisesta tellimuse ID.'; return; }
                        sbManualBtn.disabled = true;
                        resultEl.textContent = 'Saadan...';
                        jQuery.post(ajaxurl, {
                            action: 'swi_sb_manual_send',
                            security: MyAjax.nonce,
                            order_id: orderId
                        }, function(resp) {
                            sbManualBtn.disabled = false;
                            if (resp.success) {
                                resultEl.innerHTML = '<span style="color:#0a6b23">✓ ' + (resp.data.message || 'Edastatud.') + '</span>';
                                swiNotify(resp.data.message || 'Arve edastatud.', 'ok');
                            } else {
                                var msg = (resp.data && resp.data.error) ? resp.data.error : 'Tundmatu viga';
                                resultEl.innerHTML = '<span style="color:#b32d2e">⚠ ' + msg + '</span>';
                            }
                        }).fail(function() {
                            sbManualBtn.disabled = false;
                            resultEl.textContent = 'Serveri ühendus katkes.';
                        });
                    });
                }

                // SB: Arve eelvaade (JSON)
                var sbPreviewBtn = document.getElementById('swi-sb-preview-btn');
                if (sbPreviewBtn) {
                    sbPreviewBtn.addEventListener('click', function() {
                        var orderId = document.getElementById('swi-sb-preview-id').value;
                        var pre = document.getElementById('swi-sb-preview-result');
                        if (!orderId) { pre.style.display='block'; pre.textContent = 'Sisesta tellimuse ID.'; return; }
                        sbPreviewBtn.disabled = true;
                        jQuery.post(ajaxurl, {
                            action: 'swi_sb_manual_send',
                            security: MyAjax.nonce,
                            order_id: orderId,
                            preview_only: '1'
                        }, function(resp) {
                            sbPreviewBtn.disabled = false;
                            pre.style.display = 'block';
                            if (resp.success && resp.data.payload) {
                                pre.textContent = JSON.stringify(resp.data.payload, null, 2);
                            } else if (resp.data && resp.data.error) {
                                pre.textContent = 'Viga: ' + resp.data.error;
                            } else {
                                pre.textContent = JSON.stringify(resp, null, 2);
                            }
                        }).fail(function() {
                            sbPreviewBtn.disabled = false;
                            pre.style.display = 'block';
                            pre.textContent = 'Serveri ühendus katkes.';
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
                'swi_smartaccounts_payment_days',
                'swi_erply_license_key',
                'swi_erply_crypto_key',
                'swi_stdb_license_key',
                'swi_stdb_crypto_key',
                'swi_rik_license_key',
                'swi_rik_crypto_key',
                'swi_rik_reg_label',
                'swi_rik_vat_label',
                'swi_smartpost_license_key',
                'swi_smartpost_crypto_key',
                'swi_smartpost_title',
                'swi_smartpost_sender_name',
                'swi_smartpost_sender_phone',
                'swi_smartpost_sender_email',
                'swi_smartpost_label_email',
            ] as $field ) {
                if ( isset( $_POST[ $field ] ) ) {
                    update_option( $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
                }
            }

            // Smartpost toggles
            foreach ( [ 'swi_smartpost_auto_send', 'swi_smartpost_add_tracking', 'swi_smartpost_send_label_copy', 'swi_smartpost_mobile_classic' ] as $tog ) {
                update_option( $tog, isset( $_POST[ $tog ] ) && $_POST[ $tog ] === 'yes' ? 'yes' : 'no' );
            }

            // Smartpost select fields
            foreach ( [ 'swi_smartpost_label_size', 'swi_smartpost_status_after_label' ] as $sel ) {
                if ( isset( $_POST[ $sel ] ) ) {
                    update_option( $sel, sanitize_text_field( wp_unslash( $_POST[ $sel ] ) ) );
                }
            }

            // Smartpost countries (checkboxes → array)
            $sp_countries = isset( $_POST['swi_smartpost_countries'] ) ? array_map( 'sanitize_text_field', (array) $_POST['swi_smartpost_countries'] ) : [];
            update_option( 'swi_smartpost_countries', $sp_countries );

            // Smartpost hinnad (per country per size)
            $sp_price_raw = $_POST['swi_sp_price'] ?? [];
            $sp_prices    = [];
            $sp_cc_list   = ['EE', 'FI', 'LV', 'LT', 'SE'];
            $sp_sizes     = ['xs', 's', 'm', 'l', 'xl', 'free'];
            foreach ( $sp_cc_list as $cc ) {
                foreach ( $sp_sizes as $sz ) {
                    $val = $sp_price_raw[$cc][$sz] ?? '';
                    $sp_prices[$cc][$sz] = sanitize_text_field( $val );
                }
            }
            update_option( 'swi_smartpost_prices', wp_json_encode( $sp_prices ) );

            // Select fields
            foreach ( [
                'smart_wp_integtaion_invoice_status',
                'smart_wp_integtaion_maksumaar',
                'smart_wp_integtaion_arve_ridade_tyyp',
                'smart_wp_integtaion_deparment',
                'swi_simplebooks_order_status',
                'swi_smartaccounts_order_status',
                'swi_erply_order_status',
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
                'swi_erply_enable',
                'swi_stdb_enable',
                'swi_rik_enable',
                'swi_rik_show_vat',
                'swi_rik_reg_required',
                'swi_rik_autofill_address',
                'swi_smartpost_enable',
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
                'swi_erply_enable',
                'swi_erply_license_key',
                'swi_erply_crypto_key',
                'swi_erply_order_status',
                'swi_stdb_enable',
                'swi_stdb_license_key',
                'swi_stdb_crypto_key',
                'swi_stdb_order_status',
                'swi_rik_enable',
                'swi_rik_license_key',
                'swi_rik_crypto_key',
                'swi_rik_show_vat',
                'swi_rik_reg_required',
                'swi_rik_autofill_address',
                'swi_smartpost_license_key',
                'swi_smartpost_crypto_key',
                'swi_smartpost_title',
                'swi_smartpost_sender_name',
                'swi_smartpost_sender_phone',
                'swi_smartpost_sender_email',
                'swi_smartpost_label_size',
                'swi_smartpost_auto_send',
                'swi_smartpost_status_after_label',
                'swi_smartpost_add_tracking',
                'swi_smartpost_send_label_copy',
                'swi_smartpost_label_email',
                'swi_smartpost_mobile_classic',
                'swi_smartpost_prices',
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
