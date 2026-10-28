<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Remembers which interest a public form submission key produced (idempotent retries).
 *
 * @property int $id
 * @property string $submission_key
 * @property int $interest_id
 */
class SiteLeadSubmission extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];
}
