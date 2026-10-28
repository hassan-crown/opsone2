<?php

namespace App\Http\Controllers\Agreements;

use App\Actions\StateMachines\M08Document\ReviseDocument;
use App\Actions\StateMachines\M08Document\T01SendDocument;
use App\Actions\StateMachines\M08Document\T02RecordExternalApproval;
use App\Actions\StateMachines\M08Document\T06ExtendDocument;
use App\Actions\StateMachines\M08Document\T08CancelDocument;
use App\Actions\StateMachines\M08Document\UpdateDocumentDraft;
use App\Actions\StateMachines\M26Decision\T00RequestDiscount;
use App\Agreements\AgreementPresenter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agreements\ExtendRequest;
use App\Http\Requests\Agreements\ExternalApprovalRequest;
use App\Http\Requests\Agreements\KeyOnlyRequest;
use App\Http\Requests\Agreements\QuoteDraftRequest;
use App\Http\Requests\Agreements\ReasonRequest;
use App\Http\Requests\Agreements\SendDocumentRequest;
use App\Models\Agreements\AgreementDocument;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Writes on one approval document (M-08) and its discount request (M-26). Each answers with the refreshed agreement
 * payload; errors are the shared ones (403 Gate, 409 `{condition}` / `stale_version`, 422).
 */
class DocumentController extends Controller
{
    public function update(QuoteDraftRequest $request, AgreementDocument $document, UpdateDocumentDraft $update): JsonResponse
    {
        $update->handle($this->actor($request), $document, $request->body(), $request->lockVersion(), $request->key());

        return $this->respond($request, $document);
    }

    public function send(SendDocumentRequest $request, AgreementDocument $document, T01SendDocument $send): JsonResponse
    {
        $days = $request->validated('validity_days');
        $send->handle($this->actor($request), $document, $days === null ? null : (int) $days, $request->lockVersion(), $request->key());

        return $this->respond($request, $document);
    }

    public function revise(KeyOnlyRequest $request, AgreementDocument $document, ReviseDocument $revise): JsonResponse
    {
        $draft = $revise->handle($this->actor($request), $document, $request->key());

        return $this->respond($request, $document, ['document_id' => $draft->id], 201);
    }

    public function externalApproval(ExternalApprovalRequest $request, AgreementDocument $document, T02RecordExternalApproval $approve): JsonResponse
    {
        /** @var array{channel: string, decided_on: string, note?: string|null, content_hash: string} $data */
        $data = collect($request->body())->except('evidence')->all();
        $approve->handle($this->actor($request), $document, $data, $request->evidence(), $request->lockVersion(), $request->key());

        return $this->respond($request, $document);
    }

    public function extend(ExtendRequest $request, AgreementDocument $document, T06ExtendDocument $extend): JsonResponse
    {
        $extend->handle($this->actor($request), $document, (int) $request->validated('validity_days'), $request->lockVersion());

        return $this->respond($request, $document);
    }

    public function cancel(ReasonRequest $request, AgreementDocument $document, T08CancelDocument $cancel): JsonResponse
    {
        $cancel->handle($this->actor($request), $document, (string) $request->validated('reason'), $request->lockVersion());

        return $this->respond($request, $document);
    }

    public function requestDiscount(ReasonRequest $request, AgreementDocument $document, T00RequestDiscount $requestDiscount): JsonResponse
    {
        $decision = $requestDiscount->handle($this->actor($request), $document, (string) $request->validated('reason'), $request->lockVersion(), $request->key());

        return $this->respond($request, $document, ['decision_id' => $decision->id], 201);
    }

    /**
     * @param  array<string, int>  $extra
     */
    private function respond(Request $request, AgreementDocument $document, array $extra = [], int $status = 200): JsonResponse
    {
        return response()->json([...$extra, 'agreement' => AgreementPresenter::forOrder($document->order()->firstOrFail(), $this->actor($request))], $status);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
