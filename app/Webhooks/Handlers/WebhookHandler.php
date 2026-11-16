<?php

namespace App\Webhooks\Handlers;

use App\Models\WebhookEvent;
use App\Webhooks\HandlerResult;

/**
 * Applies one stored webhook event to the domain records. Implementations must be
 * idempotent: the same event may be handled more than once (retries, replays).
 */
interface WebhookHandler
{
    public function handle(WebhookEvent $event): HandlerResult;
}
