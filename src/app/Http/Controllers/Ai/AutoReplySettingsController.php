<?php

namespace App\Http\Controllers\Ai;

use App\Access\Contracts\LicenseCapabilities;
use App\Ai\AiFeature;
use App\Ai\AiSettings;
use App\Ai\AutoReply\AutoReplyIntent;
use App\Ai\AutoReply\AutoReplySettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\UpdateAutoReplySettingsRequest;
use App\Inbox\InboxSettings;
use App\Models\WorkSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → AI → automatic reply (AI-6, Q-92: the facility owner only): per intent on/off and «always» or «outside working hours
 * only», the optional hand-off note, and the approved template for a closed window. Everything is off by default.
 */
class AutoReplySettingsController extends Controller
{
    public function edit(Request $request, AutoReplySettings $settings, AiSettings $ai, LicenseCapabilities $license, InboxSettings $inbox): Response
    {
        abort_unless(AutoReplySettings::canManage($request->user()), 403);
        $values = $settings->all();
        $work = WorkSettings::current();

        return Inertia::render('account/settings-ai-auto-reply', [
            'featureEnabled' => $ai->enabled(AiFeature::AutoReply),
            'licensed' => $license->allows(AiFeature::AutoReply->capability()),
            'inboxEnabled' => $inbox->enabled(),
            'intents' => array_map(fn (AutoReplyIntent $i) => ['key' => $i->value, ...$values['intents'][$i->value]], AutoReplyIntent::cases()),
            'handoffNote' => $values['handoff_note'],
            'template' => $values['template'],
            // Only templates with exactly one parameter can carry the filled answer.
            'templates' => array_values(array_map(
                fn (array $t) => ['name' => $t['name'], 'language' => $t['language'], 'label' => $t['label']],
                array_filter($inbox->templates(), fn (array $t) => $t['params'] === 1),
            )),
            'workingHours' => ['from' => $work->work_starts_at, 'to' => $work->work_ends_at],
            'dailyCap' => (int) config('ai.auto_reply.daily_cap', 3),
        ]);
    }

    public function update(UpdateAutoReplySettingsRequest $request, AutoReplySettings $settings): RedirectResponse
    {
        $settings->save($request->user(), $request->values());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('ai.autoreply.settings.saved')]);

        return back();
    }
}
