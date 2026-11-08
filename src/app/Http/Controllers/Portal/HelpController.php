<?php

namespace App\Http\Controllers\Portal;

use App\Help\HelpCategory;
use App\Help\HelpImages;
use App\Help\HelpPresenter;
use App\Help\HelpReader;
use App\Models\HelpArticle;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Customer help in the portal (ADR-0048): customer-audience articles only; staff articles are never listed, opened
 * or found here (404), whatever the slug.
 */
final class HelpController extends PortalController
{
    public function index(): Response
    {
        $this->customer();
        $reader = HelpReader::forCustomer();
        $articles = $reader->query()->orderBy('sort')->orderBy('id')->get();

        return Inertia::render('portal/help', [
            'categories' => collect(HelpCategory::cases())->map(fn (HelpCategory $c) => [
                'key' => $c->value,
                'articles' => $articles->where('category', $c)->map(fn (HelpArticle $a) => HelpPresenter::summary($a, $reader))->values()->all(),
            ])->filter(fn (array $c) => $c['articles'] !== [])->values()->all(),
        ]);
    }

    public function article(string $slug): Response
    {
        $this->customer();
        $reader = HelpReader::forCustomer();
        $article = $reader->query()->where('slug', $slug)->first() ?? abort(404);

        return Inertia::render('portal/help-article', [
            'article' => HelpPresenter::full($article, $reader),
        ]);
    }

    public function image(string $file): BinaryFileResponse
    {
        $this->customer();

        return HelpImages::response(HelpReader::forCustomer(), $file);
    }
}
