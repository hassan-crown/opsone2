<?php

namespace App\Ai;

use App\Access\Access;
use App\Access\LicenseLimits;
use App\Access\Permission;
use App\Enums\MembershipStatus;
use App\Models\Ai\AiUsage;
use App\Models\Facility;
use App\Models\Membership;
use App\Models\User;
use App\Notifications\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Monthly AI credits (plan §3.4, §4.3). The month is the calendar month in the facility's timezone; the limit is
 * `limits.ai_credits` from the license (0 when absent). Charged = `ok` rows plus `pending` reservations younger than
 * the reservation window (a crashed call stops holding credits after that). Failures are never charged.
 *
 * Included tasks (weight 0) do not use credits but have a daily fair-use cap per task. At the alert ratio (80%)
 * holders of P-02 get one notice per month. When credits run out only AI stops; core work never does.
 */
final class AiCredits
{
    /** A pending reservation older than this no longer holds credits. */
    private const RESERVATION_MINUTES = 15;

    public const NOTICE = 'ai.credits_low';

    public function __construct(
        private readonly LicenseLimits $limits,
        private readonly Notifier $notifier,
    ) {}

    public function limit(): int
    {
        return $this->limits->aiCredits();
    }

    public function used(): int
    {
        return (int) $this->charged()->where('created_at', '>=', $this->monthStart())->sum('credits');
    }

    /**
     * @return array{used: int, limit: int, remaining: int, ratio: float, month_start: string}
     */
    public function status(): array
    {
        $used = $this->used();
        $limit = $this->limit();

        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => max(0, $limit - $used),
            'ratio' => $limit > 0 ? round($used / $limit, 4) : ($used > 0 ? 1.0 : 0.0),
            'month_start' => $this->monthStart()->toIso8601String(),
        ];
    }

    /**
     * Reserves the task's credits for one call, as a `pending` usage row, or records a `no_credit` row and returns
     * it. Runs in its own short transaction with the facility row locked, so parallel calls cannot overspend.
     */
    public function reserve(AiTask $task, string $driver, ?User $user, ?Model $subject): AiUsage
    {
        return DB::transaction(function () use ($task, $driver, $user, $subject) {
            Facility::query()->lockForUpdate()->orderBy('id')->first();

            $weight = $task->weight();
            $allowed = $weight > 0
                ? $this->used() + $weight <= $this->limit()
                : $this->usedToday($task) < max(0, (int) config('ai.fair_use_daily', 300));

            return AiUsage::query()->create([
                'task' => $task->value,
                'model' => $task->model(),
                'driver' => $driver,
                'outcome' => $allowed ? AiOutcome::Pending : AiOutcome::NoCredit,
                'credits' => $allowed ? $weight : 0,
                'user_id' => $user?->id,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
            ]);
        });
    }

    /**
     * Sends the 80% notice once a month to the holders of P-02 (after a successful charge).
     */
    public function alertIfLow(): void
    {
        $limit = $this->limit();
        if ($limit <= 0 || $this->used() < $limit * (float) config('ai.alert_ratio', 0.8)) {
            return;
        }

        $month = $this->monthStart()->format('Y-m');
        $userIds = Membership::query()->where('status', MembershipStatus::Active)->pluck('user_id');
        foreach (User::query()->whereIn('id', $userIds)->where('is_support', false)->get() as $user) {
            if (Access::can($user, Permission::P02)) {
                $this->notifier->send($user, self::NOTICE, ['used' => $this->used(), 'limit' => $limit], null, 'ai-credits-low:'.$month);
            }
        }
    }

    public function monthStart(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfMonth()->utc();
    }

    private static function timezone(): string
    {
        $timezone = Facility::current()?->timezone;

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Asia/Riyadh';
    }

    private function usedToday(AiTask $task): int
    {
        $dayStart = CarbonImmutable::now(self::timezone())->startOfDay()->utc();

        return $this->charged(includeFree: true)->where('task', $task->value)->where('created_at', '>=', $dayStart)->count();
    }

    /**
     * @return Builder<AiUsage>
     */
    private function charged(bool $includeFree = false): Builder
    {
        return AiUsage::query()->where(function ($q) {
            $q->where('outcome', AiOutcome::Ok->value)
                ->orWhere(fn ($p) => $p->where('outcome', AiOutcome::Pending->value)->where('created_at', '>', now()->subMinutes(self::RESERVATION_MINUTES)));
        })->when(! $includeFree, fn ($q) => $q->where('credits', '>', 0));
    }
}
