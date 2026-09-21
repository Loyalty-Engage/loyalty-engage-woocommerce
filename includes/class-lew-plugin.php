<?php

if (!defined('ABSPATH')) {
    exit;
}

class LEW_Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        LEW_Settings::boot();
        LEW_Outbox::boot();
        LEW_Webhooks::boot();
        LEW_Rest_API::boot();
        LEW_Shipping::boot();
        LEW_Storefront::boot();
    }
}
