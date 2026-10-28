<?php

namespace App\Http\Controllers\Agreements;

use App\Access\Access;
use App\Access\Permission;
use App\Actions\StateMachines\M26Decision\T01ApproveDiscount;
use App\Actions\StateMachines\M26Decision\T02RejectDiscount;
use App\Actions\StateMachines\M26Decision\T03CancelDiscount;
use App\Agreements\AgreementPresenter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agreements\DecisionRequest;
use App\Http\Requests\Agreements\ReasonRequest;
use App\Models\Agreements\DiscountDecision;
use App\Models\User;
use App\Orders\OrderAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Discount override decision (AgrDiscountDecision, AgrDecisionMobile — M-26, AGR-02): a P-31 holder in scope sees the
 * request's base, discount and total for this decision only (no P-37 needed), the requester sees their own request.
 */
class DiscountDecisionController extends Controller
{
    public function show(Request $request, DiscountDecision $decision): Response
    {
        $actor = $this->actor($request);
        $order = $decision->order;
        abort_unless($decision->requested_by === $actor->id || Access::can($actor, Permission::P31, $order), 403);

        return Inertia::render('agreements/decision', [
            'decision' => AgreementPresenter::decision($decision, $actor),
            'canOpenOrder' => OrderAccess::canView($actor, $order),
        ]);
    }

    public function approve(DecisionRequest $request, DiscountDecision $decision, T01ApproveDiscount $approve): JsonResponse
    {
        $decision = $approve->handle($this->actor($request), $decision, true, $request->validated('reason'), $request->lockVersion(), $request->key());

        return response()->json(['decision' => AgreementPresenter::decision($decision, $this->actor($request))]);
    }

    public function reject(ReasonRequest $request, DiscountDecision $decision, T02RejectDiscount $reject): JsonResponse
    {
        $decision = $reject->handle($this->actor($request), $decision, (string) $request->validated('reason'), $request->lockVersion(), $request->key());

        return response()->json(['decision' => AgreementPresenter::decision($decision, $this->actor($request))]);
    }

    public function cancel(Request $request, DiscountDecision $decision, T03CancelDiscount $cancel): JsonResponse
    {
        $version = $request->validate(['lock_version' => ['nullable', 'integer', 'min:1']])['lock_version'] ?? null;
        $decision = $cancel->handle($this->actor($request), $decision, $version === null ? null : (int) $version);

        return response()->json(['decision' => AgreementPresenter::decision($decision, $this->actor($request))]);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
