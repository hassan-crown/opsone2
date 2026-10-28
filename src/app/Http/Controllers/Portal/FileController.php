<?php

namespace App\Http\Controllers\Portal;

use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\Attachment;
use App\Portal\PortalAccess;
use App\Portal\PortalSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a customer file from a signed link (the `signed` middleware rejects tampered or expired links). The link must
 * be the signed-in customer's and the file's record must still be theirs; otherwise 404 (existence is not revealed).
 */
final class FileController extends PortalController
{
    public function __invoke(Request $request, string $attachment): StreamedResponse
    {
        $customer = $this->customer();
        abort_unless((int) $request->query('c') === $customer->id, 403);
        $file = Attachment::query()->where('uuid', $attachment)->first();
        abort_unless($file !== null && PortalAccess::canDownload($customer, $file), 404);

        $disk = Storage::disk($file->disk);
        abort_unless($disk->exists($file->path), 404);

        DB::transaction(fn () => Audit::record(AuditAction::AttachmentDownloaded, $file->attachable, null, after: [
            'attachment' => $file->uuid, 'name' => $file->original_name, 'by' => 'customer',
        ], source: PortalSession::SOURCE));

        return $disk->download($file->path, $file->original_name, [
            'Content-Type' => $file->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
