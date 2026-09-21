<?php

if (!defined('ABSPATH')) {
    exit;
}

class LEW_Loyalty_Engage_Client
{
    private string $base_url;

    public function __construct()
    {
        $settings = LEW_Settings::get_settings();
        $configured = trim((string) ($settings['api_base_url'] ?? ''));
        $this->base_url = untrailingslashit($configured !== '' ? $configured : 'https://app.loyaltyengage.tech/api/v1');
    }

    public function request(string $path, string $method = 'GET', ?array $body = null): array
    {
        if (!LEW_Settings::is_module_enabled()) {
            return [
                'success' => false,
                'status' => 503,
                'message' => 'Loyalty Engage module is disabled.',
                'body' => null,
            ];
        }

        $settings = LEW_Settings::get_settings();
        $client_id = trim((string) ($settings['client_id'] ?? ''));
        $client_secret = trim((string) ($settings['client_secret'] ?? ''));

        if ($client_id === '' || $client_secret === '') {
            LEW_Logger::warning('Loyalty Engage credentials missing');
            return [
                'success' => false,
                'status' => 400,
                'message' => 'Missing Loyalty Engage credentials.',
                'body' => null,
            ];
        }

        $args = [
            'method' => strtoupper($method),
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $client_secret),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($this->base_url . $path, $args);
        if (is_wp_error($response)) {
            LEW_Logger::error('Loyalty Engage request failed', [
                'path' => $path,
                'method' => strtoupper($method),
                'error' => $response->get_error_message(),
            ]);
            return [
                'success' => false,
                'status' => 500,
                'message' => $response->get_error_message(),
                'body' => null,
            ];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $decoded = json_decode($raw_body, true);
        if ($status < 200 || $status >= 300) {
            LEW_Logger::warning('Loyalty Engage returned non-success response', [
                'path' => $path,
                'method' => strtoupper($method),
                'status' => $status,
                'body' => is_array($decoded) ? $decoded : $raw_body,
            ]);
        }

        return [
            'success' => $status >= 200 && $status < 300,
            'status' => $status,
            'message' => is_array($decoded) && !empty($decoded['message']) ? (string) $decoded['message'] : '',
            'body' => is_array($decoded) ? $decoded : $raw_body,
        ];
    }
}
