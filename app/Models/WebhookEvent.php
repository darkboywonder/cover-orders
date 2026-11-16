<?php

namespace App\Models;

use App\Enums\WebhookEventStatus;
use App\Enums\WebhookSource;
use Database\Factories\WebhookEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Throwable;

/**
 * The webhook inbox. Every verified delivery is stored here, raw and encrypted,
 * before it is processed, so any event can be replayed through the same handlers.
 */
#[Fillable([
    'source', 'topic', 'shop_id', 'dedup_key', 'external_event_id', 'payload', 'headers',
    'status', 'attempts', 'last_error', 'received_at', 'processing_started_at', 'processed_at', 'processing_ms',
])]
#[Hidden(['payload'])]
class WebhookEvent extends Model
{
    /** @use HasFactory<WebhookEventFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => WebhookSource::class,
            'status' => WebhookEventStatus::class,
            'payload' => 'encrypted',
            'headers' => 'array',
            'received_at' => 'immutable_datetime',
            'processing_started_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function decodedPayload(): array
    {
        return json_decode($this->payload, true, flags: JSON_THROW_ON_ERROR);
    }

    public function markProcessing(): void
    {
        $this->forceFill([
            'status' => WebhookEventStatus::Processing,
            'attempts' => $this->attempts + 1,
            'processing_started_at' => now(),
        ])->save();
    }

    public function markFinished(WebhookEventStatus $status, ?string $note, int $processingMilliseconds): void
    {
        $this->forceFill([
            'status' => $status,
            'last_error' => $note,
            'processed_at' => now(),
            'processing_ms' => $processingMilliseconds,
        ])->save();
    }

    public function recordAttemptFailure(Throwable $exception, int $processingMilliseconds): void
    {
        $this->forceFill([
            'last_error' => Str::limit($exception::class.': '.$exception->getMessage(), 2000),
            'processing_ms' => $processingMilliseconds,
        ])->save();
    }

    public function markFailed(?Throwable $exception): void
    {
        $this->forceFill([
            'status' => WebhookEventStatus::Failed,
            'last_error' => $exception
                ? Str::limit($exception::class.': '.$exception->getMessage(), 2000)
                : $this->last_error,
        ])->save();
    }

    public function markForReplay(): void
    {
        $this->forceFill([
            'status' => WebhookEventStatus::Received,
            'processed_at' => null,
        ])->save();
    }
}
