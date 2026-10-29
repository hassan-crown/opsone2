<?php

/**
 * Integrated acceptance «صلاحيات ولغتان» (SRS «قبول النظام المتكامل»): refuse links, files and actions out of scope,
 * and use Arabic and English on admin, customer and knowledge.
 *
 * Requirements: SRS-ORG-03 (one grant per role and scope; membership alone grants nothing), SRS-ORG-10 (language
 * preference), SRS-SHR-05 (a link never grants access), SRS-SHR-07 (bilingual in-app help), SRS-SHR-09 (no
 * snippet or count reveals a hidden record), SRS-SHR-10 (private files, unauthorized access refused),
 * SRS-WEB-01 (Arabic and English), SRS-WEB-08 (customer account never reveals another identity or order),
 * SRS-WEB-10 (customer file links re-check access).
 *
 * Fixture: the customer workspace fixture (two customers of branch A, each with an order, quote, design, transfer
 * proof with its private file, receipt and appointment). Every action under test goes through HTTP as its actor:
 * staff with `actingAs`, the customer through the real OTP sign-in or the customer guard (PortalTestHelpers).
 */

use App\Files\AttachmentService;
use App\Models\Attachment;
use App\Models\Finance\PaymentProof;
use App\Models\HelpArticle;
use App\Portal\PortalFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Portal/PortalTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.locale' => 'ar', 'app.fallback_locale' => 'en']);
    $this->f = portalFixture();
    // Staff of branch C only: the same roles as branch A's staff, another scope (ORG-03).
    $this->outManager = $this->f->org->member('manager', [$this->f->org->c]);
    $this->outFinance = $this->f->org->member('finance', [$this->f->org->c]);
});

describe('staff out of scope', function () {
    it('refuses order pages and order actions of another branch, with no effect', function () {
        $order = $this->f->orderA;
        $before = DB::table('orders')->where('id', $order->id)->first(['status', 'branch_id', 'lock_version', 'need_summary']);
        $lines = DB::table('order_lines')->where('order_id', $order->id)->count();

        // In scope (control): the branch A manager opens it.
        $this->actingAs($this->f->manager)->getJson("/orders/{$order->id}")->assertOk();

        $this->actingAs($this->outManager);
        accI09AssertRefused($this->getJson("/orders/{$order->id}"));
        accI09AssertRefused($this->getJson("/orders/{$order->id}/agreement"));
        accI09AssertRefused($this->getJson("/orders/{$order->id}/finance"));
        accI09AssertRefused($this->putJson("/orders/{$order->id}", ['lock_version' => $before->lock_version, 'need_summary' => 'out of scope']));
        accI09AssertRefused($this->postJson("/orders/{$order->id}/lines", ['lock_version' => $before->lock_version, 'type' => 'custom_need', 'description' => 'out of scope']));
        accI09AssertRefused($this->postJson("/orders/{$order->id}/branch-transfer", ['branch_id' => $this->f->org->c->id, 'reason' => 'محاولة نقل', 'assignee_id' => $this->outManager->id, 'lock_version' => $before->lock_version]));

        // The list never contains it either (SHR-09).
        $listed = collect($this->getJson('/orders')->json('orders') ?? [])->pluck('id')->all();
        expect($listed)->not->toContain($order->id)
            ->and(DB::table('orders')->where('id', $order->id)->first(['status', 'branch_id', 'lock_version', 'need_summary']))->toEqual($before)
            ->and(DB::table('order_lines')->where('order_id', $order->id)->count())->toBe($lines);
    });

    it('refuses money actions on another branch\'s records, with no effect', function () {
        $proof = $this->f->recA->proof;
        $before = DB::table('payment_proofs')->where('id', $proof->id)->first(['status', 'lock_version']);
        $payments = DB::table('payments')->where('order_id', $this->f->orderA->id)->count();

        $this->actingAs($this->outFinance);
        accI09AssertRefused($this->postJson("/finance/proofs/{$proof->id}/confirm", [
            'idempotency_key' => 'acc-i09-proof-1', 'lock_version' => $before->lock_version, 'amount' => '1000', 'paid_on' => now()->toDateString(),
        ]));
        accI09AssertRefused($this->postJson("/finance/proofs/{$proof->id}/correction", ['reason' => 'الصورة غير واضحة', 'lock_version' => $before->lock_version]));
        accI09AssertRefused($this->postJson("/orders/{$this->f->orderA->id}/finance/payments", [
            'amount' => '100.00', 'method' => 'cash', 'paid_on' => now()->toDateString(), 'idempotency_key' => 'acc-i09-pay-1',
            'lock_version' => finVersion($this->f->orderA), 'acknowledge_similar' => true,
            'evidence' => UploadedFile::fake()->createWithContent('t.pdf', "%PDF-1.4\nacc-i09"),
        ]));

        expect(DB::table('payment_proofs')->where('id', $proof->id)->first(['status', 'lock_version']))->toEqual($before)
            ->and(DB::table('payments')->where('order_id', $this->f->orderA->id)->count())->toBe($payments);
    });

    it('serves a private file only on a link signed for the user and only while the user may see its record', function () {
        $file = accI09ProofFile($this->f->recA->proof);
        $files = app(AttachmentService::class);
        $audited = fn () => DB::table('audit_events')->where('action', 'attachment.downloaded')->count();

        // In scope: the owner's own link downloads and is audited.
        $this->actingAs($this->f->owner)->get($files->downloadUrl($file, $this->f->owner))->assertOk();
        expect($audited())->toBe(1);

        // A link issued for someone else is refused (403); a link issued for an out-of-scope user is not found (404).
        $this->actingAs($this->outFinance)->get($files->downloadUrl($file, $this->f->owner))->assertForbidden();
        $this->actingAs($this->outFinance)->get($files->downloadUrl($file, $this->outFinance))->assertNotFound();
        // A tampered (unsigned) link is refused.
        $this->actingAs($this->f->owner)->get('/files/'.$file->uuid.'?u='.$this->f->owner->id)->assertForbidden();
        // A role without the money permission (P-37) in the same branch cannot open a transfer proof file.
        $tech = $this->f->tech;
        $this->actingAs($tech)->get($files->downloadUrl($file, $tech))->assertNotFound();

        expect($audited())->toBe(1);
    });

    it('keeps staff out of the customer workspace', function () {
        $this->actingAs($this->f->owner)->get('/portal')->assertRedirect('/portal/login');
        $this->actingAs($this->f->owner)->get("/portal/orders/{$this->f->orderA->id}")->assertRedirect('/portal/login');
    });
});

describe('customer out of scope', function () {
    it('answers 404 for another customer\'s order, document and file, and cannot reach staff routes', function () {
        Queue::fake(); // keep the sign-in code readable in the outbox (delivery clears the payload)
        portalSignIn($this->f->a);
        $b = $this->f->recB;
        $designBefore = DB::table('agreement_documents')->where('id', $b->design->id)->first(['status', 'lock_version']);
        $decisionsBefore = DB::table('customer_decisions')->where('order_id', $b->order->id)->count();
        $cancellationsBefore = DB::table('order_cancellations')->where('order_id', $b->order->id)->count();

        // Own records open (control).
        $this->get("/portal/orders/{$this->f->orderA->id}")->assertOk();
        $this->get(PortalFiles::url(accI09ProofFile($this->f->recA->proof), $this->f->a))->assertOk();

        $this->get("/portal/orders/{$b->order->id}")->assertNotFound();
        $this->get("/portal/documents/{$b->design->id}")->assertNotFound();
        $this->post("/portal/documents/{$b->design->id}/approve", ['content_hash' => $b->design->content_hash])->assertNotFound();
        $this->post("/portal/orders/{$b->order->id}/cancellation", ['reason' => 'محاولة'])->assertNotFound();
        $foreignFile = accI09ProofFile($b->proof);
        $this->get(PortalFiles::url($foreignFile, $this->f->a))->assertNotFound();
        $this->get(PortalFiles::url($foreignFile, $this->f->b))->assertForbidden();

        // The customer session is not a staff session: staff pages, JSON and staff file links are refused.
        $this->get('/dashboard')->assertRedirect('/login');
        $this->getJson("/orders/{$this->f->orderA->id}")->assertUnauthorized();
        $this->postJson("/finance/proofs/{$this->f->recA->proof->id}/confirm", [])->assertUnauthorized();
        $this->get(app(AttachmentService::class)->downloadUrl(accI09ProofFile($this->f->recA->proof), $this->f->owner))->assertRedirect('/login');
        expect(Auth::guard('web')->check())->toBeFalse();

        expect(DB::table('agreement_documents')->where('id', $b->design->id)->first(['status', 'lock_version']))->toEqual($designBefore)
            ->and(DB::table('customer_decisions')->where('order_id', $b->order->id)->count())->toBe($decisionsBefore)
            ->and(DB::table('order_cancellations')->where('order_id', $b->order->id)->count())->toBe($cancellationsBefore);
    });
});

describe('two languages', function () {
    it('serves the admin in Arabic and English from the user preference', function () {
        $owner = $this->f->owner;
        $owner->forceFill(['locale' => 'en'])->save();

        $this->actingAs($owner)->get(route('dashboard'))->assertOk()
            ->assertSee('lang="en" dir="ltr"', false)
            ->assertInertia(fn (Assert $p) => $p->where('locale', 'en')->where('dir', 'ltr'));

        $this->put(route('locale.update'), ['locale' => 'ar'])->assertRedirect();
        expect($owner->refresh()->locale)->toBe('ar');
        $this->get(route('dashboard'))
            ->assertSee('lang="ar" dir="rtl"', false)
            ->assertInertia(fn (Assert $p) => $p->where('locale', 'ar')->where('dir', 'rtl'));

        // Admin pages translate their strings in both languages (same key set in both files).
        $en = json_decode((string) file_get_contents(lang_path('en.json')), true);
        $ar = json_decode((string) file_get_contents(lang_path('ar.json')), true);
        expect($en['nav.dashboard'])->toBe('Dashboard')
            ->and($ar['nav.dashboard'])->toBe('لوحة التحكم')
            ->and(array_keys($ar))->toEqualCanonicalizing(array_keys($en));
        $this->put(route('locale.update'), ['locale' => 'fr'])->assertSessionHasErrors('locale');
    });

    it('serves the customer workspace in Arabic and English from the customer preference, never the staff one', function () {
        $this->f->owner->forceFill(['locale' => 'en'])->save();
        $this->actingAs($this->f->owner);
        actingAsCustomer($this->f->a);

        $this->get('/portal')->assertOk()
            ->assertSee('lang="ar" dir="rtl"', false)
            ->assertInertia(fn (Assert $p) => $p->component('portal/home')->where('locale', 'ar')->where('dir', 'rtl'));

        $this->put('/portal/locale', ['locale' => 'en'])->assertRedirect();
        expect($this->f->a->refresh()->portal_locale)->toBe('en');
        $this->get('/portal')
            ->assertSee('lang="en" dir="ltr"', false)
            ->assertInertia(fn (Assert $p) => $p->where('locale', 'en')->where('dir', 'ltr'));
        $this->get("/portal/orders/{$this->f->orderA->id}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('portal/order')->where('locale', 'en'));

        // Signed out, the sign-in page follows the language cookie.
        $this->withUnencryptedCookie('locale', 'en');
        Auth::forgetGuards();
        $this->get('/portal/login')->assertOk()->assertSee('lang="en" dir="ltr"', false);
    });

    it('serves help and knowledge articles in Arabic and English, falling back per field', function () {
        HelpArticle::query()->create([
            'slug' => 'invite-staff', 'category' => 'faq',
            'title_ar' => 'دعوة الموظفين', 'title_en' => 'Inviting staff',
            'summary_ar' => 'ملخص الدعوة', 'summary_en' => null,
            'body_ar' => 'نص المقال بالعربية', 'body_en' => 'Article body in English',
            'published_at' => now()->subDay(),
        ]);
        $sales = $this->f->sales;

        $sales->forceFill(['locale' => 'ar'])->save();
        $this->actingAs($sales)->get(route('help.article', 'invite-staff'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('help/article')->where('locale', 'ar')->where('dir', 'rtl')
                ->where('article.title', 'دعوة الموظفين')->where('article.body', 'نص المقال بالعربية'));
        $this->get(route('help.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('help/index')->where('locale', 'ar'));

        $this->put(route('locale.update'), ['locale' => 'en']);
        $this->get(route('help.article', 'invite-staff'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('locale', 'en')->where('dir', 'ltr')
                ->where('article.title', 'Inviting staff')->where('article.summary', 'ملخص الدعوة')->where('article.body', 'Article body in English'));
        $this->get(route('help.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('help/index')->where('locale', 'en'));

        // Knowledge is for signed-in staff only: the customer session does not reach it.
        Auth::forgetGuards();
        actingAsCustomer($this->f->a);
        $this->get(route('help.article', 'invite-staff'))->assertRedirect('/login');
    });
});

/**
 * Out of scope means refused without revealing: 403 or 404, never a success.
 */
function accI09AssertRefused(TestResponse $response): void
{
    expect($response->status())->toBeIn([403, 404]);
}

function accI09ProofFile(PaymentProof $proof): Attachment
{
    $file = Attachment::query()->where('attachable_type', $proof->getMorphClass())->where('attachable_id', $proof->id)->sole();
    // The faked attachments disk lives at a fixed path that concurrent test processes in the same container wipe on
    // Storage::fake(); put the bytes back so the outcome depends on access only, not on another process.
    $disk = Storage::disk($file->disk);
    if (! $disk->exists($file->path)) {
        $disk->put($file->path, "%PDF-1.4\nacc-i09");
    }

    return $file;
}
