<?php

if (!defined('ABSPATH')) {
    exit;
}

class LEW_Personalizations
{
    private const OPTION_KEY = 'lew_personalizations';
    private static array $rendered = [];
    private static bool $scripts_enqueued = false;

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_post_lew_save_personalizations', [self::class, 'save']);
        add_shortcode('loyalty_engage_personalization', [self::class, 'shortcode']);
        add_filter('woocommerce_short_description', [self::class, 'render_inline_shortcodes'], 20);
        add_filter('render_block_woocommerce/product-summary', [self::class, 'render_inline_shortcodes'], 20);
        add_filter('render_block_core/html', [self::class, 'render_inline_shortcodes'], 20);
        add_filter('render_block_core/post-excerpt', [self::class, 'render_inline_shortcodes'], 20);
        add_filter('widget_custom_html_content', [self::class, 'render_inline_shortcodes'], 20);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_base_script'], 20);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_for_auto_pages'], 30);
        add_filter('script_loader_tag', [self::class, 'module_script_tag'], 10, 2);
        add_action('woocommerce_single_product_summary', [self::class, 'render_product_auto'], 35);
        add_filter('the_content', [self::class, 'append_page_auto'], 30);
    }

    public static function get_settings(): array
    {
        $saved = get_option(self::OPTION_KEY, []);
        $settings = wp_parse_args(is_array($saved) ? $saved : [], [
            'tenant_id' => '',
            'rules' => [],
        ]);
        // Existing installations may have stored the generated script URL.
        $settings['tenant_id'] = self::extract_tenant_id((string) ($settings['tenant_id'] ?: ($settings['script_url'] ?? '')));
        return $settings;
    }

    public static function extract_tenant_id(string $input): string
    {
        $input = trim($input);
        $uuid = '[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}';
        if (preg_match('~^(' . $uuid . ')$~i', $input, $matches)) {
            return strtolower($matches[1]);
        }
        if (preg_match('~https://le-cloud\.app/f/t/(' . $uuid . ')\.js(?:[?"\']|$)~i', $input, $matches)) {
            return strtolower($matches[1]);
        }
        if (str_contains($input, 'https://le-cloud.app/f/t/') &&
            preg_match('~\(\s*window\s*,\s*document\s*,\s*["\']script["\']\s*,\s*["\'](' . $uuid . ')["\']\s*\)~i', $input, $matches)) {
            return strtolower($matches[1]);
        }
        return '';
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            'loyalty-engage',
            'Personalisaties',
            'Personalisaties',
            'manage_woocommerce',
            'loyalty-engage-personalizations',
            [self::class, 'render_admin_page']
        );
    }

    public static function save(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Geen toegang.');
        }
        check_admin_referer('lew_save_personalizations');

        $input = isset($_POST['lew_personalizations']) && is_array($_POST['lew_personalizations'])
            ? wp_unslash($_POST['lew_personalizations'])
            : [];
        $tenant_input = trim((string) ($input['tenant_id'] ?? ''));
        $tenant_id = self::extract_tenant_id($tenant_input);
        $invalid_tenant = $tenant_input !== '' && $tenant_id === '';
        if ($invalid_tenant) {
            $tenant_id = self::get_settings()['tenant_id'];
        }

        $rules = [];
        $used_keys = [];
        $submitted = isset($input['rules']) && is_array($input['rules']) ? $input['rules'] : [];
        foreach (array_slice($submitted, 0, 100) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = sanitize_title((string) ($row['key'] ?? ''));
            $public_id = sanitize_text_field((string) ($row['public_id'] ?? ''));
            if ($key === '' || $public_id === '' || isset($used_keys[$key])) {
                continue;
            }
            $used_keys[$key] = true;
            $placement = ($row['placement'] ?? '') === 'auto' ? 'auto' : 'shortcode';
            $page_type = (string) ($row['page_type'] ?? 'product');
            if (!in_array($page_type, ['product', 'cart', 'checkout', 'pages'], true)) {
                $page_type = 'product';
            }
            $page_ids = isset($row['page_ids']) && is_array($row['page_ids'])
                ? array_filter(array_map('absint', $row['page_ids']))
                : [];
            $rules[] = [
                'key' => $key,
                'public_id' => $public_id,
                'enabled' => !empty($row['enabled']) ? 'yes' : 'no',
                'placement' => $placement,
                'page_type' => $page_type,
                'page_ids' => implode(',', array_unique($page_ids)),
                'personalized' => !empty($row['personalized']) ? 'yes' : 'no',
                'use_cart_value' => !empty($row['use_cart_value']) ? 'yes' : 'no',
            ];
        }

        update_option(self::OPTION_KEY, ['tenant_id' => $tenant_id, 'rules' => $rules]);
        wp_safe_redirect(add_query_arg(
            'updated', $invalid_tenant ? 'invalid-tenant' : 'true',
            admin_url('admin.php?page=loyalty-engage-personalizations')
        ));
        exit;
    }

    public static function render_admin_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $settings = self::get_settings();
        ?>
        <div class="wrap">
            <h1>Loyalty Engage-personalisaties</h1>
            <?php if (isset($_GET['updated']) && $_GET['updated'] === 'true') : ?><div class="notice notice-success"><p>Personalisaties opgeslagen.</p></div><?php endif; ?>
            <?php if (isset($_GET['updated']) && $_GET['updated'] === 'invalid-tenant') : ?><div class="notice notice-error"><p>Geen geldige tenant-ID gevonden. De vorige tenant-ID is behouden. Plak de officiële loader-snippet of vul alleen de UUID in.</p></div><?php endif; ?>
            <?php if ($settings['tenant_id'] === '' && array_filter($settings['rules'], static fn (array $rule): bool => $rule['enabled'] === 'yes')) : ?><div class="notice notice-warning"><p>Voeg de Loyalty Engage-loader-snippet of tenant-ID toe om actieve personalisaties te tonen.</p></div><?php endif; ?>
            <p>Gebruik de public ID uit Loyalty Engage. Bij een ingestelde tenant en actieve regels laadt het basisscript eenmaal in de head van deze shop. Triggers worden alleen uitgevoerd voor de geplaatste personalisatieblokken. De bestaande loyaltyshop-personalisatie-instellingen staan los van deze functie.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="lew_save_personalizations">
                <?php wp_nonce_field('lew_save_personalizations'); ?>
                <table class="form-table" role="presentation"><tr>
                    <th scope="row"><label for="lew-personalization-tenant-id">Loyalty Engage-basisscript</label></th>
                    <td><textarea id="lew-personalization-tenant-id" name="lew_personalizations[tenant_id]" class="large-text code" rows="4" placeholder="Plak de volledige &lt;script&gt;-snippet of de tenant-UUID"><?php echo esc_textarea($settings['tenant_id']); ?></textarea>
                    <p class="description">Plak de tenantgebonden loader-snippet uit Loyalty Engage of vul alleen de tenant-UUID in. Alleen de ID wordt opgeslagen; de plugin laadt automatisch https://le-cloud.app/f/t/&lt;tenant-ID&gt;.js.</p></td>
                </tr></table>
                <h2>Personalisaties</h2>
                <div id="lew-personalization-rules">
                    <?php foreach ($settings['rules'] as $index => $rule) : self::render_rule_fields($rule, (string) $index); endforeach; ?>
                </div>
                <p><button type="button" class="button" id="lew-add-personalization">Personalisatie toevoegen</button></p>
                <?php submit_button('Personalisaties opslaan'); ?>
            </form>
            <template id="lew-personalization-template"><?php self::render_rule_fields([], '__INDEX__'); ?></template>
            <script>
            (function () {
              var list = document.getElementById('lew-personalization-rules');
              var template = document.getElementById('lew-personalization-template');
              document.getElementById('lew-add-personalization').addEventListener('click', function () {
                var index = String(Date.now()) + String(Math.floor(Math.random() * 1000));
                list.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', index));
              });
              list.addEventListener('click', function (event) {
                if (event.target.matches('[data-lew-remove-rule]')) {
                  event.target.closest('.lew-personalization-rule').remove();
                }
                if (event.target.matches('[data-lew-copy-shortcode]') && navigator.clipboard) {
                  var shortcode = event.target.previousElementSibling.textContent;
                  navigator.clipboard.writeText(shortcode).then(function () {
                    event.target.textContent = 'Gekopieerd';
                  });
                }
              });
            })();
            </script>
        </div>
        <?php
    }

    private static function render_rule_fields(array $rule, string $index): void
    {
        $name = 'lew_personalizations[rules][' . $index . ']';
        $key = (string) ($rule['key'] ?? '');
        ?>
        <fieldset class="lew-personalization-rule" style="margin: 1em 0; padding: 1em; border: 1px solid #c3c4c7; background: #fff;">
            <legend><strong>Personalisatie</strong></legend>
            <p><label>Naam voor shortcode<br><input class="regular-text" name="<?php echo esc_attr($name); ?>[key]" value="<?php echo esc_attr($key); ?>" placeholder="pdp-punten" required></label>
            <label>Public ID in Loyalty Engage<br><input class="regular-text" name="<?php echo esc_attr($name); ?>[public_id]" value="<?php echo esc_attr($rule['public_id'] ?? ''); ?>" required></label></p>
            <?php if ($key !== '') : ?><p>Shortcode: <code>[loyalty_engage_personalization id="<?php echo esc_attr($key); ?>"]</code> <button type="button" class="button" data-lew-copy-shortcode>Kopieer shortcode</button></p><?php endif; ?>
            <p><label><input type="checkbox" name="<?php echo esc_attr($name); ?>[enabled]" value="1" <?php checked($rule['enabled'] ?? 'no', 'yes'); ?>> Actief</label>
            <label><input type="checkbox" name="<?php echo esc_attr($name); ?>[personalized]" value="1" <?php checked($rule['personalized'] ?? 'no', 'yes'); ?>> Klantgegevens gebruiken</label>
            <label><input type="checkbox" name="<?php echo esc_attr($name); ?>[use_cart_value]" value="1" <?php checked($rule['use_cart_value'] ?? 'no', 'yes'); ?>> CartValue meesturen en bijwerken</label></p>
            <p><label>Plaatsing <select name="<?php echo esc_attr($name); ?>[placement]">
                <option value="shortcode" <?php selected($rule['placement'] ?? 'shortcode', 'shortcode'); ?>>Alleen shortcode</option>
                <option value="auto" <?php selected($rule['placement'] ?? 'shortcode', 'auto'); ?>>Automatisch</option>
            </select></label>
            <label>Pagina's <select name="<?php echo esc_attr($name); ?>[page_type]">
                <option value="product" <?php selected($rule['page_type'] ?? 'product', 'product'); ?>>Alle productpagina's</option>
                <option value="cart" <?php selected($rule['page_type'] ?? '', 'cart'); ?>>Winkelwagen</option>
                <option value="checkout" <?php selected($rule['page_type'] ?? '', 'checkout'); ?>>Afrekenen</option>
                <option value="pages" <?php selected($rule['page_type'] ?? '', 'pages'); ?>>Geselecteerde pagina's</option>
            </select></label>
            <label>Geselecteerde pagina's <select name="<?php echo esc_attr($name); ?>[page_ids][]" multiple size="4">
                <?php $selected_pages = array_map('intval', explode(',', (string) ($rule['page_ids'] ?? ''))); ?>
                <?php foreach (get_pages(['sort_column' => 'post_title']) as $page) : ?>
                    <option value="<?php echo esc_attr((string) $page->ID); ?>" <?php selected(in_array((int) $page->ID, $selected_pages, true)); ?>><?php echo esc_html($page->post_title); ?></option>
                <?php endforeach; ?>
            </select></label></p>
            <p class="description">Bij shortcodeplaatsing bepaalt de shortcode de locatie; de paginakeuze geldt voor automatische plaatsing. CartValue werkt alleen correct wanneer de winkelvaluta gelijk is aan de standaardvaluta van de Loyalty Engage-organisatie.</p>
            <button type="button" class="button-link-delete" data-lew-remove-rule>Verwijderen</button>
        </fieldset>
        <?php
    }

    public static function render_inline_shortcodes(string $content): string
    {
        if (!str_contains($content, '[loyalty_engage_personalization')) {
            return $content;
        }
        // Process only our shortcode in contexts that do not normally expand shortcodes.
        return preg_replace_callback('/' . get_shortcode_regex(['loyalty_engage_personalization']) . '/s', 'do_shortcode_tag', $content);
    }

    public static function shortcode(array $atts = []): string
    {
        if (!LEW_Settings::is_module_enabled()) {
            return '';
        }
        $atts = shortcode_atts(['id' => ''], $atts, 'loyalty_engage_personalization');
        $key = sanitize_title((string) $atts['id']);
        foreach (self::get_settings()['rules'] as $rule) {
            if ($rule['key'] === $key && $rule['enabled'] === 'yes') {
                self::enqueue_scripts();
                return self::container($key);
            }
        }
        return '';
    }

    public static function enqueue_for_auto_pages(): void
    {
        if (!LEW_Settings::is_module_enabled()) {
            return;
        }
        foreach (self::get_settings()['rules'] as $rule) {
            if (self::applies_automatically($rule)) {
                self::enqueue_scripts();
                return;
            }
        }
    }

    public static function render_product_auto(): void
    {
        if (!is_product()) {
            return;
        }
        foreach (self::get_settings()['rules'] as $rule) {
            if (self::applies_automatically($rule) && $rule['page_type'] === 'product') {
                echo self::container($rule['key']);
            }
        }
    }

    public static function append_page_auto(string $content): string
    {
        if (!is_main_query() || !in_the_loop() || !is_singular('page')) {
            return $content;
        }
        foreach (self::get_settings()['rules'] as $rule) {
            if (self::applies_automatically($rule) && $rule['page_type'] !== 'product' && empty(self::$rendered[$rule['key']])) {
                $content .= self::container($rule['key']);
            }
        }
        return $content;
    }

    private static function applies_automatically(array $rule): bool
    {
        if (!LEW_Settings::is_module_enabled() || $rule['enabled'] !== 'yes' || $rule['placement'] !== 'auto') {
            return false;
        }
        switch ($rule['page_type']) {
            case 'product': return is_product();
            case 'cart': return is_cart();
            case 'checkout': return is_checkout() && !is_order_received_page();
            case 'pages': return is_page(array_map('intval', explode(',', $rule['page_ids'])));
        }
        return false;
    }

    private static function container(string $key): string
    {
        $index = self::$rendered[$key] ?? 0;
        self::$rendered[$key] = $index + 1;
        $id = 'lew-personalization-' . $key . ($index ? '-' . $index : '');
        return '<div id="' . esc_attr($id) . '" class="lew-personalization-slot" data-lew-personalization="' . esc_attr($key) . '"></div>';
    }

    public static function enqueue_base_script(): bool
    {
        if (!LEW_Settings::is_module_enabled()) {
            return false;
        }
        $settings = self::get_settings();
        $active_rules = array_filter($settings['rules'], static fn (array $rule): bool => ($rule['enabled'] ?? 'no') === 'yes');
        if ($settings['tenant_id'] === '' || !$active_rules) {
            return false;
        }
        // Load before content rendering: shortcode/page-builder slots may appear after wp_head.
        if (!wp_script_is('lew-personalization-base', 'registered')) {
            $base_url = 'https://le-cloud.app/f/t/' . $settings['tenant_id'] . '.js';
            wp_register_script('lew-personalization-base', $base_url, [], null, false);
            wp_add_inline_script('lew-personalization-base', 'window._lecloud=window._lecloud||{event:[],personalization:[]};window._lecloud.event=window._lecloud.event||[];window._lecloud.personalization=window._lecloud.personalization||[];', 'before');
        }
        wp_enqueue_script('lew-personalization-base');
        return true;
    }

    private static function enqueue_scripts(): void
    {
        if (self::$scripts_enqueued) {
            return;
        }
        $settings = self::get_settings();
        if (!self::enqueue_base_script()) {
            return;
        }
        self::$scripts_enqueued = true;
        wp_enqueue_script('lew-storefront-bridge');
        wp_register_script('lew-personalizations', LEW_PLUGIN_URL . 'assets/js/personalizations.js', ['lew-storefront-bridge', 'lew-personalization-base'], LEW_PLUGIN_VERSION, true);
        $rules = array_values(array_filter($settings['rules'], static fn (array $rule): bool => $rule['enabled'] === 'yes'));
        wp_add_inline_script('lew-personalizations', 'window.lewPersonalizationRules=' . wp_json_encode($rules, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';', 'before');
        wp_enqueue_script('lew-personalizations');
    }

    public static function module_script_tag(string $tag, string $handle): string
    {
        if ($handle !== 'lew-personalization-base') {
            return $tag;
        }
        // WordPress includes inline-before/after tags here. Only the external engine is a module.
        return preg_replace_callback('~<script\b(?=[^>]*\bsrc\s*=)[^>]*>~i', static function (array $match): string {
            $external = preg_replace('~\s+type=(["\']).*?\1~i', '', $match[0]);
            $external = preg_replace('~\s+async(?:=(["\']).*?\1)?(?=\s|>)~i', '', $external);
            return preg_replace('~<script\b~i', '<script type="module" async', $external, 1);
        }, $tag);
    }
}
