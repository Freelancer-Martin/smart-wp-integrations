<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'LocalApiClient' ) ) :

class LocalApiClient {

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

    protected static function get_base_url(): string {
        $url = get_option( 'smart_wp_integration_server_url', '' );
        return $url ? rtrim( $url, '/' ) : 'http://172.168.10.105';
    }

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
            'merit'         => [ 'smart_wp_integtaion_license_text', 'smart_wp_integtaion_crypto_text' ],
            'simplebooks'   => [ 'swi_simplebooks_license_key',      'swi_simplebooks_crypto_key' ],
            'smartaccounts' => [ 'swi_smartaccounts_license_key',     'swi_smartaccounts_crypto_key' ],
        ];
        [ $lk, $ck ] = $map[ $system ] ?? $map['merit'];
        return [ get_option( $lk, '' ), get_option( $ck, '' ) ];
    }

    public static function sendEncryptedOrder( array $orderPayload, string $system = 'merit', ?string $aad = null ): array {
        $order_id = $orderPayload['InvoiceNo'] ?? $orderPayload['Customer']['Name'] ?? '?';

        [ $license_key, $crypto_key ] = self::keys_for_system( $system );

        if ( empty( $crypto_key ) || empty( $license_key ) ) {
            $msg = 'Litsentsi võti või krüptovõti puudub seadistustes.';
            self::log( 'plugin->laravel', $system, $order_id, 'ERROR', $msg );
            return [ 'status' => 'error', 'message' => $msg ];
        }

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

        if ( ! in_array( 'aes-256-gcm', openssl_get_cipher_methods( true ), true ) ) {
            $msg = 'aes-256-gcm ei ole selle PHP/OpenSSL versiooniga toetatud.';
            self::log( 'plugin->laravel', $system, $order_id, 'ERROR', $msg );
            return [ 'status' => 'error', 'message' => $msg ];
        }

        $iv        = random_bytes( 12 );
        $tag       = '';
        $aad_bytes = $aad ?? '';
        $cipher    = openssl_encrypt( $json, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad_bytes, 16 );

        if ( $cipher === false || $tag === '' ) {
            $msg = 'Krüpteerimine ebaõnnestus.';
            self::log( 'plugin->laravel', $system, $order_id, 'ERROR', $msg );
            return [ 'status' => 'error', 'message' => $msg ];
        }

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
                'X-License-Token' => $license_key,
            ],
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

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            self::log( 'plugin->laravel', $system, $order_id, 'ERROR', "HTTP {$code} vigane JSON: " . substr( $body, 0, 200 ) );
            return [ 'status' => 'error', 'message' => 'Serverilt tuli vigane JSON.', 'http_code' => $code, 'raw' => $body ];
        }

        if ( $code === 202 && ! empty( $result['success'] ) ) {
            $msg = $result['message'] ?? 'Järjekorda lisatud';
            self::log( 'plugin->laravel', $system, $order_id, 'QUEUED', "HTTP {$code}: {$msg}" );
            return [ 'status' => 'queued', 'message' => $msg, 'http_code' => $code, 'response' => $result ];
        }

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
