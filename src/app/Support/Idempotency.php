<?php

namespace App\Support;

use App\Models\IdempotencyKey;
use App\Models\User;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Runs a create action once per (scope, user, key). The key row is inserted in the same transaction as the work, so
 * a concurrent duplicate waits on the unique index and, once the first commits, replays its stored result instead
 * of creating again; if the first rolls back, the key is free again. Reusing a key for a different payload is refused.
 * The transaction is a LockingTransaction, so work that takes gapless numbers is safe on MariaDB.
 */
final class Idempotency
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  Closure(): array<string, mixed>  $work
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function run(string $scope, User $user, string $key, array $payload, Closure $work): array
    {
        $hash = hash('sha256', (string) json_encode(self::canonical($payload)));

        $existing = $this->find($scope, $user, $key);
        if ($existing !== null) {
            return $this->replay($existing, $hash);
        }

        try {
            return LockingTransaction::run(function () use ($scope, $user, $key, $hash, $work) {
                $row = IdempotencyKey::query()->create([
                    'scope' => $scope, 'user_id' => $user->id, 'key' => $key, 'request_hash' => $hash,
                ]);
                $result = $work();
                $row->forceFill(['result' => $result])->save();

                return ['result' => $result, 'replayed' => false];
            });
        } catch (UniqueConstraintViolationException $e) {
            $existing = $this->find($scope, $user, $key);
            if ($existing === null) {
                throw $e; // a unique key of the work itself, not the idempotency key
            }

            return $this->replay($existing, $hash);
        }
    }

    private function find(string $scope, User $user, string $key): ?IdempotencyKey
    {
        return IdempotencyKey::query()->where('scope', $scope)->where('user_id', $user->id)->where('key', $key)->first();
    }

    /**
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    private function replay(IdempotencyKey $existing, string $hash): array
    {
        if (! hash_equals($existing->request_hash, $hash) || $existing->result === null) {
            throw ValidationException::withMessages(['idempotency_key' => __('orders.validation.idempotency_reused')]);
        }

        return ['result' => $existing->result, 'replayed' => true];
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($v) => is_array($v) ? self::canonical($v) : $v, $value);
    }
}
