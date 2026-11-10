<?php

define('ABSPATH', __DIR__);
define('HOUR_IN_SECONDS', 3600);
define('LEW_PLUGIN_VERSION', '1.5.14');
define('LEW_PLUGIN_FILE', 'loyalty-engage-woocommerce/loyalty-engage-woocommerce.php');
function plugin_basename($file) { return $file; }
function get_site_transient($key) { return false; }
function set_site_transient($key, $value, $ttl) {}
function wp_remote_get($url, $args) { return $GLOBALS['response']; }
function is_wp_error($value) { return false; }
function wp_remote_retrieve_response_code($response) { return $response['status']; }
function wp_remote_retrieve_body($response) { return json_encode($response['body']); }
function esc_url_raw($value) { return $value; }
function sanitize_text_field($value) { return $value; }
function sanitize_textarea_field($value) { return $value; }
require_once dirname(__DIR__) . '/includes/class-lew-updater.php';

$release = [
    'tag_name' => 'v1.5.15',
    'assets' => [['name' => 'loyalty-engage-woocommerce.zip', 'browser_download_url' => 'https://example.com/plugin.zip']],
];
$response = ['status' => 200, 'body' => $release];
$update = LEW_Updater::check_for_update(false, [], LEW_PLUGIN_FILE, []);
if ($update['version'] !== '1.5.15' || $update['package'] !== 'https://example.com/plugin.zip') {
    throw new RuntimeException('The previous plugin version must receive the installable release asset.');
}
if (LEW_Updater::check_for_update('unchanged', [], 'other/plugin.php', []) !== 'unchanged') {
    throw new RuntimeException('Other plugins must not be affected.');
}
foreach ([['draft' => true], ['prerelease' => true], ['assets' => []], ['tag_name' => 'invalid']] as $override) {
    $response['body'] = array_replace($release, $override);
    if (LEW_Updater::check_for_update(false, [], LEW_PLUGIN_FILE, []) !== false) {
        throw new RuntimeException('Invalid or unpublished release must not be offered.');
    }
}
$response = ['status' => 503, 'body' => $release];
if (LEW_Updater::check_for_update(false, [], LEW_PLUGIN_FILE, []) !== false) {
    throw new RuntimeException('Failed release lookup must not offer an update.');
}
echo "Updater release-selection tests passed.\n";
