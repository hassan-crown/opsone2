<?php

namespace App\Http\Controllers\Notifications;

use App\Access\Access;
use App\Access\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\UpdateSmtpRequest;
use App\Integrations\FacilityMail;
use App\Integrations\IntegrationSettings;
use App\Mail\OrgMessage;
use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * SettingsIntegrations (SET-06, P-02): the facility's own SMTP account and the SMS state. The password is
 * write-only — it never appears in a response — and enabling needs a successful test (readiness).
 */
class IntegrationsController extends Controller
{
    public function edit(Request $request, IntegrationSettings $settings, SmsChannel $sms): Response
    {
        $this->authorizeManage($request);

        return Inertia::render('account/settings-integrations', [
            'smtp' => $settings->smtpView(),
            'smtpPorts' => UpdateSmtpRequest::PORTS,
            'sms' => ['configured' => $sms->isConfigured(), 'driver' => app()->environment('local', 'testing') ? 'log' : null],
            'fallbackMailer' => config('mail.default'),
        ]);
    }

    public function updateSmtp(UpdateSmtpRequest $request, IntegrationSettings $settings): RedirectResponse
    {
        $settings->saveSmtp($request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('integrations.smtp.saved')]);

        return back();
    }

    public function testSmtp(Request $request, IntegrationSettings $settings, FacilityMail $mail): RedirectResponse
    {
        $user = $this->authorizeManage($request);
        $draft = $settings->smtpDraft();

        if ($draft === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('integrations.smtp.test_incomplete')]);

            return back();
        }

        try {
            $mail->register($draft);
            $locale = app()->getLocale();
            Mail::mailer(FacilityMail::MAILER)->to($user->email)->send(
                (new OrgMessage(
                    title: __('integrations.smtp.test_subject'),
                    lines: [__('integrations.smtp.test_line')],
                    dir: $locale === 'ar' ? 'rtl' : 'ltr',
                ))->from($draft['from_address'], $draft['from_name'] ?? null),
            );
            $settings->recordSmtpTest($user, true);
            Inertia::flash('toast', ['type' => 'success', 'message' => __('integrations.smtp.test_ok', ['email' => $user->email])]);
        } catch (Throwable $e) {
            $settings->recordSmtpTest($user, false);
            // Class only: SMTP errors echo the username and server replies, which must not reach the logs.
            Log::warning('SMTP test failed', ['exception' => $e::class]);
            Inertia::flash('toast', ['type' => 'error', 'message' => __('integrations.smtp.test_failed')]);
        }

        return back();
    }

    public function enableSmtp(Request $request, IntegrationSettings $settings): RedirectResponse
    {
        $ok = $settings->setSmtpEnabled($this->authorizeManage($request), true);

        Inertia::flash('toast', $ok
            ? ['type' => 'success', 'message' => __('integrations.smtp.enabled')]
            : ['type' => 'error', 'message' => __('integrations.smtp.enable_needs_test')]);

        return back();
    }

    public function disableSmtp(Request $request, IntegrationSettings $settings): RedirectResponse
    {
        $settings->setSmtpEnabled($this->authorizeManage($request), false);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('integrations.smtp.disabled')]);

        return back();
    }

    private function authorizeManage(Request $request): User
    {
        $user = $request->user();
        abort_unless(Access::can($user, Permission::P02), 403);

        return $user;
    }
}
