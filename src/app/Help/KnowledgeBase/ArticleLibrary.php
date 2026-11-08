<?php

namespace App\Help\KnowledgeBase;

use App\Access\Enums\Audience;
use App\Access\LicensedCapabilities;
use App\Access\Permission;
use App\Access\PermissionCatalogue;
use App\Help\HelpAudience;
use App\Help\HelpCategory;
use Illuminate\Support\Facades\Route;

/**
 * Reads and validates the knowledge base files (ADR-0048):
 *
 *   {root}/ar/{staff|customer}/{slug}.md   authoritative, with the frontmatter below
 *   {root}/en/{staff|customer}/{slug}.md   optional translation: slug, title, summary (+ body)
 *   {root}/images/{slug}-{n}.{png|jpg|jpeg|webp}
 *
 * Required keys: slug (= file name), title, summary, category. Optional: roles, permissions, capability, page_key,
 * order. Every role, permission, capability, page key and image a file names must exist, so a broken reference
 * fails `help:sync` and the coverage test instead of reaching a reader.
 */
final class ArticleLibrary
{
    public const REQUIRED = ['slug', 'title', 'summary', 'category'];

    public const OPTIONAL = ['roles', 'permissions', 'capability', 'page_key', 'order'];

    public const TRANSLATION_KEYS = ['slug', 'title', 'summary'];

    public const SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public const IMAGE = '/^([a-z0-9]+(?:-[a-z0-9]+)*)-(\d+)\.(png|jpe?g|webp)$/';

    /** @var list<string> */
    private array $errors = [];

    public function __construct(private readonly string $root) {}

    public static function defaultRoot(): string
    {
        return resource_path('help');
    }

    /**
     * @return array{articles: list<ArticleFile>, errors: list<string>}
     */
    public function load(): array
    {
        $this->errors = [];
        /** @var array<string, ArticleFile> $articles */
        $articles = [];

        foreach (HelpAudience::cases() as $audience) {
            foreach ($this->files('ar', $audience) as $path) {
                $article = $this->arabic($path, $audience);
                if ($article === null) {
                    continue;
                }
                if (isset($articles[$article->slug])) {
                    $this->fail($path, "slug «{$article->slug}» is already used by {$articles[$article->slug]->path}");

                    continue;
                }
                $articles[$article->slug] = $article;
            }
        }

        foreach (HelpAudience::cases() as $audience) {
            foreach ($this->files('en', $audience) as $path) {
                $this->english($path, $audience, $articles);
            }
        }

        $this->checkArticleLinks($articles);

        $list = array_values($articles);
        usort($list, fn (ArticleFile $a, ArticleFile $b) => [$a->audience->value, $a->order, $a->slug] <=> [$b->audience->value, $b->order, $b->slug]);

        return ['articles' => $list, 'errors' => $this->errors];
    }

    /**
     * @return list<string>
     */
    private function files(string $locale, HelpAudience $audience): array
    {
        $files = glob($this->root.'/'.$locale.'/'.$audience->value.'/*.md') ?: [];
        sort($files);

        return $files;
    }

    private function arabic(string $path, HelpAudience $audience): ?ArticleFile
    {
        $parsed = $this->parse($path);
        if ($parsed === null) {
            return null;
        }
        ['meta' => $meta, 'body' => $body] = $parsed;
        $before = count($this->errors);

        foreach (array_diff(array_keys($meta), self::REQUIRED, self::OPTIONAL) as $unknown) {
            $this->fail($path, "unknown key «{$unknown}» (allowed: ".implode(', ', [...self::REQUIRED, ...self::OPTIONAL]).')');
        }
        foreach (self::REQUIRED as $key) {
            if (! is_string($meta[$key] ?? null) || trim((string) $meta[$key]) === '') {
                $this->fail($path, "missing required key «{$key}»");
            }
        }
        if ($body === '') {
            $this->fail($path, 'the article body is empty');
        }

        $slug = is_string($meta['slug'] ?? null) ? $meta['slug'] : '';
        $this->checkSlug($path, $slug);

        $title = is_string($meta['title'] ?? null) ? trim($meta['title']) : '';
        if (mb_strlen($title) > 200) {
            $this->fail($path, 'title is longer than 200 characters');
        }

        $category = is_string($meta['category'] ?? null) ? HelpCategory::tryFrom($meta['category']) : null;
        if (isset($meta['category']) && $category === null) {
            $this->fail($path, 'category must be one of: '.implode(', ', array_map(fn (HelpCategory $c) => $c->value, HelpCategory::cases())));
        }

        $roles = $this->list($path, $meta, 'roles');
        foreach ($roles as $role) {
            if (! array_key_exists($role, PermissionCatalogue::ROLES)) {
                $this->fail($path, "unknown role «{$role}»");
            }
        }

        $permissions = $this->list($path, $meta, 'permissions');
        foreach ($permissions as $code) {
            $permission = Permission::tryFrom($code);
            if ($permission === null) {
                $this->fail($path, "unknown permission «{$code}»");
            } elseif ($audience === HelpAudience::Staff && ! in_array($permission->audience(), [Audience::Staff, Audience::Self], true)) {
                $this->fail($path, "permission «{$code}» is not a staff permission");
            }
        }
        if ($audience === HelpAudience::Customer && $permissions !== []) {
            $this->fail($path, 'customer articles cannot require permissions (customers hold no staff grants)');
        }
        if ($audience === HelpAudience::Customer && $roles !== []) {
            $this->fail($path, 'customer articles cannot list staff roles');
        }

        $capability = $meta['capability'] ?? null;
        if ($capability !== null && (! is_string($capability) || ! in_array($capability, LicensedCapabilities::KNOWN, true))) {
            $this->fail($path, 'unknown capability «'.(is_scalar($capability) ? $capability : 'list').'» (known: '.implode(', ', LicensedCapabilities::KNOWN).')');
        }

        $pageKey = $meta['page_key'] ?? null;
        if ($pageKey !== null && (! is_string($pageKey) || ! Route::has($pageKey))) {
            $this->fail($path, 'page_key «'.(is_scalar($pageKey) ? $pageKey : 'list').'» is not a route name');
        } elseif (is_string($pageKey) && ($audience === HelpAudience::Customer) !== str_starts_with($pageKey, 'customer.')) {
            $this->fail($path, "page_key «{$pageKey}» belongs to the other audience");
        }

        $order = $meta['order'] ?? 0;
        if (! is_int($order) || $order < 0 || $order > 65535) {
            $this->fail($path, 'order must be a whole number between 0 and 65535');
        }

        $this->checkImages($path, $slug, $body);

        if (count($this->errors) > $before || $category === null) {
            return null;
        }

        return new ArticleFile(
            slug: $slug,
            audience: $audience,
            category: $category,
            titleAr: $title,
            summaryAr: is_string($meta['summary'] ?? null) ? trim($meta['summary']) : '',
            bodyAr: $body,
            roles: array_values(array_unique($roles)),
            permissions: array_values(array_unique($permissions)),
            capability: is_string($capability) ? $capability : $this->sharedCapability($permissions),
            pageKey: is_string($pageKey) ? $pageKey : null,
            order: is_int($order) ? $order : 0,
            path: $this->relative($path),
        );
    }

    /**
     * @param  array<string, ArticleFile>  $articles
     */
    private function english(string $path, HelpAudience $audience, array &$articles): void
    {
        $parsed = $this->parse($path);
        if ($parsed === null) {
            return;
        }
        ['meta' => $meta, 'body' => $body] = $parsed;

        foreach (array_diff(array_keys($meta), self::TRANSLATION_KEYS) as $unknown) {
            $this->fail($path, "unknown key «{$unknown}» in a translation (allowed: ".implode(', ', self::TRANSLATION_KEYS).'; the rest comes from the Arabic file)');
        }
        $slug = is_string($meta['slug'] ?? null) ? $meta['slug'] : '';
        $this->checkSlug($path, $slug);
        $title = is_string($meta['title'] ?? null) ? trim($meta['title']) : '';
        if ($title === '') {
            $this->fail($path, 'missing required key «title»');
        }
        if ($body === '') {
            $this->fail($path, 'the article body is empty');
        }
        $original = $articles[$slug] ?? null;
        if ($original === null || $original->audience !== $audience) {
            $this->fail($path, "no Arabic article «{$slug}» in ar/{$audience->value}");

            return;
        }
        $this->checkImages($path, $slug, $body);

        $summary = is_string($meta['summary'] ?? null) && trim($meta['summary']) !== '' ? trim($meta['summary']) : null;
        if ($title !== '' && $body !== '') {
            $articles[$slug] = $original->withEnglish($title, $summary, $body);
        }
    }

    /**
     * @return array{meta: array<string, string|int|list<string>|null>, body: string}|null
     */
    private function parse(string $path): ?array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            $this->fail($path, 'cannot be read');

            return null;
        }

        try {
            return FrontMatter::parse($contents);
        } catch (InvalidArticleFile $e) {
            $this->fail($path, $e->getMessage());

            return null;
        }
    }

    private function checkSlug(string $path, string $slug): void
    {
        if ($slug === '') {
            return;
        }
        if (! preg_match(self::SLUG, $slug) || strlen($slug) > 120) {
            $this->fail($path, "slug «{$slug}» must be lowercase latin words joined by «-» (120 characters at most)");
        }
        if ($slug !== basename($path, '.md')) {
            $this->fail($path, "slug «{$slug}» must match the file name");
        }
    }

    /**
     * @param  array<string, string|int|list<string>|null>  $meta
     * @return list<string>
     */
    private function list(string $path, array $meta, string $key): array
    {
        $value = $meta[$key] ?? [];
        if (! is_array($value)) {
            $this->fail($path, "«{$key}» must be a list like [a, b]");

            return [];
        }

        return $value;
    }

    /**
     * Images may only be our own files, named after the article: `images/{slug}-{n}.png`.
     */
    private function checkImages(string $path, string $slug, string $body): void
    {
        preg_match_all('/!\[[^\]]*\]\(\s*([^)\s]+)[^)]*\)/u', $body, $matches);
        foreach ($matches[1] as $src) {
            $file = str_starts_with($src, 'images/') ? substr($src, 7) : null;
            if ($file === null || ! preg_match(self::IMAGE, $file, $m) || ($slug !== '' && $m[1] !== $slug)) {
                $this->fail($path, "image «{$src}» must be images/{$slug}-{n}.png (png, jpg or webp)");

                continue;
            }
            if (! is_file($this->root.'/images/'.$file)) {
                $this->fail($path, "image «{$src}» does not exist in images/");
            }
        }
    }

    /**
     * `[title](article:slug)` must name an article of the same audience (the link opens on the reader's side).
     *
     * @param  array<string, ArticleFile>  $articles
     */
    private function checkArticleLinks(array $articles): void
    {
        foreach ($articles as $article) {
            foreach (array_filter([$article->bodyAr, $article->bodyEn]) as $body) {
                preg_match_all('/(?<!!)\[[^\]]*\]\(\s*article:([^)\s]*)[^)]*\)/u', $body, $matches);
                foreach ($matches[1] as $slug) {
                    $target = $articles[$slug] ?? null;
                    if ($target === null || $target->audience !== $article->audience) {
                        $this->errors[] = $article->path.": link «article:{$slug}» does not name a {$article->audience->value} article";
                    }
                }
            }
        }
    }

    /**
     * An article about a licensed feature needs that capability even when the file does not say so: when every
     * permission it lists requires the same capability, that capability applies.
     *
     * @param  list<string>  $permissions
     */
    private function sharedCapability(array $permissions): ?string
    {
        $capabilities = array_unique(array_map(fn (string $code) => Permission::tryFrom($code)?->capability(), $permissions));

        return count($capabilities) === 1 && is_string($capabilities[array_key_first($capabilities)])
            ? $capabilities[array_key_first($capabilities)]
            : null;
    }

    private function relative(string $path): string
    {
        return ltrim(substr($path, strlen($this->root)), '/');
    }

    private function fail(string $path, string $message): void
    {
        $this->errors[] = $this->relative($path).': '.$message;
    }
}
