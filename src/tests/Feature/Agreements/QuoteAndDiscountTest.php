<?php

use App\Access\Exceptions\InvalidTransition;
use App\Actions\Settings\UpdateDiscountLimits;
use App\Actions\Settings\UpdateFinanceSettings;
use App\Actions\StateMachines\M08Document\T00CreateQuote;
use App\Actions\StateMachines\M08Document\UpdateDocumentDraft;
use App\Actions\StateMachines\M26Decision\T00RequestDiscount;
use App\Actions\StateMachines\M26Decision\T01ApproveDiscount;
use App\Actions\StateMachines\M26Decision\T02RejectDiscount;
use App\Agreements\DiscountCheck;
use App\Agreements\DiscountLimits;
use App\Agreements\QuotePricing;
use App\Enums\DiscountDecisionStatus;
use App\Enums\DocumentStatus;
use App\Models\Agreements\DiscountDecision;
use App\Models\FinanceSettings;
use App\Models\RoleTemplate;
use App\Models\StateTransition;
use App\Money\VatCalculator;
use App\Support\StaleRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/AgreementTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = agreementFixture();
});

describe('quote pricing (AGR-01, Q-34, Q-35)', function () {
    it('prices the AgrQuote prototype exactly: 12% on 91,500.00, exclusive VAT 15% → 92,598.00', function () {
        agrSettings(1500, false);
        $quote = agrQuote($this->f->sales, $this->f->order, agrQuoteData(['type' => 'percent', 'value' => '12']));
        $t = $quote->content['totals'];

        expect($t)->toBe([
            'base_minor' => 9_150_000, 'discount_minor' => 1_098_000, 'net_minor' => 8_052_000,
            'vat_minor' => 1_207_800, 'total_minor' => 9_259_800, 'products_minor' => 7_392_000,
        ])
            // The discount is allocated to the lines by amount: 10,080.00 and 900.00.
            ->and(array_column($quote->content['lines'], 'discount_minor'))->toBe([1_008_000, 90_000]);

        // 12% is above the sales limit of 10% by 1,830.00 (AgrQuote: «أعلى من حدك بمبلغ 1,830.00»).
        $state = DiscountCheck::state($this->f->sales, 'document', $quote->id, $quote->content);
        expect($state['state'])->toBe(DiscountCheck::NEEDS_DECISION)
            ->and($state['limit_bp'])->toBe(1000)
            ->and($state['share_bp'])->toBe(1200)
            ->and($state['over_minor'])->toBe(183_000);
    });

    it('computes VAT per line with half-up and sums the rounded lines (inclusive and exclusive)', function () {
        $lines = [
            ['description' => 'a', 'quantity' => '1', 'unit_price' => '10.01'],
            ['description' => 'b', 'quantity' => '1', 'unit_price' => '10.01'],
            ['description' => 'c', 'quantity' => '1.5', 'unit_price' => '3.33'],
        ];
        $inclusive = QuotePricing::price(array_map(fn ($l) => [...$l, 'unit_price_minor' => (int) round(((float) $l['unit_price']) * 100)], $lines), ['type' => 'none'], new VatCalculator(1500, true));
        // 1001 → vat 130.565… → 131 each; 3.33 × 1.5 = 4.995 → 500 → vat 65.217… → 65.
        expect(array_column($inclusive['lines'], 'vat_minor'))->toBe([131, 131, 65])
            ->and($inclusive['totals']['vat_minor'])->toBe(327)
            ->and($inclusive['totals']['total_minor'])->toBe(2502);

        $exclusive = QuotePricing::price(array_map(fn ($l) => [...$l, 'unit_price_minor' => (int) round(((float) $l['unit_price']) * 100)], $lines), ['type' => 'none'], new VatCalculator(1500, false));
        // 1001 × 15% = 150.15 → 150 each; 500 × 15% = 75. Total = 2502 + 375.
        expect(array_column($exclusive['lines'], 'vat_minor'))->toBe([150, 150, 75])
            ->and($exclusive['totals']['total_minor'])->toBe(2877);
    });

    it('builds the draft from the order lines and leaves unknown prices unknown, never zero', function () {
        $quote = app(T00CreateQuote::class)->handle($this->f->sales, $this->f->order, agrKey());

        expect($quote->status)->toBe(DocumentStatus::Draft)
            ->and($quote->number)->toStartWith('QUO-')
            ->and($quote->content['lines'][0]['description'])->toBe('جزيرة وسطية')
            ->and($quote->content['lines'][0]['unit_price_minor'])->toBeNull()
            ->and($quote->content['complete'])->toBeFalse()
            ->and($quote->content['totals']['total_minor'])->toBeNull();
        // The draft creates no debt: the order keeps no value.
        expect($this->f->order->refresh()->total_minor)->toBeNull();
    });

    it('refuses an amount discount above the lines and non-positive quantities', function () {
        $quote = app(T00CreateQuote::class)->handle($this->f->sales, $this->f->order, agrKey());
        expect(fn () => app(UpdateDocumentDraft::class)->handle($this->f->sales, $quote, agrQuoteData(['type' => 'amount', 'value' => '100000']), $quote->lock_version))
            ->toThrow(ValidationException::class);
        expect(fn () => app(UpdateDocumentDraft::class)->handle($this->f->sales, $quote->refresh(), agrQuoteData(lines: [['description' => 'x', 'quantity' => '0', 'unit_price' => '1']]), $quote->lock_version))
            ->toThrow(ValidationException::class);
    });

    it('lets only P-29 holders price a quote', function () {
        $quote = app(T00CreateQuote::class)->handle($this->f->sales, $this->f->order, agrKey());
        agrRefused(fn () => app(UpdateDocumentDraft::class)->handle($this->f->finance, $quote, agrQuoteData(), $quote->lock_version, agrKey()), AuthorizationException::class);
    });
});

describe('discount limit and referral (AGR-02, M-26)', function () {
    beforeEach(function () {
        agrSettings(1500, false);
        $this->quote = agrQuote($this->f->sales, $this->f->order, agrQuoteData(['type' => 'percent', 'value' => '12']));
    });

    it('does not send a discount above the limit as approved', function () {
        agrRefused(fn () => agrSend($this->f->sales, $this->quote), InvalidTransition::class, 'discount_needs_decision');
    });

    it('sends within the limit without any decision', function () {
        $quote = app(UpdateDocumentDraft::class)->handle($this->f->sales, $this->quote, agrQuoteData(['type' => 'percent', 'value' => '10']), $this->quote->lock_version, agrKey());
        expect(agrSend($this->f->sales, $quote)->status)->toBe(DocumentStatus::AwaitingCustomer)
            ->and(DiscountDecision::query()->count())->toBe(0);
    });

    it('refers the excess to a supervisor in scope who approves it; then the preparer sends it', function () {
        $decision = app(T00RequestDiscount::class)->handle($this->f->sales, $this->quote, 'مشروع ست شقق', $this->quote->lock_version, agrKey());
        expect($decision->status)->toBe(DiscountDecisionStatus::Requested)
            ->and($decision->base_minor)->toBe(9_150_000)->and($decision->discount_minor)->toBe(1_098_000)->and($decision->limit_bp)->toBe(1000);

        // Still blocked while requested.
        agrRefused(fn () => agrSend($this->f->sales, $this->quote), InvalidTransition::class, 'discount_needs_decision');

        app(T01ApproveDiscount::class)->handle($this->f->supervisor, $decision, true, null, $decision->lock_version, agrKey());
        expect($decision->refresh()->status)->toBe(DiscountDecisionStatus::Approved)
            ->and($decision->decided_by)->toBe($this->f->supervisor->id)
            ->and(StateTransition::query()->where('machine', 'M-26')->where('transition', 'T01')->count())->toBe(1);
        // Approval does not send the quote.
        expect($this->quote->refresh()->status)->toBe(DocumentStatus::Draft);

        expect(agrSend($this->f->sales, $this->quote)->status)->toBe(DocumentStatus::AwaitingCustomer);
    });

    it('never lets the requester approve their own request', function () {
        $quote = app(UpdateDocumentDraft::class)->handle($this->f->manager, $this->quote, agrQuoteData(['type' => 'percent', 'value' => '25']), $this->quote->lock_version, agrKey());
        $decision = app(T00RequestDiscount::class)->handle($this->f->manager, $quote, 'سبب', $quote->lock_version, agrKey());
        agrRefused(fn () => app(T01ApproveDiscount::class)->handle($this->f->manager, $decision, true, null, $decision->lock_version, agrKey()), InvalidTransition::class, 'own_request');
    });

    it('refuses an approver whose approval limit is below the discount, and anyone without P-31', function () {
        $quote = app(UpdateDocumentDraft::class)->handle($this->f->sales, $this->quote, agrQuoteData(['type' => 'percent', 'value' => '18']), $this->quote->lock_version, agrKey());
        $decision = app(T00RequestDiscount::class)->handle($this->f->sales, $quote, 'سبب', $quote->lock_version, agrKey());

        agrRefused(fn () => app(T01ApproveDiscount::class)->handle($this->f->supervisor, $decision, true, null, $decision->lock_version, agrKey()), InvalidTransition::class, 'above_approval_limit');
        agrRefused(fn () => app(T01ApproveDiscount::class)->handle($this->f->finance, $decision, true, null, $decision->lock_version, agrKey()), AuthorizationException::class);
        // The owner has no limit.
        app(T01ApproveDiscount::class)->handle($this->f->owner, $decision, true, null, $decision->lock_version, agrKey());
        expect($decision->refresh()->status)->toBe(DiscountDecisionStatus::Approved);
    });

    it('rejects with a reason and keeps the quote a draft', function () {
        $decision = app(T00RequestDiscount::class)->handle($this->f->sales, $this->quote, 'سبب', $this->quote->lock_version, agrKey());
        expect(fn () => app(T02RejectDiscount::class)->handle($this->f->supervisor, $decision, ' ', $decision->lock_version))->toThrow(ValidationException::class);
        app(T02RejectDiscount::class)->handle($this->f->supervisor, $decision, 'أعلى من المعتاد', $decision->lock_version, agrKey());

        expect($decision->refresh()->status)->toBe(DiscountDecisionStatus::Rejected)
            ->and($this->quote->refresh()->status)->toBe(DocumentStatus::Draft);
        agrRefused(fn () => agrSend($this->f->sales, $this->quote), InvalidTransition::class, 'discount_needs_decision');
    });

    it('re-checks when a price, quantity or line changes: the approval is superseded (T04)', function () {
        $decision = app(T00RequestDiscount::class)->handle($this->f->sales, $this->quote, 'سبب', $this->quote->lock_version, agrKey());
        app(T01ApproveDiscount::class)->handle($this->f->supervisor, $decision, true, null, $decision->lock_version, agrKey());

        $lines = agrQuoteData()['lines'];
        $lines[0]['quantity'] = '7';
        $quote = app(UpdateDocumentDraft::class)->handle($this->f->sales, $this->quote->refresh(), agrQuoteData(['type' => 'percent', 'value' => '12'], $lines), $this->quote->lock_version, agrKey());

        expect($decision->refresh()->status)->toBe(DiscountDecisionStatus::Superseded)
            ->and(StateTransition::query()->where('machine', 'M-26')->where('transition', 'T04')->count())->toBe(1);
        agrRefused(fn () => agrSend($this->f->sales, $quote), InvalidTransition::class, 'discount_needs_decision');
    });

    it('refuses a decision on content that changed after the request', function () {
        $decision = app(T00RequestDiscount::class)->handle($this->f->sales, $this->quote, 'سبب', $this->quote->lock_version, agrKey());
        // A change cancels the open request (T03) — deciding it afterwards is refused.
        app(UpdateDocumentDraft::class)->handle($this->f->sales, $this->quote->refresh(), agrQuoteData(['type' => 'percent', 'value' => '11']), $this->quote->lock_version, agrKey());
        expect($decision->refresh()->status)->toBe(DiscountDecisionStatus::Cancelled);
        agrRefused(fn () => app(T01ApproveDiscount::class)->handle($this->f->supervisor, $decision, true, null, $decision->lock_version, agrKey()), InvalidTransition::class, 'not_from_cancelled');
    });
});

it('stores finance settings with optimistic locking and only for P-02', function () {
    $settings = app(UpdateFinanceSettings::class)->handle($this->f->manager, [
        'vat_rate_bp' => 1500, 'prices_include_vat' => false, 'quote_validity_days' => 10, 'contracts_enabled' => false,
    ], null);
    expect(FinanceSettings::current()->prices_include_vat)->toBeFalse()->and($settings->lock_version)->toBe(1);

    expect(fn () => app(UpdateFinanceSettings::class)->handle($this->f->manager, [
        'vat_rate_bp' => 1000, 'prices_include_vat' => true, 'quote_validity_days' => 10, 'contracts_enabled' => true,
    ], 99))->toThrow(StaleRecord::class);
    expect(fn () => app(UpdateFinanceSettings::class)->handle($this->f->sales, [
        'vat_rate_bp' => 1000, 'prices_include_vat' => true, 'quote_validity_days' => 10, 'contracts_enabled' => true,
    ], 1))->toThrow(AuthorizationException::class);
    expect(FinanceSettings::current()->vat_rate_bp)->toBe(1500);
});

it('lets only the owner set discount limits', function () {
    $roles = RoleTemplate::query()->where('key', 'sales')->pluck('id');
    $payload = ['limits' => [['role_template_id' => $roles[0], 'custom_bp' => 500, 'ready_bp' => 300, 'approval_bp' => 0]]];

    expect(fn () => app(UpdateDiscountLimits::class)->handle($this->f->manager, $payload))->toThrow(AuthorizationException::class);
    app(UpdateDiscountLimits::class)->handle($this->f->owner, $payload);
    expect(DiscountLimits::of($this->f->sales, 'custom_bp'))->toBe(500)
        ->and(DiscountLimits::of($this->f->owner, 'custom_bp'))->toBeNull();
});
