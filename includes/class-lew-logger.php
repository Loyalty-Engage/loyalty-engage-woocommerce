<?php

if (!defined('ABSPATH')) {
    exit;
}

class LEW_Logger
{
    public static function log(string $level, string $message, array $context = []): void
    {
        if (!function_exists('wc_get_logger')) {
            return;
        }

        $settings = LEW_Settings::get_settings();
        if (($settings['logger_enabled'] ?? 'no') !== 'yes') {
            return;
        }

        $logger = wc_get_logger();
        $context['source'] = 'loyalty-engage-woocommerce';
        $logger->log($level, $message . self::format_context($context), $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    private static function format_context(array $context): string
    {
        unset($context['source']);
        if (!$context) {
            return '';
        }

        return ' | ' . wp_json_encode($context);
    }
}
