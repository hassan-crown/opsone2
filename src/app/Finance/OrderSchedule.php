<?php

namespace App\Finance;

use App\Agreements\OrderAgreement;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\DueBasis;
use App\Enums\DueEvent;
use App\Models\Agreements\Agreement;
use App\Models\Finance\OrderDue;
use App\Models\Finance\OrderFinance;
use App\Models\Order;
use App\Models\User;
use App\Money\Money;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The order's payment schedule (FIN-02). It appears when the agreement is fixed — the approval creates it from the
 * facility template (`FlowAction_appr`) — and follows the active agreement: percentages and the «rest» are recomputed
 * from `OrderAgreement`, never from event numbers. `sync()` is idempotent on the agreement id and runs from the
 * `AgreementApplied` listener and at the start of every finance read and write, so a lost event heals itself.
 * Amounts never exceed the agreement; the «rest» (or the last percentage when they make 100%) takes the rounding.
 */
final class OrderSchedule
{
    /** Default template: 50% at the agreement, the rest when the final payment's stage starts (domain 05). */
    public const DEFAULT_TEMPLATE = 'half';

    /**
     * @var array<string, list<array{reference: string, name: array{ar: string, en: string}, basis: string, value: int|null, event: string}>>
     */
    public const TEMPLATES = [
        'half' => [
            ['reference' => 'first', 'name' => ['ar' => 'الدفعة الأولى', 'en' => 'First payment'], 'basis' => 'percent', 'value' => 5000, 'event' => 'agreement'],
            ['reference' => 'final', 'name' => ['ar' => 'الدفعة الأخيرة', 'en' => 'Final payment'], 'basis' => 'rest', 'value' => null, 'event' => 'payment_stage'],
        ],
        'single' => [
            ['reference' => 'first', 'name' => ['ar' => 'كامل المبلغ', 'en' => 'Full amount'], 'basis' => 'rest', 'value' => null, 'event' => 'agreement'],
        ],
    ];

    public const MAX_DUES = 12;

    public function __construct(private readonly Allocator $allocator) {}

    /**
     * Rules of one schedule row as a screen sends it (`FinSchedule`): amount text for `amount`, percentage text for
     * `percent`, nothing for `rest`.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'dues' => ['present', 'array', 'max:'.self::MAX_DUES],
            'dues.*.name' => ['required', 'array'],
            'dues.*.name.ar' => ['required', 'string', 'max:80'],
            'dues.*.name.en' => ['nullable', 'string', 'max:80'],
            'dues.*.reference' => ['required', 'string', 'regex:/^[a-z0-9_]{1,30}$/', 'distinct'],
            'dues.*.basis' => ['required', 'in:amount,percent,rest'],
            'dues.*.value' => ['nullable', 'string', 'max:20'],
            'dues.*.event' => ['required', 'in:agreement,payment_stage,delivery,date'],
            'dues.*.due_date' => ['nullable', 'required_if:dues.*.event,date', 'date_format:Y-m-d'],
        ];
    }

    /**
     * Builds or follows the schedule of the active agreement once per agreement version. Call under the order lock.
     */
    public function sync(Order $order, OrderFinance $head, ?User $actor, string $source): bool
    {
        $agreement = OrderAgreement::current($order);
        if ($agreement === null || $head->agreement_id === $agreement->id) {
            return false;
        }

        $value = $agreement->total();
        $dues = Allocator::openDues($order->id);
        $before = self::summary($dues);
        if ($dues->isEmpty() && $head->agreement_id === null) {
            foreach (self::TEMPLATES[self::DEFAULT_TEMPLATE] as $position => $row) {
                OrderDue::query()->create([
                    'order_id' => $order->id, 'position' => $position + 1, 'reference' => $row['reference'], 'name' => $row['name'],
                    'basis' => $row['basis'], 'basis_value' => $row['value'], 'amount_minor' => 0, 'due_event' => $row['event'],
                ]);
            }
            $dues = Allocator::openDues($order->id);
        }

        $amounts = self::fit(self::amounts(array_values($dues->map(fn (OrderDue $d) => ['basis' => $d->basis, 'value' => $d->basis_value])->all()), $value), $value);
        foreach ($dues->values() as $i => $due) {
            if ($due->amount_minor !== $amounts[$i]) {
                $due->forceFill(['amount_minor' => $amounts[$i]])->save();
            }
        }

        $head->forceFill(['agreement_id' => $agreement->id])->save();
        FinanceLock::touch($head);
        $this->allocator->trim($order, $actor, $source);
        $this->allocator->settle($order, $actor, $source);

        Audit::record(AuditAction::FinanceScheduleSynced, $order, $actor, before: ['dues' => $before], after: [
            'agreement_version' => $agreement->version, 'value_minor' => $value->minor, 'dues' => self::summary(Allocator::openDues($order->id)),
        ], source: $source, label: $order->number);

        return true;
    }

    /**
     * Replaces the schedule (FinSchedule, P-38). Refused when it exceeds the agreement or would leave a due holding
     * less than what was already allocated to it (`due_has_allocations`): a paid due never changes silently.
     *
     * @param  list<array{name: array{ar: string, en?: string|null}, reference: string, basis: string, value?: string|null, event: string, due_date?: string|null}>  $rows
     */
    public function replace(Order $order, Agreement $agreement, array $rows, User $actor, string $source): void
    {
        $value = $agreement->total();
        $parsed = [];
        foreach ($rows as $i => $row) {
            $parsed[] = ['basis' => DueBasis::from($row['basis']), 'value' => self::parseValue($row, $i)];
        }
        $amounts = self::amounts($parsed, $value, strict: true);

        $existing = OrderDue::query()->where('order_id', $order->id)->whereNull('removed_at')->get()->keyBy('reference');
        $allocated = MoneyLedger::allocatedByDue($order->id);
        $before = self::summary(Allocator::openDues($order->id));
        $kept = [];

        foreach ($rows as $i => $row) {
            $due = $existing->get($row['reference']);
            $held = $due === null ? 0 : ($allocated[$due->id] ?? 0);
            if ($amounts[$i] < $held) {
                throw ValidationException::withMessages(["dues.{$i}.value" => __('finance.validation.due_has_allocations')]);
            }
            $attributes = [
                'position' => $i + 1, 'name' => $row['name'], 'basis' => $parsed[$i]['basis'], 'basis_value' => $parsed[$i]['value'],
                'amount_minor' => $amounts[$i], 'due_event' => DueEvent::from($row['event']),
                'due_date' => $row['event'] === DueEvent::Date->value ? ($row['due_date'] ?? null) : null,
            ];
            if ($due === null) {
                $due = OrderDue::query()->create(['order_id' => $order->id, 'reference' => $row['reference'], ...$attributes]);
            } else {
                $due->forceFill($attributes)->save();
            }
            $kept[] = $due->id;
        }

        foreach ($existing as $due) {
            if (! in_array($due->id, $kept, true)) {
                if (($allocated[$due->id] ?? 0) > 0) {
                    throw ValidationException::withMessages(['dues' => __('finance.validation.due_has_allocations')]);
                }
                $due->forceFill(['removed_at' => now()])->save();
            }
        }

        $this->allocator->settle($order, $actor, $source);
        Audit::record(AuditAction::FinanceScheduleUpdated, $order, $actor, before: ['dues' => $before], after: [
            'dues' => self::summary(Allocator::openDues($order->id)),
        ], source: $source, label: $order->number);
    }

    /**
     * Amounts of a schedule for an agreed value, in order. Strict (a person's schedule): above the agreement is
     * refused. A `rest` takes what the others leave; without one, percentages that make exactly 100% put the rounding
     * on the last of them.
     *
     * @param  list<array{basis: DueBasis, value: int|null}>  $rows
     * @return list<int>
     */
    public static function amounts(array $rows, Money $value, bool $strict = false): array
    {
        $rests = array_keys(array_filter($rows, fn (array $r) => $r['basis'] === DueBasis::Rest));
        if (count($rests) > 1) {
            throw ValidationException::withMessages(['dues' => __('finance.validation.one_rest')]);
        }

        $amounts = [];
        foreach ($rows as $row) {
            $amounts[] = match ($row['basis']) {
                DueBasis::Amount => (int) $row['value'],
                DueBasis::Percent => $value->percent((int) $row['value'])->minor,
                DueBasis::Rest => 0,
            };
        }

        $percents = array_filter($rows, fn (array $r) => $r['basis'] === DueBasis::Percent);
        $onlyPercents = count($percents) === count($rows) && $rows !== [];
        if ($onlyPercents && array_sum(array_column($rows, 'value')) === 10000) {
            $last = array_key_last($rows);
            $amounts[$last] = $value->minor - (array_sum($amounts) - $amounts[$last]);
        }

        $sum = array_sum($amounts);
        if ($strict && $sum > $value->minor) {
            throw ValidationException::withMessages(['dues' => __('finance.validation.exceeds_agreement', ['total' => Money::of($sum)->toInput(), 'value' => $value->toInput()])]);
        }
        if ($rests !== []) {
            $amounts[$rests[0]] = max(0, $value->minor - $sum);
        }

        return array_values($amounts);
    }

    /**
     * After the agreement went down: lowers the latest dues until the schedule fits (never above the agreement).
     *
     * @param  list<int>  $amounts
     * @return list<int>
     */
    public static function fit(array $amounts, Money $value): array
    {
        $excess = array_sum($amounts) - $value->minor;
        for ($i = count($amounts) - 1; $i >= 0 && $excess > 0; $i--) {
            $cut = min($amounts[$i], $excess);
            $amounts[$i] -= $cut;
            $excess -= $cut;
        }

        return array_values($amounts);
    }

    /**
     * @param  array{basis: string, value?: string|null}  $row
     */
    private static function parseValue(array $row, int $i): ?int
    {
        $text = trim((string) ($row['value'] ?? ''));
        $basis = DueBasis::from($row['basis']);
        if ($basis === DueBasis::Rest) {
            return null;
        }
        if ($basis === DueBasis::Percent) {
            if (preg_match('/^(\d{1,3})(?:[.,](\d{1,2}))?$/', $text, $m) !== 1) {
                throw ValidationException::withMessages(["dues.{$i}.value" => __('finance.validation.percent')]);
            }
            $bp = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
            if ($bp < 1 || $bp > 10000) {
                throw ValidationException::withMessages(["dues.{$i}.value" => __('finance.validation.percent')]);
            }

            return $bp;
        }
        try {
            $amount = Money::fromInput($text);
        } catch (\Throwable) {
            throw ValidationException::withMessages(["dues.{$i}.value" => __('finance.validation.amount')]);
        }
        if ($amount->isNegative() || $amount->isZero()) {
            throw ValidationException::withMessages(["dues.{$i}.value" => __('finance.validation.amount')]);
        }

        return $amount->minor;
    }

    /**
     * @param  Collection<int, OrderDue>  $dues
     * @return list<array{reference: string, amount_minor: int}>
     */
    private static function summary(Collection $dues): array
    {
        return array_values($dues->map(fn (OrderDue $d) => ['reference' => $d->reference, 'amount_minor' => $d->amount_minor])->all());
    }
}
