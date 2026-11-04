<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Portal\CustomerOtp;
use App\Portal\OtpThrottled;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ActivateAccount (WEB-08): the signed link staff sent opens this page; the customer asks for a code that goes to the
 * account's own mobile (or email), then verifies like any sign-in. The link itself never signs anyone in.
 */
final class ActivationController extends Controller
{
    public function __construct(private readonly CustomerOtp $otp) {}

    public function show(string $customer): Response
    {
        $account = self::account($customer);

        return Inertia::render('portal/activate', [
            'name' => $account->name,
            'destination' => $this->otp->destinationHint($account),
            'activated' => $account->portal_activated_at !== null,
            'send_url' => request()->fullUrlWithQuery([]),
        ]);
    }

    public function send(Request $request, string $customer): RedirectResponse
    {
        $account = self::account($customer);
        try {
            $this->otp->issue($account->mobile, $request->ip(), CustomerOtp::ACTIVATION);
        } catch (OtpThrottled $e) {
            throw ValidationException::withMessages(['code' => SignInController::throttled($e)]);
        }
        $request->session()->put(SignInController::SESSION_KEY, ['identifier' => $account->mobile, 'shown' => CustomerOtp::maskMobile($account->mobile)]);

        return redirect()->route('customer.login.verify');
    }

    private static function account(string $id): Customer
    {
        abort_unless(preg_match('/^\d{1,18}$/', $id) === 1, 404);
        $account = Customer::query()->whereKey((int) $id)->where('portal_login_enabled', true)->firstOrFail();
        // A link sent before the first activation or before staff turned the workspace off is spent (L-9).
        abort_unless((string) request()->query('v') === (string) $account->portal_invite_version, 403);

        return $account;
    }
}
