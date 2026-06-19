<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WordPress plugina ja Laravel vaheserveri vahelise suhtluse klass.
 *
 * Kogu plugina välistsuhtlus käib läbi selle klassi. Põhivoog:
 * 1. Võta payload (Merit/Simplebooks arve andmed)
 * 2. Krüpteeri AES-256-GCM-iga kasutades litsentsi kryptovõtit
 * 3. Saada krüpteeritud andmed Laravel-i /api/license/check endpointi
 * 4. Laravel dekrüpteerib, valideerib litsentsi ja edastab vastavale handlerile
 *
 * Krüpteerimine on kohustuslik kuna payload sisaldab tundlikke kliendi andmeid
 * (nimed, aadressid, summad) ning saadetakse üle avaliku interneti.
 * AES-256-GCM tagab nii konfidentsiaalsuse (krüpteering) kui tervikluse (GCM tag).
 *
 * if (!class_exists) kaitseb topelt-laadimise eest, kuna fail include-itakse
 * mitmes kohas ja PHP ei tohi sama klassi kaks korda defineerida.
 *
 * @package Smart_Wp_Integrations
 */

if ( ! class_exists( 'LocalApiClient' ) ) :

class LocalApiClient {

    /**
     * Kirjuta debug-rida logifaili.
     *
     * Fail asub wp-content/uploads/swi-debug.txt — uploads kaust on kirjutatav
     * ja välistele kasutajatele nähtav, kuid nimes pole isikuandmeid.
     * FILE_APPEND | LOCK_EX tagab et paralleelsed protsessid (nt mitu orderit korraga)
     * ei kirjuta teineteise kirjete peale.
     *
     * @param string      $direction 'plugin->laravel' või 'laravel->plugin'.
     * @param string      $system    'merit', 'simplebooks' vms.
     * @param mixed       $order_id  Order ID või null kui teadmata.
     * @param string      $status    Lühike staatuslabel, nt 'SEND', 'OK', 'ERROR'.
     * @param string      $detail    Lisainfo, nt URL või veateade.
     */
    private static function log( string $direction, string $system, $order_id, string $status, string $detail ): void {
        $file = WP_CONTENT_DIR . '/uploads/swi-debug.txt';
        $line = sprintf(
            "[%s] [%s] [%s] order=%s status=%s | %s\n",
            date( 'Y-m-d H:i:s' ),
            strtoupper( $direction ),
            strtoupper( $system ),
            $order_id !== null ? $order_id : '-',
            $status,
            $detail
        );
        file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
    }

    /**
     * Tagastab vaheserveri baas-URL seadistest.
     *
     * Fallback IP 172.168.10.105 on arenduse/testkeskkonna IP — tootmises
     * peaks smart_wp_integration_server_url alati seadistatud olema.
     *
     * @return string URL ilma lõpu kaldkriipsuta.
     */
    protected static function get_base_url(): string {
        $url = get_option( 'smart_wp_integration_server_url', '' );
        return $url ? rtrim( $url, '/' ) : 'http://172.168.10.105';
    }

    /**
     * Avalik wrapper get_base_url() meetodile teistele klassidele (nt MeritServersDataClient).
     *
     * @return string Vaheserveri baas-URL.
     */
    public static function get_base_url_public(): string {
        return self::get_base_url();
    }

    /**
     * Tagastab litsentsi kontrollimise endpointide täis-URL.
     *
     * @return string Täis-URL, nt 'https://server.example.com/api/license/check'.
     */
    protected static function get_license_endpoint(): string {
        return self::get_base_url() . '/api/license/check';
    }

    /**
     * Tagastab serveri tervise kontrollimise endpointide täis-URL.
     *
     * @return string Täis-URL, nt 'https://server.example.com/api/health'.
     */
    protected static function get_health_endpoint(): string {
        return self::get_base_url() . '/api/health';
    }

    /**
     * Kontrollib kas vaheserver on kättesaadav.
     *
     * Kasutatakse seadete lehel ühenduse testimiseks. Timeout 5s on tahtlikult
     * lühike — kasutaja ootab kohest vastust, seega aeglane vastus on sama halb
     * kui ühenduse puudumine.
     *
     * @return string|null Null kui ühendus OK, veateade string kui probleem.
     */
    public static function pingServer(): ?string {
        $license_key = get_option( 'smart_wp_integtaion_license_text', '' );

        $resp = wp_remote_get( self::get_health_endpoint(), [
            'timeout' => 5,
            'headers' => [
                'X-License-Token' => $license_key,
                'Accept'          => 'application/json',
            ],
        ] );

        if ( is_wp_error( $resp ) ) {
            return $resp->get_error_message();
        }

        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code < 200 || $code >= 300 ) {
            return sprintf( 'HTTP %d: %s', $code, wp_strip_all_tags( wp_remote_retrieve_body( $resp ) ) );
        }

        return null;
    }

    /**
     * Tagastab õige litsentsi- ja kryptovõtme wp_options välja nimed vastavalt süsteemile.
     *
     * Iga integratsioon (merit, simplebooks, smartaccounts) hoiab oma võtmeid
     * eraldi wp_options kirjetes. See eraldatus tähendab, et ühe süsteemi
     * võtme leke ei mõjuta teisi.
     *
     * @param string $system Integratsioonisüsteemi nimi ('merit', 'simplebooks', 'smartaccounts').
     * @return array [license_key_value, crypto_key_value].
     */
    private static function keys_for_system( string $system ): array {
        $map = [
            'merit'         => [ 'smart_wp_integtaion_license_text', 'smart_wp_integtaion_crypto_text' ],
            'simplebooks'   => [ 'swi_simplebooks_license_key',      'swi_simplebooks_crypto_key' ],
            'smartaccounts' => [ 'swi_smartaccounts_license_key',     'swi_smartaccounts_crypto_key' ],
            'erply'         => [ 'swi_erply_license_key',            'swi_erply_crypto_key' ],
        ];
        // Tundmatu süsteemi korral tagastatakse Merit võtmed (tagavaravõimalus)
        [ $lk, $ck ] = $map[ $system ] ?? $map['merit'];
        return [ get_option( $lk, '' ), get_option( $ck, '' ) ];
    }

    /**
     * Krüpteerib orderi payload AES-256-GCM-iga ja saadab vaheserverisse.
     *
     * Krüpteerimise voog:
     * 1. Payload → JSON string
     * 2. Kryptovõti HEX kujul (64 märki) → 32 baidine binaarstring (hex2bin)
     * 3. random_bytes(12) → 12-baidine IV (nonce) — iga saatmise jaoks uus
     * 4. openssl_encrypt AES-256-GCM → ciphertext + 16-baidine GCM auth tag
     * 5. IV + ciphertext + tag saadetakse base64 kodeeringus JSON-ina
     *
     * GCM auth tag ($tag) on kriitiline — ilma selleta ei saa Laravel kontrollida
     * kas andmed on transiidigel muutmata. Pikkus 16 baiti on GCM maksimaalne turvalisus.
     *
     * HTTP 202 vastus tähendab 'queued' — Laravel lisas töö järjekorda,
     * edu kinnitatakse hiljem (async töötlus). 200 tähendab sünkroonset edu.
     *
     * @param array       $orderPayload Arve andmed Merit/Simplebooks formaadis.
     * @param string      $system       Süsteem kuhu saata: 'merit', 'simplebooks', 'smartaccounts'.
     * @param string|null $aad          Lisatuvastusandmed (Additional Authenticated Data) GCM-ile.
     * @return array ['status' => 'ok'|'queued'|'error', 'message' => ..., 'response' => ..., 'http_code' => ...].
     */
    public static function sendEncryptedOrder( array $orderPayload, string $system = 'merit', ?string $aad = null ): array {
        // Logi ID tuvastamiseks: arve number Merit jaoks, kliendi nimi Simplebooks jaoks
        $order_id = $orderPayload['InvoiceNo'] ?? $orderPayload['Customer']['Name'] ?? '?';

        [ $license_key, $crypto_key ] = self::keys_for_system( $system );

        if ( empty( $crypto_key ) || empty( $license_key ) ) {
            $msg = 'Litsentsi võti või krüptovõti puudub seadistustes.';
            self::log( 'plugin->laravel', $system, $order_id, 'ERROR', $msg );
            return [ 'status' => 'error', 'message' => $msg ];
        }

        // Kryptovõti peab olema täpselt 64 HEX märki = 32 baiti AES-256 jaoks
        $key = hex2bin( $crypto_key );
        if ( $key === false || strlen( $key ) !== 32 ) {
            $msg = 'Vigane krüptovõti (peab olema 64-märgiline HEX).';
            self::log( 'plugin->laravel', $system, $order_id, 'ERROR', $msg );
            return [ 'status' => 'error', 'message' => $msg ];
        }

        $json = wp_json_encode( $orderPayload, JSON_UNESCAPED_UNICODE );
        if ( ! $json ) {
            $msg = 'Payload JSON encode ebaõnnestus.';
            self::log( 'plugin->laravel', $system, $order_id, 'ERROR', $msg );
            return [ 'status' => 'error', 'message' => $msg ];
        }

        // Kontrolli et PHP/OpenSSL supports AES-256-GCM — vanemates PHP versioonides puudub
        if ( ! in_array( 'aes-256-gcm', openssl_get_cipher_methods( true ), true ) ) {
            $msg = 'aes-256-gcm ei ole selle PHP/OpenSSL versiooniga toetatud.';
            self::log( 'plugin->laravel', $system, $order_id, 'ERROR', $msg );
            return [ 'status' => 'error', 'message' => $msg ];
        }

        // Iga sõnumi jaoks uus juhuslik IV — sama võtmega ei tohi kahte sõnumit sama IV-ga saata
        $iv        = random_bytes( 12 );
        $tag       = '';
        $aad_bytes = $aad ?? '';
        // $tag edastatakse viidetena (pass by reference) — openssl_encrypt täidab selle GCM tagiga
        $cipher    = openssl_encrypt( $json, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad_bytes, 16 );

        if ( $cipher === false || $tag === '' ) {
            $msg = 'Krüpteerimine ebaõnnestus.';
            self::log( 'plugin->laravel', $system, $order_id, 'ERROR', $msg );
            return [ 'status' => 'error', 'message' => $msg ];
        }

        // Kõik binaarandmed base64-kodeeringus JSON-i transportimiseks HTTP kaudu
        $payload = [
            'system' => $system,
            'iv'     => base64_encode( $iv ),
            'data'   => base64_encode( $cipher ),
            'tag'    => base64_encode( $tag ),
        ];

        self::log( 'plugin->laravel', $system, $order_id, 'SEND', 'POST ' . self::get_license_endpoint() );

        $resp = wp_remote_post( self::get_license_endpoint(), [
            'headers' => [
                'Content-Type'    => 'application/json',
                'Accept'          => 'application/json',
                // X-License-Token identifitseerib WC saidi Laravel-is — iga sait on eraldi litsents
                'X-License-Token' => $license_key,
            ],
            // Timeout 60s: Merit API võib vastata aeglaselt suure koormuse korral
            'timeout' => 60,
            'body'    => wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
        ] );

        if ( is_wp_error( $resp ) ) {
            $msg = $resp->get_error_message();
            self::log( 'plugin->laravel', $system, $order_id, 'ERROR', 'WP_Error: ' . $msg );
            return [ 'status' => 'error', 'message' => $msg ];
        }

        $code   = wp_remote_retrieve_response_code( $resp );
        $body   = wp_remote_retrieve_body( $resp );
        $result = json_decode( $body, true );

        // Vigase JSON-i kontroll: Laravel peaks alati JSON tagastama, aga PHP/veebiserver võib tagastada HTML veateateid
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            self::log( 'plugin->laravel', $system, $order_id, 'ERROR', "HTTP {$code} vigane JSON: " . substr( $body, 0, 200 ) );
            return [ 'status' => 'error', 'message' => 'Serverilt tuli vigane JSON.', 'http_code' => $code, 'raw' => $body ];
        }

        // HTTP 202 = async järjekorda lisatud (Laravel queue worker töötleb hiljem)
        if ( $code === 202 && ! empty( $result['success'] ) ) {
            $msg = $result['message'] ?? 'Järjekorda lisatud';
            self::log( 'plugin->laravel', $system, $order_id, 'QUEUED', "HTTP {$code}: {$msg}" );
            return [ 'status' => 'queued', 'message' => $msg, 'http_code' => $code, 'response' => $result ];
        }

        // Ülejäänud vastused: edu kui result['success'] on truthy, muidu viga
        $is_success = ! empty( $result['success'] );
        $status_str = $is_success ? 'OK' : 'ERROR';
        $detail     = $result['message'] ?? ( $is_success ? 'Edastatud' : wp_json_encode( $result ) );
        self::log( 'plugin->laravel', $system, $order_id, "HTTP{$code} {$status_str}", $detail );

        return [
            'status'    => $is_success ? 'ok' : ( $result['status'] ?? 'error' ),
            'http_code' => $code,
            'response'  => $result,
        ];
    }
}

endif;
