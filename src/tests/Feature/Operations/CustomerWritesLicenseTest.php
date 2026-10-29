<?php

/*
 * Customer and public writes follow the license phase (SUB-12, ADR-0028): in the read-only phase staff can only read
 * and export, so a customer's approval, proof, appointment answer, cancellation request or ticket, and a public lead,
 * are refused before any effect; the customer still reads their workspace. Wind-down still accepts them.
 */

use App\Licensing\CurrentLicense;
use App\Licensing\LicenseStatus;
use App\Models\Finance\PaymentProof;
use App\Models\Interest;
use App\Models\Tickets\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt as CryptFacade;
use Illuminate\Support\Str;
use Tests\Support\LicenseFactory;

require_once __DIR__.'/../Portal/PortalTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->f = portalFixture();
    config(['site.lead.min_seconds' => 3]);
});

function customerWritesPhase(string $status): void
{
    $now = CarbonImmutable::now('UTC');
    $days = ['active' => -30, 'wind_down' => 10, 'expired' => 60][$status];
    LicenseFactory::place([
        'starts_at' => $now->subDays($days + 365)->toIso8601ZuluString(),
        'ends_at' => $now->subDays($days)->toIso8601ZuluString(),
        'issued_at' => $now->toIso8601ZuluString(),
    ]);
    expect(app(CurrentLicense::class)->refresh()->status)->toBe(LicenseStatus::from($status));
}

function customerWritesLead(): array
{
    $submission = (string) Str::uuid();

    return [
        'name' => 'سلمان', 'mobile' => '0551234567', 'email' => null, 'need' => 'مطبخ',
        'submission' => $submission, 'form_token' => CryptFacade::encryptString((time() - 30).'|'.$submission), 'website' => '',
    ];
}

it('refuses customer and public writes in the read-only phase without effect, and keeps reading open', function () {
    customerWritesPhase('expired');
    $rec = $this->f->recA;
    actingAsCustomer($this->f->a);
    $before = [PaymentProof::query()->count(), Ticket::query()->count(), Interest::query()->count(), $rec->design->refresh()->status];

    $this->get('/portal')->assertOk();
    $this->get("/portal/orders/{$rec->order->id}")->assertOk();
    $this->post("/portal/documents/{$rec->design->id}/approve")->assertForbidden();
    $this->post("/portal/orders/{$rec->order->id}/proofs", portalProofForm())->assertForbidden();
    $this->post("/portal/appointments/{$rec->appointment->id}/confirm")->assertForbidden();
    $this->post("/portal/orders/{$rec->order->id}/cancellation", ['reason' => 'x'])->assertForbidden();
    $this->post('/portal/tickets', ['type' => 'issue', 'subject' => 'x', 'body' => 'y'])->assertForbidden();
    $this->post('/ar/contact', customerWritesLead())->assertForbidden();

    expect([PaymentProof::query()->count(), Ticket::query()->count(), Interest::query()->count(), $rec->design->refresh()->status])->toEqual($before);
});

it('still accepts customer writes while the facility winds down existing work', function () {
    customerWritesPhase('wind_down');
    actingAsCustomer($this->f->a);

    $this->post('/portal/tickets', ['type' => 'issue', 'subject' => 'x', 'body' => 'y'])->assertRedirect();
    $this->post('/ar/contact', customerWritesLead())->assertRedirect();
    expect(Ticket::query()->count())->toBe(1);
});
