<?php

/**
 * Indian number formatting helper.
 *
 * format_inr($value)        → "₹15,39,43,337.59"  (with rupee symbol)
 * format_inr_number($value) → "15,39,43,337.59"    (without rupee symbol)
 *
 * Indian comma rule: last 3 digits grouped once, remaining grouped by 2.
 */

if (!function_exists('format_inr_number')) {
    function format_inr_number($value): string
    {
        $amount = (float) $value;
        $negative = $amount < 0;
        $amount = abs($amount);

        $formatted = number_format($amount, 2, '.', '');
        [$whole, $decimal] = explode('.', $formatted);

        if (strlen($whole) > 3) {
            $lastThree = substr($whole, -3);
            $restUnits = substr($whole, 0, -3);
            $restUnits = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $restUnits);
            $whole = $restUnits . ',' . $lastThree;
        }

        return ($negative ? '-' : '') . $whole . '.' . $decimal;
    }
}

if (!function_exists('format_inr')) {
    function format_inr($value): string
    {
        $formatted = format_inr_number($value);
        $amount = (float) $value;
        return ($amount < 0 ? '-₹' : '₹') . ltrim($formatted, '-');
    }
}
