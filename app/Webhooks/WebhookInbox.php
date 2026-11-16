<?php

namespace App\Webhooks;

use App\Enums\WebhookEventStatus;
use App\Enums\WebhookSource;
use App\Jobs\ProcessWebhookEvent;
use App\Models\Shop;
use App\Models\WebhookEvent;

/**
 * Persists verified deliveries before anything else happens. The unique
 * (source, dedup_key) index makes recording idempotent: a retried or duplicated
 * delivery returns the existing row instead of creating a second one.
 */
class WebhookInbox
{
    /**
     * Record the delivery and queue it for processing if it has not been seen before.
     * Check wasRecentlyCreated on the result to tell a new delivery from a duplicate.
     *
     * @param  array<string, string|null>  $headers
     */
    public function accept(
        WebhookSource $source,
        string $topic,
        ?Shop $shop,
        string $dedupKey,
        ?string $externalEventId,
        string $rawBody,
        array $headers,
    ): WebhookEvent {
        $event = $this->record($source, $topic, $shop, $dedupKey, $externalEventId, $rawBody, $headers);

        // If queueing fails after the row is stored, the event stays "received" and the
        // scheduled stuck-event sweep (webhooks:replay --stuck) dispatches it later.
        if ($event->wasRecentlyCreated) {
            ProcessWebhookEvent::dispatch($event);
        }

        return $event;
    }

    /**
     * @param  array<string, string|null>  $headers
     */
    public function record(
        WebhookSource $source,
        string $topic,
        ?Shop $shop,
        string $dedupKey,
        ?string $externalEventId,
        string $rawBody,
        array $headers,
    ): WebhookEvent {
        return WebhookEvent::createOrFirst(
            ['source' => $source, 'dedup_key' => $dedupKey],
            [
                'topic' => $topic,
                'shop_id' => $shop?->id,
                'external_event_id' => $externalEventId,
                'payload' => $rawBody,
                'headers' => $headers,
                'status' => WebhookEventStatus::Received,
                'received_at' => now(),
            ],
        );
    }
}
