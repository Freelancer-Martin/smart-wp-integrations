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
        $data = $this->call( 'merit/departments' );
        $list = $data['departments'] ?? [];

        $codes = [];
        foreach ( $list as $item ) {
            if ( isset( $item['Code'] ) ) {
                $codes[] = $item['Code'];
            }
        }

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
}
