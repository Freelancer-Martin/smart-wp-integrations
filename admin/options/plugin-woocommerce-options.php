<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_filter( 'woocommerce_get_settings_pages', function( $settings ) {
    if ( ! class_exists( 'WC_Settings_Page' ) ) return $settings;

    class WC_Settings_Smart_WP_Integration extends WC_Settings_Page {

        private $opt_payment_map  = 'smart_wp_integtaion_payment_map';
        private $opt_shipping_map = 'smart_wp_integtaion_shipping_map';
        private $opt_tax_map      = 'smart_wp_integtaion_tax_map';
        private $opt_country_map  = 'smart_wp_integtaion_country_map';

        public function __construct() {
            $this->id    = 'smart_wp_integration';
            $this->label = __( 'Smart WP Integration', 'smart-wp-integration' );

            parent::__construct();

            // Custom field tüübid
            add_action( 'woocommerce_admin_field_merit_country_map',  [ $this, 'field_country_map' ] );
            add_action( 'woocommerce_admin_field_merit_payment_map',  [ $this, 'field_payment_map' ] );
            add_action( 'woocommerce_admin_field_merit_tax_map',      [ $this, 'field_tax_map' ] );
            add_action( 'woocommerce_admin_field_merit_shipping_map', [ $this, 'field_shipping_map' ] );
            add_action( 'woocommerce_update_options', [ $this, 'save' ] );
        }

        /* -----------------------------
         *  SETTINGS
         * --------------------------- */
        public function get_settings( $section = '' ) {
            $order_statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];

            $client = new MeritServersDataClient();

            $settings = [

                /* ÜLDSEADED (pildi ülemine blokk) */
                [
                    'name' => __( 'Merit Aktiva – Üldseaded', 'smart-wp-integration' ),
                    'type' => 'title',
                    'desc' => __( 'Seadista Merit Aktiva liidestus.', 'smart-wp-integration' ),
                    'id'   => 'smart_wp_integtaion_settings',
                ],
                [
                    'name'    => __( 'Luba Merit Aktiva liidestus', 'smart-wp-integration' ),
                    'type'    => 'checkbox',
                    'id'      => 'smart_wp_integtaion_enable',
                    'default' => 'no',
                ],
                [
                    'name'    => __( 'WP-liidese litsentsi võti', 'smart-wp-integration' ),
                    'type'    => 'text',
                    'id'      => 'smart_wp_integtaion_license_text',
                    'default' => '',
                    'desc'    => __( 'Sisesta WP-liidese litsentsi võti (UUID).', 'smart-wp-integration' ),
                ],
                
                [
                    'name'    => __( 'WP-liidese krüptovõti (HEX, 32 baiti)', 'smart-wp-integration' ),
                    'type'    => 'text',
                    'id'      => 'smart_wp_integtaion_crypto_text',
                    'default' => '',
                    'desc'    => __( 'AES-256-GCM jaoks 64-hex pikkusega võti (32 baiti).', 'smart-wp-integration' ),
                ],
                [
                    'name'    => __( 'Arve eesliides', 'smart-wp-integration' ),
                    'type'    => 'text',
                    'id'      => 'smart_wp_integtaion_arve_eesliides',
                    'default' => 'WP',
                    'desc'    => __( 'Arve eesliides.', 'smart-wp-integration' ),
                ],
                [
                    'name'    => __( 'Maksetähtaeg päevades', 'smart-wp-integration' ),
                    'type'    => 'text',
                    'id'      => 'smart_wp_integtaion_maksetahtaeg',
                    'default' => '14',
                    'desc'    => __( 'Maksetähtaeg päevades.', 'smart-wp-integration' ),
                ],
/*                
                [
                    'name'     => __( 'Staatus: etikett trükitud', 'smart-wp-integration' ),
                    'type'     => 'select',
                    'id'       => 'erply_shipping_label_status',
                    'options'  => $order_statuses,
                    'default'  => 'wc-processing',
                ],
                [
                    'name'     => __( 'Staatus: kohaletoimetatud', 'smart-wp-integration' ),
                    'type'     => 'select',
                    'id'       => 'erply_shipping_delivered_status',
                    'options'  => $order_statuses,
                    'default'  => 'wc-completed',
                ],
*/                
                [
                    'name'     => __( 'Millise staatusega tellimusi saata', 'smart-wp-integration' ),
                    'type'     => 'select',
                    'id'       => 'smart_wp_integtaion_invoice_status',
                    'options'  => $order_statuses,
                    'default'  => 'completed',
                ],
                [
                    'name'     => __( 'Vali Maksutyyp', 'smart-wp-integration' ),
                    'type'     => 'select',
                    'id'       => 'smart_wp_integtaion_maksumaar',
                    'options'  => [
                        '973a4395-665f-47a6-a5b6-5384dd24f8d0'        => __( '0% käibemaks', 'smart-wp-integration' ),
                        '6b618baa-680b-4606-9ad9-eff0beb27344'       => __( '9% käibemaks', 'smart-wp-integration' ),
                        'fd050f9b-f376-40fb-aee5-af2d1f04970f'     => __( '13% käibemak', 'smart-wp-integration' ),
                        'b9b25735-6a15-4d4e-8720-25b254ae3d21' => __( '20% käibemaks', 'smart-wp-integration' ),
                        '307000b4-f1f2-4bc7-a110-24cb18d77212'  => __( '22% käibemaks', 'smart-wp-integration' ),
                        '1e420e04-3dd7-46a5-b71f-0490779c2638'  => __( '24% käibemaks', 'smart-wp-integration' ),
                    ],
                    'default'  => '1e420e04-3dd7-46a5-b71f-0490779c2638',
                ],
                [
                    'name'     => __( 'Arve ridade tüüp', 'smart-wp-integration' ),
                    'type'     => 'select',
                    'id'       => 'smart_wp_integtaion_arve_ridade_tyyp',
                    'options'  => [
                        1        => __( 'LaoKaup', 'smart-wp-integration' ),
                        2       => __( 'Teenus', 'smart-wp-integration' ),
                        3     => __( 'Kaup', 'smart-wp-integration' ),
                        
                    ],
                    'default'  => 1,
                ],
                [
                    'name'     => __( 'Vaikimisi osakond', 'smart-wp-integration' ),
                    'type'     => 'select',
                    'id'       => 'smart_wp_integtaion_deparment',
                    'options'  => [
                       implode(" ",$client->getDepartments()) => implode(" ",$client->getDepartments())
                        
                        
                    ],
                    'default'  => '',
                ],
                [
                    'type' => 'sectionend',
                    'id'   => 'smart_wp_integtaion_settings',
                ],

                /* RIIGIPÕHISED (pildil: “Riik – VAT kood – Nimetus – Vaikimisi?”) */
                [
                    'name' => __( 'Riigi eriseaded (VAT jms)', 'smart-wp-integration' ),
                    'type' => 'title',
                    'desc' => __( 'Määra vajadusel eri riikide VAT koodid/vaikeseaded.', 'smart-wp-integration' ),
                    'id'   => 'smart_wp_integtaion_country_settings',
                ],
                [
                    'type' => 'merit_country_map',
                    'id'   => $this->opt_country_map,
                ],
                [
                    'type' => 'sectionend',
                    'id'   => 'smart_wp_integtaion_country_settings',
                ],

                /* MAKSEMEETODITE KAARDISTUS (pildi keskosa vasakul) */
                [
                    'name' => __( 'Maksemeetodite kaardistus → Merit konto', 'smart-wp-integration' ),
                    'type' => 'title',
                    'desc' => __( 'Seo Woo maksemeetod Merit Aktiva kontoga.', 'smart-wp-integration' ),
                    'id'   => 'smart_wp_integtaion_payment_settings',
                ],
                [
                    'type' => 'merit_payment_map',
                    'id'   => $this->opt_payment_map,
                ],
                [
                    'type' => 'sectionend',
                    'id'   => 'smart_wp_integtaion_payment_settings',
                ],

                /* MAKSUD (pildi keskosa – “Maksu ID / Maksu % / Nimetus / Vaikimisi?” + EU/vaikimisi toote % ) */
                [
                    'name' => __( 'Maksud / VAT kaardistus', 'smart-wp-integration' ),
                    'type' => 'title',
                    'desc' => __( 'Lihtne VAT-koodide kaardistus Meritisse.', 'smart-wp-integration' ),
                    'id'   => 'smart_wp_integtaion_tax_settings',
                ],
                [
                    'type' => 'merit_tax_map',
                    'id'   => $this->opt_tax_map,
                ],
                [
                    'type' => 'sectionend',
                    'id'   => 'smart_wp_integtaion_tax_settings',
                ],

                /* TARNE (pildi alumine pikk tabel – Montonio/Itella/Omniva/DPD/Venipak jne) */
                [
                    'name' => __( 'Tarnemeetodite kaardistus → Artikli kood', 'smart-wp-integration' ),
                    'type' => 'title',
                    'desc' => __( 'Seo tarne-teenused Merit Aktiva artikli koodidega.', 'smart-wp-integration' ),
                    'id'   => 'smart_wp_integtaion_shipping_settings',
                ],
                [
                    'type' => 'merit_shipping_map',
                    'id'   => $this->opt_shipping_map,
                ],
                [
                    'type' => 'sectionend',
                    'id'   => 'smart_wp_integtaion_shipping_settings',
                ],
            ];

            return apply_filters( 'woocommerce_smart_wp_integration_settings', $settings );
        }

        /* -----------------------------
         *  OUTPUT (ära loo oma <form>-i)
         * --------------------------- */
        public function output() {
            ?>
            
            <style>
                .nav-pills .nav-link.active {background:#2271b1!important;}
                .tab-content {border:1px solid #eee; padding:20px 16px; background:#fff; border-radius:0 0 8px 8px;}
                .nav-pills {margin-bottom:-1px; border-radius:8px 8px 0 0; background:#f6f7f7; padding:8px 12px 0 12px;}
                .nav-link {font-size:1.07em; margin-right:4px; border-radius:6px 6px 0 0;}
                .form-table th {width:220px;}
            </style>
            <div class="wrap">
                <h2 style="margin-top:10px;">Smart WP Integration</h2>
                <ul class="nav nav-pills mb-3" id="pills-tab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="pills-merit-aktiva-tab" data-bs-toggle="pill" data-bs-target="#pills-merit-aktiva" type="button" role="tab" aria-controls="pills-merit-aktiva" aria-selected="true">Merit aktiva liidestus</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="pills-simplebooks-tab" data-bs-toggle="pill" data-bs-target="#pills-simplebooks" type="button" role="tab" aria-controls="pills-simplebooks" aria-selected="false">Simpelbooks liidestus</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="pills-erply-tab" data-bs-toggle="pill" data-bs-target="#pills-erply" type="button" role="tab" aria-controls="pills-erply" aria-selected="false">Erply liidestus</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="pills-standard-books-tab" data-bs-toggle="pill" data-bs-target="#pills-standard-books" type="button" role="tab" aria-controls="pills-standard-books" aria-selected="false">Standard books liidestus</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="pills-smart-account-tab" data-bs-toggle="pill" data-bs-target="#pills-smart-account" type="button" role="tab" aria-controls="pills-smart-account" aria-selected="false">Smart account liidestus</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="pills-ariregister-tab" data-bs-toggle="pill" data-bs-target="#pills-ariregister" type="button" role="tab" aria-controls="pills-ariregister" aria-selected="false">"Ariregistri moodul"</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="pills-messages-tab" data-bs-toggle="pill" data-bs-target="#pills-messages" type="button" role="tab" aria-controls="pills-messages" aria-selected="false">Card Payments</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="pills-settings-tab" data-bs-toggle="pill" data-bs-target="#pills-settings" type="button" role="tab" aria-controls="pills-settings" aria-selected="false">Shipping</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="pills-home-tab" data-bs-toggle="pill" data-bs-target="#pills-home" type="button" role="tab" aria-controls="pills-home" aria-selected="true">Merit aktiva liidestus</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="pills-profile-tab" data-bs-toggle="pill" data-bs-target="#pills-profile" type="button" role="tab" aria-controls="pills-profile" aria-selected="false">Bank Payments</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="pills-messages-tab" data-bs-toggle="pill" data-bs-target="#pills-messages" type="button" role="tab" aria-controls="pills-messages" aria-selected="false">Card Payments</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="pills-settings-tab" data-bs-toggle="pill" data-bs-target="#pills-settings" type="button" role="tab" aria-controls="pills-settings" aria-selected="false">Shipping</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="pills-home-tab" data-bs-toggle="pill" data-bs-target="#pills-home" type="button" role="tab" aria-controls="pills-home" aria-selected="true">Merit aktiva liidestus</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="pills-profile-tab" data-bs-toggle="pill" data-bs-target="#pills-profile" type="button" role="tab" aria-controls="pills-profile" aria-selected="false">Bank Payments</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="pills-messages-tab" data-bs-toggle="pill" data-bs-target="#pills-messages" type="button" role="tab" aria-controls="pills-messages" aria-selected="false">Card Payments</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="pills-settings-tab" data-bs-toggle="pill" data-bs-target="#pills-settings" type="button" role="tab" aria-controls="pills-settings" aria-selected="false">Shipping</button>
                    </li>
                    
                </ul>
                <div class="tab-content" id="pills-tabContent">
                    <div class="tab-pane fade show active" id="pills-merit-aktiva" role="tabpanel" aria-labelledby="pills-merit-aktiva">  
                        
                          <?php
                            woocommerce_admin_fields( $this->get_settings() );
                           
                          ?>
                        
                    </div>
                    <div class="tab-pane fade" id="pills-simplebooks" role="tabpanel" aria-labelledby="pills-simplebooks-tab">
                        <h3>Bank Payments</h3>
                        <p>Siia pane panga maksete seaded või info.</p>
                    </div>
                    <div class="tab-pane fade" id="pills-erply" role="tabpanel" aria-labelledby="pills-erply-tab">
                        <h3>Card Payments</h3>
                        <p>Siia pane kaardimaksete info/seaded.</p>
                    </div>
                    <div class="tab-pane fade" id="pills-standard-books" role="tabpanel" aria-labelledby="pills-standard-books-tab">
                        <h3>Shipping</h3>
                        
                    </div>
                    <div class="tab-pane fade" id="pills-smart-account" role="tabpanel" aria-labelledby="pills-smart-account-tab">
                        <h3>Bank Payments</h3>
                        <p>Siia pane panga maksete seaded või info.</p>
                    </div>
                    <div class="tab-pane fade" id="pills-ariregister" role="tabpanel" aria-labelledby="pills-ariregister-tab">
                        <h3>Card Payments</h3>
                        <p>Siia pane kaardimaksete info/seaded.</p>
                    </div>
                    <div class="tab-pane fade" id="pills-settings" role="tabpanel" aria-labelledby="pills-settings-tab">
                        <h3>Shipping</h3>
                        
                    </div>
                </div>
            </div>
            <?php
        }

        /* -----------------------------
         *  SAVE (salvesta ka custom tabelid)
         * --------------------------- */
        public function save() {
            // Standardsed väljad
            woocommerce_update_options( $this->get_settings() );

            // Custom tabelid
            $maps = [
                $this->opt_payment_map  => isset($_POST[$this->opt_payment_map])  ? (array) $_POST[$this->opt_payment_map]  : [],
                $this->opt_shipping_map => isset($_POST[$this->opt_shipping_map]) ? (array) $_POST[$this->opt_shipping_map] : [],
                $this->opt_tax_map      => isset($_POST[$this->opt_tax_map])      ? (array) $_POST[$this->opt_tax_map]      : [],
                $this->opt_country_map  => isset($_POST[$this->opt_country_map])  ? (array) $_POST[$this->opt_country_map]  : [],
            ];

            foreach ( $maps as $key => $val ) {
                $sanitized = $this->deep_sanitize_array( $val );
                // Eemalda tühjad read
                $sanitized = array_filter( $sanitized, function( $row ) {
                    if ( is_array($row) ) {
                        foreach ( $row as $v ) { if ( (string)$v !== '' ) return true; }
                        return false;
                    }
                    return (string)$row !== '';
                });
                update_option( $key, $sanitized, false );
            }
        }

        private function deep_sanitize_array( $arr ) {
            $out = [];
            foreach ( (array) $arr as $k => $v ) {
                $key = is_scalar($k) ? sanitize_key( wp_unslash($k) ) : '';
                if ( is_array( $v ) ) {
                    $out[$key] = $this->deep_sanitize_array( $v );
                } else {
                    $out[$key] = is_scalar($v) ? sanitize_text_field( wp_unslash($v) ) : '';
                }
            }
            return $out;
        }

        /* -----------------------------
         *  CUSTOM FIELD RENDERERS
         * --------------------------- */

        // RIIGI ERISEADED
        public function field_country_map( $value ) {
            $option_key = $value['id'];
            $stored     = (array) get_option( $option_key, [] );
            $countries  = new WC_Countries();

            echo '<table class="widefat striped" style="margin:8px 0;">
                    <thead>
                        <tr>
                            <th>'.esc_html__('Riik','smart-wp-integration').'</th>
                            <th>'.esc_html__('VAT kood','smart-wp-integration').'</th>
                            <th>'.esc_html__('Nimetus','smart-wp-integration').'</th>
                            <th>'.esc_html__('Vaikimisi?','smart-wp-integration').'</th>
                        </tr>
                    </thead>
                    <tbody>';

            if ( empty( $stored ) ) {
                $stored = [ uniqid('row_') => [ 'country' => '', 'vat_code' => '', 'name' => '', 'default' => '' ] ];
            }

            foreach ( $stored as $row_key => $row ) {
                echo '<tr>';
                echo '<td><select name="'.esc_attr($option_key).'['.esc_attr($row_key).'][country]" style="min-width:220px">';
                echo '<option value="">'.esc_html__('— vali riik —','smart-wp-integration').'</option>';
                foreach ( $countries->get_countries() as $code => $label ) {
                    printf( '<option value="%s" %s>%s</option>',
                        esc_attr($code),
                        selected( ($row['country'] ?? '') === $code, true, false ),
                        esc_html($label)
                    );
                }
                echo '</select></td>';
                printf( '<td><input type="text" name="%1$s[%2$s][vat_code]" value="%3$s" style="min-width:200px"></td>',
                    esc_attr($option_key), esc_attr($row_key), esc_attr($row['vat_code'] ?? '')
                );
                printf( '<td><input type="text" name="%1$s[%2$s][name]" value="%3$s" style="min-width:200px"></td>',
                    esc_attr($option_key), esc_attr($row_key), esc_attr($row['name'] ?? '')
                );
                echo '<td><select name="'.esc_attr($option_key).'['.esc_attr($row_key).'][default]">
                        <option value="">'.esc_html__('Ei','smart-wp-integration').'</option>
                        <option value="yes" '.selected( ($row['default'] ?? '') === 'yes', true, false ).'>'.esc_html__('Jah','smart-wp-integration').'</option>
                      </select></td>';
                echo '</tr>';
            }

            // tühi lisa-rida
            $new = uniqid('new_');
            echo '<tr>';
            echo '<td><select name="'.esc_attr($option_key).'['.esc_attr($new).'][country]" style="min-width:220px">';
            echo '<option value="">'.esc_html__('— lisa uus —','smart-wp-integration').'</option>';
            foreach ( $countries->get_countries() as $code => $label ) {
                printf( '<option value="%s">%s</option>', esc_attr($code), esc_html($label) );
            }
            echo '</select></td>';
            printf( '<td><input type="text" name="%1$s[%2$s][vat_code]" value="" style="min-width:200px"></td>', esc_attr($option_key), esc_attr($new) );
            printf( '<td><input type="text" name="%1$s[%2$s][name]" value="" style="min-width:200px"></td>', esc_attr($option_key), esc_attr($new) );
            echo '<td><select name="'.esc_attr($option_key).'['.esc_attr($new).'][default]">
                    <option value="">'.esc_html__('Ei','smart-wp-integration').'</option>
                    <option value="yes">'.esc_html__('Jah','smart-wp-integration').'</option>
                  </select></td>';
            echo '</tr>';

            echo '</tbody></table>';
            echo '<p class="description">'.esc_html__('Täida read ja vajuta “Save changes”. Tühje ridu ei salvestata.','smart-wp-integration').'</p>';
        }

        // MAKSEMEETODID
        public function field_payment_map( $value ) {
            $option_key = $value['id'];
            $stored     = (array) get_option( $option_key, [] );

            $methods = [];
            if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
                $gateways = WC()->payment_gateways()->get_available_payment_gateways();
                foreach ( (array) $gateways as $id => $g ) {
                    $methods[$id] = $g->get_title();
                }
            }
            // pildil olnud lisad
            $extra = [
                'bacs'            => 'Direct bank transfer',
                'cheque'          => 'Check payments',
                'cod'             => 'Cash on delivery',
                'montonio_bank'   => 'Pay with your bank',
                'card_payment'    => 'Card Payment',
                'blik'            => 'BLIK',
                'pay_later'       => 'Pay Later',
                'financing'       => 'Financing',
            ];
            foreach ( $extra as $k => $v ) {
                if ( ! isset( $methods[$k] ) ) $methods[$k] = $v;
            }

            echo '<table class="widefat striped" style="margin:8px 0;">
                    <thead><tr>
                        <th>'.esc_html__('Maksemeetod','smart-wp-integration').'</th>
                        <th>'.esc_html__('Merit konto/kood','smart-wp-integration').'</th>
                    </tr></thead><tbody>';

            foreach ( $methods as $id => $title ) {
                $val = $stored[$id] ?? '';
                echo '<tr>';
                echo '<td>'.esc_html($title).' <code style="opacity:.7">('.esc_html($id).')</code></td>';
                printf( '<td><input type="text" name="%1$s[%2$s]" value="%3$s" style="min-width:240px" placeholder="nt 1000"></td>',
                    esc_attr($option_key), esc_attr($id), esc_attr($val)
                );
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '<p class="description">'.esc_html__('Sisesta Merit Aktiva pearaamatu konto või muu kood.','smart-wp-integration').'</p>';
        }

        // MAKSUD
        public function field_tax_map( $value ) {
            $option_key = $value['id'];
            $stored     = (array) get_option( $option_key, [
                'std20'   => [ 'id' => '20', 'rate' => '20', 'name' => '20%', 'is_default' => '' ],
                'zero'    => [ 'id' => '0',  'rate' => '0',  'name' => '0%',  'is_default' => 'yes' ],
                '_eu_vat' => '',
                '_product_default_vat' => '',
            ] );

            echo '<table class="widefat striped" style="margin:8px 0;">
                    <thead><tr>
                        <th>'.esc_html__('Maksu ID','smart-wp-integration').'</th>
                        <th>'.esc_html__('%','smart-wp-integration').'</th>
                        <th>'.esc_html__('Nimetus','smart-wp-integration').'</th>
                        <th>'.esc_html__('Vaikimisi?','smart-wp-integration').'</th>
                    </tr></thead><tbody>';

            // read (v.a. meta võtmed, mis algavad alakriipsuga)
            foreach ( $stored as $k => $row ) {
                if ( str_starts_with( (string)$k, '_' ) ) continue;
                $rk = esc_attr($k);
                echo '<tr>';
                printf( '<td><input type="text" name="%1$s[%2$s][id]" value="%3$s" style="min-width:120px"></td>', esc_attr($option_key), $rk, esc_attr($row['id'] ?? '') );
                printf( '<td><input type="text" name="%1$s[%2$s][rate]" value="%3$s" style="min-width:120px"></td>', esc_attr($option_key), $rk, esc_attr($row['rate'] ?? '') );
                printf( '<td><input type="text" name="%1$s[%2$s][name]" value="%3$s" style="min-width:240px"></td>', esc_attr($option_key), $rk, esc_attr($row['name'] ?? '') );
                echo '<td><select name="'.esc_attr($option_key).'['.$rk.'][is_default]">
                        <option value="">'.esc_html__('Ei','smart-wp-integration').'</option>
                        <option value="yes" '.selected( ($row['is_default'] ?? '') === 'yes', true, false ).'>'.esc_html__('Jah','smart-wp-integration').'</option>
                      </select></td>';
                echo '</tr>';
            }

            // tühi lisa-rida
            $new = uniqid('new_');
            echo '<tr>';
            printf( '<td><input type="text" name="%1$s[%2$s][id]" value="" style="min-width:120px"></td>', esc_attr($option_key), esc_attr($new) );
            printf( '<td><input type="text" name="%1$s[%2$s][rate]" value="" style="min-width:120px"></td>', esc_attr($option_key), esc_attr($new) );
            printf( '<td><input type="text" name="%1$s[%2$s][name]" value="" style="min-width:240px"></td>', esc_attr($option_key), esc_attr($new) );
            echo '<td><select name="'.esc_attr($option_key).'['.esc_attr($new).'][is_default]">
                    <option value="">'.esc_html__('Ei','smart-wp-integration').'</option>
                    <option value="yes">'.esc_html__('Jah','smart-wp-integration').'</option>
                  </select></td>';
            echo '</tr>';
            echo '</tbody></table>';

            // EU VAT ja vaikimisi toote % lisaväljad
            $eu_vat  = $stored['_eu_vat'] ?? '';
            $p_def   = $stored['_product_default_vat'] ?? '';
            echo '<table class="form-table"><tbody><tr>';
            echo '<th scope="row">'.esc_html__('EU VAT koodiga tellimus → maksukood','smart-wp-integration').'</th>';
            echo '<td><input type="text" name="'.esc_attr($option_key).'[_eu_vat]" value="'.esc_attr($eu_vat).'" style="min-width:240px"></td>';
            echo '</tr><tr>';
            echo '<th scope="row">'.esc_html__('Toodete vaikimisi maksuprotsent (%)','smart-wp-integration').'</th>';
            echo '<td><input type="text" name="'.esc_attr($option_key).'[_product_default_vat]" value="'.esc_attr($p_def).'" style="min-width:240px"></td>';
            echo '</tr></tbody></table>';

            echo '<p class="description">'.esc_html__('Täida vajalikud read. Tühjad read salvestamisel ignoreeritakse.','smart-wp-integration').'</p>';
        }

        // TARNE
        public function field_shipping_map( $value ) {
            $option_key = $value['id'];
            $stored     = (array) get_option( $option_key, [] );

            // Pildi põhjal: Montonio + Smartpost/Omniva/DPD/Venipak jms
            $services = [
                // Montonio – EE/LV/LT
                'montonio_smartpost_parcel_machines'     => 'Smartpost parcel machines',
                'montonio_smartpost_parcel_shops'        => 'Smartpost parcel shops',
                'montonio_omniva_parcel_machines'        => 'Omniva parcel machines',
                'montonio_omniva_post_offices'           => 'Omniva post offices',
                'montonio_omniva_courier'                => 'Omniva courier',
                'montonio_dpd_parcel_machines'           => 'DPD parcel machines',
                'montonio_dpd_courier'                   => 'DPD courier',
                'montonio_venipak_post_offices'          => 'Venipak post offices',
                'montonio_venipak_courier'               => 'Venipak courier',
                'montonio_lpexpress_parcel_machines'     => 'LP Express parcel machines',
                'montonio_latvian_post_parcel_machines'  => 'Latvian post parcel machines',
                'montonio_latvian_post_courier'          => 'Latvian post courier',

                // Itella/Smartpost/Omniva/DPD/Venipak – alamloetelu
                'itella_smartpost'                       => 'SmartPost',
                'itella_smartpost_courier'               => 'SmartPost courier',
                'itella_smartpost_courier_ee'            => 'SmartPost courier EE',
                'itella_omniva_parcel'                   => 'Omniva parcel',
                'itella_omniva_parcel_office'            => 'Omniva post office',
                'itella_omniva_parcel_pakiautomaat'      => 'Omniva parcel machine',
                'itella_omniva'                           => 'Omniva',
                'itella_venipak'                          => 'Venipak',
                'itella_dpd_parcel'                       => 'DPD parcel',
                'pickup_location'                         => 'Pickup (kohaletoomiseta)',
                // fallbackid
                'dpd_parcel_machines'                     => 'DPD parcel machines (fallback)',
                'smartpost_parcel_machines'               => 'Smartpost parcel machines (fallback)',
                'omniva_parcel_machines'                  => 'Omniva parcel machines (fallback)',
            ];

            echo '<table class="widefat striped" style="margin:8px 0;">
                    <thead><tr>
                        <th>'.esc_html__('Teenuse ID','smart-wp-integration').'</th>
                        <th>'.esc_html__('Nimetus','smart-wp-integration').'</th>
                        <th>'.esc_html__('Merit artikli kood','smart-wp-integration').'</th>
                    </tr></thead><tbody>';

            foreach ( $services as $slug => $title ) {
                $val = $stored[$slug] ?? '';
                echo '<tr>';
                echo '<td><code>'.esc_html($slug).'</code></td>';
                echo '<td>'.esc_html($title).'</td>';
                printf( '<td><input type="text" name="%1$s[%2$s]" value="%3$s" style="min-width:240px" placeholder="nt TRANSPORT_01"></td>',
                    esc_attr($option_key), esc_attr($slug), esc_attr($val)
                );
                echo '</tr>';
            }

            echo '</tbody></table>';
            echo '<p class="description">'.esc_html__('Täida ainult teenused, mida kasutad.','smart-wp-integration').'</p>';
        }
    }

    $settings[] = new WC_Settings_Smart_WP_Integration();
    return $settings;
} );
