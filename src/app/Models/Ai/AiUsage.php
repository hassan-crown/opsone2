<?php

namespace App\Models\Ai;

use App\Ai\AiOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One AI gateway call (ADR-0050 §6). Metadata only: no prompt, no answer.
 *
 * @property int $id
 * @property string $task
 * @property string $model
 * @property string $driver
 * @property AiOutcome $outcome
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property int $cost_micro_usd
 * @property int $credits
 * @property int $duration_ms
 * @property int|null $user_id
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property Carbon $created_at
 */
class AiUsage extends Model
{
    protected $table = 'ai_usage';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'outcome' => AiOutcome::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'cost_micro_usd' => 'integer',
            'credits' => 'integer',
            'duration_ms' => 'integer',
        ];
    }
}
