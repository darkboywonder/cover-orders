<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Webhook bodies carry customer data and signatures, so in production they must
 * arrive over TLS. Behind a load balancer this relies on trusted proxy headers
 * (TRUSTED_PROXIES) so that Request::secure() reflects the original scheme.
 */
class EnsureWebhookIsSecure
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isProduction() && ! $request->secure()) {
            abort(403, 'Webhooks must be delivered over HTTPS.');
        }

        return $next($request);
    }
}
