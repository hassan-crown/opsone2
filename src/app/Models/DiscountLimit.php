<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A discount limit in basis points (AGR-02, SAL-09): for a role template or as a member's own override. `custom_bp`
 * caps the negotiated discount on a made-to-order quote, `ready_bp` the staff discount on ready goods (P8), and
 * `approval_bp` the highest discount the holder may approve for someone else (P-31).
 *
 * @property int $id
 * @property int|null $role_template_id
 * @property int|null $membership_id
 * @property int $custom_bp
 * @property int $ready_bp
 * @property int $approval_bp
 */
class DiscountLimit extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['custom_bp' => 'integer', 'ready_bp' => 'integer', 'approval_bp' => 'integer'];
    }
}
