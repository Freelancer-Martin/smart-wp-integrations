<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class My_Simple_Ajax_Plugin {

    private $tax_field;
    private $payment_deadline;
    private $regNo;
    private $arve_eesliides;
    private $order_Status;
    private $arve_ridade_tyyp;
    private $department_code;
    private $AccountingDoc;

    public function __construct() {
        add_action( 'wp_enqueue_scripts',   [ $this, 'enqueue_scripts' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        add_action( 'wp_ajax_my_custom_action',        [ $this, 'handle_ajax' ] );
        add_action( 'wp_ajax_nopriv_my_custom_action', [ $this, 'handle_ajax' ] );
        add_action( 'wp_ajax_send_invoice_action',        [ $this, 'send_invoice_to_customer' ] );
        add_action( 'wp_ajax_nopriv_send_invoice_action', [ $this, 'send_invoice_to_customer' ] );

        // Automaatne hook — saadab orderi serverisse kui staatus muutub
        add_action( 'woocommerce_order_status_changed', [ $this, 'auto_send_order' ], 10, 3 );

        $this->tax_field        = get_option( 'smart_wp_integtaion_maksumaar' );
        $this->payment_deadline = get_option( 'smart_wp_integtaion_maksetahtaeg' );
        $this->regNo            = get_option( 'regno' );
        $this->arve_eesliides   = get_option( 'smart_wp_integtaion_arve_eesliides' );
        $this->order_Status     = get_option( 'smart_wp_integtaion_invoice_status' );
        $this->arve_ridade_tyyp = get_option( 'smart_wp_integtaion_arve_ridade_tyyp' );
        $this->department_code  = get_option( 'smart_wp_integtaion_deparment' );
        $this->AccountingDoc    = get_option( 'smart_wp_integtaion_AccountingDoc' );
    }

    public function enqueue_scripts() {
        wp_enqueue_script( 'my-simple-ajax', plugin_dir_url( __FILE__ ) . '../../js/smart-wp-integrations-admin.js', [ 'jquery' ], '1.0', true );
        wp_localize_script( 'my-simple-ajax', 'MyAjax', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'my_nonce' ),
        ] );
    }

    /**
     * Automaatne saatmine — käivitub kui WC tellimuse staatus muutub konfigureeritule.
     */
    public function auto_send_order( int $order_id, string $old_status, string $new_status ): void {
        if ( get_option( 'smart_wp_integtaion_enable' ) !== 'yes' ) {
            return;
        }

        $configured = get_option( 'smart_wp_integtaion_invoice_status', 'wc-completed' );
        if ( 'wc-' . $new_status !== $configured ) {
            return;
        }

        // Ära saada sama orderit kaks korda
        if ( get_post_meta( $order_id, '_swi_sent_merit', true ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $payload = $this->build_payload_for_order( $order );
        if ( ! $payload ) {
            return;
        }

        $res = LocalApiClient::sendEncryptedOrder( $payload, 'merit' );

        if ( isset( $res['status'] ) && in_array( $res['status'], [ 'ok', 'queued' ], true ) ) {
            update_post_meta( $order_id, '_swi_sent_merit', current_time( 'mysql' ) );
            $order->add_order_note( 'Merit Aktiva: arve edastatud (' . $res['status'] . ').' );
        } else {
            $msg = $res['message'] ?? wp_json_encode( $res );
            $order->add_order_note( 'Merit Aktiva: edastamine ebaõnnestus — ' . $msg );
            error_log( 'SWI Merit auto-send failed order ' . $order_id . ': ' . wp_json_encode( $res ) );
        }
    }

    /**
     * Tagastab order ID-d mis vastavad konfigureeritule staatusele ja pole veel Meriti saadetud.
     */
    public function Smart_WP_Filter_Woocommerce_Merit_aktiva_Invoices(): array {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return [];
        }

        $orders               = wc_get_orders( [ 'limit' => -1 ] );
        $merit_server_invoices = new MeritServersDataClient();
        $results              = $merit_server_invoices->get_all_invoices();
        $filtered             = [];

        foreach ( $orders as $order ) {
            if ( 'wc-' . $order->get_status() !== $this->order_Status ) {
                continue;
            }
            $exists = false;
            if ( is_array( $results ) ) {
                foreach ( $results as $invoice ) {
                    if ( $this->arve_eesliides . $order->get_id() === $invoice->InvoiceNo ) {
                        $exists = true;
                        break;
                    }
                }
            }
            if ( ! $exists ) {
                $filtered[] = $order->get_id();
            }
        }

        return $filtered;
    }

    /**
     * Ehita Merit Aktiva payload ühele WC orderile.
     */
    public function build_payload_for_order( \WC_Order $order ): ?array {
        $country_settings = get_option( 'smart_wp_integtaion_country_map', [] );
        $payment_map      = get_option( 'smart_wp_integtaion_payment_map', [] );

        $country = $order->get_billing_country() ?: $order->get_shipping_country();
        if ( empty( $country ) ) {
            foreach ( $country_settings as $cfg ) {
                if ( ! empty( $cfg['default'] ) ) { $country = $cfg['country'] ?? ''; break; }
            }
        }

        $vat_code = $this->tax_field;
        $vat_name = null;
        $is_default_country = false;
        if ( ! empty( $country ) && ! empty( $country_settings ) ) {
            foreach ( $country_settings as $row ) {
                if ( ( $row['country'] ?? '' ) === $country ) {
                    $vat_code           = ! empty( $row['vat_code'] ) ? $row['vat_code'] : $vat_code;
                    $vat_name           = $row['name'] ?? null;
                    $is_default_country = ! empty( $row['default'] );
                    break;
                }
            }
        }

        $wc_method     = $order->get_payment_method();
        $merit_account = $payment_map[ $wc_method ] ?? null;
        $is_company    = ! empty( $order->get_billing_company() );

        $customer = [
            'Name'            => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'RegNo'           => $is_company ? $this->regNo : null,
            'NotTDCustomer'   => false,
            'VatRegNo'        => '00000000',
            'CurrencyCode'    => $order->get_currency(),
            'PaymentDeadLine' => (int) $this->payment_deadline,
            'OverDueCharge'   => 0,
            'RefNoBase'       => 1,
            'Address'         => $order->get_billing_address_1(),
            'CountryCode'     => $order->get_billing_country(),
            'County'          => $order->get_shipping_state(),
            'City'            => $order->get_billing_city(),
            'PostalCode'      => $order->get_shipping_postcode(),
            'PhoneNo'         => $order->get_billing_phone(),
            'Email'           => $order->get_billing_email(),
        ];

        $rows      = $this->create_invoice_items_array( $order, $vat_code );
        $tax_total = round( (float) $order->get_total_tax(), 2 );

        $doc_date = $order->get_date_created()
            ? $order->get_date_created()->date( 'Ymd' )
            : gmdate( 'Ymd' );
        $due_date = gmdate( 'Ymd', strtotime( '+' . max( 1, (int) $this->payment_deadline ) . ' days' ) );

        return [
            'Customer'       => $customer,
            'AccountingDoc'  => 1,
            'DocDate'        => $doc_date,
            'DueDate'        => $due_date,
            'InvoiceNo'      => $this->arve_eesliides . $order->get_id(),
            'DepartmentCode' => $this->department_code ?: null,
            'ReferenceNo'    => null,
            'InvoiceRow'     => $rows,
            'TaxAmount'      => [
                [ 'TaxId' => $vat_code, 'Amount' => round( $tax_total, 2 ) ],
            ],
        ];
    }

    /**
     * Ehita payloadid kõigile filtreerimata orderitele (käsitsi saatmiseks).
     */
    public function create_invoice(): array {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return [ 'error' => 'WooCommerce ei ole aktiivne!' ];
        }

        $order_ids = $this->Smart_WP_Filter_Woocommerce_Merit_aktiva_Invoices();
        if ( empty( $order_ids ) ) {
            return [ 'error' => 'Ühtegi uut orderit ei leitud.' ];
        }

        $invoiceArray = [];
        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) continue;
            $payload = $this->build_payload_for_order( $order );
            if ( $payload ) $invoiceArray[] = $payload;
        }

        return $invoiceArray;
    }

    public function create_invoice_items_array( \WC_Order $order, ?string $vat_code_override = null ): array {
        $payload_arrays = [];

        foreach ( $order->get_items() as $item ) {
            $product  = $item->get_product();
            $sku      = $product ? $product->get_sku() : '';
            $qty      = max( 1, (int) $item->get_quantity() );
            $price_ex = round( (float) $item->get_total() / $qty, 4 );

            $payload_arrays[] = [
                'Item'           => [
                    'Code'        => $sku ? substr( $sku, 0, 20 ) : null,
                    'Description' => $item->get_name(),
                    'Type'        => (int) $this->arve_ridade_tyyp,
                    'UOMName'     => 'tk',
                ],
                'Quantity'       => (float) $qty,
                'Price'          => $price_ex,
                'DiscountPct'    => 0,
                'DiscountAmount' => 0,
                'TaxId'          => $vat_code_override ?: $this->tax_field,
                'LocationCode'   => '1',
            ];
        }

        foreach ( $order->get_shipping_methods() as $shipping_item ) {
            $shipping_total = (float) $shipping_item->get_total();
            if ( $shipping_total <= 0 ) continue;
            $payload_arrays[] = [
                'Item'           => [
                    'Code'        => 'TRANSPORT',
                    'Description' => $shipping_item->get_name(),
                    'Type'        => 1,
                    'UOMName'     => 'tk',
                ],
                'Quantity'       => 1,
                'Price'          => $shipping_total,
                'DiscountPct'    => 0,
                'DiscountAmount' => 0,
                'TaxId'          => $vat_code_override ?: $this->tax_field,
                'LocationCode'   => '1',
            ];
        }

        return $payload_arrays;
    }

    public function handle_ajax(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            wp_send_json_error( [ 'error' => 'WooCommerce ei ole aktiivne!' ] );
        }

        $payloads = $this->create_invoice();

        if ( empty( $payloads ) || isset( $payloads['error'] ) ) {
            wp_send_json_error( [ 'error' => $payloads['error'] ?? 'Ühtegi orderit ei leitud.' ] );
        }

        $results = [];
        $errors  = [];

        foreach ( $payloads as $payload ) {
            $res = LocalApiClient::sendEncryptedOrder( $payload, 'merit' );
            if ( in_array( $res['status'] ?? '', [ 'ok', 'queued' ], true ) ) {
                $results[] = $res;
            } else {
                $errors[] = $res;
            }
        }

        if ( ! empty( $errors ) ) {
            wp_send_json_error( [ 'message' => 'Mõned orderid ei õnnestunud saata', 'errors' => $errors, 'success' => $results ] );
        }

        wp_send_json_success( $results );
    }

    public function send_invoice_to_customer(): void {
        check_ajax_referer( 'my_nonce', 'security' );

        if ( ! class_exists( 'WooCommerce' ) ) {
            wp_send_json_error( [ 'error' => 'WooCommerce ei ole aktiivne!' ] );
        }

        $client  = new MeritServersDataClient();
        $results = $client->get_all_invoices();

        if ( is_array( $results ) ) {
            foreach ( $results as $invoice ) {
                if ( ! empty( $invoice->SIHId ) ) {
                    $client->merit_send_invoice_by_email( $invoice->SIHId );
                }
            }
        }

        wp_send_json_success( 'Emailid on saadetud' );
    }
}

$test = new My_Simple_Ajax_Plugin();
