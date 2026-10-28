<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Portal\CustomerOtp;
use App\Portal\OtpThrottled;
use App\Portal\PortalSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Passwordless customer sign-in (WEB-08, Q-47): mobile or email → one-time code → workspace. Every answer is the same
 * whether an account exists; the identifier stays in the (customer) session between the two steps.
 */
final class SignInController extends Controller
{
    public const SESSION_KEY = 'portal.otp';

    public function __construct(private readonly CustomerOtp $otp) {}

    public function show(): Response
    {
        return Inertia::render('portal/login', ['ttl_minutes' => CustomerOtp::TTL_MINUTES]);
    }

    public function send(Request $request): RedirectResponse
    {
        $data = $request->validate(['identifier' => ['required', 'string', 'max:191']]);
        $identifier = CustomerOtp::normalize((string) $data['identifier']);
        if ($identifier === null) {
            throw ValidationException::withMessages(['identifier' => __('portal.validation.identifier')]);
        }

        $this->issue($identifier, $request, 'identifier', CustomerOtp::LOGIN);
        $request->session()->put(self::SESSION_KEY, ['identifier' => $identifier, 'shown' => trim((string) $data['identifier'])]);

        return redirect()->route('customer.login.verify');
    }

    public function showVerify(Request $request): Response|RedirectResponse
    {
        $pending = $request->session()->get(self::SESSION_KEY);
        if (! is_array($pending)) {
            return redirect()->route('customer.login');
        }

        return Inertia::render('portal/verify', [
            'identifier' => (string) ($pending['shown'] ?? ''),
            'digits' => CustomerOtp::DIGITS,
            'ttl_minutes' => CustomerOtp::TTL_MINUTES,
            'resend_after' => CustomerOtp::RESEND_COOLDOWN,
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $pending = $request->session()->get(self::SESSION_KEY);
        if (! is_array($pending) || ! is_string($pending['identifier'] ?? null)) {
            return redirect()->route('customer.login');
        }

        try {
            $result = $this->otp->verify($pending['identifier'], preg_replace('/\D/', '', (string) $data['code']) ?? '', $request->ip());
        } catch (OtpThrottled $e) {
            throw ValidationException::withMessages(['code' => self::throttled($e)]);
        }
        if ($result === null) {
            throw ValidationException::withMessages(['code' => __('portal.otp.invalid')]);
        }

        $request->session()->forget(self::SESSION_KEY);
        PortalSession::signIn($request, $result['customer'], $result['channel']);

        return redirect()->intended(route('customer.home'));
    }

    public function resend(Request $request): RedirectResponse
    {
        $pending = $request->session()->get(self::SESSION_KEY);
        if (! is_array($pending) || ! is_string($pending['identifier'] ?? null)) {
            return redirect()->route('customer.login');
        }
        $this->issue($pending['identifier'], $request, 'code', CustomerOtp::LOGIN);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('portal.otp.resent')]);

        return redirect()->route('customer.login.verify');
    }

    public function logout(Request $request): RedirectResponse
    {
        PortalSession::signOut($request);

        return redirect()->route('customer.login');
    }

    public function logoutEverywhere(Request $request): RedirectResponse
    {
        PortalSession::signOut($request, everywhere: true);

        return redirect()->route('customer.login');
    }

    private function issue(string $identifier, Request $request, string $field, string $purpose): void
    {
        try {
            $this->otp->issue($identifier, $request->ip(), $purpose);
        } catch (OtpThrottled $e) {
            throw ValidationException::withMessages([$field => self::throttled($e)]);
        }
    }

    public static function throttled(OtpThrottled $e): string
    {
        return __('portal.otp.throttled', ['minutes' => max(1, (int) ceil($e->retryAfterSeconds / 60))]);
    }
}
