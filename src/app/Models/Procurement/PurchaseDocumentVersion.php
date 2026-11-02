<?php

namespace App\Models\Procurement;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One saved version of the facility's purchase-order document (ADR-0038). Append-only: a change is a new version,
 * and every generated PDF names the version it used.
 *
 * @property int $id
 * @property int $version
 * @property array<string, mixed> $layout
 * @property array<string, mixed> $email
 * @property int|null $created_by
 * @property CarbonInterface $created_at
 */
class PurchaseDocumentVersion extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A purchase document version never changes.'));
        static::deleting(fn () => throw new LogicException('A purchase document version is never deleted.'));
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'layout' => 'array', 'email' => 'array'];
    }
}
