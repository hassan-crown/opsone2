<?php

/**
 * SHR-10 / NFR-14 / ADR-0006: private attachments — allowlisted types checked by content, the license storage
 * quota, and short-lived signed links that re-check access on the owning record at download time.
 */

use App\Access\Exceptions\LicenseLimitReached;
use App\Audit\AuditAction;
use App\Files\AttachmentService;
use App\Models\Attachment;
use App\Models\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FileOwnerRecord;
use Tests\Support\LicenseFactory;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    FileOwnerRecord::createTable();
    $this->org = Org::make();
    $this->manager = $this->org->member('manager', [$this->org->a]);
    $this->recordA = FileOwnerRecord::query()->create(['branch_id' => $this->org->a->id]);
    $this->recordC = FileOwnerRecord::query()->create(['branch_id' => $this->org->c->id]);
    $this->files = app(AttachmentService::class);
});

function pdf(string $name = 'quote.pdf', int $kb = 4): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n".str_repeat('0', $kb * 1024));
}

describe('upload', function () {
    it('stores an allowed file privately, under a random name, and audits it', function () {
        $attachment = $this->files->store($this->manager, $this->recordA, pdf('عرض سعر.pdf'));

        expect($attachment->original_name)->toBe('عرض سعر.pdf')
            ->and($attachment->mime)->toBe('application/pdf')
            ->and($attachment->path)->not->toContain('عرض')
            ->and($attachment->toArray())->not->toHaveKeys(['path', 'disk']);
        Storage::disk('attachments')->assertExists($attachment->path);

        $audit = AuditEvent::query()->where('action', AuditAction::AttachmentUploaded->value)->firstOrFail();
        expect($audit->after['attachment'])->toBe($attachment->uuid)
            ->and($audit->accessBranchIds())->toBe([$this->org->a->id]);
    });

    it('refuses to attach to a record outside the uploader scope', function () {
        expect(fn () => $this->files->store($this->manager, $this->recordC, pdf()))->toThrow(AuthorizationException::class);
        expect(Attachment::query()->count())->toBe(0);
    });

    it('refuses disallowed types and content that does not match the extension', function (string $name, string $content) {
        expect(fn () => $this->files->store($this->manager, $this->recordA, UploadedFile::fake()->createWithContent($name, $content)))
            ->toThrow(ValidationException::class);

        expect(Attachment::query()->count())->toBe(0)
            ->and(Storage::disk('attachments')->allFiles())->toBe([]);
    })->with([
        'php script' => ['shell.php', '<?php system($_GET["c"]);'],
        'svg' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
        'html' => ['page.html', '<html><script>alert(1)</script></html>'],
        'executable renamed to pdf' => ['invoice.pdf', "MZ\x90\x00".str_repeat("\x00", 64)],
        'script renamed to png' => ['photo.png', '<?php echo 1;'],
        'no extension' => ['README', '%PDF-1.4 hello'],
    ]);

    it('explains a refused type in the user language', function () {
        foreach (['ar' => 'نوع الملف غير مسموح', 'en' => 'This file type is not allowed'] as $locale => $text) {
            app()->setLocale($locale);
            try {
                $this->files->store($this->manager, $this->recordA, UploadedFile::fake()->createWithContent('x.exe', 'MZ'));
                $this->fail('expected a validation error');
            } catch (ValidationException $e) {
                expect($e->errors()['file'][0])->toContain($text);
            }
        }
    });
});

describe('storage quota', function () {
    beforeEach(function () {
        LicenseFactory::install(['limits' => ['seats' => 15, 'branches' => 3, 'storage_mb' => 1]]);
        $this->files = app(AttachmentService::class);
    });

    it('refuses a file that would exceed the plan storage, with a bilingual message, leaving nothing behind', function () {
        $this->files->store($this->manager, $this->recordA, pdf('one.pdf', 700));

        foreach (['ar' => 'امتلأت سعة تخزين الملفات', 'en' => 'storage is full'] as $locale => $text) {
            app()->setLocale($locale);
            try {
                $this->files->store($this->manager, $this->recordA, pdf('two.pdf', 700));
                $this->fail('expected the storage limit');
            } catch (LicenseLimitReached $e) {
                expect($e->resource)->toBe('storage')
                    ->and($e->limit)->toBe(1)
                    ->and($e->userMessage())->toContain($text);
            }
        }

        expect(Attachment::query()->count())->toBe(1)
            ->and(Storage::disk('attachments')->allFiles())->toHaveCount(1);
    });

    it('reports measured storage on the plan page', function () {
        $this->files->store($this->manager, $this->recordA, pdf('one.pdf', 700));

        $this->actingAs($this->org->owner)->get(route('plan.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('usage.storage.measured', true)
                ->where('usage.storage.used_mb', 1)
                ->where('usage.storage.limit', 1));
    });
});

describe('signed download', function () {
    beforeEach(function () {
        $this->attachment = $this->files->store($this->org->owner, $this->recordA, pdf());
    });

    it('downloads with a valid link as a private, non-sniffable attachment', function () {
        $response = $this->actingAs($this->manager)->get($this->files->downloadUrl($this->attachment, $this->manager));

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Type', 'application/pdf');
        expect($response->headers->get('Content-Disposition'))->toStartWith('attachment')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
        expect(AuditEvent::query()->where('action', 'attachment.downloaded')->where('actor_user_id', $this->manager->id)->exists())->toBeTrue();
    });

    it('refuses a link for a record outside the user scope', function () {
        $foreign = $this->files->store($this->org->owner, $this->recordC, pdf());

        $this->actingAs($this->manager)->get($this->files->downloadUrl($foreign, $this->manager))->assertNotFound();
    });

    it('re-checks access at download time, after the link was issued', function () {
        $url = $this->files->downloadUrl($this->attachment, $this->manager);
        $this->recordA->forceFill(['branch_id' => $this->org->c->id])->save();

        $this->actingAs($this->manager)->get($url)->assertNotFound();
    });

    it('refuses an expired link', function () {
        $url = $this->files->downloadUrl($this->attachment, $this->manager);
        $this->travel(6)->minutes();

        $this->actingAs($this->manager)->get($url)->assertForbidden();
    });

    it('refuses a tampered link and a link issued to someone else', function () {
        $url = $this->files->downloadUrl($this->attachment, $this->manager);
        $this->actingAs($this->manager)->get(str_replace('u='.$this->manager->id, 'u='.$this->org->owner->id, $url))->assertForbidden();

        $ownersLink = $this->files->downloadUrl($this->attachment, $this->org->owner);
        $this->actingAs($this->manager)->get($ownersLink)->assertForbidden();
    });

    it('requires a signed-in user and has no unsigned route', function () {
        $this->get($this->files->downloadUrl($this->attachment, $this->manager))->assertRedirect('/login');
        $this->actingAs($this->manager)->get('/files/'.$this->attachment->uuid)->assertForbidden();
    });
});
