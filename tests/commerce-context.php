<?php

define('ABSPATH', __DIR__);
$page = 'thankyou';
$user_id = 7;
$order_id = 42;
$_GET['key'] = 'valid-order-key';
class WC_Order {
    public int $customer = 7;
    public function get_order_key() { return 'valid-order-key'; }
    public function get_customer_id() { return $this->customer; }
    public function get_currency() { return 'EUR'; }
    public function get_items($type) {
        if ($type !== 'line_item') { throw new RuntimeException('Only discounted line items may count.'); }
        return [new class { public function get_total() { return 80; } }, new class { public function get_total() { return 20; } }];
    }
}
class WC_Cart { public float $total = 30; public function get_cart_contents_total() { return $this->total; } public function get_cart_contents_count() { return 1; } }
class WC_Product { public function is_type($type) { return false; } public function get_id() { return 9; } public function get_sku() { return 'TEST'; } public function get_type() { return 'simple'; } }
$order = new WC_Order();
$wc = (object) ['cart' => new WC_Cart()];
function WC() { return $GLOBALS['wc']; }
function is_order_received_page() { return $GLOBALS['page'] === 'thankyou'; }
function is_product() { return $GLOBALS['page'] === 'product'; }
function is_cart() { return $GLOBALS['page'] === 'cart'; }
function is_checkout() { return in_array($GLOBALS['page'], ['checkout', 'thankyou'], true); }
function get_query_var($key) { return $GLOBALS['order_id']; }
function get_queried_object_id() { return 9; }
function absint($value) { return abs((int) $value); }
function sanitize_text_field($value) { return strip_tags($value); }
function wp_unslash($value) { return $value; }
function wc_get_order($id) { return $id === 42 ? $GLOBALS['order'] : false; }
function wc_get_product($id) { return new WC_Product(); }
function wc_get_price_excluding_tax($product) { return 60; }
function is_user_logged_in() { return $GLOBALS['user_id'] > 0; }
function get_current_user_id() { return $GLOBALS['user_id']; }
function get_woocommerce_currency() { return 'USD'; }
function wc_get_price_decimals() { return 2; }
function apply_filters($hook, $value, ...$args) { return $value; }
require_once dirname(__DIR__) . '/includes/class-lew-storefront.php';
function expect_value(float $expected, string $message) {
    $context = LEW_Storefront::get_frontend_context();
    if ($context['cartValue'] !== $expected) { throw new RuntimeException($message); }
    return $context;
}
$context = expect_value(100.0, 'Thank-you value must use discounted order lines, not the live cart.');
if ($context['pageType'] !== 'thankyou' || $context['currency'] !== 'EUR' || $context['cartValueBasis'] !== 'discounted_order_items_excluding_tax') { throw new RuntimeException('Wrong order context metadata.'); }
$_GET['key'] = 'wrong';
expect_value(0.0, 'Invalid key must not expose an order.');
unset($_GET['key']);
expect_value(0.0, 'Missing key must not expose an order.');
$_GET['key'] = ['invalid'];
expect_value(0.0, 'Array keys must be rejected.');
$_GET['key'] = 'valid-order-key';
$user_id = 9;
expect_value(0.0, 'Another customer must not access an order.');
$user_id = 0;
expect_value(0.0, 'Anonymous visitors must not access registered-customer orders.');
$order->customer = 0;
expect_value(100.0, 'A guest order may use its valid secret order key.');
$order_id = 99;
expect_value(0.0, 'Nonexistent orders must not expose a value.');
$order_id = 42;
$page = 'other';
expect_value(0.0, 'Ordinary pages must remain zero.');
if (LEW_Storefront::get_frontend_context('thankyou')['cartValue'] !== 0.0) { throw new RuntimeException('A requested page type must not bypass the actual order-received check.'); }
$page = 'product';
expect_value(60.0, 'Product context must keep using the product price.');
$page = 'cart';
expect_value(30.0, 'Cart context must keep using discounted product totals.');
$page = 'checkout';
expect_value(30.0, 'Checkout context must keep using discounted product totals.');
$wc->cart->total = 0;
expect_value(0.0, 'Empty carts remain zero.');
echo "Commerce context and thank-you order access tests passed.\n";
class LEW_Settings { public static function is_module_enabled(): bool { return true; } }
require_once dirname(__DIR__) . '/includes/class-lew-personalizations.php';
$matches = new ReflectionMethod(LEW_Personalizations::class, 'applies_automatically');
$rule = ['enabled' => 'yes', 'placement' => 'auto', 'page_type' => 'thankyou'];
$page = 'thankyou';
if (!$matches->invoke(null, $rule)) { throw new RuntimeException('Thank-you placement must match order-received pages.'); }
if ($matches->invoke(null, array_replace($rule, ['page_type' => 'checkout']))) { throw new RuntimeException('Checkout-only rules must not leak onto thank-you pages.'); }
$page = 'checkout';
if ($matches->invoke(null, $rule)) { throw new RuntimeException('Thank-you rules must not render before an order exists.'); }
