<?php

namespace App\Enums;

enum WebhookSource: string
{
    case Shopify = 'shopify';
    case Recharge = 'recharge';
}
