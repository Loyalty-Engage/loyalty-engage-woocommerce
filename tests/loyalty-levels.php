<?php

define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__) . '/includes/class-lew-storefront.php';

$levels = LEW_Storefront::parse_loyalty_levels("Brons|0\nZilver|500\nGoud|1500\nGoud|2000\nOngeldig\nPlatina|abc");
if ($levels !== [
    ['name' => 'Brons', 'requiredPoints' => 0],
    ['name' => 'Zilver', 'requiredPoints' => 500],
    ['name' => 'Goud', 'requiredPoints' => 1500],
]) {
    throw new RuntimeException('Configured loyalty levels were not parsed correctly.');
}

echo "Loyalty level tests passed.\n";
