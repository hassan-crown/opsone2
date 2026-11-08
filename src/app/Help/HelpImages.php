<?php

namespace App\Help;

use App\Help\KnowledgeBase\ArticleLibrary;
use App\Models\HelpArticle;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves knowledge base images (resources/help/images) to readers of the article they belong to. The file name is
 * matched against a strict pattern (no separators, no dots but the extension), the image's slug must be an article
 * the reader may read, and the resolved real path must stay inside the images folder.
 */
final class HelpImages
{
    private const TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];

    public static function response(HelpReader $reader, string $file, ?string $root = null): BinaryFileResponse
    {
        abort_unless(preg_match(ArticleLibrary::IMAGE, $file, $m) === 1, 404);

        $article = HelpArticle::query()->where('slug', $m[1])->first();
        abort_if($article === null || ! $reader->canRead($article), 404);

        $base = realpath(($root ?? ArticleLibrary::defaultRoot()).'/images');
        $path = $base === false ? false : realpath($base.DIRECTORY_SEPARATOR.$file);
        abort_if($base === false || $path === false || ! str_starts_with($path, $base.DIRECTORY_SEPARATOR) || ! is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => self::TYPES[strtolower($m[3])],
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
