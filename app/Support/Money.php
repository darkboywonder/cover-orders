<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Converts the decimal strings Shopify and Recharge send ("12.50") into integer
 * minor units without going through floats. Assumes a two-decimal currency;
 * zero- and three-decimal currencies are listed as future work.
 */
final class Money
{
    public static function toCents(string|int|float|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        $normalized = is_string($amount) ? trim($amount) : number_format((float) $amount, 2, '.', '');

        if (! preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', $normalized, $matches)) {
            throw new InvalidArgumentException("Unparseable money amount [{$normalized}].");
        }

        $fraction = str_pad(substr($matches[3] ?? '', 0, 2), 2, '0');
        $cents = ((int) $matches[2]) * 100 + (int) $fraction;

        return $matches[1] === '-' ? -$cents : $cents;
    }

    public static function format(int $cents, ?string $currency = null): string
    {
        $formatted = number_format($cents / 100, 2, '.', ',');

        return $currency ? $formatted.' '.strtoupper($currency) : $formatted;
    }
}
