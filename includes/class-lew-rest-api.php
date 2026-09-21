<?php

if (!defined('ABSPATH')) {
    exit;
}

class LEW_Rest_API
{
    public static function boot(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void
    {
        register_rest_route('loyalty-engage/v1', '/products', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_products'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('loyalty-engage/v1', '/loyalty-status', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_loyalty_status'],
            'permission_callback' => [self::class, 'can_access_customer_query'],
        ]);

        register_rest_route('loyalty-engage/v1', '/discount/(?P<sku>[^/]+)/(?P<customer_ref>[^/]+)', [
            'methods' => 'POST',
            'callback' => [self::class, 'redeem_discount'],
            'permission_callback' => [self::class, 'can_access_customer_route'],
        ]);

        register_rest_route('loyalty-engage/v1', '/physical-redeem/(?P<sku>[^/]+)/(?P<customer_ref>[^/]+)', [
            'methods' => 'POST',
            'callback' => [self::class, 'redeem_physical'],
            'permission_callback' => [self::class, 'can_access_customer_route'],
        ]);

        register_rest_route('loyalty-engage/v1', '/cart-remove/(?P<sku>[^/]+)/(?P<customer_ref>[^/]+)', [
            'methods' => 'POST',
            'callback' => [self::class, 'cart_remove'],
            'permission_callback' => [self::class, 'can_access_customer_route'],
        ]);

        register_rest_route('loyalty-engage/v1', '/customer-update', [
            'methods' => 'POST',
            'callback' => [self::class, 'customer_update'],
            'permission_callback' => [self::class, 'can_process_customer_update'],
        ]);

        register_rest_route('loyalty-engage/v1', '/redeem-points/(?P<customer_ref>[^/]+)', [
            'methods' => 'POST',
            'callback' => [self::class, 'redeem_points'],
            'permission_callback' => [self::class, 'can_access_customer_route'],
        ]);

        register_rest_route('loyalty-engage/v1', '/redeem-points/(?P<customer_ref>[^/]+)', [
            'methods' => 'DELETE',
            'callback' => [self::class, 'remove_redeemed_points_discount'],
            'permission_callback' => [self::class, 'can_access_customer_route'],
        ]);

        register_rest_route('loyalty-engage/v1', '/redeem-points/(?P<customer_ref>[^/]+)/info', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_redeem_points_info'],
            'permission_callback' => [self::class, 'can_access_customer_route'],
        ]);

        register_rest_route('loyalty-engage/v1', '/redeem-points/(?P<customer_ref>[^/]+)/preview', [
            'methods' => 'POST',
            'callback' => [self::class, 'preview_redeem_points'],
            'permission_callback' => [self::class, 'can_access_customer_route'],
        ]);
    }

    public static function get_products(WP_REST_Request $request): WP_REST_Response
    {
        if (!LEW_Settings::is_module_enabled()) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Loyalty Engage module is disabled.',
            ], 503);
        }

        $products = LEW_Storefront::get_cached_available_products();
        $customer_ref = (string) ($request->get_param('customerId') ?: $request->get_param('customerEmail') ?: '');

        $matched = array_values(array_filter(array_map(
            static function (array $product) {
                $match = LEW_Storefront::match_loyalty_product_to_wc_product($product);
                if (!$match && ($product['type'] ?? '') !== 'discount_code') {
                    return null;
                }

                return LEW_Storefront::build_matched_product($product, $match);
            },
            $products
        )));

        $matched = LEW_Storefront::personalize_products($matched, $customer_ref);

        return new WP_REST_Response([
            'success' => true,
            'matchedProducts' => $matched,
        ]);
    }

    public static function get_loyalty_status(WP_REST_Request $request): WP_REST_Response
    {
        if (!LEW_Settings::is_module_enabled()) {
            return new WP_REST_Response(['success' => false, 'message' => 'Loyalty Engage module is disabled.'], 503);
        }

        $customer_ref = (string) $request->get_param('customer_ref');
        $customer = LEW_Storefront::resolve_customer($customer_ref);
        if (!$customer) {
            return new WP_REST_Response(['success' => false, 'message' => 'Customer not found'], 404);
        }

        $email = $customer->user_email;
        $client = new LEW_Loyalty_Engage_Client();
        $response = $client->request('/contact/' . rawurlencode($email) . '/loyalty_status', 'GET');

        return new WP_REST_Response([
            'success' => !empty($response['success']),
            'user_data' => is_array($response['body']) ? ($response['body']['user_data'] ?? []) : [],
            'message' => $response['message'],
        ], (int) $response['status']);
    }

    public static function redeem_discount(WP_REST_Request $request): WP_REST_Response
    {
        if (!LEW_Settings::is_module_enabled()) {
            return new WP_REST_Response(['success' => false, 'message' => 'Loyalty Engage module is disabled.'], 503);
        }

        $sku = (string) $request['sku'];
        $customer = LEW_Storefront::resolve_customer((string) $request['customer_ref']);
        if (!$customer) {
            return new WP_REST_Response(['success' => false, 'message' => 'Customer not found'], 404);
        }

        $reward = LEW_Storefront::find_reward_by_sku($sku);
        if (!$reward || ($reward['type'] ?? '') !== 'discount_code') {
            return new WP_REST_Response(['success' => false, 'message' => 'Reward not found'], 404);
        }

        $payload = $request->get_json_params();
        $client = new LEW_Loyalty_Engage_Client();
        $response = $client->request(
            '/loyalty/shop/' . rawurlencode($customer->user_email) . '/cart/buy_discount_code',
            'POST',
            [
                'sku' => $sku,
                'cartSubtotal' => isset($payload['cartSubtotal']) ? (float) $payload['cartSubtotal'] : 0,
            ]
        );

        self::maybe_store_discount_code($sku, $customer->user_email, $response);
        $coupon_result = self::maybe_create_coupon($sku, $customer->user_email, $reward, $response);

        $normalized = self::normalize_api_response($response);
        $normalized['couponApplied'] = $coupon_result['applied'];
        $normalized['couponPending'] = $coupon_result['pending'];

        return new WP_REST_Response($normalized, (int) $response['status']);
    }

    public static function redeem_physical(WP_REST_Request $request): WP_REST_Response
    {
        if (!LEW_Settings::is_module_enabled()) {
            return new WP_REST_Response(['success' => false, 'message' => 'Loyalty Engage module is disabled.'], 503);
        }

        $sku = (string) $request['sku'];
        $customer = LEW_Storefront::resolve_customer((string) $request['customer_ref']);
        if (!$customer) {
            return new WP_REST_Response(['success' => false, 'message' => 'Customer not found'], 404);
        }

        $cart = WC()->cart;
        if (!$cart instanceof WC_Cart) {
            return new WP_REST_Response(['success' => false, 'message' => 'Winkelmand niet beschikbaar.'], 500);
        }

        $rule_message = null;
        if (!LEW_Storefront::can_add_loyalty_product($cart, $rule_message)) {
            return new WP_REST_Response(['success' => false, 'message' => $rule_message ?: 'Loyalty product kan niet worden toegevoegd.'], 400);
        }

        $payload = $request->get_json_params();
        $reward = LEW_Storefront::find_reward_by_sku($sku);
        if (!$reward || ($reward['type'] ?? '') !== 'physical') {
            return new WP_REST_Response(['success' => false, 'message' => 'Reward not found'], 404);
        }

        $variant_id = isset($payload['variantId']) ? absint((string) $payload['variantId']) : 0;
        if (!$variant_id) {
            $match = LEW_Storefront::match_loyalty_product_to_wc_product($reward);
            $variant_id = isset($match['variantId']) ? absint((string) $match['variantId']) : 0;
        }
        if (!$variant_id) {
            return new WP_REST_Response(['success' => false, 'message' => 'Variant not found'], 404);
        }

        $client = new LEW_Loyalty_Engage_Client();
        $response = $client->request(
            '/loyalty/shop/' . rawurlencode($customer->user_email) . '/cart/add',
            'POST',
            [
                'sku' => $sku,
                'variantId' => $variant_id,
                'cartSubtotal' => isset($payload['cartSubtotal']) ? (float) $payload['cartSubtotal'] : 0,
                'cartSkus' => isset($payload['cartSkus']) && is_array($payload['cartSkus']) ? $payload['cartSkus'] : [],
            ]
        );

        self::maybe_store_redemption($sku, $customer->user_email, $response);
        if (empty($response['success'])) {
            return new WP_REST_Response(self::normalize_api_response($response), (int) $response['status']);
        }

        $cart_result = self::add_reward_product_to_cart($variant_id, $sku, $response);
        if (is_wp_error($cart_result)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $cart_result->get_error_message(),
            ], 500);
        }

        $normalized = self::normalize_api_response($response);
        $normalized['cartItemKey'] = $cart_result['cart_item_key'];
        $normalized['cartUrl'] = wc_get_cart_url();
        $normalized['checkoutUrl'] = wc_get_checkout_url();

        return new WP_REST_Response($normalized, 200);
    }

    public static function cart_remove(WP_REST_Request $request): WP_REST_Response
    {
        if (!LEW_Settings::is_module_enabled()) {
            return new WP_REST_Response(['success' => false, 'message' => 'Loyalty Engage module is disabled.'], 503);
        }

        $sku = (string) $request['sku'];
        $customer = LEW_Storefront::resolve_customer((string) $request['customer_ref']);
        if (!$customer) {
            return new WP_REST_Response(['success' => false, 'message' => 'Customer not found'], 404);
        }

        $client = new LEW_Loyalty_Engage_Client();
        $response = $client->request(
            '/loyalty/shop/' . rawurlencode($customer->user_email) . '/cart/remove',
            'DELETE',
            ['sku' => $sku, 'quantity' => 1]
        );

        return new WP_REST_Response(self::normalize_api_response($response), (int) $response['status']);
    }

    public static function customer_update(WP_REST_Request $request): WP_REST_Response
    {
        if (!LEW_Settings::is_module_enabled()) {
            return new WP_REST_Response(['success' => false, 'message' => 'Loyalty Engage module is disabled.'], 503);
        }

        $payload = $request->get_json_params();
        $customer_ref = (string) ($payload['customerId'] ?? $payload['email'] ?? '');
        $customer = LEW_Storefront::resolve_customer($customer_ref);
        if (!$customer) {
            return new WP_REST_Response(['success' => false, 'message' => 'Customer not found'], 404);
        }

        if (!empty($payload['loyaltyData']) && is_array($payload['loyaltyData'])) {
            foreach ($payload['loyaltyData'] as $key => $value) {
                foreach (self::expand_loyalty_meta_keys((string) $key) as $meta_key) {
                    update_user_meta($customer->ID, $meta_key, $value);
                }
            }
        }

        return new WP_REST_Response(['success' => true, 'customerId' => $customer->ID]);
    }

    public static function redeem_points(WP_REST_Request $request): WP_REST_Response
    {
        $guard = self::points_redemption_guard();
        if ($guard instanceof WP_REST_Response) {
            return $guard;
        }

        $customer = LEW_Storefront::resolve_customer((string) $request['customer_ref']);
        if (!$customer) {
            return new WP_REST_Response(['success' => false, 'message' => 'Customer not found'], 404);
        }

        $validation = self::validate_points_redemption_request($request, $customer->ID);
        if ($validation instanceof WP_REST_Response) {
            return $validation;
        }

        $payload = $request->get_json_params();
        $points = (int) ($payload['points'] ?? 0);

        $client = new LEW_Loyalty_Engage_Client();
        $response = $client->request('/loyalty/shop/' . rawurlencode($customer->user_email) . '/redeem-points', 'POST', [
            'points' => $points,
            'cartSubtotal' => self::get_cart_subtotal(),
        ]);

        if (empty($response['success'])) {
            return new WP_REST_Response(self::normalize_api_response($response), (int) $response['status']);
        }

        $body = is_array($response['body']) ? $response['body'] : [];
        $discount_code = (string) ($body['discountCode'] ?? $body['discount_code'] ?? '');
        if ($discount_code === '') {
            return new WP_REST_Response(['success' => false, 'message' => 'Geen kortingscode ontvangen van Loyalty Engage.'], 500);
        }

        self::maybe_create_coupon('POINTS-REDEMPTION', $customer->user_email, [], $response);
        $coupon_result = self::apply_coupon_to_cart($discount_code);
        if ($coupon_result instanceof WP_Error) {
            return new WP_REST_Response(['success' => false, 'message' => $coupon_result->get_error_message()], 400);
        }

        self::store_active_points_coupon($customer->ID, $discount_code);

        $normalized = self::normalize_api_response($response);
        $normalized['discountCode'] = $discount_code;
        $normalized['pointsRedeemed'] = $points;

        return new WP_REST_Response($normalized, 200);
    }

    public static function remove_redeemed_points_discount(WP_REST_Request $request): WP_REST_Response
    {
        $guard = self::points_redemption_guard();
        if ($guard instanceof WP_REST_Response) {
            return $guard;
        }

        $customer = LEW_Storefront::resolve_customer((string) $request['customer_ref']);
        if (!$customer) {
            return new WP_REST_Response(['success' => false, 'message' => 'Customer not found'], 404);
        }

        $client = new LEW_Loyalty_Engage_Client();
        $response = $client->request('/loyalty/shop/' . rawurlencode($customer->user_email) . '/redeem-points', 'DELETE');

        $coupon_code = self::get_active_points_coupon($customer->ID);
        if ($coupon_code !== '' && function_exists('WC') && WC()->cart) {
            WC()->cart->remove_coupon($coupon_code);
        }
        delete_user_meta($customer->ID, 'lew_active_points_coupon');

        return new WP_REST_Response([
            'success' => !empty($response['success']),
            'message' => !empty($response['message']) ? $response['message'] : 'Puntenkorting verwijderd.',
        ], !empty($response['success']) ? 200 : (int) $response['status']);
    }

    public static function get_redeem_points_info(WP_REST_Request $request): WP_REST_Response
    {
        $guard = self::points_redemption_guard();
        if ($guard instanceof WP_REST_Response) {
            return $guard;
        }

        $customer = LEW_Storefront::resolve_customer((string) $request['customer_ref']);
        if (!$customer) {
            return new WP_REST_Response(['success' => false, 'message' => 'Customer not found'], 404);
        }

        $settings = LEW_Settings::get_settings();
        return new WP_REST_Response([
            'enabled' => true,
            'availablePoints' => (int) LEW_Storefront::get_customer_loyalty_value($customer->ID, 'lew_points'),
            'pointsPerCurrencyUnit' => (int) ($settings['points_per_currency_unit'] ?? 1),
            'minPointsToRedeem' => (int) ($settings['min_points_to_redeem'] ?? 1),
            'maxPointsPerOrder' => (int) ($settings['max_points_per_order'] ?? 0),
            'maxDiscountPercentage' => (int) ($settings['max_discount_percentage'] ?? 0),
            'activeCouponCode' => self::get_active_points_coupon($customer->ID),
        ]);
    }

    public static function preview_redeem_points(WP_REST_Request $request): WP_REST_Response
    {
        $guard = self::points_redemption_guard();
        if ($guard instanceof WP_REST_Response) {
            return $guard;
        }

        $customer = LEW_Storefront::resolve_customer((string) $request['customer_ref']);
        if (!$customer) {
            return new WP_REST_Response(['success' => false, 'message' => 'Customer not found'], 404);
        }

        $validation = self::validate_points_redemption_request($request, $customer->ID);
        if ($validation instanceof WP_REST_Response) {
            return $validation;
        }

        $payload = $request->get_json_params();
        $points = (int) ($payload['points'] ?? 0);
        $settings = LEW_Settings::get_settings();
        $discount = self::calculate_points_discount_amount($points, self::get_cart_subtotal(), $settings);

        return new WP_REST_Response([
            'success' => true,
            'canRedeem' => true,
            'points' => $points,
            'discountAmount' => $discount,
        ]);
    }

    public static function can_access_customer_route(WP_REST_Request $request)
    {
        if (!is_user_logged_in()) {
            return new WP_Error('lew_unauthorized', 'Authentication required.', ['status' => 401]);
        }

        $customer = LEW_Storefront::resolve_customer((string) $request['customer_ref']);
        if (!$customer) {
            return new WP_Error('lew_customer_not_found', 'Customer not found.', ['status' => 404]);
        }

        $current_user_id = get_current_user_id();
        if ($current_user_id !== (int) $customer->ID && !current_user_can('manage_woocommerce')) {
            return new WP_Error('lew_forbidden', 'You cannot access this customer resource.', ['status' => 403]);
        }

        return true;
    }

    public static function can_access_customer_query(WP_REST_Request $request)
    {
        $request->set_param('customer_ref', (string) ($request->get_param('customer_ref') ?: $request->get_param('customerId') ?: $request->get_param('customerEmail') ?: ''));
        return self::can_access_customer_route($request);
    }

    public static function can_process_customer_update(WP_REST_Request $request)
    {
        $settings = LEW_Settings::get_settings();
        $secret = trim((string) ($settings['webhook_secret'] ?? ''));
        if ($secret === '') {
            return new WP_Error('lew_webhook_secret_missing', 'Webhook secret not configured.', ['status' => 503]);
        }

        $provided = trim((string) $request->get_header('x-lew-signature'));
        if ($provided === '') {
            $provided = trim((string) $request->get_header('authorization'));
        }

        if (!hash_equals($secret, preg_replace('/^Bearer\s+/i', '', $provided))) {
            return new WP_Error('lew_invalid_signature', 'Invalid webhook signature.', ['status' => 401]);
        }

        return true;
    }

    private static function normalize_api_response(array $response): array
    {
        $body = is_array($response['body']) ? $response['body'] : [];
        return array_merge([
            'success' => !empty($response['success']),
            'message' => (string) ($response['message'] ?? ''),
        ], $body);
    }

    private static function maybe_store_discount_code(string $sku, string $email, array $response): void
    {
        global $wpdb;

        $body = is_array($response['body']) ? $response['body'] : [];
        $discount_code = (string) ($body['discountCode'] ?? $body['discount_code'] ?? '');
        if ($discount_code === '') {
            return;
        }

        $table = $wpdb->prefix . 'lew_discount_codes';
        $now = current_time('mysql', true);
        $wpdb->replace($table, [
            'discount_code' => $discount_code,
            'customer_email' => $email,
            'sku' => $sku,
            'meta' => wp_json_encode($body),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private static function maybe_create_coupon(string $sku, string $email, array $reward, array $response): array
    {
        $result = ['applied' => false, 'pending' => false];
        $body = is_array($response['body']) ? $response['body'] : [];
        $discount_code = (string) ($body['discountCode'] ?? $body['discount_code'] ?? '');
        if ($discount_code === '') {
            return $result;
        }

        $coupon = new WC_Coupon();
        $existing_id = wc_get_coupon_id_by_code($discount_code);
        if ($existing_id) {
            $coupon = new WC_Coupon($existing_id);
        }

        $discount_amount = isset($body['discountAmount']) ? (float) $body['discountAmount'] : 0.0;
        $discount_percentage = isset($body['discountPercentage']) ? (float) $body['discountPercentage'] : 0.0;
        $coupon_type = 'fixed_cart';
        $amount = $discount_amount;

        if (!empty($body['freeShipping'])) {
            $coupon_type = 'fixed_cart';
            $amount = 0;
            $coupon->set_free_shipping(true);
        } elseif ($discount_percentage > 0) {
            $coupon_type = 'percent';
            $amount = $discount_percentage;
        } elseif ($amount <= 0 && isset($reward['discountAmount'])) {
            $amount = (float) $reward['discountAmount'];
        }

        if ($amount < 0) {
            $amount = 0;
        }

        $coupon->set_code($discount_code);
        $coupon->set_amount($amount);
        $coupon->set_discount_type($coupon_type);
        $coupon->set_individual_use(true);
        $coupon->set_usage_limit(1);
        $coupon->set_usage_limit_per_user(1);
        $coupon->set_email_restrictions([$email]);
        $coupon->set_description(sprintf('Loyalty Engage reward for %s (%s)', $email, $sku));
        $coupon->set_virtual(true);

        $expires_at = !empty($body['expiresAt']) ? strtotime((string) $body['expiresAt']) : (time() + (3 * DAY_IN_SECONDS));
        if ($expires_at) {
            $coupon->set_date_expires($expires_at);
        }

        try {
            $coupon->save();

            if (function_exists('WC') && WC()->cart instanceof WC_Cart) {
                if (WC()->customer instanceof WC_Customer && WC()->customer->get_billing_email() === '') {
                    WC()->customer->set_billing_email($email);
                }
                $result['applied'] = WC()->cart->has_discount($discount_code) || WC()->cart->apply_coupon($discount_code);
            }

            if ($result['applied']) {
                self::clear_pending_coupon($email);
            } else {
                self::store_pending_coupon($discount_code, $email);
                $result['pending'] = true;
            }

            LEW_Storefront::persist_cart_session();

            LEW_Logger::info('Created or updated WooCommerce coupon for loyalty reward', [
                'discount_code' => $discount_code,
                'coupon_type' => $coupon_type,
                'amount' => $amount,
                'email' => $email,
                'coupon_applied' => $result['applied'],
                'coupon_pending' => $result['pending'],
            ]);
            return $result;
        } catch (Throwable $throwable) {
            LEW_Logger::error('Failed to create WooCommerce coupon for loyalty reward', [
                'discount_code' => $discount_code,
                'message' => $throwable->getMessage(),
            ]);
            return $result;
        }
    }

    private static function store_pending_coupon(string $discount_code, string $email): void
    {
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('lew_pending_coupon', $discount_code);
        }

        $user = get_user_by('email', $email);
        if ($user instanceof WP_User) {
            update_user_meta($user->ID, 'lew_pending_coupon', $discount_code);
        }
    }

    private static function clear_pending_coupon(string $email): void
    {
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('lew_pending_coupon', null);
        }

        $user = get_user_by('email', $email);
        if ($user instanceof WP_User) {
            delete_user_meta($user->ID, 'lew_pending_coupon');
        }
    }

    private static function maybe_store_redemption(string $sku, string $email, array $response): void
    {
        global $wpdb;

        $body = is_array($response['body']) ? $response['body'] : [];
        $discount_code = (string) ($body['discountCode'] ?? $body['discount_code'] ?? '');
        if ($discount_code === '') {
            return;
        }

        $table = $wpdb->prefix . 'lew_physical_redemptions';
        $now = current_time('mysql', true);
        $wpdb->replace($table, [
            'discount_code' => $discount_code,
            'customer_email' => $email,
            'sku' => $sku,
            'status' => 'pending',
            'meta' => wp_json_encode($body),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private static function add_reward_product_to_cart(int $product_or_variation_id, string $sku, array $response)
    {
        if (!function_exists('WC') || !WC()->cart) {
            return new WP_Error('lew_cart_unavailable', 'Cart is not available.');
        }

        $product = wc_get_product($product_or_variation_id);
        if (!$product) {
            return new WP_Error('lew_product_missing', 'Reward product could not be loaded.');
        }

        $quantity = 1;
        $cart_item_data = [
            'lew_loyalty_reward' => true,
            'lew_loyalty_sku' => $sku,
            'lew_loyalty_discount_code' => (string) ((is_array($response['body']) ? ($response['body']['discountCode'] ?? $response['body']['discount_code'] ?? '') : '')),
        ];

        $variation_id = 0;
        $variation = [];
        $product_id = $product->get_id();

        if ($product instanceof WC_Product_Variation) {
            $variation_id = $product->get_id();
            $product_id = $product->get_parent_id();
            $variation = $product->get_attributes();
        }

        $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation, $cart_item_data);
        if (!$cart_item_key) {
            return new WP_Error('lew_add_to_cart_failed', 'Could not add reward product to cart.');
        }

        LEW_Storefront::persist_cart_session();

        return [
            'cart_item_key' => $cart_item_key,
        ];
    }

    private static function points_redemption_guard(): ?WP_REST_Response
    {
        if (!LEW_Settings::is_module_enabled()) {
            return new WP_REST_Response(['success' => false, 'message' => 'Loyalty Engage module is disabled.'], 503);
        }

        $settings = LEW_Settings::get_settings();
        if (($settings['points_redemption_enabled'] ?? 'no') !== 'yes') {
            return new WP_REST_Response(['success' => false, 'message' => 'Points redemption is disabled.'], 403);
        }

        if (!function_exists('WC') || !WC()->cart) {
            return new WP_REST_Response(['success' => false, 'message' => 'Winkelmand niet beschikbaar.'], 500);
        }

        return null;
    }

    private static function validate_points_redemption_request(WP_REST_Request $request, int $user_id): ?WP_REST_Response
    {
        $payload = $request->get_json_params();
        $points = (int) ($payload['points'] ?? 0);
        $settings = LEW_Settings::get_settings();
        $available_points = (int) LEW_Storefront::get_customer_loyalty_value($user_id, 'lew_points');
        $min_points = max(1, (int) ($settings['min_points_to_redeem'] ?? 1));
        $max_points = (int) ($settings['max_points_per_order'] ?? 0);

        if ($points < $min_points) {
            return new WP_REST_Response(['success' => false, 'message' => sprintf('Je moet minimaal %d punten inwisselen.', $min_points)], 400);
        }

        if ($max_points > 0 && $points > $max_points) {
            return new WP_REST_Response(['success' => false, 'message' => sprintf('Je kunt maximaal %d punten per bestelling inwisselen.', $max_points)], 400);
        }

        if ($available_points > 0 && $points > $available_points) {
            return new WP_REST_Response(['success' => false, 'message' => 'Je hebt niet genoeg punten beschikbaar.'], 400);
        }

        return null;
    }

    private static function get_cart_subtotal(): float
    {
        if (!function_exists('WC') || !WC()->cart) {
            return 0.0;
        }

        return round((float) WC()->cart->get_subtotal(), 2);
    }

    private static function calculate_points_discount_amount(int $points, float $subtotal, array $settings): float
    {
        $points_per_unit = max(1, (int) ($settings['points_per_currency_unit'] ?? 1));
        $discount = $points / $points_per_unit;
        $max_percentage = max(0, (int) ($settings['max_discount_percentage'] ?? 0));
        if ($max_percentage > 0 && $subtotal > 0) {
            $discount = min($discount, $subtotal * ($max_percentage / 100));
        }

        return round(max(0, $discount), 2);
    }

    private static function apply_coupon_to_cart(string $coupon_code)
    {
        if (!function_exists('WC') || !WC()->cart) {
            return new WP_Error('lew_cart_unavailable', 'Cart is not available.');
        }

        if (WC()->cart->has_discount($coupon_code)) {
            return true;
        }

        $applied = WC()->cart->apply_coupon($coupon_code);
        if (!$applied) {
            return new WP_Error('lew_apply_coupon_failed', 'Kortingscode kon niet automatisch worden toegepast.');
        }

        return true;
    }

    private static function store_active_points_coupon(int $user_id, string $coupon_code): void
    {
        update_user_meta($user_id, 'lew_active_points_coupon', $coupon_code);
    }

    private static function get_active_points_coupon(int $user_id): string
    {
        return (string) get_user_meta($user_id, 'lew_active_points_coupon', true);
    }

    private static function expand_loyalty_meta_keys(string $incoming_key): array
    {
        $normalized = sanitize_key($incoming_key);
        $map = [
            'current_tier' => ['lew_current_tier', 'lew_currenttier'],
            'currenttier' => ['lew_current_tier', 'lew_currenttier'],
            'le_current_tier' => ['lew_current_tier', 'lew_le_current_tier', 'lew_currenttier', 'lew_le_currenttier'],
            'points' => ['lew_points'],
            'le_points' => ['lew_points', 'lew_le_points'],
            'available_coins' => ['lew_availableCoins', 'lew_available_coins'],
            'availablecoins' => ['lew_availableCoins', 'lew_available_coins'],
            'le_available_coins' => ['lew_availableCoins', 'lew_le_available_coins'],
            'next_tier' => ['lew_next_tier', 'lew_nexttier'],
            'nexttier' => ['lew_next_tier', 'lew_nexttier'],
            'le_next_tier' => ['lew_next_tier', 'lew_le_next_tier'],
            'points_to_next_tier' => ['lew_points_to_next_tier', 'lew_pointstonexttier'],
            'pointstonexttier' => ['lew_points_to_next_tier', 'lew_pointstonexttier'],
            'le_points_to_next_tier' => ['lew_points_to_next_tier', 'lew_le_points_to_next_tier'],
            'reserved_coins' => ['lew_reserved_coins', 'lew_reservedcoins'],
            'reservedcoins' => ['lew_reserved_coins', 'lew_reservedcoins'],
            'le_reserved_coins' => ['lew_reserved_coins', 'lew_le_reserved_coins'],
            'expiring_points_30d' => ['lew_expiring_points_30d', 'lew_expiringpoints30d'],
            'expiringpoints30d' => ['lew_expiring_points_30d', 'lew_expiringpoints30d'],
            'le_expiring_points_30d' => ['lew_expiring_points_30d', 'lew_le_expiring_points_30d'],
        ];

        return $map[$normalized] ?? ['lew_' . $normalized];
    }
}
