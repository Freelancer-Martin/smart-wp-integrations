<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'LocalApiClient' ) ) :

class LocalApiClient {

    protected static function get_base_url(): string {
        $url = get_option( 'smart_wp_integration_server_url', '' );
        return $url ? rtrim( $url, '/' ) : 'http://172.168.10.105';
    }

    // Avalik meetod MeritServersDataClient jt jaoks
    public static function get_base_url_public(): string {
        return self::get_base_url();
    }

    protected static function get_license_endpoint(): string {
        return self::get_base_url() . '/api/license/check';
    }

    protected static function get_health_endpoint(): string {
        return self::get_base_url() . '/api/health';
    }

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

    private static function keys_for_system( string $system ): array {
        $map = [
            'merit'        => [ 'smart_wp_integtaion_license_text', 'smart_wp_integtaion_crypto_text' ],
            'simplebooks'  => [ 'swi_simplebooks_license_key',      'swi_simplebooks_crypto_key' ],
            'smartaccounts'=> [ 'swi_smartaccounts_license_key',     'swi_smartaccounts_crypto_key' ],
        ];
        [ $lk, $ck ] = $map[ $system ] ?? $map['merit'];
        return [ get_option( $lk, '' ), get_option( $ck, '' ) ];
    }

    public static function sendEncryptedOrder( array $orderPayload, string $system = 'merit', ?string $aad = null ): array {
        [ $license_key, $crypto_key ] = self::keys_for_system( $system );

        if ( empty( $crypto_key ) || empty( $license_key ) ) {
            return [ 'status' => 'error', 'message' => 'Litsentsi võti või krüptovõti puudub seadistustes.' ];
        }

        $key = hex2bin( $crypto_key );
        if ( $key === false || strlen( $key ) !== 32 ) {
            return [ 'status' => 'error', 'message' => 'Vigane krüptovõti (peab olema 64-märgiline HEX).' ];
        }

        $json = wp_json_encode( $orderPayload, JSON_UNESCAPED_UNICODE );
        if ( ! $json ) {
            return [ 'status' => 'error', 'message' => 'Payload JSON encode ebaõnnestus.' ];
        }

        if ( ! in_array( 'aes-256-gcm', openssl_get_cipher_methods( true ), true ) ) {
            return [ 'status' => 'error', 'message' => 'aes-256-gcm ei ole selle PHP/OpenSSL versiooniga toetatud.' ];
        }

        $iv       = random_bytes( 12 );
        $tag      = '';
        $aad_bytes = $aad ?? '';

        $cipher = openssl_encrypt( $json, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad_bytes, 16 );

        if ( $cipher === false || $tag === '' ) {
            return [ 'status' => 'error', 'message' => 'Krüpteerimine ebaõnnestus.' ];
        }

        $payload = [
            'system' => $system,
            'iv'     => base64_encode( $iv ),
            'data'   => base64_encode( $cipher ),
            'tag'    => base64_encode( $tag ),
        ];

        $resp = wp_remote_post( self::get_license_endpoint(), [
            'headers' => [
                'Content-Type'    => 'application/json',
                'Accept'          => 'application/json',
                'X-License-Token' => $license_key,
            ],
            'timeout' => 60,
            'body'    => wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
        ] );

        if ( is_wp_error( $resp ) ) {
            return [ 'status' => 'error', 'message' => $resp->get_error_message() ];
        }

        $code   = wp_remote_retrieve_response_code( $resp );
        $body   = wp_remote_retrieve_body( $resp );
        $result = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return [ 'status' => 'error', 'message' => 'Serverilt tuli vigane JSON.', 'http_code' => $code, 'raw' => $body ];
        }

        if ( $code === 202 && ! empty( $result['success'] ) ) {
            return [ 'status' => 'queued', 'message' => $result['message'] ?? 'Järjekorda lisatud', 'http_code' => $code, 'response' => $result ];
        }

        $is_success = ! empty( $result['success'] );
        return [
            'status'    => $is_success ? 'ok' : ( $result['status'] ?? 'error' ),
            'http_code' => $code,
            'response'  => $result,
        ];
    }
}

endif;
