<?php

namespace App\Http\Controllers\Rules;

use App\Actions\Rules\RulesAccess;
use App\Actions\Rules\UpdateTaskSla;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rules\TaskSlaRequest;
use App\Models\WorkSettings;
use App\Tasks\TaskSla;
use App\Workflow\Catalogue\ActionType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › task SLA (ADR-0037): working days per action type, holidays and escalation. The working week is shown
 * from the work settings, where it is edited.
 */
class TaskSlaController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();
        RulesAccess::authorizeView($user);
        $settings = WorkSettings::current();
        $sla = $settings->task_sla ?? [];

        return Inertia::render('rules/sla', [
            'settings' => [
                'enabled' => (bool) ($sla['enabled'] ?? true),
                'default_days' => (int) ($sla['default'] ?? TaskSla::DEFAULT_DAYS),
                'by_type' => (object) ($sla['by_type'] ?? []),
                'holidays' => $settings->holidays ?? [],
                'escalate_after_days' => $settings->task_escalate_after_days,
            ],
            'types' => array_map(fn (ActionType $t) => ['type' => $t->value, 'label' => $t->label()[app()->getLocale()] ?? $t->label()['ar']], ActionType::cases()),
            'workingDays' => $settings->working_days,
            'canEdit' => RulesAccess::canEdit($user),
        ]);
    }

    public function update(TaskSlaRequest $request, UpdateTaskSla $update): RedirectResponse
    {
        $update->handle($request->user(), $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('rules.saved')]);

        return to_route('rules.sla');
    }
}
