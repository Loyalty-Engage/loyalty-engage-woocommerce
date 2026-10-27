<?php

if (!defined('ABSPATH')) {
    exit;
}

class LEW_Updater
{
    private const UPDATE_URI = 'https://github.com/Loyalty-Engage/loyalty-engage-woocommerce';
    private const RELEASE_API = 'https://api.github.com/repos/Loyalty-Engage/loyalty-engage-woocommerce/releases/latest';
    private const RELEASE_ASSET = 'loyalty-engage-woocommerce.zip';
    private const CACHE_KEY = 'lew_latest_github_release';
    private const CACHE_TTL = 6 * HOUR_IN_SECONDS;
    private const SLUG = 'loyalty-engage-woocommerce';

    public static function boot(): void
    {
        add_filter('update_plugins_github.com', [self::class, 'check_for_update'], 10, 4);
        add_filter('plugins_api', [self::class, 'plugin_information'], 20, 3);
    }

    public static function check_for_update($update, array $plugin_data, string $plugin_file, array $locales)
    {
        unset($plugin_data, $locales);

        if ($plugin_file !== plugin_basename(LEW_PLUGIN_FILE)) {
            return $update;
        }

        $release = self::get_latest_release();
        if (!$release) {
            return false;
        }

        return [
            'id' => self::UPDATE_URI,
            'slug' => self::SLUG,
            'version' => $release['version'],
            'url' => $release['html_url'],
            'package' => $release['package'],
            'requires' => '6.5',
            'requires_php' => '8.1',
        ];
    }

    public static function plugin_information($result, string $action, $args)
    {
        if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? '') !== self::SLUG) {
            return $result;
        }

        $release = self::get_latest_release();
        if (!$release) {
            return $result;
        }

        return (object) [
            'name' => 'Loyalty Engage for WooCommerce',
            'slug' => self::SLUG,
            'version' => $release['version'],
            'author' => '<a href="https://loyaltyengage.nl/">Loyalty Engage B.V.</a>',
            'homepage' => self::UPDATE_URI,
            'requires' => '6.5',
            'requires_php' => '8.1',
            'last_updated' => $release['published_at'],
            'download_link' => $release['package'],
            'sections' => [
                'description' => '<p>Loyalty Engage integration for WooCommerce.</p>',
                'changelog' => wpautop(esc_html($release['body'] ?: 'Bekijk de GitHub-release voor de wijzigingen in deze versie.')),
            ],
        ];
    }

    private static function get_latest_release(): ?array
    {
        $cached = get_site_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return !empty($cached['available']) ? $cached : null;
        }

        $response = wp_remote_get(self::RELEASE_API, [
            'timeout' => 10,
            'redirection' => 3,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'Loyalty-Engage-WooCommerce/' . LEW_PLUGIN_VERSION,
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_site_transient(self::CACHE_KEY, ['available' => false], HOUR_IN_SECONDS);
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || !empty($data['draft']) || !empty($data['prerelease'])) {
            set_site_transient(self::CACHE_KEY, ['available' => false], HOUR_IN_SECONDS);
            return null;
        }

        $version = ltrim((string) ($data['tag_name'] ?? ''), 'vV');
        $package = self::find_release_asset(isset($data['assets']) && is_array($data['assets']) ? $data['assets'] : []);
        if (!preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version) || $package === '') {
            set_site_transient(self::CACHE_KEY, ['available' => false], HOUR_IN_SECONDS);
            return null;
        }

        $release = [
            'available' => true,
            'version' => $version,
            'html_url' => esc_url_raw((string) ($data['html_url'] ?? self::UPDATE_URI . '/releases')),
            'package' => esc_url_raw($package),
            'published_at' => sanitize_text_field((string) ($data['published_at'] ?? '')),
            'body' => sanitize_textarea_field((string) ($data['body'] ?? '')),
        ];

        set_site_transient(self::CACHE_KEY, $release, self::CACHE_TTL);
        return $release;
    }

    private static function find_release_asset(array $assets): string
    {
        foreach ($assets as $asset) {
            if (!is_array($asset) || ($asset['name'] ?? '') !== self::RELEASE_ASSET) {
                continue;
            }

            return (string) ($asset['browser_download_url'] ?? '');
        }

        return '';
    }
}
