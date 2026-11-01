<?php

namespace Database\Seeders\ShowcaseKit;

use App\Actions\Customers\CreateCustomer;
use App\Models\Customer;
use Carbon\CarbonImmutable;

/**
 * Four customer-workspace accounts with live work to show: a design waiting for approval, a quote waiting for the
 * customer's e-signature (signing link sent), an agreement with its first payment due (quote and contract e-signed, a
 * transfer receipt under review) and a kitchen in production whose installation visit waits for the customer's
 * confirmation (quote e-signed, contract awaiting signature). Mobiles are fixed so the demo can sign in (OTP via the
 * outbox / log mailer).
 */
final class Portal
{
    /** @var list<array{mobile: string, name: string, email: string, branch: string, start: string, opts: array<string, mixed>}> */
    public const ACCOUNTS = [
        ['mobile' => '+966501110001', 'name' => 'نورة بنت سعد الفهد', 'email' => 'portal1@mail-demo.test', 'branch' => 'RUH', 'start' => '2026-10-13 10:00', 'opts' => ['stopAfter' => 'design-upload', 'unconfirmed' => ['measurement']]],
        ['mobile' => '+966501110002', 'name' => 'عبدالرحمن بن ناصر الخالدي', 'email' => 'portal2@mail-demo.test', 'branch' => 'JED', 'start' => '2026-10-04 10:00', 'opts' => ['stopAfter' => 'quote-send', 'viaPortal' => true, 'discount' => '0', 'esign' => 'pending']],
        ['mobile' => '+966501110003', 'name' => 'شركة المسار للمقاولات', 'email' => 'portal3@mail-demo.test', 'branch' => 'RUH', 'start' => '2026-09-27 10:00', 'opts' => ['stopAfter' => 'first-payment', 'proofByCustomer' => true, 'viaPortal' => true, 'discount' => '0', 'esign' => 'signed', 'contract' => 'signed', 'signer' => 'م. خالد بن فهد المسار']],
        ['mobile' => '+966501110004', 'name' => 'ريم بنت محمد السبيعي', 'email' => 'portal4@mail-demo.test', 'branch' => 'DMM', 'start' => '2026-09-06 10:00', 'opts' => ['stopAfter' => 'stage-manufacture', 'unconfirmed' => ['installation'], 'viaPortal' => true, 'discount' => '0', 'esign' => 'signed', 'contract' => 'pending']],
    ];

    public function __construct(private readonly Showcase $s) {}

    public function run(): void
    {
        foreach (self::ACCOUNTS as $i => $a) {
            $story = new Story('portal-'.$i);
            $this->s->timeline->at(CarbonImmutable::parse($a['start'], 'Asia/Riyadh'), $story, 'account', function () use ($a) {
                $sales = $this->s->users[['RUH' => 'sales_ruh1', 'JED' => 'sales_jed', 'DMM' => 'sales_dmm'][$a['branch']]];
                $isCompany = str_starts_with($a['name'], 'شركة');
                $customer = app(CreateCustomer::class)->handle($sales, [
                    'kind' => $isCompany ? 'organization' : 'person', 'name' => $a['name'], 'mobile' => $a['mobile'], 'email' => $a['email'], 'country' => 'SA',
                    'branch_id' => $this->s->branches[$a['branch']]->id, 'assignee_id' => $sales->id, 'portal_login_enabled' => true, 'preferred_channel' => 'whatsapp',
                    ...($isCompany ? ['commercial_registration' => '1010445566', 'vat_number' => '300112233400003'] : []),
                ]);
                Customer::query()->whereKey($customer->id)->update(['portal_login_enabled' => true]);
                $this->s->customOrders->openDirect(CarbonImmutable::now()->addHour(), $a['branch'], $customer->id, $a['opts']);
            });
        }
    }
}
