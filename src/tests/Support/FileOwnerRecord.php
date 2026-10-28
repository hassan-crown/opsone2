<?php

namespace Tests\Support;

use App\Access\Permission;
use App\Files\Contracts\AttachmentOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
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

    /**
     * A TEMPORARY table: on MariaDB/MySQL a plain CREATE TABLE inside the test's transaction commits it implicitly,
     * which destroys the savepoints `DB::transaction()` relies on (a later rollback fails with "SAVEPOINT does not
     * exist"). Temporary tables do not commit, and a later test on the same connection reuses the one that is left.
     */
    public static function createTable(): void
    {
        try {
            self::query()->delete();

            return;
        } catch (QueryException) {
            // not created yet on this connection
        }

        Schema::create('test_file_owners', function (Blueprint $table) {
            $table->temporary();
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
