<?php

namespace App\Models\EInvoicing;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * An onboarded EGS unit (ADR-0033): its key pair and CSR, the compliance CSID (with the compliance checks it ran) and
 * the production CSID that signs invoices. The private key and every CSID secret are encrypted with APP_KEY and hidden
 * from serialization; they never reach a payload, the audit log or the application log.
 *
 * @property int $id
 * @property string $environment
 * @property string $status draft | compliance | active | retired
 * @property string $egs_serial
 * @property string $common_name
 * @property string $csr
 * @property string $private_key
 * @property string|null $compliance_request_id
 * @property string|null $compliance_token
 * @property string|null $compliance_secret
 * @property array<string, string>|null $compliance_checks
 * @property string|null $production_token
 * @property string|null $production_secret
 * @property CarbonInterface|null $certificate_expires_at
 * @property CarbonInterface|null $onboarded_at
 * @property CarbonInterface|null $retired_at
 * @property string|null $last_error
 * @property int|null $live_key
 * @property int|null $created_by
 */
class ZatcaDevice extends Model
{
    public const DRAFT = 'draft';

    public const COMPLIANCE = 'compliance';

    public const ACTIVE = 'active';

    public const RETIRED = 'retired';

    protected $guarded = ['id'];

    protected $hidden = ['private_key', 'compliance_token', 'compliance_secret', 'production_token', 'production_secret', 'csr'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('An EGS device is retired, never deleted (its invoices reference it).'));
    }

    /**
     * The live (non-retired) device, if any.
     */
    public static function live(): ?self
    {
        return self::query()->where('live_key', 1)->first();
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE && $this->production_token !== null;
    }

    /**
     * The certificate that signs: the production CSID once active, else the compliance CSID (compliance checks).
     */
    public function signingToken(): ?string
    {
        return $this->status === self::ACTIVE ? $this->production_token : $this->compliance_token;
    }

    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'compliance_token' => 'encrypted',
            'compliance_secret' => 'encrypted',
            'production_token' => 'encrypted',
            'production_secret' => 'encrypted',
            'compliance_checks' => 'array',
            'certificate_expires_at' => 'datetime',
            'onboarded_at' => 'datetime',
            'retired_at' => 'datetime',
            'live_key' => 'integer',
        ];
    }
}
