<?php

namespace App\Agreements;

use App\Models\User;
use App\Support\Idempotency;
use App\Support\LockingTransaction;
use Closure;

/**
 * Money-affecting agreement writes run once per idempotency key (backend rule: idempotency for every money action):
 * a double submit or a retry replays the first result; the same key with another payload is refused (422). Without
 * a key (system callers) the work simply runs in a LockingTransaction.
 */
final class AgreementWrite
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

        return $this->idempotency->run('agreement.'.$scope, $actor, $key, $payload, $work);
    }
}
