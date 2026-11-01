<?php

namespace App\Http\Controllers\Customers;

use App\Access\Access;
use App\Access\Permission;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Consent for WhatsApp messages (ADR-0029): staff with P-10 over the customer record that the customer asked to stop
 * (or to resume). SMS keeps working; only the WhatsApp channel is switched off for this customer.
 */
class CustomerMessagingController extends Controller
{
    public function __invoke(Request $request, Customer $customer): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        Access::authorize($actor, Permission::P10, $customer);
        $optOut = $request->validate(['whatsapp_opt_out' => ['required', 'boolean']])['whatsapp_opt_out'];

        if ($optOut !== ($customer->whatsapp_opt_out_at !== null)) {
            DB::transaction(function () use ($customer, $actor, $optOut) {
                $customer->forceFill([
                    'whatsapp_opt_out_at' => $optOut ? now() : null,
                    'whatsapp_opt_out_source' => $optOut ? 'staff' : null,
                ])->save();
                Audit::record($optOut ? AuditAction::CustomerWhatsappOptOut : AuditAction::CustomerWhatsappOptIn, $customer, $actor, after: ['source' => 'staff'], label: $customer->name);
            });
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __($optOut ? 'whatsapp.consent.opted_out' : 'whatsapp.consent.opted_in')]);

        return back();
    }
}
