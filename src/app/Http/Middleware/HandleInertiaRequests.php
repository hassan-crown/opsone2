<?php

namespace App\Http\Middleware;

use App\Access\Access;
use App\Access\Permission;
use App\Delivery\DeliveryAccess;
use App\Inventory\StockAccess;
use App\Notifications\NotificationFeed;
use App\Returns\ReturnAccess;
use App\Support\TranslationsVersion;
use App\Tickets\TicketAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
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
            // ~330 KB of strings: sent once per locale and version, then the client keeps them (ADR-0027).
            'translations' => Inertia::once(fn () => $this->translations())->as(TranslationsVersion::key('translations')),
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
     * @return array{team: bool, branches: bool, audit: bool, customers: bool, catalog: bool, orders: bool, flow: bool, tasks: bool, calendar: bool, finance: bool, commissions: bool, reports: bool, procurement: bool}|null
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
            'customers' => Access::can($user, Permission::P09) || Access::can($user, Permission::P10),
            'catalog' => Access::can($user, Permission::P11) || Access::can($user, Permission::P12) || Access::can($user, Permission::P13),
            'orders' => Access::can($user, Permission::P15) || Access::can($user, Permission::P16),
            'flow' => Access::can($user, Permission::P20),
            'tasks' => Access::can($user, Permission::P24) || Access::can($user, Permission::P22),
            'calendar' => Access::can($user, Permission::P54) || Access::can($user, Permission::P24),
            'finance' => Access::can($user, Permission::P37),
            'commissions' => Access::can($user, Permission::P94),
            'reports' => Access::can($user, Permission::P60),
            'stock' => StockAccess::any($user),
            'delivery' => DeliveryAccess::any($user),
            'returns' => ReturnAccess::any($user),
            'site' => Access::can($user, Permission::P55) || Access::can($user, Permission::P56) || Access::can($user, Permission::P100) || Access::can($user, Permission::P101),
            'tickets' => TicketAccess::any($user),
            'procurement' => Access::can($user, Permission::P45) || Access::can($user, Permission::P46) || Access::can($user, Permission::P47),
            'store' => Access::can($user, Permission::P91) || Access::can($user, Permission::P92),
        ];
    }
}
