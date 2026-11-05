<?php

namespace App\Models\Automation;

use App\Support\Translatable\TranslatableString;
use App\Support\Translatable\Translated;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A draft automation rule (ADR-0042): «عند [trigger] وإذا [conditions] فافعل [actions]». The application runs the rules
 * of a configuration release, never these rows — except the kill switch (`killed_at`), which is live: a killed rule is
 * skipped in every release until revived. Archived rows stay so their key keeps naming the same rule in old releases
 * and run logs.
 *
 * @property int $id
 * @property string $key
 * @property Translated $name
 * @property string $trigger
 * @property array<string, int>|null $trigger_params
 * @property array{match: string, items: list<array{field: string, op: string, value: mixed}>} $conditions
 * @property list<array<string, mixed>> $actions
 * @property bool $enabled
 * @property int $position
 * @property CarbonInterface|null $killed_at
 * @property int|null $killed_by
 * @property string|null $kill_reason
 * @property CarbonInterface|null $archived_at
 * @property int $lock_version
 * @property int|null $updated_by
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class AutomationRule extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['lock_version' => 1];

    /**
     * @param  Builder<AutomationRule>  $query
     * @return Builder<AutomationRule>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * The rule as a release stores it (ADR-0040 snapshot section `automation`).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name->toArray(),
            'trigger' => $this->trigger,
            'params' => $this->trigger_params ?? [],
            'conditions' => $this->conditions,
            'actions' => $this->actions,
            'enabled' => $this->enabled,
            'position' => $this->position,
        ];
    }

    protected function casts(): array
    {
        return [
            'name' => TranslatableString::class,
            'trigger_params' => 'array',
            'conditions' => 'array',
            'actions' => 'array',
            'enabled' => 'boolean',
            'position' => 'integer',
            'killed_at' => 'datetime',
            'archived_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
