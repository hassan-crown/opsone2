<?php

namespace App\Files\Contracts;

use App\Access\Contracts\ScopedRecord;
use App\Access\Permission;

/**
 * A record that can own private attachments. Access to its files follows the record: uploading needs the upload
 * permission on the record, and every download re-checks the view permission on the record at download time.
 */
interface AttachmentOwner extends ScopedRecord
{
    public function attachmentViewPermission(): Permission;

    public function attachmentUploadPermission(): Permission;
}
