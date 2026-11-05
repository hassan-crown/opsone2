<?php

namespace App\Models\Marketing;

use App\Support\RequestCache;
use App\Support\Translatable\TranslatableString;
use App\Support\Translatable\Translated;
use Illuminate\Database\Eloquent\Model;

/**
 * The facility's marketing settings (ADR-0045, one row, P-107): tag IDs only — validated by format, never free code —,
 * the WhatsApp button and the public links. Read on every public page, so once per request.
 *
 * @property int $id
 * @property string|null $gtm_id
 * @property string|null $ga4_id
 * @property string|null $meta_pixel_id
 * @property string|null $tiktok_pixel_id
 * @property string|null $snap_pixel_id
 * @property string|null $google_ads_id
 * @property string|null $google_ads_purchase_label
 * @property string|null $google_ads_lead_label
 * @property string|null $whatsapp_number
 * @property Translated $whatsapp_message
 * @property array<string, string>|null $links
 * @property int $lock_version
 */
class MarketingSettings extends Model
{
    /** ID formats (P-107 form and the page loader agree on these). */
    public const FORMATS = [
        'gtm_id' => '/^GTM-[A-Z0-9]{4,12}$/',
        'ga4_id' => '/^G-[A-Z0-9]{4,15}$/',
        'meta_pixel_id' => '/^[0-9]{8,20}$/',
        'tiktok_pixel_id' => '/^[A-Z0-9]{8,30}$/',
        'snap_pixel_id' => '/^[a-f0-9-]{20,40}$/',
        'google_ads_id' => '/^AW-[0-9]{6,15}$/',
        'google_ads_purchase_label' => '/^[A-Za-z0-9_-]{4,40}$/',
        'google_ads_lead_label' => '/^[A-Za-z0-9_-]{4,40}$/',
    ];

    /** Public links: key => host suffixes a link may point to (null = any https host). */
    public const LINKS = [
        'instagram' => ['instagram.com'],
        'x' => ['x.com', 'twitter.com'],
        'tiktok' => ['tiktok.com'],
        'snapchat' => ['snapchat.com'],
        'youtube' => ['youtube.com', 'youtu.be'],
        'linkedin' => ['linkedin.com'],
        'facebook' => ['facebook.com', 'fb.com'],
        'google_business' => ['google.com', 'goo.gl', 'g.page'],
        'google_review' => ['google.com', 'goo.gl', 'g.page'],
    ];

    protected $table = 'marketing_settings';

    protected $guarded = ['id'];

    public static function current(): self
    {
        return app(RequestCache::class)->remember('marketing.settings', fn () => self::query()->orderBy('id')->first() ?? new self);
    }

    /** Is any analytics or advertising tag configured (so the consent banner is needed)? */
    public function tracks(): bool
    {
        return $this->gtm_id !== null || $this->ga4_id !== null || $this->meta_pixel_id !== null
            || $this->tiktok_pixel_id !== null || $this->snap_pixel_id !== null || $this->google_ads_id !== null;
    }

    /**
     * What the page's loader needs (no secret: these IDs are public in any page that uses them).
     *
     * @return array<string, string|null>
     */
    public function tags(): array
    {
        return [
            'gtm' => $this->gtm_id, 'ga4' => $this->ga4_id, 'meta' => $this->meta_pixel_id, 'tiktok' => $this->tiktok_pixel_id,
            'snap' => $this->snap_pixel_id, 'ads' => $this->google_ads_id,
            'ads_purchase' => $this->google_ads_purchase_label, 'ads_lead' => $this->google_ads_lead_label,
        ];
    }

    /** `https://wa.me/9665…?text=…` with the greeting of the language, or null without a number. */
    public function whatsappUrl(string $locale): ?string
    {
        if ($this->whatsapp_number === null) {
            return null;
        }
        $text = (string) $this->whatsapp_message->only($locale);

        return 'https://wa.me/'.$this->whatsapp_number.($text !== '' ? '?text='.rawurlencode($text) : '');
    }

    /**
     * Public profile links (JSON-LD `sameAs`, footer), Google review link excluded.
     *
     * @return array<string, string>
     */
    public function profiles(): array
    {
        return array_filter(array_diff_key($this->links ?? [], ['google_review' => true]), fn ($v) => is_string($v) && $v !== '');
    }

    protected function casts(): array
    {
        return ['whatsapp_message' => TranslatableString::class, 'links' => 'array'];
    }
}
