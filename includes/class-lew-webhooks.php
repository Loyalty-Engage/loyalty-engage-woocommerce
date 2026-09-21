<?php

if (!defined('ABSPATH')) {
    exit;
}

class LEW_Webhooks
{
    public static function boot(): void
    {
        add_action('woocommerce_order_status_changed', [self::class, 'handle_order_status_change'], 20, 4);
        add_action('woocommerce_order_fully_refunded', [self::class, 'handle_refund_by_order'], 20, 2);
        add_action('woocommerce_refund_created', [self::class, 'handle_refund'], 20, 2);
        add_action('user_register', [self::class, 'handle_customer_register']);
        add_action('comment_post', [self::class, 'handle_review_created'], 20, 3);
        add_action('wp_set_comment_status', [self::class, 'handle_review_status_change'], 20, 2);
    }

    public static function handle_order_status_change(int $order_id, string $from_status, string $to_status, WC_Order $order): void
    {
        if (!LEW_Settings::is_module_enabled()) {
            return;
        }

        $settings = LEW_Settings::get_settings();
        if ($settings['order_export_enabled'] !== 'yes') {
            return;
        }

        $configured_statuses = array_values(array_filter(array_map('trim', explode(',', (string) ($settings['purchase_export_statuses'] ?? '')))));
        if ($configured_statuses && !in_array($to_status, $configured_statuses, true)) {
            return;
        }

        $email = $order->get_billing_email();
        if (!$email) {
            LEW_Logger::warning('Skipping order export because billing email is missing', ['order_id' => $order_id]);
            return;
        }

        self::confirm_physical_redemptions($order);
        self::mark_discount_codes_as_redeemed($order);
        self::clear_active_points_coupon_after_order($order);

        $products = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $sku = $product && $product->get_sku() ? $product->get_sku() : 'product-' . $item->get_product_id();
            $products[] = [
                'sku' => $sku,
                'price' => number_format((float) $order->get_item_total($item, false, false), 2, '.', ''),
                'quantity' => (int) $item->get_quantity(),
            ];
        }

        if (!$products) {
            LEW_Logger::warning('Skipping order export because no line items were mapped', ['order_id' => $order_id]);
            return;
        }

        LEW_Outbox::enqueue_order($order_id, [[
            'event' => 'Purchase',
            'identifier' => $email,
            'orderId' => (string) $order_id,
            'orderDate' => gmdate('c', $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time()),
            'currency' => $order->get_currency(),
            'products' => $products,
        ]]);
        LEW_Logger::info('Order queued for Loyalty Engage export', ['order_id' => $order_id]);

        self::store_customer_purchase_tags($order);
    }

    public static function handle_refund_by_order(int $order_id, int $refund_id): void
    {
        self::handle_refund($refund_id, []);
    }

    public static function handle_refund(int $refund_id, $args): void
    {
        if (!LEW_Settings::is_module_enabled()) {
            return;
        }

        $settings = LEW_Settings::get_settings();
        if ($settings['return_export_enabled'] !== 'yes') {
            return;
        }

        $refund = wc_get_order($refund_id);
        if (!$refund instanceof WC_Order_Refund) {
            return;
        }

        $order = wc_get_order($refund->get_parent_id());
        if (!$order instanceof WC_Order) {
            return;
        }

        $email = $order->get_billing_email();
        if (!$email) {
            LEW_Logger::warning('Skipping refund export because billing email is missing', [
                'refund_id' => $refund_id,
                'order_id' => $order->get_id(),
            ]);
            return;
        }

        self::refund_physical_redemptions($order);

        $products = [];
        foreach ($refund->get_items() as $item) {
            $product = $item->get_product();
            $sku = $product && $product->get_sku() ? $product->get_sku() : 'product-' . $item->get_product_id();
            $quantity = abs((int) $item->get_quantity());
            if ($quantity <= 0) {
                continue;
            }

            $products[] = [
                'sku' => $sku,
                'price' => number_format(abs((float) $refund->get_item_total($item, false, false)), 2, '.', ''),
                'quantity' => $quantity,
            ];
        }

        if (!$products) {
            LEW_Logger::warning('Skipping refund export because no refundable products were mapped', [
                'refund_id' => $refund_id,
                'order_id' => $order->get_id(),
            ]);
            return;
        }

        LEW_Outbox::enqueue_refund($refund_id, $order->get_id(), [[
            'event' => 'Return',
            'identifier' => $email,
            'orderDate' => gmdate('c', $refund->get_date_created() ? $refund->get_date_created()->getTimestamp() : time()),
            'products' => $products,
        ]]);
        LEW_Logger::info('Refund queued for Loyalty Engage export', [
            'refund_id' => $refund_id,
            'order_id' => $order->get_id(),
        ]);
    }

    public static function handle_customer_register(int $user_id): void
    {
        if (!LEW_Settings::is_module_enabled()) {
            return;
        }

        $settings = LEW_Settings::get_settings();
        if ($settings['customer_sync_enabled'] !== 'yes') {
            return;
        }

        $user = get_user_by('id', $user_id);
        if (!$user || !$user->user_email) {
            return;
        }

        $client = new LEW_Loyalty_Engage_Client();
        $result = $client->request('/events', 'POST', [[
            'event' => 'Registration',
            'identifier' => $user->user_email,
            'customerId' => (string) $user_id,
            'customerEmail' => $user->user_email,
            'orderId' => 'CUSTOMER-REGISTRATION-' . $user_id,
        ]]);
        if (!empty($result['success'])) {
            LEW_Logger::info('Customer registration synced to Loyalty Engage', ['user_id' => $user_id]);
        }
    }

    public static function handle_review_created(int $comment_id, $comment_approved, array $commentdata): void
    {
        if ((string) $comment_approved !== '1') {
            return;
        }

        self::maybe_enqueue_review_export($comment_id);
    }

    public static function handle_review_status_change(int $comment_id, string $status): void
    {
        if ($status !== 'approve') {
            return;
        }

        self::maybe_enqueue_review_export($comment_id);
    }

    private static function store_customer_purchase_tags(WC_Order $order): void
    {
        $settings = LEW_Settings::get_settings();
        if ($settings['loyalty_shop_personalization_enabled'] !== 'yes') {
            return;
        }

        $user_id = (int) $order->get_user_id();
        if ($user_id <= 0) {
            return;
        }

        $selected_tags = array_filter(array_map('trim', explode(',', (string) $settings['loyalty_shop_personalization_tags'])));
        $selected_tags = array_map('sanitize_title', $selected_tags);
        if (!$selected_tags) {
            return;
        }

        $matched_tags = [];
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $terms = wp_get_post_terms($product_id, 'product_tag', ['fields' => 'slugs']);
            if (is_wp_error($terms)) {
                continue;
            }

            foreach ($terms as $term_slug) {
                if (in_array($term_slug, $selected_tags, true)) {
                    $matched_tags[] = $term_slug;
                }
            }
        }

        if (!$matched_tags) {
            return;
        }

        $existing = get_user_meta($user_id, 'lew_purchase_tags', true);
        $existing = is_array($existing) ? $existing : [];
        $merged = array_values(array_unique(array_merge($existing, $matched_tags)));
        update_user_meta($user_id, 'lew_purchase_tags', $merged);
        LEW_Logger::info('Stored loyalty personalization tags for customer', [
            'user_id' => $user_id,
            'tags' => $merged,
        ]);
    }

    private static function maybe_enqueue_review_export(int $comment_id): void
    {
        if (!LEW_Settings::is_module_enabled()) {
            return;
        }

        $settings = LEW_Settings::get_settings();
        if (($settings['review_export_enabled'] ?? 'no') !== 'yes') {
            return;
        }

        $comment = get_comment($comment_id);
        if (!$comment instanceof WP_Comment) {
            return;
        }

        if ((int) $comment->comment_approved !== 1) {
            return;
        }

        $product_id = (int) $comment->comment_post_ID;
        if (get_post_type($product_id) !== 'product') {
            return;
        }

        $email = $comment->comment_author_email;
        if (!$email) {
            return;
        }

        LEW_Outbox::enqueue_review($comment_id, [[
            'event' => 'Review',
            'identifier' => $email,
            'reviewId' => (string) $comment_id,
            'productId' => (string) $product_id,
            'reviewDate' => gmdate('c', strtotime((string) $comment->comment_date_gmt ?: 'now')),
            'content' => wp_strip_all_tags((string) $comment->comment_content),
            'rating' => (int) get_comment_meta($comment_id, 'rating', true),
        ]]);
        LEW_Logger::info('Review queued for Loyalty Engage export', [
            'comment_id' => $comment_id,
            'product_id' => $product_id,
        ]);
    }

    private static function clear_active_points_coupon_after_order(WC_Order $order): void
    {
        $user_id = (int) $order->get_user_id();
        if ($user_id <= 0) {
            return;
        }

        $active_coupon = (string) get_user_meta($user_id, 'lew_active_points_coupon', true);
        if ($active_coupon === '') {
            return;
        }

        $applied_coupons = array_map('strval', $order->get_coupon_codes());
        if (!in_array($active_coupon, $applied_coupons, true)) {
            return;
        }

        delete_user_meta($user_id, 'lew_active_points_coupon');
        LEW_Logger::info('Cleared active loyalty points coupon after order', [
            'user_id' => $user_id,
            'coupon_code' => $active_coupon,
            'order_id' => $order->get_id(),
        ]);
    }

    private static function confirm_physical_redemptions(WC_Order $order): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'lew_physical_redemptions';
        $email = $order->get_billing_email();
        if (!$email) {
            return;
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE customer_email = %s AND status = 'pending'",
            $email
        ), ARRAY_A);

        if (!$rows) {
            return;
        }

        $loyalty_skus = [];
        $discount_codes = [];
        foreach ($order->get_items() as $item) {
            $sku_meta = $item->get_meta('_lew_loyalty_sku', true);
            $discount_meta = $item->get_meta('_lew_loyalty_discount_code', true);
            if ($sku_meta) {
                $loyalty_skus[] = (string) $sku_meta;
            }
            if ($discount_meta) {
                $discount_codes[] = (string) $discount_meta;
            }
        }

        if (!$loyalty_skus && !$discount_codes) {
            return;
        }

        $client = new LEW_Loyalty_Engage_Client();
        foreach ($rows as $row) {
            if (!in_array((string) $row['sku'], $loyalty_skus, true) && !in_array((string) $row['discount_code'], $discount_codes, true)) {
                continue;
            }

            $result = $client->request('/loyalty/shop/' . rawurlencode($email) . '/cart/purchase', 'POST', [
                'orderId' => (string) $order->get_id(),
                'products' => [[
                    'sku' => (string) $row['sku'],
                    'quantity' => 1,
                ]],
            ]);

            if (!empty($result['success'])) {
                $wpdb->update($table, [
                    'status' => 'purchased',
                    'wc_order_id' => $order->get_id(),
                    'updated_at' => current_time('mysql', true),
                ], ['id' => (int) $row['id']]);
                LEW_Logger::info('Confirmed physical loyalty redemption', [
                    'order_id' => $order->get_id(),
                    'sku' => $row['sku'],
                    'discount_code' => $row['discount_code'],
                ]);
            }
        }
    }

    private static function refund_physical_redemptions(WC_Order $order): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'lew_physical_redemptions';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE wc_order_id = %d AND status = 'purchased'",
            $order->get_id()
        ), ARRAY_A);

        if (!$rows) {
            return;
        }

        $email = $order->get_billing_email();
        if (!$email) {
            return;
        }

        $client = new LEW_Loyalty_Engage_Client();
        foreach ($rows as $row) {
            $result = $client->request('/loyalty/shop/' . rawurlencode($email) . '/cart/remove', 'DELETE', [
                'sku' => (string) $row['sku'],
                'quantity' => 1,
            ]);

            if (!empty($result['success'])) {
                $wpdb->update($table, [
                    'status' => 'refunded',
                    'updated_at' => current_time('mysql', true),
                ], ['id' => (int) $row['id']]);
                LEW_Logger::info('Refunded physical loyalty redemption', [
                    'order_id' => $order->get_id(),
                    'sku' => $row['sku'],
                ]);
            }
        }
    }

    private static function mark_discount_codes_as_redeemed(WC_Order $order): void
    {
        global $wpdb;

        $coupons = $order->get_coupon_codes();
        if (!$coupons) {
            return;
        }

        $table = $wpdb->prefix . 'lew_discount_codes';
        $client = new LEW_Loyalty_Engage_Client();
        foreach ($coupons as $code) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE discount_code = %s",
                $code
            ), ARRAY_A);

            if (!$row) {
                continue;
            }

            $result = $client->request('/discount/' . rawurlencode($code) . '/redeem', 'PUT', [
                'identifier' => (string) $row['customer_email'],
            ]);

            if (!empty($result['success'])) {
                $wpdb->delete($table, ['discount_code' => $code]);
                LEW_Logger::info('Marked Loyalty Engage discount code as redeemed', [
                    'order_id' => $order->get_id(),
                    'discount_code' => $code,
                ]);
            }
        }
    }
}
