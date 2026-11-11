<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Merit Aktiva andmete lugemine vaheserveri (Laravel) kaudu.
 *
 * Plugin ei ühenda Merit API-ga otse kunagi — kõik päringud suunatakse
 * Laravel-i vaheserverisse, mis lisab HMAC-SHA256 autentimise ja edastab
 * päringud Merit Aktiva serverile. See arhitektuur hoidab Merit API võtmed
 * ainult serveris, mitte WordPress-i andmebaasis.
 *
 * @package Smart_Wp_Integrations
 */
class MeritServersDataClient {

    /**
     * Sisemine abimeetod: saada autenditud GET päring vaheserverisse.
     *
     * X-License-Token header on see, millega Laravel tuvastab millist Merit
     * kontot kasutada — iga WooCommerce sait on eraldi litsents eraldi Merit
     * API võtmetega. Timeout 15s on kompromiss: piisav Merit API aegluse jaoks,
     * aga mitte nii pikk et WP admin-leht külmuks.
     *
     * @param string $path API tee ilma algse kaldkriipsuta, nt 'merit/invoices'.
     * @return array Dekodeeritud JSON vastus massiivina.
     * @throws RuntimeException Võrguühenduse viga või mitte-2xx HTTP vastuse korral.
     */
    private function call( string $path ): array {
        $base        = LocalApiClient::get_base_url_public();
        $license_key = get_option( 'smart_wp_integtaion_license_text', '' );

        $resp = wp_remote_get( rtrim( $base, '/' ) . '/api/' . ltrim( $path, '/' ), [
            'timeout' => 15,
            'headers' => [
                'X-License-Token' => $license_key,
                'Accept'          => 'application/json',
            ],
        ] );

        if ( is_wp_error( $resp ) ) {
            throw new RuntimeException( $resp->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $resp );
        $body = wp_remote_retrieve_body( $resp );
        $data = json_decode( $body, true );

        if ( $code < 200 || $code >= 300 ) {
            // Kasuta Laravel-i error välja kui saadaval, muidu toorkehasse
            $msg = $data['error'] ?? $body;
            throw new RuntimeException( "HTTP {$code}: {$msg}" );
        }

        return $data ?? [];
    }

    /**
     * Tagastab Merit Aktiva osakondade koodide nimekirja.
     *
     * Kasutatakse seadete lehel osakondade valiku täitmiseks.
     * Laravel-i endpoint küsib Merit API-st osakonnad ja tagastab need JSON-ina.
     *
     * @return array Osakondade koodide massiiv, nt ['SALES', 'SUPPORT', 'WAREHOUSE'].
     */
    public function getDepartments(): array {
        $cached = get_transient( 'swi_merit_departments' );
        if ( $cached !== false ) return $cached;

        $data = $this->call( 'merit/departments' );
        $list = $data['departments'] ?? [];

        $codes = [];
        foreach ( $list as $item ) {
            if ( isset( $item['Code'] ) ) {
                $codes[] = $item['Code'];
            }
        }

        set_transient( 'swi_merit_departments', $codes, 10 * MINUTE_IN_SECONDS );
        return $codes;
    }

    /**
     * Tagastab viimase perioodi müügiarved Merit Aktivast.
     *
     * Kasutatakse peamiselt topeltsaatmise kontrolliks — enne uue arve saatmist
     * võrreldakse WC orderi arvenumbrit (eesliide + ID) Merit arvete nimekirjaga.
     *
     * @return array Merit arve objektide massiiv.
     */
    public function get_all_invoices(): array {
        $data = $this->call( 'merit/invoices' );
        return $data['invoices'] ?? [];
    }

    /**
     * Tagastab arved sünkroonimise kontrolliks pikemat perioodi hõlmates.
     *
     * Erineb get_all_invoices()-ist selle poolest, et võimaldab perioodi määrata.
     * Merit API ise piirab perioodi 3 kuuga, seega months=12 tagastab praktikas
     * siiski ainult viimased 3 kuud — Laravel-i poolel on see piirang lahendatud.
     *
     * @param int $months Soovitud periood kuudes (Merit piirab sisemiselt 3-le).
     * @return array Merit arve objektide massiiv.
     */
    public function get_all_invoices_for_sync( int $months = 12 ): array {
        $data = $this->call( 'merit/all-invoices?months=' . $months );
        return $data['invoices'] ?? [];
    }

    /**
     * Saadaks Merit Aktiva arve e-mailiga kliendile.
     *
     * Meetod on reserveeritud tulevaseks kasutuseks — praegu pole Laravel-i poolel
     * vastavat POST /api/merit/send-invoice-email endpointi implementeeritud.
     * Kutsutakse send_invoice_to_customer() AJAX handleris.
     *
     * @param string $invoiceGuid Merit Aktiva arve GUID (SIHId väli).
     * @throws RuntimeException Alati — meetod pole implementeeritud.
     */
    public function merit_send_invoice_by_email( string $invoiceGuid ): void {
        // E-maili saatmine käib läbi Laravel-i — praegu mitte implementeeritud.
        // Kui vaja, lisa POST /api/merit/send-invoice-email endpoint.
        throw new RuntimeException( 'merit_send_invoice_by_email pole veel proxy kaudu implementeeritud.' );
    }

    /**
     * Tagastab Merit Aktiva kõik konfigureeritud käibemaksumäärad UUID-de ja protsendiga.
     *
     * Kasutatakse seadete lehel (Feature 1) et admin saaks valida õige VAT UUID
     * otse Merit serverist, mitte sisestada neid käsitsi. Iga kirje sisaldab
     * Code (lühikood), Name (kuvatav nimi) ja Rate (protsent).
     *
     * @return array Maksumäärade massiiv, nt [['Code' => 'VATEE22', 'Name' => 'Käibemaks 22%', 'Rate' => 22]].
     */
    public function get_vatcodes(): array {
        $data = $this->call( 'merit/vatcodes' );
        return $data['vatcodes'] ?? [];
    }

    /**
     * Sisemine abimeetod: saada autenditud POST päring (JSON body) vaheserverisse.
     *
     * Samad põhimõtted mis call()-il, aga kirjutavate päringute jaoks (makse, kliendi/toote sync,
     * arve kustutamine) — neil on JSON body, mitte ainult query string.
     *
     * @param string $path API tee ilma algse kaldkriipsuta, nt 'merit/payment'.
     * @param array  $body Saadetav JSON body.
     * @return array Dekodeeritud JSON vastus massiivina.
     * @throws RuntimeException Võrguühenduse viga või mitte-2xx HTTP vastuse korral.
     */
    private function call_post( string $path, array $body = [] ): array {
        $base        = LocalApiClient::get_base_url_public();
        $license_key = get_option( 'smart_wp_integtaion_license_text', '' );

        $resp = wp_remote_post( rtrim( $base, '/' ) . '/api/' . ltrim( $path, '/' ), [
            'timeout' => 15,
            'headers' => [
                'X-License-Token' => $license_key,
                'Accept'          => 'application/json',
                'Content-Type'    => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $resp ) ) {
            throw new RuntimeException( $resp->get_error_message() );
        }

        $code      = wp_remote_retrieve_response_code( $resp );
        $resp_body = wp_remote_retrieve_body( $resp );
        $data      = json_decode( $resp_body, true );

        if ( $code < 200 || $code >= 300 ) {
            $msg = $data['error'] ?? $resp_body;
            throw new RuntimeException( "HTTP {$code}: {$msg}" );
        }

        return $data ?? [];
    }

    /**
     * Märgib Meriti arve tasutuks (vt api.merit.ee sendpayment).
     *
     * @param string      $invoice_no    Arve number (nt 'WP123').
     * @param float       $amount        Makse summa.
     * @param string      $payment_date  Makse kuupäev kujul Y-m-d.
     * @param string|null $customer_name Kliendi nimi (valikuline, aitab Meritil täpsemalt sobitada).
     * @return array Laravel-i proxy vastus.
     */
    public function send_payment( string $invoice_no, float $amount, string $payment_date, ?string $customer_name = null ): array {
        return $this->call_post( 'merit/payment', [
            'invoice_no'    => $invoice_no,
            'amount'        => $amount,
            'payment_date'  => $payment_date,
            'customer_name' => $customer_name,
        ] );
    }

    /**
     * Loob või uuendab Meriti kliendikaarti.
     *
     * Kui $fields sisaldab 'merit_customer_id', uuendatakse olemasolevat kaarti,
     * vastasel juhul luuakse uus ja Laravel tagastab vastuses uue 'Id'.
     *
     * @param array $fields Kliendi väljad (name, country_code, email, reg_no, merit_customer_id jne).
     * @return array Laravel-i proxy vastus.
     */
    public function sync_customer( array $fields ): array {
        return $this->call_post( 'merit/customer', $fields );
    }

    /**
     * Loob või uuendab Meriti tootekaardi.
     *
     * Kui $fields sisaldab 'merit_item_id', uuendatakse olemasolevat kaarti,
     * vastasel juhul luuakse uus ja Laravel tagastab vastuses uue ItemId-i.
     *
     * @param array $fields Toote väljad (code, description, sales_price, merit_item_id jne).
     * @return array Laravel-i proxy vastus.
     */
    public function sync_item( array $fields ): array {
        return $this->call_post( 'merit/item', $fields );
    }

    /**
     * Tagastab Meriti arve täisdetailid (päised + arveread) arve numbri järgi.
     *
     * @param string $invoice_no Arve number (nt 'WP123').
     * @return array Arve detailid ('invoice') ja kokkuvõte ('summary').
     */
    public function get_invoice_details( string $invoice_no ): array {
        return $this->call( 'merit/invoice/details?invoice_no=' . rawurlencode( $invoice_no ) );
    }

    /**
     * Kustutab Meriti arve arve numbri järgi. Ei puutu WC tellimust ennast.
     *
     * @param string $invoice_no Arve number (nt 'WP123').
     * @return array Laravel-i proxy vastus.
     */
    public function delete_invoice( string $invoice_no ): array {
        return $this->call_post( 'merit/invoice/delete', [ 'invoice_no' => $invoice_no ] );
    }
}
