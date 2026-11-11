<?php

define('ABSPATH', __DIR__);
function wp_strip_all_tags($value) { return strip_tags($value); }
function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function get_option($key, $default) { return $default; }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
function sanitize_textarea_field($value) { return trim(strip_tags($value)); }
function esc_url_raw($value) { return $value; }
function untrailingslashit($value) { return rtrim($value, '/'); }
function absint($value) { return abs((int) $value); }
require_once dirname(__DIR__) . '/includes/class-lew-settings.php';
require_once dirname(__DIR__) . '/includes/class-lew-storefront.php';

$settings = LEW_Settings::sanitize([
    'levels_title' => 'Mijn titel',
    'levels_intro' => '',
    'rewards_title' => 'Onze cadeaus',
    'rewards_intro' => 'Je saldo: {{coins}} coins',
    'loyalty_custom_css' => '#lew-loyalty-page > .lew-section { color: red; }',
]);
foreach (['levels_title' => 'Mijn titel', 'levels_intro' => '', 'rewards_title' => 'Onze cadeaus', 'rewards_intro' => 'Je saldo: {{coins}} coins'] as $key => $expected) {
    if ($settings[$key] !== $expected) {
        throw new RuntimeException('Appearance setting did not survive sanitization: ' . $key);
    }
}
if (!str_contains($settings['loyalty_custom_css'], '> .lew-section')) {
    throw new RuntimeException('CSS child selectors must be preserved.');
}
if (str_contains(LEW_Settings::sanitize_custom_css('</style><script>alert(1)</script>'), '<')) {
    throw new RuntimeException('CSS must not allow closing the style element.');
}
if (LEW_Storefront::render_section_heading('', '') !== '') {
    throw new RuntimeException('Empty section text must be hidden.');
}
$html = LEW_Storefront::render_section_heading('<script>alert(1)</script>', "Line 1\nLine 2");
if (str_contains($html, '<script>') || !str_contains($html, '<br')) {
    throw new RuntimeException('Section text must escape HTML and preserve line breaks.');
}
if (LEW_Settings::get_settings()['levels_title'] !== 'Jouw titel') {
    throw new RuntimeException('Existing installs must receive default appearance settings.');
}
echo "Appearance settings and output tests passed.\n";
