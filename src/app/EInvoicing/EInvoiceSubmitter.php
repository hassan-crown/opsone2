<?php

namespace App\EInvoicing;

use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\EInvoicing\Zatca\Api\ZatcaClients;
use App\EInvoicing\Zatca\Api\ZatcaCredentials;
use App\EInvoicing\Zatca\Api\ZatcaResponse;
use App\EInvoicing\Zatca\Api\ZatcaUnavailable;
use App\Models\EInvoicing\EInvoice;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Notifications\Outbox;
use Illuminate\Support\Facades\DB;

/**
 * Sends one signed e-invoice to ZATCA from the outbox (ADR-0032): clearance for standard invoices (the cleared XML
 * ZATCA returns is kept), reporting for simplified ones. Idempotent: only a `pending` invoice is sent, under its row
 * lock, so a replayed or duplicated outbox message changes nothing. 200/202 settle it (202 = with warnings); 303 on
 * clearance means clearance is switched off, so it is reported instead; 400 rejects it for good (corrected by a new
 * document); 401/403 fail it without retry (the CSID is the problem); 429/5xx/transport errors throw so the outbox
 * retries with backoff, and when retries run out `exhausted()` marks it failed for the owner to retry.
 */
final class EInvoiceSubmitter
{
    public function __construct(private readonly ZatcaClients $clients, private readonly Outbox $outbox) {}

    public function deliver(OutboxMessage $message): void
    {
        $id = (int) ($message->payload['e_invoice_id'] ?? 0);

        $invoice = DB::transaction(function () use ($id) {
            $invoice = EInvoice::query()->with(['order', 'device'])->lockForUpdate()->find($id);
            if ($invoice === null || $invoice->submission_status !== EInvoice::PENDING) {
                return null;
            }
            $invoice->forceFill(['submission_attempts' => $invoice->submission_attempts + 1])->save();
            Audit::record(AuditAction::EInvoiceSubmitted, $invoice, null, after: [
                'number' => $invoice->number, 'attempt' => $invoice->submission_attempts,
                'flow' => $invoice->needsClearance() ? 'clearance' : 'reporting',
            ], source: TransitionRecorder::SOURCE_SYSTEM, branchIds: [$invoice->order->branch_id], label: $invoice->number);

            return $invoice;
        });
        if ($invoice === null) {
            return;
        }

        $device = $invoice->device;
        if ($device === null || $device->production_token === null || $device->production_secret === null) {
            $this->settle($invoice, EInvoice::FAILED, null, ['error' => 'device_not_onboarded']);

            return;
        }
        $client = $this->clients->for($device->environment);
        $credentials = new ZatcaCredentials($device->production_token, $device->production_secret);

        try {
            $response = $invoice->needsClearance()
                ? $client->clear($credentials, $invoice->uuid, $invoice->hash, $invoice->xml)
                : $client->report($credentials, $invoice->uuid, $invoice->hash, $invoice->xml);
            if ($invoice->needsClearance() && $response->status === 303) {
                $response = $client->report($credentials, $invoice->uuid, $invoice->hash, $invoice->xml);
            }
        } catch (ZatcaUnavailable $e) {
            DB::transaction(fn () => Audit::record(AuditAction::EInvoiceResponse, $invoice, null, after: [
                'number' => $invoice->number, 'outcome' => 'unavailable', 'attempt' => $invoice->submission_attempts,
            ], source: TransitionRecorder::SOURCE_SYSTEM, branchIds: [$invoice->order->branch_id], label: $invoice->number));

            throw $e;
        }

        $this->settle($invoice, $this->outcome($invoice, $response), $response);
    }

    /**
     * Outbox retries exhausted on a transport/server error: the owner may retry from the settings page.
     */
    public function exhausted(OutboxMessage $message): void
    {
        $invoice = EInvoice::query()->find((int) ($message->payload['e_invoice_id'] ?? 0));
        if ($invoice !== null && $invoice->submission_status === EInvoice::PENDING) {
            $this->settle($invoice, EInvoice::FAILED, null, ['error' => 'retries_exhausted']);
        }
    }

    /**
     * The owner's retry of a failed submission: back to pending and a fresh outbox message.
     */
    public function retry(EInvoice $invoice, User $actor): bool
    {
        return DB::transaction(function () use ($invoice, $actor) {
            $invoice = EInvoice::query()->with('order')->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->submission_status !== EInvoice::FAILED || ! $invoice->signed) {
                return false;
            }
            $invoice->forceFill(['submission_status' => EInvoice::PENDING])->save();
            Audit::record(AuditAction::EInvoiceRetryRequested, $invoice, $actor, after: [
                'number' => $invoice->number, 'attempts' => $invoice->submission_attempts,
            ], branchIds: [$invoice->order->branch_id], label: $invoice->number);
            $this->outbox->enqueueEInvoice($invoice->id, 'zatca:'.$invoice->uuid.':retry:'.$invoice->submission_attempts);

            return true;
        });
    }

    private function outcome(EInvoice $invoice, ZatcaResponse $response): string
    {
        if ($response->ok()) {
            return $invoice->needsClearance() && $response->string('clearanceStatus') === 'CLEARED' ? EInvoice::CLEARED : EInvoice::REPORTED;
        }
        if ($response->status === 409) {
            // Already received by ZATCA (same UUID and hash): nothing more to send.
            return $invoice->needsClearance() ? EInvoice::CLEARED : EInvoice::REPORTED;
        }
        if ($response->status === 401 || $response->status === 403) {
            return EInvoice::FAILED;
        }

        return EInvoice::REJECTED;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function settle(EInvoice $invoice, string $status, ?ZatcaResponse $response, array $extra = []): void
    {
        DB::transaction(function () use ($invoice, $status, $response, $extra) {
            $invoice = EInvoice::query()->with('order')->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->submission_status !== EInvoice::PENDING) {
                return;
            }
            $summary = ($response?->summary() ?? []) + $extra;
            $cleared = $response !== null && $status === EInvoice::CLEARED ? base64_decode((string) $response->string('clearedInvoice'), true) : false;
            $invoice->forceFill([
                'submission_status' => $status,
                'submission_warnings' => $response !== null && $response->warnings(),
                'submitted_at' => now(),
                'zatca_response' => $summary,
                'cleared_xml' => is_string($cleared) && $cleared !== '' ? $cleared : null,
            ])->save();
            Audit::record(AuditAction::EInvoiceResponse, $invoice, null, after: [
                'number' => $invoice->number, 'outcome' => $status, 'warnings' => $invoice->submission_warnings,
                'http_status' => $response?->status, 'errors' => count($summary['errors'] ?? []),
            ], source: TransitionRecorder::SOURCE_SYSTEM, branchIds: [$invoice->order->branch_id], label: $invoice->number);
        });
    }
}
