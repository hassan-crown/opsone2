<?php

namespace App\Http\Controllers\Settlement;

use App\Actions\StateMachines\M39Dispute\T00OpenDispute;
use App\Actions\StateMachines\M39Dispute\T01AwaitExternal;
use App\Actions\StateMachines\M39Dispute\T02ResumeDispute;
use App\Actions\StateMachines\M39Dispute\T03ResolveDispute;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settlement\DisputeAwaitRequest;
use App\Http\Requests\Settlement\DisputeNoteRequest;
use App\Http\Requests\Settlement\DisputeRequest;
use App\Http\Requests\Settlement\DisputeResolveRequest;
use App\Models\Finance\FinancialDispute;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

/**
 * Financial disputes (M-39, P-85): FinDispute (open), waiting for an external party, FinDisputeResolve.
 */
class DisputeController extends Controller
{
    use SettlementResponses;

    public function store(DisputeRequest $request, Order $order, T00OpenDispute $action): JsonResponse
    {
        $dispute = $action->handle($this->actor($request), $order, $request->body(), $request->lockVersion(), $request->key());

        return $this->respond($request, $order, ['dispute_id' => $dispute->id], 201);
    }

    public function await(DisputeAwaitRequest $request, FinancialDispute $dispute, T01AwaitExternal $action): JsonResponse
    {
        $action->handle($this->actor($request), $dispute, (string) $request->validated('external_party'), $request->validated('note'), $request->lockVersion(), $request->key());

        return $this->respond($request, $dispute->order);
    }

    public function resume(DisputeNoteRequest $request, FinancialDispute $dispute, T02ResumeDispute $action): JsonResponse
    {
        $action->handle($this->actor($request), $dispute, (string) $request->validated('note'), $request->lockVersion(), $request->key());

        return $this->respond($request, $dispute->order);
    }

    public function resolve(DisputeResolveRequest $request, FinancialDispute $dispute, T03ResolveDispute $action): JsonResponse
    {
        $body = collect($request->body())->except('proof')->all();
        $action->handle($this->actor($request), $dispute, $body, $request->proof(), $request->lockVersion(), $request->key());

        return $this->respond($request, $dispute->order);
    }
}
