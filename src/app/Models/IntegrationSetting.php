<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Facility-owned integration account (SMTP, SMS provider). `config` is encrypted at rest and hidden from
 * serialization; secrets are never sent to the browser (SRS-SET-06).
 *
 * @property int $id
 * @property string $key
 * @property bool $enabled
 * @property array<string, mixed>|null $config
 * @property Carbon|null $tested_at
 * @property bool|null $test_ok
 * @property int|null $updated_by
 */
#[Hidden(['config'])]
class IntegrationSetting extends Model
{
    public const SMTP = 'smtp';

    public const SMS = 'sms';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'config' => 'encrypted:array',
            'tested_at' => 'datetime',
            'test_ok' => 'boolean',
        ];
    }
}
