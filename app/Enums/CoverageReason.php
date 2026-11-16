<?php

namespace App\Enums;

enum CoverageReason: string
{
    /** The order itself contains the ShipInsure protection line item. */
    case ProtectionLine = 'protection_line';

    /** The order was placed by a subscription whose original order was covered. */
    case SubscriptionInherited = 'subscription_inherited';
}
