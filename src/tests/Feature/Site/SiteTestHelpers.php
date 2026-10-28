<?php

use App\Actions\Site\SitePageWriter;
use App\Models\Site\SitePage;
use App\Models\User;

/**
 * A complete content of one language: a title, a text section and the contact section.
 *
 * @return array{title: string, meta_description: string, sections: list<array<string, mixed>>}
 */
function pageContent(string $title, string $body = 'Body text'): array
{
    return [
        'title' => $title, 'meta_description' => 'About '.$title,
        'sections' => [
            ['id' => 's1', 'type' => 'text', 'visible' => true, 'data' => ['heading' => $title, 'body' => $body]],
        ],
    ];
}

/**
 * Creates a content page with a complete draft in both languages and publishes it (unless told otherwise).
 */
function makePage(User $actor, string $slug = 'about', bool $publish = true): SitePage
{
    $writer = app(SitePageWriter::class);
    $page = $writer->create($actor, $slug, ['ar' => 'عنا', 'en' => 'About']);
    $writer->saveDraft($actor, $page, ['ar' => pageContent('عنا', 'نص عربي'), 'en' => pageContent('About', 'English text')]);
    if ($publish) {
        $writer->publish($actor, $page, ['ar', 'en']);
    }

    return $page->refresh();
}
