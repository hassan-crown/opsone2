<?php

namespace App\Http\Controllers\Customers;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use App\Portal\PortalInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Staff send a customer the activation link of their workspace (ActivateAccount, P-10 over the customer).
 */
final class PortalInviteController extends Controller
{
    public function __invoke(Request $request, Customer $customer, PortalInvitation $invitation): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $invitation->send($user, $customer);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('portal.invite.sent')]);

        return back();
    }
}
