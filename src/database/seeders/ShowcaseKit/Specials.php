<?php

namespace Database\Seeders\ShowcaseKit;

use App\Actions\StateMachines\M09Change\T00CreateChange;
use App\Actions\StateMachines\M09Change\T01PresentChange;
use App\Actions\StateMachines\M09Change\T04ApplyChange;
use App\Actions\StateMachines\M09Change\UpdateChangeDraft;
use App\Actions\StateMachines\M11Payment\T07CorrectPayment;
use App\Actions\StateMachines\M12Refund\T00RequestRefund;
use App\Actions\StateMachines\M12Refund\T02ConfirmRefund;
use App\Actions\StateMachines\M33Payout\T00CreatePayout;
use App\Actions\StateMachines\M33Payout\T01ApprovePayout;
use App\Actions\StateMachines\M34Cancellation\T03RecordAndAcceptCancellation;
use App\Actions\StateMachines\M39Dispute\T00OpenDispute;
use App\Cancellation\CancellationImpact;
use App\Cancellation\CancellationInput;
use App\Finance\FinanceLock;
use App\Finance\MoneyLedger;
use App\Models\Agreements\Agreement;
use App\Models\Finance\Payment;
use App\Models\Finance\Refund;
use App\Models\Order;
use App\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The exceptions of a real business, dated through the six months: agreement changes, a price reduction with refund,
 * a full cancellation with a retained share and refund, one payment correction, one financial dispute, and two
 * monthly commission payouts (August and September).
 */
final class Specials
{
    public function __construct(private readonly Showcase $s) {}

    public function run(): void
    {
        $d = fn (string $date) => CarbonImmutable::parse($date.' 11:00', 'Asia/Riyadh');

        foreach (['2026-07-23', '2026-08-26', '2026-09-24', '2026-10-14'] as $i => $date) {
            $this->at($d($date), 'change-'.$i, fn () => $this->change($i % 2 === 0));
        }
        $this->at($d('2026-09-02'), 'reduction', fn () => $this->change(false, true));
        $this->at($d('2026-09-16'), 'cancellation-1', fn () => $this->cancel('نقل العميل لمدينة أخرى وألغى المشروع.', '2000'));
        $this->at($d('2026-10-20'), 'cancellation-2', fn () => $this->cancel('تأجيل المشروع لعام كامل بسبب تأخر البناء.', '0'));
        $this->at($d('2026-10-22'), 'correction', fn () => $this->correction());
        $this->at($d('2026-10-26'), 'dispute', fn () => $this->dispute());
        // Weekly accounting run (Thursdays): finance links the invoice issued in the external accounting system.
        $thursday = CarbonImmutable::parse('2026-05-07 15:00', 'Asia/Riyadh');
        for ($w = 0; $thursday->lessThanOrEqualTo($this->s->end); $w++, $thursday = $thursday->addWeek()) {
            $this->at($thursday, 'invoices-'.$w, fn () => $this->invoices());
        }
        $this->at($d('2026-09-03'), 'payout-aug', fn () => $this->payouts('2026-08-01', '2026-08-31'));
        $this->at($d('2026-10-04'), 'payout-sep', fn () => $this->payouts('2026-09-01', '2026-09-30'));
    }

    private function at(CarbonImmutable $when, string $label, \Closure $fn): void
    {
        // One story per event: a failed exception does not cancel the others.
        $this->s->timeline->at($when, new Story('special-'.$label), $label, fn () => $fn());
    }

    private function invoices(): void
    {
        $paid = \Illuminate\Support\Facades\DB::table('payments')->where('status', 'confirmed')->where('created_at', '<', CarbonImmutable::now()->subDay())
            ->pluck('order_id')->unique();
        $done = \Illuminate\Support\Facades\DB::table('external_documents')->pluck('order_id')->all();
        foreach (Order::query()->where('kind', 'custom')->whereIn('id', $paid)->whereNotIn('id', $done)->orderBy('id')->limit(40)->get() as $order) {
            if ($this->s->rng->chance(12)) {
                continue; // a few invoices are still being prepared by the accountant
            }
            $order->refresh();
            $collected = (int) app(MoneyLedger::class)->collected($order->id)->jsonSerialize();
            if ($collected <= 0) {
                continue;
            }
            $number = 'INV-'.CarbonImmutable::now()->format('Y').'-'.str_pad((string) (1000 + $order->id), 5, '0', STR_PAD_LEFT);
            app(\App\Actions\StateMachines\M40Document\T01AttachDocument::class)->handle($this->s->users['finance'], $order, null, [
                'kind' => $order->kind->value === 'custom' ? 'tax_invoice' : 'simplified_invoice', 'event' => 'order', 'source' => 'قيود', 'number' => $number,
                'issued_on' => CarbonImmutable::now()->toDateString(), 'amount' => Money::of($collected)->toInput(),
            ], Assets::pdf($number.'.pdf', 'Invoice '.$number), null, $this->key());
        }
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    /**
     * An in-progress custom order with an active agreement, in one of the given stages, not already touched.
     *
     * @param  list<string>  $stages
     */
    private function pick(array $stages, array $skip = []): ?Order
    {
        $ids = \Illuminate\Support\Facades\DB::table('order_workflows')
            ->join('order_stages', 'order_stages.id', '=', 'order_workflows.active_stage_id')
            ->whereIn('order_stages.key', $stages)->pluck('order_workflows.order_id');
        foreach (Order::query()->whereIn('id', $ids)->where('kind', 'custom')->where('status', 'in_progress')->orderBy('id')->get() as $order) {
            if (in_array($order->id, $skip, true) || Agreement::query()->where('order_id', $order->id)->where('status', 'active')->doesntExist()) {
                continue;
            }
            if (\App\Models\Agreements\AgreementChange::query()->where('order_id', $order->id)->exists()) {
                continue;
            }

            return $order;
        }

        return null;
    }

    private function change(bool $increase, bool $reduce = false): void
    {
        $order = $this->pick(['manufacture', 'install']);
        if ($order === null) {
            throw new \RuntimeException('no order to change');
        }
        $agreement = Agreement::query()->where('order_id', $order->id)->where('status', 'active')->firstOrFail();
        $lines = [];
        foreach ($agreement->content['lines'] as $line) {
            $lines[] = ['order_line_id' => $line['order_line_id'], 'description' => $line['description'], 'quantity' => (string) $line['quantity'], 'unit' => $line['unit'],
                'unit_price' => Money::of((int) $line['unit_price_minor'])->toInput(), 'commissionable' => (bool) $line['commissionable']];
        }
        if ($reduce) {
            // The customer drops the installation of the countertop: a price reduction that leaves a settlement.
            $lines[1]['unit_price'] = Money::of((int) round($lines[1]['unit_price'] * 100 * 0.5))->toInput();
        } else {
            $lines[] = ['description' => $increase ? 'إضافة رف جانبي مفتوح وإضاءة LED تحت الخزائن' : 'ترقية المقابض إلى مخفية (G-profile) لكامل المطبخ', 'quantity' => '1', 'unit' => 'unit',
                'unit_price' => (string) ($increase ? 2300 : 1850), 'commissionable' => true];
        }
        $discount = ['type' => 'none']; // a change is priced at list; the earlier discount stays in the first version
        $actor = $this->s->users['gm'];
        $change = app(T00CreateChange::class)->handle($actor, $order, $reduce ? 'تخفيض بند سطح العمل بناءً على طلب العميل' : 'تعديل نطاق العمل بعد اعتماد الاتفاق', $this->key());
        $change = app(UpdateChangeDraft::class)->handle($actor, $change, [
            'lines' => $lines, 'discount' => $discount, 'terms' => $agreement->content['terms'] ?? null, 'execution_days' => 34,
            'description' => $reduce ? 'تخفيض بند سطح العمل بناءً على طلب العميل' : 'تعديل نطاق العمل بعد اعتماد الاتفاق', 'affected' => [], 'delay_days' => $reduce ? 0 : 3,
        ], $change->lock_version, $this->key());
        $change = app(T01PresentChange::class)->handle($actor, $change->refresh(), $change->lock_version, $this->key());
        app(T04ApplyChange::class)->handle($actor, $change->refresh(), [
            'channel' => 'phone', 'decided_on' => CarbonImmutable::now()->toDateString(), 'note' => 'موافقة العميل هاتفيًا وتأكيد برسالة', 'content_hash' => (string) $change->content_hash,
        ], Assets::pdf('change-approval.pdf', 'Change approval'), $change->lock_version, $this->key());

        if ($reduce) {
            $this->s->timeline->at(CarbonImmutable::now()->addDays(1), new Story('reduction-refund'), 'refund', function () use ($order) {
                $this->refundOf($order, 0.3, 'فرق تخفيض بند سطح العمل بعد تعديل الاتفاق.');
            });
        }
    }

    private function refundOf(Order $order, float $share, string $reason): void
    {
        $order = $order->refresh();
        $payment = Payment::query()->where('order_id', $order->id)->orderBy('id')->firstOrFail();
        $amount = Money::of((int) round($payment->amount_minor * $share));
        $refund = app(T00RequestRefund::class)->handle($this->s->users['gm'], $order, [
            'payment_id' => $payment->id, 'amount' => $amount->toInput(), 'method' => 'transfer', 'reason' => $reason,
        ], FinanceLock::versionOf($order), $this->key());
        $this->s->timeline->at(CarbonImmutable::now()->addDay(), new Story('refund-pay-'.$refund->id), 'pay', function () use ($refund) {
            $refund = Refund::query()->findOrFail($refund->id);
            app(T02ConfirmRefund::class)->handle($this->s->users['finance'], $refund, ['reference' => 'TRX'.$this->s->rng->int(10000000, 99999999)],
                Assets::pdf('refund-proof.pdf', 'Refund'), $refund->lock_version, $this->key());
        });
    }

    private function cancel(string $reason, string $retained): void
    {
        $order = $this->pick(['manufacture', 'agreement', 'design']);
        if ($order === null) {
            throw new \RuntimeException('no order to cancel');
        }
        $scope = ['scope' => 'full', 'retained' => $retained === '0' ? null : $retained];
        $order->refresh();
        $hash = CancellationImpact::compute($order, CancellationInput::fromInput($scope), null)['hash'];
        app(T03RecordAndAcceptCancellation::class)->handle($this->s->users['gm'], $order, [
            'source' => 'external', 'channel' => 'phone', 'requested_on' => CarbonImmutable::now()->toDateString(), 'reason' => $reason, ...$scope,
        ], $hash, $order->lock_version, $this->key());
        $collected = (int) app(MoneyLedger::class)->collected($order->id)->jsonSerialize();
        if ($collected > 0) {
            $this->s->timeline->at(CarbonImmutable::now()->addDays(2), new Story('cancel-refund-'.$order->id), 'refund', function () use ($order) {
                $this->refundOf($order, 0.8, 'استرداد الدفعة بعد الإلغاء مع خصم تكلفة العمل المنفذ.');
            });
        }
    }

    private function correction(): void
    {
        $payment = Payment::query()->where('status', 'confirmed')->where('method', 'transfer')->where('amount_minor', '>', 500000)->orderByDesc('id')->skip(3)->firstOrFail();
        $order = Order::query()->findOrFail($payment->order_id);
        $order->refresh();
        app(T07CorrectPayment::class)->handle($this->s->users['finance'], $order, $payment, [
            'kind' => 'amend', 'reason' => 'خطأ في إدخال المبلغ: الحوالة الفعلية أقل من المسجّلة بحسب كشف الحساب البنكي.',
            'amount' => Money::of((int) round($payment->amount_minor * 0.9))->toInput(), 'method' => 'transfer', 'paid_on' => $payment->paid_on->toDateString(),
            'reference' => (string) $payment->reference,
        ], Assets::pdf('bank-statement.pdf', 'Statement'), FinanceLock::versionOf($order), $this->key());
    }

    private function dispute(): void
    {
        $payment = Payment::query()->where('status', 'confirmed')->where('amount_minor', '>', 300000)->orderByDesc('id')->skip(1)->firstOrFail();
        $order = Order::query()->findOrFail($payment->order_id)->refresh();
        app(T00OpenDispute::class)->handle($this->s->users['finance'], $order, [
            'payment_id' => $payment->id, 'subject' => 'settlement', 'amount' => '500', 'responsible_id' => $this->s->users['gm']->id,
            'reason' => 'يرى العميل أن قيمة التسوية أقل بمبلغ 500 ريال بسبب ملحق التركيب، وقيد المراجعة مع المحاسبة.',
        ], FinanceLock::versionOf($order), $this->key());
    }

    private function payouts(string $from, string $to): void
    {
        $actor = $this->s->users['owner'];
        foreach (['sales_ruh1', 'sales_ruh2', 'sales_jed', 'sales_dmm'] as $key) {
            try {
                $draft = app(T00CreatePayout::class)->handle($actor, $this->s->users[$key], ['period_from' => $from, 'period_to' => $to], $this->key());
                app(T01ApprovePayout::class)->handle($actor, $draft->refresh(), $draft->lock_version, $this->key(), [
                    'method' => 'transfer', 'paid_on' => CarbonImmutable::now()->toDateString(), 'reference' => 'SAL-'.strtoupper(Str::random(6)),
                ], Assets::pdf('payout-'.$key.'.pdf', 'Payout'));
            } catch (\Throwable $e) {
                // A member without accrued commission in the period has nothing to pay.
                if (! $e instanceof \Illuminate\Validation\ValidationException && ! $e instanceof \App\Access\Exceptions\InvalidTransition) {
                    throw $e;
                }
            }
        }
    }
}
