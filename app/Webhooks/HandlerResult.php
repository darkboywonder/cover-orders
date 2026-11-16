<?php

namespace App\Webhooks;

use App\Enums\WebhookEventStatus;

final readonly class HandlerResult
{
    private function __construct(public WebhookEventStatus $status, public ?string $note = null) {}

    public static function processed(?string $note = null): self
    {
        return new self(WebhookEventStatus::Processed, $note);
    }

    public static function ignored(string $note): self
    {
        return new self(WebhookEventStatus::Ignored, $note);
    }
}
