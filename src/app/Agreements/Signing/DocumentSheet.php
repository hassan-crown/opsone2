<?php

namespace App\Agreements\Signing;

use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Finance\OrderSchedule;
use App\Models\Agreements\AgreementDocument;
use App\Models\Attachment;
use App\Models\Facility;
use App\Models\Finance\OrderDue;
use App\Models\FinanceSettings;
use App\Money\Money;
use App\Site\Theme\Color;
use App\Site\Theme\ThemeSettings;
use Illuminate\Support\Facades\Storage;

/**
 * Everything a quote/contract PDF shows (ADR-0029), gathered from the sources and nothing else: the sent content
 * (lines, discount, VAT-inclusive breakdown, terms), the facility's identity and live theme (logo, brand colour), the
 * customer and order, the payment schedule (the order's dues once the agreement exists, else the facility template
 * applied to the total) and the order's design images. The fingerprint is the sha256 of all of it plus the layout
 * version: the PDF is generated again only when it changes.
 */
final class DocumentSheet
{
    /** Bump when the PDF layout changes, so unsigned documents get a fresh PDF (signed ones never change). */
    public const LAYOUT_VERSION = 2;

    public const DEFAULT_BRAND = '#0E7C72';

    public const MAX_IMAGES = 4;

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png'];

    private const MAX_IMAGE_BYTES = 6 * 1048576;

    /**
     * @param  array<string, mixed>  $view
     * @param  array<string, string>  $images  name => bytes (logo, design-n)
     */
    private function __construct(public readonly array $view, public readonly array $images, public readonly string $fingerprint) {}

    /**
     * Can this document be rendered (and so signed)? Quotes always; contracts built from the agreement (an uploaded
     * contract file is its own document and keeps the plain approval).
     */
    public static function supports(AgreementDocument $document): bool
    {
        return match ($document->kind) {
            DocumentKind::Quote => true,
            DocumentKind::Contract => ($document->content['source'] ?? null) === 'agreement',
            DocumentKind::Design => false,
        };
    }

    public static function build(AgreementDocument $document, string $locale): self
    {
        $document->loadMissing('order.customer');
        $order = $document->order;
        $customer = $order->customer;
        $facility = Facility::current();
        $content = $document->content;
        $theme = ThemeSettings::published();
        $brand = (string) ($theme['palette']['light']['brand'] ?? $theme['brand'] ?? '');
        $images = [];
        $logo = self::themeLogo($theme['logo'] ?? null);
        if ($logo !== null) {
            $images['logo'] = $logo;
        }

        $designs = [];
        foreach (self::designImages($document) as $i => $bytes) {
            $images["design{$i}"] = $bytes;
            $designs[] = "design{$i}";
        }

        $vat = $content['vat'] ?? [];
        $totals = $content['totals'] ?? [];
        $parties = $content['parties'] ?? [];
        $view = [
            'locale' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'kind' => $document->kind->value,
            'number' => $document->number,
            'status' => $document->status->value,
            'draft' => $document->status === DocumentStatus::Draft,
            'sent_on' => $document->sent_at?->toDateString(),
            'valid_until' => $document->valid_until?->toDateString(),
            'content_hash' => $document->content_hash,
            'brand' => Color::valid($brand) ? $brand : self::DEFAULT_BRAND,
            'has_logo' => $logo !== null,
            'facility' => [
                'name' => $locale === 'en' && (string) $facility?->name_en !== '' ? $facility?->name_en : $facility?->name_ar,
                'address' => $facility?->address,
                'phone' => $facility?->contact_phone,
                'email' => $facility?->contact_email,
                'commercial_registration' => $parties['facility']['commercial_registration'] ?? null,
                'vat_number' => $parties['facility']['vat_number'] ?? null,
            ],
            'customer' => [
                'name' => $customer->name,
                'mobile' => $customer->mobile,
                'email' => $customer->email,
                'commercial_registration' => $customer->commercial_registration,
                'vat_number' => $customer->vat_number,
            ],
            'order' => ['number' => $order->number, 'opened_on' => $order->created_at->toDateString()],
            'description' => $content['description'] ?? null,
            'lines' => array_values(array_map(fn (array $l) => [
                'description' => (string) ($l['description'] ?? ''),
                'quantity' => (string) ($l['quantity'] ?? ''),
                'unit' => $l['unit'] ?? null,
                'unit_price' => self::money($l['unit_price_minor'] ?? null),
                'discount' => ($l['discount_minor'] ?? 0) > 0 ? self::money($l['discount_minor']) : null,
                'vat' => self::money($l['vat_minor'] ?? null),
                'total' => self::money($l['gross_minor'] ?? $l['amount_minor'] ?? null),
            ], $content['lines'] ?? [])),
            'vat_rate' => self::percent((int) ($vat['rate_bp'] ?? FinanceSettings::current()->vat_rate_bp)),
            'vat_inclusive' => (bool) ($vat['inclusive'] ?? true),
            'totals' => [
                'base' => self::money($totals['base_minor'] ?? null),
                'discount' => ($totals['discount_minor'] ?? 0) > 0 ? self::money($totals['discount_minor']) : null,
                'net' => self::money($totals['net_minor'] ?? null),
                'vat' => self::money($totals['vat_minor'] ?? null),
                'total' => self::money($totals['total_minor'] ?? null),
            ],
            'schedule' => self::schedule($document, $locale, $totals['total_minor'] ?? null),
            'execution_days' => $content['execution_days'] ?? null,
            'terms' => array_values(array_filter([$content['agreement_terms'] ?? null, $content['terms'] ?? null], fn ($t) => is_string($t) && trim($t) !== '')),
            'clauses' => array_values(array_filter($content['clauses'] ?? [], 'is_string')),
            'agreement_version' => $content['agreement_version'] ?? null,
            'designs' => $designs,
        ];

        $imageHashes = array_map(fn (string $bytes) => hash('sha256', $bytes), $images);
        ksort($imageHashes);
        $fingerprint = hash('sha256', (string) json_encode([self::LAYOUT_VERSION, $view, $imageHashes], JSON_UNESCAPED_UNICODE));

        return new self($view, $images, $fingerprint);
    }

    /**
     * Minor units as text with grouping, e.g. 1234567 → «12,345.67». Null stays null (an unknown amount is never 0).
     */
    public static function money(?int $minor): ?string
    {
        if ($minor === null) {
            return null;
        }
        $abs = abs($minor);

        return ($minor < 0 ? '-' : '').number_format(intdiv($abs, 100)).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function percent(int $bp): string
    {
        return rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.').'%';
    }

    /**
     * The order's dues when the agreement fixed them, else the facility's default template applied to the total.
     *
     * @return list<array{name: string, when: string, amount: string|null}>
     */
    private static function schedule(AgreementDocument $document, string $locale, ?int $totalMinor): array
    {
        $dues = OrderDue::query()->where('order_id', $document->order_id)->whereNull('removed_at')->orderBy('position')->get();
        if ($dues->isNotEmpty()) {
            return array_values($dues->map(fn (OrderDue $d) => [
                'name' => (string) $d->name->get($locale),
                'when' => $d->due_date !== null ? $d->due_date->toDateString() : __('esign.pdf.due.'.$d->due_event->value, [], $locale),
                'amount' => self::money($d->amount_minor),
            ])->all());
        }

        $template = OrderSchedule::TEMPLATES[OrderSchedule::DEFAULT_TEMPLATE];
        $rows = [];
        $left = $totalMinor;
        foreach ($template as $row) {
            $amount = null;
            if ($totalMinor !== null) {
                $amount = $row['basis'] === 'percent' ? Money::of($totalMinor)->percent((int) $row['value'])->minor : $left;
                $left = $left === null || $amount === null ? null : $left - $amount;
            }
            $rows[] = [
                'name' => $row['name'][$locale] ?? $row['name']['ar'],
                'when' => __('esign.pdf.due.'.$row['event'], [], $locale),
                'amount' => self::money($amount),
            ];
        }

        return $rows;
    }

    private static function themeLogo(mixed $uuid): ?string
    {
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }
        $file = Attachment::query()->where('uuid', $uuid)->first();

        return $file === null ? null : self::imageBytes($file);
    }

    /**
     * Images of the order's design: the approved design document, else the one awaiting the customer.
     *
     * @return list<string>
     */
    private static function designImages(AgreementDocument $document): array
    {
        $design = AgreementDocument::query()->where('order_id', $document->order_id)->where('kind', DocumentKind::Design->value)
            ->whereIn('status', [DocumentStatus::Approved->value, DocumentStatus::AwaitingCustomer->value])
            ->orderByRaw('case when status = ? then 0 else 1 end', [DocumentStatus::Approved->value])->orderByDesc('id')->first();
        if ($design === null) {
            return [];
        }
        $out = [];
        foreach ($design->attachments()->whereIn('extension', self::IMAGE_EXTENSIONS)->orderBy('id')->get() as $file) {
            $bytes = self::imageBytes($file);
            if ($bytes !== null) {
                $out[] = $bytes;
            }
            if (count($out) >= self::MAX_IMAGES) {
                break;
            }
        }

        return $out;
    }

    private static function imageBytes(Attachment $file): ?string
    {
        if (! in_array($file->extension, self::IMAGE_EXTENSIONS, true) || $file->size_bytes > self::MAX_IMAGE_BYTES) {
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
