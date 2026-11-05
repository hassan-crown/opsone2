<?php

/**
 * Shared helpers of the marketing readiness tests (ADR-0045).
 */

/** @return list<array<string, mixed>> */
function jsonLdBlocks(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    return array_map(fn ($j) => json_decode($j, true, flags: JSON_THROW_ON_ERROR), $m[1]);
}
