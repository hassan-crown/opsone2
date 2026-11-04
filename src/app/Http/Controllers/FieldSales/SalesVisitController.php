<?php

namespace App\Http\Controllers\FieldSales;

use App\Actions\StateMachines\M08Document\T00CreateQuote;
use App\Actions\StateMachines\M08Document\T01SendDocument;
use App\Actions\Workflow\RecordMeasurement;
use App\Agreements\Signing\SignLinks;
use App\FieldSales\SalesVisits;
use App\Http\Controllers\Controller;
use App\Http\Requests\FieldSales\QuoteSendRequest;
use App\Http\Requests\FieldSales\SalesFinishRequest;
use App\Http\Requests\FieldSales\SalesMeasurementRequest;
use App\Http\Requests\FieldSales\SalesNoteRequest;
use App\Http\Requests\FieldSales\SalesPhotoRequest;
use App\Installation\FieldRefused;
use App\Models\Agreements\AgreementDocument;
use App\Models\Appointment;
use App\Models\FieldSales\SalesVisit;
use App\Models\Task;
use App\Models\User;
use App\Portal\PortalInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The field rep's steps on a visit/measurement appointment (ADR-0041). The visit's own steps re-check inside
 * SalesVisits; measurements, the quote, the signing link and the portal invite go through their existing actions,
 * which authorize the rep over the order (P-14, P-29, P-30, P-10) — the field app adds no access of its own.
 */
class SalesVisitController extends Controller
{
    public function start(Request $request, Appointment $appointment, SalesVisits $visits): RedirectResponse
    {
        $visits->start($this->user($request), $appointment);

        return to_route('field.visits.show', $appointment);
    }

    public function note(SalesNoteRequest $request, Appointment $appointment, SalesVisits $visits): RedirectResponse
    {
        $visits->note($this->user($request), $this->visit($request, $appointment), (string) $request->input('note'),
            $request->filled('lock_version') ? $request->integer('lock_version') : null);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('field_sales.flash.noted')]);

        return back();
    }

    /** JSON: the device's upload queue calls it and retries with the same `client_uuid`. */
    public function photo(SalesPhotoRequest $request, Appointment $appointment, SalesVisits $visits): JsonResponse
    {
        $photo = $visits->addPhoto($this->user($request), $this->visit($request, $appointment), $request->file('file'), (string) $request->input('client_uuid'));

        return response()->json(['photo' => ['id' => $photo->id, 'client_uuid' => $photo->client_uuid]], $photo->wasRecentlyCreated ? 201 : 200);
    }

    public function measure(SalesMeasurementRequest $request, Appointment $appointment, RecordMeasurement $record): RedirectResponse
    {
        $visit = $this->visit($request, $appointment);
        $task = $appointment->task_id === null ? null : Task::query()->with(['order', 'action'])->find($appointment->task_id);
        $record->handle($this->user($request), $visit->order()->firstOrFail(), $request->validated(), $task);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('field_sales.flash.measured')]);

        return back();
    }

    public function createQuote(Request $request, Appointment $appointment, T00CreateQuote $create): RedirectResponse
    {
        $this->visit($request, $appointment);
        $create->handle($this->user($request), $appointment->order()->firstOrFail(), "field-quote:{$appointment->id}");
        Inertia::flash('toast', ['type' => 'success', 'message' => __('field_sales.flash.quote_created')]);

        return back();
    }

    public function sendDocument(QuoteSendRequest $request, Appointment $appointment, AgreementDocument $document, T01SendDocument $send): RedirectResponse
    {
        $this->document($request, $appointment, $document);
        $days = $request->validated('validity_days');
        $send->handle($this->user($request), $document, $days === null ? null : (int) $days, $request->integer('lock_version'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('field_sales.flash.document_sent')]);

        return back();
    }

    public function signLink(Request $request, Appointment $appointment, AgreementDocument $document, SignLinks $links): RedirectResponse
    {
        $this->document($request, $appointment, $document);
        $links->send($this->user($request), $document);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('field_sales.flash.sign_link')]);

        return back();
    }

    public function portalInvite(Request $request, Appointment $appointment, PortalInvitation $invitation): RedirectResponse
    {
        $this->visit($request, $appointment);
        $invitation->send($this->user($request), $appointment->order()->firstOrFail()->customer()->firstOrFail());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('portal.invite.sent')]);

        return back();
    }

    public function finish(SalesFinishRequest $request, Appointment $appointment, SalesVisits $visits): RedirectResponse
    {
        $visits->finish($this->user($request), $this->visit($request, $appointment), (string) $request->input('outcome'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('field_sales.flash.finished')]);

        return to_route('field.index');
    }

    private function visit(Request $request, Appointment $appointment): SalesVisit
    {
        SalesVisits::authorize($this->user($request), $appointment);

        return SalesVisit::query()->where('appointment_id', $appointment->id)->first() ?? throw new FieldRefused('not_arrived');
    }

    /** A document of this visit's order (a foreign one is 404). */
    private function document(Request $request, Appointment $appointment, AgreementDocument $document): void
    {
        $this->visit($request, $appointment);
        abort_unless($document->order_id === $appointment->order_id, 404);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
