<?php

namespace App\AppointmentRequests;

use App\Calendar\CalendarPresenter;
use App\Models\AppointmentRequest;
use App\Organization\OrgPresenter;

/**
 * Staff payload of a request (ADR-0039). Times in the facility time zone; links only as ids (each page re-checks its
 * own permission when opened).
 *
 * @phpstan-type RequestRow array<string, mixed>
 */
final class AppointmentRequestPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function row(AppointmentRequest $request): array
    {
        return [
            'id' => $request->id,
            'reference' => $request->reference,
            'status' => $request->status->value,
            'source' => $request->source,
            'kind' => $request->kind,
            'preferred_from' => $request->preferred_from?->format('Y-m-d'),
            'preferred_to' => $request->preferred_to?->format('Y-m-d'),
            'time_of_day' => $request->time_of_day,
            'name' => $request->name,
            'phone' => $request->phone,
            'city' => $request->city,
            'branch' => OrgPresenter::name($request->branch),
            'assignee' => $request->assignee === null ? null : ['id' => $request->assignee->id, 'name' => (string) $request->assignee->name],
            'due_at' => $request->due_at === null ? null : CalendarPresenter::local($request->due_at)->format('Y-m-d H:i'),
            'overdue' => $request->isOverdue(),
            'created_at' => CalendarPresenter::local($request->created_at)->format('Y-m-d H:i'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(AppointmentRequest $request): array
    {
        return [
            ...self::row($request),
            'address' => $request->address,
            'note' => $request->note,
            'lock_version' => $request->lock_version,
            'customer' => $request->customer === null ? null : ['id' => $request->customer->id, 'name' => $request->customer->name],
            'interest' => $request->interest === null ? null : ['id' => $request->interest->id, 'reference' => $request->interest->reference],
            'order' => $request->order === null ? null : ['id' => $request->order->id, 'number' => $request->order->number],
            'appointment_id' => $request->appointment_id,
            'scheduled_at' => $request->scheduled_at === null ? null : CalendarPresenter::local($request->scheduled_at)->format('Y-m-d H:i'),
            'close_reason' => $request->close_reason?->value,
            'close_note' => $request->close_note,
            'closed_at' => $request->closed_at === null ? null : CalendarPresenter::local($request->closed_at)->format('Y-m-d H:i'),
        ];
    }
}
