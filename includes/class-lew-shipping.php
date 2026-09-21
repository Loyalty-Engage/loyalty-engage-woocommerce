<?php

if (!defined('ABSPATH')) {
    exit;
}

class LEW_Shipping
{
    public static function boot(): void
    {
        add_filter('woocommerce_package_rates', [self::class, 'maybe_apply_loyalty_free_shipping'], 20, 2);
    }

    public static function maybe_apply_loyalty_free_shipping(array $rates, array $package): array
    {
        if (!LEW_Settings::is_module_enabled()) {
            return $rates;
        }

        $settings = LEW_Settings::get_settings();
        if (($settings['free_shipping_enabled'] ?? 'no') !== 'yes') {
            return $rates;
        }

        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return $rates;
        }

        if (!self::customer_qualifies_for_free_shipping($user_id, $settings)) {
            return $rates;
        }

        foreach ($rates as $rate_id => $rate) {
            if (!$rate instanceof WC_Shipping_Rate) {
                continue;
            }

            $rates[$rate_id]->set_cost(0);

            $taxes = $rate->get_taxes();
            if (is_array($taxes) && $taxes) {
                $rates[$rate_id]->set_taxes(array_map(static function (): float {
                    return 0.0;
                }, $taxes));
            }
        }

        LEW_Logger::info('Applied loyalty tier free shipping', [
            'user_id' => $user_id,
            'tier' => self::get_customer_tier($user_id),
            'rate_count' => count($rates),
        ]);

        return $rates;
    }

    private static function customer_qualifies_for_free_shipping(int $user_id, array $settings): bool
    {
        $tier = self::get_customer_tier($user_id);
        if ($tier === '') {
            return false;
        }

        $configured_tiers = array_values(array_filter(array_map('trim', explode(',', (string) ($settings['free_shipping_tiers'] ?? '')))));
        if (!$configured_tiers) {
            return false;
        }

        $normalized_tier = strtolower($tier);
        foreach ($configured_tiers as $configured_tier) {
            if ($normalized_tier === strtolower($configured_tier)) {
                return true;
            }
        }

        return false;
    }

    private static function get_customer_tier(int $user_id): string
    {
        return trim((string) LEW_Storefront::get_customer_loyalty_value($user_id, 'lew_current_tier'));
    }
}
