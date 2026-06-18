<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'LocalApiClient' ) ) :

class LocalApiClient {

    /**
     * Endpoints – kohanda vastavalt.
     * Litsentsi kontroll: lihtne kontroll (Bearer), tagastab JSON.
     * Orderi saatmine: AES-GCM payload (iv,data,tag) + system.
     */
    protected static $license_endpoint  = 'http://172.168.10.105/api/license/check';
    protected static $health_endpoint   = 'http://172.168.10.105/api/health';
    
    // === AJAX: krüpteeritud orderi saatmine läbi WP AJAX-i ===
    public function __construct() {
        // Frontend (külalised)
        //add_action('wp_ajax_nopriv_local_send_encrypted_order', [$this, 'ajax_send_encrypted_order']);
        // Admin / logged-in
        //add_action('wp_ajax_local_send_encrypted_order', [$this, 'ajax_send_encrypted_order']);
        
        
    }

    
    /**
     * Kontrollib, kas vaheserver on kättesaadav.
     * Tagastab null kui ok, veateate kui ei saa ühendust.
     */
    public static function pingServer(): ?string {
        $license_key = get_option( 'smart_wp_integtaion_license_text', '' );

        $resp = wp_remote_get( self::$health_endpoint, [
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
            $body = wp_remote_retrieve_body( $resp );
            return sprintf( 'HTTP %d: %s', $code, wp_strip_all_tags( $body ) );
        }

        return null;
    }

    /**
     * Saada krüpteeritud order (AES-256-GCM).
     * Backend eeldab välju: system, iv, data, tag (HEX või BASE64). Kasutame BASE64.
     *
     * @param array $orderPayload – sinu tellimuse sisu (assoc array).
     * @param string $system – sihtsüsteemi alias (nt 'merit').
     * @param string|null $aad – valikuline AAD.
     * @return array – API vastus või viga.
     */
    public static function sendEncryptedOrder( array $orderPayload, string $system = 'merit', ?string $aad = null ) {
        
        
        $crypto_key  = get_option('smart_wp_integtaion_crypto_text');
        $license_key = get_option('smart_wp_integtaion_license_text');
        // 2) Võti (HEX -> raw 32 baiti)
        $key = hex2bin( $crypto_key );
        if ( $key === false || strlen( $key ) !== 32 ) {
            return [ 'status' => 'error', 'message' => 'Invalid crypto key format (expect 32-byte key in hex).' ];
        }

        // 3) JSON
        $json = wp_json_encode( $orderPayload, JSON_UNESCAPED_UNICODE );
        if ( ! $json ) {
            return [ 'status' => 'error', 'message' => 'Payload JSON encode failed.' ];
        }

        // 4) AES-256-GCM krüpteerimine
        if ( ! in_array( 'aes-256-gcm', openssl_get_cipher_methods( true ), true ) ) {
            return [ 'status' => 'error', 'message' => 'aes-256-gcm not supported by this PHP/OpenSSL build.' ];
        }

        $iv  = random_bytes(12); // GCM standardne IV = 12 baiti
        $tag = '';
        $aad_bytes = $aad !== null ? $aad : '';

        $cipher = openssl_encrypt(
            $json,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad_bytes,
            16 // 128-bit tag
        );

        if ( $cipher === false || $tag === '' ) {
            return [ 'status' => 'error', 'message' => 'Encryption failed (GCM).' ];
        }

        // 5) Koosta serverile sobiv payload
        $payload = [
            'system' => $system,
            'iv'     => base64_encode( $iv ),
            'data'   => base64_encode( $cipher ),
            'tag'    => base64_encode( $tag ),
            // 'aad'  => base64_encode($aad_bytes), // kui tahad AAD edastada ja backend seda arvestab
        ];

        $args = [
            'headers' => [
            'Content-Type'    => 'application/json',
            'Accept'          => 'application/json',
            'X-License-Token' => $license_key, // või 'Authorization' => 'Bearer '.$token
        ],
            'timeout' => 60,
            'body'    => wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
        ];

        $resp = wp_remote_post( self::$license_endpoint , $args );

        if ( is_wp_error( $resp ) ) {
            return [ 'status' => 'error', 'message' => $resp->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $resp );
        $body = wp_remote_retrieve_body( $resp );

        // Ootame JSON-i; sinu backend võib 202 tagastada "Queued for processing"
        $result = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return [ 'status' => 'error', 'message' => 'Invalid JSON from order endpoint.', 'http_code' => $code, 'raw' => $body ];
        }

        // Tõsta välja levinud variandid
        if ( $code === 202 && ! empty( $result['success'] ) ) {
            return [
                'status'    => 'queued',
                'message'   => $result['message'] ?? 'Queued for processing',
                'http_code' => $code,
                'response'  => $result,
            ];
        }

        return [
            'status'    => $result['status'] ?? ($result['success'] ? 'ok' : 'error'),
            'http_code' => $code,
            'response'  => $result,
        ];
    }
}
new LocalApiClient();

endif;
