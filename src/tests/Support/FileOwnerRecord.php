<?php

namespace Tests\Support;

use App\Access\Permission;
use App\Files\Contracts\AttachmentOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A minimal branch-scoped record that owns attachments, standing in for the domain records (orders, tickets, ...)
 * that will own files from P4 on. Access to its files follows P-03 on its branch.
 *
 * @property int $id
 * @property int $branch_id
 */
final class FileOwnerRecord extends Model implements AttachmentOwner
{
    protected $table = 'test_file_owners';

    protected $guarded = [];

    public $timestamps = false;

    public static function createTable(): void
    {
        Schema::create('test_file_owners', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
        });
    }

    public function attachmentViewPermission(): Permission
    {
        return Permission::P03;
    }

    public function attachmentUploadPermission(): Permission
    {
        return Permission::P03;
    }

    public function accessBranchIds(): array
    {
        return [$this->branch_id];
    }

    public function accessDepartmentId(): ?int
    {
        return null;
    }

    public function accessAssigneeIds(): array
    {
        return [];
    }

    public function accessOwnerId(): ?int
    {
        return null;
    }
}
