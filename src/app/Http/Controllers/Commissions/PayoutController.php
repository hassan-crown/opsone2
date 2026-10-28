<?php

namespace App\Http\Controllers\Commissions;

use App\Actions\StateMachines\M33Payout\T00CreatePayout;
use App\Actions\StateMachines\M33Payout\T01ApprovePayout;
use App\Actions\StateMachines\M33Payout\T02RecordPayout;
use App\Actions\StateMachines\M33Payout\T03CancelPayout;
use App\Actions\StateMachines\M33Payout\T04CorrectPayout;
use App\Commissions\CommissionPresenter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commissions\CommissionDecisionRequest;
use App\Http\Requests\Commissions\CommissionWriteRequest;
use App\Models\Commissions\CommissionPayout;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * FinPayout — M-33 writes. Each answers with the member's refreshed statement (`{statement: Payload}`).
 */
class PayoutController extends Controller
{
    public function __construct(private readonly CommissionPresenter $presenter) {}

    public function store(CommissionWriteRequest $request, User $member, T00CreatePayout $create): JsonResponse
    {
        $payout = $create->handle($this->actor($request), $member, $request->body(), $request->key());

        return $this->respond($request, $payout, 201);
    }

    /**
     * T01, or T01 + T02 at once when `pay_now` carries the execution details and the proof.
     */
    public function approve(CommissionWriteRequest $request, CommissionPayout $payout, T01ApprovePayout $approve): JsonResponse
    {
        $body = $request->body();
        $now = (bool) ($body['pay_now'] ?? false);
        if ($now && $request->proof() === null) {
            throw ValidationException::withMessages(['proof' => __('commissions.validation.proof')]);
        }
        $payout = $approve->handle($this->actor($request), $payout, $request->lockVersion(), $request->key(),
            $now ? collect($body)->only(['method', 'paid_on', 'reference', 'note'])->all() : null, $now ? $request->proof() : null);

        return $this->respond($request, $payout);
    }

    public function record(CommissionWriteRequest $request, CommissionPayout $payout, T02RecordPayout $record): JsonResponse
    {
        $proof = $request->proof() ?? throw ValidationException::withMessages(['proof' => __('commissions.validation.proof')]);
        $payout = $record->handle($this->actor($request), $payout, $request->body(), $proof, $request->lockVersion(), $request->key());

        return $this->respond($request, $payout);
    }

    public function cancel(CommissionDecisionRequest $request, CommissionPayout $payout, T03CancelPayout $cancel): JsonResponse
    {
        $payout = $cancel->handle($this->actor($request), $payout, (string) ($request->body()['reason'] ?? ''), $request->lockVersion());

        return $this->respond($request, $payout);
    }

    public function correct(CommissionWriteRequest $request, CommissionPayout $payout, T04CorrectPayout $correct): JsonResponse
    {
        $proof = $request->proof() ?? throw ValidationException::withMessages(['proof' => __('commissions.validation.proof')]);
        $payout = $correct->handle($this->actor($request), $payout, $request->body(), $proof, $request->lockVersion(), $request->key());

        return $this->respond($request, $payout);
    }

    private function respond(Request $request, CommissionPayout $payout, int $status = 200): JsonResponse
    {
        return response()->json([
            'payout_id' => $payout->id,
            'statement' => $this->presenter->member($this->actor($request), $payout->member),
        ], $status);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
