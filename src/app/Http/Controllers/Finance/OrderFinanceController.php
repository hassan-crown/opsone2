<?php

namespace App\Http\Controllers\Finance;

use App\Access\TransitionRecorder;
use App\Actions\Finance\TransferCredit;
use App\Actions\Finance\UpdateSchedule;
use App\Actions\StateMachines\M11Payment\T01RecordPayment;
use App\Agreements\OrderAgreement;
use App\Enums\OrderKind;
use App\Enums\StateMachine;
use App\Finance\FinanceLock;
use App\Finance\FinancePresenter;
use App\Finance\OrderSchedule;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\RecordPaymentRequest;
use App\Http\Requests\Finance\ScheduleRequest;
use App\Http\Requests\Finance\TransferRequest;
use App\Models\Finance\OrderFinance;
use App\Models\Order;
use App\Orders\OrderAccess;
use App\Orders\OrderPresenter;
use App\Support\LockingTransaction;
use App\Workflow\AppointmentBooking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Finance» tab of an order (FinOrder and its variants) and its writes: record a payment (FinRecordPayment), the
 * schedule (FinSchedule) and moving credit to another order (FinAllocate). Reads need P-16 over the order; amounts
 * follow FinancePresenter (P-37). Executors never reach it (no P-16): their task carries no money.
 */
class OrderFinanceController extends Controller
{
    use FinanceResponses;

    public function show(Request $request, Order $order, OrderSchedule $schedule): Response|JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless(OrderAccess::canView($actor, $order), 403);
        abort_if($order->kind !== OrderKind::Custom, 404);
        $this->heal($order, $schedule);
        $finance = FinancePresenter::forOrder($order->refresh(), $actor);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['finance' => $finance]);
        }

        return Inertia::render('orders/finance', [
            'order' => OrderPresenter::row($order, $actor),
            'finance' => $finance,
            'today' => AppointmentBooking::today(),
        ]);
    }

    public function recordPayment(RecordPaymentRequest $request, Order $order, T01RecordPayment $record): JsonResponse
    {
        $body = collect($request->body())->except('evidence')->all();
        $payment = $record->handle($this->actor($request), $order, $body, $request->evidence(), $request->lockVersion(), $request->key());

        return $this->respond($request, $order, ['payment_id' => $payment->id, 'receipt' => $payment->receipt?->number], 201);
    }

    public function updateSchedule(ScheduleRequest $request, Order $order, UpdateSchedule $update): JsonResponse
    {
        $update->handle($this->actor($request), $order, $request->body(), $request->lockVersion(), $request->key());

        return $this->respond($request, $order);
    }

    public function transferCredit(TransferRequest $request, Order $order, TransferCredit $transfer): JsonResponse
    {
        $transfer->handle($this->actor($request), $order, $request->body(), $request->lockVersion(), $request->key());

        return $this->respond($request, $order);
    }

    /**
     * A lost AgreementApplied would leave the schedule behind the agreement: follow it now (idempotent).
     */
    private function heal(Order $order, OrderSchedule $schedule): void
    {
        $agreement = OrderAgreement::current($order);
        $synced = OrderFinance::query()->where('order_id', $order->id)->value('agreement_id');
        if ($agreement === null || (int) $synced === $agreement->id) {
            return;
        }
        LockingTransaction::run(function () use ($order, $schedule) {
            $locked = FinanceLock::order($order->id, StateMachine::M11, 'sync');
            $schedule->sync($locked, FinanceLock::head($locked), null, TransitionRecorder::SOURCE_SYSTEM);
        });
    }
}
