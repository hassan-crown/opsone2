<?php

namespace App\Http\Middleware\Portal;

use App\Http\Middleware\SetLocale;
use App\Models\Facility;
use App\Portal\PortalSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Middleware;

/**
 * Shared props of the customer workspace. Nothing staff-side is shared here: no staff user, navigation, permissions or
 * notification bell; only the facility's public name and the signed-in customer's display name.
 */
final class HandlePortalInertiaRequests extends Middleware
{
    /** Only the strings the workspace uses travel to the customer (no staff vocabulary). */
    private const PREFIXES = ['portal.', 'ui.', 'orders.kind.', 'tickets.'];

    /** Ticket strings of the staff screens stay out of the customer payload. */
    private const EXCLUDED = ['tickets.staff.', 'tickets.notify.'];

    protected $rootView = 'app';

    /**
     * Starts from no shared props: whatever a staff request shared earlier in a long-lived process never reaches a
     * customer page.
     */
    public function handle(Request $request, Closure $next)
    {
        Inertia::flushShared();

        return parent::handle($request, $next);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $customer = PortalSession::customer();
        $facility = Facility::current();
        $locale = app()->getLocale();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => $locale,
            'dir' => SetLocale::direction($locale),
            'locales' => config('app.available_locales'),
            'translations' => self::translations(),
            'auth' => ['user' => null],
            'portal' => [
                'facility' => $facility === null ? null : ($locale === 'en' && $facility->name_en !== null ? $facility->name_en : $facility->name_ar),
                'customer' => $customer === null ? null : ['name' => $customer->name],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function translations(): array
    {
        $load = static function (string $locale): array {
            $path = lang_path($locale.'.json');
            $all = is_file($path) ? (array) json_decode((string) file_get_contents($path), true) : [];

            return array_filter($all, fn ($key) => Str::startsWith((string) $key, self::PREFIXES) && ! Str::startsWith((string) $key, self::EXCLUDED), ARRAY_FILTER_USE_KEY);
        };

        return array_merge($load(config('app.fallback_locale')), $load(app()->getLocale()));
    }
}
