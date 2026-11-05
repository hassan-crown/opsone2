<?php

/**
 * Clause library and message templates (ADR-0037): clauses rendered with the closed variables and copied into the
 * prepared document with their version (immutable once sent; a library edit adds a version and never reaches it), the
 * seller tax profile in the contract's parties, facility wordings for SMS / mail / in-app with closed variables, a
 * preview that writes nothing, reset to the built-in text, and per-facility WhatsApp template names. P-104 only.
 */

use App\Actions\Rules\SaveDocumentClause;
use App\Actions\Rules\SaveMessageTemplate;
use App\Actions\StateMachines\M08Document\T00PrepareContract;
use App\Actions\StateMachines\M08Document\UpdateDocumentDraft;
use App\Finance\CollectionReminders;
use App\Models\Agreements\DocumentClause;
use App\Models\Agreements\DocumentClauseVersion;
use App\Models\AuditEvent;
use App\Models\CustomerMessage;
use App\Models\EInvoicing\ZatcaSettings;
use App\Models\MessageTemplate;
use App\Models\WorkSettings;
use App\Notifications\Notifier;
use App\Whatsapp\WhatsAppEvent;
use App\Whatsapp\WhatsAppSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/RulesTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = financeFixture();
});

/**
 * @param  list<string>  $kinds
 */
function saveClause(object $f, string $key, string $ar, array $kinds = ['contract'], ?DocumentClause $clause = null): DocumentClause
{
    return app(SaveDocumentClause::class)->handle($f->owner, $clause, [
        'key' => $key, 'title' => ['ar' => 'بند '.$key], 'body' => ['ar' => $ar], 'applies_to' => $kinds, 'included_by_default' => true,
    ], $clause?->lock_version);
}

it('copies the rendered clauses and their versions into a contract, which keeps them after the library changes', function () {
    ZatcaSettings::query()->create(['seller_name_ar' => 'معرض', 'vat_number' => '300000000000003', 'commercial_registration' => '1010101010']);
    $clause = saveClause($this->f, 'warranty', 'تضمن {facility} الطلب {order} للعميل {customer} بقيمة {total}.');
    publishRules($this->f->owner);
    finAgree($this->f, '11500');

    $contract = app(T00PrepareContract::class)->handle($this->f->sales, $this->f->order->refresh(), null, null, agrKey());
    $content = $contract->content;
    expect($content['clause_versions'])->toBe([['key' => 'warranty', 'version' => 1]])
        ->and($content['clauses'][0])->toContain($this->f->order->number)->toContain('11500.00')->not->toContain('{')
        ->and($content['parties']['facility']['vat_number'])->toBe('300000000000003')
        ->and($content['parties']['facility']['commercial_registration'])->toBe('1010101010')
        ->and($content['missing'])->not->toContain('facility.vat_number');
    $sent = agrSend($this->f->sales, $contract)->refresh();
    $hash = $sent->content_hash;

    saveClause($this->f, 'warranty', 'نص جديد للضمان.', clause: $clause->refresh());
    expect($clause->refresh()->version)->toBe(2)
        ->and(DocumentClauseVersion::query()->where('document_clause_id', $clause->id)->pluck('version')->all())->toBe([1, 2])
        ->and($sent->refresh()->content['clauses'][0])->not->toContain('نص جديد')
        ->and($sent->content_hash)->toBe($hash);

    // A metadata-only edit (no text change) adds no version; versions are immutable.
    app(SaveDocumentClause::class)->handle($this->f->owner, $clause->refresh(), [
        'key' => 'warranty', 'title' => ['ar' => 'بند warranty'], 'body' => ['ar' => 'نص جديد للضمان.'], 'applies_to' => ['contract', 'quote'], 'included_by_default' => true,
    ], $clause->lock_version);
    expect($clause->refresh()->version)->toBe(2);
    expect(fn () => DocumentClauseVersion::query()->first()->update(['version' => 9]))->toThrow(LogicException::class);
});

it('keeps the built-in clauses without a library, and re-renders a quote draft from its pinned clause version', function () {
    finAgree($this->f, '1000');
    $contract = app(T00PrepareContract::class)->handle($this->f->sales, $this->f->order->refresh(), null, null, agrKey());
    expect($contract->content['clauses'])->toHaveCount(4)->and($contract->content['clause_versions'])->toBe([]);

    $other = openCustomOrder($this->f->sales, $this->f->inA);
    saveClause($this->f, 'site', 'السعر {total} لا يشمل الكهرباء.', ['quote']);
    publishRules($this->f->owner);
    $quote = agrQuote($this->f->sales, $other);
    expect($quote->content['clause_versions'])->toBe([['key' => 'site', 'version' => 1]]);
    saveClause($this->f, 'site', 'نص آخر {total}.', ['quote'], DocumentClause::query()->where('key', 'site')->sole());
    publishRules($this->f->owner);

    $quote = app(UpdateDocumentDraft::class)->handle($this->f->sales, $quote->refresh(), agrQuoteData(lines: [finLine('مطبخ', '2300')]), $quote->lock_version);
    expect($quote->content['clause_versions'])->toBe([['key' => 'site', 'version' => 1]])
        ->and($quote->content['clauses'][0])->toBe('السعر 2300.00 لا يشمل الكهرباء.');
});

it('refuses clause variables outside the closed set and changes by anyone without P-104', function () {
    expect(fn () => saveClause($this->f, 'bad', 'قيمة {price}'))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveDocumentClause::class)->handle($this->f->manager, null, [
            'key' => 'x', 'title' => ['ar' => 'x'], 'body' => ['ar' => 'x'], 'applies_to' => ['quote'], 'included_by_default' => true,
        ], null))->toThrow(AuthorizationException::class);
    saveClause($this->f, 'ok', 'نص');
    expect(AuditEvent::query()->where('action', 'document_clause.saved')->count())->toBe(1);
    $this->actingAs($this->f->manager)->get('/settings/clauses')->assertOk()->assertInertia(fn ($p) => $p->component('rules/clauses')->where('canEdit', false));
});

it('sends customer reminders and staff notices in the facility wording, then the built-in text after a reset', function () {
    WorkSettings::query()->create([...WorkSettings::DEFAULTS, 'reminder_from' => '00:00', 'reminder_until' => '23:59']);
    $save = app(SaveMessageTemplate::class);
    $save->handle($this->f->owner, ['event' => 'finance.due_overdue', 'channel' => 'sms', 'locale' => 'ar', 'body' => 'عزيزنا، {due} للطلب {order} بمبلغ {amount} ريال متأخرة. {facility}']);
    publishRules($this->f->owner);
    finAgree($this->f, '20000');
    $this->travel(3)->days();
    app(CollectionReminders::class)->runDue(now());
    // The customer log keeps the text sent (the outbox drops its payload once delivered).
    $sms = CustomerMessage::query()->where('idempotency_key', 'like', 'due:%:overdue:%')->sole();
    expect($sms->body)->toStartWith('عزيزنا، ')->toContain($this->f->order->number)->toContain('10000.00')->not->toContain('{');

    // In-app: rendered in the recipient's language at send time.
    $save->handle($this->f->owner, ['event' => 'task.assigned', 'channel' => 'in_app', 'locale' => 'ar', 'subject' => 'مهمة: {task}', 'body' => 'الطلب {order}']);
    publishRules($this->f->owner);
    $notice = app(Notifier::class)->send($this->f->sales, 'task.assigned', ['order' => 'ORD-1', 'task' => 'قياس']);
    expect($notice->data['title'])->toBe('مهمة: قياس')->and($notice->data['body'])->toBe('الطلب ORD-1');

    $save->reset($this->f->owner, 'task.assigned', 'in_app', 'ar');
    publishRules($this->f->owner);
    $again = app(Notifier::class)->send($this->f->sales, 'task.assigned', ['order' => 'ORD-2', 'task' => 'قياس']);
    expect($again->data['title'])->toBe('notifications.type.task_assigned.title')
        ->and(MessageTemplate::query()->count())->toBe(1)
        ->and(AuditEvent::query()->whereIn('action', ['message_template.saved', 'message_template.reset'])->count())->toBe(3);
});

it('refuses unknown variables, events and channels, previews without writing, and needs P-104', function () {
    $save = app(SaveMessageTemplate::class);
    expect(fn () => $save->handle($this->f->owner, ['event' => 'finance.due_soon', 'channel' => 'sms', 'locale' => 'ar', 'body' => 'رقم {iban}']))->toThrow(ValidationException::class)
        ->and(fn () => $save->handle($this->f->owner, ['event' => 'finance.due_soon', 'channel' => 'in_app', 'locale' => 'ar', 'body' => 'x']))->toThrow(ValidationException::class)
        ->and(fn () => $save->handle($this->f->owner, ['event' => 'nope', 'channel' => 'sms', 'locale' => 'ar', 'body' => 'x']))->toThrow(ValidationException::class)
        ->and(fn () => $save->handle($this->f->manager, ['event' => 'finance.due_soon', 'channel' => 'sms', 'locale' => 'ar', 'body' => 'x']))->toThrow(AuthorizationException::class);

    $this->actingAs($this->f->owner)->postJson('/settings/messages/preview', ['event' => 'finance.due_soon', 'locale' => 'ar', 'body' => 'تذكير {order} بمبلغ {amount}'])
        ->assertOk()->assertJson(['body' => 'تذكير ORD-2026-00042 بمبلغ 12,500.00']);
    $this->actingAs($this->f->owner)->postJson('/settings/messages/preview', ['event' => 'finance.due_soon', 'locale' => 'ar', 'body' => '{iban}'])->assertUnprocessable();
    $this->actingAs($this->f->sales)->postJson('/settings/messages/preview', ['event' => 'finance.due_soon', 'locale' => 'ar', 'body' => 'x'])->assertForbidden();
    $this->actingAs($this->f->owner)->get('/settings/messages')->assertOk()->assertInertia(fn ($p) => $p->component('rules/messages')->has('whatsapp', 9));
    expect(MessageTemplate::query()->count())->toBe(0);
});

it('uses the facility\'s approved WhatsApp template name per event, else the environment\'s', function () {
    $settings = app(WhatsAppSettings::class);
    expect(WhatsAppEvent::QuoteReady->template())->toBe('opsone_quote_ready');
    $settings->save($this->f->owner, true, WhatsAppEvent::values());
    $settings->saveTemplateNames($this->f->owner, ['quote_ready' => 'kitchens_quote_v2', 'payment_due' => '']);
    expect(WhatsAppEvent::QuoteReady->template())->toBe('kitchens_quote_v2')
        ->and(WhatsAppEvent::PaymentDue->template())->toBe('opsone_payment_due')
        ->and($settings->allows(WhatsAppEvent::QuoteReady))->toBeTrue(); // the events survive the names

    // Saving the switches keeps the names.
    $settings->save($this->f->owner, true, ['quote_ready']);
    expect(WhatsAppEvent::QuoteReady->template())->toBe('kitchens_quote_v2');
    expect(fn () => $settings->saveTemplateNames($this->f->owner, ['quote_ready' => 'Bad Name!']))->toThrow(ValidationException::class);
    $this->actingAs($this->f->manager)->put('/settings/messages/whatsapp', ['templates' => ['quote_ready' => 'x']])->assertForbidden();
});
