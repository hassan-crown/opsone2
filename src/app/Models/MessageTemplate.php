<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The facility's wording of one event's message on one channel and language (ADR-0037). Absent = the built-in text.
 *
 * @property int $id
 * @property string $event
 * @property string $channel sms | mail | in_app
 * @property string $locale ar | en
 * @property string|null $subject mail subject or in-app title
 * @property string $body
 * @property int|null $updated_by
 */
class MessageTemplate extends Model
{
    protected $guarded = ['id'];
}
