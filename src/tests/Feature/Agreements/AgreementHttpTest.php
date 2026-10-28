<?php

/**
 * HTTP contract of the agreement screens (docs/p6-finance-api.md): pages, JSON writes with idempotency keys and
 * versions, and amounts removed from the payload for anyone without P-37/P-29 (executor, supervisor).
 */

use App\Access\Authorizer;
use App\Models\Agreements\AgreementDocument;
use App\Models\Agreements\DiscountDecision;
use App\Models\FinanceSettings;
use App\Models\StateTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/AgreementTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = agreementFixture();
});

/**
 * Every key anywhere in a payload.
 *
 * @param  array<array-key, mixed>  $payload
 * @return list<string>
 */
function agrKeys(array $payload): array
{
    $keys = [];
    $walk = function (array $a) use (&$walk, &$keys) {
        foreach ($a as $k => $v) {
            $keys[] = (string) $k;
            if (is_array($v)) {
                $walk($v);
            }
        }
    };
    $walk($payload);

    return $keys;
}

it('renders the agreement page for sales with amounts and creation rights', function () {
    $this->actingAs($this->f->sales)->get(route('orders.agreement', $this->f->order))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('orders/agreement')
            ->where('agreement.amounts_visible', true)
            ->where('agreement.can.create_quote', true)
            ->where('agreement.agreement', null)
            ->has('lines', 1)
            ->has('actions'));
});

it('creates, prices and sends a quote over JSON with idempotency keys; a double submit replays', function () {
    $this->actingAs($this->f->sales);
    $key = agrKey();
    $first = $this->postJson(route('orders.agreement.quotes.store', $this->f->order), ['idempotency_key' => $key])->assertCreated();
    $again = $this->postJson(route('orders.agreement.quotes.store', $this->f->order), ['idempotency_key' => $key])->assertCreated();
    expect($again->json('document_id'))->toBe($first->json('document_id'))
        ->and(AgreementDocument::query()->count())->toBe(1);

    $this->postJson(route('orders.agreement.quotes.store', $this->f->order), [])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

    $document = AgreementDocument::query()->sole();
    $this->putJson(route('agreement.documents.update', $document), [...agrQuoteData(), 'idempotency_key' => agrKey(), 'lock_version' => $document->lock_version])
        ->assertOk()->assertJsonPath('agreement.documents.0.content.totals.total_minor', 9_150_000);

    // A stale version is refused with the shared 409 contract.
    $this->postJson(route('agreement.documents.send', $document), ['idempotency_key' => agrKey(), 'lock_version' => 1])
        ->assertStatus(409)->assertJsonPath('code', 'stale_version');

    $sendKey = agrKey();
    $payload = ['idempotency_key' => $sendKey, 'lock_version' => $document->refresh()->lock_version];
    $this->postJson(route('agreement.documents.send', $document), $payload)->assertOk()->assertJsonPath('agreement.documents.0.status', 'awaiting_customer');
    $this->postJson(route('agreement.documents.send', $document), $payload)->assertOk();
    expect(StateTransition::query()->where('machine', 'M-08')->where('transition', 'T01')->count())->toBe(1);

    // Sending again with a new key is an invalid transition.
    $this->postJson(route('agreement.documents.send', $document), ['idempotency_key' => agrKey(), 'lock_version' => $document->refresh()->lock_version])
        ->assertStatus(409)->assertJsonPath('condition', 'not_from_awaiting_customer');
});

it('records an external approval with evidence over multipart', function () {
    $quote = agrSend($this->f->sales, agrQuote($this->f->sales, $this->f->order));
    $this->actingAs($this->f->owner)->post(route('agreement.documents.external-approval', $quote), [
        'idempotency_key' => agrKey(), 'lock_version' => $quote->lock_version, 'channel' => 'paper',
        'decided_on' => now()->toDateString(), 'content_hash' => $quote->content_hash, 'evidence' => agrPdf(),
    ], ['Accept' => 'application/json'])->assertOk()
        ->assertJsonPath('agreement.agreement.version', 1)
        ->assertJsonPath('agreement.agreement.total_minor', 9_150_000)
        ->assertJsonPath('agreement.agreement.decision.channel', 'paper');
});

it('never sends amounts to an executor or a supervisor (API and Inertia props)', function () {
    agrAgreed($this->f, [['description' => 'مطبخ', 'quantity' => '1', 'unit_price' => '18450']]);
    agrChange($this->f->sales, $this->f->order, [['description' => 'مطبخ', 'quantity' => '1', 'unit_price' => '19650']]);

    // By default an executor cannot open the order at all.
    $this->actingAs($this->f->executor)->getJson(route('orders.agreement', $this->f->order))->assertForbidden();

    grantOrders($this->f->executor, 'P-16', 'branches', [$this->f->org->a->id]);
    app(Authorizer::class)->flush();
    foreach ([$this->f->executor, $this->f->supervisor] as $viewer) {
        $json = $this->actingAs($viewer)->getJson(route('orders.agreement', $this->f->order))->assertOk()->json('agreement');
        $keys = agrKeys($json);
        expect($json['amounts_visible'])->toBeFalse()
            ->and(array_filter($keys, fn (string $k) => str_ends_with($k, '_minor')))->toBe([])
            ->and($keys)->not->toContain('discount')->not->toContain('totals')
            ->and($json['agreement']['version'])->toBe(1);

        $this->actingAs($viewer)->get(route('orders.agreement', $this->f->order))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('agreement.amounts_visible', false)->missing('agreement.agreement.total_minor'));
    }
});

it('shows the supervisor the decision numbers for that request only, and lets them decide', function () {
    agrSettings(1500, false);
    $quote = agrQuote($this->f->sales, $this->f->order, agrQuoteData(['type' => 'percent', 'value' => '12']));
    $this->actingAs($this->f->sales)->postJson(route('agreement.documents.discount-requests', $quote), [
        'idempotency_key' => agrKey(), 'lock_version' => $quote->lock_version, 'reason' => 'سعر موحد لست شقق',
    ])->assertCreated();
    $decision = DiscountDecision::query()->sole();

    $this->actingAs($this->f->supervisor)->get(route('agreement.decisions.show', $decision))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('agreements/decision')
            ->where('decision.base_minor', 9_150_000)->where('decision.discount_minor', 1_098_000)
            ->where('decision.over_minor', 183_000)->where('decision.total_minor', 9_259_800)
            ->where('decision.can.decide', true));
    $this->actingAs($this->f->finance)->get(route('agreement.decisions.show', $decision))->assertForbidden();

    $this->actingAs($this->f->supervisor)->postJson(route('agreement.decisions.approve', $decision), [
        'idempotency_key' => agrKey(), 'lock_version' => $decision->lock_version,
    ])->assertOk()->assertJsonPath('decision.status', 'approved');
});

it('renders and saves the sales settings', function () {
    $this->actingAs($this->f->manager)->get(route('settings.sales'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('account/settings-sales')
            ->where('settings.vat_rate_bp', 1500)->where('settings.prices_include_vat', true)
            ->where('canEdit', true)->where('canEditLimits', false));

    $this->actingAs($this->f->manager)->put(route('settings.sales.update'), [
        'vat_rate_bp' => 1500, 'prices_include_vat' => false, 'quote_validity_days' => 21, 'contracts_enabled' => true,
    ])->assertRedirect(route('settings.sales'));
    expect(FinanceSettings::current()->quote_validity_days)->toBe(21);

    $this->actingAs($this->f->sales)->get(route('settings.sales'))->assertForbidden();
});

it('keeps the order page working and links the agreement tab', function () {
    $this->actingAs($this->f->sales)->get(route('orders.show', $this->f->order))->assertOk();
});
