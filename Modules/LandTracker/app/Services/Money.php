<?php

namespace Modules\LandTracker\Services;

// "BZ$1,250.00" in the company's currency
class Money
{
    private const SYMBOLS = ['BZD' => 'BZ$', 'USD' => 'US$', 'MXN' => 'MX$', 'GTQ' => 'Q', 'EUR' => '€', 'GBP' => '£', 'CAD' => 'CA$'];

    public static function format($amount, ?string $currency): string
    {
        $currency = strtoupper($currency ?: 'BZD');
        $symbol = self::SYMBOLS[$currency] ?? ($currency . ' ');

        return $symbol . number_format((float) $amount, 2);
    }
}
