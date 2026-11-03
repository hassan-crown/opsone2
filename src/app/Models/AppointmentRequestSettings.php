<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What customers may request (ADR-0039, P-104): the site and workspace switches, the requestable kinds, each optional
 * form field as off / optional / required, and the assignee's response time in hours (null = no SLA). One row;
 * current() falls back to the defaults.
 *
 * @property bool $site_enabled
 * @property bool $portal_enabled
 * @property list<string> $kinds
 * @property array<string, string> $fields
 * @property int|null $response_hours
 * @property int $lock_version
 * @property int|null $updated_by
 */
class AppointmentRequestSettings extends Model
{
    public const OFF = 'off';

    public const OPTIONAL = 'optional';

    public const REQUIRED = 'required';

    public const MODES = [self::OFF, self::OPTIONAL, self::REQUIRED];

    /** The configurable fields; name and phone are always required (staff must be able to call back). */
    public const FIELDS = ['preferred_date', 'time_of_day', 'city', 'address', 'note'];

    public const DEFAULTS = [
        'site_enabled' => true,
        'portal_enabled' => true,
        'kinds' => ['measurement', 'visit'],
        'fields' => [
            'preferred_date' => self::OPTIONAL,
            'time_of_day' => self::OPTIONAL,
            'city' => self::REQUIRED,
            'address' => self::OPTIONAL,
            'note' => self::OPTIONAL,
        ],
        'response_hours' => 24,
    ];

    protected $table = 'appointment_request_settings';

    protected $guarded = ['id', 'lock_version'];

    protected $attributes = ['lock_version' => 1];

    public static function current(): self
    {
        return self::query()->orderBy('id')->first() ?? new self(self::DEFAULTS);
    }

    public function mode(string $field): string
    {
        $mode = $this->fields[$field] ?? self::DEFAULTS['fields'][$field] ?? self::OFF;

        return in_array($mode, self::MODES, true) ? $mode : self::OFF;
    }

    public function allowsKind(string $kind): bool
    {
        return in_array($kind, $this->kinds, true);
    }

    public function enabledFor(string $source): bool
    {
        return ($source === AppointmentRequest::SOURCE_SITE ? $this->site_enabled : $this->portal_enabled) && $this->kinds !== [];
    }

    protected function casts(): array
    {
        return [
            'site_enabled' => 'boolean',
            'portal_enabled' => 'boolean',
            'kinds' => 'array',
            'fields' => 'array',
            'response_hours' => 'integer',
            'lock_version' => 'integer',
        ];
    }
}
