<?php

namespace App\Actions\Rules;

use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\NumberFormat;
use App\Models\User;
use App\Numbering\NumberFormats;
use App\Support\RequestCache;
use App\Workflow\AppointmentBooking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Sets the format of one document sequence (P-104, ADR-0037). Only sequences in NumberFormats (never tax invoices);
 * a prefix unique among all sequences; a starting number above the counter's current value for this period — so
 * numbers stay unique and gapless from the start number on. Applies to the next number taken.
 */
final class SaveNumberFormat
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'sequence' => ['required', 'in:'.implode(',', array_keys(NumberFormats::SEQUENCES))],
            'prefix' => ['required', 'string', 'regex:/^[A-Z0-9]{1,8}$/'],
            'year_mode' => ['required', 'in:'.implode(',', NumberFormats::YEAR_MODES)],
            'per_branch' => ['required', 'boolean'],
            'pad' => ['required', 'integer', 'between:3,8'],
            'start_at' => ['nullable', 'integer', 'min:1', 'max:99999999'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, array $data, string $source = TransitionRecorder::SOURCE_WEB): NumberFormat
    {
        RulesAccess::authorize($actor);
        $data = Validator::make($data, self::rules())->validate();
        $sequence = (string) $data['sequence'];
        if ($data['per_branch'] && ! in_array($sequence, NumberFormats::BRANCHED, true)) {
            throw ValidationException::withMessages(['per_branch' => __('rules.numbering.validation.branch')]);
        }
        $prefix = (string) $data['prefix'];
        $others = collect(NumberFormats::SEQUENCES)->except($sequence)
            ->map(fn (array $d, string $name) => NumberFormat::query()->where('sequence', $name)->value('prefix') ?? $d['prefix']);
        if ($others->contains($prefix) || in_array($prefix, NumberFormats::RESERVED, true)) {
            throw ValidationException::withMessages(['prefix' => __('rules.numbering.validation.prefix_taken')]);
        }

        return DB::transaction(function () use ($actor, $data, $sequence, $prefix, $source) {
            $period = NumberFormats::period((string) $data['year_mode'], now(AppointmentBooking::timezone()));
            $start = $data['start_at'] === null ? null : (int) $data['start_at'];
            if ($start !== null) {
                $current = (int) DB::table('sequence_counters')->where('name', $sequence)->where('period', $period)->lockForUpdate()->value('last_value');
                if ($start <= $current) {
                    throw ValidationException::withMessages(['start_at' => __('rules.numbering.validation.start_below', ['current' => $current])]);
                }
            }
            $format = NumberFormat::query()->lockForUpdate()->firstOrNew(['sequence' => $sequence]);
            $before = $format->exists ? $format->only(['prefix', 'year_mode', 'per_branch', 'pad', 'start_at', 'start_period']) : [];
            $format->forceFill([
                'prefix' => $prefix, 'year_mode' => $data['year_mode'], 'per_branch' => (bool) $data['per_branch'], 'pad' => (int) $data['pad'],
                'start_at' => $start, 'start_period' => $start === null ? null : $period, 'updated_by' => $actor->id,
            ])->save();
            Audit::record(AuditAction::NumberFormatSaved, $format, $actor, before: $before,
                after: $format->only(['prefix', 'year_mode', 'per_branch', 'pad', 'start_at', 'start_period']), source: $source, label: $sequence);
            app(RequestCache::class)->flush();

            return $format;
        });
    }
}
