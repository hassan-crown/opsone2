<?php

namespace App\Http\Controllers\Rules;

use App\Actions\Rules\ArchivePaymentPlan;
use App\Actions\Rules\RulesAccess;
use App\Actions\Rules\SavePaymentPlan;
use App\Enums\DueEvent;
use App\Finance\OrderSchedule;
use App\Finance\Plans\PaymentPlans;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rules\PaymentPlanRequest;
use App\Models\Finance\PaymentPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › payment plans (ADR-0037): the plans with their lines, starting templates, the trigger catalogue and the
 * flow stages a «stage started» trigger may name. Reading needs P-103 or P-02; changes need P-103.
 */
class PaymentPlanController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        RulesAccess::authorizeView($user);
        $locale = app()->getLocale();

        return Inertia::render('rules/payment-plans', [
            'plans' => array_values(PaymentPlan::query()->orderByRaw('archived_at is not null')->orderByDesc('is_default')->orderBy('key')->get()
                ->map(fn (PaymentPlan $p) => PaymentPlans::present($p))->all()),
            'templates' => array_map(fn (string $key, array $t) => [
                'key' => $key, 'name' => $t['name'][$locale] ?? $t['name']['ar'],
                'lines' => array_map(fn (array $l) => [...$l, 'value' => PaymentPlans::valueText($l), 'event' => $l['event'] === DueEvent::PaymentStage->value ? DueEvent::StageStarted->value : $l['event'], 'stage_key' => $l['event'] === DueEvent::PaymentStage->value ? PaymentPlans::stageOfCheck('final') : $l['stage_key']], $t['lines']),
            ], array_keys(PaymentPlans::TEMPLATES), PaymentPlans::TEMPLATES),
            'triggers' => DueEvent::planTriggers(),
            'stages' => PaymentPlans::stageOptions(),
            'gateReferences' => PaymentPlans::gateReferences(),
            'limits' => ['lines' => OrderSchedule::MAX_DUES, 'plans' => PaymentPlans::MAX_ACTIVE, 'offset' => PaymentPlans::MAX_OFFSET_DAYS, 'grace' => PaymentPlans::MAX_GRACE_DAYS],
            'canEdit' => RulesAccess::canEdit($user),
        ]);
    }

    public function store(PaymentPlanRequest $request, SavePaymentPlan $save): RedirectResponse
    {
        $save->handle($request->user(), null, $request->safe()->except('lock_version'), null);

        return $this->saved();
    }

    public function update(PaymentPlanRequest $request, PaymentPlan $plan, SavePaymentPlan $save): RedirectResponse
    {
        $save->handle($request->user(), $plan, $request->safe()->except('lock_version'), $request->integer('lock_version') ?: null);

        return $this->saved();
    }

    public function archive(Request $request, PaymentPlan $plan, ArchivePaymentPlan $archive): RedirectResponse
    {
        $archive->handle($request->user(), $plan);

        return $this->saved();
    }

    private function saved(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __('rules.saved')]);

        return to_route('rules.plans');
    }
}
