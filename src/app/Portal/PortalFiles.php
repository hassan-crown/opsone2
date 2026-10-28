<?php

namespace App\Portal;

use App\Models\Attachment;
use App\Models\Customer;
use Illuminate\Support\Facades\URL;

/**
 * Customer file links (WEB-10): short-lived, signed and bound to the customer they were issued for. Like staff links
 * (ADR-0006) a link is not a grant: the download re-checks that the file's record is still the customer's.
 */
final class PortalFiles
{
    public static function url(Attachment $attachment, Customer $customer): string
    {
        return URL::temporarySignedRoute(
            'customer.files.download',
            now()->addMinutes((int) config('attachments.link_ttl_minutes', 5)),
            ['attachment' => $attachment->uuid, 'c' => $customer->id],
        );
    }
}
