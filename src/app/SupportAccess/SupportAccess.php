<?php

namespace App\SupportAccess;

use App\Access\Enums\Scope;
use App\Access\Grant;
use App\Enums\SupportGrantStatus;
use App\Models\SupportGrant;
use App\Models\User;

/**
 * Using a support grant (P-80): which grants are live for a support identity and what they open. A support
 * identity holds nothing of its own — every grant here comes from a live M-38 grant and ends with it.
 */
final class SupportAccess
{
    /**
     * Live grants: active and not past `expires_at`. Time alone ends a grant, even before the expiry job runs.
     *
     * @return list<SupportGrant>
     */
    public function liveGrants(User $user): array
    {
        if (! $user->is_support) {
            return [];
        }

        return array_values(SupportGrant::query()
            ->where('support_user_id', $user->id)
            ->where('status', SupportGrantStatus::Active->value)
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->get()
            ->all());
    }

    public function isLive(User $user): bool
    {
        return $this->liveGrants($user) !== [];
    }

    /**
     * Read grants opened by the live grants' areas, facility-wide (the owner granted them, P-79 is facility scope).
     *
     * @return list<Grant>
     */
    public function grantsFor(User $user): array
    {
        $grants = [];
        foreach ($this->liveGrants($user) as $grant) {
            foreach ($grant->areas ?? [] as $value) {
                foreach (SupportArea::tryFrom($value)?->permissions() ?? [] as $permission) {
                    $grants[$permission->value] = new Grant($permission, Scope::Tenant);
                }
            }
        }

        return array_values($grants);
    }
}
