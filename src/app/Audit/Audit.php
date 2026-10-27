<?php

namespace App\Audit;

use Illuminate\Support\Facades\Facade;

/**
 * `Audit::record(AuditAction::X, $subject, $actor, before: [...], after: [...])` — call inside the change's transaction.
 *
 * @method static \App\Models\AuditEvent record(AuditAction $action, ?\Illuminate\Database\Eloquent\Model $subject, ?\App\Models\User $actor, array<string, mixed> $before = [], array<string, mixed> $after = [], ?string $reason = null, string $source = 'web', list<int>|null $branchIds = null, ?int $departmentId = null, ?string $label = null)
 *
 * @see AuditRecorder
 */
final class Audit extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AuditRecorder::class;
    }
}
