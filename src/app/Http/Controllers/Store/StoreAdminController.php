<?php

namespace App\Http\Controllers\Store;

use App\Access\Access;
use App\Access\Permission;
use App\Enums\OrderKind;
use App\Finance\Gateway\Moyasar\MoyasarSettings;
use App\Http\Controllers\Controller;
use App\Models\Catalog\CatalogReadyVariant;
use App\Models\Order;
use App\Models\Store\Coupon;
use App\Models\Store\StoreLink;
use App\Models\Store\StoreSettings;
use App\Models\User;
use App\Orders\OrderAccess;
use App\Store\CouponAdmin;
use App\Store\StoreCatalogue;
use App\Store\StoreGate;
use App\Store\StoreLinks;
use App\Store\StoreSetup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff side of the store (domain 07): StoreSettings + Moyasar test account (P-02), Coupons / CouponNew (P-91) and
 * LinksMobile (P-92 / P-93). Payloads are allow-lists: no secret, no token of another member.
 */
final class StoreAdminController extends Controller
{
    public function __construct(private readonly StoreGate $gate) {}

    public function settings(Request $request, MoyasarSettings $moyasar): Response
    {
        $user = self::user($request);
        abort_unless(Access::can($user, Permission::P02), 403);
        $settings = StoreSettings::current();
        $probe = new Order(['kind' => OrderKind::Ready, 'branch_id' => $settings->branch_id]);

        return Inertia::render('store/settings', [
            'settings' => [
                'enabled' => $settings->enabled, 'branch_id' => $settings->branch_id, 'assignee_id' => $settings->assignee_id,
                'reservation_minutes' => $settings->reservation_minutes, 'link_hours_default' => $settings->link_hours_default,
                'link_days_max' => $settings->link_days_max, 'lock_version' => $settings->lock_version,
            ],
            'branches' => array_map(fn ($b) => ['id' => $b->id, 'name' => app()->getLocale() === 'en' && $b->name_en !== null ? $b->name_en : $b->name_ar], OrderAccess::creatableBranches($user, OrderKind::Ready)),
            'members' => User::query()->whereHas('membership', fn ($q) => $q->where('status', 'active'))->where('is_support', false)->orderBy('name')->get()
                ->filter(fn (User $u) => $settings->branch_id === null || OrderAccess::isValidAssignee($u->id, $probe))
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values()->all(),
            'licensed' => $this->gate->licensed(),
            'open' => $this->gate->open(),
            'payments' => $moyasar->view(),
        ]);
    }

    public function saveSettings(Request $request, StoreSetup $setup): RedirectResponse
    {
        $setup->save(self::user($request), $request->all(), $request->integer('lock_version') ?: null);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('store.settings.saved')]);

        return redirect()->route('store.admin.settings');
    }

    public function savePayments(Request $request, MoyasarSettings $moyasar): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(Access::can($user, Permission::P02), 403);
        $data = $request->validate([
            'publishable_key' => ['required', 'string', 'max:140'],
            'secret_key' => ['nullable', 'string', 'max:140'],
            'webhook_secret' => ['nullable', 'string', 'max:140'],
            'enabled' => ['required', 'boolean'],
        ]);
        $moyasar->save($user, $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('store.payments.saved')]);

        return redirect()->route('store.admin.settings');
    }

    public function coupons(Request $request): Response
    {
        abort_unless(Access::can(self::user($request), Permission::P91), 403);
        $locale = app()->getLocale();

        return Inertia::render('store/coupons', [
            'coupons' => Coupon::query()->orderByDesc('id')->limit(200)->get()->map(fn (Coupon $c) => [
                'id' => $c->id, 'code' => $c->code, 'title' => $c->titleIn($locale), 'kind' => $c->kind, 'value' => $c->value,
                'starts_at' => $c->starts_at->toIso8601String(), 'ends_at' => $c->ends_at?->toIso8601String(),
                'status' => $c->status()->value, 'uses' => $c->uses_count, 'max_uses' => $c->max_uses,
                'max_uses_per_customer' => $c->max_uses_per_customer, 'min_subtotal_minor' => $c->min_subtotal_minor,
                'scoped' => $c->variant_ids !== null,
            ])->all(),
            'licensed' => $this->gate->licensed(),
        ]);
    }

    public function newCoupon(Request $request): Response
    {
        abort_unless(Access::can(self::user($request), Permission::P91), 403);

        return Inertia::render('store/coupon-new', ['variants' => self::variants()]);
    }

    public function storeCoupon(Request $request, CouponAdmin $admin): RedirectResponse
    {
        $coupon = $admin->save(self::user($request), $request->all());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('coupons.saved', ['code' => $coupon->code])]);

        return redirect()->route('store.admin.coupons.index');
    }

    public function pauseCoupon(Request $request, Coupon $coupon, CouponAdmin $admin): RedirectResponse
    {
        $admin->pause(self::user($request), $coupon, $request->boolean('paused'));

        return redirect()->route('store.admin.coupons.index');
    }

    public function links(Request $request): Response
    {
        $user = self::user($request);
        abort_unless(Access::can($user, Permission::P92), 403);
        $locale = app()->getLocale();
        $links = StoreLink::query()->where('owner_id', $user->id)->orderByDesc('id')->limit(100)->get();

        return Inertia::render('store/links', [
            'links' => $links->map(fn (StoreLink $l) => [
                'id' => $l->id, 'number' => $l->number, 'status' => $l->status->value,
                'lapsed' => $l->isLapsed(), 'expires_at' => $l->expires_at->toIso8601String(), 'lock_version' => $l->lock_version,
                'lines' => count($l->lines), 'note' => $l->note,
                // The owner may copy their own link again while it can still be used.
                'url' => $l->status->isOpen() && ! $l->isLapsed() ? route('store.link', ['locale' => $locale, 'token' => $l->token]) : null,
                'order' => $l->usedOrder?->number,
            ])->all(),
            'variants' => self::variants(),
            'settings' => ['link_hours_default' => StoreSettings::current()->link_hours_default, 'link_days_max' => StoreSettings::current()->link_days_max],
            'open' => $this->gate->open(),
            'issued' => session('store.issued'),
        ]);
    }

    public function storeLink(Request $request, StoreLinks $links): RedirectResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:'.StoreLinks::MAX_LINES],
            'lines.*.variant_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'hours' => ['nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        // Only these fields reach the writer: an owner, price or customer sent by the browser is ignored (SAL-05).
        $issued = $links->issue(self::user($request), $data);

        return redirect()->route('store.admin.links.index')->with('store.issued', $issued['link']->number);
    }

    public function cancelLink(Request $request, StoreLink $link, StoreLinks $links): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500'], 'lock_version' => ['required', 'integer']]);
        $links->cancel(self::user($request), $link, $data['reason'] ?? null, (int) $data['lock_version']);

        return redirect()->route('store.admin.links.index');
    }

    public function replaceLink(Request $request, StoreLink $link, StoreLinks $links): RedirectResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer'], 'hours' => ['nullable', 'integer', 'min:1']]);
        $issued = $links->cancel(self::user($request), $link, 'replaced', (int) $data['lock_version'], [
            'lines' => $link->lines, 'hours' => $data['hours'] ?? null, 'note' => $link->note,
        ]);

        return redirect()->route('store.admin.links.index')->with('store.issued', $issued['link']->number ?? null);
    }

    /**
     * @return list<array{id: int, name: string, price_minor: int, available: int}>
     */
    private static function variants(): array
    {
        $locale = app()->getLocale();
        $variants = CatalogReadyVariant::query()->selectable()->with('product')->orderBy('ready_product_id')->orderBy('id')->limit(500)->get()
            ->filter(fn (CatalogReadyVariant $v) => $v->product->isSelectable());
        $available = app(StoreCatalogue::class)->availability(array_values(array_map('intval', $variants->pluck('id')->all())));

        return array_values($variants->map(fn (CatalogReadyVariant $v) => [
            'id' => $v->id, 'name' => $v->product->name->get($locale).' — '.$v->name->get($locale),
            'price_minor' => $v->price_minor, 'available' => $available[$v->id] ?? 0,
        ])->all());
    }

    private static function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
