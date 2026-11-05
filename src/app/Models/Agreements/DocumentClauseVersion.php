<?php

namespace App\Models\Agreements;

use App\Support\Translatable\TranslatableString;
use App\Support\Translatable\Translated;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One saved text of a clause (ADR-0037). Immutable: documents name it by (clause key, version).
 *
 * @property int $id
 * @property int $document_clause_id
 * @property int $version
 * @property Translated $title
 * @property Translated $body
 * @property int|null $created_by
 * @property CarbonInterface $created_at
 */
class DocumentClauseVersion extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A clause version never changes.'));
        static::deleting(fn () => throw new LogicException('A clause version is never deleted.'));
    }

    protected function casts(): array
    {
        return [
            'title' => TranslatableString::class,
            'body' => TranslatableString::class,
            'created_at' => 'datetime',
        ];
    }
}
