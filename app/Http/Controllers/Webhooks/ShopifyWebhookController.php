<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\WebhookSource;
use App\Http\Controllers\Controller;
use App\Http\Middleware\LogWebhookRequest;
use App\Models\Shop;
use App\Webhooks\Verification\InvalidWebhookSignature;
use App\Webhooks\Verification\ShopifySignatureVerifier;
use App\Webhooks\WebhookInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives every Shopify topic on one URL. Verify, store, enqueue, acknowledge:
 * Shopify allows five seconds, so no business logic runs here.
 */
class ShopifyWebhookController extends Controller
{
    /**
     * Headers kept with the stored event for auditing and debugging. The HMAC is not kept.
     */
    private const STORED_HEADERS = [
        'x-shopify-topic', 'x-shopify-shop-domain', 'x-shopify-webhook-id', 'x-shopify-event-id',
        'x-shopify-triggered-at', 'x-shopify-api-version',
    ];

    public function __invoke(Request $request, ShopifySignatureVerifier $verifier, WebhookInbox $inbox): JsonResponse
    {
        $topic = $request->header('X-Shopify-Topic');
        $shopDomain = $request->header('X-Shopify-Shop-Domain');
        $webhookId = $request->header('X-Shopify-Webhook-Id');

        LogWebhookRequest::addContext($request, [
            'source' => WebhookSource::Shopify->value,
            'topic' => $topic,
            'shop' => $shopDomain,
            'webhook_id' => $webhookId,
            'event_id' => $request->header('X-Shopify-Event-Id'),
        ]);

        $rawBody = $request->getContent();

        try {
            $verifier->verify($rawBody, $request->header('X-Shopify-Hmac-Sha256'));
        } catch (InvalidWebhookSignature $exception) {
            LogWebhookRequest::addContext($request, ['outcome' => 'rejected', 'reason' => $exception->getMessage()]);

            abort(401, 'Invalid signature.');
        }

        if (! $topic || ! $shopDomain) {
            LogWebhookRequest::addContext($request, ['outcome' => 'rejected', 'reason' => 'Missing topic or shop domain.']);

            abort(400, 'Missing X-Shopify-Topic or X-Shopify-Shop-Domain.');
        }

        // A correctly signed delivery proves the shop installed our app, so the shop can be created on first sight.
        $shop = Shop::firstOrCreate(['shopify_domain' => strtolower($shopDomain)]);

        $event = $inbox->accept(
            source: WebhookSource::Shopify,
            topic: $topic,
            shop: $shop,
            dedupKey: $webhookId ?: hash('sha256', $topic.'|'.$rawBody),
            externalEventId: $request->header('X-Shopify-Event-Id'),
            rawBody: $rawBody,
            headers: $this->storedHeaders($request),
        );

        $outcome = $event->wasRecentlyCreated ? 'accepted' : 'duplicate';

        LogWebhookRequest::addContext($request, ['outcome' => $outcome, 'webhook_event_id' => $event->id]);

        return response()->json(['status' => $outcome]);
    }

    /**
     * @return array<string, string|null>
     */
    private function storedHeaders(Request $request): array
    {
        return collect(self::STORED_HEADERS)
            ->mapWithKeys(fn (string $name): array => [$name => $request->header($name)])
            ->all();
    }
}
