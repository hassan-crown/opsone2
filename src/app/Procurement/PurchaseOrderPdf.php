<?php

namespace App\Procurement;

use App\Agreements\Signing\DocumentSheet;
use App\Agreements\Signing\PdfRenderer;
use App\EInvoicing\EInvoicePdf;
use App\Models\Attachment;
use App\Models\EInvoicing\ZatcaSettings;
use App\Models\Facility;
use App\Models\Inventory\StockLocation;
use App\Models\Order;
use App\Models\OrderAction;
use App\Models\OrderLine;
use App\Models\OrderMeasurement;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\PurchaseOrderLine;
use App\Models\Task;
use App\Organization\OrgPresenter;
use App\Site\Theme\Color;
use App\Site\Theme\ThemeSettings;
use App\Workflow\Catalogue\ActionType;
use Illuminate\Support\Facades\Storage;

/**
 * The purchase-order PDF (ADR-0038): Arabic first, through the mPDF pipeline of ADR-0029, laid out as the facility's
 * current (or previewed) PurchaseDocumentLayout says. Only data the system holds is printed: the facility and branch,
 * the supplier, the linked orders (the customer's name and phone only when the owner chose to show them to the
 * workshop), each line with its frozen options, measurements, notes and design images, the dates, the receiving
 * location, terms and signature boxes. Costs appear only when the layout shows prices AND the viewer holds P-82.
 */
final class PurchaseOrderPdf
{
    /** Design or measurement images per line, and the largest image read. */
    public const MAX_IMAGES = 4;

    public const MAX_IMAGE_BYTES = 3 * 1048576;

    public function __construct(private readonly PdfRenderer $renderer) {}

    /**
     * @param  array<string, mixed>  $layout  normalized PurchaseDocumentLayout
     */
    public function render(PurchaseOrder $po, array $layout, int $templateVersion, string $locale, bool $prices): string
    {
        [$data, $images] = $this->data($po, $layout, $templateVersion, $locale, $prices);

        return $this->renderer->render(view('pdf.purchase-order', ['v' => $data])->render(), $images, $po->number);
    }

    /**
     * Everything the PDF prints, and its fingerprint input (the same data gives the same stored PDF).
     *
     * @param  array<string, mixed>  $layout
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public function data(PurchaseOrder $po, array $layout, int $templateVersion, string $locale, bool $prices): array
    {
        $po->loadMissing(['supplier', 'branch', 'lines']);
        $flags = $layout['flags'];
        $facility = Facility::current();
        $zatca = ZatcaSettings::query()->first();
        $theme = ThemeSettings::published();
        $brand = (string) ($theme['palette']['light']['brand'] ?? $theme['brand'] ?? '');
        $images = [];
        $logo = EInvoicePdf::logo();
        if ($logo !== null) {
            $images['logo'] = $logo;
        }
        $pick = fn (?string $ar, ?string $en) => $locale === 'en' && $en !== null && $en !== '' ? $en : ($ar ?? '');

        $orderIds = $po->lines->pluck('order_id')->filter()->unique()->map(fn ($id) => (int) $id)->values()->all();
        $orders = Order::query()->with('customer')->whereIn('id', $orderIds)->orderBy('id')->get();
        $orderLines = OrderLine::query()->whereIn('id', $po->lines->where('item_type', PurchaseOrderLine::ORDER_LINE)->pluck('item_id')->all())->get()->keyBy('id');
        $measurements = OrderMeasurement::query()->with('attachments')->whereIn('order_id', $orderIds)->orderBy('id')->get();
        $designFiles = $this->designImages($orderIds);
        $location = $po->location_id === null ? null : StockLocation::query()->find($po->location_id);

        $lines = [];
        $orderLevelShown = [];
        foreach ($po->lines as $line) {
            $orderLine = $line->item_type === PurchaseOrderLine::ORDER_LINE ? $orderLines->get($line->item_id) : null;
            $details = ['options' => [], 'dimensions' => [], 'notes' => null, 'images' => []];

            if ($orderLine !== null) {
                if ($flags['line_options']) {
                    foreach ((array) ($orderLine->snapshot['options'] ?? []) as $option) {
                        $label = $option['label'] ?? $option['key'] ?? '';
                        $label = is_array($label) ? $pick($label['ar'] ?? null, $label['en'] ?? null) : (string) $label;
                        $details['options'][] = ['label' => $label, 'value' => (string) ($option['value'] ?? '')];
                    }
                }
                if ($flags['line_notes'] && $orderLine->description !== null) {
                    $details['notes'] = $orderLine->description;
                }
                // The line's own measurements; order-level ones are printed once, under the order's first line.
                $own = $measurements->where('order_line_id', $orderLine->id);
                if ($own->isEmpty() && ! isset($orderLevelShown[$orderLine->order_id])) {
                    $own = $measurements->where('order_id', $orderLine->order_id)->whereNull('order_line_id');
                    $orderLevelShown[$orderLine->order_id] = true;
                }
                $final = $own->where('kind', 'final');
                $own = $final->isNotEmpty() ? $final : $own;
                if ($flags['line_dimensions']) {
                    $details['dimensions'] = $own->map(fn (OrderMeasurement $m) => [
                        'location' => $m->location, 'name' => $m->name, 'value' => $m->value.' '.$m->unit, 'kind' => $m->kind,
                    ])->values()->all();
                }
                if ($flags['line_images']) {
                    $files = $own->flatMap(fn (OrderMeasurement $m) => $m->attachments)->concat($designFiles[$orderLine->order_id] ?? []);
                    foreach ($files->take(self::MAX_IMAGES) as $file) {
                        $bytes = self::image($file);
                        if ($bytes !== null) {
                            $name = 'img'.count($images);
                            $images[$name] = $bytes;
                            $details['images'][] = $name;
                        }
                    }
                }
            }

            $lines[] = [
                'no' => $line->line_no,
                'name' => $pick($line->item_name->only('ar'), $line->item_name->only('en')),
                'quantity' => Quantity::toInput($line->quantity_milli),
                'unit' => __('stock.unit.'.$line->unit, [], $locale),
                'order' => $line->order_id === null ? null : $orders->firstWhere('id', $line->order_id)?->number,
                'details' => $details,
                'unit_cost' => $prices ? DocumentSheet::money($line->unit_cost_minor) : null,
                'gross' => $prices ? DocumentSheet::money($line->gross_minor) : null,
            ];
        }

        $data = [
            'locale' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'brand' => Color::valid($brand) ? $brand : DocumentSheet::DEFAULT_BRAND,
            'has_logo' => $logo !== null,
            'template_version' => $templateVersion,
            'title' => $pick($layout['title']['ar'] ?? null, $layout['title']['en'] ?? null),
            'blocks' => array_values(array_map(fn (array $b) => $b['key'], array_filter($layout['blocks'], fn (array $b) => $b['visible']))),
            'flags' => $flags,
            'number' => $po->number,
            'status' => $po->status->value,
            'issued' => ($po->ordered_at ?? $po->created_at)->toDateString(),
            'expected_on' => $po->expected_on?->toDateString(),
            'facility' => [
                'name' => $pick($facility?->name_ar, $facility?->name_en),
                'phone' => $facility?->contact_phone,
                'email' => $facility?->contact_email,
                'address' => $facility?->address,
                'vat_number' => $zatca?->vat_number,
                'cr' => $zatca?->commercial_registration,
            ],
            'branch' => OrgPresenter::name($po->branch),
            'supplier' => [
                'name' => $pick($po->supplier->name->only('ar'), $po->supplier->name->only('en')),
                'contact' => $po->supplier->contact_name,
                'phone' => $po->supplier->phone,
                'email' => $po->supplier->email,
                'vat_number' => $po->supplier->vat_number,
            ],
            'orders' => $orders->map(fn (Order $o) => [
                'number' => $o->number,
                'customer' => $flags['customer_name'] ? $o->customer->name : null,
                'phone' => $flags['customer_phone'] ? $o->customer->mobile : null,
            ])->values()->all(),
            'delivery' => [
                'location' => $location === null ? null : $location->code.' — '.($location->name->get($locale) ?? ''),
                'address' => $facility?->address,
            ],
            'lines' => $lines,
            'prices' => $prices,
            'total' => $prices ? DocumentSheet::money($po->gross_minor) : null,
            'notes' => $po->notes,
            'terms' => $pick($layout['terms']['ar'] ?? null, $layout['terms']['en'] ?? null),
        ];

        return [$data, $images];
    }

    /**
     * Image files of the orders' design work (the `prepare_design` task attachments).
     *
     * @param  array<int, int>  $orderIds
     * @return array<int, list<Attachment>>
     */
    private function designImages(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $actions = OrderAction::query()->whereIn('order_id', $orderIds)->where('type', ActionType::PrepareDesign->value)->pluck('id');
        $tasks = Task::query()->whereIn('order_action_id', $actions->all())->get(['id', 'order_id'])->keyBy('id');
        $files = Attachment::query()->where('attachable_type', (new Task)->getMorphClass())
            ->whereIn('attachable_id', $tasks->keys()->all())->whereIn('extension', ['jpg', 'jpeg', 'png'])->orderBy('id')->get();

        $out = [];
        foreach ($files as $file) {
            $out[(int) $tasks[$file->attachable_id]->order_id][] = $file;
        }

        return $out;
    }

    private static function image(Attachment $file): ?string
    {
        if (! in_array($file->extension, ['jpg', 'jpeg', 'png'], true) || $file->size_bytes > self::MAX_IMAGE_BYTES) {
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
