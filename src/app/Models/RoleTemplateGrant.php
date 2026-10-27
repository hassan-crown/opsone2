<?php

namespace App\Models;

use App\Access\Enums\Scope;
use App\Access\Permission;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $role_template_id
 * @property Permission $permission
 * @property Scope $scope
 */
#[Fillable(['role_template_id', 'permission', 'scope'])]
class RoleTemplateGrant extends Model
{
    /**
     * @return BelongsTo<RoleTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(RoleTemplate::class, 'role_template_id');
    }

    protected function casts(): array
    {
        return [
            'permission' => Permission::class,
            'scope' => Scope::class,
        ];
    }
}
