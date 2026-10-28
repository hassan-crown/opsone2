<?php

namespace App\Http\Controllers\Site;

use App\Access\Permission;
use App\Actions\Site\SitePageWriter;
use App\Files\AttachmentService;
use App\Http\Requests\Site\ImageRequest;
use App\Http\Requests\Site\PageRequest;
use App\Http\Requests\Site\PublishRequest;
use App\Models\Site\SitePage;
use App\Models\Site\SitePageVersion;
use App\Site\Sections\SectionCatalogue;
use App\Site\SiteContext;
use App\Site\SiteRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SitePages and PageEditor (WEB-03/04, M-17). Thin: the writer owns policy, state and authorization.
 */
final class SitePageController extends SiteAdminController
{
    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        abort_unless($this->tabs($actor)['pages'], 403);

        $pages = SitePage::query()->with('locales')->orderBy('sort')->orderBy('id')->get();
        $versions = SitePageVersion::query()->whereIn('id', $pages->flatMap(fn ($p) => $p->locales->flatMap(fn ($l) => [$l->published_version_id, $l->draft_version_id]))->filter()->all())->get()->keyBy('id');

        return Inertia::render('site/pages/index', [
            'pages' => $pages->map(fn (SitePage $p) => [
                'id' => $p->id, 'slug' => $p->slug, 'policy' => $p->policy, 'system_key' => $p->system_key, 'state' => $p->isSystem() ? 'system' : $p->state(),
                'title' => [
                    'ar' => $this->title($p, 'ar', $versions), 'en' => $this->title($p, 'en', $versions),
                ],
                'locales' => collect(SitePageWriter::LOCALES)->mapWithKeys(fn ($l) => [$l => [
                    'published' => $p->localeRow($l)?->published_version_id !== null, 'draft' => $p->localeRow($l)?->draft_version_id !== null,
                ]])->all(),
                'can_edit' => ! $p->isSystem() && ! $p->isArchived(),
                'can_archive' => $p->canArchive(), 'can_restore' => $p->isArchived(),
            ])->values(),
            'tabs' => $this->tabs($actor),
            'canCreate' => $this->access->can($actor, Permission::P55),
            'canPublish' => $this->access->can($actor, Permission::P56),
        ]);
    }

    public function store(PageRequest $request, SitePageWriter $writer): RedirectResponse
    {
        $page = $writer->create($this->actor($request), (string) $request->validated('slug'), (array) $request->validated('title', []), (bool) $request->validated('show_in_nav', false));
        $this->toast(__('site.toast.created'));

        return to_route('site.admin.pages.edit', $page);
    }

    public function edit(Request $request, SitePage $page, SitePageWriter $writer): Response
    {
        $actor = $this->actor($request);
        abort_unless($this->access->can($actor, Permission::P55, $page) || $this->access->can($actor, Permission::P56, $page), 403);
        $page->load('locales');

        $content = [];
        foreach (SitePageWriter::LOCALES as $locale) {
            $row = $page->localeRow($locale);
            $v = ($row->draft_version_id ?? $row?->published_version_id) === null ? null : SitePageVersion::query()->find($row->draft_version_id ?? $row->published_version_id);
            $content[$locale] = [
                'title' => $v->title ?? '', 'meta_description' => $v->meta_description ?? '', 'sections' => $v->sections ?? [],
                'has_draft' => $row?->draft_version_id !== null, 'published' => $row?->published_version_id !== null,
            ];
        }

        return Inertia::render('site/pages/edit', [
            'page' => [
                'id' => $page->id, 'slug' => $page->slug, 'policy' => $page->policy, 'state' => $page->isSystem() ? 'system' : $page->state(),
                'show_in_nav' => $page->show_in_nav, 'is_home' => $page->isHome(),
            ],
            'content' => $content,
            'readiness' => $writer->readiness($page),
            'images' => $page->attachments()->get()->map(fn ($a) => ['uuid' => $a->uuid, 'name' => $a->original_name, 'url' => route('site.admin.media', $a->uuid)])->values(),
            'sectionTypes' => SectionCatalogue::TYPES,
            'links' => SitePage::query()->whereNull('archived_at')->where('policy', '!=', SitePage::POLICY_SYSTEM)->orderBy('sort')->get(['slug', 'system_key'])
                ->map(fn ($p) => $p->system_key === 'home' ? 'home' : $p->slug)->values(),
            'can' => [
                'edit' => $this->access->can($actor, Permission::P55, $page) && ! $page->isSystem() && ! $page->isArchived(),
                'publish' => $this->access->can($actor, Permission::P56, $page) && ! $page->isSystem() && ! $page->isArchived(),
                'unpublish' => $page->policy === SitePage::POLICY_CONTENT && $this->access->can($actor, Permission::P56, $page) && ! $page->isArchived(),
                'archive' => $page->canArchive() && $this->access->can($actor, Permission::P56, $page),
                'restore' => $page->isArchived() && $this->access->can($actor, Permission::P56, $page),
            ],
            'tabs' => $this->tabs($actor),
        ]);
    }

    public function update(PageRequest $request, SitePage $page, SitePageWriter $writer): RedirectResponse
    {
        $writer->saveDraft(
            $this->actor($request), $page, (array) $request->input('content', []),
            $request->has('slug') ? (string) $request->validated('slug') : null,
            $request->has('show_in_nav') ? (bool) $request->validated('show_in_nav') : null,
        );
        $this->toast(__('site.toast.draft_saved'));

        return back();
    }

    public function publish(PublishRequest $request, SitePage $page, SitePageWriter $writer): RedirectResponse
    {
        $done = $writer->publish($this->actor($request), $page, $request->validated('locales'), (bool) $request->validated('only_complete', false));
        $this->toast(__('site.toast.published', ['locales' => implode(' + ', array_map(strtoupper(...), $done))]));

        return back();
    }

    public function unpublish(Request $request, SitePage $page, SitePageWriter $writer): RedirectResponse
    {
        $writer->unpublish($this->actor($request), $page);
        $this->toast(__('site.toast.unpublished'));

        return back();
    }

    public function discard(Request $request, SitePage $page, SitePageWriter $writer): RedirectResponse
    {
        $writer->discardDraft($this->actor($request), $page);
        $this->toast(__('site.toast.discarded'));

        return back();
    }

    public function archive(Request $request, SitePage $page, SitePageWriter $writer): RedirectResponse
    {
        $writer->archive($this->actor($request), $page);
        $this->toast(__('site.toast.archived'));

        return to_route('site.admin.pages.index');
    }

    public function restore(Request $request, SitePage $page, SitePageWriter $writer): RedirectResponse
    {
        $writer->restore($this->actor($request), $page);
        $this->toast(__('site.toast.restored'));

        return back();
    }

    public function image(ImageRequest $request, SitePage $page, AttachmentService $files): RedirectResponse
    {
        abort_if($page->isSystem() || $page->isArchived(), 403);
        $files->store($this->actor($request), $page, $request->file('file'), 'site_image');
        $this->toast(__('site.toast.image'));

        return back();
    }

    /** Draft preview (never public): noindex, the draft of the language (or its published copy). */
    public function preview(Request $request, SitePage $page, string $locale, SiteRenderer $renderer): View
    {
        $actor = $this->actor($request);
        abort_unless(in_array($locale, SiteContext::LOCALES, true), 404);
        $this->access->authorize($actor, Permission::P55, $page);
        $page->load('locales');
        $row = $page->localeRow($locale);
        $id = $row->draft_version_id ?? $row?->published_version_id;
        abort_if($id === null || $page->isSystem(), 404);
        app()->setLocale($locale);

        return $renderer->render($page, SitePageVersion::query()->findOrFail($id), $locale, preview: true);
    }

    /**
     * @param  Collection<int, SitePageVersion>  $versions
     */
    private function title(SitePage $p, string $locale, $versions): string
    {
        $row = $p->localeRow($locale);
        $v = $versions->get($row->draft_version_id ?? $row?->published_version_id);

        return $v?->title ?: ($p->isSystem() ? __('site.system_page.'.$p->system_key) : '');
    }
}
