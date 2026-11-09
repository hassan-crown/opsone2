<?php

namespace App\Http\Controllers\Ai;

use App\Access\Access;
use App\Access\Contracts\LicenseCapabilities;
use App\Access\Permission;
use App\Ai\AiCredits;
use App\Ai\AiFeature;
use App\Ai\AiSettings;
use App\Ai\AutoReply\AutoReplySettings;
use App\Ai\Contracts\AiProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\UpdateAiSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SettingsAi (P-02, ADR-0050 §5): the facility's AI switches, this month's credits and the privacy notice. The
 * provider key and the driver are environment-only (Q-95): the page shows state, never a secret.
 */
class AiSettingsController extends Controller
{
    public function edit(Request $request, AiSettings $settings, AiCredits $credits, LicenseCapabilities $license, AiProvider $provider): Response
    {
        abort_unless(Access::can($request->user(), Permission::P02), 403);

        return Inertia::render('account/settings-ai', [
            'features' => array_map(fn (AiFeature $f) => [
                'key' => $f->value,
                'enabled' => $settings->enabled($f),
                'capability' => $f->capability(),
                'licensed' => $license->allows($f->capability()),
            ], AiFeature::cases()),
            'credits' => $credits->status(),
            'driver' => $provider->name(),
            // AI-6: the automatic-reply page is the owner's alone (Q-92).
            'canManageAutoReply' => AutoReplySettings::canManage($request->user()),
            // True only when calls can actually leave the server (Q-94 keeps this off until the legal review).
            'sendsExternally' => $provider->name() === 'anthropic' && config('ai.anthropic.enabled') === true && (string) config('ai.anthropic.api_key') !== '',
        ]);
    }

    public function update(UpdateAiSettingsRequest $request, AiSettings $settings): RedirectResponse
    {
        /** @var array<string, bool> $features */
        $features = array_map('boolval', (array) $request->validated('features'));
        $settings->save($request->user(), $features);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('ai.settings.saved')]);

        return back();
    }
}
