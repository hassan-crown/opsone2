<?php

namespace App\Actions\StateMachines\M22Membership;

use App\Access\Enums\Scope;
use App\Access\TransitionRecorder;
use App\Enums\MembershipStatus;
use App\Enums\StateMachine;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * M-22-T00 (implicit creation, Q-21): a membership starts «نشطة بعد الإثبات».
 * Internal step: callers (invitation acceptance M-01-T01, provisioning seeders) authorize first.
 */
final class T00CreateMembership
{
    public function __construct(private readonly TransitionRecorder $transitions) {}

    /**
     * @param  list<int>  $branchIds
     * @param  list<array{role_template_id: int, scope?: string|null, targets?: list<int>|null}>  $roles
     */
    public function handle(
        User $user,
        ?int $departmentId,
        array $branchIds,
        array $roles,
        ?string $jobTitle,
        ?User $actor,
        string $source,
    ): Membership {
        return DB::transaction(function () use ($user, $departmentId, $branchIds, $roles, $jobTitle, $actor, $source) {
            $membership = Membership::query()->create([
                'user_id' => $user->id,
                'department_id' => $departmentId,
                'job_title' => $jobTitle,
            ]);
            $membership->branches()->sync($branchIds);

            foreach ($roles as $role) {
                $membership->roles()->create([
                    'role_template_id' => $role['role_template_id'],
                    'scope' => isset($role['scope']) ? Scope::from($role['scope']) : null,
                    'targets' => $role['targets'] ?? null,
                ]);
            }

            $this->transitions->record(
                StateMachine::M22, 'T00', $membership, null, MembershipStatus::Active, $actor, $source,
            );

            return $membership;
        });
    }
}
