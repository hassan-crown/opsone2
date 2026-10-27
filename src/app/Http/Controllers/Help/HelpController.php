<?php

namespace App\Http\Controllers\Help;

use App\Help\HelpCategory;
use App\Http\Controllers\Controller;
use App\Models\HelpArticle;
use App\Models\WhatsNewEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * HelpCenter, category list, HelpArticle and WhatsNew (SHR-07). Only published articles are served;
 * articles are public to every signed-in user (P-83).
 */
class HelpController extends Controller
{
    public function index(): Response
    {
        $articles = HelpArticle::query()->published()->orderBy('sort')->orderBy('id')->get();

        return Inertia::render('help/index', [
            'categories' => collect(HelpCategory::cases())->map(fn (HelpCategory $c) => [
                'key' => $c->value,
                'articles' => $articles->where('category', $c)->map(fn (HelpArticle $a) => $this->summary($a))->values()->all(),
            ])->filter(fn (array $c) => $c['articles'] !== [])->values()->all(),
            'latest' => $this->latestRelease(),
        ]);
    }

    public function category(HelpCategory $category): Response
    {
        $articles = HelpArticle::query()->published()->where('category', $category->value)->orderBy('sort')->orderBy('id')->get();

        return Inertia::render('help/category', [
            'category' => $category->value,
            'articles' => $articles->map(fn (HelpArticle $a) => $this->summary($a))->values()->all(),
        ]);
    }

    public function article(HelpArticle $article): Response
    {
        abort_unless($article->published_at !== null && $article->published_at->isPast(), 404);

        $related = HelpArticle::query()->published()->where('category', $article->category->value)
            ->whereKeyNot($article->id)->orderBy('sort')->limit(4)->get();

        return Inertia::render('help/article', [
            'article' => [
                ...$this->summary($article),
                'body' => $article->body(),
                'category' => $article->category->value,
                'updated_at' => $article->updated_at?->toIso8601String(),
            ],
            'related' => $related->map(fn (HelpArticle $a) => $this->summary($a))->values()->all(),
        ]);
    }

    /**
     * The contextual «?» in the shell: opens the article that explains the page the user is on
     * (matched by route name), else the help center. The path is only matched, never followed.
     */
    public function context(Request $request): RedirectResponse
    {
        $path = (string) $request->query('path', '/');
        $name = null;

        try {
            $name = Route::getRoutes()->match(Request::create('/'.ltrim(parse_url($path, PHP_URL_PATH) ?: '/', '/')))->getName();
        } catch (HttpExceptionInterface|UrlGenerationException) {
            // Unknown path: fall through to the help center.
        }

        $article = $name === null ? null : HelpArticle::query()->published()->where('page_key', $name)->first();

        return $article === null
            ? redirect()->route('help.index')
            : redirect()->route('help.article', $article);
    }

    public function whatsNew(): Response
    {
        return Inertia::render('help/whats-new', [
            'entries' => WhatsNewEntry::query()->published()->orderByDesc('released_on')->orderByDesc('id')->get()
                ->map(fn (WhatsNewEntry $e) => $this->release($e))->values()->all(),
        ]);
    }

    /**
     * @return array{slug: string, title: string, summary: string|null}
     */
    private function summary(HelpArticle $article): array
    {
        return ['slug' => $article->slug, 'title' => $article->title(), 'summary' => $article->summary()];
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
