<?php

if (!defined('ABSPATH')) {
    exit;
}

class LEW_Outbox
{
    public static function boot(): void
    {
        add_filter('cron_schedules', [self::class, 'add_cron_schedule']);
        add_action('lew_process_order_outbox', [self::class, 'process_order_outbox']);
        add_action('lew_process_refund_outbox', [self::class, 'process_refund_outbox']);
        add_action('lew_process_review_outbox', [self::class, 'process_review_outbox']);
    }

    public static function add_cron_schedule(array $schedules): array
    {
        $schedules['minute'] = [
            'interval' => MINUTE_IN_SECONDS,
            'display' => 'Every Minute',
        ];

        return $schedules;
    }

    public static function enqueue_order(int $order_id, array $payload): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'lew_order_outbox';
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE wc_order_id = %d", $order_id));
        if ($existing) {
            return;
        }

        $now = current_time('mysql', true);
        $wpdb->insert($table, [
            'wc_order_id' => $order_id,
            'status' => 'pending',
            'retry_count' => 0,
            'request_payload' => wp_json_encode($payload),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function enqueue_refund(int $refund_id, int $order_id, array $payload): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'lew_refund_outbox';
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE wc_refund_id = %d", $refund_id));
        if ($existing) {
            return;
        }

        $now = current_time('mysql', true);
        $wpdb->insert($table, [
            'wc_refund_id' => $refund_id,
            'wc_order_id' => $order_id,
            'status' => 'pending',
            'retry_count' => 0,
            'request_payload' => wp_json_encode($payload),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function enqueue_review(int $comment_id, array $payload): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'lew_review_outbox';
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE wp_comment_id = %d", $comment_id));
        if ($existing) {
            return;
        }

        $now = current_time('mysql', true);
        $wpdb->insert($table, [
            'wp_comment_id' => $comment_id,
            'status' => 'pending',
            'retry_count' => 0,
            'request_payload' => wp_json_encode($payload),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function process_order_outbox(): void
    {
        self::process_generic_outbox('lew_order_outbox');
    }

    public static function process_refund_outbox(): void
    {
        self::process_generic_outbox('lew_refund_outbox');
    }

    public static function process_review_outbox(): void
    {
        self::process_generic_outbox('lew_review_outbox');
    }

    private static function process_generic_outbox(string $suffix): void
    {
        global $wpdb;

        $table = $wpdb->prefix . $suffix;
        $lock_key = 'lew_outbox_lock_' . $suffix;
        if (get_transient($lock_key)) {
            return;
        }

        set_transient($lock_key, '1', 55);
        $rows = $wpdb->get_results(
            "SELECT * FROM {$table}
             WHERE status IN ('pending', 'failed')
             AND (next_retry_at IS NULL OR next_retry_at <= UTC_TIMESTAMP())
             ORDER BY id ASC
             LIMIT 20",
            ARRAY_A
        );

        if (!$rows) {
            delete_transient($lock_key);
            return;
        }

        $client = new LEW_Loyalty_Engage_Client();

        foreach ($rows as $row) {
            $payload = json_decode((string) $row['request_payload'], true);
            $row_id = (int) $row['id'];
            $wpdb->update($table, [
                'status' => 'processing',
                'updated_at' => current_time('mysql', true),
            ], ['id' => $row_id, 'status' => $row['status']]);

            $result = $client->request('/events', 'POST', is_array($payload) ? $payload : []);

            if (!empty($result['success'])) {
                $wpdb->update($table, [
                    'status' => 'sent',
                    'last_error' => null,
                    'updated_at' => current_time('mysql', true),
                ], ['id' => $row_id]);
                LEW_Logger::info('Outbox event sent', ['table' => $table, 'row_id' => $row_id]);
                continue;
            }

            $retry_count = (int) $row['retry_count'] + 1;
            $delay_minutes = min(32, 2 ** $retry_count);
            $status = $retry_count >= 5 ? 'dead_letter' : 'failed';
            $next_retry = gmdate('Y-m-d H:i:s', time() + ($delay_minutes * MINUTE_IN_SECONDS));

            $wpdb->update($table, [
                'status' => $status,
                'retry_count' => $retry_count,
                'next_retry_at' => $status === 'dead_letter' ? null : $next_retry,
                'last_error' => (string) ($result['message'] ?? 'Unknown error'),
                'updated_at' => current_time('mysql', true),
            ], ['id' => $row_id]);
            LEW_Logger::warning('Outbox event failed', [
                'table' => $table,
                'row_id' => $row_id,
                'status' => $status,
                'retry_count' => $retry_count,
                'message' => (string) ($result['message'] ?? 'Unknown error'),
            ]);
        }

        delete_transient($lock_key);
    }
}
