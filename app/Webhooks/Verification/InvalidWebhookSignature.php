<?php

namespace App\Webhooks\Verification;

use RuntimeException;

/**
 * Thrown when a webhook's signature is missing, malformed, stale or does not match.
 * The message is safe to log; it never contains the secret or the expected digest.
 */
class InvalidWebhookSignature extends RuntimeException {}
