<?php

namespace App\Agreements;

use App\Enums\DocumentKind;
use App\Models\Agreements\AgreementDocument;
use App\Models\Order;
use App\Models\User;
use App\Workflow\ActionResults;
use App\Workflow\Catalogue\ActionType;

/**
 * The agreement as result owner of the P5 actions (docs/p5-engine-api.md «For P6/P7»): a sent quote or contract
 * verifies «prepare a quote or contract» for that document, and the customer's approval of a design, quote or
 * contract verifies «approval» with that subject. The engine never infers an approval; a replayed signal is a no-op.
 */
final class AgreementSignals
{
    public const SOURCE = 'agreement_document';

    public function __construct(private readonly ActionResults $results) {}

    public function sent(Order $order, AgreementDocument $document, User $actor): void
    {
        if ($document->kind !== DocumentKind::Design) {
            $this->results->verifyFromSource($order, ActionType::PrepareDocument, $document->kind->value, self::SOURCE, $document->id, $actor);
        }
    }

    public function approved(Order $order, AgreementDocument $document, ?User $actor): void
    {
        $this->results->verifyFromSource($order, ActionType::CustomerApproval, $document->kind->value, self::SOURCE, $document->id, $actor);
    }
}
