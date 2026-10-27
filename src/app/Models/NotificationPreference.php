<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A user's choice to receive (or not) the email copy of an optional notification type.
 *
 * @property int $user_id
 * @property string $type
 * @property bool $email
 */
class NotificationPreference extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['email' => 'boolean'];
    }
}
