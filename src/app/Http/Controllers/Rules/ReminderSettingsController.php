<?php

namespace App\Http\Controllers\Rules;

use App\Actions\Rules\RulesAccess;
use App\Actions\Rules\UpdateReminderSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rules\ReminderSettingsRequest;
use App\Models\ReminderSettings;
use App\Models\RoleTemplate;
use App\Models\WorkSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › reminders (ADR-0037): the collection reminder policy, escalation and the appointment lead time.
 */
class ReminderSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();
        RulesAccess::authorizeView($user);
        $settings = ReminderSettings::current();
        $work = WorkSettings::current();

        return Inertia::render('rules/reminders', [
            'settings' => [...$settings->only(array_keys(ReminderSettings::DEFAULTS)), 'lock_version' => $settings->exists ? $settings->lock_version : null],
            'roles' => array_values(RoleTemplate::query()->whereNotNull('key')->orderBy('id')->get()
                ->map(fn (RoleTemplate $r) => ['key' => (string) $r->key, 'name' => $r->name()])->all()),
            'hours' => ['from' => $work->reminder_from, 'until' => $work->reminder_until],
            'canEdit' => RulesAccess::canEdit($user),
        ]);
    }

    public function update(ReminderSettingsRequest $request, UpdateReminderSettings $update): RedirectResponse
    {
        $update->handle($request->user(), $request->safe()->except('lock_version'), $request->integer('lock_version') ?: null);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('rules.saved')]);

        return to_route('rules.reminders');
    }
}
