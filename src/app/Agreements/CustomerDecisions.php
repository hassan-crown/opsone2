<?php

namespace App\Agreements;

use App\Models\Agreements\Agreement;
use App\Models\Agreements\AgreementChange;
use App\Models\Agreements\AgreementDocument;
use App\Models\Agreements\CustomerDecision;
use App\Support\LockingTransaction;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Decisions a customer takes from their own account (P-67, AGR-04/09): approve or ask for changes on the exact content
 * shown. For the customer portal (P8), which authenticates the customer and checks access to the order before calling;
 * this service re-checks that the order is theirs. Approval is recorded with time and mobile and replaces a signature
 * (Q-40 a). A replaced or expired document, changed content or a repeated approval is refused without effect.
 */
final class CustomerDecisions
{
    public const SOURCE = 'customer';

    public function __construct(
        private readonly DocumentApproval $documents,
        private readonly ChangeApplication $changes,
    ) {}

    public function approveDocument(CustomerActor $customer, AgreementDocument $document, string $contentHash): CustomerDecision
    {
        return LockingTransaction::run(function () use ($customer, $document, $contentHash) {
            [$order, $document] = AgreementLock::document($document, null, 'T02');
            self::ensureOwn($customer, $order->customer_id);
            DocumentApproval::ensureDecidable($document, $contentHash, 'T02');

            return $this->documents->approve($order, $document, self::how($customer, null), null, self::SOURCE);
        });
    }

    public function requestDocumentChanges(CustomerActor $customer, AgreementDocument $document, string $contentHash, string $note): CustomerDecision
    {
        $note = self::note($note);

        return LockingTransaction::run(function () use ($customer, $document, $contentHash, $note) {
            [$order, $document] = AgreementLock::document($document, null, 'T03');
            self::ensureOwn($customer, $order->customer_id);
            DocumentApproval::ensureDecidable($document, $contentHash, 'T03');

            return $this->documents->requestChanges($order, $document, self::how($customer, $note), null, self::SOURCE);
        });
    }

    public function approveChange(CustomerActor $customer, AgreementChange $change, string $contentHash): Agreement
    {
        return LockingTransaction::run(function () use ($customer, $change, $contentHash) {
            [$order, $change] = AgreementLock::change($change, null, 'T04');
            self::ensureOwn($customer, $order->customer_id);
            ChangeApplication::ensureDecidable($change, $contentHash, 'T04');

            return $this->changes->apply($order, $change, self::how($customer, null), null, self::SOURCE);
        });
    }

    public function requestChangeRevision(CustomerActor $customer, AgreementChange $change, string $contentHash, string $note): void
    {
        $note = self::note($note);
        LockingTransaction::run(function () use ($customer, $change, $contentHash, $note) {
            [$order, $change] = AgreementLock::change($change, null, 'T02');
            self::ensureOwn($customer, $order->customer_id);
            ChangeApplication::ensureDecidable($change, $contentHash, 'T02');
            $this->changes->requestRevision($order, $change, $note, null, self::SOURCE);
        });
    }

    private static function ensureOwn(CustomerActor $customer, int $orderCustomerId): void
    {
        if ($customer->customerId !== $orderCustomerId) {
            throw new AuthorizationException;
        }
    }

    /**
     * @return array{method: string, decided_at: CarbonInterface, customer_mobile: string, note: string|null}
     */
    private static function how(CustomerActor $customer, ?string $note): array
    {
        return ['method' => CustomerDecision::METHOD_ACCOUNT, 'decided_at' => now(), 'customer_mobile' => $customer->mobile, 'note' => $note];
    }

    private static function note(string $note): string
    {
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 1000) {
            throw ValidationException::withMessages(['note' => __('agreements.validation.note')]);
        }

        return $note;
    }
}
