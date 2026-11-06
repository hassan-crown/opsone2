<?php

namespace App\Http\Controllers\Portal;

use App\Finance\BankTransfer\BankTransferDetails;
use App\Models\AppointmentRequest;
use App\Models\AppointmentRequestSettings;
use App\Portal\PortalAccess;
use App\Portal\PortalPresenter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CustHome, CustOrders, CustOrder and the notifications list (WEB-09): «مطلوب منك» first, then orders and updates.
 */
final class WorkspaceController extends PortalController
{
    public function home(): Response
    {
        $customer = $this->customer();
        $orders = PortalAccess::orders($customer)->latest('id')->limit(50)->get();

        return Inertia::render('portal/home', [
            'required' => PortalPresenter::requiredActions($customer),
            'orders' => array_values(array_filter(PortalPresenter::orders($orders), fn (array $row) => $row['open'])),
            'updates' => PortalPresenter::feed($customer, 5),
        ]);
    }

    public function orders(): Response
    {
        $customer = $this->customer();
        $orders = PortalAccess::orders($customer)->latest('id')->limit(200)->get();

        return Inertia::render('portal/orders', [
            'orders' => PortalPresenter::orders($orders),
        ]);
    }

    public function order(string $order): Response
    {
        $customer = $this->customer();
        $record = PortalAccess::order($customer, $order);

        return Inertia::render('portal/order', [
            'order' => PortalPresenter::orderDetail($customer, $record),
            // ADR-0046: transfer details beside the payment schedule; null hides the section.
            'bank' => BankTransferDetails::forCustomer(),
            // ADR-0039: the customer may ask for an appointment on an open order when the workspace form is on.
            'canRequestAppointment' => ! $record->state()->isFinal() && AppointmentRequestSettings::current()->enabledFor(AppointmentRequest::SOURCE_PORTAL),
        ]);
    }

    public function notifications(): Response
    {
        return Inertia::render('portal/notifications', ['updates' => PortalPresenter::feed($this->customer(), 50)]);
    }
}
