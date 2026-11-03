<?php

namespace App\Http\Controllers\Portal;

use App\Actions\AppointmentRequests\SubmitAppointmentRequest;
use App\AppointmentRequests\AppointmentRequests;
use App\Http\Requests\AppointmentRequests\PortalAppointmentRequestRequest;
use App\Models\AppointmentRequest;
use App\Models\AppointmentRequestSettings;
use App\Portal\PortalAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer asks for an appointment from the workspace (ADR-0039): one of their open orders (or none), a kind the
 * facility allows and the fields its settings show. Their own requests are listed with their status only.
 */
final class AppointmentRequestController extends PortalController
{
    public function create(Request $request): Response
    {
        $customer = $this->customer();
        $settings = AppointmentRequestSettings::current();
        $orders = PortalAccess::orders($customer)->latest('id')->limit(100)->get()->reject(fn ($o) => $o->state()->isFinal())->values();
        $linked = $request->query('order');
        $fields = [];
        foreach (AppointmentRequestSettings::FIELDS as $field) {
            $fields[$field] = $settings->mode($field);
        }

        return Inertia::render('portal/appointment-request', [
            'enabled' => $settings->enabledFor(AppointmentRequest::SOURCE_PORTAL),
            'orders' => array_values($orders->map(fn ($o) => ['id' => $o->id, 'number' => $o->number])->all()),
            'order_id' => is_string($linked) ? $orders->firstWhere('id', (int) $linked)?->id : null,
            'kinds' => $settings->kinds,
            'fields' => $fields,
            'today' => now(AppointmentRequests::timezone())->format('Y-m-d'),
            'requests' => array_values(AppointmentRequest::query()->where('customer_id', $customer->id)->latest('id')->limit(10)->get()
                ->map(fn (AppointmentRequest $r) => [
                    'id' => $r->id, 'reference' => $r->reference, 'kind' => $r->kind,
                    // The customer sees whether it is still waiting for a call, booked or closed — never staff notes.
                    'status' => $r->status->isOpen() ? 'open' : $r->status->value,
                    'created_at' => $r->created_at->format('Y-m-d'),
                ])->all()),
        ]);
    }

    public function store(PortalAppointmentRequestRequest $request, SubmitAppointmentRequest $submit): RedirectResponse
    {
        $data = $request->validated();
        $created = $submit->fromPortal($this->customer(), $data['client_key'], Arr::except($data, ['client_key']));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('appointment_requests.portal.sent', ['ref' => (string) $created->reference])]);

        return $created->order_id !== null
            ? redirect()->route('customer.orders.show', $created->order_id)
            : redirect()->route('customer.appointment-requests.create');
    }
}
