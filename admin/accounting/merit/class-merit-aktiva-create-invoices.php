<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class My_Simple_Ajax_Plugin {
    //public $api_url = 'https://aktiva.merit.ee/api/v2/sendinvoice';
    private $api_key;
    private $tax_field;
    private $payment_deadline;
    private $regNo;
    private $arve_eesliides;
    private $order_Status;
    private $arve_ridade_tyyp;
    private $department_code;
    private $AccountingDoc;

    public function __construct() {
        // Laeme skripti ainult frontendis
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        //add_action( 'wp_loaded', [ $this, 'create_invoice' ] );
        // AJAX actionid (logitud & külalised)
        add_action( 'wp_ajax_my_custom_action', [ $this, 'handle_ajax' ] );
        add_action( 'wp_ajax_nopriv_my_custom_action', [ $this, 'handle_ajax' ] );

        add_action( 'wp_ajax_send_invoice_action', [ $this, 'send_invoice_to_customer' ] );
        add_action( 'wp_ajax_nopriv_send_invoice_action', [ $this, 'send_invoice_to_customer' ] );

        
        $this->tax_field = get_option('smart_wp_integtaion_maksumaar');
        $this->payment_deadline = get_option('smart_wp_integtaion_maksetahtaeg');
        $this->regNo  = get_option('regno');
        $this->arve_eesliides = get_option('smart_wp_integtaion_arve_eesliides');
        $this->order_Status = get_option('smart_wp_integtaion_invoice_status');
        $this->arve_ridade_tyyp = get_option('smart_wp_integtaion_arve_ridade_tyyp'); 
        $this->department_code = get_option('smart_wp_integtaion_deparment');
        $this->AccountingDoc = get_option('smart_wp_integtaion_AccountingDoc');
        
    }

    public function enqueue_scripts() {
        wp_enqueue_script(
            'my-simple-ajax',
            plugin_dir_url( __FILE__ ) . '../../js/smart-wp-integrations-admin.js',
            [ 'jquery' ],
            '1.0',
            true
        );

        wp_localize_script( 'my-simple-ajax', 'MyAjax', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'my_nonce' )
        ] );
    }

    public function Smart_WP_Filter_Woocommerce_Merit_aktiva_Invoices() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return [ 'error' => 'WooCommerce ei ole aktiivne!' ];
        }

        global $wpdb;

        // Näidis: kõik Woo tellimused
        $orders = wc_get_orders( [ 'limit' => -1 ] );
        $merit_server_invoices = new MeritServersDataClient();
        $results = $merit_server_invoices->get_all_invoices();//$wpdb->get_results( "SELECT * FROM {$wpdb->prefix}merit_invoices" );

        $filtered = [];


        foreach ( $orders as $order ) {
            $order_exists_in_invoice = false;
            
            if('wc-' . $order->get_status() === $this->order_Status)
            {
                if ( is_array( $results ) ) {
                    
                    foreach ( $results as $invoice ) {
                        
                        if ( $this->arve_eesliides . $order->get_id() == $invoice->InvoiceNo ) {
                            
                            $order_exists_in_invoice = true;
                            break;
                        }
                        
                    }
                }

                if ( ! $order_exists_in_invoice ) {
                    $filtered[] = $order->get_id();
                }
            }
                
        }

        return $filtered;
        
    }


   public function create_invoice() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return [ 'error' => 'WooCommerce ei ole aktiivne!' ];
        }

        // Seaded UI-st
        $country_settings = get_option('smartwp_merit_country_settings', []); // riigi VAT seaded
        $payment_map      = get_option('smart_wp_integtaion_payment_map', []);      // WC makseviis -> Merit konto

        // Eeldame, et see funktsioon tagastab order ID-d
        $order_ids = $this->Smart_WP_Filter_Woocommerce_Merit_aktiva_Invoices();

        if ( empty( $order_ids ) || ! is_array( $order_ids ) ) {
            return [ 'error' => 'Ühtegi orderit ei leitud.' ];
        }

        $invoiceArray = [];

        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                continue;
            }

            // ——— Riigi VAT seaded (võtame billing → shipping → vaikimisi) ———
            $country = $order->get_billing_country();
            if ( empty($country) ) {
                $country = $order->get_shipping_country();
            }
            // kui ikka tühi, kasuta esimest default=true kirjet
            if ( empty($country) ) {
                foreach ($country_settings as $cc => $cfg) {
                    if ( !empty($cfg['default']) ) { $country = $cc; break; }
                }
            }

            $vat_code = $this->tax_field; // fallback sinu olemasolevale väljale
            $vat_name = null;
            $is_default_country = false;
            if ( !empty($country) && !empty($country_settings[$country]) ) {
                $vat_code          = !empty($country_settings[$country]['vat_code']) ? $country_settings[$country]['vat_code'] : $vat_code;
                $vat_name          = !empty($country_settings[$country]['vat_name']) ? $country_settings[$country]['vat_name'] : null;
                $is_default_country= !empty($country_settings[$country]['default']);
            }

            // ——— Maksemeetodi kaardistus → Merit konto ———
            $wc_method     = $order->get_payment_method();          // nt bacs, cod, cheque, montonio_bank, card_payment, blik, pay_later, financing
            $merit_account = isset($payment_map[$wc_method]) ? $payment_map[$wc_method] : null;

            // Determine if the customer is a company
            $is_company = ! empty( $order->get_billing_company() );

            $customer = [
                "Name"            => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                "RegNo"           => $is_company ? $this->regNo : null,
                "NotTDCustomer"   => false,
                "VatRegNo"        => "00000000",
                "CurrencyCode"    => $order->get_currency(),
                "PaymentDeadLine" => $this->payment_deadline,
                "OverDueCharge"   => 0,
                "RefNoBase"       => 1,
                "Address"         => $order->get_billing_address_1(),
                "CountryCode"     => $order->get_billing_country(),
                "County"          => $order->get_shipping_state(),
                "City"            => $order->get_billing_city(),
                "PostalCode"      => $order->get_shipping_postcode(),
                "PhoneNo"         => $order->get_billing_phone(),
                "Email"           => $order->get_billing_email()
            ];

            // readymade read + kogusummad
            $rows = $this->create_invoice_items_array( $order, $vat_code );

            // summad
            $subtotal_ex = (float)$order->get_total() - (float)$order->get_total_tax() - (float)$order->get_shipping_total();
            $tax_total   = (float)$order->get_total_tax() + (float)$order->get_shipping_total();
            $grand_total = (float)$order->get_total();

            $json_payload = [
                "Customer"        => $customer,
                "AccountingDoc"   => $this->AccountingDoc,
                "DocDate"         => $order->get_date_created() ? $order->get_date_created()->date( "YmdHis" ) : gmdate("YmdHis"),
                "DueDate"         => date( "YmdHis", strtotime( "+14 days" ) ),
                "InvoiceNo"       => $this->arve_eesliides . $order->get_id(),
                "DepartmentCode"  => $this->department_code,
                "RefNo"           => "0000",

                "Country" => [
                    "Code"     => $country ?: null,
                    "VatCode"  => $vat_code,
                    "VatName"  => $vat_name,
                    "Default"  => (bool)$is_default_country
                ],

                "Payment" => [
                    "WcMethod"     => $wc_method,
                    "MeritAccount" => $merit_account,
                    "Amount"       => $grand_total,
                    "Currency"     => $order->get_currency(),
                    // "AutoReceipt" => true, // kui soovid kohe laekumist saata – jäta või lisa
                    "TransactionId"=> $order->get_transaction_id()
                ],

                "InvoiceRow"   => $rows,

                "Totals" => [
                    "SubtotalEx" => round($subtotal_ex, 2),
                    "TaxTotal"   => round($tax_total, 2),
                    "GrandTotal" => round($grand_total, 2)
                ],

                "TaxAmount" => [
                    [
                        "TaxId"  => $vat_code,
                        "Amount" => round($tax_total, 2)
                    ]
                ]
            ];

            $invoiceArray[] = $json_payload;
        }

        return $invoiceArray;
    }


    

    public function create_invoice_items_array( $order, $vat_code_override = null ) {
        $payload_arrays = [];

        foreach ( $order->get_items() as $item_id => $item ) {
            $product  = $item->get_product();
            $sku      = $product ? $product->get_sku() : '';
            $price_ex = $product ? (float)$product->get_price() : (float)$item->get_total() / max(1,(int)$item->get_quantity());

            $payload_arrays[] = [
                "Item" => [
                    "Code"        => $sku,
                    "Description" => $item->get_name(),
                    "Type"        => $this->arve_ridade_tyyp,
                    "UOMName"     => "tk"
                ],
                "Quantity"       => (float)$item->get_quantity(),
                "Price"          => $price_ex,
                "DiscountPct"    => 0,
                "DiscountAmount" => 0,
                "TaxId"          => $vat_code_override ?: $this->tax_field,
                "LocationCode"   => "1"
            ];
        }

        // Shipping read
        foreach ( $order->get_shipping_methods() as $shipping_item_id => $shipping_item ) {
            if ( empty( $shipping_item ) ) { continue; }

            $method_title   = $shipping_item->get_name();      // nt 'Pickup'
            $shipping_total = (float)$shipping_item->get_total();

            if ( $shipping_total > 0 ) {
                $payload_arrays[] = [
                    "Item" => [
                        "Code"        => "Merit Arve",
                        "Description" => $method_title,
                        "Type"        => 1,
                        "UOMName"     => "tk"
                    ],
                    "Quantity"       => 1,
                    "Price"          => $shipping_total,
                    "DiscountPct"    => 0,
                    "DiscountAmount" => 0,
                    "TaxId"          => $vat_code_override ?: $this->tax_field,
                    "LocationCode"   => "1"
                ];
            }
        }

        return $payload_arrays;
    }



    public function handle_ajax() {
        //check_ajax_referer( 'my_nonce', 'security' );

        if ( ! class_exists( 'WooCommerce' ) ) {
            wp_send_json_error( [ 'error' => 'WooCommerce ei ole aktiivne!' ] );
        }

        $payloads = $this->create_invoice();

        if ( empty( $payloads ) || isset( $payloads['error'] ) ) {
            wp_send_json_error( [ 'error' => 'Ühtegi orderit ei leitud.' ] );
        }

        $results = [];
        $errors  = [];

        foreach ( $payloads as $payload ) {
            $sendEncryptedOrders = new LocalApiClient();
            $res = $sendEncryptedOrders->sendEncryptedOrder( $payload );

            if ( isset( $res['error'] ) ) {
                $errors[] = $res;
            } else {
                $results[] = $res;
            }
        }

        if ( ! empty( $errors ) ) {
            wp_send_json_error( [
                'message' => 'Mõned orderid ei õnnestunud saata',
                'errors'  => $errors,
                'success' => $results,
            ] );
        }

        wp_send_json_success( $results );
    }

    public function send_invoice_to_customer() {
        check_ajax_referer( 'my_nonce', 'security' );

        if ( ! class_exists( 'WooCommerce' ) ) {
            wp_send_json_error( [ 'error' => 'WooCommerce ei ole aktiivne!' ] );
        }

        $merit_server_invoices = new MeritServersDataClient();
        $results = $merit_server_invoices->get_all_invoices();

      
        
        if ( is_array( $results ) ) {
            
            foreach ( $results as $invoice ) {
                
                if ($invoice)
                {
                    $merit_server_invoices->merit_send_invoice_by_email($invoice->SIHId);
                }
            }
        }

            
        

        wp_send_json_success( 'Emailid on saadetud' );
    }

}

$test = new My_Simple_Ajax_Plugin();
//print_r($test->Smart_WP_Filter_Woocommerce_Merit_aktiva_Invoices());