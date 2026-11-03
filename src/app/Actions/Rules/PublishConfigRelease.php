<?php

namespace App\Actions\Rules;

use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Configuration\ConfigCheck;
use App\Configuration\ConfigDiff;
use App\Configuration\ConfigReleases;
use App\Configuration\ConfigRestorer;
use App\Configuration\ConfigSnapshot;
use App\Models\ConfigRelease;
use App\Models\Flow;
use App\Models\User;
use App\Support\StaleRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publishes the draft business rules as a numbered release, rolls back to an older release, or discards the draft
 * (P-104, ADR-0040). The writer states the release it reviewed (`base`) and the draft it reviewed (`draft_hash`); a
 * newer release or a draft changed since then is a 409 conflict, so two owners never publish over each other and
 * nobody publishes what they did not see. Every outcome is audited with the change log.
 */
final class PublishConfigRelease
{
    public const MAX_NOTES = 1000;

    public function __construct(private readonly ConfigRestorer $restorer) {}

    public function publish(User $actor, ?string $notes, int $base, string $draftHash, string $source = TransitionRecorder::SOURCE_WEB): ConfigRelease
    {
        RulesAccess::authorize($actor);

        return DB::transaction(function () use ($actor, $notes, $base, $draftHash, $source) {
            $current = $this->lockCurrent($base);
            $draft = ConfigSnapshot::capture();
            if (ConfigSnapshot::hash($draft) !== $draftHash) {
                throw new StaleRecord($current->number ?? 0);
            }
            if ($current !== null && $current->snapshot_hash === $draftHash) {
                throw ValidationException::withMessages(['draft' => __('rules.releases.nothing')]);
            }

            return $this->write($actor, $current, $draft, $notes, null, AuditAction::ConfigReleasePublished, $source);
        });
    }

    /**
     * Publishes a copy of an older release as the next release. The draft becomes that copy; the older release and
     * everything published after it stay as they were.
     */
    public function rollback(User $actor, ConfigRelease $target, ?string $notes, int $base, string $source = TransitionRecorder::SOURCE_WEB): ConfigRelease
    {
        RulesAccess::authorize($actor);

        return DB::transaction(function () use ($actor, $target, $notes, $base, $source) {
            $current = $this->lockCurrent($base);
            if ($current === null || $current->id === $target->id || $current->snapshot_hash === $target->snapshot_hash) {
                throw ValidationException::withMessages(['release' => __('rules.releases.already_current')]);
            }
            $this->restorer->restore($target->snapshot, $actor);
            $draft = ConfigSnapshot::capture();

            return $this->write($actor, $current, $draft, $notes, $target, AuditAction::ConfigReleaseRolledBack, $source);
        });
    }

    /**
     * Puts the draft back to the current release (nothing is published).
     */
    public function discard(User $actor, int $base, string $source = TransitionRecorder::SOURCE_WEB): void
    {
        RulesAccess::authorize($actor);
        DB::transaction(function () use ($actor, $base, $source) {
            $current = $this->lockCurrent($base);
            if ($current === null) {
                throw ValidationException::withMessages(['draft' => __('rules.releases.nothing_to_discard')]);
            }
            $changes = ConfigDiff::between($current->snapshot, ConfigSnapshot::capture());
            if ($changes === []) {
                return;
            }
            $this->restorer->restore($current->snapshot, $actor);
            Audit::record(AuditAction::ConfigDraftDiscarded, $current, $actor, before: ['changes' => ConfigDiff::summary($changes)],
                after: ['release' => $current->number], source: $source, label: 'R'.$current->number);
        });
    }

    private function lockCurrent(int $base): ?ConfigRelease
    {
        $current = ConfigRelease::query()->lockForUpdate()->orderByDesc('number')->first();
        if (($current->number ?? 0) !== $base) {
            throw new StaleRecord($current->number ?? 0);
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function write(User $actor, ?ConfigRelease $current, array $draft, ?string $notes, ?ConfigRelease $from, AuditAction $action, string $source): ConfigRelease
    {
        $published = $current->snapshot ?? ConfigSnapshot::defaults();
        ConfigCheck::assert($draft, $published);
        $notes = $notes === null || trim($notes) === '' ? null : mb_substr(trim($notes), 0, self::MAX_NOTES);
        $changes = ConfigDiff::between($published, $draft);

        $release = ConfigRelease::query()->create([
            'number' => ($current->number ?? 0) + 1,
            'snapshot' => $draft,
            'snapshot_hash' => ConfigSnapshot::hash($draft),
            'flow_version_id' => Flow::current()?->published_version_id,
            'notes' => $notes,
            'restored_from_id' => $from?->id,
            'published_by' => $actor->id,
            'published_at' => now(),
        ]);
        Audit::record($action, $release, $actor,
            before: ['release' => $current?->number],
            after: ['release' => $release->number, 'restored_from' => $from?->number, 'hash' => $release->snapshot_hash, 'changes' => ConfigDiff::summary($changes), 'notes' => $notes],
            source: $source, label: 'R'.$release->number);
        ConfigReleases::forget();

        return $release;
    }
}
