<?php

namespace App\Http\Controllers\Portal;

use App\Agreements\CustomerActor;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Portal\PortalSession;

/**
 * Base of the customer workspace controllers: the customer comes from the `customer` guard only (AuthenticateCustomer
 * ran first), never from the request or a route id.
 */
abstract class PortalController extends Controller
{
    protected function customer(): Customer
    {
        return PortalSession::customer() ?? abort(401);
    }

    protected function actor(): CustomerActor
    {
        $customer = $this->customer();

        return new CustomerActor($customer->id, $customer->mobile);
    }
}
