<?php

namespace App\Workflow;

use App\Access\Access;
use App\Access\Permission;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Calendar reads (SHR-04, `CalWeek` and the daily view): appointments in a window that the user may manage (P-54 in
 * scope) or is booked on (P-24). Times are UTC; screens show them in the facility time zone.
 */
final class AppointmentQueries
{
    public const LIMIT = 500;

    /** An appointment never lasts longer (T00/T03 refuse more), so a window search can bound its start from below. */
    public const MAX_DURATION_HOURS = 72;

    /**
     * @param  list<int>|null  $memberIds  only appointments of these members
     * @param  int|null  $branchId  only appointments of this branch's orders (before the limit, not after it)
     * @return Collection<int, Appointment>
     */
    public static function between(User $user, CarbonInterface $from, CarbonInterface $to, ?array $memberIds = null, ?int $branchId = null): Collection
    {
        // The rows feed the Gate (order scope) and the calendar row (branch, customer): loaded once, not per row.
        return Appointment::query()->with(['order.branch', 'order.customer', 'members'])
            ->whereIn('status', [AppointmentStatus::Proposed->value, AppointmentStatus::AwaitingConfirmation->value, AppointmentStatus::Confirmed->value, AppointmentStatus::Done->value])
            ->where('starts_at', '<', $to->copy()->utc())
            // Same rows as ends_at > from alone, but the (status, starts_at) index now reads a bounded range.
            ->where('starts_at', '>', $from->copy()->utc()->subHours(self::MAX_DURATION_HOURS))
            ->where('ends_at', '>', $from->copy()->utc())
            ->when($memberIds !== null, fn ($q) => $q->whereHas('members', fn ($m) => $m->whereIn('users.id', $memberIds)))
            ->when($branchId !== null, fn ($q) => $q->whereIn('order_id', Order::query()->where('branch_id', $branchId)->select('id')))
            ->orderBy('starts_at')->limit(self::LIMIT)->get()
            ->tap(fn ($rows) => Order::primeAccessDepartments($rows->pluck('order')))
            ->filter(fn (Appointment $a) => Access::can($user, Permission::P54, $a) || Access::can($user, Permission::P24, $a))
            ->values();
    }
}
