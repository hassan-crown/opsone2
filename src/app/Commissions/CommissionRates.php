<?php

namespace App\Commissions;

use App\Models\Commissions\CommissionRate;
use App\Models\Commissions\CommissionSettings;
use App\Models\Membership;
use App\Models\MembershipRole;
use App\Models\User;

/**
 * The rate a new sale is pinned with (SAL-10): the member's own rate, else the highest rate of their role templates,
 * else the facility default; 0 with source `disabled` while commissions are off. Only read when pinning — a later
 * change never reaches a pinned sale (SAL-19).
 */
final class CommissionRates
{
    /**
     * @return array{rate_bp: int, source: string}
     */
    public static function resolve(User $member): array
    {
        $settings = CommissionSettings::current();
        if (! $settings->enabled) {
            return ['rate_bp' => 0, 'source' => 'disabled'];
        }

        $own = CommissionRate::query()->where('user_id', $member->id)->value('rate_bp');
        if ($own !== null) {
            return ['rate_bp' => (int) $own, 'source' => 'member'];
        }

        $roles = MembershipRole::query()
            ->whereIn('membership_id', Membership::query()->where('user_id', $member->id)->select('id'))
            ->pluck('role_template_id');
        $role = CommissionRate::query()->whereIn('role_template_id', $roles)->max('rate_bp');
        if ($role !== null) {
            return ['rate_bp' => (int) $role, 'source' => 'role'];
        }

        return ['rate_bp' => $settings->default_rate_bp, 'source' => 'default'];
    }
}
