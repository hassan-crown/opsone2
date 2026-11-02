<?php

namespace App\Actions\Rules;

use App\Access\TransitionRecorder;
use App\Agreements\DocumentClauses;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\Agreements\DocumentClause;
use App\Models\Agreements\DocumentClauseVersion;
use App\Models\User;
use App\Support\StaleRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Adds or edits a library clause (P-104, ADR-0037). A text change adds a new immutable version; documents already
 * prepared keep the version they copied. Placeholders come from DocumentClauses::VARIABLES only.
 */
final class SaveDocumentClause
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'key' => ['required', 'string', 'regex:/^[a-z0-9_]{1,30}$/'],
            'title' => ['required', 'array'],
            'title.ar' => ['required', 'string', 'max:120'],
            'title.en' => ['nullable', 'string', 'max:120'],
            'body' => ['required', 'array'],
            'body.ar' => ['required', 'string', 'max:3000'],
            'body.en' => ['nullable', 'string', 'max:3000'],
            'applies_to' => ['required', 'array', 'min:1'],
            'applies_to.*' => ['in:'.implode(',', DocumentClauses::KINDS), 'distinct'],
            'included_by_default' => ['required', 'boolean'],
            'position' => ['sometimes', 'integer', 'between:0,999'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, ?DocumentClause $clause, array $data, ?int $lockVersion, string $source = TransitionRecorder::SOURCE_WEB): DocumentClause
    {
        RulesAccess::authorize($actor);
        $data = Validator::make($data, self::rules())->validate();
        $title = ['ar' => trim((string) $data['title']['ar']), 'en' => self::optional($data['title']['en'] ?? null)];
        $body = ['ar' => trim((string) $data['body']['ar']), 'en' => self::optional($data['body']['en'] ?? null)];
        DocumentClauses::checkVariables($body['ar'], 'body.ar');
        DocumentClauses::checkVariables((string) $body['en'], 'body.en');

        return DB::transaction(function () use ($actor, $clause, $data, $title, $body, $lockVersion, $source) {
            if ($clause !== null) {
                $clause = DocumentClause::query()->lockForUpdate()->findOrFail($clause->id);
                if ($lockVersion !== null && $lockVersion !== $clause->lock_version) {
                    throw new StaleRecord($clause->lock_version);
                }
            } elseif (DocumentClause::query()->whereNull('archived_at')->count() >= DocumentClauses::MAX_ACTIVE) {
                throw ValidationException::withMessages(['key' => __('rules.clauses.validation.too_many', ['max' => DocumentClauses::MAX_ACTIVE])]);
            }
            if (DocumentClause::query()->where('key', $data['key'])->when($clause !== null, fn ($q) => $q->whereKeyNot($clause->id))->exists()) {
                throw ValidationException::withMessages(['key' => __('rules.clauses.validation.key_taken')]);
            }

            $before = $clause === null ? [] : self::snapshot($clause);
            $textChanged = $clause === null || $clause->title->toArray() !== $title || $clause->body->toArray() !== $body;
            $clause ??= new DocumentClause;
            $clause->forceFill([
                'key' => $data['key'], 'title' => $title, 'body' => $body, 'applies_to' => array_values($data['applies_to']),
                'included_by_default' => (bool) $data['included_by_default'], 'position' => (int) ($data['position'] ?? $clause->position ?? 0),
                'version' => $clause->exists ? $clause->version + ($textChanged ? 1 : 0) : 1,
                'lock_version' => $clause->exists ? $clause->lock_version + 1 : 1, 'updated_by' => $actor->id,
            ])->save();
            if ($textChanged) {
                DocumentClauseVersion::query()->create([
                    'document_clause_id' => $clause->id, 'version' => $clause->version, 'title' => $title, 'body' => $body,
                    'created_by' => $actor->id, 'created_at' => now(),
                ]);
            }
            Audit::record(AuditAction::DocumentClauseSaved, $clause, $actor, before: $before, after: self::snapshot($clause), source: $source, label: $clause->key);

            return $clause;
        });
    }

    public function archive(User $actor, DocumentClause $clause, string $source = TransitionRecorder::SOURCE_WEB): void
    {
        RulesAccess::authorize($actor);
        DB::transaction(function () use ($actor, $clause, $source) {
            $clause = DocumentClause::query()->lockForUpdate()->findOrFail($clause->id);
            if ($clause->archived_at !== null) {
                return;
            }
            $clause->forceFill(['archived_at' => now(), 'lock_version' => $clause->lock_version + 1, 'updated_by' => $actor->id])->save();
            Audit::record(AuditAction::DocumentClauseArchived, $clause, $actor, after: ['key' => $clause->key, 'version' => $clause->version], source: $source, label: $clause->key);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshot(DocumentClause $clause): array
    {
        return [
            'key' => $clause->key, 'version' => $clause->version, 'title' => $clause->title->toArray(), 'body' => $clause->body->toArray(),
            'applies_to' => $clause->applies_to, 'included_by_default' => $clause->included_by_default,
        ];
    }

    private static function optional(mixed $text): ?string
    {
        return is_string($text) && trim($text) !== '' ? trim($text) : null;
    }
}
