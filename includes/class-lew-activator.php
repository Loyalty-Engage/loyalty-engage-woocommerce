<?php

if (!defined('ABSPATH')) {
    exit;
}

class LEW_Activator
{
    private const SCHEMA_VERSION = '2026-09-03';

    public static function activate(): void
    {
        self::sync_schema();
        update_option('lew_schema_version', self::SCHEMA_VERSION);
    }

    public static function maybe_upgrade(): void
    {
        if (get_option('lew_schema_version') === self::SCHEMA_VERSION) {
            return;
        }

        self::sync_schema();
        update_option('lew_schema_version', self::SCHEMA_VERSION);
    }

    private static function sync_schema(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $orders = $wpdb->prefix . 'lew_order_outbox';
        $refunds = $wpdb->prefix . 'lew_refund_outbox';
        $reviews = $wpdb->prefix . 'lew_review_outbox';
        $discounts = $wpdb->prefix . 'lew_discount_codes';
        $redemptions = $wpdb->prefix . 'lew_physical_redemptions';

        dbDelta("
            CREATE TABLE {$orders} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                wc_order_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                retry_count INT UNSIGNED NOT NULL DEFAULT 0,
                request_payload LONGTEXT NOT NULL,
                next_retry_at DATETIME NULL,
                last_error TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY wc_order_id (wc_order_id),
                KEY status_next_retry_at (status, next_retry_at)
            ) {$charset};
        ");

        dbDelta("
            CREATE TABLE {$refunds} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                wc_refund_id BIGINT UNSIGNED NOT NULL,
                wc_order_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                retry_count INT UNSIGNED NOT NULL DEFAULT 0,
                request_payload LONGTEXT NOT NULL,
                next_retry_at DATETIME NULL,
                last_error TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY wc_refund_id (wc_refund_id),
                KEY status_next_retry_at (status, next_retry_at)
            ) {$charset};
        ");

        dbDelta("
            CREATE TABLE {$reviews} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                wp_comment_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                retry_count INT UNSIGNED NOT NULL DEFAULT 0,
                request_payload LONGTEXT NOT NULL,
                next_retry_at DATETIME NULL,
                last_error TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY wp_comment_id (wp_comment_id),
                KEY status_next_retry_at (status, next_retry_at)
            ) {$charset};
        ");

        dbDelta("
            CREATE TABLE {$discounts} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                discount_code VARCHAR(191) NOT NULL,
                customer_email VARCHAR(191) NOT NULL,
                sku VARCHAR(191) NOT NULL,
                meta LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY discount_code (discount_code)
            ) {$charset};
        ");

        dbDelta("
            CREATE TABLE {$redemptions} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                discount_code VARCHAR(191) NOT NULL,
                customer_email VARCHAR(191) NOT NULL,
                sku VARCHAR(191) NOT NULL,
                wc_order_id BIGINT UNSIGNED NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                meta LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY discount_code (discount_code),
                KEY wc_order_id (wc_order_id)
            ) {$charset};
        ");

        self::add_minute_schedule_to_runtime();

        if (!wp_next_scheduled('lew_process_order_outbox')) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'minute', 'lew_process_order_outbox');
        }

        if (!wp_next_scheduled('lew_process_refund_outbox')) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'minute', 'lew_process_refund_outbox');
        }

        if (!wp_next_scheduled('lew_process_review_outbox')) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'minute', 'lew_process_review_outbox');
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('lew_process_order_outbox');
        wp_clear_scheduled_hook('lew_process_refund_outbox');
        wp_clear_scheduled_hook('lew_process_review_outbox');
    }

    public static function add_minute_schedule(array $schedules): array
    {
        $schedules['minute'] = [
            'interval' => MINUTE_IN_SECONDS,
            'display' => 'Every Minute',
        ];

        return $schedules;
    }

    private static function add_minute_schedule_to_runtime(): void
    {
        add_filter('cron_schedules', [self::class, 'add_minute_schedule']);
    }
}
