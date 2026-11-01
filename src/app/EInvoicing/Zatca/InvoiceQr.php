<?php

namespace App\EInvoicing\Zatca;

use App\Models\EInvoicing\EInvoice;

/**
 * The QR content of an e-invoice (ADR-0032). Phase 1: seller name, VAT number, timestamp, total with VAT and VAT
 * (tags 1–5). Phase 2 adds the invoice hash (6), the ECDSA signature (7), the public key (8) and, on simplified
 * invoices, the CSID certificate's signature (9). Hash and signature travel as their Base64 text.
 */
final class InvoiceQr
{
    /**
     * @return array{seller_name: string, vat_number: string, timestamp: string, total: string, vat: string}
     */
    public static function fields(EInvoice $invoice): array
    {
        return [
            'seller_name' => (string) ($invoice->seller['name'] ?? ''),
            'vat_number' => (string) ($invoice->seller['vat_number'] ?? ''),
            'timestamp' => $invoice->issue_date->format('Y-m-d').'T'.$invoice->issue_time,
            'total' => UblInvoice::amount($invoice->total_minor),
            'vat' => UblInvoice::amount($invoice->vat_minor),
        ];
    }

    /**
     * @param  array{seller_name: string, vat_number: string, timestamp: string, total: string, vat: string}  $fields
     */
    public static function phaseOne(array $fields): string
    {
        return Tlv::encode(self::base($fields));
    }

    /**
     * @param  array{seller_name: string, vat_number: string, timestamp: string, total: string, vat: string}  $fields
     */
    public static function phaseTwo(array $fields, string $hash, string $signature, Certificate $certificate, bool $simplified): string
    {
        $tags = self::base($fields) + [6 => $hash, 7 => $signature, 8 => $certificate->publicKeyDer()];
        if ($simplified) {
            $tags[9] = $certificate->signature();
        }

        return Tlv::encode($tags);
    }

    /**
     * @param  array{seller_name: string, vat_number: string, timestamp: string, total: string, vat: string}  $fields
     * @return array<int, string>
     */
    private static function base(array $fields): array
    {
        return [1 => $fields['seller_name'], 2 => $fields['vat_number'], 3 => $fields['timestamp'], 4 => $fields['total'], 5 => $fields['vat']];
    }
}
