<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\WebhookSource;
use App\Http\Controllers\Controller;
use App\Http\Middleware\LogWebhookRequest;
use App\Models\Shop;
use App\Webhooks\Verification\InvalidWebhookSignature;
use App\Webhooks\Verification\RechargeSignatureVerifier;
use App\Webhooks\WebhookInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives Recharge webhooks at /webhooks/recharge/{shop uuid}/{topic}.
 *
 * Recharge sends no topic header and no event id, and each store signs with its
 * own API client secret. So we register one URL per shop and topic: the URL tells
 * us which secret to verify with and which topic the body belongs to.
 */
class RechargeWebhookController extends Controller
{
    /**
     * Headers kept with the stored event for auditing and debugging. Signatures are not kept.
     */
    private const STORED_HEADERS = [
        'x-recharge-webhook-timestamp', 'x-recharge-external-platform-domain', 'x-recharge-topic', 'user-agent',
    ];

    public function __invoke(
        Request $request,
        string $shopUuid,
        string $topic,
        RechargeSignatureVerifier $verifier,
        WebhookInbox $inbox,
    ): JsonResponse {
        LogWebhookRequest::addContext($request, [
            'source' => WebhookSource::Recharge->value,
            'topic' => $topic,
            'shop_uuid' => $shopUuid,
        ]);

        $shop = Shop::where('uuid', $shopUuid)->first();

        if ($shop === null) {
            LogWebhookRequest::addContext($request, ['outcome' => 'rejected', 'reason' => 'Unknown shop.']);

            abort(404);
        }

        $rawBody = $request->getContent();

        try {
            $verifier->verify(
                $rawBody,
                $shop->recharge_webhook_secret,
                $request->header('X-Recharge-Webhook-Signature'),
                $request->header('X-Recharge-Hmac-Sha256'),
            );
        } catch (InvalidWebhookSignature $exception) {
            LogWebhookRequest::addContext($request, ['outcome' => 'rejected', 'reason' => $exception->getMessage()]);

            abort(401, 'Invalid signature.');
        }

        $event = $inbox->accept(
            source: WebhookSource::Recharge,
            topic: $topic,
            shop: $shop,
            dedupKey: $this->dedupKey($topic, $rawBody),
            externalEventId: null,
            rawBody: $rawBody,
            headers: collect(self::STORED_HEADERS)
                ->mapWithKeys(fn (string $name): array => [$name => $request->header($name)])
                ->all(),
        );

        $outcome = $event->wasRecentlyCreated ? 'accepted' : 'duplicate';

        LogWebhookRequest::addContext($request, ['outcome' => $outcome, 'webhook_event_id' => $event->id]);

        return response()->json(['status' => $outcome]);
    }

    /**
     * Recharge has no event id. A retry resends the same resource state, so the
     * topic plus the resource's id and updated_at identify a delivery. A genuinely
     * new change to the resource bumps updated_at and is stored as a new event.
     */
    private function dedupKey(string $topic, string $rawBody): string
    {
        $decoded = json_decode($rawBody, true);
        $resource = is_array($decoded) && count($decoded) === 1 ? reset($decoded) : null;

        if (is_array($resource) && isset($resource['id'])) {
            return hash('sha256', implode('|', [$topic, $resource['id'], $resource['updated_at'] ?? '']));
        }

        return hash('sha256', $topic.'|'.$rawBody);
    }
}
