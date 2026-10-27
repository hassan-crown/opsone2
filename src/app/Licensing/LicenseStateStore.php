<?php

namespace App\Licensing;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * Keeps the last trusted license state in the cache, backed by the `license_states` row
 * so it survives a cache flush (ADR-0004 §4).
 */
final readonly class LicenseStateStore
{
    private const int ROW_ID = 1;

    public function __construct(
        private Cache $cache,
        private ConnectionInterface $db,
        private string $cacheKey = 'license.state',
    ) {}

    public function get(): ?LicenseState
    {
        $cached = $this->cache->get($this->cacheKey);

        if (is_array($cached) && ($state = LicenseState::fromArray($cached)) !== null) {
            return $state;
        }

        $row = $this->db->table('license_states')->where('id', self::ROW_ID)->first();

        if ($row === null) {
            return null;
        }

        $state = LicenseState::fromArray((array) $row);

        if ($state !== null) {
            $this->cache->forever($this->cacheKey, $state->toArray());
        }

        return $state;
    }

    public function put(LicenseState $state): void
    {
        $data = $state->toArray();

        $this->db->table('license_states')->updateOrInsert(['id' => self::ROW_ID], [
            ...$data,
            'verified_at' => $state->verifiedAt?->toDateTimeString(),
            'last_seen_at' => $state->lastSeenAt->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $this->cache->forever($this->cacheKey, $data);
    }
}
