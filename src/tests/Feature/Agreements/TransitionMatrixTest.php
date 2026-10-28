<?php

/**
 * Every M-08, M-09 and M-26 transition: allowed from its defined states (target state + `state_transitions` row) and
 * refused from every other state without any side effect.
 */

use App\Access\Exceptions\InvalidTransition;
use App\Actions\StateMachines\M08Document\T02RecordExternalApproval;
use App\Actions\StateMachines\M08Document\T06ExtendDocument;
use App\Actions\StateMachines\M08Document\T08CancelDocument;
use App\Actions\StateMachines\M09Change\T01PresentChange;
use App\Actions\StateMachines\M09Change\T02RequestChangeRevision;
use App\Actions\StateMachines\M09Change\T05WithdrawChange;
use App\Actions\StateMachines\M26Decision\T00RequestDiscount;
use App\Actions\StateMachines\M26Decision\T01ApproveDiscount;
use App\Actions\StateMachines\M26Decision\T02RejectDiscount;
use App\Actions\StateMachines\M26Decision\T03CancelDiscount;
use App\Agreements\CustomerActor;
use App\Agreements\CustomerDecisions;
use App\Enums\ChangeStatus;
use App\Enums\DiscountDecisionStatus;
use App\Enums\DocumentStatus;
use App\Models\Agreements\AgreementChange;
use App\Models\Agreements\AgreementDocument;
use App\Models\Agreements\DiscountDecision;
use App\Models\StateTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/AgreementTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = agreementFixture();
});

/**
 * A sent quote moved to `$status` directly (the machine is what is under test).
 */
function agrDocumentIn(object $f, DocumentStatus $status): AgreementDocument
{
    $quote = $status === DocumentStatus::Draft ? agrQuote($f->sales, $f->order) : agrSend($f->sales, agrQuote($f->sales, $f->order));
    DB::table('agreement_documents')->where('id', $quote->id)->update(['status' => $status->value]);

    return $quote->refresh();
}

/**
 * @return array<string, Closure(object, AgreementDocument): mixed>
 */
function agrDocumentAttempts(): array
{
    return [
        'T01' => fn (object $f, AgreementDocument $d) => agrSend($f->sales, $d),
        'T02' => fn (object $f, AgreementDocument $d) => app(T02RecordExternalApproval::class)->handle($f->owner, $d, [
            'channel' => 'paper', 'decided_on' => now()->toDateString(), 'content_hash' => $d->content_hash ?? str_repeat('0', 64),
        ], agrPdf(), $d->lock_version, agrKey()),
        'T03' => fn (object $f, AgreementDocument $d) => app(CustomerDecisions::class)->requestDocumentChanges(
            new CustomerActor($f->order->customer_id, $f->order->customer->mobile), $d, (string) $d->content_hash, 'ملاحظة'),
        'T06' => fn (object $f, AgreementDocument $d) => app(T06ExtendDocument::class)->handle($f->sales, $d, 5, $d->lock_version),
        'T08' => fn (object $f, AgreementDocument $d) => app(T08CancelDocument::class)->handle($f->sales, $d, 'سبب', $d->lock_version),
    ];
}

describe('M-08', function () {
    $cases = [];
    foreach (DocumentStatus::transitions() as $code => $definition) {
        if (! in_array($code, ['T01', 'T02', 'T03', 'T06', 'T08'], true)) {
            continue; // T04/T05/T07 are covered with their triggers (replacement send, timed expiry, replacement approval).
        }
        foreach (DocumentStatus::cases() as $status) {
            $cases["{$code} from {$status->value}"] = [$code, $status, in_array($status, $definition['from'], true), $definition['to']];
        }
    }

    it('allows each transition only from its states', function (string $code, DocumentStatus $from, bool $allowed, DocumentStatus $to) {
        $document = agrDocumentIn($this->f, $from);
        $attempt = fn () => agrDocumentAttempts()[$code]($this->f, $document);

        if (! $allowed) {
            agrRefused($attempt, InvalidTransition::class, 'not_from_'.$from->value);

            return;
        }
        $attempt();
        expect($document->refresh()->status)->toBe($to)
            ->and(StateTransition::query()->where('machine', 'M-08')->where('transition', $code)->where('subject_id', $document->id)->exists())->toBeTrue();
    })->with($cases);
});

describe('M-09', function () {
    $attempts = [
        'T01' => fn (object $f, AgreementChange $c) => app(T01PresentChange::class)->handle($f->sales, $c, $c->lock_version, agrKey()),
        'T02' => fn (object $f, AgreementChange $c) => app(T02RequestChangeRevision::class)->handle($f->sales, $c, 'سبب', $c->lock_version),
        'T04' => fn (object $f, AgreementChange $c) => agrApplyChange($f->owner, $c),
        'T05' => fn (object $f, AgreementChange $c) => app(T05WithdrawChange::class)->handle($f->sales, $c, 'سبب', $c->lock_version),
    ];
    $cases = [];
    foreach (ChangeStatus::transitions() as $code => $definition) {
        foreach (ChangeStatus::cases() as $status) {
            $cases["{$code} from {$status->value}"] = [$code, $status, in_array($status, $definition['from'], true), $definition['to']];
        }
    }

    it('allows each transition only from its states', function (string $code, ChangeStatus $from, bool $allowed, ChangeStatus $to) use ($attempts) {
        agrAgreed($this->f, [['description' => 'مطبخ', 'quantity' => '1', 'unit_price' => '20000']]);
        $change = agrChange($this->f->sales, $this->f->order, [['description' => 'مطبخ', 'quantity' => '1', 'unit_price' => '21000']]);
        // T01 (draft) and T03 (needs revision) share the present action, which picks the code from the state.
        $attempt = $attempts[$code === 'T03' ? 'T01' : $code];
        DB::table('agreement_changes')->where('id', $change->id)->update([
            'status' => $from->value, 'open_key' => $from->isOpen() ? $this->f->order->id : null,
        ]);
        $change->refresh();

        if (! $allowed) {
            if (in_array($code, ['T01', 'T03'], true) && in_array($from, [ChangeStatus::Draft, ChangeStatus::NeedsRevision], true)) {
                // The present action takes T01 from a draft and T03 after a revision request: covered by the other code.
                expect($attempts)->toHaveKey('T01');

                return;
            }
            agrRefused(fn () => $attempt($this->f, $change), InvalidTransition::class, 'not_from_'.$from->value);

            return;
        }
        $attempt($this->f, $change);
        expect($change->refresh()->status)->toBe($to)
            ->and(StateTransition::query()->where('machine', 'M-09')->where('transition', $code)->where('subject_id', $change->id)->exists())->toBeTrue();
    })->with($cases);
});

describe('M-26', function () {
    $attempts = [
        'T01' => fn (object $f, DiscountDecision $d) => app(T01ApproveDiscount::class)->handle($f->owner, $d, true, null, $d->lock_version, agrKey()),
        'T02' => fn (object $f, DiscountDecision $d) => app(T02RejectDiscount::class)->handle($f->owner, $d, 'سبب', $d->lock_version, agrKey()),
        'T03' => fn (object $f, DiscountDecision $d) => app(T03CancelDiscount::class)->handle($f->sales, $d, $d->lock_version),
    ];
    $cases = [];
    foreach (DiscountDecisionStatus::transitions() as $code => $definition) {
        if ($code === 'T04') {
            continue; // automatic on a content change (QuoteAndDiscountTest)
        }
        foreach (DiscountDecisionStatus::cases() as $status) {
            $cases["{$code} from {$status->value}"] = [$code, $status, in_array($status, $definition['from'], true), $definition['to']];
        }
    }

    it('allows each transition only from its states', function (string $code, DiscountDecisionStatus $from, bool $allowed, DiscountDecisionStatus $to) use ($attempts) {
        $quote = agrQuote($this->f->sales, $this->f->order, agrQuoteData(['type' => 'percent', 'value' => '12']));
        $decision = app(T00RequestDiscount::class)->handle($this->f->sales, $quote, 'سبب', $quote->lock_version, agrKey());
        DB::table('discount_decisions')->where('id', $decision->id)->update(['status' => $from->value]);
        $decision->refresh();

        if (! $allowed) {
            agrRefused(fn () => $attempts[$code]($this->f, $decision), InvalidTransition::class, 'not_from_'.$from->value);

            return;
        }
        $attempts[$code]($this->f, $decision);
        expect($decision->refresh()->status)->toBe($to)
            ->and(StateTransition::query()->where('machine', 'M-26')->where('transition', $code)->where('subject_id', $decision->id)->exists())->toBeTrue();
    })->with($cases);
});
