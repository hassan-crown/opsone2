<?php

namespace App\Http\Middleware;

use App\Access\Access;
use App\Access\Permission;
use App\Notifications\NotificationFeed;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => app()->getLocale(),
            'dir' => SetLocale::direction(app()->getLocale()),
            'locales' => config('app.available_locales'),
            'translations' => $this->translations(),
            'auth' => [
                'user' => $request->user(),
            ],
            // Navigation entries the Gate allows; every route still enforces access server-side.
            'orgNav' => fn () => $this->orgNav($request),
            // Bell data (SHR-05); the client polls it with a partial reload instead of a socket (Q-26).
            'notifications' => fn () => app(NotificationFeed::class)->for($request->user()),
        ];
    }

    /**
     * JSON translations for the active locale, over the fallback locale.
     *
     * @return array<string, string>
     */
    private function translations(): array
    {
        $load = static function (string $locale): array {
            $path = lang_path($locale.'.json');

            return is_file($path) ? (array) json_decode((string) file_get_contents($path), true) : [];
        };

        return array_merge($load(config('app.fallback_locale')), $load(app()->getLocale()));
    }

    /**
     * @return array{team: bool, branches: bool, audit: bool}|null
     */
    private function orgNav(Request $request): ?array
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        return [
            'team' => Access::can($user, Permission::P04) || Access::can($user, Permission::P05) || Access::can($user, Permission::P06),
            'branches' => Access::can($user, Permission::P03),
            'audit' => Access::can($user, Permission::P63),
        ];
    }
}
