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
            'discount_reward_mode' => 'woocommerce_coupon',
            'max_loyalty_products_per_cart' => '0',
            'minimum_order_value' => '0',
            'minimum_order_value_message' => 'Je winkelmand moet minimaal {{minimum}} zijn om loyalty rewards toe te voegen. Huidig subtotaal: {{current}}.',
            'loyalty_product_removed_message' => 'Je loyalty reward is verwijderd omdat je winkelmand onder {{minimum}} is gekomen. Huidig subtotaal: {{current}}.',
            'pdp_points_enabled' => 'no',
            'pdp_points_per_currency_unit' => '1',
            'pdp_points_label' => 'Met dit product verdien je {{points}} punten.',
            'free_shipping_enabled' => 'no',
            'free_shipping_tiers' => '',
            'expose_customer_meta' => 'yes',
            'account_block_title' => 'Loyalty overzicht',
            'levels_title' => 'Jouw titel',
            'levels_intro' => 'Bekijk welke levels je al hebt bereikt en welke nog voor je liggen.',
            'rewards_title' => 'Cadeaus en cadeaubonnen',
            'rewards_intro' => 'Je hebt {{coins}} coins om in te wisselen. Ontdek alle rewards, ook wanneer je nog coins of een hoger level nodig hebt.',
            'loyalty_custom_css' => '',
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
            'loyalty_levels' => '',
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
            'discount_reward_mode' => in_array($merged['discount_reward_mode'], ['woocommerce_coupon', 'email_gift_card'], true)
                ? $merged['discount_reward_mode']
                : $defaults['discount_reward_mode'],
            'max_loyalty_products_per_cart' => $sanitize_int($merged['max_loyalty_products_per_cart']),
            'minimum_order_value' => $sanitize_decimal($merged['minimum_order_value']),
            'minimum_order_value_message' => sanitize_textarea_field((string) $merged['minimum_order_value_message']),
            'loyalty_product_removed_message' => sanitize_textarea_field((string) $merged['loyalty_product_removed_message']),
            'pdp_points_enabled' => $is_enabled($merged['pdp_points_enabled']),
            'pdp_points_per_currency_unit' => $sanitize_decimal($merged['pdp_points_per_currency_unit']),
            'pdp_points_label' => sanitize_text_field((string) $merged['pdp_points_label']),
            'free_shipping_enabled' => $is_enabled($merged['free_shipping_enabled']),
            'free_shipping_tiers' => sanitize_text_field((string) $merged['free_shipping_tiers']),
            'expose_customer_meta' => $is_enabled($merged['expose_customer_meta']),
            'account_block_title' => sanitize_text_field((string) $merged['account_block_title']),
            'levels_title' => sanitize_text_field((string) $merged['levels_title']),
            'levels_intro' => sanitize_textarea_field((string) $merged['levels_intro']),
            'rewards_title' => sanitize_text_field((string) $merged['rewards_title']),
            'rewards_intro' => sanitize_textarea_field((string) $merged['rewards_intro']),
            'loyalty_custom_css' => self::sanitize_custom_css((string) $merged['loyalty_custom_css']),
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
            'loyalty_levels' => sanitize_textarea_field((string) $merged['loyalty_levels']),
        ];
    }

    public static function sanitize_custom_css(string $css): string
    {
        // Never allow custom CSS to close the inline style element.
        return str_replace('<', '', wp_strip_all_tags($css));
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
                        <th scope="row"><label for="lew-discount-reward-mode">Discount reward delivery</label></th>
                        <td>
                            <select id="lew-discount-reward-mode" name="<?php echo esc_attr(self::OPTION_KEY); ?>[discount_reward_mode]">
                                <option value="woocommerce_coupon" <?php selected($settings['discount_reward_mode'], 'woocommerce_coupon'); ?>>WooCommerce coupon (apply to cart)</option>
                                <option value="email_gift_card" <?php selected($settings['discount_reward_mode'], 'email_gift_card'); ?>>E-mail gift card (do not add to cart)</option>
                            </select>
                            <p class="description">In e-mail gift card mode, Loyalty Engage creates and delivers the voucher. WooCommerce does not create or apply a coupon. Configure the voucher value, for example &euro;25, on the Loyalty Engage reward.</p>
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
                        <th scope="row">Coins inwisselen</th>
                        <td>
                            <p class="description">Coins worden ingewisseld via de cadeaus en cadeaubonnen in de loyaltyshop. Levelpunten zijn uitsluitend bedoeld voor voortgang naar het volgende level.</p>
                            <?php if (($settings['points_redemption_enabled'] ?? 'no') === 'yes') : ?>
                                <p><strong>Let op:</strong> de eerdere vrije puntenkorting is uitgeschakeld. Bestaande instellingen worden verwijderd zodra je deze pagina opslaat.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Points on product pages</th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[pdp_points_enabled]" value="1" <?php checked($settings['pdp_points_enabled'], 'yes'); ?>> Show estimated earning points on product pages</label>
                            <p class="description">Uses the displayed WooCommerce product price. Configure the base earning rate used by Loyalty Engage.</p>
                            <input type="number" min="0" step="0.01" id="lew-pdp-points-rate" name="<?php echo esc_attr(self::OPTION_KEY); ?>[pdp_points_per_currency_unit]" value="<?php echo esc_attr($settings['pdp_points_per_currency_unit']); ?>" class="small-text">
                            <label for="lew-pdp-points-rate"> Points earned per currency unit</label>
                            <br>
                            <input id="lew-pdp-points-label" name="<?php echo esc_attr(self::OPTION_KEY); ?>[pdp_points_label]" value="<?php echo esc_attr($settings['pdp_points_label']); ?>" class="regular-text">
                            <p class="description">Use <code>{{points}}</code> for the amount or range. Campaign bonuses are calculated by Loyalty Engage after purchase and are not included in this estimate.</p>
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
                        <th scope="row">Teksten loyaltypagina</th>
                        <td>
                            <?php foreach (['levels_title' => 'Titel levels', 'levels_intro' => 'Ondertitel levels', 'rewards_title' => 'Titel rewards', 'rewards_intro' => 'Ondertitel rewards'] as $key => $label) : ?>
                                <p><label for="lew-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></p>
                                <textarea id="lew-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($key); ?>]" class="large-text" rows="2"><?php echo esc_textarea($settings[$key]); ?></textarea>
                            <?php endforeach; ?>
                            <p class="description">Gebruik {{coins}} in de reward-ondertitel voor het inwisselbare saldo. Lege teksten worden niet getoond. Dit geldt voor [loyalty_engage_page], niet voor externe personalisatietemplates.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="lew-custom-css">Eigen CSS loyaltypagina</label></th>
                        <td>
                            <textarea id="lew-custom-css" name="<?php echo esc_attr(self::OPTION_KEY); ?>[loyalty_custom_css]" class="large-text code" rows="8"><?php echo esc_textarea($settings['loyalty_custom_css']); ?></textarea>
                            <p class="description">Alleen CSS, zonder &lt;style&gt;-tags. Begin selectors met <code>#lew-loyalty-page</code> om andere onderdelen van de pagina niet te wijzigen. Bijvoorbeeld: <code>#lew-loyalty-page .lew-card { border-radius: 20px; }</code></p>
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
                        <th scope="row"><label for="lew-loyalty-levels">Loyalty levels</label></th>
                        <td>
                            <textarea id="lew-loyalty-levels" name="<?php echo esc_attr(self::OPTION_KEY); ?>[loyalty_levels]" class="large-text code" rows="5" placeholder="Brons|0&#10;Zilver|500&#10;Goud|1500"><?php echo esc_textarea($settings['loyalty_levels']); ?></textarea>
                            <p class="description">Alle levels voor het loyalty-overzicht, één per regel in oplopende volgorde: naam|benodigde punten. Ook levels zonder gekoppelde reward worden getoond.</p>
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
