<?php

if (!defined('ABSPATH')) {
    exit;
}

class LEW_Storefront
{
    private const PRODUCT_CACHE_KEY = 'lew_products_cache';
    private const PRODUCT_CACHE_TTL = 300;

    public static function boot(): void
    {
        add_shortcode('loyalty_engage_page', [self::class, 'render_loyalty_page']);
        add_action('wp_enqueue_scripts', [self::class, 'register_assets']);
        add_action('woocommerce_before_calculate_totals', [self::class, 'apply_zero_price_to_loyalty_items'], 20);
        add_action('woocommerce_before_calculate_totals', [self::class, 'enforce_loyalty_cart_rules'], 30);
        add_action('woocommerce_checkout_create_order_line_item', [self::class, 'copy_loyalty_item_meta_to_order'], 20, 4);
        add_action('woocommerce_remove_cart_item', [self::class, 'maybe_release_reserved_loyalty_item'], 20, 2);
    }

    public static function register_assets(): void
    {
        wp_register_style('lew-loyalty-page', LEW_PLUGIN_URL . 'assets/css/loyalty-page.css', [], LEW_PLUGIN_VERSION);
        wp_register_script('lew-loyalty-page', LEW_PLUGIN_URL . 'assets/js/loyalty-page.js', [], LEW_PLUGIN_VERSION, true);
    }

    public static function render_loyalty_page(): string
    {
        if (!LEW_Settings::is_module_enabled()) {
            return '<div class="lew-login-prompt"><p>Loyalty Engage is momenteel uitgeschakeld.</p></div>';
        }

        if (!is_user_logged_in()) {
            return '<div class="lew-login-prompt"><p>Log in om je loyalty voordelen te bekijken.</p></div>';
        }

        $user = wp_get_current_user();
        wp_enqueue_style('lew-loyalty-page');
        wp_enqueue_script('lew-loyalty-page');
        wp_add_inline_script('lew-loyalty-page', 'window.lewRestNonce = ' . wp_json_encode(wp_create_nonce('wp_rest')) . ';', 'before');

        return sprintf(
            '<div id="lew-loyalty-page" data-customer-id="%1$d" data-customer-email="%2$s" data-api-base="%3$s" data-coins="%4$s"><div id="lew-meta-block">%5$s</div><div id="lew-points-block">%6$s</div><div class="lew-section"><h2>Rewards</h2><div id="lew-rewards-grid"><p>Producten laden...</p></div></div><div class="lew-section"><h2>Physical rewards</h2><div id="lew-physical-grid"><p>Producten laden...</p></div></div><div id="lew-message" class="lew-message" aria-live="polite"></div></div>',
            (int) $user->ID,
            esc_attr($user->user_email),
            esc_url(rest_url('loyalty-engage/v1')),
            esc_attr((string) get_user_meta($user->ID, 'lew_availableCoins', true)),
            self::render_loyalty_meta_block($user->ID),
            self::render_points_redemption_block($user->ID)
        );
    }

    public static function resolve_customer(string $customer_ref): ?WP_User
    {
        $customer_ref = trim($customer_ref);
        if ($customer_ref === '') {
            return null;
        }

        if (is_email($customer_ref)) {
            $user = get_user_by('email', $customer_ref);
            return $user instanceof WP_User ? $user : null;
        }

        $user = get_user_by('id', (int) $customer_ref);
        return $user instanceof WP_User ? $user : null;
    }

    public static function match_loyalty_product_to_wc_product(array $loyalty_product): ?array
    {
        $sku = trim((string) ($loyalty_product['sku'] ?? ''));
        $title = trim((string) ($loyalty_product['title'] ?? ''));
        $variant_id = self::normalize_numeric_id($loyalty_product['variantId'] ?? $loyalty_product['sku'] ?? '');

        $product_id = $sku !== '' ? wc_get_product_id_by_sku($sku) : 0;
        if (!$product_id && $variant_id) {
            $product_id = $variant_id;
        }
        if (!$product_id && $sku !== '') {
            $product_id = self::find_product_id_by_barcode_or_meta($sku);
        }
        if (!$product_id && $title !== '') {
            $products = wc_get_products([
                'limit' => 1,
                'status' => 'publish',
                'name' => $title,
            ]);
            $product_id = !empty($products[0]) ? $products[0]->get_id() : 0;
        }

        if (!$product_id) {
            return null;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return null;
        }

        return self::format_wc_product_match($product, $sku);
    }

    public static function build_matched_product(array $loyalty_product, ?array $wc_match): array
    {
        $loyalty = [
            'title' => $loyalty_product['title'] ?? '',
            'coinPrice' => $loyalty_product['coinPrice'] ?? 0,
            'description' => $loyalty_product['shortDescription'] ?? '',
            'sku' => $loyalty_product['sku'] ?? '',
            'requiredTier' => $loyalty_product['requiredLoyaltyTierName'] ?? '',
            'requiredPoints' => $loyalty_product['requiredLoyaltyTierPoints'] ?? 0,
            'imageUrl' => $loyalty_product['imageUrl'] ?? '',
            'product_type' => $loyalty_product['type'] ?? '',
            'discountPercentage' => $loyalty_product['discountPercentage'] ?? null,
            'discountAmount' => $loyalty_product['discountAmount'] ?? null,
        ];

        return [
            'loyalty' => $loyalty,
            'shopify' => $wc_match ?: [
                'id' => null,
                'title' => $loyalty['title'],
                'variantId' => null,
                'sku' => $loyalty['sku'],
                'barcode' => '',
                'image' => $loyalty['imageUrl'],
                'tags' => [],
                'variants' => [],
                'hasMultipleVariants' => false,
            ],
            'personalization' => [
                'matchedTags' => [],
                'matchCount' => 0,
                'isMatch' => false,
            ],
        ];
    }

    public static function personalize_products(array $products, string $customer_ref): array
    {
        $settings = LEW_Settings::get_settings();
        if ($settings['loyalty_shop_personalization_enabled'] !== 'yes') {
            return $products;
        }

        $selected_tags = array_values(array_filter(array_map('sanitize_title', array_map('trim', explode(',', (string) $settings['loyalty_shop_personalization_tags'])))));
        if (!$selected_tags) {
            return $products;
        }

        $customer = self::resolve_customer($customer_ref);
        if (!$customer) {
            return $products;
        }

        $customer_tags = get_user_meta($customer->ID, 'lew_purchase_tags', true);
        $customer_tags = is_array($customer_tags) ? array_values(array_unique(array_map('sanitize_title', $customer_tags))) : [];
        if (!$customer_tags) {
            return $products;
        }

        $ranked = [];
        foreach ($products as $index => $product) {
            $product_tags = isset($product['shopify']['tags']) && is_array($product['shopify']['tags']) ? $product['shopify']['tags'] : [];
            $product_tags = array_values(array_intersect(array_map('sanitize_title', $product_tags), $selected_tags));
            $matched_tags = array_values(array_intersect($product_tags, $customer_tags));

            $product['personalization'] = [
                'matchedTags' => $matched_tags,
                'matchCount' => count($matched_tags),
                'isMatch' => count($matched_tags) > 0,
            ];

            $ranked[] = [
                'index' => $index,
                'product' => $product,
            ];
        }

        $has_match = false;
        foreach ($ranked as $entry) {
            if (!empty($entry['product']['personalization']['isMatch'])) {
                $has_match = true;
                break;
            }
        }

        if (!$has_match) {
            return $products;
        }

        usort($ranked, static function (array $left, array $right): int {
            $diff = $right['product']['personalization']['matchCount'] - $left['product']['personalization']['matchCount'];
            if ($diff !== 0) {
                return $diff;
            }

            return $left['index'] - $right['index'];
        });

        if ($settings['loyalty_shop_display_mode'] === 'only_matching') {
            $ranked = array_values(array_filter($ranked, static function (array $entry): bool {
                return !empty($entry['product']['personalization']['isMatch']);
            }));
        }

        return array_values(array_map(static function (array $entry): array {
            return $entry['product'];
        }, $ranked));
    }

    public static function get_cached_available_products(): array
    {
        $cached = get_transient(self::PRODUCT_CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $client = new LEW_Loyalty_Engage_Client();
        $response = $client->request('/loyalty/shop/available_products', 'GET');
        $body = is_array($response['body']) ? $response['body'] : [];
        $products = isset($body['availableProducts']) && is_array($body['availableProducts']) ? $body['availableProducts'] : [];

        set_transient(self::PRODUCT_CACHE_KEY, $products, self::PRODUCT_CACHE_TTL);
        return $products;
    }

    public static function find_reward_by_sku(string $sku): ?array
    {
        foreach (self::get_cached_available_products() as $reward) {
            if (!is_array($reward)) {
                continue;
            }

            if (strcasecmp((string) ($reward['sku'] ?? ''), $sku) === 0) {
                return $reward;
            }
        }

        return null;
    }

    public static function apply_zero_price_to_loyalty_items(WC_Cart $cart): void
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        foreach ($cart->get_cart() as $cart_item) {
            if (empty($cart_item['lew_loyalty_reward']) || empty($cart_item['data']) || !is_object($cart_item['data'])) {
                continue;
            }

            $cart_item['data']->set_price(0);
        }
    }

    public static function enforce_loyalty_cart_rules(WC_Cart $cart): void
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        if (!LEW_Settings::is_module_enabled()) {
            return;
        }

        $minimum_order_value = self::get_minimum_order_value();
        if ($minimum_order_value <= 0) {
            return;
        }

        $subtotal = self::get_non_loyalty_cart_subtotal($cart);
        if ($subtotal >= $minimum_order_value) {
            return;
        }

        $removed = false;
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (empty($cart_item['lew_loyalty_reward'])) {
                continue;
            }

            $cart->remove_cart_item($cart_item_key);
            $removed = true;
        }

        if ($removed && function_exists('wc_add_notice')) {
            wc_add_notice(self::format_minimum_value_message('loyalty_product_removed_message', $minimum_order_value, $subtotal), 'notice');
        }
    }

    public static function copy_loyalty_item_meta_to_order(WC_Order_Item_Product $item, string $cart_item_key, array $values, WC_Order $order): void
    {
        if (empty($values['lew_loyalty_reward'])) {
            return;
        }

        $item->add_meta_data('_lew_loyalty_reward', 'yes', true);
        $item->add_meta_data('_lew_loyalty_sku', (string) ($values['lew_loyalty_sku'] ?? ''), true);
        $item->add_meta_data('_lew_loyalty_discount_code', (string) ($values['lew_loyalty_discount_code'] ?? ''), true);
    }

    public static function maybe_release_reserved_loyalty_item(string $cart_item_key, WC_Cart $cart): void
    {
        $removed = method_exists($cart, 'get_removed_cart_contents') ? $cart->get_removed_cart_contents() : [];
        if (empty($removed[$cart_item_key])) {
            return;
        }

        $item = $removed[$cart_item_key];
        if (empty($item['lew_loyalty_reward']) || empty($item['lew_loyalty_sku'])) {
            return;
        }

        $user = wp_get_current_user();
        if (!$user || !$user->user_email) {
            return;
        }

        $client = new LEW_Loyalty_Engage_Client();
        $client->request('/loyalty/shop/' . rawurlencode($user->user_email) . '/cart/remove', 'DELETE', [
            'sku' => (string) $item['lew_loyalty_sku'],
            'quantity' => 1,
        ]);
    }

    public static function can_add_loyalty_product(WC_Cart $cart, ?string &$message = null): bool
    {
        if (!LEW_Settings::is_module_enabled()) {
            $message = 'Loyalty Engage is momenteel uitgeschakeld.';
            return false;
        }

        $settings = LEW_Settings::get_settings();
        $max_products = absint((string) ($settings['max_loyalty_products_per_cart'] ?? '0'));
        if ($max_products > 0 && self::count_loyalty_items_in_cart($cart) >= $max_products) {
            $message = sprintf('Je kunt maximaal %d loyalty product(en) tegelijk in je winkelmand hebben.', $max_products);
            return false;
        }

        $minimum_order_value = self::get_minimum_order_value();
        if ($minimum_order_value > 0) {
            $subtotal = self::get_non_loyalty_cart_subtotal($cart);
            if ($subtotal < $minimum_order_value) {
                $message = self::format_minimum_value_message('minimum_order_value_message', $minimum_order_value, $subtotal);
                return false;
            }
        }

        return true;
    }

    public static function get_loyalty_meta_rows(int $user_id): array
    {
        $settings = LEW_Settings::get_settings();
        if (($settings['expose_customer_meta'] ?? 'yes') !== 'yes') {
            return [];
        }

        $fields = [
            'current_tier' => ['meta' => 'lew_current_tier', 'enabled' => 'le_current_tier_enabled', 'label' => 'le_current_tier_label'],
            'points' => ['meta' => 'lew_points', 'enabled' => 'le_points_enabled', 'label' => 'le_points_label'],
            'available_coins' => ['meta' => 'lew_availableCoins', 'enabled' => 'le_available_coins_enabled', 'label' => 'le_available_coins_label'],
            'next_tier' => ['meta' => 'lew_next_tier', 'enabled' => 'le_next_tier_enabled', 'label' => 'le_next_tier_label'],
            'points_to_next_tier' => ['meta' => 'lew_points_to_next_tier', 'enabled' => 'le_points_to_next_tier_enabled', 'label' => 'le_points_to_next_tier_label'],
            'reserved_coins' => ['meta' => 'lew_reserved_coins', 'enabled' => 'le_reserved_coins_enabled', 'label' => 'le_reserved_coins_label'],
            'expiring_points_30d' => ['meta' => 'lew_expiring_points_30d', 'enabled' => 'le_expiring_points_30d_enabled', 'label' => 'le_expiring_points_30d_label'],
        ];

        $rows = [];
        foreach ($fields as $field) {
            if (($settings[$field['enabled']] ?? 'no') !== 'yes') {
                continue;
            }

            $value = self::get_customer_loyalty_value($user_id, $field['meta']);
            if ($value === '' || $value === null) {
                continue;
            }

            $rows[] = [
                'label' => (string) ($settings[$field['label']] ?? ''),
                'value' => is_scalar($value) ? (string) $value : wp_json_encode($value),
            ];
        }

        return $rows;
    }

    public static function get_customer_loyalty_value(int $user_id, string $canonical_key)
    {
        $aliases = [
            'lew_current_tier' => ['lew_current_tier', 'lew_le_current_tier', 'lew_currenttier', 'lew_le_currenttier'],
            'lew_points' => ['lew_points', 'lew_le_points'],
            'lew_availableCoins' => ['lew_availableCoins', 'lew_available_coins', 'lew_le_available_coins', 'lew_le_availablecoins'],
            'lew_next_tier' => ['lew_next_tier', 'lew_le_next_tier', 'lew_nexttier', 'lew_le_nexttier'],
            'lew_points_to_next_tier' => ['lew_points_to_next_tier', 'lew_le_points_to_next_tier', 'lew_pointstonexttier', 'lew_le_pointstonexttier'],
            'lew_reserved_coins' => ['lew_reserved_coins', 'lew_le_reserved_coins', 'lew_reservedcoins', 'lew_le_reservedcoins'],
            'lew_expiring_points_30d' => ['lew_expiring_points_30d', 'lew_le_expiring_points_30d', 'lew_expiringpoints30d', 'lew_le_expiringpoints30d'],
        ];

        $keys = $aliases[$canonical_key] ?? [$canonical_key];
        foreach ($keys as $key) {
            $value = get_user_meta($user_id, $key, true);
            if ($value !== '' && $value !== null) {
                return $value;
            }
        }

        return '';
    }

    private static function format_wc_product_match(WC_Product $product, string $fallback_sku = ''): array
    {
        $base_product_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
        $base_product = $product->is_type('variation') ? wc_get_product($base_product_id) : $product;
        $image_id = $product->get_image_id() ?: ($base_product ? $base_product->get_image_id() : 0);
        $tags = wp_get_post_terms($base_product_id ?: $product->get_id(), 'product_tag', ['fields' => 'slugs']);
        $variants = [];
        $variant_id = $product->get_id();
        $has_multiple = false;

        if ($base_product && $base_product->is_type('variable')) {
            $has_multiple = true;
            foreach ($base_product->get_children() as $child_id) {
                $child = wc_get_product($child_id);
                if (!$child instanceof WC_Product_Variation) {
                    continue;
                }

                $variants[] = [
                    'id' => $child->get_id(),
                    'title' => wc_get_formatted_variation($child, true, false, true) ?: $child->get_name(),
                    'sku' => $child->get_sku(),
                    'barcode' => (string) $child->get_meta('_barcode'),
                    'price' => $child->get_price(),
                    'available' => $child->is_in_stock() && $child->is_purchasable(),
                    'options' => array_values($child->get_attributes()),
                ];
            }

            if ($product instanceof WC_Product_Variation) {
                $variant_id = $product->get_id();
            } elseif ($variants) {
                $variant_id = (int) $variants[0]['id'];
            }
        } else {
            $variants[] = [
                'id' => $product->get_id(),
                'title' => $product->get_name(),
                'sku' => $product->get_sku() ?: $fallback_sku,
                'barcode' => (string) $product->get_meta('_barcode'),
                'price' => $product->get_price(),
                'available' => $product->is_in_stock() && $product->is_purchasable(),
                'options' => [],
            ];
        }

        return [
            'id' => $base_product_id ?: $product->get_id(),
            'title' => $base_product ? $base_product->get_name() : $product->get_name(),
            'variantId' => $variant_id,
            'sku' => $product->get_sku() ?: $fallback_sku,
            'barcode' => (string) $product->get_meta('_barcode'),
            'image' => $image_id ? wp_get_attachment_image_url($image_id, 'medium') : '',
            'tags' => is_wp_error($tags) ? [] : $tags,
            'variants' => $variants,
            'hasMultipleVariants' => $has_multiple,
            'url' => $base_product ? get_permalink($base_product->get_id()) : get_permalink($product->get_id()),
        ];
    }

    private static function find_product_id_by_barcode_or_meta(string $value): int
    {
        global $wpdb;

        $meta_keys = ['_barcode', 'barcode', '_sku'];
        foreach ($meta_keys as $meta_key) {
            $product_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                $meta_key,
                $value
            ));

            if ($product_id > 0) {
                return $product_id;
            }
        }

        return 0;
    }

    private static function normalize_numeric_id($value): int
    {
        $text = trim((string) $value);
        if ($text === '') {
            return 0;
        }

        if (preg_match('/(\d+)$/', $text, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    private static function count_loyalty_items_in_cart(WC_Cart $cart): int
    {
        $count = 0;
        foreach ($cart->get_cart() as $cart_item) {
            if (empty($cart_item['lew_loyalty_reward'])) {
                continue;
            }

            $count += max(1, (int) ($cart_item['quantity'] ?? 1));
        }

        return $count;
    }

    private static function get_non_loyalty_cart_subtotal(WC_Cart $cart): float
    {
        $subtotal = 0.0;
        foreach ($cart->get_cart() as $cart_item) {
            if (empty($cart_item['data']) || !is_object($cart_item['data']) || !method_exists($cart_item['data'], 'get_price')) {
                continue;
            }

            if (!empty($cart_item['lew_loyalty_reward'])) {
                continue;
            }

            $quantity = max(1, (int) ($cart_item['quantity'] ?? 1));
            $subtotal += (float) $cart_item['data']->get_price() * $quantity;
        }

        return round($subtotal, 2);
    }

    private static function get_minimum_order_value(): float
    {
        $settings = LEW_Settings::get_settings();
        return max(0.0, (float) ($settings['minimum_order_value'] ?? 0));
    }

    private static function format_minimum_value_message(string $setting_key, float $minimum, float $current): string
    {
        $settings = LEW_Settings::get_settings();
        $template = trim((string) ($settings[$setting_key] ?? ''));
        if ($template === '') {
            $template = 'Je winkelmand voldoet niet aan de loyalty voorwaarden.';
        }

        $replacements = [
            '{{minimum}}' => wc_format_decimal($minimum, 2),
            '{{current}}' => wc_format_decimal($current, 2),
        ];

        return strtr($template, $replacements);
    }

    private static function render_loyalty_meta_block(int $user_id): string
    {
        $settings = LEW_Settings::get_settings();
        $rows = self::get_loyalty_meta_rows($user_id);
        if (($settings['expose_customer_meta'] ?? 'yes') !== 'yes') {
            return '';
        }

        $html = '<div class="lew-section lew-meta"><h2>' . esc_html((string) ($settings['account_block_title'] ?? 'Loyalty overzicht')) . '</h2>';
        if (!$rows) {
            $html .= '<p>Er is nog geen loyalty data beschikbaar.</p></div>';
            return $html;
        }

        $html .= '<dl class="lew-meta__list">';
        foreach ($rows as $row) {
            $html .= '<div class="lew-meta__row"><dt>' . esc_html($row['label']) . '</dt><dd>' . esc_html($row['value']) . '</dd></div>';
        }
        $html .= '</dl></div>';

        return $html;
    }

    private static function render_points_redemption_block(int $user_id): string
    {
        $settings = LEW_Settings::get_settings();
        if (($settings['points_redemption_enabled'] ?? 'no') !== 'yes') {
            return '';
        }

        $available_points = (int) self::get_customer_loyalty_value($user_id, 'lew_points');
        $min_points = max(1, (int) ($settings['min_points_to_redeem'] ?? 1));

        return '<div class="lew-section lew-points">' .
            '<h2>Punten inwisselen</h2>' .
            '<p>Beschikbare punten: <strong>' . esc_html((string) $available_points) . '</strong></p>' .
            '<label class="lew-points__label" for="lew-points-input">Aantal punten</label>' .
            '<input id="lew-points-input" class="lew-points__input" type="number" min="' . esc_attr((string) $min_points) . '" step="1" value="' . esc_attr((string) $min_points) . '">' .
            '<p class="lew-points__preview">Verwachte korting: <strong id="lew-points-preview">-</strong></p>' .
            '<div class="lew-points__actions">' .
            '<button type="button" id="lew-points-redeem-button">Punten inwisselen</button>' .
            '<button type="button" id="lew-points-remove-button" class="lew-button-secondary">Puntenkorting verwijderen</button>' .
            '</div>' .
            '</div>';
    }
}
