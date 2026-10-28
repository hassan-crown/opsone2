<?php

namespace App\Actions\StateMachines\M33Payout;

use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\PayoutStatus;
use App\Enums\StateMachine;
use App\Finance\FinanceWrite;
use App\Models\Commissions\CommissionPayout;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

/**
 * A draft payout of one member for one period (FinPayout, SAL-14, P-96). Nothing is held yet: the lines are taken
 * by M-33-T01. One open payout per member at a time.
 */
final class T00CreatePayout
{
    public function __construct(
        private readonly FinanceWrite $write,
        private readonly TransitionRecorder $transitions,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'period_from' => ['required', 'date_format:Y-m-d'],
            'period_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_from', 'before_or_equal:today'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, User $member, array $data, ?string $key, string $source = TransitionRecorder::SOURCE_WEB): CommissionPayout
    {
        /** @var array{period_from: string, period_to: string} $data */
        $data = Validator::make($data, self::rules())->validate();

        $result = $this->write->run('commission.payout.create', $actor, $key, [...$data, 'member' => $member->id], function () use ($actor, $member, $data, $source) {
            PayoutLock::member($member->id);
            $payout = new CommissionPayout(['user_id' => $member->id, ...$data]);
            PayoutLock::authorize($actor, $payout, Permission::P96, 'T00');
            $open = CommissionPayout::query()->where('user_id', $member->id)
                ->whereIn('status', [PayoutStatus::Draft->value, PayoutStatus::Reserved->value])->exists();
            if ($open) {
                throw new InvalidTransition(StateMachine::M33, 'T00', 'payout_open');
            }
            $payout->forceFill(['status' => PayoutStatus::Draft, 'created_by' => $actor->id])->save();
            $this->transitions->record(StateMachine::M33, 'T00', $payout, null, PayoutStatus::Draft, $actor, $source);
            Audit::record(AuditAction::CommissionPayoutCreated, $payout, $actor, after: [
                'member' => $member->id, 'period_from' => $data['period_from'], 'period_to' => $data['period_to'],
            ], source: $source, label: $member->name);

            return ['payout_id' => $payout->id];
        });

        return CommissionPayout::query()->findOrFail((int) $result['result']['payout_id']);
    }
}
