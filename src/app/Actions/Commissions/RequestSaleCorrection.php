<?php

namespace App\Actions\Commissions;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Commissions\SaleOwnership;
use App\Enums\MembershipStatus;
use App\Enums\StateMachine;
use App\Finance\FinanceLock;
use App\Models\Commissions\SaleCorrection;
use App\Models\Membership;
use App\Models\Order;
use App\Models\User;
use App\Support\LockingTransaction;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Asks for a financial decision on a pinned sale (SAL-16, Q-37 a): a new owner (P-98) or a new rate (P-97), with a
 * reason. Nobody asks to move commission to or from themselves («لا يغير المبيعات نسبته لنفسه»): `own_sale`. One open
 * request per order (`correction_open`). Nothing moves until someone else approves it.
 */
final class RequestSaleCorrection
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'kind' => ['required', Rule::in([SaleCorrection::OWNER, SaleCorrection::RATE])],
            'to_user_id' => ['required_if:kind,owner', 'nullable', 'integer'],
            'rate_bp' => ['required_if:kind,rate', 'nullable', 'integer', 'min:0', 'max:10000'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, Order $order, array $data, string $source = TransitionRecorder::SOURCE_WEB): SaleCorrection
    {
        /** @var array{kind: string, to_user_id?: int|null, rate_bp?: int|null, reason: string} $data */
        $data = Validator::make($data, self::rules())->validate();

        return LockingTransaction::run(function () use ($actor, $order, $data, $source) {
            $locked = FinanceLock::order($order->id, StateMachine::M33, 'T05');
            $sale = SaleOwnership::active($locked) ?? throw new InvalidTransition(StateMachine::M33, 'T05', 'no_sale');
            $sale->setRelation('order', $locked);
            Access::authorize($actor, $data['kind'] === SaleCorrection::OWNER ? Permission::P98 : Permission::P97, $sale);

            $to = $data['kind'] === SaleCorrection::OWNER ? (int) ($data['to_user_id'] ?? 0) : $sale->user_id;
            $rate = $data['kind'] === SaleCorrection::RATE ? (int) ($data['rate_bp'] ?? 0) : $sale->rate_bp;
            if ($data['kind'] === SaleCorrection::OWNER) {
                $active = Membership::query()->where('user_id', $to)->where('status', MembershipStatus::Active->value)->exists();
                if (! $active) {
                    throw ValidationException::withMessages(['to_user_id' => __('commissions.validation.member')]);
                }
            }
            if (in_array($actor->id, [$sale->user_id, $to], true)) {
                throw new InvalidTransition(StateMachine::M33, 'T05', 'own_sale');
            }
            if ($to === $sale->user_id && $rate === $sale->rate_bp) {
                throw new InvalidTransition(StateMachine::M33, 'T05', 'no_change');
            }
            if (SaleCorrection::query()->where('order_id', $locked->id)->where('status', SaleCorrection::PENDING)->exists()) {
                throw new InvalidTransition(StateMachine::M33, 'T05', 'correction_open');
            }

            $correction = SaleCorrection::query()->create([
                'order_sale_id' => $sale->id, 'order_id' => $locked->id, 'kind' => $data['kind'],
                'from_user_id' => $sale->user_id, 'to_user_id' => $to, 'from_rate_bp' => $sale->rate_bp, 'to_rate_bp' => $rate,
                'reason' => trim($data['reason']), 'status' => SaleCorrection::PENDING, 'pending_key' => $locked->id,
                'requested_by' => $actor->id,
            ]);
            Audit::record(AuditAction::SaleCorrectionRequested, $locked, $actor, after: [
                'correction' => $correction->id, 'kind' => $data['kind'], 'to_user' => $to, 'to_rate_bp' => $rate,
            ], reason: $correction->reason, source: $source, label: $locked->number);

            return $correction;
        });
    }
}
