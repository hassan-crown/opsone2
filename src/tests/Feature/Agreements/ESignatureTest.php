<?php

/**
 * Branded quote/contract PDFs and the customer's electronic signature (ADR-0029): PDFs stored once per source with
 * their hash, signed documents immutable, signing from the portal with a fresh step-up code through the existing
 * M-08-T02 path, signing links that expire, and the refusals (wrong customer, replay, already signed).
 */

use App\Actions\StateMachines\M08Document\T00PrepareContract;
use App\Agreements\OrderAgreement;
use App\Agreements\Signing\DocumentPdfs;
use App\Agreements\Signing\DocumentSheet;
use App\Agreements\Signing\ESignature;
use App\Agreements\Signing\SignLinks;
use App\Enums\DocumentStatus;
use App\Enums\StateMachine;
use App\Models\Agreements\AgreementDocument;
use App\Models\Agreements\CustomerDecision;
use App\Models\Agreements\DocumentPdf;
use App\Models\Agreements\DocumentSignature;
use App\Models\Agreements\DocumentSignLink;
use App\Models\AuditEvent;
use App\Models\CustomerOtpChallenge;
use App\Models\OutboxMessage;
use App\Models\Site\SiteTheme;
use App\Models\StateTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Mpdf\Mpdf;
use setasign\Fpdi\PdfParser\StreamReader;

require_once __DIR__.'/../Portal/PortalTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->f = portalFixture();
    Queue::fake();
    // A second order of customer A with a quote awaiting the customer (no agreement yet): the one to sign.
    $this->f->orderC = openCustomOrder($this->f->sales, $this->f->a);
    $this->quote = agrSend($this->f->sales, agrQuote($this->f->sales, $this->f->orderC))->refresh();
    Auth::forgetGuards();
});

function esignPng(): string
{
    $image = imagecreatetruecolor(480, 160);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
    $ink = (int) imagecolorallocate($image, 20, 30, 60);
    imagesetthickness($image, 4);
    imageline($image, 30, 120, 180, 40, $ink);
    imageline($image, 180, 40, 300, 130, $ink);
    imageline($image, 300, 130, 440, 50, $ink);
    ob_start();
    imagepng($image);

    return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
}

function esignPdfBytes(DocumentPdf $pdf): string
{
    return (string) Storage::disk($pdf->attachment->disk)->get($pdf->attachment->path);
}

function esignPages(string $bytes): int
{
    return (new Mpdf(['tempDir' => storage_path('framework/cache/mpdf')]))->setSourceFile(StreamReader::createByString($bytes));
}

/**
 * Opens the document page (generating the PDF it binds to), asks for a code and returns the form to sign.
 *
 * @return array<string, mixed>
 */
function esignForm(AgreementDocument $document, array $override = []): array
{
    test()->get("/portal/documents/{$document->id}")->assertOk();
    test()->post("/portal/documents/{$document->id}/sign/code")->assertRedirect();
    $pdf = DocumentPdfs::latest($document->refresh(), DocumentPdf::ORIGINAL, 'ar');

    return [
        'signer_name' => 'سارة العتيبي',
        'signature' => esignPng(),
        'consent' => '1',
        'code' => lastOtpCode(),
        'content_hash' => $document->content_hash,
        'pdf_sha256' => $pdf?->sha256,
        'locale' => 'ar',
        ...$override,
    ];
}

it('generates the branded PDF once per source and stores it as a private attachment with its hash', function () {
    $this->actingAs($this->f->owner);
    $response = $this->get("/agreement/documents/{$this->quote->id}/pdf?locale=ar")->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf');

    $pdf = DocumentPdf::query()->where('agreement_document_id', $this->quote->id)->sole();
    $bytes = esignPdfBytes($pdf);
    expect(str_starts_with($bytes, '%PDF'))->toBeTrue()
        ->and($pdf->sha256)->toBe(hash('sha256', $bytes))
        ->and($pdf->attachment->sha256)->toBe($pdf->sha256)
        ->and($pdf->attachment->purpose)->toBe(DocumentPdfs::PURPOSE)
        ->and($pdf->layout_version)->toBe(DocumentSheet::LAYOUT_VERSION);

    // Same source → same stored PDF; the other language is its own PDF.
    $this->get("/agreement/documents/{$this->quote->id}/pdf?locale=ar")->assertOk();
    $this->get("/agreement/documents/{$this->quote->id}/pdf?locale=en&download=1")->assertOk()->assertDownload("{$this->quote->number}-en.pdf");
    expect(DocumentPdf::query()->where('agreement_document_id', $this->quote->id)->count())->toBe(2);

    // What the PDF shows changed (new brand colour) while the customer may still decide → a new PDF.
    $theme = SiteTheme::current();
    $theme->forceFill(['published' => ['brand' => '#1F5C6B']])->save();
    $this->get("/agreement/documents/{$this->quote->id}/pdf?locale=ar")->assertOk();
    expect(DocumentPdf::query()->where('agreement_document_id', $this->quote->id)->where('locale', 'ar')->count())->toBe(2);
});

it('previews a draft without storing it and refuses the PDF to staff who cannot see the amounts', function () {
    $draft = agrQuote($this->f->sales, openCustomOrder($this->f->sales, $this->f->a));
    $this->actingAs($this->f->owner)->get("/agreement/documents/{$draft->id}/pdf")->assertOk();
    expect(DocumentPdf::query()->where('agreement_document_id', $draft->id)->exists())->toBeFalse();

    $this->actingAs($this->f->tech)->get("/agreement/documents/{$this->quote->id}/pdf")->assertForbidden();
});

it('signs a quote from the portal with a step-up code and approves it through the existing workflow', function () {
    actingAsCustomer($this->f->a);
    $this->get("/portal/documents/{$this->quote->id}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('portal/document')
        ->where('document.signing.signable', true)
        ->where('document.signing.pdf_sha256', fn ($hash) => is_string($hash) && strlen($hash) === 64));
    $form = esignForm($this->quote);

    $this->withHeader('User-Agent', 'PestBrowser/1.0')->post("/portal/documents/{$this->quote->id}/sign", $form)
        ->assertSessionHasNoErrors()->assertRedirect("/portal/documents/{$this->quote->id}");

    $quote = $this->quote->refresh();
    $signature = DocumentSignature::query()->where('agreement_document_id', $quote->id)->sole();
    $decision = CustomerDecision::query()->findOrFail($signature->customer_decision_id);
    expect($quote->status)->toBe(DocumentStatus::Approved)
        ->and(OrderAgreement::current($this->f->orderC->refresh()))->not->toBeNull()
        ->and($decision->method)->toBe(CustomerDecision::METHOD_SIGNATURE)
        ->and($decision->content_hash)->toBe($quote->content_hash)
        ->and($signature->signer_name)->toBe('سارة العتيبي')
        ->and($signature->customer_id)->toBe($this->f->a->id)
        ->and($signature->user_agent)->toBe('PestBrowser/1.0')
        ->and($signature->ip)->toBe('127.0.0.1')
        ->and($signature->pdf_sha256)->toBe($form['pdf_sha256'])
        ->and($signature->content_hash)->toBe($quote->content_hash)
        ->and($signature->consent_version)->toBe(ESignature::CONSENT_VERSION)
        ->and(CustomerOtpChallenge::query()->findOrFail($signature->otp_challenge_id)->consumed_at)->not->toBeNull();

    // Workflow transition (M-08-T02) written by the existing path, and the audit entry.
    expect(StateTransition::query()->where('machine', StateMachine::M08->value)->where('transition', 'T02')
        ->where('subject_id', $quote->id)->where('context->method', CustomerDecision::METHOD_SIGNATURE)->exists())->toBeTrue();
    $audit = AuditEvent::query()->where('action', 'agreement_document.signed')->sole();
    expect($audit->after['document'])->toBe($quote->number)
        ->and($audit->after['pdf_sha256'])->toBe($signature->pdf_sha256)
        ->and(json_encode($audit->after))->not->toContain('سارة');

    // Signed PDF: the signed original's pages unchanged plus one certificate page.
    $signed = $signature->signedPdf;
    expect($signed)->not->toBeNull()
        ->and($signed->variant)->toBe(DocumentPdf::SIGNED)
        ->and(esignPages(esignPdfBytes($signed)))->toBe(esignPages(esignPdfBytes($signature->pdf)) + 1);

    $this->get("/portal/documents/{$quote->id}")->assertInertia(fn (AssertableInertia $p) => $p
        ->where('document.signing.signable', false)->where('document.signing.signed.signer_name', 'سارة العتيبي'));
    $this->get("/portal/documents/{$quote->id}/pdf?download=1")->assertOk()->assertDownload("{$quote->number}-ar-signed.pdf");
});

it('keeps a signed document and its PDFs immutable', function () {
    actingAsCustomer($this->f->a);
    $this->post("/portal/documents/{$this->quote->id}/sign", esignForm($this->quote))->assertSessionHasNoErrors();
    $signature = DocumentSignature::query()->sole();
    $before = DocumentPdf::query()->count();

    // Branding changes after the signature never regenerate the signed document's PDF.
    SiteTheme::current()->forceFill(['published' => ['brand' => '#8A4317']])->save();
    Auth::forgetGuards();
    $this->actingAs($this->f->owner)->get("/agreement/documents/{$this->quote->id}/pdf?version=original&locale=ar")->assertOk();
    expect(DocumentPdf::query()->count())->toBe($before);

    expect(fn () => $signature->forceFill(['signer_name' => 'غيره'])->save())->toThrow(LogicException::class)
        ->and(fn () => $signature->pdf->forceFill(['sha256' => str_repeat('0', 64)])->save())->toThrow(LogicException::class)
        ->and(fn () => $signature->delete())->toThrow(LogicException::class)
        ->and(fn () => $this->quote->refresh()->forceFill(['content' => []])->save())->toThrow(LogicException::class);

    // Staff cannot send a link or cancel it any more.
    $this->postJson("/agreement/documents/{$this->quote->id}/sign-link")->assertStatus(409)->assertJsonPath('condition', 'not_awaiting_customer');
    $this->postJson("/agreement/documents/{$this->quote->id}/cancel", ['reason' => 'خطأ', 'lock_version' => $this->quote->refresh()->lock_version])->assertStatus(409);
});

it('refuses another customer, who cannot even see the document', function () {
    actingAsCustomer($this->f->b);
    $this->get("/portal/documents/{$this->quote->id}")->assertNotFound();
    $this->get("/portal/documents/{$this->quote->id}/pdf")->assertNotFound();
    $this->post("/portal/documents/{$this->quote->id}/sign/code")->assertNotFound();
    $this->post("/portal/documents/{$this->quote->id}/sign", [
        'signer_name' => 'x y', 'signature' => esignPng(), 'consent' => '1', 'code' => '123456',
        'content_hash' => $this->quote->content_hash, 'pdf_sha256' => str_repeat('a', 64), 'locale' => 'ar',
    ])->assertNotFound();
    expect(DocumentSignature::query()->count())->toBe(0)->and($this->quote->refresh()->status)->toBe(DocumentStatus::AwaitingCustomer);
});

it('refuses a replayed signature, a reused code and a code issued for another document', function () {
    actingAsCustomer($this->f->a);
    $form = esignForm($this->quote);
    $this->post("/portal/documents/{$this->quote->id}/sign", $form)->assertSessionHasNoErrors();

    // The same request again (double tap, replay): refused, nothing recorded twice.
    $this->from("/portal/documents/{$this->quote->id}")->post("/portal/documents/{$this->quote->id}/sign", $form)->assertSessionHasErrors('transition');
    expect(DocumentSignature::query()->count())->toBe(1)
        ->and(CustomerDecision::query()->where('subject_id', $this->quote->id)->where('subject_type', 'document')->count())->toBe(1);

    // A contract of order A awaiting the customer: a code issued for it works only for it, and only once.
    $contract = agrSend($this->f->sales, app(T00PrepareContract::class)->handle($this->f->manager, $this->f->orderA->refresh(), null, 'بنود إضافية', agrKey()))->refresh();
    Auth::forgetGuards();
    actingAsCustomer($this->f->a);
    $contractForm = esignForm($contract);
    $quoteOfOtherOrder = agrSend($this->f->sales, agrQuote($this->f->sales, openCustomOrder($this->f->sales, $this->f->a)))->refresh();
    Auth::forgetGuards();
    actingAsCustomer($this->f->a);
    $this->get("/portal/documents/{$quoteOfOtherOrder->id}")->assertOk();
    $otherPdf = DocumentPdfs::latest($quoteOfOtherOrder, DocumentPdf::ORIGINAL, 'ar');
    $this->from("/portal/documents/{$quoteOfOtherOrder->id}")->post("/portal/documents/{$quoteOfOtherOrder->id}/sign", [
        ...$contractForm, 'content_hash' => $quoteOfOtherOrder->content_hash, 'pdf_sha256' => $otherPdf?->sha256,
    ])->assertSessionHasErrors('code');

    $this->post("/portal/documents/{$contract->id}/sign", $contractForm)->assertSessionHasNoErrors();
    expect($contract->refresh()->status)->toBe(DocumentStatus::Approved);
    $this->from("/portal/documents/{$contract->id}")->post("/portal/documents/{$contract->id}/sign", $contractForm)->assertSessionHasErrors('transition');
});

it('refuses a missing consent, a blank signature, a wrong code and a PDF the customer did not see', function () {
    actingAsCustomer($this->f->a);
    $form = esignForm($this->quote);
    $url = "/portal/documents/{$this->quote->id}/sign";
    $this->from($url)->post($url, [...$form, 'consent' => null])->assertSessionHasErrors('consent');
    $this->from($url)->post($url, [...$form, 'signature' => 'data:image/png;base64,AAAA'])->assertSessionHasErrors('signature');
    $this->from($url)->post($url, [...$form, 'pdf_sha256' => str_repeat('b', 64)])->assertSessionHasErrors('pdf_sha256');
    $this->from($url)->post($url, [...$form, 'code' => $form['code'] === '000000' ? '111111' : '000000'])->assertSessionHasErrors('code');
    expect(DocumentSignature::query()->count())->toBe(0)->and($this->quote->refresh()->status)->toBe(DocumentStatus::AwaitingCustomer);
});

it('rate limits step-up codes', function () {
    actingAsCustomer($this->f->a);
    $this->post("/portal/documents/{$this->quote->id}/sign/code")->assertSessionHasNoErrors();
    $this->post("/portal/documents/{$this->quote->id}/sign/code")->assertSessionHasErrors('code');
    expect(CustomerOtpChallenge::query()->where('purpose', 'step_up')->count())->toBe(1);
});

it('sends an unguessable signing link that brings the customer to sign, once', function () {
    $this->actingAs($this->f->sales)->postJson("/agreement/documents/{$this->quote->id}/sign-link")->assertOk()
        ->assertJsonPath('agreement.documents.0.esign.link.channel', fn ($c) => in_array($c, ['sms', 'email', 'both'], true));
    $link = DocumentSignLink::query()->sole();
    $message = OutboxMessage::query()->where('idempotency_key', 'like', "esign-link:{$link->id}:%")->firstOrFail();
    preg_match('#/portal/sign/([A-Za-z0-9]{48})#', (string) json_encode($message->payload, JSON_UNESCAPED_SLASHES), $m);
    expect($m[1] ?? null)->not->toBeNull()->and($link->token_hash)->toBe(hash('sha256', $m[1]));
    expect(AuditEvent::query()->where('action', 'agreement_document.sign_link_sent')->exists())->toBeTrue();
    Auth::forgetGuards();

    // Not signed in → sign-in first; another customer → «not valid»; the order's customer → the document.
    $this->get("/portal/sign/{$m[1]}")->assertRedirect('/portal/login');
    actingAsCustomer($this->f->b);
    $this->get("/portal/sign/{$m[1]}")->assertNotFound()->assertInertia(fn (AssertableInertia $p) => $p->component('portal/sign-link')->where('state', 'invalid'));
    Auth::forgetGuards();
    actingAsCustomer($this->f->a);
    $this->get("/portal/sign/{$m[1]}")->assertRedirect("/portal/documents/{$this->quote->id}?sign=1");
    $this->post("/portal/documents/{$this->quote->id}/sign", esignForm($this->quote))->assertSessionHasNoErrors();

    expect(DocumentSignature::query()->sole()->sign_link_id)->toBe($link->id)
        ->and($link->refresh()->used_at)->not->toBeNull();
    // A used link leads to the signed document, never to a second signature.
    $this->get("/portal/sign/{$m[1]}")->assertRedirect("/portal/documents/{$this->quote->id}");
});

it('answers an expired or revoked signing link with an expiry page', function () {
    $this->actingAs($this->f->sales)->postJson("/agreement/documents/{$this->quote->id}/sign-link")->assertOk();
    $link = DocumentSignLink::query()->sole();
    $token = (string) preg_replace('#.*/portal/sign/([A-Za-z0-9]{48}).*#s', '$1',
        (string) json_encode(OutboxMessage::query()->where('idempotency_key', 'like', "esign-link:{$link->id}:%")->firstOrFail()->payload, JSON_UNESCAPED_SLASHES));
    Auth::forgetGuards();
    actingAsCustomer($this->f->a);

    $this->travel(SignLinks::LINK_DAYS + 1)->days();
    $this->get("/portal/sign/{$token}")->assertStatus(410)->assertInertia(fn (AssertableInertia $p) => $p->component('portal/sign-link')->where('state', 'expired'));
    $this->travelBack();

    $this->actingAs($this->f->sales)->postJson("/agreement/documents/{$this->quote->id}/sign-link/revoke")->assertOk();
    expect($link->refresh()->revoked_at)->not->toBeNull();
    $this->get("/portal/sign/{$token}")->assertStatus(410);
    $this->get('/portal/sign/'.str_repeat('x', 48))->assertNotFound();
});

it('accepts the fixed demo code for the step-up when enabled, auditing its use without the value', function () {
    config(['portal.demo_otp' => '424242']);
    actingAsCustomer($this->f->a);
    $form = esignForm($this->quote, ['code' => '424242']);

    expect($form['code'])->not->toBe(lastOtpCode());
    $this->post("/portal/documents/{$this->quote->id}/sign", $form)->assertSessionHasNoErrors();

    $audit = AuditEvent::query()->where('action', 'agreement_document.signed')->latest('id')->firstOrFail();
    expect($this->quote->refresh()->status)->toBe(DocumentStatus::Approved)
        ->and($audit->after['demo_code'] ?? null)->toBeTrue()
        ->and(json_encode($audit->after))->not->toContain('424242');
});

it('refuses the demo code for the step-up when it is not configured or the environment is production', function () {
    actingAsCustomer($this->f->a);
    $form = esignForm($this->quote, ['code' => '424242']);
    $url = "/portal/documents/{$this->quote->id}/sign";

    config(['portal.demo_otp' => '']);
    $this->from($url)->post($url, $form)->assertSessionHasErrors('code');

    // Same value, but the current environment (testing) is not on the allow-list, as in production.
    config(['portal.demo_otp' => '424242', 'portal.demo_otp_environments' => ['local', 'demo']]);
    $this->from($url)->post($url, $form)->assertSessionHasErrors('code');
    expect(DocumentSignature::query()->count())->toBe(0);
});
