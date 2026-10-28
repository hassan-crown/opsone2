<?php

namespace App\Actions\StateMachines\M40Document;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Enums\ExternalDocumentStatus;
use App\Enums\StateMachine;
use App\Finance\FinanceLock;
use App\Models\Finance\ExternalDocument;
use App\Models\Order;
use App\Models\User;
use App\Support\StaleRecord;

/**
 * Shared steps of an M-40 transition (P-44): lock the order and its finance head, authorize, lock the document, check
 * its version and its state; then move it and record the transition.
 */
final class DocumentStep
{
    public static function order(User $actor, int $orderId, string $code): Order
    {
        $order = FinanceLock::order($orderId, StateMachine::M40, $code);
        Access::authorize($actor, Permission::P44, $order);
        FinanceLock::touch(FinanceLock::head($order));

        return $order;
    }

    /**
     * @return array{0: Order, 1: ExternalDocument}
     */
    public static function lock(User $actor, ExternalDocument $document, ?int $lockVersion, string $code): array
    {
        $order = self::order($actor, $document->order_id, $code);
        $fresh = ExternalDocument::query()->lockForUpdate()->findOrFail($document->id);
        if ($lockVersion !== null && $fresh->lock_version !== $lockVersion) {
            throw new StaleRecord($fresh->lock_version);
        }
        if (! in_array($fresh->status, ExternalDocumentStatus::transitions()[$code]['from'], true)) {
            throw new InvalidTransition(StateMachine::M40, $code, 'not_from_'.$fresh->status->value);
        }
        $fresh->setRelation('order', $order);

        return [$order, $fresh];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public static function move(TransitionRecorder $transitions, ExternalDocument $document, string $code, array $fields, User $actor, string $source, ?string $reason = null): void
    {
        $from = $document->exists ? $document->status : null;
        $to = ExternalDocumentStatus::transitions()[$code]['to'];
        $document->forceFill([...$fields, 'status' => $to, 'lock_version' => $document->exists ? $document->lock_version + 1 : 1])->save();
        $transitions->record(StateMachine::M40, $code, $document, $from, $to, $actor, $source, $reason);
    }
}
