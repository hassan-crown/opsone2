<?php

namespace App\Help\KnowledgeBase;

use App\Models\HelpArticle;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors the knowledge base files into help_articles (ADR-0048). All or nothing: any invalid file stops the sync
 * before a row changes. Idempotent: an unchanged file leaves its row untouched (updated_at included). Rows that came
 * from a file that is gone are deleted; rows not managed by files (source_path null) are left alone.
 */
final class HelpSync
{
    public function __construct(private readonly ArticleLibrary $library) {}

    public static function fromDefaultRoot(): self
    {
        return new self(new ArticleLibrary(ArticleLibrary::defaultRoot()));
    }

    /**
     * @return array{created: int, updated: int, unchanged: int, deleted: int, errors: list<string>}
     */
    public function run(bool $dryRun = false): array
    {
        ['articles' => $articles, 'errors' => $errors] = $this->library->load();
        $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'deleted' => 0, 'errors' => $errors];

        if ($errors !== []) {
            return $result;
        }

        $apply = function () use ($articles, &$result, $dryRun): void {
            foreach ($articles as $file) {
                $article = HelpArticle::query()->firstOrNew(['slug' => $file->slug]);
                $article->fill($file->attributes());
                if ($article->published_at === null) {
                    $article->forceFill(['published_at' => now()]);
                }

                $key = ! $article->exists ? 'created' : ($article->isDirty() ? 'updated' : 'unchanged');
                $result[$key]++;
                if (! $dryRun && $key !== 'unchanged') {
                    $article->save();
                }
            }

            $gone = HelpArticle::query()->whereNotNull('source_path')
                ->whereNotIn('slug', array_map(fn (ArticleFile $f) => $f->slug, $articles));
            $result['deleted'] = $dryRun ? $gone->count() : $gone->delete();
        };

        DB::transaction($apply);

        return $result;
    }
}
