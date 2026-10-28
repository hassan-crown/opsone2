<?php

namespace App\Http\Controllers\Portal;

use App\Enums\CancellationStatus;
use App\Portal\CustomerCancellations;
use App\Portal\PortalAccess;
use App\Portal\PortalPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CancelRequest (P-99, WEB-13): the customer asks, the facility decides after its impact review; the customer may
 * withdraw a pending request.
 */
final class CancellationController extends PortalController
{
    public function __construct(private readonly CustomerCancellations $cancellations) {}

    public function create(string $order): Response
    {
        $customer = $this->customer();
        $record = PortalAccess::order($customer, $order);
        $current = PortalAccess::cancellations($customer)->where('order_id', $record->id)->latest('id')->first();

        return Inertia::render('portal/cancel', [
            'order' => PortalPresenter::order($record),
            'cancellation' => $current === null ? null : PortalPresenter::cancellation($current),
            'can_request' => PortalPresenter::cancellable($record) && ($current === null || $current->status !== CancellationStatus::Pending),
        ]);
    }

    public function store(Request $request, string $order): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $record = PortalAccess::order($this->customer(), $order);
        $this->cancellations->request($this->actor(), $record, $data['reason']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('portal.cancel.sent')]);

        return redirect()->route('customer.orders.show', $record->id);
    }

    public function withdraw(string $cancellation): RedirectResponse
    {
        $record = PortalAccess::cancellation($this->customer(), $cancellation);
        $this->cancellations->withdraw($this->actor(), $record);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('portal.cancel.withdrawn')]);

        return redirect()->route('customer.orders.show', $record->order_id);
    }
}
