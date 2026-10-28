<?php

namespace App\Cancellation;

use App\Access\Access;
use App\Access\Permission;
use App\Models\Cancellation\OrderCancellation;
use App\Models\Order;
use App\Models\User;
use App\Support\Idempotency;
use App\Support\LockingTransaction;
use App\Workflow\WorkGuard;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Envelope of the cancellation writes: idempotency (scope `cancellation.*`, a replay returns the known decision —
 * M-34-T06) and the request fields every decision records (SRS-COM-14: source, date, reason).
 */
final class CancellationWrite
{
    public function __construct(private readonly Idempotency $idempotency) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  Closure(): array<string, mixed>  $work
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function run(string $scope, User $actor, ?string $key, array $payload, Closure $work): array
    {
        if ($key === null) {
            return ['result' => LockingTransaction::run($work), 'replayed' => false];
        }

        return $this->idempotency->run('cancellation.'.$scope, $actor, $key, $payload, $work);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{source: string, channel: string|null, requested_on: string, reason: string}
     */
    public static function request(array $data): array
    {
        $source = (string) ($data['source'] ?? OrderCancellation::SOURCE_EXTERNAL);
        if (! in_array($source, [OrderCancellation::SOURCE_EXTERNAL, OrderCancellation::SOURCE_ACCOUNT], true)) {
            throw ValidationException::withMessages(['source' => __('cancel.validation.source')]);
        }
        $channel = isset($data['channel']) && $data['channel'] !== '' ? (string) $data['channel'] : null;
        if ($source === OrderCancellation::SOURCE_EXTERNAL && $channel === null) {
            throw ValidationException::withMessages(['channel' => __('cancel.validation.channel')]);
        }
        $on = (string) ($data['requested_on'] ?? now()->toDateString());
        if ($on > now()->toDateString()) {
            throw ValidationException::withMessages(['requested_on' => __('cancel.validation.future')]);
        }

        return ['source' => $source, 'channel' => $channel, 'requested_on' => $on, 'reason' => WorkGuard::reason(isset($data['reason']) ? (string) $data['reason'] : null)];
    }

    /**
     * The value kept on a full cancellation is an amount: only a viewer of the order's money may set it (P-37).
     */
    public static function authorizeAmounts(User $actor, Order $order, CancellationInput $input): void
    {
        if ($input->retainedMinor !== null && ! Access::can($actor, Permission::P37, $order)) {
            throw new AuthorizationException(__('access.denied.no_grant'));
        }
    }
}
