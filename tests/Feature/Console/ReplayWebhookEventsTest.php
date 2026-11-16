<?php

namespace Tests\Feature\Console;

use App\Enums\WebhookEventStatus;
use App\Jobs\ProcessWebhookEvent;
use App\Models\Shop;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SendsWebhooks;
use Tests\TestCase;

class ReplayWebhookEventsTest extends TestCase
{
    use LazilyRefreshDatabase, SendsWebhooks;

    public function test_event_that_keeps_failing_lands_in_the_dead_letter_state_and_replays_after_a_fix(): void
    {
        $shop = Shop::factory()->create();
        $event = WebhookEvent::factory()->for($shop)->create(['topic' => 'orders/create', 'payload' => '{"name":"#1001"}']);

        $this->artisan('webhooks:replay', ['--id' => [$event->id], '--sync' => true])->assertFailed();

        $event->refresh();
        $this->assertSame(WebhookEventStatus::Failed, $event->status);
        $this->assertStringContainsString('Shopify order payload has no id', $event->last_error);
        $this->assertDatabaseEmpty('coverages');

        $event->update(['payload' => json_encode($this->fixture('shopify/orders-create-covered'))]);

        $this->artisan('webhooks:replay', ['--status' => 'failed', '--sync' => true])->assertSuccessful();

        $this->assertSame(WebhookEventStatus::Processed, $event->refresh()->status);
        $this->assertDatabaseCount('coverages', 1);
    }

    public function test_replaying_an_already_processed_event_does_not_duplicate_coverage(): void
    {
        $shop = Shop::factory()->create();
        $this->postShopifyWebhook($shop, 'orders/create', $this->fixture('shopify/orders-create-covered'))->assertOk();
        $event = WebhookEvent::sole();

        $this->artisan('webhooks:replay', ['--id' => [$event->id], '--sync' => true])->assertSuccessful();

        $this->assertSame(WebhookEventStatus::Processed, $event->refresh()->status);
        $this->assertSame(2, $event->attempts);
        $this->assertDatabaseCount('coverages', 1);
    }

    public function test_stuck_events_are_redispatched_and_recent_ones_are_left_alone(): void
    {
        Queue::fake([ProcessWebhookEvent::class]);
        config(['coverage.stuck_event_minutes' => 10]);
        $stuck = WebhookEvent::factory()->create(['updated_at' => now()->subMinutes(11)]);
        WebhookEvent::factory()->create(['updated_at' => now()->subMinutes(2)]);
        WebhookEvent::factory()->failed()->create(['updated_at' => now()->subHour()]);

        $this->artisan('webhooks:replay', ['--stuck' => true])->assertSuccessful();

        Queue::assertPushedTimes(ProcessWebhookEvent::class, 1);
        Queue::assertPushed(ProcessWebhookEvent::class, fn (ProcessWebhookEvent $job): bool => $job->event->is($stuck));
    }
}
