<?php

define('ABSPATH', __DIR__);
define('LEW_PLUGIN_URL', '/plugin/');
define('LEW_PLUGIN_VERSION', 'test');
class LEW_Settings {
    public static bool $enabled = true;
    public static function is_module_enabled(): bool { return self::$enabled; }
}
$settings = ['tenant_id' => '9e8f3289-46bc-46cd-8e89-340640053a87', 'rules' => []];
$registered = [];
$inline = [];
$enqueued = [];
$hooks = [];
function get_option($key, $default) { return $GLOBALS['settings']; }
function get_network_option($network, $key, $default) { return $GLOBALS['settings']; }
function get_current_network_id() { return 1; }
function get_current_blog_id() { return 2; }
function wp_parse_args($value, $defaults) { return array_merge($defaults, $value); }
function wp_script_is($handle, $status) { return isset($GLOBALS['registered'][$handle]); }
function wp_register_script($handle, $url, $deps, $version, $footer) { $GLOBALS['registered'][$handle] = compact('url', 'deps', 'footer'); }
function wp_add_inline_script($handle, $code, $position) { $GLOBALS['inline'][$handle][] = $code; }
function wp_enqueue_script($handle) { $GLOBALS['enqueued'][$handle] = true; }
function shortcode_atts($defaults, $atts, $tag) { return array_merge($defaults, $atts); }
function sanitize_title($value) { return $value; }
function esc_attr($value) { return htmlspecialchars($value, ENT_QUOTES); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function add_action($name, $callback, $priority = 10) { $GLOBALS['hooks'][$name][$priority][] = $callback; }
function add_filter($name, $callback, $priority = 10, $args = 1) {}
function add_shortcode($name, $callback) {}
require_once dirname(__DIR__) . '/includes/class-lew-personalizations.php';

LEW_Personalizations::boot();
if (!isset($hooks['wp_enqueue_scripts'][20])) { throw new RuntimeException('Head loader must be registered before automatic placement.'); }
if (LEW_Personalizations::enqueue_base_script()) { throw new RuntimeException('No active rules must not load the engine.'); }
$settings['rules'] = [['key' => 'test', 'public_id' => 'bp-test', 'enabled' => 'yes', 'placement' => 'shortcode', 'site_ids' => [2]]];
LEW_Settings::$enabled = false;
if (LEW_Personalizations::enqueue_base_script()) { throw new RuntimeException('Disabled module must not load the engine.'); }
LEW_Settings::$enabled = true;
LEW_Personalizations::enqueue_base_script();
LEW_Personalizations::enqueue_base_script();
if (($registered['lew-personalization-base']['footer'] ?? true) !== false || count($inline['lew-personalization-base']) !== 1) {
    throw new RuntimeException('The engine and its queue bootstrap must load once in the head.');
}
if (isset($registered['lew-personalizations'])) { throw new RuntimeException('Head preload must not trigger personalizations on unrelated pages.'); }
$html = LEW_Personalizations::shortcode(['id' => 'test']);
if (!str_contains($html, 'data-lew-personalization="test"') || !$registered['lew-personalizations']['footer']) {
    throw new RuntimeException('A late shortcode must still render its slot and enqueue the footer trigger.');
}
if (count($inline['lew-personalization-base']) !== 1) { throw new RuntimeException('Late shortcode must not duplicate queue initialization.'); }
echo "Personalization head preload and late-shortcode tests passed.\n";
