<?php

namespace App\Console\Commands;

use App\Enums\WebhookEventStatus;
use App\Enums\WebhookSource;
use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

#[Signature('webhooks:replay
    {--id=* : Replay specific webhook event ids (any status)}
    {--status=failed : Replay events with this status}
    {--source= : Only replay events from this source (shopify or recharge)}
    {--since= : Only replay events received at or after this date/time}
    {--stuck : Replay events stuck in received/processing longer than coverage.stuck_event_minutes}
    {--sync : Process in this process instead of queueing}')]
#[Description('Re-run stored webhook events through the same idempotent handlers')]
class ReplayWebhookEvents extends Command
{
    public function handle(): int
    {
        $events = $this->query()->orderBy('id')->get();

        if ($events->isEmpty()) {
            $this->components->info('No webhook events matched.');

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($events as $event) {
            $event->markForReplay();

            if (! $this->option('sync')) {
                ProcessWebhookEvent::dispatch($event);
                $this->components->twoColumnDetail("#{$event->id} {$event->source->value} {$event->topic}", 'queued');

                continue;
            }

            try {
                ProcessWebhookEvent::dispatchSync($event);
                $this->components->twoColumnDetail("#{$event->id} {$event->source->value} {$event->topic}", $event->fresh()->status->value);
            } catch (Throwable $exception) {
                $failures++;
                $this->components->twoColumnDetail("#{$event->id} {$event->source->value} {$event->topic}", '<fg=red>failed</> '.$exception->getMessage());
            }
        }

        $this->components->info("Replayed {$events->count()} event(s).");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return Builder<WebhookEvent>
     */
    private function query(): Builder
    {
        $query = WebhookEvent::query();

        if ($ids = $this->option('id')) {
            return $query->whereKey($ids);
        }

        if ($this->option('stuck')) {
            $query
                ->whereIn('status', [WebhookEventStatus::Received, WebhookEventStatus::Processing])
                ->where('updated_at', '<', now()->subMinutes((int) config('coverage.stuck_event_minutes')));
        } else {
            $query->where('status', WebhookEventStatus::from($this->option('status')));
        }

        if ($source = $this->option('source')) {
            $query->where('source', WebhookSource::from($source));
        }

        if ($since = $this->option('since')) {
            $query->where('received_at', '>=', CarbonImmutable::parse($since));
        }

        return $query;
    }
}
