<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Editable role template (Q-19). The owner template is locked: the owner always holds every staff permission.
 *
 * @property int $id
 * @property string|null $key
 * @property string $name_ar
 * @property string|null $name_en
 * @property bool $is_owner
 * @property-read Collection<int, RoleTemplateGrant> $grants
 */
#[Fillable(['key', 'name_ar', 'name_en', 'is_owner'])]
class RoleTemplate extends Model
{
    /**
     * @return HasMany<RoleTemplateGrant, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(RoleTemplateGrant::class);
    }

    public function name(?string $locale = null): string
    {
        return (($locale ?? app()->getLocale()) === 'en' && $this->name_en) ? $this->name_en : $this->name_ar;
    }

    protected function casts(): array
    {
        return ['is_owner' => 'boolean'];
    }
}
