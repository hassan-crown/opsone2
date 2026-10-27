<?php

namespace App\Actions\StateMachines\M38SupportAccess;

use App\Access\Exceptions\InvalidTransition;
use App\Access\SessionRevoker;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\StateMachine;
use App\Enums\SupportGrantStatus;
use App\Models\SupportGrant;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Shared guard for M-38 decisions (T01..T04): lock, require an allowed source state, check conditions, apply,
 * then log the transition and the audit event in the same transaction. Ending a grant ends the support sessions.
 */
abstract class SupportAccessTransition
{
    public function __construct(
        protected readonly TransitionRecorder $transitions,
        protected readonly SessionRevoker $sessions,
    ) {}

    /**
     * @param  list<SupportGrantStatus>  $from
     * @param  (Closure(SupportGrant): ?string)|null  $guard  returns the failed condition, or null
     * @param  (Closure(SupportGrant): array<string, mixed>)|null  $effect  returns extra attributes to save
     */
    protected function move(
        string $code,
        SupportGrant $grant,
        array $from,
        SupportGrantStatus $to,
        ?User $actor,
        ?Closure $guard,
        ?Closure $effect,
        string $source,
        ?string $reason = null,
    ): SupportGrant {
        return DB::transaction(function () use ($code, $grant, $from, $to, $actor, $guard, $effect, $source, $reason) {
            $locked = SupportGrant::query()->lockForUpdate()->findOrFail($grant->id);
            $before = $locked->status;

            if (! in_array($before, $from, true)) {
                throw new InvalidTransition(StateMachine::M38, $code, 'not_'.implode('_or_', array_map(fn ($s) => $s->value, $from)));
            }
            if ($guard !== null && ($failed = $guard($locked)) !== null) {
                throw new InvalidTransition(StateMachine::M38, $code, $failed);
            }

            $locked->forceFill([...($effect === null ? [] : $effect($locked)), 'status' => $to])->save();

            if ($before === SupportGrantStatus::Active) {
                // Revoked or expired: every session of the support identity ends now (matrix P-79).
                $this->sessions->revoke($locked->support_user_id);
            }

            $this->transitions->record(StateMachine::M38, $code, $locked, $before, $to, $actor, $source, $reason, [
                'areas' => $locked->areas ?? $locked->requested_areas,
            ]);
            Audit::record(self::auditAction($to), $locked, $actor,
                before: ['status' => $before],
                after: ['status' => $to, 'areas' => $locked->areas, 'hours' => $locked->hours, 'expires_at' => $locked->expires_at],
                reason: $reason, source: $source, label: self::label($locked),
            );

            return $locked;
        });
    }

    public static function label(SupportGrant $grant): string
    {
        return $grant->ticket ?? 'SUP-'.$grant->id;
    }

    private static function auditAction(SupportGrantStatus $to): AuditAction
    {
        return match ($to) {
            SupportGrantStatus::Requested => AuditAction::SupportAccessRequested,
            SupportGrantStatus::Active => AuditAction::SupportAccessGranted,
            SupportGrantStatus::Rejected => AuditAction::SupportAccessRejected,
            SupportGrantStatus::Revoked => AuditAction::SupportAccessRevoked,
            SupportGrantStatus::Expired => AuditAction::SupportAccessExpired,
        };
    }
}
