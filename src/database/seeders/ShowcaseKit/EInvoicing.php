<?php

namespace Database\Seeders\ShowcaseKit;

use App\Access\TransitionRecorder;
use App\EInvoicing\InvoiceBlocked;
use App\EInvoicing\InvoiceIssuer;
use App\EInvoicing\MissingInvoices;
use App\EInvoicing\ZatcaOnboarding;
use App\Enums\CustomerKind;
use App\Models\Customer;
use App\Models\EInvoicing\EInvoice;
use App\Models\EInvoicing\ZatcaSettings;
use App\Models\OutboxMessage;
use App\Notifications\OutboxChannel;
use App\Notifications\OutboxDeliverer;
use App\Notifications\OutboxStatus;
use Carbon\CarbonImmutable;

/**
 * ZATCA e-invoicing for the showcase (ADR-0033), on the in-process `fake` gateway only (no ZATCA call, no real CSID):
 * the tax profile is on from the first historical day, so the real flows issue phase 1 invoices on first payments and
 * credit notes on executed refunds while the clock walks; on 1 August the owner onboards the EGS device and every later
 * document is signed and reported/cleared. Afterwards most VAT-registered customers get their national address (their
 * standard invoices are issued by the sweep, a few stay "missing" to show that list), and two manual credit notes and
 * one debit note document after-sale corrections.
 */
final class EInvoicing
{
    public function __construct(private readonly Showcase $s) {}

    public function enable(): void
    {
        ZatcaSettings::query()->create([
            'enabled' => true,
            'enabled_at' => $this->s->start,
            'environment' => 'fake',
            'seller_name_ar' => 'مؤسسة أوبس ون للمطابخ والأثاث',
            'seller_name_en' => 'OpsOne Kitchens & Furniture Est.',
            'vat_number' => '310122393500003',
            'commercial_registration' => '1010010000',
            'street' => 'طريق الملك فهد',
            'building_number' => '2322',
            'additional_number' => '7788',
            'district' => 'العليا',
            'city' => 'الرياض',
            'postal_code' => '12211',
            'branch_name' => 'الرياض',
            'industry' => 'مطابخ وأثاث حسب الطلب',
            'updated_by' => $this->s->users['owner']->id,
        ]);

        $this->s->timeline->at(CarbonImmutable::parse('2026-08-01 09:00', 'Asia/Riyadh'), new Story('zatca-onboarding'), 'onboard',
            fn () => app(ZatcaOnboarding::class)->start($this->s->users['owner'], '123456'));
    }

    public function settle(): void
    {
        $this->deliver();

        // VAT-registered customers: national addresses for most, so the sweep can issue their standard invoices.
        Customer::query()->where('kind', CustomerKind::Organization->value)->whereNotNull('vat_number')->orderBy('id')->get()
            ->each(function (Customer $customer, int $i) {
                if ($i % 5 !== 4 && $customer->national_address === null) {
                    $customer->forceFill(['national_address' => [
                        'street' => 'شارع التحلية', 'building_number' => (string) (3000 + $customer->id % 900), 'additional_number' => null,
                        'district' => 'السليمانية', 'city' => 'الرياض', 'postal_code' => '12245',
                    ]])->save();
                }
            });
        app(MissingInvoices::class)->sweep();

        // After-sale corrections on the latest invoices: two credit notes and one debit note.
        $issuer = app(InvoiceIssuer::class);
        $owner = $this->s->users['owner'];
        $invoices = EInvoice::query()->where('kind', EInvoice::KIND_INVOICE)->orderByDesc('id')->limit(3)->get();
        foreach ($invoices as $i => $invoice) {
            try {
                match ($i) {
                    0 => $issuer->creditNote($invoice, min(25_000, intdiv($invoice->total_minor, 10)), 'خصم تسوية بعد التسليم لتأخر التركيب', null, $owner, TransitionRecorder::SOURCE_SEEDER),
                    1 => $issuer->creditNote($invoice, min(11_500, intdiv($invoice->total_minor, 20)), 'إرجاع قطعة إكسسوار تالفة', null, $owner, TransitionRecorder::SOURCE_SEEDER),
                    default => $issuer->debitNote($invoice, 17_250, 'بند إضافي: تركيب إضاءة أسفل الخزائن', $owner, TransitionRecorder::SOURCE_SEEDER),
                };
            } catch (InvoiceBlocked $e) {
                $this->s->warn('e-invoice note skipped: '.$e->reason);
            }
        }
        $this->deliver();
    }

    /**
     * Submissions the queue worker would make, run here so the dataset is complete without a worker (fake gateway).
     */
    private function deliver(): void
    {
        $deliverer = app(OutboxDeliverer::class);
        OutboxMessage::query()->where('channel', OutboxChannel::Zatca->value)->where('status', OutboxStatus::Pending->value)
            ->orderBy('id')->pluck('id')->each(fn (int $id) => $deliverer->deliver($id));
    }
}
