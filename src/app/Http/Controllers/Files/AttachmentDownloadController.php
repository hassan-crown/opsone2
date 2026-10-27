<?php

namespace App\Http\Controllers\Files;

use App\Files\AttachmentService;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a private attachment from a signed link (the `signed` middleware rejects tampered or expired links).
 * The link must belong to the signed-in user, and access to the owning record is re-checked now; an attachment
 * the user may not see answers 404 so its existence is not revealed.
 */
final class AttachmentDownloadController extends Controller
{
    public function __invoke(Request $request, Attachment $attachment, AttachmentService $files): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless((int) $request->query('u') === $user->id, 403);
        abort_unless($files->canDownload($user, $attachment), 404);

        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);

        return $disk->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
