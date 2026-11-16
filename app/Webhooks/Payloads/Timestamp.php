<?php

namespace App\Webhooks\Payloads;

use Carbon\CarbonImmutable;

/**
 * Parses platform timestamps. Shopify and Recharge send ISO 8601 with an offset;
 * a missing offset is treated as UTC.
 */
final class Timestamp
{
    public static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value, 'UTC')->utc();
    }
}
