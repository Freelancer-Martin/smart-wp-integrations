<?php
class MeritServersDataClient
{
    private string $endpoint;
    private string $apiId;
    private string $apiKey; 
    private string $get_invoices_api_url;


    public function __construct() 
    {
        $this->apiId    = '728ba0f4-3fd2-47a5-b0a6-4912e29dc96f';
        $this->apiKey   = '+R5mDWMN+tFoJ1XyAqI83MJQbYWMD5Nj+6fwRX00Hss=';        
        $this->endpoint = 'https://aktiva.merit.ee/api/v1/getdepartments';
        $this->get_invoices_api_url = 'https://aktiva.merit.ee/api/v2/getinvoices';
    }

    /**
     * Võta kõik osakonnad (tagastab PHP massiivi).
     * @return array<int, array<string, mixed>>
     * @throws RuntimeException
     */
    public function getDepartments(): array
    {
        // 1) Ajatempel + JSON (GET puhul tühi)
        $timestamp = gmdate('YmdHis');
        $json      = '';

        // 2) HMAC-SHA256 -> base64
        $signable  = $this->apiId . $timestamp . $json;
        $rawSig    = hash_hmac('sha256', $signable, $this->apiKey, true);
        $signature = base64_encode($rawSig);

        // 3) URL koos queryga
        $query = http_build_query([
            'ApiId'     => $this->apiId,
            'timestamp' => $timestamp,
            'signature' => $signature,
        ]);
        $url = $this->endpoint . '?' . $query;

        // 4) cURL GET
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
            ],
        ]);

        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException("cURL error: {$err}");
        }
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("HTTP {$code}: {$body}");
        }

        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('JSON decode error: ' . json_last_error_msg() . " | Raw: {$body}");
        }

        foreach ($decoded as $line)
        {
            return (array)$line['Code'];
        }
    }


    public function get_all_invoices(): array {
        $timestamp = gmdate('YmdHis');
        $signature = hash_hmac('sha256', $timestamp, $this->apiId);

        $today = new DateTime();
        $threeMonthsAgo = (new DateTime())->modify('-3 months');
        $periodStart = $threeMonthsAgo->format('Ymd');
        $periodEnd = $today->format('Ymd');

        $payload = array(
            'Periodstart' => intval($periodStart),
            'PeriodEnd' => intval($periodEnd),
            'UnPaid' => true,
        );

        $request_url = $this->get_invoices_api_url . '?ApiId=' . $this->apiId . '&timestamp=' . $timestamp . '&signature=' . $signature;

        $request_args = array(
            'body' => json_encode($payload),
            'headers' => array('Content-Type' => 'application/json'),
            'method' => 'POST',
        );

        $response = wp_remote_request($request_url, $request_args);

        if (is_wp_error($response)) {
            error_log('Error fetching invoices: ' . $response->get_error_message());
            //return;
        }

        $body = wp_remote_retrieve_body($response);
        $decode_json = json_decode($body);
        
        return $decode_json;
    }


    function merit_send_invoice_by_email( $invoiceGuid, $delivNote = false, $handshakeGuid = null ) {
        $payload = [
            'Id'        => $invoiceGuid,          // SIHId (GUID), nt "6126680c-10eb-40eb-a496-7111de0ebe20"
            'DelivNote' => (bool) $delivNote,     // true = hinnadeta saateleht
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $timestamp = gmdate('YmdHis'); // UTC

        // HMAC-SHA256 sign(ApiId + timestamp + RequestJSON), Base64-encode raw output
        $signature = base64_encode( hash_hmac('sha256', $this->apiId.$timestamp.$json, $this->apiKey, true) );

        $url = "https://aktiva.merit.ee/api/v2/sendinvoicebyemail"
            . "?ApiId={$this->apiId}&timestamp={$timestamp}&signature={$signature}";

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        if ($handshakeGuid) { $headers[] = 'Handshake: '.$handshakeGuid; } // valikuline

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($code !== 200) {
            throw new \RuntimeException("Merit API error HTTP {$code}: ".$resp);
        }
        return trim($resp, "\"\r\n"); // ootuspäraselt "OK"
    }



    
}


