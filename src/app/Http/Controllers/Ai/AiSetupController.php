<?php

namespace App\Http\Controllers\Ai;

use App\Actions\Rules\RulesAccess;
use App\Ai\AiFeature;
use App\Ai\AiGateway;
use App\Ai\AiSettings;
use App\Ai\AiSuggestions;
use App\Ai\AiSuggestionStatus;
use App\Ai\Onboarding\ApplyFlowSetup;
use App\Ai\Onboarding\FlowDraftProposer;
use App\Ai\Onboarding\FlowSetupCheck;
use App\Ai\Onboarding\ProposeFlowSetup;
use App\Enums\FlowStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\ApplyFlowSetupRequest;
use App\Http\Requests\Ai\ProposeFlowSetupRequest;
use App\Models\Ai\AiFlowSetup;
use App\Models\User;
use App\Workflow\FlowProvisioner;
use App\Workflow\FlowTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «اقتراح الإعداد» (AI-1, P-104): describe → questions → differences → «اعتمد كمسودة». The page shows the latest request
 * and polls while the model works; applying writes drafts only (ApplyFlowSetup). The description and answers are shown
 * back only to P-104 holders.
 */
class AiSetupController extends Controller
{
    public function show(Request $request, AiGateway $gateway, AiSettings $settings, FlowSetupCheck $check): Response
    {
        $actor = $this->actor($request);
        RulesAccess::authorize($actor);
        $flow = app(FlowProvisioner::class)->ensure();
        $latest = AiFlowSetup::query()->orderByDesc('id')->first();

        return Inertia::render('settings/ai-setup/index', [
            'available' => $gateway->available(AiFeature::FlowSetup),
            'enabled' => $settings->enabled(AiFeature::FlowSetup),
            'driver' => $gateway->driver(),
            'templates' => array_map(fn (string $k) => ['key' => $k, 'label' => FlowTemplates::label($k)[app()->getLocale() === 'en' ? 'en' : 'ar']], FlowTemplates::keys()),
            'flow' => ['lock_version' => $flow->lock_version, 'has_draft' => $flow->status === FlowStatus::PublishedWithDraft, 'template' => $flow->template_key],
            'setup' => $latest === null ? null : $this->present($latest, $check),
            'history' => AiFlowSetup::query()->orderByDesc('id')->limit(10)->get()->map(fn (AiFlowSetup $s) => [
                'id' => $s->id, 'status' => $this->status($s), 'created_at' => $s->created_at->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    public function store(ProposeFlowSetupRequest $request, AiSuggestions $suggestions, AiGateway $gateway): RedirectResponse
    {
        $actor = $this->actor($request);
        if (! $gateway->available(AiFeature::FlowSetup)) {
            throw ValidationException::withMessages(['description' => __('ai_setup.errors.unavailable')]);
        }

        DB::transaction(function () use ($request, $actor, $suggestions) {
            // A new request replaces any open one (its draft, if any, is rejected).
            foreach (AiFlowSetup::query()->whereIn('status', [AiFlowSetup::PENDING, AiFlowSetup::RUNNING, AiFlowSetup::READY])->lockForUpdate()->get() as $open) {
                $suggestion = FlowDraftProposer::suggestionOf($open);
                if ($suggestion !== null && $suggestion->status === AiSuggestionStatus::Proposed) {
                    $suggestions->reject($suggestion, $actor);
                }
                $open->forceFill(['status' => AiFlowSetup::SUPERSEDED, 'decided_by' => $actor->id, 'decided_at' => now()])->save();
            }

            $setup = AiFlowSetup::query()->create([
                'status' => AiFlowSetup::PENDING,
                'description' => trim((string) $request->validated('description')),
                'base_template' => $request->validated('base_template'),
                'answers' => array_values(array_map(fn (array $a) => ['question' => trim((string) $a['question']), 'answer' => trim((string) $a['answer'])], (array) ($request->validated('answers') ?? []))) ?: null,
                'requested_by' => $actor->id,
            ]);

            ProposeFlowSetup::dispatch($setup->id);
        });

        return to_route('ai-setup.show');
    }

    public function apply(ApplyFlowSetupRequest $request, int $setup, ApplyFlowSetup $apply): RedirectResponse
    {
        $result = $apply->handle(
            $this->actor($request),
            AiFlowSetup::query()->findOrFail($setup),
            (bool) $request->validated('include_plan'),
            array_values(array_map('strval', (array) $request->validated('rules'))),
            (int) $request->validated('lock_version'),
            (bool) $request->validated('replace_draft', false),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('ai_setup.applied', ['rules' => count($result['rules'])])]);

        return to_route('ai-setup.show');
    }

    public function reject(Request $request, int $setup, ApplyFlowSetup $apply): RedirectResponse
    {
        $apply->reject($this->actor($request), AiFlowSetup::query()->findOrFail($setup));

        return to_route('ai-setup.show');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AiFlowSetup $setup, FlowSetupCheck $check): array
    {
        $suggestion = FlowDraftProposer::suggestionOf($setup);
        $payload = $suggestion?->status === AiSuggestionStatus::Proposed || $suggestion?->status === AiSuggestionStatus::Accepted || $suggestion?->status === AiSuggestionStatus::Edited
            ? ($suggestion->final_payload ?? $suggestion->payload) : null;
        $status = $this->status($setup);

        return [
            'id' => $setup->id,
            'status' => $status,
            'outcome' => $setup->outcome,
            'base_template' => $setup->base_template,
            'description' => $setup->description,
            'answers' => $setup->answers ?? [],
            'created_at' => $setup->created_at->toIso8601String(),
            'expires_at' => $suggestion?->expires_at?->toIso8601String(),
            'proposal' => $payload === null ? null : [
                'base_template' => $payload['base_template'],
                'summary' => $payload['summary'],
                'questions' => $payload['questions'],
                'payment_plan' => $payload['payment_plan'],
                'automation' => array_map(fn (array $r) => ['key' => $r['key'], 'name' => $r['name']['ar'], 'trigger' => $r['trigger'], 'params' => $r['params'], 'why' => $r['why'] ?? ''], $payload['automation']),
                // Checked again on display: the facility's draft tables may have moved since the proposal.
                'checks' => $status === AiFlowSetup::READY ? $this->checks($check, $payload) : ($payload['checks'] ?? null),
                'diff' => $check->diff($payload),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function checks(FlowSetupCheck $check, array $payload): array
    {
        $result = $check->run($payload);

        return ['passes' => $result['passes'], 'flow_issues' => $result['flow_issues'], 'config_errors' => $result['config_errors']];
    }

    private function status(AiFlowSetup $setup): string
    {
        if (in_array($setup->status, [AiFlowSetup::PENDING, AiFlowSetup::RUNNING], true)) {
            return $setup->waiting() ? AiFlowSetup::PENDING : AiFlowSetup::FAILED;
        }

        return $setup->status;
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
