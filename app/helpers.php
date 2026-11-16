<?php

if (! function_exists('peso')) {
    function peso($amount): string
    {
        return '₱'.number_format((float) $amount, 2);
    }
}

if (! function_exists('track_expiry')) {
    /**
     * Whether expiry dates are asked for and shown (switch on the POS style page, off unless turned on).
     * Off only hides them: dates already saved are kept and come back when it is turned on again.
     */
    function track_expiry(): bool
    {
        return \App\Models\Setting::get('track_expiry', '0') === '1';
    }
}

if (! function_exists('qty')) {
    /** 12.500 -> "12.5", 3.000 -> "3" */
    function qty($amount): string
    {
        return rtrim(rtrim(number_format((float) $amount, 3, '.', ','), '0'), '.');
    }
}
