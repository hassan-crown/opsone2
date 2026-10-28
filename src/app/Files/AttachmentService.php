<?php

namespace App\Files;

use App\Access\Authorizer;
use App\Access\LicenseLimits;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Files\Contracts\AttachmentOwner;
use App\Files\Rules\AllowedAttachment;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stores private attachments and issues their download links (SHR-10, NFR-14, ADR-0006).
 *
 * Order: authorize on the owner → validate type and size → write the file under a random name → in one transaction
 * reserve storage against the license, insert the row and audit it. If the transaction fails, the file is removed.
 */
final class AttachmentService
{
    public function __construct(
        private readonly Authorizer $access,
        private readonly LicenseLimits $limits,
    ) {}

    public function store(User $actor, Model&AttachmentOwner $owner, UploadedFile $file, ?string $purpose = null): Attachment
    {
        $this->access->authorize($actor, $owner->attachmentUploadPermission(), $owner);

        return $this->persist($actor, $owner, $file, $purpose);
    }

    /**
     * A file the customer uploads from their own account (P-68, P8 portal): no staff user, so the caller — the
     * customer service that re-checked the order is theirs — is the authorization. Same type, size and quota checks.
     */
    public function storeFromCustomer(Model&AttachmentOwner $owner, UploadedFile $file, string $purpose): Attachment
    {
        return $this->persist(null, $owner, $file, $purpose);
    }

    private function persist(?User $actor, Model&AttachmentOwner $owner, UploadedFile $file, ?string $purpose): Attachment
    {
        Validator::make(['file' => $file], ['file' => ['required', new AllowedAttachment]])->validate();
        $extension = (string) AllowedAttachment::detect($file);

        $disk = (string) config('attachments.disk');
        $uuid = (string) Str::uuid();
        $path = now()->format('Y/m').'/'.$uuid.'.'.$extension;
        $size = (int) $file->getSize();
        $sha256 = (string) hash_file('sha256', (string) $file->getRealPath());

        Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path));

        try {
            return DB::transaction(function () use ($actor, $owner, $file, $purpose, $disk, $uuid, $path, $extension, $size, $sha256) {
                $this->limits->reserveStorage($size);

                $attachment = Attachment::query()->create([
                    'uuid' => $uuid,
                    'attachable_type' => $owner->getMorphClass(),
                    'attachable_id' => $owner->getKey(),
                    'purpose' => $purpose,
                    'disk' => $disk,
                    'path' => $path,
                    'original_name' => self::safeName($file->getClientOriginalName(), $extension),
                    'extension' => $extension,
                    'mime' => (string) $file->getMimeType(),
                    'size_bytes' => $size,
                    'sha256' => $sha256,
                    'uploaded_by_user_id' => $actor?->id,
                ]);

                Audit::record(AuditAction::AttachmentUploaded, $owner, $actor, after: [
                    'attachment' => $attachment->uuid,
                    'name' => $attachment->original_name,
                    'mime' => $attachment->mime,
                    'size_bytes' => $attachment->size_bytes,
                    'purpose' => $purpose,
                ], source: $actor === null ? 'customer' : TransitionRecorder::SOURCE_WEB);

                return $attachment;
            });
        } catch (Throwable $e) {
            Storage::disk($disk)->delete($path);

            throw $e;
        }
    }

    /**
     * A short-lived signed link, bound to the user it is issued for. It is not a grant: the download re-checks
     * access on the owning record, so a link issued before access was removed stops working.
     */
    public function downloadUrl(Attachment $attachment, User $user): string
    {
        return URL::temporarySignedRoute(
            'files.download',
            now()->addMinutes((int) config('attachments.link_ttl_minutes', 5)),
            ['attachment' => $attachment->uuid, 'u' => $user->id],
        );
    }

    /**
     * Can this user download the file right now? Missing or foreign owners are never downloadable.
     */
    public function canDownload(User $user, Attachment $attachment): bool
    {
        $owner = $attachment->attachable;

        return $owner instanceof AttachmentOwner
            && $this->access->can($user, $owner->attachmentViewPermission(), $owner);
    }

    private static function safeName(string $name, string $extension): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', '_', $base));
        $base = Str::limit($base === '' ? 'file' : $base, 150, '');

        return $base.'.'.$extension;
    }
}
