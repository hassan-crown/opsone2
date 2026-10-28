<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * An immutable published flow definition. Orders pin one when they start (WF-10); a new publish never changes them.
 *
 * @property int $id
 * @property int $flow_id
 * @property int $number
 * @property string $template_key
 * @property array{stages: list<array<string, mixed>>} $definition
 * @property int|null $published_by
 * @property CarbonInterface $published_at
 */
class FlowVersion extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A published flow version never changes.'));
        static::deleting(fn () => throw new LogicException('A published flow version is never deleted.'));
    }

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'published_at' => 'datetime',
        ];
    }
}
