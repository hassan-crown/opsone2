<?php

namespace App\EInvoicing\Zatca\Api;

/**
 * The Fatoora API (ADR-0032): onboarding (compliance CSID with the portal OTP, compliance checks, production CSID)
 * and submission (reporting for simplified, clearance for standard). Every call returns ZATCA's status and body;
 * transport errors, 429 and 5xx throw ZatcaUnavailable so the outbox retries with backoff.
 */
interface ZatcaClient
{
    public function complianceCsid(string $csrBase64, string $otp): ZatcaResponse;

    public function complianceCheck(ZatcaCredentials $credentials, string $uuid, string $hash, string $xml): ZatcaResponse;

    public function productionCsid(ZatcaCredentials $credentials, string $complianceRequestId): ZatcaResponse;

    public function report(ZatcaCredentials $credentials, string $uuid, string $hash, string $xml): ZatcaResponse;

    public function clear(ZatcaCredentials $credentials, string $uuid, string $hash, string $xml): ZatcaResponse;
}
