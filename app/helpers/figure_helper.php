<?php

if (!function_exists('format_inr')) {
    function format_inr($value): string
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

        return ($negative ? '-₹' : '₹') . $whole . '.' . $decimal;
    }
}
