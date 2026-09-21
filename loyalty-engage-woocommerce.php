<?php
/**
 * Plugin Name: Loyalty Engage for WooCommerce
 * Description: Loyalty Engage integration for WooCommerce with order export, returns, loyalty rewards, discount redemption and customer sync.
 * Version: 0.1.0
 * Author: Loyalty Engage B.V.
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Text Domain: loyalty-engage-woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

define('LEW_PLUGIN_VERSION', '0.1.0');
define('LEW_PLUGIN_FILE', __FILE__);
define('LEW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LEW_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once LEW_PLUGIN_DIR . 'includes/class-lew-activator.php';
require_once LEW_PLUGIN_DIR . 'includes/class-lew-loyalty-engage-client.php';
require_once LEW_PLUGIN_DIR . 'includes/class-lew-logger.php';
require_once LEW_PLUGIN_DIR . 'includes/class-lew-outbox.php';
require_once LEW_PLUGIN_DIR . 'includes/class-lew-settings.php';
require_once LEW_PLUGIN_DIR . 'includes/class-lew-rest-api.php';
require_once LEW_PLUGIN_DIR . 'includes/class-lew-shipping.php';
require_once LEW_PLUGIN_DIR . 'includes/class-lew-webhooks.php';
require_once LEW_PLUGIN_DIR . 'includes/class-lew-storefront.php';
require_once LEW_PLUGIN_DIR . 'includes/class-lew-plugin.php';

register_activation_hook(__FILE__, ['LEW_Activator', 'activate']);
register_deactivation_hook(__FILE__, ['LEW_Activator', 'deactivate']);

function lew_boot_plugin(): void
{
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>Loyalty Engage for WooCommerce requires WooCommerce to be active.</p></div>';
        });
        return;
    }

    LEW_Activator::maybe_upgrade();
    LEW_Plugin::instance()->boot();
}

add_action('plugins_loaded', 'lew_boot_plugin');
