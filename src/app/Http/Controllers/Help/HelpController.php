<?php

namespace App\Http\Controllers\Help;

use App\Access\PermissionCatalogue;
use App\Ai\Help\HelpAssistant;
use App\Help\HelpCategory;
use App\Help\HelpImages;
use App\Help\HelpPresenter;
use App\Help\HelpReader;
use App\Help\SupportContact;
use App\Http\Controllers\Controller;
use App\Models\HelpArticle;
use App\Models\User;
use App\Models\WhatsNewEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * HelpCenter, category list, HelpArticle, «My guide», the contextual drawer and WhatsNew (SHR-07, ADR-0048).
 * Help is open to every signed-in staff member (P-83), but each article is filtered here by HelpReader: staff
 * audience, the reader's permissions, and the license capability (locked articles for the owner and managers only).
 */
class HelpController extends Controller
{
    public function index(Request $request, HelpAssistant $assistant): Response
    {
        $reader = $this->reader($request);
        $articles = $reader->query()->where('category', '!=', HelpCategory::RoleGuide->value)->orderBy('sort')->orderBy('id')->get();

        return Inertia::render('help/index', [
            'categories' => collect(HelpCategory::cases())->reject(fn (HelpCategory $c) => $c === HelpCategory::RoleGuide)->map(fn (HelpCategory $c) => [
                'key' => $c->value,
                'articles' => $articles->where('category', $c)->map(fn (HelpArticle $a) => HelpPresenter::summary($a, $reader))->values()->all(),
            ])->filter(fn (array $c) => $c['articles'] !== [])->values()->all(),
            'latest' => $this->latestRelease(),
            'supportContact' => SupportContact::channels(),
            'startHere' => HelpPresenter::startHere($this->user($request)),
            'hasGuide' => $reader->roles() !== [],
            'assistant' => $assistant->available(),
        ]);
    }

    public function category(Request $request, HelpCategory $category): Response
    {
        $reader = $this->reader($request);
        $articles = $reader->query()->where('category', $category->value)->orderBy('sort')->orderBy('id')->get();

        return Inertia::render('help/category', [
            'category' => $category->value,
            'articles' => $articles->map(fn (HelpArticle $a) => HelpPresenter::summary($a, $reader))->values()->all(),
        ]);
    }

    public function article(Request $request, HelpArticle $article): Response
    {
        $reader = $this->reader($request);
        // Not readable (draft, other audience, missing permission, unlicensed for this role): same 404 as missing.
        abort_unless($reader->canRead($article), 404);

        $related = $reader->query()->where('category', $article->category->value)
            ->whereKeyNot($article->id)->orderBy('sort')->orderBy('id')->limit(4)->get();

        return Inertia::render('help/article', [
            'article' => HelpPresenter::full($article, $reader),
            'related' => $related->map(fn (HelpArticle $a) => HelpPresenter::summary($a, $reader))->values()->all(),
        ]);
    }

    /**
     * «My guide»: the role guides of the reader's roles, then every article written for those roles, in order.
     */
    public function guide(Request $request): Response
    {
        $reader = $this->reader($request);
        $articles = $reader->query()->orderBy('sort')->orderBy('id')->get()
            ->filter(fn (HelpArticle $a) => $reader->isForMyRoles($a));
        [$guides, $others] = $articles->partition(fn (HelpArticle $a) => $a->category === HelpCategory::RoleGuide);

        return Inertia::render('help/guide', [
            'roles' => array_values(array_map(
                fn (string $role) => app()->getLocale() === 'en' ? PermissionCatalogue::ROLES[$role]['en'] : PermissionCatalogue::ROLES[$role]['ar'],
                array_filter($reader->roles(), fn (string $role) => isset(PermissionCatalogue::ROLES[$role])),
            )),
            'guides' => $guides->map(fn (HelpArticle $a) => HelpPresenter::full($a, $reader))->values()->all(),
            'articles' => $others->map(fn (HelpArticle $a) => [...HelpPresenter::summary($a, $reader), 'category' => $a->category->value])->values()->all(),
        ]);
    }

    /**
     * The contextual «?» without JavaScript: opens the article that explains the page the user is on (matched by
     * route name), else the help center.
     */
    public function context(Request $request): RedirectResponse
    {
        $article = HelpPresenter::forPage($this->reader($request), HelpPresenter::routeNameFor((string) $request->query('path', '/')));

        return $article === null
            ? redirect()->route('help.index')
            : redirect()->route('help.article', $article);
    }

    /**
     * The contextual «?» drawer: the article for the current page, rendered, or null.
     */
    public function panel(Request $request): JsonResponse
    {
        $reader = $this->reader($request);
        $article = HelpPresenter::forPage($reader, HelpPresenter::routeNameFor((string) $request->query('path', '/')));

        return response()->json([
            'article' => $article === null ? null : [
                ...HelpPresenter::full($article, $reader),
                'url' => route('help.article', $article, false),
            ],
        ]);
    }

    public function image(Request $request, string $file): BinaryFileResponse
    {
        return HelpImages::response($this->reader($request), $file);
    }

    /**
     * Closes the «Start here» checklist for this user, on every device.
     */
    public function dismissStartHere(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $user->forceFill(['help_start_dismissed_at' => now()])->save();

        return back();
    }

    public function whatsNew(): Response
    {
        return Inertia::render('help/whats-new', [
            'entries' => WhatsNewEntry::query()->published()->orderByDesc('released_on')->orderByDesc('id')->get()
                ->map(fn (WhatsNewEntry $e) => $this->release($e))->values()->all(),
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function reader(Request $request): HelpReader
    {
        return HelpReader::forStaff($this->user($request));
    }

    /**
     * @return array{version: string, released_on: string, title: string, body: string}|null
     */
    private function latestRelease(): ?array
    {
        $entry = WhatsNewEntry::query()->published()->orderByDesc('released_on')->orderByDesc('id')->first();

        return $entry === null ? null : $this->release($entry);
    }

    /**
     * @return array{version: string, released_on: string, title: string, body: string}
     */
    private function release(WhatsNewEntry $entry): array
    {
        return [
            'version' => $entry->version,
            'released_on' => $entry->released_on->toDateString(),
            'title' => $entry->title(),
            'body' => $entry->body(),
        ];
    }
}
