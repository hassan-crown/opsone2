<?php

namespace App\Actions\Site;

use App\Access\Authorizer;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\StateMachine;
use App\Models\Site\SitePage;
use App\Models\Site\SitePageLocale;
use App\Models\Site\SitePageVersion;
use App\Models\User;
use App\Site\Sections\SectionCatalogue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single write path of site pages (M-17, ADR-0021). Policy and state are enforced here, so the API is as
 * protected as the screen (WEB-04): system pages never change, only `content` pages archive or unpublish, a published
 * version is never edited (a change is a new draft) and an unpublished language is never exposed.
 */
final class SitePageWriter
{
    public const LOCALES = ['ar', 'en'];

    public function __construct(private readonly Authorizer $access, private readonly TransitionRecorder $transitions) {}

    /**
     * @param  array{ar?: string, en?: string}  $titles
     */
    public function create(User $actor, string $slug, array $titles, bool $showInNav = false): SitePage
    {
        $this->access->authorize($actor, Permission::P55);

        return DB::transaction(function () use ($actor, $slug, $titles, $showInNav) {
            $page = SitePage::query()->create([
                'slug' => $slug, 'policy' => SitePage::POLICY_CONTENT, 'show_in_nav' => $showInNav,
                'sort' => (int) SitePage::query()->max('sort') + 1, 'created_by' => $actor->id,
            ]);
            foreach (self::LOCALES as $locale) {
                $version = SitePageVersion::query()->create([
                    'page_id' => $page->id, 'locale' => $locale, 'number' => 1, 'title' => trim((string) ($titles[$locale] ?? '')),
                    'sections' => [], 'created_by' => $actor->id,
                ]);
                SitePageLocale::query()->create(['page_id' => $page->id, 'locale' => $locale, 'draft_version_id' => $version->id]);
            }
            $this->transitions->record(StateMachine::M17, 'T00', $page, null, 'draft', $actor, TransitionRecorder::SOURCE_WEB);
            Audit::record(AuditAction::SitePageCreated, $page, $actor, after: ['slug' => $slug]);

            return $page->load('locales');
        });
    }

    /**
     * Saves the drafts of one or both languages (M-17-T02 on a published page).
     *
     * @param  array<string, array{title?: string, meta_description?: string|null, sections?: mixed}>  $content
     */
    public function saveDraft(User $actor, SitePage $page, array $content, ?string $slug = null, ?bool $showInNav = null): SitePage
    {
        $this->access->authorize($actor, Permission::P55, $page);

        return DB::transaction(function () use ($actor, $page, $content, $slug, $showInNav) {
            $locked = $this->lock($page);
            $this->assertEditable($locked);
            $before = $locked->state();

            if ($slug !== null && $slug !== $locked->slug) {
                if ($locked->policy !== SitePage::POLICY_CONTENT) {
                    throw ValidationException::withMessages(['slug' => __('site.error.slug_locked')]);
                }
                if ($locked->locales->contains(fn (SitePageLocale $l) => $l->published_version_id !== null)) {
                    throw ValidationException::withMessages(['slug' => __('site.error.slug_published')]);
                }
                $locked->slug = $slug;
            }
            if ($showInNav !== null && $locked->policy === SitePage::POLICY_CONTENT) {
                $locked->show_in_nav = $showInNav;
            }
            $locked->save();

            foreach (self::LOCALES as $locale) {
                if (! isset($content[$locale])) {
                    continue;
                }
                $in = $content[$locale];
                $sections = SectionCatalogue::normalize($in['sections'] ?? [], "$locale.sections");
                if (! SectionCatalogue::mediaBelongsTo(SectionCatalogue::mediaIn($sections), $locked)) {
                    throw ValidationException::withMessages(["$locale.sections" => __('site.error.media')]);
                }
                $row = $locked->localeRow($locale) ?? SitePageLocale::query()->create(['page_id' => $locked->id, 'locale' => $locale]);
                $attrs = [
                    'title' => mb_substr(trim((string) ($in['title'] ?? '')), 0, 160),
                    'meta_description' => ($m = trim((string) ($in['meta_description'] ?? ''))) === '' ? null : mb_substr($m, 0, 320),
                    'sections' => $sections,
                ];
                $draft = $row->draft_version_id === null ? null : SitePageVersion::query()->find($row->draft_version_id);
                if ($draft === null) {
                    $number = (int) SitePageVersion::query()->where('page_id', $locked->id)->where('locale', $locale)->max('number') + 1;
                    $draft = SitePageVersion::query()->create($attrs + ['page_id' => $locked->id, 'locale' => $locale, 'number' => $number, 'created_by' => $actor->id]);
                    $row->forceFill(['draft_version_id' => $draft->id])->save();
                } else {
                    $draft->forceFill($attrs)->save();
                }
            }

            $locked->load('locales');
            if ($before === 'published' && $locked->state() === 'published_with_draft') {
                $this->transitions->record(StateMachine::M17, 'T02', $locked, 'published', 'published_with_draft', $actor, TransitionRecorder::SOURCE_WEB);
            }
            Audit::record(AuditAction::SitePageDraftSaved, $locked, $actor, after: ['slug' => $locked->slug, 'locales' => array_keys($content)]);

            return $locked;
        });
    }

    /**
     * What publishing would do per language: complete → publishable, otherwise the issues (shown before publishing).
     *
     * @return array<string, array{has_draft: bool, complete: bool, issues: list<array{section: int|null, code: string, field: string|null}>}>
     */
    public function readiness(SitePage $page): array
    {
        $page->loadMissing('locales');
        $out = [];
        foreach (self::LOCALES as $locale) {
            $row = $page->localeRow($locale);
            $draft = $row?->draft_version_id === null ? null : SitePageVersion::query()->find($row->draft_version_id);
            $issues = [];
            if ($draft !== null) {
                if (trim($draft->title) === '') {
                    $issues[] = ['section' => null, 'code' => 'title_required', 'field' => 'title'];
                }
                $issues = array_merge($issues, SectionCatalogue::incomplete($draft->sections));
            }
            $out[$locale] = ['has_draft' => $draft !== null, 'complete' => $draft !== null && $issues === [], 'issues' => $issues];
        }

        return $out;
    }

    /**
     * Publishes the chosen languages (M-17-T01 / T03). Incomplete ones block the call unless `$onlyComplete`, which
     * publishes the complete ones and leaves the rest as they were (the last published copy stays live).
     *
     * @param  list<string>  $locales
     * @return list<string> languages published
     */
    public function publish(User $actor, SitePage $page, array $locales, bool $onlyComplete = false): array
    {
        $this->access->authorize($actor, Permission::P56, $page);

        return DB::transaction(function () use ($actor, $page, $locales, $onlyComplete) {
            $locked = $this->lock($page);
            $this->assertEditable($locked);
            $readiness = $this->readiness($locked);
            $locales = array_values(array_intersect(self::LOCALES, $locales));

            $ready = array_values(array_filter($locales, fn ($l) => $readiness[$l]['complete']));
            $blocked = array_values(array_diff($locales, $ready));
            if ($ready === [] || ($blocked !== [] && ! $onlyComplete)) {
                $errors = [];
                foreach ($blocked as $l) {
                    $errors["locales.$l"] = $readiness[$l]['has_draft'] ? __('site.error.incomplete', ['locale' => $l]) : __('site.error.nothing_to_publish', ['locale' => $l]);
                }
                throw ValidationException::withMessages($errors ?: ['locales' => __('site.error.nothing_to_publish', ['locale' => implode(',', $locales)])]);
            }

            $before = $locked->state();
            foreach ($ready as $locale) {
                $row = $locked->localeRow($locale);
                $draft = SitePageVersion::query()->findOrFail($row->draft_version_id);
                $draft->forceFill(['published_at' => now(), 'published_by' => $actor->id])->save();
                $row->forceFill(['published_version_id' => $draft->id, 'draft_version_id' => null])->save();
            }
            $locked->load('locales');
            $to = $locked->state();
            $this->transitions->record(StateMachine::M17, $before === 'draft' ? 'T01' : 'T03', $locked, $before, $to, $actor, TransitionRecorder::SOURCE_WEB, context: ['locales' => $ready]);
            Audit::record(AuditAction::SitePagePublished, $locked, $actor, after: ['slug' => $locked->slug, 'locales' => $ready]);

            return $ready;
        });
    }

    /**
     * M-17-T04: only content pages; the published copy stays as a draft to edit.
     */
    public function unpublish(User $actor, SitePage $page): SitePage
    {
        $this->access->authorize($actor, Permission::P56, $page);

        return DB::transaction(function () use ($actor, $page) {
            $locked = $this->lock($page);
            if ($locked->policy !== SitePage::POLICY_CONTENT || $locked->isArchived()) {
                throw new InvalidTransition(StateMachine::M17, 'T04', 'policy');
            }
            $before = $locked->state();
            $this->backToDraft($actor, $locked);
            $locked->load('locales');
            $this->transitions->record(StateMachine::M17, 'T04', $locked, $before, 'draft', $actor, TransitionRecorder::SOURCE_WEB);
            Audit::record(AuditAction::SitePageUnpublished, $locked, $actor, after: ['slug' => $locked->slug]);

            return $locked;
        });
    }

    /**
     * M-17-T07: drop the unpublished changes of a published page; the published copy stays as it is.
     */
    public function discardDraft(User $actor, SitePage $page): SitePage
    {
        $this->access->authorize($actor, Permission::P55, $page);

        return DB::transaction(function () use ($actor, $page) {
            $locked = $this->lock($page);
            $this->assertEditable($locked);
            $dropped = [];
            foreach ($locked->locales as $row) {
                if ($row->draft_version_id !== null && $row->published_version_id !== null) {
                    SitePageVersion::query()->whereKey($row->draft_version_id)->delete();
                    $row->forceFill(['draft_version_id' => null])->save();
                    $dropped[] = $row->locale;
                }
            }
            if ($dropped === []) {
                throw new InvalidTransition(StateMachine::M17, 'T07', 'no_unpublished_changes');
            }
            $locked->load('locales');
            $this->transitions->record(StateMachine::M17, 'T07', $locked, 'published_with_draft', $locked->state(), $actor, TransitionRecorder::SOURCE_WEB);
            Audit::record(AuditAction::SitePageDraftDiscarded, $locked, $actor, after: ['slug' => $locked->slug, 'locales' => $dropped]);

            return $locked;
        });
    }

    /**
     * M-17-T05: a content page only. Nothing is deleted; the public link redirects to the home page.
     */
    public function archive(User $actor, SitePage $page): SitePage
    {
        $this->access->authorize($actor, Permission::P56, $page);

        return DB::transaction(function () use ($actor, $page) {
            $locked = $this->lock($page);
            if (! $locked->canArchive()) {
                throw new InvalidTransition(StateMachine::M17, 'T05', $locked->isArchived() ? 'already_archived' : 'policy');
            }
            $before = $locked->state();
            $locked->forceFill(['archived_at' => now(), 'archived_by' => $actor->id])->save();
            $this->transitions->record(StateMachine::M17, 'T05', $locked, $before, 'archived', $actor, TransitionRecorder::SOURCE_WEB);
            Audit::record(AuditAction::SitePageArchived, $locked, $actor, after: ['slug' => $locked->slug]);

            return $locked;
        });
    }

    /**
     * M-17-T06: back as a draft; going public again needs an explicit publish.
     */
    public function restore(User $actor, SitePage $page): SitePage
    {
        $this->access->authorize($actor, Permission::P56, $page);

        return DB::transaction(function () use ($actor, $page) {
            $locked = $this->lock($page);
            if (! $locked->isArchived()) {
                throw new InvalidTransition(StateMachine::M17, 'T06', 'not_archived');
            }
            if (SitePage::query()->where('slug', $locked->slug)->whereKeyNot($locked->id)->whereNull('archived_at')->exists()) {
                throw ValidationException::withMessages(['slug' => __('site.error.slug_taken')]);
            }
            $locked->forceFill(['archived_at' => null, 'archived_by' => null])->save();
            $locked->load('locales');
            $this->backToDraft($actor, $locked);
            $locked->load('locales');
            $this->transitions->record(StateMachine::M17, 'T06', $locked, 'archived', 'draft', $actor, TransitionRecorder::SOURCE_WEB);
            Audit::record(AuditAction::SitePageRestored, $locked, $actor, after: ['slug' => $locked->slug]);

            return $locked;
        });
    }

    /**
     * Takes every language off the public site, keeping its content as an editable draft.
     */
    private function backToDraft(User $actor, SitePage $page): void
    {
        foreach ($page->locales as $row) {
            if ($row->published_version_id === null) {
                continue;
            }
            if ($row->draft_version_id === null) {
                $published = SitePageVersion::query()->findOrFail($row->published_version_id);
                $number = (int) SitePageVersion::query()->where('page_id', $page->id)->where('locale', $row->locale)->max('number') + 1;
                $draft = SitePageVersion::query()->create([
                    'page_id' => $page->id, 'locale' => $row->locale, 'number' => $number, 'title' => $published->title,
                    'meta_description' => $published->meta_description, 'sections' => $published->sections, 'created_by' => $actor->id,
                ]);
                $row->draft_version_id = $draft->id;
            }
            $row->published_version_id = null;
            $row->save();
        }
    }

    private function lock(SitePage $page): SitePage
    {
        $locked = SitePage::query()->lockForUpdate()->findOrFail($page->id);
        $locked->load('locales');

        return $locked;
    }

    private function assertEditable(SitePage $page): void
    {
        if ($page->isSystem()) {
            throw ValidationException::withMessages(['page' => __('site.error.system_page')]);
        }
        if ($page->isArchived()) {
            throw ValidationException::withMessages(['page' => __('site.error.archived_page')]);
        }
    }
}
