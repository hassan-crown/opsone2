<?php

namespace App\Finance;

use App\Models\User;
use App\Support\Idempotency;
use App\Support\LockingTransaction;
use Closure;

/**
 * Money writes run once per idempotency key: a double submit or a retry replays the first result; the same key with
 * another payload is refused (422). System callers without a key run in a LockingTransaction (gapless receipts).
 */
final class FinanceWrite
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

        return $this->idempotency->run('finance.'.$scope, $actor, $key, $payload, $work);
    }
}
