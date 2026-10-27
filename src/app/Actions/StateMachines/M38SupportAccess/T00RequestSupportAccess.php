<?php

namespace App\Actions\StateMachines\M38SupportAccess;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\MembershipStatus;
use App\Enums\StateMachine;
use App\Enums\SupportGrantStatus;
use App\Models\Membership;
use App\Models\SupportGrant;
use App\Models\SupportRequest;
use App\Models\User;
use App\Notifications\Notifier;
use App\SupportAccess\SupportArea;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * M-38-T00: OpsOne support asks for access (platform side, P-80). Nothing opens yet: the request waits for the
 * owner's decision and lapses after `opsone.support.request_ttl_hours`. Holders of P-79 are notified.
 */
final class T00RequestSupportAccess
{
    public function __construct(
        private readonly TransitionRecorder $transitions,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @param  list<string>  $areas
     */
    public function handle(User $support, array $areas, int $hours, string $reason, ?SupportRequest $request = null, ?string $ticket = null): SupportGrant
    {
        $areas = array_values(array_unique($areas));
        $max = (int) config('opsone.support.max_access_hours', 72);

        if (! $support->is_support || Membership::query()->where('user_id', $support->id)->exists()) {
            throw new InvalidArgumentException('Support access can only be requested for an OpsOne support identity.');
        }
        if ($areas === [] || array_diff($areas, SupportArea::values()) !== []) {
            throw new InvalidArgumentException('Unknown support area.');
        }
        if ($hours < 1 || $hours > $max) {
            throw new InvalidArgumentException("Support access lasts between 1 and {$max} hours.");
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required.');
        }

        return DB::transaction(function () use ($support, $areas, $hours, $reason, $request, $ticket) {
            $grant = SupportGrant::query()->create([
                'uuid' => (string) Str::uuid(),
                'support_user_id' => $support->id,
                'support_request_id' => $request?->id,
                'ticket' => $ticket ?? $request?->reference(),
                'reason' => Str::limit($reason, 500, ''),
                'requested_areas' => $areas,
                'requested_hours' => $hours,
                'status' => SupportGrantStatus::Requested,
                'request_expires_at' => now()->addHours((int) config('opsone.support.request_ttl_hours', 72)),
            ]);

            $this->transitions->record(StateMachine::M38, 'T00', $grant, null, SupportGrantStatus::Requested, $support, TransitionRecorder::SOURCE_SYSTEM, $grant->reason, ['areas' => $areas]);
            Audit::record(AuditAction::SupportAccessRequested, $grant, $support,
                after: ['status' => SupportGrantStatus::Requested, 'areas' => $areas, 'hours' => $hours],
                reason: $grant->reason, source: TransitionRecorder::SOURCE_SYSTEM, label: SupportAccessTransition::label($grant),
            );

            foreach ($this->approvers() as $approver) {
                $this->notifier->send($approver, 'support.access_requested', ['ticket' => SupportAccessTransition::label($grant), 'hours' => $hours], dedupeKey: "support-grant:{$grant->id}");
            }

            return $grant;
        });
    }

    /**
     * Active members who may decide (P-79): the owner, and anyone the owner explicitly granted it to.
     *
     * @return list<User>
     */
    private function approvers(): array
    {
        $users = User::query()
            ->whereIn('id', Membership::query()->where('status', MembershipStatus::Active->value)->select('user_id'))
            ->get();

        return array_values($users->filter(fn (User $u) => Access::can($u, Permission::P79))->all());
    }
}
