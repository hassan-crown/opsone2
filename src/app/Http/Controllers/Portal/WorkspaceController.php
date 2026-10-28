<?php

namespace App\Http\Controllers\Portal;

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

        return Inertia::render('portal/order', [
            'order' => PortalPresenter::orderDetail($customer, PortalAccess::order($customer, $order)),
        ]);
    }

    public function notifications(): Response
    {
        return Inertia::render('portal/notifications', ['updates' => PortalPresenter::feed($this->customer(), 50)]);
    }
}
