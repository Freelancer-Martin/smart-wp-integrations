<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Küsib Merit Aktiva andmeid läbi vaheserveri (Laravel).
 * Plugin ei kutsu Merit API-t kunagi otse.
 */
class MeritServersDataClient {

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
            $msg = $data['error'] ?? $body;
            throw new RuntimeException( "HTTP {$code}: {$msg}" );
        }

        return $data ?? [];
    }

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

    public function get_all_invoices(): array {
        $data = $this->call( 'merit/invoices' );
        return $data['invoices'] ?? [];
    }

    public function merit_send_invoice_by_email( string $invoiceGuid ): void {
        // E-maili saatmine käib läbi Laravel-i — praegu mitte implementeeritud.
        // Kui vaja, lisa POST /api/merit/send-invoice-email endpoint.
        throw new RuntimeException( 'merit_send_invoice_by_email pole veel proxy kaudu implementeeritud.' );
    }
}
