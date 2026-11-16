<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Webhooks\WebhookEventProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $timeout = 60;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public WebhookEvent $event) {}

    public function handle(WebhookEventProcessor $processor): void
    {
        $processor->process($this->event);
    }

    /**
     * Exponential backoff (10s, 30s, 90s, 270s, 810s) with up to 25% random jitter,
     * so a burst of failures (for example a database blip) does not retry in lockstep.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return array_map(
            fn (int $attempt): int => (int) (10 * 3 ** $attempt * (1 + random_int(0, 25) / 100)),
            range(0, $this->tries - 2),
        );
    }

    /**
     * After the last attempt the event stays in the inbox as "failed": that is our dead
     * letter queue. `php artisan webhooks:replay --status=failed` re-runs it after a fix.
     */
    public function failed(?Throwable $exception): void
    {
        $this->event->fresh()?->markFailed($exception);
    }
}
