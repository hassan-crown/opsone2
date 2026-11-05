<?php

namespace App\Models\Site;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A permanent redirect of an old public path (ADR-0045), written when a published page changes its slug.
 *
 * @property int $id
 * @property string $from_path
 * @property string $to_path
 * @property int $hits
 * @property CarbonInterface|null $last_hit_at
 */
class SiteRedirect extends Model
{
    protected $fillable = ['from_path', 'to_path'];

    /**
     * Records `from → to` for a moved path: earlier redirects that pointed at `from` now point at `to` (never a chain),
     * and a path that is live again (`to`) stops redirecting.
     */
    public static function move(string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }
        self::release($to);
        self::query()->where('to_path', $from)->update(['to_path' => $to]);
        self::query()->updateOrCreate(['from_path' => $from], ['to_path' => $to]);
    }

    /** A live page took this path: it no longer redirects. */
    public static function release(string $path): void
    {
        self::query()->where('from_path', $path)->delete();
    }

    protected function casts(): array
    {
        return ['last_hit_at' => 'datetime'];
    }
}
