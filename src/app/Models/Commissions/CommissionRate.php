<?php

namespace App\Models\Commissions;

use Illuminate\Database\Eloquent\Model;

/**
 * A commission rate for a role template or for one member (the member's own rate wins).
 *
 * @property int $id
 * @property int|null $role_template_id
 * @property int|null $user_id
 * @property int $rate_bp
 */
class CommissionRate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rate_bp' => 'integer'];
    }
}
