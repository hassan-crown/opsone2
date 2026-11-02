<?php

namespace App\Http\Controllers\Field;

use App\Http\Controllers\Controller;
use App\Http\Requests\Field\ArriveRequest;
use App\Http\Requests\Field\FinishRequest;
use App\Http\Requests\Field\PhotoRequest;
use App\Http\Requests\Field\RemarkRequest;
use App\Http\Requests\Field\SignatureRequest;
use App\Installation\FieldAccess;
use App\Installation\FieldRefused;
use App\Installation\FieldVisits;
use App\Installation\InstallationRemarks;
use App\Models\Appointment;
use App\Models\Installation\InstallationVisit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The technician's steps on a visit (ADR-0036). Every step re-checks inside FieldVisits / InstallationRemarks; the
 * membership check here only keeps a foreign appointment from revealing whether it has a visit.
 */
class FieldVisitController extends Controller
{
    public function arrive(ArriveRequest $request, Appointment $appointment, FieldVisits $field): RedirectResponse
    {
        $data = $request->validated();
        $field->arrive($this->user($request), $appointment,
            isset($data['latitude']) ? (float) $data['latitude'] : null,
            isset($data['longitude']) ? (float) $data['longitude'] : null,
            isset($data['accuracy']) ? (int) $data['accuracy'] : null);

        return to_route('field.visits.show', $appointment);
    }

    /** JSON: the device's upload queue calls it and retries with the same `client_uuid`. */
    public function photo(PhotoRequest $request, Appointment $appointment, FieldVisits $field): JsonResponse
    {
        $photo = $field->addPhoto($this->user($request), $this->visit($request, $appointment), (string) $request->input('phase'),
            $request->file('file'), (string) $request->input('client_uuid'));

        return response()->json(['photo' => ['id' => $photo->id, 'phase' => $photo->phase, 'client_uuid' => $photo->client_uuid]], $photo->wasRecentlyCreated ? 201 : 200);
    }

    public function remark(RemarkRequest $request, Appointment $appointment, InstallationRemarks $remarks): RedirectResponse
    {
        $remarks->record($this->user($request), $this->visit($request, $appointment), (string) $request->input('kind'),
            (string) $request->input('description'), $request->boolean('blocking'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('installation.flash.remark')]);

        return back();
    }

    public function sign(SignatureRequest $request, Appointment $appointment, FieldVisits $field): RedirectResponse
    {
        $field->signOnDevice($this->user($request), $this->visit($request, $appointment), [
            'signer_name' => (string) $request->input('signer_name'), 'signature' => (string) $request->input('signature'), 'consent' => $request->boolean('consent'),
        ], $request->ip(), $request->userAgent());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('installation.flash.signed')]);

        return back();
    }

    public function signatureLink(Request $request, Appointment $appointment, FieldVisits $field): RedirectResponse
    {
        $field->requestPortalSignature($this->user($request), $this->visit($request, $appointment));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('installation.flash.link_sent')]);

        return back();
    }

    public function finish(FinishRequest $request, Appointment $appointment, FieldVisits $field): RedirectResponse
    {
        $result = $field->finish($this->user($request), $this->visit($request, $appointment), $request->input('note'),
            $request->filled('lock_version') ? $request->integer('lock_version') : null);
        Inertia::flash('toast', ['type' => 'success', 'message' => __($result['stage_completed'] ? 'installation.flash.finished_stage' : 'installation.flash.finished')]);

        return to_route('field.index');
    }

    private function visit(Request $request, Appointment $appointment): InstallationVisit
    {
        FieldAccess::authorize($this->user($request), $appointment);

        return InstallationVisit::query()->where('appointment_id', $appointment->id)->first() ?? throw new FieldRefused('not_arrived');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
