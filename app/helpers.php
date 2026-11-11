<?php

if (! function_exists('peso')) {
    function peso($amount): string
    {
        return '₱'.number_format((float) $amount, 2);
    }
}

if (! function_exists('qty')) {
    /** 12.500 -> "12.5", 3.000 -> "3" */
    function qty($amount): string
    {
        return rtrim(rtrim(number_format((float) $amount, 3, '.', ','), '0'), '.');
    }
}
