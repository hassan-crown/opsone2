<?php

namespace App\Http\Controllers\Agreements;

use App\Actions\StateMachines\M09Change\T01PresentChange;
use App\Actions\StateMachines\M09Change\T02RequestChangeRevision;
use App\Actions\StateMachines\M09Change\T04ApplyChange;
use App\Actions\StateMachines\M09Change\T05WithdrawChange;
use App\Actions\StateMachines\M09Change\UpdateChangeDraft;
use App\Actions\StateMachines\M26Decision\T00RequestDiscount;
use App\Agreements\AgreementPresenter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agreements\ChangeDraftRequest;
use App\Http\Requests\Agreements\ExternalApprovalRequest;
use App\Http\Requests\Agreements\KeyOnlyRequest;
use App\Http\Requests\Agreements\ReasonRequest;
use App\Models\Agreements\AgreementChange;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Writes on an agreement change (M-09: AgrChange, AgrChangePending, AgrExternalChange) and its discount request.
 */
class ChangeController extends Controller
{
    public function update(ChangeDraftRequest $request, AgreementChange $change, UpdateChangeDraft $update): JsonResponse
    {
        $update->handle($this->actor($request), $change, $request->body(), $request->lockVersion(), $request->key());

        return $this->respond($request, $change);
    }

    public function present(KeyOnlyRequest $request, AgreementChange $change, T01PresentChange $present): JsonResponse
    {
        $present->handle($this->actor($request), $change, $request->lockVersion(), $request->key());

        return $this->respond($request, $change);
    }

    public function requestRevision(ReasonRequest $request, AgreementChange $change, T02RequestChangeRevision $revision): JsonResponse
    {
        $revision->handle($this->actor($request), $change, (string) $request->validated('reason'), $request->lockVersion());

        return $this->respond($request, $change);
    }

    public function apply(ExternalApprovalRequest $request, AgreementChange $change, T04ApplyChange $apply): JsonResponse
    {
        /** @var array{channel: string, decided_on: string, note?: string|null, content_hash: string} $data */
        $data = collect($request->body())->except('evidence')->all();
        $apply->handle($this->actor($request), $change, $data, $request->evidence(), $request->lockVersion(), $request->key());

        return $this->respond($request, $change);
    }

    public function withdraw(ReasonRequest $request, AgreementChange $change, T05WithdrawChange $withdraw): JsonResponse
    {
        $withdraw->handle($this->actor($request), $change, (string) $request->validated('reason'), $request->lockVersion());

        return $this->respond($request, $change);
    }

    public function requestDiscount(ReasonRequest $request, AgreementChange $change, T00RequestDiscount $requestDiscount): JsonResponse
    {
        $decision = $requestDiscount->handle($this->actor($request), $change, (string) $request->validated('reason'), $request->lockVersion(), $request->key());

        return $this->respond($request, $change, ['decision_id' => $decision->id], 201);
    }

    /**
     * @param  array<string, int>  $extra
     */
    private function respond(Request $request, AgreementChange $change, array $extra = [], int $status = 200): JsonResponse
    {
        return response()->json([...$extra, 'agreement' => AgreementPresenter::forOrder($change->order()->firstOrFail(), $this->actor($request))], $status);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
