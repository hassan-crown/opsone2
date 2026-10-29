<?php

namespace App\Support;

use stdClass;

/**
 * Large list props for Inertia (ADR-0027). Inertia walks every nested array of a prop looking for lazy/deferred
 * values, which cost ~130 ms for a 500-row calendar; a row that is already an object is passed through untouched
 * and serializes to the same JSON object. Only for rows of plain values (already shaped by a presenter).
 */
final class PropRows
{
    /**
     * @param  iterable<array<string, mixed>>  $rows
     * @return list<stdClass>
     */
    public static function of(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = (object) $row;
        }

        return $out;
    }
}
