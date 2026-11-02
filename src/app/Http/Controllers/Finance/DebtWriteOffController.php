<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Finance\WriteOffDebt;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\WriteOffRequest;
use App\Http\Requests\Finance\WriteOffReversalRequest;
use App\Models\Finance\DebtWriteOff;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Debt write-off at closure and its reversal (P-103, ADR-0034). The Action authorizes on the order, locks it and
 * writes the balanced journal; this class only validates the envelope and answers.
 */
final class DebtWriteOffController extends Controller
{
    public function store(WriteOffRequest $request, Order $order, WriteOffDebt $writeOff): RedirectResponse|JsonResponse
    {
        $done = $writeOff->handle($this->actor($request), $order, (int) $request->validated('amount_minor'), (string) $request->validated('reason'), $request->upload(), $request->lockVersion(), $request->key());

        return $this->answer($request, $order, $done, __('finance.write_off.recorded', ['number' => (string) $done->number]), 201);
    }

    public function reverse(WriteOffReversalRequest $request, DebtWriteOff $writeOff, WriteOffDebt $action): RedirectResponse|JsonResponse
    {
        $done = $action->reverse($this->actor($request), $writeOff, (string) $request->validated('reason'), $request->lockVersion(), $request->key());

        return $this->answer($request, $writeOff->order, $done, __('finance.write_off.reversed', ['number' => (string) $done->number]));
    }

    private function answer(Request $request, Order $order, DebtWriteOff $done, string $message, int $status = 200): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['write_off' => ['id' => $done->id, 'number' => $done->number, 'kind' => $done->kind, 'amount_minor' => $done->amount_minor]], $status);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('orders.show', $order);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
