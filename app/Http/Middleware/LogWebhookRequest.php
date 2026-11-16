<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes one structured line per webhook request, accepted or rejected, to the
 * "webhooks" log channel. Only metadata is logged: never the body, signatures or PII.
 * Controllers add context through the "webhook.log" request attribute.
 */
class LogWebhookRequest
{
    public const CONTEXT_ATTRIBUTE = 'webhook.log';

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);

        $response = $next($request);

        Log::channel('webhooks')->info('webhook.request', [
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
            'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
            ...$request->attributes->get(self::CONTEXT_ATTRIBUTE, []),
        ]);

        return $response;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function addContext(Request $request, array $context): void
    {
        $request->attributes->set(self::CONTEXT_ATTRIBUTE, [
            ...$request->attributes->get(self::CONTEXT_ATTRIBUTE, []),
            ...$context,
        ]);
    }
}
