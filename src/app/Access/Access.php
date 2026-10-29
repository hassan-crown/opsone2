<?php

namespace App\Access;

use Illuminate\Support\Facades\Facade;

/**
 * `Access::can($user, 'P-xx', $record = null)` — entry point to the central Gate.
 *
 * @method static bool can(?\Illuminate\Contracts\Auth\Authenticatable $user, Permission|string $permission, ?object $record = null)
 * @method static void authorize(?\Illuminate\Contracts\Auth\Authenticatable $user, Permission|string $permission, ?object $record = null)
 * @method static AccessDecision inspect(?\Illuminate\Contracts\Auth\Authenticatable $user, Permission|string $permission, ?object $record = null)
 * @method static AccessDecision inspectForReading(?\Illuminate\Contracts\Auth\Authenticatable $user, Permission|string $permission, ?object $record = null)
 * @method static bool canRead(?\Illuminate\Contracts\Auth\Authenticatable $user, Permission|string $permission, ?object $record = null)
 * @method static list<Grant> grantsFor(\App\Models\User $user, Permission $permission, bool $reading = false)
 * @method static list<Grant> effectiveGrants(\App\Models\User $user)
 *
 * @see Authorizer
 */
final class Access extends Facade
{
    /**
     * The Authorizer is scoped (per request, per queued job): never keep one across forgetScopedInstances(), or a
     * long-running worker would decide with the first job's license and grant caches.
     */
    protected static $cached = false;

    protected static function getFacadeAccessor(): string
    {
        return Authorizer::class;
    }
}
