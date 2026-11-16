<?php

namespace App\Enums;

enum OrderOrigin: string
{
    /** The customer placed the order through the storefront checkout. */
    case Checkout = 'checkout';

    /** Recharge placed the order on a subscription's schedule. */
    case RechargeRecurring = 'recharge_recurring';
}
