<?php

namespace App\Models\Agreements;

use App\Support\Translatable\TranslatableString;
use App\Support\Translatable\Translated;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A contract or quote clause of the facility library (ADR-0037). The row holds the current text and version; each
 * save adds an immutable DocumentClauseVersion. A prepared document copies the rendered text and stores the version.
 *
 * @property int $id
 * @property string $key
 * @property Translated $title
 * @property Translated $body
 * @property list<string> $applies_to quote | contract
 * @property bool $included_by_default
 * @property int $position
 * @property int $version
 * @property CarbonInterface|null $archived_at
 * @property int $lock_version
 * @property int|null $updated_by
 */
class DocumentClause extends Model
{
    protected $guarded = ['id', 'lock_version'];

    protected $attributes = ['lock_version' => 1, 'version' => 1];

    /**
     * @return HasMany<DocumentClauseVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentClauseVersion::class);
    }

    protected function casts(): array
    {
        return [
            'title' => TranslatableString::class,
            'body' => TranslatableString::class,
            'applies_to' => 'array',
            'included_by_default' => 'boolean',
            'position' => 'integer',
            'version' => 'integer',
            'archived_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
