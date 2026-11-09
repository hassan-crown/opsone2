<?php

namespace App\Models\Ai;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A redacted question the help assistant could not answer from the guide. No user, no branch: content gaps only.
 *
 * @property int $id
 * @property string $question
 * @property string $question_hash
 * @property string $reason no_match | unsupported
 * @property int $times
 * @property Carbon $last_asked_at
 */
class HelpUnansweredQuestion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['times' => 'integer', 'last_asked_at' => 'datetime'];
    }
}
