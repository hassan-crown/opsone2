<?php

namespace App\Catalog;

use App\Access\Authorizer;
use App\Access\Permission;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\Catalog\CatalogItem;
use App\Models\Catalog\CatalogVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The one write path of the catalog: authorize on the record, change it, append a version when its specification
 * changed, and audit — all in one transaction. Rows are never deleted; disabling only flips the status.
 */
final class CatalogWriter
{
    public function __construct(private readonly Authorizer $access) {}

    /**
     * Create or update a record. An update that changes nothing writes no version and no audit row.
     *
     * @template T of Model&CatalogItem
     *
     * @param  T  $record
     * @param  array<string, mixed>  $attributes
     * @return T
     */
    public function save(User $actor, Permission $permission, Model&CatalogItem $record, array $attributes, AuditAction $created, AuditAction $updated): Model
    {
        $this->access->authorize($actor, $permission, $record);

        return DB::transaction(function () use ($actor, $record, $attributes, $created, $updated) {
            if (! $record->exists) {
                $record->fill($attributes)->save();
                $this->version($record, $actor);
                Audit::record($created, $record, $actor, after: $record->auditSnapshot(), label: $this->label($record));

                return $record;
            }

            $before = $record->versionPayload();
            $auditBefore = $record->auditSnapshot();
            $record->fill($attributes);
            $after = $record->versionPayload();
            $auditAfter = $record->auditSnapshot();

            if ($auditBefore === $auditAfter) {
                return $record;
            }

            $versioned = $before !== $after;
            if ($versioned) {
                $record->nextVersion();
            }
            $record->save();

            if ($versioned) {
                $this->version($record, $actor);
            }
            Audit::record($updated, $record, $actor, before: $auditBefore, after: $auditAfter, label: $this->label($record));

            return $record;
        });
    }

    /**
     * Disable, enable, publish: only the status changes, so the specification version stays the same.
     */
    public function setStatus(User $actor, Permission $permission, Model&CatalogItem $record, CatalogStatus $to, AuditAction $action): Model
    {
        $this->access->authorize($actor, $permission, $record);

        return DB::transaction(function () use ($actor, $record, $to, $action) {
            $from = $record->catalogStatus();
            if ($from === $to) {
                return $record;
            }

            $record->changeStatus($to);
            $record->save();

            Audit::record($action, $record, $actor, before: ['status' => $from->value], after: ['status' => $to->value], label: $this->label($record));

            return $record;
        });
    }

    private function version(Model&CatalogItem $record, User $actor): void
    {
        CatalogVersion::query()->create([
            'versionable_type' => $record->getMorphClass(),
            'versionable_id' => $record->getKey(),
            'version' => $record->versionNumber(),
            'payload' => $record->versionPayload(),
            'created_by_user_id' => $actor->id,
        ]);
    }

    private function label(Model&CatalogItem $record): string
    {
        return $record->displayName();
    }
}
