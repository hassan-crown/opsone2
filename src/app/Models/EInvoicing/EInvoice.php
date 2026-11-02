<?php

namespace App\Models\EInvoicing;

use App\Models\Customer;
use App\Models\Finance\Payment;
use App\Models\Finance\Refund;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A ZATCA tax invoice, credit note or debit note (ADR-0033). Immutable once issued: its number, UUID, parties, lines,
 * amounts, chain position (ICV, previous hash, hash) and XML never change — a correction is a credit or debit note
 * referencing it. Only the submission outcome (status, attempts, ZATCA's response and the cleared XML) moves.
 *
 * @property int $id
 * @property string $uuid
 * @property string $number
 * @property string $kind invoice | credit_note | debit_note
 * @property string $type standard | simplified
 * @property int $order_id
 * @property int $customer_id
 * @property int|null $payment_id
 * @property int|null $refund_id
 * @property int|null $original_invoice_id
 * @property int|null $order_invoice_key
 * @property string|null $reason
 * @property CarbonInterface $issued_at
 * @property CarbonInterface $issue_date
 * @property string $issue_time
 * @property string $currency
 * @property array<string, string|null> $seller
 * @property array<string, mixed> $buyer
 * @property list<array{description: string, quantity: string, unit: string|null, net_minor: int, vat_minor: int, gross_minor: int}> $lines
 * @property int $vat_rate_bp
 * @property int $net_minor
 * @property int $vat_minor
 * @property int $total_minor
 * @property string $payment_means_code
 * @property int|null $device_id
 * @property int $icv
 * @property string $previous_hash
 * @property string $hash
 * @property bool $signed
 * @property string $xml
 * @property string $qr
 * @property string $submission_status
 * @property bool $submission_warnings
 * @property int $submission_attempts
 * @property CarbonInterface|null $submitted_at
 * @property array<string, mixed>|null $zatca_response
 * @property string|null $cleared_xml
 * @property int|null $issued_by
 * @property-read Order $order
 * @property-read Customer $customer
 * @property-read EInvoice|null $original
 * @property-read ZatcaDevice|null $device
 */
class EInvoice extends Model
{
    public const KIND_INVOICE = 'invoice';

    public const KIND_CREDIT = 'credit_note';

    public const KIND_DEBIT = 'debit_note';

    public const STANDARD = 'standard';

    public const SIMPLIFIED = 'simplified';

    /** Phase 1 only (no onboarded device): generated, nothing to submit. */
    public const NOT_REQUIRED = 'not_required';

    public const PENDING = 'pending';

    public const REPORTED = 'reported';

    public const CLEARED = 'cleared';

    /** ZATCA refused it (400): never resent; corrected by a new document. */
    public const REJECTED = 'rejected';

    /** Retries exhausted on transport/server errors: the owner may retry. */
    public const FAILED = 'failed';

    /** The only columns that change after issue. */
    private const MUTABLE = ['submission_status', 'submission_warnings', 'submission_attempts', 'submitted_at', 'zatca_response', 'cleared_xml', 'updated_at'];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $invoice) {
            if (array_diff(array_keys($invoice->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException('An issued e-invoice never changes; issue a credit or debit note.');
            }
        });
        static::deleting(fn () => throw new LogicException('An issued e-invoice is never deleted.'));
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<Refund, $this>
     */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    /**
     * @return BelongsTo<EInvoice, $this>
     */
    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_invoice_id');
    }

    /**
     * @return HasMany<EInvoice, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(self::class, 'original_invoice_id');
    }

    /**
     * @return BelongsTo<ZatcaDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(ZatcaDevice::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function isNote(): bool
    {
        return $this->kind !== self::KIND_INVOICE;
    }

    /**
     * UBL InvoiceTypeCode: 388 invoice, 381 credit note, 383 debit note.
     */
    public function typeCode(): string
    {
        return match ($this->kind) {
            self::KIND_CREDIT => '381',
            self::KIND_DEBIT => '383',
            default => '388',
        };
    }

    /**
     * The InvoiceTypeCode `name` (NNPNESB): 01 standard / 02 simplified, then no third-party, nominal, export, summary
     * or self-billed flags.
     */
    public function subtypeCode(): string
    {
        return ($this->type === self::STANDARD ? '01' : '02').'00000';
    }

    /**
     * Reporting (simplified) or clearance (standard) — the ProfileID ZATCA expects is `reporting:1.0` for both.
     */
    public function needsClearance(): bool
    {
        return $this->type === self::STANDARD;
    }

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'issue_date' => 'date',
            'seller' => 'array',
            'buyer' => 'array',
            'lines' => 'array',
            'vat_rate_bp' => 'integer',
            'net_minor' => 'integer',
            'vat_minor' => 'integer',
            'total_minor' => 'integer',
            'icv' => 'integer',
            'signed' => 'boolean',
            'submission_warnings' => 'boolean',
            'submission_attempts' => 'integer',
            'submitted_at' => 'datetime',
            'zatca_response' => 'array',
        ];
    }
}
