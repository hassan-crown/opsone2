<?php

namespace App\Customers;

use App\Enums\CustomerKind;
use App\Models\Customer;

/**
 * Canonical form of customer input: E.164 mobile, lowercase email, business fields only for organizations.
 * Used by the Form Requests (so uniqueness compares canonical values) and by the actions.
 */
final class CustomerData
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        $data = $input;

        if (array_key_exists('mobile', $data)) {
            $data['mobile'] = is_string($data['mobile']) ? (Phone::normalize($data['mobile']) ?? trim($data['mobile'])) : $data['mobile'];
        }
        if (array_key_exists('office_phone', $data)) {
            $data['office_phone'] = is_string($data['office_phone']) && trim($data['office_phone']) !== ''
                ? (Phone::normalize($data['office_phone'], false) ?? trim($data['office_phone']))
                : null;
        }
        if (array_key_exists('email', $data)) {
            $email = is_string($data['email']) ? mb_strtolower(trim($data['email'])) : null;
            $data['email'] = $email === '' ? null : $email;
        }
        foreach (['commercial_registration', 'vat_number', 'internal_notes', 'preferred_channel'] as $key) {
            if (array_key_exists($key, $data)) {
                $value = is_string($data[$key]) ? trim($data[$key]) : $data[$key];
                $data[$key] = $value === '' ? null : $value;
            }
        }

        if (($data['kind'] ?? null) === CustomerKind::Person->value) {
            foreach (['commercial_registration', 'vat_number', 'office_phone'] as $key) {
                $data[$key] = null;
            }
        }

        return $data;
    }

    /**
     * Audit-safe view of customer attributes: sensitive values are replaced by a marker.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function forAudit(array $attributes): array
    {
        foreach (Customer::SENSITIVE as $key) {
            if (array_key_exists($key, $attributes) && $attributes[$key] !== null) {
                $attributes[$key] = '[redacted]';
            }
        }

        return $attributes;
    }
}
