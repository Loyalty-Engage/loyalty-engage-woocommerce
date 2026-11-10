<?php

define('ABSPATH', __DIR__);

class WP_REST_Request {}

class WP_REST_Response
{
    public function __construct(public array $data, public int $status = 200) {}
}

require_once dirname(__DIR__) . '/includes/class-lew-rest-api.php';

$legacy_routes = [];
function register_rest_route($namespace, $route, $options)
{
    if (str_contains($route, '/redeem-points/')) {
        $GLOBALS['legacy_routes'][] = $options;
    }
}
LEW_Rest_API::register_routes();
if (count($legacy_routes) !== 4) {
    throw new RuntimeException('All four legacy routes must remain registered.');
}
foreach ($legacy_routes as $route) {
    if ($route['callback'] !== [LEW_Rest_API::class, 'legacy_points_redemption_disabled'] ||
        $route['permission_callback'] !== [LEW_Rest_API::class, 'can_access_customer_route']) {
        throw new RuntimeException('Legacy routes must reject redemption and retain authentication.');
    }
}

$response = LEW_Rest_API::legacy_points_redemption_disabled(new WP_REST_Request());
if ($response->status !== 410 || $response->data['success'] !== false) {
    throw new RuntimeException('Legacy points redemption must return HTTP 410.');
}

echo "Legacy points redemption is disabled.\n";
