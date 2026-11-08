<?php

namespace App\Help\Pdf;

use App\Help\HelpMarkdown;
use App\Help\KnowledgeBase\ArticleFile;
use Illuminate\Support\Carbon;

/**
 * Turns a RoleBook into one self-contained HTML document (cover, contents, role guide, sections): the intermediate
 * form the PDF is printed from. `article:slug` links become `#a-slug` anchors when that article is in the book (any
 * other article link keeps its text only), images become in-memory `var:` references (no file or URL is fetched
 * while printing), and article headings sit one level under the article title.
 */
final class RoleBookHtml
{
    /**
     * @param  array<string, int>  $pages  printed page numbers by anchor id (known after a first print)
     * @return array{html: string, images: array<string, string>}
     */
    public function render(RoleBook $book, string $root, ?Carbon $issued = null, array $pages = []): array
    {
        $included = array_flip(array_map(fn (ArticleFile $a) => $a->slug, $book->articles()));
        $images = [];
        $bodies = [];

        foreach ($book->articles() as $article) {
            $html = HelpMarkdown::render(
                $article->bodyAr,
                fn (string $slug) => isset($included[$slug]) ? '#a-'.$slug : null,
                function (string $file) use ($root, &$images) {
                    $name = 'img_'.preg_replace('/\W+/', '_', $file);
                    $path = $root.'/images/'.$file;
                    if (! isset($images[$name]) && is_file($path)) {
                        $images[$name] = (string) file_get_contents($path);
                    }

                    return 'var:'.$name;
                },
            );
            $bodies[$article->slug] = $this->shape($html);
        }

        $sectionTitles = [];
        foreach (RoleBook::categoryOrder() as $category) {
            $sectionTitles[$category->value] = __('help.category.'.$category->value, [], 'ar');
        }

        $view = view('help.pdf.book', [
            'book' => $book,
            'bodies' => $bodies,
            'pages' => $pages,
            'sectionTitles' => $sectionTitles,
            'issued' => ($issued ?? now())->format('Y-m-d'),
        ]);

        return ['html' => $view->render(), 'images' => $images];
    }

    /**
     * Demotes body headings under the article title and keeps a lone image with its own block, so it is never split.
     */
    private function shape(string $html): string
    {
        $html = (string) preg_replace_callback(
            '#<(/?)h([1-6])(?=[\s>])#',
            fn (array $m) => '<'.$m[1].'h'.min(6, (int) $m[2] + 1),
            $html,
        );

        return (string) preg_replace('#<p>(<img [^>]*>)</p>#', '<div class="fig">$1</div>', $html);
    }
}
