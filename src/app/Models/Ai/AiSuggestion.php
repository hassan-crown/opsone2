<?php

namespace App\Models\Ai;

use App\Ai\AiSuggestionStatus;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An AI draft waiting for a staff member (ADR-0050 §1). Payloads are encrypted at rest and hidden from serialization;
 * a feature exposes only what its screen needs.
 *
 * @property int $id
 * @property string $task
 * @property int|null $ai_usage_id
 * @property AiSuggestionStatus $status
 * @property array<string, mixed> $payload
 * @property array<string, mixed>|null $final_payload
 * @property int|null $requested_by
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon|null $expires_at
 */
#[Hidden(['payload', 'final_payload'])]
class AiSuggestion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => AiSuggestionStatus::class,
            'payload' => 'encrypted:array',
            'final_payload' => 'encrypted:array',
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
