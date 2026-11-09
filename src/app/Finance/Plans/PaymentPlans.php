<?php

namespace App\Finance\Plans;

use App\Configuration\ConfigReleases;
use App\Enums\DueBasis;
use App\Enums\DueEvent;
use App\Finance\OrderSchedule;
use App\Models\Finance\OrderFinance;
use App\Models\Finance\PaymentPlan;
use App\Models\Flow;
use App\Models\Order;
use App\Money\Money;
use App\Workflow\FlowTemplates;
use Closure;
use Illuminate\Validation\ValidationException;

/**
 * Payment plans (ADR-0037): the closed rules of a plan, the starting templates and which plan an order gets. A plan
 * is valid when it has 1..12 lines with unique keys, at most one `rest` (and then last), percentages making exactly
 * 100% when there is no `rest` (a fixed amount needs a `rest` to absorb the difference), triggers from the closed
 * catalogue and an existing flow stage for `stage.started`. A plan never carries VAT: shares apply to the agreed
 * value as the agreement shows it.
 */
final class PaymentPlans
{
    public const MAX_ACTIVE = 20;

    public const MAX_OFFSET_DAYS = 365;

    public const MAX_GRACE_DAYS = 90;

    /**
     * Starting templates (the owner copies one into a plan). `half` is also the built-in fallback when the facility
     * has no plan: it reproduces the schedule every order had before plans existed.
     *
     * @var array<string, array{name: array{ar: string, en: string}, lines: list<array{key: string, name: array{ar: string, en: string}, basis: string, value: int|null, event: string, stage_key: string|null, offset_days: int, grace_days: int}>}>
     */
    public const TEMPLATES = [
        'half' => ['name' => ['ar' => 'نصف عند الاتفاق والباقي عند مرحلة الدفعة الأخيرة', 'en' => 'Half at agreement, rest at the final payment stage'], 'lines' => [
            ['key' => 'first', 'name' => ['ar' => 'الدفعة الأولى', 'en' => 'First payment'], 'basis' => 'percent', 'value' => 5000, 'event' => 'agreement', 'stage_key' => null, 'offset_days' => 0, 'grace_days' => 0],
            ['key' => 'final', 'name' => ['ar' => 'الدفعة الأخيرة', 'en' => 'Final payment'], 'basis' => 'rest', 'value' => null, 'event' => 'payment_stage', 'stage_key' => null, 'offset_days' => 0, 'grace_days' => 0],
        ]],
        'single' => ['name' => ['ar' => 'كامل المبلغ عند الاتفاق', 'en' => 'Full amount at agreement'], 'lines' => [
            ['key' => 'first', 'name' => ['ar' => 'كامل المبلغ', 'en' => 'Full amount'], 'basis' => 'rest', 'value' => null, 'event' => 'agreement', 'stage_key' => null, 'offset_days' => 0, 'grace_days' => 0],
        ]],
        'fifty_fifty' => ['name' => ['ar' => '50% عند العقد و50% عند التسليم', 'en' => '50% at contract, 50% at delivery'], 'lines' => [
            ['key' => 'first', 'name' => ['ar' => 'الدفعة الأولى', 'en' => 'First payment'], 'basis' => 'percent', 'value' => 5000, 'event' => 'agreement', 'stage_key' => null, 'offset_days' => 0, 'grace_days' => 0],
            ['key' => 'final', 'name' => ['ar' => 'عند التسليم', 'en' => 'At delivery'], 'basis' => 'rest', 'value' => null, 'event' => 'delivery.completed', 'stage_key' => null, 'offset_days' => 0, 'grace_days' => 3],
        ]],
        'forty_forty_twenty' => ['name' => ['ar' => '40% عند العقد، 40% قبل التركيب، 20% بعد التسليم', 'en' => '40% at contract, 40% before installation, 20% after delivery'], 'lines' => [
            ['key' => 'first', 'name' => ['ar' => 'الدفعة الأولى', 'en' => 'First payment'], 'basis' => 'percent', 'value' => 4000, 'event' => 'agreement', 'stage_key' => null, 'offset_days' => 0, 'grace_days' => 0],
            ['key' => 'second', 'name' => ['ar' => 'قبل التركيب', 'en' => 'Before installation'], 'basis' => 'percent', 'value' => 4000, 'event' => 'stage.started', 'stage_key' => 'install', 'offset_days' => 0, 'grace_days' => 0],
            ['key' => 'final', 'name' => ['ar' => 'بعد التسليم', 'en' => 'After delivery'], 'basis' => 'rest', 'value' => null, 'event' => 'delivery.completed', 'stage_key' => null, 'offset_days' => 0, 'grace_days' => 3],
        ]],
    ];

    public const FALLBACK = 'half';

    /**
     * Stages of a flow that is not published yet, assumed for the duration of assumingFlow() (AI-1 setup proposal).
     *
     * @var list<array<string, mixed>>|null
     */
    private static ?array $assumedStages = null;

    /**
     * Input rules of a plan as the editor sends it: values are text (percent «40» or «33.33», amount «1500.00»).
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'key' => ['required', 'string', 'regex:/^[a-z0-9_]{1,30}$/'],
            'name' => ['required', 'array'],
            'name.ar' => ['required', 'string', 'max:120'],
            'name.en' => ['nullable', 'string', 'max:120'],
            'is_default' => ['sometimes', 'boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:'.OrderSchedule::MAX_DUES],
            'lines.*.key' => ['required', 'string', 'regex:/^[a-z0-9_]{1,30}$/', 'distinct'],
            'lines.*.name' => ['required', 'array'],
            'lines.*.name.ar' => ['required', 'string', 'max:80'],
            'lines.*.name.en' => ['nullable', 'string', 'max:80'],
            'lines.*.basis' => ['required', 'in:amount,percent,rest'],
            'lines.*.value' => ['nullable', 'string', 'max:20'],
            'lines.*.event' => ['required', 'in:'.implode(',', DueEvent::planTriggers())],
            'lines.*.stage_key' => ['nullable', 'string', 'max:40'],
            'lines.*.offset_days' => ['sometimes', 'integer', 'min:0', 'max:'.self::MAX_OFFSET_DAYS],
            'lines.*.grace_days' => ['sometimes', 'integer', 'min:0', 'max:'.self::MAX_GRACE_DAYS],
        ];
    }

    /**
     * Normalizes validated editor lines into stored lines, enforcing the plan rules (codes in `finance.plans.*`).
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{key: string, name: array{ar: string, en: string|null}, basis: string, value: int|null, event: string, stage_key: string|null, offset_days: int, grace_days: int}>
     */
    public static function normalize(array $lines): array
    {
        $stages = self::stageKeys();
        $out = [];
        foreach ($lines as $i => $line) {
            $basis = DueBasis::from((string) $line['basis']);
            $event = DueEvent::from((string) $line['event']);
            $stage = $event->needsStage() ? trim((string) ($line['stage_key'] ?? '')) : null;
            if ($event->needsStage() && ! in_array($stage, $stages, true)) {
                throw ValidationException::withMessages(["lines.{$i}.stage_key" => __('finance.plans.validation.stage_unknown')]);
            }
            $out[] = [
                'key' => (string) $line['key'],
                'name' => ['ar' => trim((string) $line['name']['ar']), 'en' => isset($line['name']['en']) && trim((string) $line['name']['en']) !== '' ? trim((string) $line['name']['en']) : null],
                'basis' => $basis->value,
                'value' => self::parseValue($basis, $line['value'] ?? null, "lines.{$i}.value"),
                'event' => $event->value,
                'stage_key' => $stage,
                'offset_days' => (int) ($line['offset_days'] ?? 0),
                'grace_days' => (int) ($line['grace_days'] ?? 0),
            ];
        }
        self::check($out);
        $keys = array_column($out, 'key');
        foreach (self::gateReferences() as $reference) {
            if (! in_array($reference, $keys, true)) {
                throw ValidationException::withMessages(['lines' => __('finance.plans.validation.gate_reference', ['reference' => $reference])]);
            }
        }

        return $out;
    }

    /**
     * References the published flow's `due` payment checks wait for: every plan must have a line with each, or the
     * check would ask for the whole agreed value.
     *
     * @return list<string>
     */
    public static function gateReferences(): array
    {
        $references = [];
        foreach (self::$assumedStages ?? Flow::current()?->publishedVersion?->definition['stages'] ?? [] as $stage) {
            foreach ($stage['actions'] ?? [] as $action) {
                if (($action['type'] ?? null) === 'payment_check' && ($action['inputs']['basis'] ?? null) === 'due') {
                    $references[] = (string) ($action['inputs']['reference'] ?? '');
                }
            }
        }

        return array_values(array_unique($references));
    }

    /**
     * The plan-level rules on normalized lines: one `rest`, last; without it, only percentages making exactly 100%.
     *
     * @param  list<array{basis: string, value: int|null}>  $lines
     */
    public static function check(array $lines): void
    {
        if ($lines === [] || count($lines) > OrderSchedule::MAX_DUES) {
            throw ValidationException::withMessages(['lines' => __('finance.plans.validation.size', ['max' => OrderSchedule::MAX_DUES])]);
        }
        $rests = array_keys(array_filter($lines, fn (array $l) => $l['basis'] === DueBasis::Rest->value));
        if (count($rests) > 1) {
            throw ValidationException::withMessages(['lines' => __('finance.validation.one_rest')]);
        }
        if ($rests !== [] && $rests[0] !== array_key_last($lines)) {
            throw ValidationException::withMessages(['lines' => __('finance.plans.validation.rest_last')]);
        }
        $percent = array_sum(array_map(fn (array $l) => $l['basis'] === DueBasis::Percent->value ? (int) $l['value'] : 0, $lines));
        $hasAmount = array_filter($lines, fn (array $l) => $l['basis'] === DueBasis::Amount->value) !== [];
        if ($rests === [] && ($hasAmount || $percent !== 10000)) {
            throw ValidationException::withMessages(['lines' => __('finance.plans.validation.sum', ['total' => self::percentText($percent)])]);
        }
        if ($rests !== [] && $percent >= 10000) {
            throw ValidationException::withMessages(['lines' => __('finance.plans.validation.rest_empty')]);
        }
    }

    /**
     * The plan an order gets at its first agreement, from the configuration release the order pinned (ADR-0040): the
     * one it chose (still in that release), else the release's default, else the built-in fallback.
     *
     * @return array{plan: PaymentPlan|null, key: string, lines: list<array{key: string, name: array{ar: string, en?: string|null}, basis: string, value: int|null, event: string, stage_key: string|null, offset_days: int, grace_days: int}>}
     */
    public static function forOrder(?int $chosenId, Order|int|null $order): array
    {
        $plans = self::released($order);
        $plan = $chosenId === null ? null : self::inRelease($order, $chosenId);
        $plan ??= collect($plans)->first(fn (PaymentPlan $p) => $p->is_default);
        if ($plan !== null) {
            return ['plan' => $plan, 'key' => $plan->key, 'lines' => $plan->lines];
        }

        return ['plan' => null, 'key' => self::FALLBACK, 'lines' => self::TEMPLATES[self::FALLBACK]['lines']];
    }

    /**
     * The plan an order would get now (its choice, else the default): previews before the schedule exists.
     *
     * @return array{plan: PaymentPlan|null, key: string, lines: list<array{key: string, name: array{ar: string, en?: string|null}, basis: string, value: int|null, event: string, stage_key: string|null, offset_days: int, grace_days: int}>}
     */
    public static function pending(int $orderId): array
    {
        return self::forOrder(OrderFinance::query()->where('order_id', $orderId)->value('payment_plan_id'), $orderId);
    }

    /**
     * The plans of the release an order pinned (the current release for an order without a pin).
     *
     * @return list<PaymentPlan>
     */
    public static function released(Order|int|null $order): array
    {
        return ConfigReleases::plans(ConfigReleases::forOrder($order));
    }

    public static function inRelease(Order|int|null $order, int $planId): ?PaymentPlan
    {
        return collect(self::released($order))->first(fn (PaymentPlan $p) => $p->id === $planId);
    }

    /**
     * Runs the callback as if `$stages` were the published flow: stage keys, gate references and the stage of a check
     * come from them. Used to check and save a configuration draft that belongs to a flow draft not yet published
     * (AI-1): the release check still runs against the real published flow when the rules are published.
     *
     * @template T
     *
     * @param  list<array<string, mixed>>  $stages
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function assumingFlow(array $stages, Closure $callback): mixed
    {
        $previous = self::$assumedStages;
        self::$assumedStages = $stages;
        try {
            return $callback();
        } finally {
            self::$assumedStages = $previous;
        }
    }

    /**
     * Stage keys a `stage.started` trigger may name: the published flow's stages, or the default template's before
     * any flow exists.
     *
     * @return list<string>
     */
    public static function stageKeys(): array
    {
        $stages = self::$assumedStages ?? Flow::current()?->publishedVersion?->definition['stages'] ?? FlowTemplates::definition(FlowTemplates::DEFAULT)['stages'];

        return array_map(fn (array $s) => (string) $s['key'], $stages);
    }

    /**
     * The published flow's stage holding the payment check with this reference (the «half» template's last due).
     */
    public static function stageOfCheck(string $reference): ?string
    {
        $stages = self::$assumedStages ?? Flow::current()?->publishedVersion?->definition['stages'] ?? FlowTemplates::definition(FlowTemplates::DEFAULT)['stages'];
        foreach ($stages as $stage) {
            foreach ($stage['actions'] ?? [] as $action) {
                if (($action['type'] ?? null) === 'payment_check' && ($action['inputs']['reference'] ?? null) === $reference) {
                    return (string) $stage['key'];
                }
            }
        }

        return null;
    }

    /**
     * The plans an order may choose, from its release, as screens show them (the default first).
     *
     * @return list<array<string, mixed>>
     */
    public static function options(Order|int|null $order): array
    {
        return array_values(collect(self::released($order))->sortBy([['is_default', 'desc'], ['key', 'asc']])
            ->map(fn (PaymentPlan $p) => self::present($p))->values()->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(PaymentPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'key' => $plan->key,
            'name' => $plan->name->get(),
            'name_i18n' => $plan->name->toArray(),
            'is_default' => $plan->is_default,
            'archived' => $plan->archived_at !== null,
            'lock_version' => $plan->lock_version,
            'lines' => array_map(fn (array $l) => [...$l, 'value' => self::valueText($l)], $plan->lines),
        ];
    }

    /**
     * Stages a `stage.started` trigger may name, with their names.
     *
     * @return list<array{key: string, name: string}>
     */
    public static function stageOptions(): array
    {
        $stages = self::$assumedStages ?? Flow::current()?->publishedVersion?->definition['stages'] ?? FlowTemplates::definition(FlowTemplates::DEFAULT)['stages'];
        $locale = app()->getLocale();

        return array_map(fn (array $s) => ['key' => (string) $s['key'], 'name' => (string) ($s['name'][$locale] ?? $s['name']['ar'] ?? $s['key'])], $stages);
    }

    /**
     * A stored line as the editor shows it (value as text).
     *
     * @param  array{basis: string, value: int|null}  $line
     */
    public static function valueText(array $line): ?string
    {
        return match ($line['basis']) {
            'percent' => self::percentText((int) $line['value']),
            'amount' => Money::of((int) $line['value'])->toInput(),
            default => null,
        };
    }

    private static function percentText(int $bp): string
    {
        return rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.');
    }

    private static function parseValue(DueBasis $basis, mixed $raw, string $path): ?int
    {
        if ($basis === DueBasis::Rest) {
            return null;
        }
        $text = trim((string) $raw);
        if ($basis === DueBasis::Percent) {
            if (preg_match('/^(\d{1,3})(?:[.,](\d{1,2}))?$/', $text, $m) !== 1) {
                throw ValidationException::withMessages([$path => __('finance.validation.percent')]);
            }
            $bp = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
            if ($bp < 1 || $bp > 10000) {
                throw ValidationException::withMessages([$path => __('finance.validation.percent')]);
            }

            return $bp;
        }
        try {
            $amount = Money::fromInput($text);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$path => __('finance.validation.amount')]);
        }
        if ($amount->isNegative() || $amount->isZero()) {
            throw ValidationException::withMessages([$path => __('finance.validation.amount')]);
        }

        return $amount->minor;
    }
}
