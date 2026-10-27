<?php

namespace App\Providers;

use App\Access\Authorizer;
use App\Access\Contracts\LicenseCapabilities;
use App\Access\LicensedCapabilities;
use App\Access\Permission;
use App\Access\SessionRevoker;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(LicenseCapabilities::class, LicensedCapabilities::class);
        $this->app->scoped(Authorizer::class);
        $this->app->singleton(SessionRevoker::class);
    }

    public function boot(): void
    {
        // Every matrix code is a Gate ability: $user->can('P-03', $branch), @can, `can:P-01` middleware,
        // $this->authorize('P-06', $membership). Other abilities fall through to policies.
        Gate::before(function ($user, string $ability, array $arguments): ?Response {
            if (Permission::tryFrom($ability) === null) {
                return null;
            }

            $record = $arguments[0] ?? null;

            return $this->app->make(Authorizer::class)
                ->inspect($user, $ability, is_object($record) ? $record : null)
                ->toResponse();
        });

        // Stamp each new session with the account's access version (EnsureAccessIsCurrent compares it).
        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User && request()->hasSession()) {
                request()->session()->put('access_version', (int) User::query()->whereKey($event->user->id)->value('access_version'));
            }
        });
    }
}
