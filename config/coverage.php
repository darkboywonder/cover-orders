<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Protection Line Item Matching
    |--------------------------------------------------------------------------
    |
    | A Shopify order is covered when one of its line items is the ShipInsure
    | protection product. Matching by SKU is preferred; vendor is a fallback
    | for stores where the protection variant has no SKU.
    |
    */

    'protection_skus' => array_filter(explode(',', (string) env('COVERAGE_PROTECTION_SKUS', 'SHIPINSURE-PROTECTION'))),

    'protection_vendors' => array_filter(explode(',', (string) env('COVERAGE_PROTECTION_VENDORS', 'ShipInsure'))),

    /*
    |--------------------------------------------------------------------------
    | Billable Recharge Order Statuses
    |--------------------------------------------------------------------------
    |
    | A recurring Recharge order only becomes billable once it has actually been
    | placed. Queued or skipped orders are recorded but never billed.
    |
    */

    'billable_recharge_order_statuses' => ['success'],

    /*
    |--------------------------------------------------------------------------
    | Stuck Event Threshold
    |--------------------------------------------------------------------------
    |
    | Inbox events still "received" after this many minutes are considered
    | stuck (for example the queue was down) and are re-dispatched.
    |
    */

    'stuck_event_minutes' => (int) env('COVERAGE_STUCK_EVENT_MINUTES', 10),

];
