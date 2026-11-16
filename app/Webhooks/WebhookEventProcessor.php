<?php

namespace App\Webhooks;

use App\Enums\WebhookEventStatus;
use App\Enums\WebhookSource;
use App\Models\WebhookEvent;
use App\Webhooks\Handlers\RechargeOrderHandler;
use App\Webhooks\Handlers\RechargeSubscriptionHandler;
use App\Webhooks\Handlers\ShopifyOrderHandler;
use App\Webhooks\Handlers\WebhookHandler;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Routes a stored event to its handler and records the outcome. The handler runs
 * in a transaction, so a failure leaves no partial writes and the event can be retried.
 */
class WebhookEventProcessor
{
    /**
     * @var array<string, array<string, class-string<WebhookHandler>>>
     */
    private const HANDLERS = [
        'shopify' => [
            'orders/create' => ShopifyOrderHandler::class,
            'orders/updated' => ShopifyOrderHandler::class,
            'orders/cancelled' => ShopifyOrderHandler::class,
        ],
        'recharge' => [
            'subscription/created' => RechargeSubscriptionHandler::class,
            'subscription/updated' => RechargeSubscriptionHandler::class,
            'subscription/activated' => RechargeSubscriptionHandler::class,
            'subscription/cancelled' => RechargeSubscriptionHandler::class,
            'subscription/expired' => RechargeSubscriptionHandler::class,
            'order/created' => RechargeOrderHandler::class,
            'order/processed' => RechargeOrderHandler::class,
            'order/updated' => RechargeOrderHandler::class,
            'order/success' => RechargeOrderHandler::class,
            'order/cancelled' => RechargeOrderHandler::class,
        ],
    ];

    public function __construct(private readonly Container $container) {}

    public function process(WebhookEvent $event): void
    {
        if (in_array($event->status, [WebhookEventStatus::Processed, WebhookEventStatus::Ignored], true)) {
            return;
        }

        $startedAt = hrtime(true);
        $event->markProcessing();

        $handlerClass = self::HANDLERS[$event->source->value][$event->topic] ?? null;

        if ($handlerClass === null) {
            $event->markFinished(WebhookEventStatus::Ignored, "No handler for topic [{$event->topic}].", $this->elapsedMilliseconds($startedAt));

            return;
        }

        try {
            /** @var HandlerResult $result */
            $result = DB::transaction(fn (): HandlerResult => $this->container->make($handlerClass)->handle($event));
        } catch (Throwable $exception) {
            $event->recordAttemptFailure($exception, $this->elapsedMilliseconds($startedAt));

            throw $exception;
        }

        $event->markFinished($result->status, $result->note, $this->elapsedMilliseconds($startedAt));
    }

    private function elapsedMilliseconds(int|float $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    /**
     * @return array<int, string>
     */
    public static function supportedTopics(WebhookSource $source): array
    {
        return array_keys(self::HANDLERS[$source->value]);
    }
}
