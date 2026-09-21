<?php

if (!defined('ABSPATH')) {
    exit;
}

class LEW_Settings
{
    public const OPTION_KEY = 'lew_settings';

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_init', [self::class, 'register_settings']);
    }

    public static function defaults(): array
    {
        return [
            'module_enabled' => 'yes',
            'api_base_url' => 'https://app.loyaltyengage.tech/api/v1',
            'client_id' => '',
            'client_secret' => '',
            'webhook_secret' => '',
            'logger_enabled' => 'no',
            'order_export_enabled' => 'yes',
            'purchase_export_statuses' => 'processing,completed',
            'return_export_enabled' => 'yes',
            'review_export_enabled' => 'no',
            'customer_sync_enabled' => 'yes',
            'max_loyalty_products_per_cart' => '0',
            'minimum_order_value' => '0',
            'minimum_order_value_message' => 'Je winkelmand moet minimaal {{minimum}} zijn om loyalty rewards toe te voegen. Huidig subtotaal: {{current}}.',
            'loyalty_product_removed_message' => 'Je loyalty reward is verwijderd omdat je winkelmand onder {{minimum}} is gekomen. Huidig subtotaal: {{current}}.',
            'points_redemption_enabled' => 'no',
            'points_per_currency_unit' => '1',
            'min_points_to_redeem' => '1',
            'max_points_per_order' => '0',
            'max_discount_percentage' => '0',
            'free_shipping_enabled' => 'no',
            'free_shipping_tiers' => '',
            'expose_customer_meta' => 'yes',
            'account_block_title' => 'Loyalty overzicht',
            'le_current_tier_enabled' => 'yes',
            'le_current_tier_label' => 'Huidige tier',
            'le_points_enabled' => 'yes',
            'le_points_label' => 'Punten',
            'le_available_coins_enabled' => 'yes',
            'le_available_coins_label' => 'Beschikbare coins',
            'le_next_tier_enabled' => 'yes',
            'le_next_tier_label' => 'Volgende tier',
            'le_points_to_next_tier_enabled' => 'yes',
            'le_points_to_next_tier_label' => 'Punten tot volgende tier',
            'le_reserved_coins_enabled' => 'no',
            'le_reserved_coins_label' => 'Gereserveerde coins',
            'le_expiring_points_30d_enabled' => 'no',
            'le_expiring_points_30d_label' => 'Punten die binnen 30 dagen verlopen',
            'loyalty_shop_personalization_enabled' => 'no',
            'loyalty_shop_personalization_tags' => '',
            'loyalty_shop_display_mode' => 'prioritize_matches',
        ];
    }

    public static function get_settings(): array
    {
        return wp_parse_args((array) get_option(self::OPTION_KEY, []), self::defaults());
    }

    public static function register_menu(): void
    {
        add_menu_page(
            'Loyalty Engage',
            'Loyalty Engage',
            'manage_woocommerce',
            'loyalty-engage',
            [self::class, 'render_page'],
            'dashicons-awards'
        );
    }

    public static function register_settings(): void
    {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default' => self::defaults(),
        ]);
    }

    public static function sanitize(array $settings): array
    {
        $defaults = self::defaults();
        $merged = wp_parse_args($settings, $defaults);
        $current = (array) get_option(self::OPTION_KEY, []);
        $is_enabled = static function ($value): string {
            return in_array($value, [1, '1', true, 'true', 'yes', 'on'], true) ? 'yes' : 'no';
        };
        $sanitize_secret = static function ($value): string {
            // Secrets are opaque values: HTML/text sanitizers can corrupt valid characters such as "<".
            return trim(str_replace(["\r", "\n", "\0"], '', (string) $value));
        };
        $sanitize_decimal = static function ($value): string {
            $number = is_numeric($value) ? (float) $value : 0.0;
            return (string) max(0, $number);
        };
        $sanitize_int = static function ($value): string {
            return (string) max(0, absint($value));
        };
        $api_base_url = esc_url_raw(trim((string) $merged['api_base_url']));
        if ($api_base_url === '') {
            $api_base_url = $defaults['api_base_url'];
        }

        $client_secret = $sanitize_secret($merged['client_secret']);
        if ($client_secret === '' && !empty($current['client_secret'])) {
            $client_secret = (string) $current['client_secret'];
        }

        $webhook_secret = $sanitize_secret($merged['webhook_secret']);
        if ($webhook_secret === '' && !empty($current['webhook_secret'])) {
            $webhook_secret = (string) $current['webhook_secret'];
        }

        return [
            'module_enabled' => $is_enabled($merged['module_enabled']),
            'api_base_url' => untrailingslashit($api_base_url),
            'client_id' => sanitize_text_field((string) $merged['client_id']),
            'client_secret' => $client_secret,
            'webhook_secret' => $webhook_secret,
            'logger_enabled' => $is_enabled($merged['logger_enabled']),
            'order_export_enabled' => $is_enabled($merged['order_export_enabled']),
            'purchase_export_statuses' => sanitize_text_field((string) $merged['purchase_export_statuses']),
            'return_export_enabled' => $is_enabled($merged['return_export_enabled']),
            'review_export_enabled' => $is_enabled($merged['review_export_enabled']),
            'customer_sync_enabled' => $is_enabled($merged['customer_sync_enabled']),
            'max_loyalty_products_per_cart' => $sanitize_int($merged['max_loyalty_products_per_cart']),
            'minimum_order_value' => $sanitize_decimal($merged['minimum_order_value']),
            'minimum_order_value_message' => sanitize_textarea_field((string) $merged['minimum_order_value_message']),
            'loyalty_product_removed_message' => sanitize_textarea_field((string) $merged['loyalty_product_removed_message']),
            'points_redemption_enabled' => $is_enabled($merged['points_redemption_enabled']),
            'points_per_currency_unit' => (string) max(1, absint($merged['points_per_currency_unit'])),
            'min_points_to_redeem' => (string) max(1, absint($merged['min_points_to_redeem'])),
            'max_points_per_order' => $sanitize_int($merged['max_points_per_order']),
            'max_discount_percentage' => (string) min(100, max(0, absint($merged['max_discount_percentage']))),
            'free_shipping_enabled' => $is_enabled($merged['free_shipping_enabled']),
            'free_shipping_tiers' => sanitize_text_field((string) $merged['free_shipping_tiers']),
            'expose_customer_meta' => $is_enabled($merged['expose_customer_meta']),
            'account_block_title' => sanitize_text_field((string) $merged['account_block_title']),
            'le_current_tier_enabled' => $is_enabled($merged['le_current_tier_enabled']),
            'le_current_tier_label' => sanitize_text_field((string) $merged['le_current_tier_label']),
            'le_points_enabled' => $is_enabled($merged['le_points_enabled']),
            'le_points_label' => sanitize_text_field((string) $merged['le_points_label']),
            'le_available_coins_enabled' => $is_enabled($merged['le_available_coins_enabled']),
            'le_available_coins_label' => sanitize_text_field((string) $merged['le_available_coins_label']),
            'le_next_tier_enabled' => $is_enabled($merged['le_next_tier_enabled']),
            'le_next_tier_label' => sanitize_text_field((string) $merged['le_next_tier_label']),
            'le_points_to_next_tier_enabled' => $is_enabled($merged['le_points_to_next_tier_enabled']),
            'le_points_to_next_tier_label' => sanitize_text_field((string) $merged['le_points_to_next_tier_label']),
            'le_reserved_coins_enabled' => $is_enabled($merged['le_reserved_coins_enabled']),
            'le_reserved_coins_label' => sanitize_text_field((string) $merged['le_reserved_coins_label']),
            'le_expiring_points_30d_enabled' => $is_enabled($merged['le_expiring_points_30d_enabled']),
            'le_expiring_points_30d_label' => sanitize_text_field((string) $merged['le_expiring_points_30d_label']),
            'loyalty_shop_personalization_enabled' => $is_enabled($merged['loyalty_shop_personalization_enabled']),
            'loyalty_shop_personalization_tags' => sanitize_text_field((string) $merged['loyalty_shop_personalization_tags']),
            'loyalty_shop_display_mode' => $merged['loyalty_shop_display_mode'] === 'only_matching' ? 'only_matching' : 'prioritize_matches',
        ];
    }

    public static function is_module_enabled(): bool
    {
        $settings = self::get_settings();
        return ($settings['module_enabled'] ?? 'yes') === 'yes';
    }

    public static function render_page(): void
    {
        $settings = self::get_settings();
        ?>
        <div class="wrap">
            <h1>Loyalty Engage for WooCommerce</h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_KEY); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Module</th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[module_enabled]" value="1" <?php checked($settings['module_enabled'], 'yes'); ?>> Enable Loyalty Engage</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="lew-api-base-url">API Base URL</label></th>
                        <td>
                            <input id="lew-api-base-url" name="<?php echo esc_attr(self::OPTION_KEY); ?>[api_base_url]" value="<?php echo esc_attr($settings['api_base_url']); ?>" class="regular-text">
                            <p class="description">Default: <code>https://app.loyaltyengage.tech/api/v1</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="lew-client-id">Client ID</label></th>
                        <td><input id="lew-client-id" name="<?php echo esc_attr(self::OPTION_KEY); ?>[client_id]" value="<?php echo esc_attr($settings['client_id']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="lew-client-secret">Client Secret</label></th>
                        <td>
                            <input type="password" id="lew-client-secret" name="<?php echo esc_attr(self::OPTION_KEY); ?>[client_secret]" value="" class="regular-text" autocomplete="new-password">
                            <p class="description"><?php echo $settings['client_secret'] !== '' ? 'A client secret is configured. Leave blank to keep it unchanged.' : 'Enter the Loyalty Engage client secret.'; ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="lew-webhook-secret">Webhook Secret</label></th>
                        <td>
                            <input type="password" id="lew-webhook-secret" name="<?php echo esc_attr(self::OPTION_KEY); ?>[webhook_secret]" value="" class="regular-text" autocomplete="new-password">
                            <p class="description"><?php echo $settings['webhook_secret'] !== '' ? 'A webhook secret is configured. Leave blank to keep it unchanged. ' : ''; ?>Used to verify inbound Loyalty Engage callbacks such as customer updates.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Features</th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[logger_enabled]" value="1" <?php checked($settings['logger_enabled'], 'yes'); ?>> Enable logging</label><br>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[order_export_enabled]" value="1" <?php checked($settings['order_export_enabled'], 'yes'); ?>> Order export</label><br>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[return_export_enabled]" value="1" <?php checked($settings['return_export_enabled'], 'yes'); ?>> Return export</label><br>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[review_export_enabled]" value="1" <?php checked($settings['review_export_enabled'], 'yes'); ?>> Review export</label><br>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[customer_sync_enabled]" value="1" <?php checked($settings['customer_sync_enabled'], 'yes'); ?>> Customer sync</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="lew-purchase-export-statuses">Purchase export statuses</label></th>
                        <td>
                            <input id="lew-purchase-export-statuses" name="<?php echo esc_attr(self::OPTION_KEY); ?>[purchase_export_statuses]" value="<?php echo esc_attr($settings['purchase_export_statuses']); ?>" class="regular-text" placeholder="processing,completed">
                            <p class="description">Comma separated WooCommerce order statuses that should trigger purchase export.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Loyalty cart restrictions</th>
                        <td>
                            <input type="number" min="0" id="lew-max-loyalty-products" name="<?php echo esc_attr(self::OPTION_KEY); ?>[max_loyalty_products_per_cart]" value="<?php echo esc_attr($settings['max_loyalty_products_per_cart']); ?>" class="small-text">
                            <label for="lew-max-loyalty-products"> Maximum loyalty products per cart</label>
                            <p class="description">Set to 0 for unlimited.</p>

                            <input type="number" min="0" step="0.01" id="lew-minimum-order-value" name="<?php echo esc_attr(self::OPTION_KEY); ?>[minimum_order_value]" value="<?php echo esc_attr($settings['minimum_order_value']); ?>" class="small-text">
                            <label for="lew-minimum-order-value"> Minimum non-loyalty cart subtotal</label>
                            <p class="description">Loyalty rewards can only be added when the regular cart subtotal meets this amount.</p>

                            <textarea id="lew-minimum-order-value-message" name="<?php echo esc_attr(self::OPTION_KEY); ?>[minimum_order_value_message]" class="large-text" rows="3"><?php echo esc_textarea($settings['minimum_order_value_message']); ?></textarea>
                            <p class="description">Shown when the minimum order value is not met. Use <code>{{minimum}}</code> and <code>{{current}}</code>.</p>

                            <textarea id="lew-loyalty-product-removed-message" name="<?php echo esc_attr(self::OPTION_KEY); ?>[loyalty_product_removed_message]" class="large-text" rows="3"><?php echo esc_textarea($settings['loyalty_product_removed_message']); ?></textarea>
                            <p class="description">Shown when loyalty items are removed after the cart subtotal drops below the minimum.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Points redemption</th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[points_redemption_enabled]" value="1" <?php checked($settings['points_redemption_enabled'], 'yes'); ?>> Enable points redemption in cart</label>
                            <p class="description">Uses the Loyalty Engage redeem-points API and applies the returned coupon automatically in WooCommerce.</p>
                            <input type="number" min="1" id="lew-points-per-currency-unit" name="<?php echo esc_attr(self::OPTION_KEY); ?>[points_per_currency_unit]" value="<?php echo esc_attr($settings['points_per_currency_unit']); ?>" class="small-text">
                            <label for="lew-points-per-currency-unit"> Points per currency unit discount</label>
                            <br>
                            <input type="number" min="1" id="lew-min-points-to-redeem" name="<?php echo esc_attr(self::OPTION_KEY); ?>[min_points_to_redeem]" value="<?php echo esc_attr($settings['min_points_to_redeem']); ?>" class="small-text">
                            <label for="lew-min-points-to-redeem"> Minimum points per redemption</label>
                            <br>
                            <input type="number" min="0" id="lew-max-points-per-order" name="<?php echo esc_attr(self::OPTION_KEY); ?>[max_points_per_order]" value="<?php echo esc_attr($settings['max_points_per_order']); ?>" class="small-text">
                            <label for="lew-max-points-per-order"> Maximum points per order</label>
                            <br>
                            <input type="number" min="0" max="100" id="lew-max-discount-percentage" name="<?php echo esc_attr(self::OPTION_KEY); ?>[max_discount_percentage]" value="<?php echo esc_attr($settings['max_discount_percentage']); ?>" class="small-text">
                            <label for="lew-max-discount-percentage"> Maximum discount percentage of cart subtotal</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Loyalty tier free shipping</th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[free_shipping_enabled]" value="1" <?php checked($settings['free_shipping_enabled'], 'yes'); ?>> Enable free shipping for selected loyalty tiers</label>
                            <p class="description">When enabled, all available shipping methods become free for logged-in customers whose loyalty tier matches one of the configured values.</p>
                            <input id="lew-free-shipping-tiers" name="<?php echo esc_attr(self::OPTION_KEY); ?>[free_shipping_tiers]" value="<?php echo esc_attr($settings['free_shipping_tiers']); ?>" class="regular-text" placeholder="Bronze,Silver,Gold">
                            <p class="description">Comma separated tier names. Matching is case-insensitive.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Frontend loyalty meta</th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[expose_customer_meta]" value="1" <?php checked($settings['expose_customer_meta'], 'yes'); ?>> Show loyalty overview block</label>
                            <p class="description">Displays stored loyalty customer data on the loyalty page.</p>
                            <input id="lew-account-block-title" name="<?php echo esc_attr(self::OPTION_KEY); ?>[account_block_title]" value="<?php echo esc_attr($settings['account_block_title']); ?>" class="regular-text">
                            <p class="description">Block title.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Frontend loyalty labels</th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[le_current_tier_enabled]" value="1" <?php checked($settings['le_current_tier_enabled'], 'yes'); ?>> Show current tier</label>
                            <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[le_current_tier_label]" value="<?php echo esc_attr($settings['le_current_tier_label']); ?>" class="regular-text"><br>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[le_points_enabled]" value="1" <?php checked($settings['le_points_enabled'], 'yes'); ?>> Show points</label>
                            <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[le_points_label]" value="<?php echo esc_attr($settings['le_points_label']); ?>" class="regular-text"><br>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[le_available_coins_enabled]" value="1" <?php checked($settings['le_available_coins_enabled'], 'yes'); ?>> Show available coins</label>
                            <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[le_available_coins_label]" value="<?php echo esc_attr($settings['le_available_coins_label']); ?>" class="regular-text"><br>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[le_next_tier_enabled]" value="1" <?php checked($settings['le_next_tier_enabled'], 'yes'); ?>> Show next tier</label>
                            <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[le_next_tier_label]" value="<?php echo esc_attr($settings['le_next_tier_label']); ?>" class="regular-text"><br>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[le_points_to_next_tier_enabled]" value="1" <?php checked($settings['le_points_to_next_tier_enabled'], 'yes'); ?>> Show points to next tier</label>
                            <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[le_points_to_next_tier_label]" value="<?php echo esc_attr($settings['le_points_to_next_tier_label']); ?>" class="regular-text"><br>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[le_reserved_coins_enabled]" value="1" <?php checked($settings['le_reserved_coins_enabled'], 'yes'); ?>> Show reserved coins</label>
                            <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[le_reserved_coins_label]" value="<?php echo esc_attr($settings['le_reserved_coins_label']); ?>" class="regular-text"><br>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[le_expiring_points_30d_enabled]" value="1" <?php checked($settings['le_expiring_points_30d_enabled'], 'yes'); ?>> Show expiring points (30d)</label>
                            <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[le_expiring_points_30d_label]" value="<?php echo esc_attr($settings['le_expiring_points_30d_label']); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Loyalty shop personalization</th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[loyalty_shop_personalization_enabled]" value="1" <?php checked($settings['loyalty_shop_personalization_enabled'], 'yes'); ?>> Enable personalization</label>
                            <p class="description">Only selected WooCommerce product tags are stored on customers and used for loyalty shop ranking.</p>
                            <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[loyalty_shop_personalization_tags]" value="<?php echo esc_attr($settings['loyalty_shop_personalization_tags']); ?>" class="regular-text" placeholder="dog, cat, puppy">
                            <p class="description">Comma separated product tags.</p>
                            <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[loyalty_shop_display_mode]">
                                <option value="prioritize_matches" <?php selected($settings['loyalty_shop_display_mode'], 'prioritize_matches'); ?>>Show everything, matching products first</option>
                                <option value="only_matching" <?php selected($settings['loyalty_shop_display_mode'], 'only_matching'); ?>>Show only matching products</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
