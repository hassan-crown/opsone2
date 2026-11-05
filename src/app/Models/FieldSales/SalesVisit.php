<?php

namespace App\Models\FieldSales;

use App\Access\Permission;
use App\Files\Contracts\AttachmentOwner;
use App\Models\Appointment;
use App\Models\Attachment;
use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * The rep's work on one visit/measurement appointment (ADR-0041): arrival, notes, site photos and the outcome that
 * completes the appointment. Its files follow the appointment's access (P-54 over the appointment).
 *
 * @property int $id
 * @property int $appointment_id
 * @property int $order_id
 * @property int $rep_id
 * @property CarbonInterface $arrived_at
 * @property string|null $note
 * @property CarbonInterface|null $finished_at
 * @property string|null $outcome
 * @property int $lock_version
 * @property-read Appointment $appointment
 * @property-read Order $order
 */
class SalesVisit extends Model implements AttachmentOwner
{
    public const MAX_PHOTOS = 20;

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

    /** @return HasMany<SalesVisitPhoto, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(SalesVisitPhoto::class);
    }

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function isFinished(): bool
    {
        return $this->finished_at !== null;
    }

    public function attachmentViewPermission(): Permission
    {
        return Permission::P54;
    }

    public function attachmentUploadPermission(): Permission
    {
        return Permission::P54;
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

    private function scopeSource(): Appointment
    {
        $this->loadMissing('appointment.order');

        return $this->appointment;
    }

    protected function casts(): array
    {
        return ['arrived_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
