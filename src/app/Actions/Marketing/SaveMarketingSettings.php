<?php

namespace App\Actions\Marketing;

use App\Access\Access;
use App\Access\Permission;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Customers\Phone;
use App\Models\Marketing\MarketingSettings;
use App\Models\User;
use App\Support\RequestCache;
use App\Support\StaleRecord;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * P-107 (ADR-0045): saves the tag IDs, the WhatsApp button and the public links. Every ID must match its provider's
 * format and every link must be https on the expected host, so nothing typed here can inject code into the facility's
 * pages. Optimistic lock + audit with before / after (none of these values is a secret).
 */
final class SaveMarketingSettings
{
    private const FIELDS = [
        'gtm_id', 'ga4_id', 'meta_pixel_id', 'tiktok_pixel_id', 'snap_pixel_id', 'google_ads_id',
        'google_ads_purchase_label', 'google_ads_lead_label', 'whatsapp_number', 'whatsapp_message', 'links',
    ];

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, array $input, ?int $lockVersion): MarketingSettings
    {
        Access::authorize($actor, Permission::P107);
        $data = $this->validate($input);

        return DB::transaction(function () use ($actor, $data, $lockVersion) {
            $settings = MarketingSettings::query()->lockForUpdate()->orderBy('id')->first() ?? new MarketingSettings;
            if ($settings->exists && $lockVersion !== null && $settings->lock_version !== $lockVersion) {
                throw new StaleRecord($settings->lock_version);
            }
            $before = $settings->exists ? $this->snapshot($settings) : [];
            $settings->fill($data + ['updated_by' => $actor->id]);
            $settings->lock_version = $settings->exists ? $settings->lock_version + 1 : 1;
            $settings->save();
            Audit::record(AuditAction::MarketingSettingsUpdated, $settings, $actor, before: $before, after: $this->snapshot($settings), label: 'Marketing');
            app(RequestCache::class)->flush();

            return $settings;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validate(array $input): array
    {
        // IDs are compared upper-case (as the providers print them), except Snap's lower-case UUID and Ads labels.
        foreach (['gtm_id', 'ga4_id', 'tiktok_pixel_id', 'google_ads_id'] as $key) {
            if (is_string($input[$key] ?? null)) {
                $input[$key] = strtoupper(trim($input[$key]));
            }
        }
        if (is_string($input['snap_pixel_id'] ?? null)) {
            $input['snap_pixel_id'] = strtolower(trim($input['snap_pixel_id']));
        }

        $rules = [];
        foreach (MarketingSettings::FORMATS as $key => $pattern) {
            $rules[$key] = ['nullable', 'string', 'regex:'.$pattern];
        }
        $rules['whatsapp_number'] = ['nullable', 'string', 'max:20', $this->phoneRule()];
        $rules['whatsapp_message'] = ['nullable', 'array'];
        $rules['whatsapp_message.ar'] = ['nullable', 'string', 'max:300'];
        $rules['whatsapp_message.en'] = ['nullable', 'string', 'max:300'];
        $rules['links'] = ['nullable', 'array'];
        foreach (MarketingSettings::LINKS as $key => $hosts) {
            $rules["links.$key"] = ['nullable', 'string', 'max:300', 'url:https', $this->hostRule($hosts)];
        }
        $data = Validator::make($input, $rules, [], $this->attributes())->validate();

        $out = [];
        foreach (self::FIELDS as $key) {
            $value = $data[$key] ?? null;
            $out[$key] = is_string($value) && trim($value) === '' ? null : $value;
        }
        $out['whatsapp_number'] = $out['whatsapp_number'] === null ? null : ltrim((string) Phone::normalize((string) $out['whatsapp_number']), '+');
        $out['whatsapp_message'] = ['ar' => $this->clean($data['whatsapp_message']['ar'] ?? null), 'en' => $this->clean($data['whatsapp_message']['en'] ?? null)];
        $links = [];
        foreach (array_keys(MarketingSettings::LINKS) as $key) {
            if (($url = $this->clean($data['links'][$key] ?? null)) !== null) {
                $links[$key] = $url;
            }
        }
        $out['links'] = $links;

        return $out;
    }

    private function phoneRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && trim($value) !== '' && Phone::normalize($value) === null) {
                $fail(__('marketing.validation.whatsapp'));
            }
        };
    }

    /**
     * @param  list<string>  $hosts
     */
    private function hostRule(array $hosts): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($hosts): void {
            $host = strtolower((string) parse_url((string) $value, PHP_URL_HOST));
            if ($value !== null && $value !== '' && ! collect($hosts)->contains(fn (string $h) => $host === $h || str_ends_with($host, '.'.$h))) {
                $fail(__('marketing.validation.link_host', ['hosts' => implode(', ', $hosts)]));
            }
        };
    }

    private function clean(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(MarketingSettings $s): array
    {
        return [...$s->only(array_diff(self::FIELDS, ['whatsapp_message'])), 'whatsapp_message' => $s->whatsapp_message->toArray()];
    }

    /**
     * @return array<string, string>
     */
    private function attributes(): array
    {
        $out = [];
        foreach (array_keys(MarketingSettings::FORMATS) as $key) {
            $out[$key] = __('marketing.field.'.$key);
        }

        return $out;
    }
}
