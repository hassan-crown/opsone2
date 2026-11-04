<?php

namespace App\Models\Automation;

use Illuminate\Database\Eloquent\Model;

/**
 * One action of a matched run (ADR-0042). The unique `idempotency_key` («rule:event:index») is what makes a retried
 * run never repeat an action that already happened.
 *
 * @property int $id
 * @property int $automation_run_id
 * @property string $idempotency_key
 * @property string $type
 * @property string $status done | skipped | failed
 * @property array<string, mixed>|null $result
 */
class AutomationActionLog extends Model
{
    protected $table = 'automation_actions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'automation_run_id' => 'integer',
            'result' => 'array',
        ];
    }
}
