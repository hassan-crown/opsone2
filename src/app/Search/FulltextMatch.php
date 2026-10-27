<?php

namespace App\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Applies a text match over columns: MariaDB FULLTEXT (boolean mode, prefix per word) when the table has
 * the index, plus a LIKE match so short words under the engine's minimum token size still find their rows.
 * On other drivers (SQLite in tests) it is LIKE only.
 */
final class FulltextMatch
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns  columns the LIKE match covers
     * @param  list<list<string>>  $indexes  column sets of the table's FULLTEXT indexes (each MATCH must equal one index)
     * @return Builder<TModel>
     */
    public static function apply(Builder $query, array $columns, string $term, array $indexes = []): Builder
    {
        $words = self::words($term);
        if ($words === []) {
            return $query->whereRaw('1 = 0');
        }

        $useFulltext = $indexes !== [] && in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
        $table = $query->getModel()->getTable();

        return $query->where(function (Builder $q) use ($columns, $words, $useFulltext, $table, $indexes) {
            if ($useFulltext) {
                $boolean = implode(' ', array_map(fn (string $w) => '+'.$w.'*', $words));
                foreach ($indexes as $index) {
                    $list = implode(', ', array_map(fn (string $c) => '`'.$table.'`.`'.$c.'`', $index));
                    // Column names come from code (never from the request); only the search words are bound.
                    // @phpstan-ignore argument.type
                    $q->orWhereRaw("MATCH ($list) AGAINST (? IN BOOLEAN MODE)", [$boolean]);
                }
            }

            $q->orWhere(function (Builder $all) use ($columns, $words) {
                foreach ($words as $word) {
                    $all->where(function (Builder $any) use ($columns, $word) {
                        foreach ($columns as $column) {
                            $any->orWhere($column, 'like', '%'.self::escape($word).'%');
                        }
                    });
                }
            });
        });
    }

    /**
     * Words safe for BOOLEAN MODE: letters and digits only, so operators typed by the user are inert.
     *
     * @return list<string>
     */
    public static function words(string $term): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_substr($term, 0, 100), $matches);

        return array_slice(array_values(array_unique($matches[0])), 0, 6);
    }

    private static function escape(string $word): string
    {
        return addcslashes($word, '%_\\');
    }
}
