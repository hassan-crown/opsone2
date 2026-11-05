<?php

namespace App\EInvoicing;

use App\Agreements\Signing\DocumentSheet;
use App\Agreements\Signing\PdfRenderer;
use App\EInvoicing\Zatca\InvoiceHasher;
use App\Models\Attachment;
use App\Models\EInvoicing\EInvoice;
use App\Site\Theme\Color;
use App\Site\Theme\ThemeSettings;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Storage;

/**
 * The e-invoice PDF (ADR-0033), Arabic first with the English labels beside them, through the mPDF pipeline of
 * ADR-0029. Rendered on demand from the immutable invoice row (never stored): the seller and buyer as frozen, the
 * lines and VAT breakdown, the note's reference and reason, and the QR — ZATCA's QR from the cleared XML for a cleared
 * standard invoice, else the invoice's own (phase 2 once signed, phase 1 before onboarding).
 */
final class EInvoicePdf
{
    public function __construct(private readonly PdfRenderer $renderer) {}

    public function render(EInvoice $invoice): string
    {
        $invoice->loadMissing(['order', 'original']);
        $images = ['qr' => self::qrPng(self::qr($invoice))];
        $logo = self::logo();
        if ($logo !== null) {
            $images['logo'] = $logo;
        }
        $theme = ThemeSettings::published();
        $brand = (string) ($theme['palette']['light']['brand'] ?? $theme['brand'] ?? '');
        $discount = array_sum(array_map(fn (array $l) => (int) ($l['discount_minor'] ?? 0), $invoice->lines));

        $html = view('pdf.einvoice', ['v' => [
            'brand' => Color::valid($brand) ? $brand : DocumentSheet::DEFAULT_BRAND,
            'has_logo' => $logo !== null,
            'kind' => $invoice->kind,
            'type' => $invoice->type,
            'number' => $invoice->number,
            'uuid' => $invoice->uuid,
            'issued' => $invoice->issue_date->format('Y-m-d').' '.$invoice->issue_time,
            'order' => $invoice->order->number,
            'original' => $invoice->original?->number,
            'reason' => $invoice->reason,
            'seller' => $invoice->seller,
            'buyer' => $invoice->buyer,
            'lines' => array_map(fn (array $l) => [
                'description' => $l['description'],
                'quantity' => $l['quantity'],
                'discount' => DocumentSheet::money($l['discount_minor'] ?? 0),
                'net' => DocumentSheet::money($l['net_minor']),
                'vat' => DocumentSheet::money($l['vat_minor']),
                'gross' => DocumentSheet::money($l['gross_minor']),
            ], $invoice->lines),
            'rate' => rtrim(rtrim(number_format($invoice->vat_rate_bp / 100, 2, '.', ''), '0'), '.').'%',
            // Discounts (before VAT) are shown only; the XML carries each line's net after its discount.
            'has_discount' => $discount > 0,
            'subtotal' => DocumentSheet::money($invoice->net_minor + $discount),
            'discount' => DocumentSheet::money($discount),
            'net' => DocumentSheet::money($invoice->net_minor),
            'vat' => DocumentSheet::money($invoice->vat_minor),
            'total' => DocumentSheet::money($invoice->total_minor),
            'status' => $invoice->submission_status,
            'icv' => $invoice->icv,
        ]])->render();

        return $this->renderer->render($html, $images, $invoice->number);
    }

    /**
     * The QR the PDF carries: ZATCA's (cleared standard invoice) or the invoice's own.
     */
    public static function qr(EInvoice $invoice): string
    {
        if ($invoice->cleared_xml !== null) {
            try {
                $xpath = InvoiceHasher::xpath(InvoiceHasher::load($invoice->cleared_xml));
                $value = (string) InvoiceHasher::text($xpath, "//cac:AdditionalDocumentReference[cbc:ID='QR']//cbc:EmbeddedDocumentBinaryObject");
                if ($value !== '' && base64_decode($value, true) !== false) {
                    return $value;
                }
            } catch (\InvalidArgumentException) {
                // fall back to our own QR
            }
        }

        return $invoice->qr;
    }

    public static function qrPng(string $payload): string
    {
        return (new Writer(new GDLibRenderer(360, 2)))->writeString($payload);
    }

    public static function logo(): ?string
    {
        $uuid = ThemeSettings::published()['logo'] ?? null;
        $file = is_string($uuid) && $uuid !== '' ? Attachment::query()->where('uuid', $uuid)->first() : null;
        if ($file === null || ! in_array($file->extension, ['jpg', 'jpeg', 'png'], true) || $file->size_bytes > 6 * 1048576) {
            return null;
        }
        $disk = Storage::disk($file->disk);
        if (! $disk->exists($file->path)) {
            return null;
        }
        $bytes = (string) $disk->get($file->path);

        return @getimagesizefromstring($bytes) === false ? null : $bytes;
    }
}
