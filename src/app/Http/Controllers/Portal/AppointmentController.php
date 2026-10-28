<?php

namespace App\Http\Controllers\Portal;

use App\Portal\CustomerAppointments;
use App\Portal\PortalAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Appointment answers from the order page (P-70, WEB-11): confirm, or ask to change it.
 */
final class AppointmentController extends PortalController
{
    public function __construct(private readonly CustomerAppointments $appointments) {}

    public function confirm(string $appointment): RedirectResponse
    {
        $record = PortalAccess::appointment($this->customer(), $appointment);
        $this->appointments->confirm($this->actor(), $record);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('portal.appointments.confirmed')]);

        return redirect()->route('customer.orders.show', $record->order_id);
    }

    public function requestChange(Request $request, string $appointment): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'max:1000'],
            'preferred' => ['nullable', 'string', 'max:200'],
        ]);
        $record = PortalAccess::appointment($this->customer(), $appointment);
        $this->appointments->requestChange($this->actor(), $record, $data['note'], $data['preferred'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('portal.appointments.change_sent')]);

        return redirect()->route('customer.orders.show', $record->order_id);
    }
}
