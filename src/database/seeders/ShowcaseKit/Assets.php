<?php

namespace Database\Seeders\ShowcaseKit;

use App\Files\AttachmentService;
use App\Files\Contracts\AttachmentOwner;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Files the dataset attaches: the showcase photos (WebP, committed under showcase/images) and small generated PDFs
 * standing in for transfer receipts, delivery notes and design sheets. Everything is stored through the private
 * attachments service; nothing is hot-linked.
 */
final class Assets
{
    /** @var array<string, list<int>> Pexels photo ids by subject (see images/CREDITS.md). */
    public const KITCHENS = [
        'warm' => [1080721, 4800189, 7167993, 1599791, 100575, 10153052, 4906250],
        'white' => [8134756, 12700379, 6283970, 6283972, 6538903, 7545855, 14569329, 20326399, 14495889, 35189706, 8583735, 6636288],
        'dark' => [8583896, 10099318, 6538939, 18285887, 35505452, 5998041],
        'island' => [534151, 29923543, 16056401, 3718434, 10117731, 6508354],
    ];

    public const HANDLES = [39443630, 4194847, 8650106];

    public const FAUCETS = [3847553, 3847579];

    public const DRAWERS = [7484801, 7482657, 1122160];

    public function __construct(private readonly bool $enabled) {}

    public static function dir(): string
    {
        return __DIR__.'/../showcase/images';
    }

    public function enabled(): bool
    {
        return $this->enabled && is_dir(self::dir());
    }

    public function image(int $pexelsId): ?UploadedFile
    {
        $path = self::dir().'/pexels-'.$pexelsId.'.webp';
        if (! $this->enabled() || ! is_file($path)) {
            return null;
        }

        return new UploadedFile($path, 'opsone-'.$pexelsId.'.webp', 'image/webp', null, true);
    }

    /**
     * Attaches a photo to a record through the attachments service; null when images are off.
     */
    public function attach(User $actor, Model&AttachmentOwner $owner, int $pexelsId, ?string $purpose = 'image'): ?Attachment
    {
        $file = $this->image($pexelsId);

        return $file === null ? null : app(AttachmentService::class)->store($actor, $owner, $file, $purpose);
    }

    public static function pdf(string $name, string $body = 'OpsOne'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ops');
        $text = preg_replace('/[^\x20-\x7E]/', '', $body) ?: 'OpsOne';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 144]>>endobj\n% {$text} ".Str::random(12)."\ntrailer<</Root 1 0 R>>\n%%EOF\n");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }
}
