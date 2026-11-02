<?php

namespace App\Models\Installation;

use App\Access\Permission;
use App\Files\Contracts\AttachmentOwner;
use App\Installation\InstallationStatus;
use App\Models\Appointment;
use App\Models\Attachment;
use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * What happened on site for one installation appointment (ADR-0036): arrival (time and, with consent, one location
 * reading), before/after photos, remarks, the customer's acceptance signature and the finish. It owns its files
 * privately; access follows the appointment (P-24 for its booked members, or within the viewer's P-24 scope).
 *
 * @property int $id
 * @property int $appointment_id
 * @property int $order_id
 * @property InstallationStatus $status
 * @property CarbonInterface $arrived_at
 * @property int|null $arrived_by
 * @property string|null $arrival_latitude
 * @property string|null $arrival_longitude
 * @property int|null $arrival_accuracy_m
 * @property CarbonInterface|null $finished_at
 * @property int|null $finished_by
 * @property string|null $finish_note
 * @property string|null $signature_method
 * @property string|null $signer_name
 * @property CarbonInterface|null $signed_at
 * @property int|null $signature_attachment_id
 * @property string|null $signature_sha256
 * @property string|null $signature_ip
 * @property string|null $signature_user_agent
 * @property CarbonInterface|null $signature_requested_at
 * @property int $lock_version
 * @property-read Appointment $appointment
 * @property-read Order $order
 */
class InstallationVisit extends Model implements AttachmentOwner
{
    public const SIGNED_ON_DEVICE = 'device';

    public const SIGNED_IN_PORTAL = 'portal';

    protected $guarded = ['id'];

    protected $attributes = ['lock_version' => 1];

    /** @return BelongsTo<Appointment, $this> */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<InstallationPhoto, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(InstallationPhoto::class);
    }

    /** @return HasMany<InstallationRemark, $this> */
    public function remarks(): HasMany
    {
        return $this->hasMany(InstallationRemark::class);
    }

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /** @return BelongsTo<Attachment, $this> */
    public function signature(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'signature_attachment_id');
    }

    public function isFinished(): bool
    {
        return $this->finished_at !== null;
    }

    public function isSigned(): bool
    {
        return $this->signed_at !== null;
    }

    /** Minutes on site (arrival → finish), or null while it is still running. */
    public function durationMinutes(): ?int
    {
        return $this->finished_at === null ? null : (int) round($this->arrived_at->diffInSeconds($this->finished_at, true) / 60);
    }

    public function attachmentViewPermission(): Permission
    {
        return Permission::P24;
    }

    public function attachmentUploadPermission(): Permission
    {
        return Permission::P24;
    }

    public function accessBranchIds(): array
    {
        return $this->scopeSource()->accessBranchIds();
    }

    public function accessDepartmentId(): ?int
    {
        return null;
    }

    public function accessAssigneeIds(): array
    {
        return $this->scopeSource()->accessAssigneeIds();
    }

    public function accessOwnerId(): ?int
    {
        return $this->scopeSource()->accessOwnerId();
    }

    /** The appointment the access follows (loaded explicitly when a file check meets the visit on its own). */
    private function scopeSource(): Appointment
    {
        $this->loadMissing('appointment.order');

        return $this->appointment;
    }

    protected function casts(): array
    {
        return [
            'status' => InstallationStatus::class,
            'arrived_at' => 'datetime',
            'finished_at' => 'datetime',
            'signed_at' => 'datetime',
            'signature_requested_at' => 'datetime',
            'arrival_accuracy_m' => 'integer',
            'lock_version' => 'integer',
        ];
    }
}
