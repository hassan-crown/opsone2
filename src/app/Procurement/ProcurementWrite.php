<?php

namespace App\Procurement;

use App\Models\User;
use App\Support\Idempotency;
use App\Support\LockingTransaction;
use Closure;

/**
 * Procurement writes run once per idempotency key: a double submit or a retry replays the first result and touches
 * nothing (a receipt never reaches the stock twice); the same key with another payload is refused (422). Callers
 * without a key (system) run in a LockingTransaction, which the gapless PO number needs.
 */
final class ProcurementWrite
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

        return $this->idempotency->run('procurement.'.$scope, $actor, $key, $payload, $work);
    }
}
