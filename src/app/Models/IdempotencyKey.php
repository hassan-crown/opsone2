<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $scope
 * @property int $user_id
 * @property string $key
 * @property string $request_hash
 * @property array<string, mixed>|null $result
 * @property CarbonInterface $created_at
 */
class IdempotencyKey extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['result' => 'array'];
    }
}
