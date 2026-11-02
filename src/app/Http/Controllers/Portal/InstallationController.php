<?php

namespace App\Http\Controllers\Portal;

use App\Calendar\CalendarPresenter;
use App\Http\Requests\Field\SignatureRequest;
use App\Installation\FieldPresenter;
use App\Installation\FieldVisits;
use App\Models\OrderLine;
use App\Portal\PortalAccess;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer accepts an installation from their own workspace (ADR-0036), when the technician sent them the
 * link instead of signing on the phone. The visit is found only among the customer's orders (PortalAccess).
 */
final class InstallationController extends PortalController
{
    public function show(string $visit): Response
    {
        $record = PortalAccess::installationVisit($this->customer(), $visit);
        $record->load(['order.lines', 'appointment']);
        $order = $record->order;

        return Inertia::render('portal/installation', [
            'installation' => [
                'id' => $record->id,
                'order' => ['id' => $order->id, 'number' => $order->number],
                'date' => CalendarPresenter::local($record->appointment->starts_at)->format('Y-m-d'),
                'items' => array_values($order->lines->filter(fn (OrderLine $l) => ! $l->isRemoved())->map(fn (OrderLine $l) => FieldPresenter::lineName($l))->all()),
                'signable' => FieldVisits::portalSignable($record),
                'signed' => $record->isSigned(),
                'signer_name' => $record->signer_name,
                'signed_at' => $record->signed_at === null ? null : CalendarPresenter::local($record->signed_at)->format('Y-m-d H:i'),
            ],
        ]);
    }

    public function sign(SignatureRequest $request, string $visit, FieldVisits $field): RedirectResponse
    {
        $customer = $this->customer();
        $record = PortalAccess::installationVisit($customer, $visit);
        $field->signFromPortal($customer, $record, [
            'signer_name' => (string) $request->input('signer_name'), 'signature' => (string) $request->input('signature'), 'consent' => $request->boolean('consent'),
        ], $request->ip(), $request->userAgent());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('installation.flash.portal_signed')]);

        return redirect()->route('customer.installations.show', $record->id);
    }
}
