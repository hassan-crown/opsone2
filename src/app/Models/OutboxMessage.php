<?php

namespace App\Models;

use App\Notifications\OutboxChannel;
use App\Notifications\OutboxStatus;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One outbound message (mail or SMS) waiting to be, or already, delivered. Created inside the business
 * transaction that causes it and delivered by a queued job afterwards.
 *
 * @property int $id
 * @property string $idempotency_key
 * @property OutboxChannel $channel
 * @property string $recipient
 * @property string $kind
 * @property array<string, mixed>|null $payload
 * @property OutboxStatus $status
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $claimed_at
 * @property Carbon|null $sent_at
 * @property string|null $last_error
 */
#[Hidden(['payload'])]
class OutboxMessage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'channel' => OutboxChannel::class,
            'status' => OutboxStatus::class,
            'payload' => 'encrypted:array',
            'next_attempt_at' => 'datetime',
            'claimed_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
