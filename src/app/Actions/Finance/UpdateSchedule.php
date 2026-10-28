<?php

namespace App\Actions\Finance;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Agreements\OrderAgreement;
use App\Enums\StateMachine;
use App\Finance\FinanceLock;
use App\Finance\FinanceWrite;
use App\Finance\OrderSchedule;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

/**
 * FinSchedule (P-38, FIN-02): replace the order's payment schedule. The sum never exceeds the approved agreement
 * (the unscheduled part stays visible), a due never drops below what is already allocated to it, and the credit is
 * allocated again oldest due first. Only once an agreement exists: a draft quote is never a debt.
 */
final class UpdateSchedule
{
    public function __construct(
        private readonly FinanceWrite $write,
        private readonly OrderSchedule $schedule,
    ) {}

    /**
     * @param  array<string, mixed>  $data  {dues: [...]} (OrderSchedule::rules())
     */
    public function handle(User $actor, Order $order, array $data, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): void
    {
        /** @var array{dues: list<array{name: array{ar: string, en?: string|null}, reference: string, basis: string, value?: string|null, event: string, due_date?: string|null}>} $data */
        $data = Validator::make($data, OrderSchedule::rules())->validate();

        $this->write->run('schedule.update', $actor, $idempotencyKey, [...$data, 'order_id' => $order->id, 'lock_version' => $lockVersion], function () use ($actor, $order, $data, $lockVersion, $source) {
            $order = FinanceLock::order($order->id, StateMachine::M11, 'schedule');
            Access::authorize($actor, Permission::P38, $order);
            $head = FinanceLock::head($order);
            FinanceLock::checkVersion($head, $lockVersion);
            $this->schedule->sync($order, $head, $actor, $source);
            $agreement = OrderAgreement::current($order) ?? throw new InvalidTransition(StateMachine::M11, 'schedule', 'no_agreement');

            $this->schedule->replace($order, $agreement, $data['dues'], $actor, $source);
            FinanceLock::touch($head);

            return ['order_id' => $order->id];
        });
    }
}
