<?php

namespace App\Http\Controllers\Site;

use App\Access\Authorizer;
use App\Access\Permission;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Site\SitePage;
use App\Models\Site\SiteTheme;
use App\Site\SiteContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves site images. Public: only files that belong to the live site (published page versions of live pages, the
 * published theme) — a draft image or any other private attachment is 404. Admin: files of site pages / the theme
 * for P-55 / P-100 holders (editor previews).
 */
final class MediaController extends Controller
{
    private const SERVED = ['image/png', 'image/webp'];

    public function __construct(private readonly SiteContext $site, private readonly Authorizer $access) {}

    public function public(Attachment $attachment): StreamedResponse
    {
        abort_unless($this->isSiteFile($attachment) && $this->site->isPublicMedia($attachment), 404);

        return $this->stream($attachment, 'public, max-age=86400');
    }

    public function admin(Request $request, Attachment $attachment): StreamedResponse
    {
        $owner = $attachment->attachable;
        $permission = match (true) {
            $owner instanceof SitePage => Permission::P55,
            $owner instanceof SiteTheme => Permission::P100,
            default => null,
        };
        abort_if($permission === null, 404);
        $this->access->authorize($request->user(), $permission, $owner);

        return $this->stream($attachment, 'private, max-age=60');
    }

    private function isSiteFile(Attachment $attachment): bool
    {
        $owner = $attachment->attachable;

        return $owner instanceof SitePage || $owner instanceof SiteTheme;
    }

    private function stream(Attachment $attachment, string $cache): StreamedResponse
    {
        abort_unless(in_array($attachment->mime, self::SERVED, true), 404);
        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);

        return $disk->response($attachment->path, null, [
            'Content-Type' => $attachment->mime, 'Cache-Control' => $cache, 'ETag' => '"'.$attachment->sha256.'"',
            'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline',
        ]);
    }
}
