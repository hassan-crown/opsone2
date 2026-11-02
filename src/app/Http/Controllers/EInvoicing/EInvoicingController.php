<?php

namespace App\Http\Controllers\EInvoicing;

use App\EInvoicing\EInvoicePdf;
use App\EInvoicing\EInvoiceSubmitter;
use App\EInvoicing\InvoiceBlocked;
use App\EInvoicing\InvoiceIssuer;
use App\EInvoicing\MissingInvoices;
use App\EInvoicing\Zatca\Api\ZatcaClients;
use App\EInvoicing\ZatcaAccess;
use App\EInvoicing\ZatcaOnboarding;
use App\EInvoicing\ZatcaProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\EInvoicing\IssueNoteRequest;
use App\Http\Requests\EInvoicing\OnboardZatcaRequest;
use App\Http\Requests\EInvoicing\UpdateZatcaSettingsRequest;
use App\Models\EInvoicing\EInvoice;
use App\Models\EInvoicing\ZatcaDevice;
use App\Models\EInvoicing\ZatcaSettings;
use App\Models\Order;
use App\Models\User;
use App\Support\PropRows;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * SettingsEInvoicing (ADR-0033), the owner's control panel for ZATCA: seller tax profile, environment and switch,
 * device onboarding (OTP → compliance CSID → checks → production CSID), the per-document submission list with retry,
 * missing invoices and manual credit/debit notes. PDF/XML downloads are also open to P-37 on the order. Secrets
 * (private key, CSID secrets) never reach a response.
 */
class EInvoicingController extends Controller
{
    private const LIST_LIMIT = 100;

    public function edit(Request $request, MissingInvoices $missing): Response
    {
        $this->authorizeManage($request);
        $settings = ZatcaSettings::current();
        $device = ZatcaDevice::live();
        $status = $request->query('status');
        $status = is_string($status) && in_array($status, [EInvoice::NOT_REQUIRED, EInvoice::PENDING, EInvoice::REPORTED, EInvoice::CLEARED, EInvoice::REJECTED, EInvoice::FAILED], true) ? $status : null;

        $invoices = EInvoice::query()
            ->with(['order:id,number', 'customer:id,name', 'original:id,number'])
            ->when($status !== null, fn ($q) => $q->where('submission_status', $status))
            ->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get();

        return Inertia::render('account/settings-einvoicing', [
            'settings' => [
                ...$settings->only(ZatcaSettings::PROFILE),
                'enabled' => (bool) $settings->enabled,
                'environment' => $settings->environment,
                'enabled_at' => $settings->enabled_at?->toIso8601ZuluString(),
                'lock_version' => $settings->exists ? $settings->lock_version : null,
                'profile_complete' => $settings->profileComplete(),
            ],
            'environments' => ZatcaClients::allowed(),
            'device' => $device === null ? null : [
                'id' => $device->id,
                'status' => $device->status,
                'environment' => $device->environment,
                'common_name' => $device->common_name,
                'onboarded_at' => $device->onboarded_at?->toIso8601ZuluString(),
                'certificate_expires_at' => $device->certificate_expires_at?->toIso8601ZuluString(),
                'compliance_checks' => $device->compliance_checks ?? [],
                'last_error' => $device->last_error,
            ],
            'counts' => EInvoice::query()->selectRaw('submission_status, count(*) as total')->groupBy('submission_status')->pluck('total', 'submission_status')->map(fn ($n) => (int) $n)->all(),
            'filter' => $status,
            'invoices' => PropRows::of($invoices->map(fn (EInvoice $i) => [
                'id' => $i->id,
                'number' => $i->number,
                'kind' => $i->kind,
                'type' => $i->type,
                'order' => ['id' => $i->order_id, 'number' => $i->order->number],
                'customer' => $i->customer->name,
                'original' => $i->original?->number,
                'total_minor' => $i->total_minor,
                'vat_minor' => $i->vat_minor,
                'issued_at' => $i->issued_at->toIso8601ZuluString(),
                'status' => $i->submission_status,
                'warnings' => $i->submission_warnings,
                'attempts' => $i->submission_attempts,
                'messages' => array_slice([...($i->zatca_response['errors'] ?? []), ...($i->zatca_response['warnings'] ?? [])], 0, 3),
                'error' => $i->zatca_response['error'] ?? null,
                'can_retry' => $i->submission_status === EInvoice::FAILED && $i->signed,
                'creditable_minor' => $i->kind === EInvoice::KIND_INVOICE ? InvoiceIssuer::creditable($i) : null,
            ])),
            'missing' => $missing->orders(20)->map(fn (Order $o) => ['id' => $o->id, 'number' => $o->number, 'customer' => $o->customer->name])->values()->all(),
        ]);
    }

    public function update(UpdateZatcaSettingsRequest $request, ZatcaProfile $profile): RedirectResponse
    {
        $data = $request->validated();
        $profile->save($request->user(), $data, isset($data['lock_version']) ? (int) $data['lock_version'] : null);

        return $this->toast('success', 'einvoicing.settings.saved');
    }

    public function onboard(OnboardZatcaRequest $request, ZatcaOnboarding $onboarding): RedirectResponse
    {
        if (! in_array(ZatcaSettings::current()->environment, ZatcaClients::allowed(), true)) {
            return $this->toast('error', 'einvoicing.onboarding.environment_not_allowed');
        }
        try {
            $device = $onboarding->start($request->user(), (string) $request->validated('otp'));
        } catch (InvoiceBlocked $e) {
            return $this->toast('error', 'einvoicing.blocked.'.$e->reason);
        } catch (InvalidArgumentException|RuntimeException $e) {
            // Class only: messages may echo gateway output.
            Log::warning('ZATCA onboarding failed', ['exception' => $e::class]);

            return $this->toast('error', 'einvoicing.onboarding.failed');
        }

        return $device->status === ZatcaDevice::ACTIVE
            ? $this->toast('success', 'einvoicing.onboarding.done')
            : $this->toast('error', 'einvoicing.onboarding.failed');
    }

    public function resume(Request $request, ZatcaOnboarding $onboarding): RedirectResponse
    {
        $user = $this->authorizeManage($request);
        $device = ZatcaDevice::live();
        if ($device === null || $device->status !== ZatcaDevice::COMPLIANCE) {
            return $this->toast('error', 'einvoicing.onboarding.nothing_to_resume');
        }
        $device = $onboarding->complete($device, $user);

        return $device->status === ZatcaDevice::ACTIVE
            ? $this->toast('success', 'einvoicing.onboarding.done')
            : $this->toast('error', 'einvoicing.onboarding.failed');
    }

    public function retry(Request $request, EInvoice $invoice, EInvoiceSubmitter $submitter): RedirectResponse
    {
        $user = $this->authorizeManage($request);

        return $submitter->retry($invoice, $user)
            ? $this->toast('success', 'einvoicing.retry.queued')
            : $this->toast('error', 'einvoicing.retry.not_allowed');
    }

    public function issue(Request $request, Order $order, InvoiceIssuer $issuer): RedirectResponse
    {
        $user = $this->authorizeManage($request);
        try {
            $issuer->invoiceForOrder($order, null, $user);
        } catch (InvoiceBlocked $e) {
            return $this->toast('error', 'einvoicing.blocked.'.$e->reason);
        }

        return $this->toast('success', 'einvoicing.issued');
    }

    public function note(IssueNoteRequest $request, EInvoice $invoice, InvoiceIssuer $issuer): RedirectResponse
    {
        abort_unless($invoice->kind === EInvoice::KIND_INVOICE, 404);
        try {
            $request->validated('kind') === EInvoice::KIND_CREDIT
                ? $issuer->creditNote($invoice, $request->amountMinor(), (string) $request->validated('reason'), null, $request->user())
                : $issuer->debitNote($invoice, $request->amountMinor(), (string) $request->validated('reason'), $request->user());
        } catch (InvoiceBlocked $e) {
            return $this->toast('error', 'einvoicing.blocked.'.$e->reason);
        }

        return $this->toast('success', 'einvoicing.note.issued');
    }

    public function pdf(Request $request, EInvoice $invoice, EInvoicePdf $pdf): HttpResponse
    {
        abort_unless(ZatcaAccess::views($request->user(), $invoice), 403);
        try {
            $bytes = $pdf->render($invoice);
        } catch (Throwable $e) {
            report($e);
            abort(500);
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$invoice->number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function xml(Request $request, EInvoice $invoice): HttpResponse
    {
        abort_unless(ZatcaAccess::views($request->user(), $invoice), 403);
        $request->validate(['variant' => ['nullable', Rule::in(['issued', 'cleared'])]]);
        $xml = $request->query('variant') === 'cleared' ? $invoice->cleared_xml : $invoice->xml;
        abort_if($xml === null, 404);

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$invoice->number.'.xml"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function authorizeManage(Request $request): User
    {
        $user = $request->user();
        abort_unless(ZatcaAccess::manages($user), 403);

        return $user;
    }

    private function toast(string $type, string $key): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => __($key)]);

        return back();
    }
}
