<?php

namespace App\Http\Controllers\Field;

use App\Calendar\CalendarPresenter;
use App\FieldSales\FieldReps;
use App\FieldSales\FieldSalesConfig;
use App\FieldSales\FieldSalesPresenter;
use App\Http\Controllers\Controller;
use App\Installation\FieldAccess;
use App\Installation\FieldPresenter;
use App\Models\Appointment;
use App\Models\User;
use App\Workflow\AppointmentBooking;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The field app: the technician's installation visits (ADR-0036) and the sales rep's visits, leads and appointment
 * requests (ADR-0041) for today and the next days, one screen per visit, and the install instructions. Only the
 * booked members of an appointment open it (FieldAccess).
 */
class FieldController extends Controller
{
    /** OpsOne teal (design-system `teal-600`). */
    public const THEME = '#0E7C72';

    /** Days shown after today. */
    public const AHEAD_DAYS = 6;

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $today = CarbonImmutable::parse(AppointmentBooking::today(), AppointmentBooking::timezone());
        $visits = FieldAccess::mine($user, $today->startOfDay()->utc(), $today->addDays(self::AHEAD_DAYS)->endOfDay()->utc())
            ->with(FieldPresenter::with())->limit(100)->get();
        // ADR-0041: a field sales rep also sees the leads and appointment requests that follow them.
        $rep = FieldSalesConfig::enabled() && FieldReps::isRep($user);

        return Inertia::render('field/index', [
            'today' => $today->toDateString(),
            'visits' => array_values($visits->map(fn (Appointment $a) => $a->kind === FieldAccess::INSTALLATION
                ? FieldPresenter::row($a) + ['kind' => $a->kind]
                : FieldSalesPresenter::visitRow($a))->all()),
            'work' => $rep ? FieldSalesPresenter::work($user) : null,
        ]);
    }

    public function show(Request $request, Appointment $appointment, FieldPresenter $presenter, FieldSalesPresenter $sales): Response
    {
        /** @var User $user */
        $user = $request->user();
        $appointment->load(FieldPresenter::with());
        FieldAccess::authorize($user, $appointment);
        if ($appointment->kind !== FieldAccess::INSTALLATION) {
            return Inertia::render('field/sales-visit', [
                'visit' => $sales->visit($appointment, $user),
                'today' => CalendarPresenter::local(now())->toDateString(),
            ]);
        }

        return Inertia::render('field/visit', [
            'visit' => $presenter->visit($appointment, $user),
            'today' => CalendarPresenter::local(now())->toDateString(),
        ]);
    }

    public function install(): Response
    {
        return Inertia::render('field/install');
    }

    /**
     * The web app manifest. Always OpsOne's identity — staff never see the tenant's brand here (ADR-0036). Public:
     * browsers fetch manifests without cookies.
     */
    public function manifest(): JsonResponse
    {
        $ar = app()->getLocale() !== 'en';

        return response()->json([
            'id' => '/field',
            'name' => $ar ? 'OpsOne — الميدان' : 'OpsOne Field',
            'short_name' => 'OpsOne',
            'description' => __('field.manifest.description'),
            'lang' => $ar ? 'ar' : 'en',
            'dir' => $ar ? 'rtl' : 'ltr',
            'start_url' => '/field',
            'scope' => '/field',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'theme_color' => self::THEME,
            'background_color' => '#ffffff',
            'icons' => [
                ['src' => '/field-icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/field-icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/field-icons/maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
