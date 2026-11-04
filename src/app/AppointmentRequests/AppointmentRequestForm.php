<?php

namespace App\AppointmentRequests;

use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\AppointmentRequestSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/**
 * The request form's rules from the owner's settings (ADR-0039): only the requestable kinds, each configurable field
 * off (dropped), optional or required; a preferred window from today up to 180 days ahead and at most 30 days long.
 * Name and phone are asked on the public site only (the workspace knows the customer).
 */
final class AppointmentRequestForm
{
    public const MAX_DAYS_AHEAD = 180;

    public const MAX_WINDOW_DAYS = 30;

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(AppointmentRequestSettings $settings, string $source): array
    {
        $today = CarbonImmutable::now(AppointmentRequests::timezone())->format('Y-m-d');
        $last = CarbonImmutable::now(AppointmentRequests::timezone())->addDays(self::MAX_DAYS_AHEAD)->format('Y-m-d');
        $presence = fn (string $field) => match ($settings->mode($field)) {
            AppointmentRequestSettings::REQUIRED => 'required',
            default => 'nullable',
        };

        $rules = [
            'kind' => ['required', 'string', 'in:'.implode(',', array_values(array_intersect($settings->kinds, Appointment::KINDS)))],
            'preferred_from' => [$presence('preferred_date'), 'date_format:Y-m-d', 'after_or_equal:'.$today, 'before_or_equal:'.$last],
            'preferred_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:preferred_from', 'before_or_equal:'.$last],
            'time_of_day' => [$presence('time_of_day'), 'string', 'in:'.implode(',', AppointmentRequest::TIMES_OF_DAY)],
            'city' => [$presence('city'), 'string', 'max:80'],
            'address' => [$presence('address'), 'string', 'max:500'],
            'note' => [$presence('note'), 'string', 'max:1000'],
        ];
        if ($source === AppointmentRequest::SOURCE_SITE) {
            $rules['name'] = ['required', 'string', 'max:120'];
            $rules['phone'] = ['required', 'string', 'max:30'];
        } else {
            $rules['order_id'] = ['nullable', 'integer'];
        }

        return $rules;
    }

    /**
     * Validates and keeps only the fields the settings show, trimmed; an empty optional field becomes null.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function validate(AppointmentRequestSettings $settings, string $source, array $input): array
    {
        foreach (AppointmentRequestSettings::FIELDS as $field) {
            if ($settings->mode($field) === AppointmentRequestSettings::OFF) {
                foreach ($field === 'preferred_date' ? ['preferred_from', 'preferred_to'] : [$field] as $key) {
                    unset($input[$key]);
                }
            }
        }
        $input = array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, $input);
        $data = Validator::make($input, self::rules($settings, $source))->after(function ($validator) use ($input) {
            $from = $input['preferred_from'] ?? null;
            $to = $input['preferred_to'] ?? null;
            if (is_string($from) && is_string($to) && $validator->errors()->isEmpty()
                && CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > self::MAX_WINDOW_DAYS) {
                $validator->errors()->add('preferred_to', __('appointment_requests.validation.window', ['days' => self::MAX_WINDOW_DAYS]));
            }
        })->validate();

        return $data + ['preferred_from' => null, 'preferred_to' => null, 'time_of_day' => null, 'city' => null, 'address' => null, 'note' => null];
    }
}
