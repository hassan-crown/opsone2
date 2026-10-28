<?php

namespace App\Agreements;

use App\Enums\DocumentKind;
use App\Support\GaplessSequence;

/**
 * Gapless yearly numbers for agreement records: QUO/DSN/CON-YYYY-00001 per document kind, CHG-YYYY-00001 for changes.
 */
final class Numbers
{
    public function __construct(private readonly GaplessSequence $sequence) {}

    public function document(DocumentKind $kind): string
    {
        return $this->sequence->format('agreement_'.$kind->value, $kind->numberPrefix());
    }

    public function change(): string
    {
        return $this->sequence->format('agreement_change', 'CHG');
    }
}
