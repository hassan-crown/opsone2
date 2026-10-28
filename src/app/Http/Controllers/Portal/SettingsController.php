<?php

namespace App\Http\Controllers\Portal;

use App\Models\Customer;
use App\Portal\CustomerOtp;
use App\Portal\PortalSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer settings (P-65, WEB-08): the account's identity as the facility holds it (changing the mobile or email needs
 * ownership proof and goes through the facility in v1), language, and signing out of every device.
 */
final class SettingsController extends PortalController
{
    public function show(): Response
    {
        $customer = $this->customer();

        return Inertia::render('portal/settings', [
            'account' => [
                'name' => $customer->name,
                'mobile' => CustomerOtp::maskMobile($customer->mobile),
                'email' => $customer->email === null || $customer->email === '' ? null : CustomerOtp::maskEmail($customer->email),
                'email_verified' => $customer->email_verified_at !== null,
                'locale' => $customer->portal_locale,
                'last_login_at' => $customer->portal_last_login_at?->toIso8601String(),
            ],
        ]);
    }

    public function locale(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', Rule::in(config('app.available_locales'))]]);
        $customer = PortalSession::customer();
        if ($customer !== null) {
            Customer::query()->whereKey($customer->id)->update(['portal_locale' => $data['locale']]);
        }

        return redirect()->back()->withCookie(cookie()->forever('locale', $data['locale']));
    }
}
