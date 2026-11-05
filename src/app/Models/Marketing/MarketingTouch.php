<?php

namespace App\Models\Marketing;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Where a record came from (ADR-0045): `first` and `last` touch of the visitor who created it. Append-only.
 *
 * @property int $id
 * @property string $touchable_type
 * @property int $touchable_id
 * @property string $kind
 * @property string $source
 * @property string $medium
 * @property string|null $campaign
 * @property string|null $term
 * @property string|null $content
 * @property string|null $click_id_type
 * @property string|null $click_id
 * @property string|null $referrer_host
 * @property string|null $landing_path
 * @property CarbonInterface|null $touched_at
 */
class MarketingTouch extends Model
{
    public const FIRST = 'first';

    public const LAST = 'last';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /** @return MorphTo<Model, $this> */
    public function touchable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * What staff screens show: «google / cpc · eid-sale».
     *
     * @return array{source: string, medium: string, campaign: string|null, landing_path: string|null, touched_at: string|null}
     */
    public function summary(): array
    {
        return [
            'source' => $this->source, 'medium' => $this->medium, 'campaign' => $this->campaign,
            'landing_path' => $this->landing_path, 'touched_at' => $this->touched_at?->toIso8601String(),
        ];
    }

    protected function casts(): array
    {
        return ['touched_at' => 'datetime'];
    }
}
