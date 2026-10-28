<?php

namespace App\Models\Site;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The facility's optional custom domain and its ownership proof (SUB-19, Q-46 a). The app only records and checks
 * the proof; routing the host to this deployment is the server admin's job (docs/decisions/0021).
 *
 * @property int $id
 * @property string $hostname
 * @property string $status pending|verified|failed
 * @property string $verification_token
 * @property Carbon|null $verified_at
 * @property Carbon|null $last_checked_at
 * @property string|null $last_error
 */
class SiteDomain extends Model
{
    public const PENDING = 'pending';

    public const VERIFIED = 'verified';

    public const FAILED = 'failed';

    protected $guarded = ['id'];

    public function txtName(): string
    {
        return '_opsone-verify.'.$this->hostname;
    }

    public function txtValue(): string
    {
        return 'opsone-site-verification='.$this->verification_token;
    }

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'last_checked_at' => 'datetime'];
    }
}
