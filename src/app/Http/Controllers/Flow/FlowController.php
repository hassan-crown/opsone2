<?php

namespace App\Http\Controllers\Flow;

use App\Access\Access;
use App\Access\Permission;
use App\Actions\StateMachines\M23Flow\T01ChooseTemplate;
use App\Actions\StateMachines\M23Flow\T02SaveDraft;
use App\Actions\StateMachines\M23Flow\T03PublishFlow;
use App\Actions\StateMachines\M23Flow\T04DiscardDraft;
use App\Flow\FlowPresenter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flow\FlowRequest;
use App\Models\Flow;
use App\Models\User;
use App\Workflow\FlowCheck;
use App\Workflow\FlowProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Flow settings screens (M-23, P5): `FlowEdit`, `FlowStage1-5`, `FlowCheck` and the template choice. The page needs
 * P-20; checking and publishing need P-21 (the Actions re-authorize every write). Contract: docs/p5-engine-api.md.
 */
class FlowController extends Controller
{
    public function edit(Request $request): Response
    {
        return $this->page($request, null);
    }

    /**
     * A stage of the flow (FlowStage1-5): the same editor with that stage open.
     */
    public function stage(Request $request, string $stage): Response
    {
        return $this->page($request, $stage);
    }

    public function saveDraft(FlowRequest $request, T02SaveDraft $save): JsonResponse
    {
        $flow = $save->handle($this->actor($request), ['stages' => $request->stages()], (int) $request->validated('lock_version'));

        return $this->flowResponse($flow);
    }

    public function discardDraft(FlowRequest $request, T04DiscardDraft $discard): JsonResponse
    {
        return $this->flowResponse($discard->handle($this->actor($request), (int) $request->validated('lock_version')));
    }

    /**
     * Live feedback for the editor and the FlowCheck screen: issues linked to the stage/action that causes them.
     */
    public function check(FlowRequest $request, FlowCheck $check): JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless(Access::can($actor, Permission::P21) || Access::can($actor, Permission::P20), 403);
        $result = $check->run(['stages' => $request->stages()]);

        return response()->json(['passes' => $result->passes(), 'issues' => $result->issues]);
    }

    public function publish(FlowRequest $request, T03PublishFlow $publish): JsonResponse
    {
        return $this->flowResponse($publish->handle($this->actor($request), (int) $request->validated('lock_version')));
    }

    public function chooseTemplate(FlowRequest $request, T01ChooseTemplate $choose): JsonResponse
    {
        $flow = $choose->handle(
            $this->actor($request),
            (string) $request->validated('template'),
            (bool) $request->validated('discard_draft', false),
            (int) $request->validated('lock_version'),
        );

        return $this->flowResponse($flow);
    }

    private function page(Request $request, ?string $stage): Response
    {
        $actor = $this->actor($request);
        abort_unless(Access::can($actor, Permission::P20), 403);
        $flow = app(FlowProvisioner::class)->ensure();

        return Inertia::render('flow/edit', [
            'flow' => FlowPresenter::state($flow),
            'versions' => FlowPresenter::versions($flow),
            'options' => FlowPresenter::options(),
            'can' => ['edit' => true, 'publish' => Access::can($actor, Permission::P21)],
            'selected' => ['stage' => $stage, 'action' => is_string($request->query('action')) ? $request->query('action') : null],
        ]);
    }

    private function flowResponse(Flow $flow): JsonResponse
    {
        return response()->json(['flow' => FlowPresenter::state($flow->refresh()), 'versions' => FlowPresenter::versions($flow)]);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
