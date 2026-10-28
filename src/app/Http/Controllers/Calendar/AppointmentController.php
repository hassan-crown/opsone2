<?php

namespace App\Http\Controllers\Calendar;

use App\Access\Access;
use App\Access\Permission;
use App\Actions\StateMachines\M16Appointment\T00ProposeAppointment;
use App\Actions\StateMachines\M16Appointment\T01SendToCustomer;
use App\Actions\StateMachines\M16Appointment\T02ConfirmAppointment;
use App\Actions\StateMachines\M16Appointment\T03RequestChange;
use App\Actions\StateMachines\M16Appointment\T04ApproveChange;
use App\Actions\StateMachines\M16Appointment\T05CompleteVisit;
use App\Actions\StateMachines\M16Appointment\T06CancelAppointment;
use App\Calendar\CalendarPresenter;
use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Calendar\AppointmentActionRequest;
use App\Http\Requests\Calendar\ChangeAppointmentRequest;
use App\Http\Requests\Calendar\StoreAppointmentRequest;
use App\Models\Appointment;
use App\Models\Order;
use App\Models\WorkSettings;
use App\Support\LockingTransaction;
use App\Workflow\AppointmentBooking;
use App\Workflow\AppointmentConflict;
use App\Workflow\WorkGuard;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ApptSchedule / ApptChange: the appointment screens over the M-16 Actions (docs/p5-engine-api.md). Controllers only
 * parse facility-local times and map refusals; authorization, locking and the atomic conflict check live in the Actions.
 */
class AppointmentController extends Controller
{
    public function store(StoreAppointmentRequest $request, T00ProposeAppointment $propose, T01SendToCustomer $send, T02ConfirmAppointment $confirm): RedirectResponse
    {
        $data = $request->validated();
        $order = Order::query()->findOrFail((int) $data['order_id']);

        // One transaction: a refused confirmation (policy «block», missing reason) leaves no stray proposal behind.
        $appointment = $this->refusable(fn () => LockingTransaction::run(function () use ($data, $order, $request, $propose, $send, $confirm) {
            $created = $propose->handle($request->user(), $order, [
                ...$data,
                'starts_at' => CalendarPresenter::parseLocal($data['starts_at'])->toIso8601String(),
                'ends_at' => CalendarPresenter::parseLocal($data['ends_at'])->toIso8601String(),
            ])['appointment'];

            return match ($data['confirm_mode']) {
                'send' => $send->handle($request->user(), $created),
                'confirm' => $confirm->handle($request->user(), $created, $data['confirmation_method'], $data['conflict_reason'] ?? null),
                default => $created,
            };
        }));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('appointments.saved')]);

        return to_route('appointments.show', $appointment);
    }

    /**
     * ApptChange (and the detail of any appointment).
     */
    public function show(Request $request, Appointment $appointment): Response
    {
        $user = $request->user();
        abort_unless(CalendarPresenter::canSee($user, $appointment), 404);
        $appointment->load(['order.branch', 'order.customer', 'members']);
        $manage = CalendarPresenter::canManage($user, $appointment);

        $pending = $appointment->pending_change;
        $pendingRow = null;
        if ($pending !== null) {
            $pendingRow = [
                'starts_at' => CalendarPresenter::local(CarbonImmutable::parse($pending['starts_at']))->format('Y-m-d\TH:i'),
                'ends_at' => CalendarPresenter::local(CarbonImmutable::parse($pending['ends_at']))->format('Y-m-d\TH:i'),
                'reason' => $pending['reason'],
            ];
        }
        $isMember = in_array($user->id, $appointment->memberIds(), true) && Access::can($user, Permission::P24, $appointment);
        $status = $appointment->status;

        return Inertia::render('calendar/appointment', [
            'appointment' => [
                ...CalendarPresenter::row($appointment, $user),
                'lock_version' => $appointment->lock_version,
                'confirmation_method' => $appointment->confirmation_method,
                'conflict_reason' => $manage ? $appointment->conflict_reason : null,
                'outcome' => $appointment->outcome,
                'cancel_reason' => $appointment->cancel_reason,
                'pending_change' => $manage ? $pendingRow : null,
            ],
            'can' => [
                'send' => $manage && $status === AppointmentStatus::Proposed,
                'confirm' => $manage && in_array($status, [AppointmentStatus::Proposed, AppointmentStatus::AwaitingConfirmation], true),
                'requestChange' => $manage && $status === AppointmentStatus::Confirmed && $pending === null,
                'resolveChange' => $manage && $status === AppointmentStatus::Confirmed && $pending !== null,
                'cancel' => $manage && $status !== AppointmentStatus::Done && $status !== AppointmentStatus::Cancelled,
                'complete' => ($manage || $isMember) && $status === AppointmentStatus::Confirmed,
            ],
            'allowsConflicts' => WorkSettings::current()->allowsConflicts(),
            'slotMinutes' => WorkSettings::current()->appointment_slot_minutes,
            'timezone' => AppointmentBooking::timezone(),
        ]);
    }

    public function send(AppointmentActionRequest $request, Appointment $appointment, T01SendToCustomer $action): RedirectResponse
    {
        $this->refusable(fn () => $action->handle($request->user(), $appointment, $request->integer('lock_version') ?: null));

        return $this->done($appointment);
    }

    public function confirm(AppointmentActionRequest $request, Appointment $appointment, T02ConfirmAppointment $action): RedirectResponse
    {
        $method = $request->validated('confirmation_method');
        if ($method === null) {
            throw ValidationException::withMessages(['confirmation_method' => __('workflow.validation.confirmation_method')]);
        }
        $this->refusable(fn () => $action->handle($request->user(), $appointment, $method, $request->validated('conflict_reason'), $request->integer('lock_version') ?: null));

        return $this->done($appointment);
    }

    public function requestChange(ChangeAppointmentRequest $request, Appointment $appointment, T03RequestChange $action): RedirectResponse
    {
        $data = $request->validated();
        $this->refusable(fn () => $action->handle(
            $request->user(), $appointment,
            CalendarPresenter::parseLocal($data['starts_at'])->toIso8601String(),
            CalendarPresenter::parseLocal($data['ends_at'])->toIso8601String(),
            $data['reason'], $data['lock_version'] ?? null,
        ));

        return $this->done($appointment);
    }

    public function discardChange(AppointmentActionRequest $request, Appointment $appointment, T03RequestChange $action): RedirectResponse
    {
        $this->refusable(fn () => $action->discard($request->user(), $appointment, (string) $request->validated('reason'), $request->integer('lock_version') ?: null));

        return $this->done($appointment);
    }

    public function approveChange(AppointmentActionRequest $request, Appointment $appointment, T04ApproveChange $action): RedirectResponse
    {
        $this->refusable(fn () => $action->handle($request->user(), $appointment, $request->validated('conflict_reason'), $request->integer('lock_version') ?: null));

        return $this->done($appointment);
    }

    public function cancel(AppointmentActionRequest $request, Appointment $appointment, T06CancelAppointment $action): RedirectResponse
    {
        $reason = WorkGuard::reason($request->validated('reason'));
        $this->refusable(fn () => $action->handle($request->user(), $appointment, $reason, $request->integer('lock_version') ?: null));

        return $this->done($appointment);
    }

    public function complete(AppointmentActionRequest $request, Appointment $appointment, T05CompleteVisit $action): RedirectResponse
    {
        $this->refusable(fn () => $action->handle($request->user(), $appointment, (string) $request->validated('outcome'), $request->boolean('no_show'), $request->integer('lock_version') ?: null));

        return $this->done($appointment);
    }

    private function done(Appointment $appointment): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __('appointments.saved')]);

        return to_route('appointments.show', $appointment);
    }

    /**
     * Maps a booking refusal to form errors: the message plus the condition, so the screen asks for a reason or
     * explains the block. Clashing appointments are shown from the preview endpoint, within the viewer's scope only.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function refusable(Closure $work): mixed
    {
        try {
            return $work();
        } catch (AppointmentConflict $e) {
            throw ValidationException::withMessages([
                'conflict' => __('workflow.appointment.'.$e->condition),
                'conflict_condition' => $e->condition,
            ]);
        }
    }
}
