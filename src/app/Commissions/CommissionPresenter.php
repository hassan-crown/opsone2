<?php

namespace App\Commissions;

use App\Access\Access;
use App\Access\Enums\Scope;
use App\Access\Permission;
use App\Enums\MembershipStatus;
use App\Enums\PayoutStatus;
use App\Files\AttachmentService;
use App\Models\Attachment;
use App\Models\Commissions\CommissionEntry;
use App\Models\Commissions\CommissionPayout;
use App\Models\Commissions\CommissionSettings;
use App\Models\Commissions\OrderSale;
use App\Models\Commissions\SaleCorrection;
use App\Models\Membership;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Screen payloads of FinCommissions, the member statement (FinMyCommission), FinPayout and the sale correction page.
 * Every figure comes from the sales the viewer's P-94 grants cover (SaleScope): a sales person sees only their own;
 * nobody sees another member's commission outside their scope (SAL-15).
 */
final class CommissionPresenter
{
    public function __construct(private readonly CommissionBalances $balances) {}

    /**
     * @return array<string, mixed>
     */
    public function index(User $viewer): array
    {
        $visible = SaleScope::apply(OrderSale::query(), $viewer)->get(['id', 'user_id']);
        $full = self::fullScope($viewer);
        $members = User::query()->whereIn('id', $visible->pluck('user_id')->unique())->orderBy('name')->get();
        $settings = CommissionSettings::current();

        return [
            'members' => array_values($members->map(function (User $member) use ($visible, $full, $viewer) {
                $ids = $full ? null : array_values($visible->where('user_id', $member->id)->pluck('id')->map(fn ($id) => (int) $id)->all());
                $open = CommissionPayout::query()->where('user_id', $member->id)
                    ->whereIn('status', [PayoutStatus::Draft->value, PayoutStatus::Reserved->value])->first();

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'rate' => CommissionRates::resolve($member),
                    ...$this->balances->summary($member->id, $ids),
                    'open_payout' => $open === null ? null : ['id' => $open->id, 'status' => $open->status->value],
                    'can_pay' => self::canPay($viewer, $member),
                ];
            })->all()),
            'corrections' => $this->corrections(
                SaleCorrection::query()->with(['order.customer'])->where('status', SaleCorrection::PENDING)
                    ->whereIn('order_sale_id', SaleScope::apply(OrderSale::query(), $viewer, Permission::P97)->select('id'))
                    ->orderBy('id')->get(),
                $viewer,
            ),
            'policy' => ['enabled' => $settings->enabled, 'default_rate_bp' => $settings->default_rate_bp],
            'full_scope' => $full,
            'can' => ['settings' => Access::can($viewer, Permission::P95)],
        ];
    }

    /**
     * The member statement: their sales in the viewer's scope, recent entries, payouts and what a payout would take.
     *
     * @return array<string, mixed>|null null when the viewer may see nothing of this member
     */
    public function member(User $viewer, User $member): ?array
    {
        $visible = array_values(SaleScope::apply(OrderSale::query(), $viewer)->where('user_id', $member->id)->pluck('id')->map(fn ($id) => (int) $id)->all());
        $probe = new CommissionPayout(['user_id' => $member->id]);
        $seesPayouts = Access::can($viewer, Permission::P94, $probe);
        if ($visible === [] && ! $seesPayouts) {
            return null;
        }
        $full = self::fullScope($viewer) || $viewer->id === $member->id && $seesPayouts;
        $ids = $full ? null : $visible;
        $rows = $this->balances->sales($member->id, null, $full ? null : $visible);
        $canPay = self::canPay($viewer, $member);

        return [
            'member' => ['id' => $member->id, 'name' => $member->name, 'rate' => CommissionRates::resolve($member)],
            'summary' => $this->balances->summary($member->id, $ids),
            'sales' => array_map(function (array $row) {
                /** @var OrderSale $sale */
                $sale = $row['sale'];
                $basis = CommissionBasis::of($sale->order, $sale);

                return [
                    'id' => $sale->id,
                    'status' => $sale->status,
                    'order' => ['id' => $sale->order_id, 'number' => $sale->order->number, 'customer' => $sale->order->customer->name],
                    'rate_bp' => $sale->rate_bp,
                    'rate_source' => $sale->rate_source,
                    'pinned_at' => $sale->pinned_at->toIso8601String(),
                    'eligible_minor' => $sale->isActive() ? $basis['eligible_minor'] : null,
                    'agreed_minor' => $sale->isActive() ? $basis['agreed_minor'] : null,
                    'collected_minor' => $sale->isActive() ? $basis['collected_minor'] : null,
                    'accrued_minor' => $row['accrued'],
                    'taken_minor' => $row['taken'],
                    'available_minor' => $row['available'],
                    'hold' => $row['hold'],
                ];
            }, array_reverse($rows)),
            'entries' => array_values(CommissionEntry::query()->with('sale.order')->where('user_id', $member->id)
                ->when($ids !== null, fn ($q) => $q->whereIn('order_sale_id', $ids))
                ->orderByDesc('id')->limit(50)->get()->map(fn (CommissionEntry $e) => [
                    'id' => $e->id,
                    'kind' => $e->kind,
                    'order' => ['id' => $e->order_id, 'number' => $e->sale->order->number],
                    'amount_minor' => $e->amount_minor,
                    'accrued_minor' => $e->accrued_minor,
                    'collected_minor' => $e->collected_minor,
                    'source' => explode(':', $e->source)[0],
                    'reason' => $e->reason,
                    'created_at' => $e->created_at->toIso8601String(),
                ])->all()),
            'payouts' => $seesPayouts ? $this->payouts($member, $viewer) : [],
            'payable' => $canPay ? $this->balances->payable($member->id, now()) : null,
            'can' => ['pay' => $canPay],
            'today' => now()->toDateString(),
        ];
    }

    /**
     * The order's sale page (SAL-11, SAL-16): who owns it, the pinned rate, its history and the corrections.
     *
     * @return array<string, mixed>
     */
    public function sale(Order $order, User $viewer): array
    {
        $sales = OrderSale::query()->with('member')->where('order_id', $order->id)->orderBy('id')->get();
        $sales->each(fn (OrderSale $s) => $s->setRelation('order', $order));
        $active = $sales->firstWhere('status', OrderSale::ACTIVE);
        $amounts = $active !== null && Access::can($viewer, Permission::P94, $active);
        $corrections = SaleCorrection::query()->where('order_id', $order->id)->orderByDesc('id')->get();
        $corrections->each(fn (SaleCorrection $c) => $c->setRelation('order', $order));
        $canOwner = $active !== null && Access::can($viewer, Permission::P98, $active);
        $canRate = $active !== null && Access::can($viewer, Permission::P97, $active);

        return [
            'sale' => $active === null ? null : [
                'id' => $active->id,
                'member' => ['id' => $active->user_id, 'name' => $active->member->name],
                'rate_bp' => $active->rate_bp,
                'rate_source' => $active->rate_source,
                'owner_source' => $active->owner_source,
                'pinned_at' => $active->pinned_at->toIso8601String(),
                ...($amounts ? [...CommissionBasis::of($order, $active), 'accrued_minor' => CommissionAccrual::accrued($active)] : []),
            ],
            'amounts_visible' => $amounts,
            'history' => array_values($sales->map(fn (OrderSale $s) => [
                'id' => $s->id,
                'status' => $s->status,
                'member' => $s->member->name,
                'rate_bp' => $s->rate_bp,
                'pinned_at' => $s->pinned_at->toIso8601String(),
                'entries' => $amounts ? array_values($s->entries->map(fn (CommissionEntry $e) => [
                    'id' => $e->id, 'kind' => $e->kind, 'amount_minor' => $e->amount_minor, 'accrued_minor' => $e->accrued_minor,
                    'source' => explode(':', $e->source)[0], 'reason' => $e->reason, 'created_at' => $e->created_at->toIso8601String(),
                ])->all()) : [],
            ])->all()),
            'corrections' => $this->corrections($corrections, $viewer),
            'members' => $canOwner ? array_values(Membership::query()->with('user')->where('status', MembershipStatus::Active->value)->get()
                ->map(fn (Membership $m) => ['id' => $m->user_id, 'name' => $m->user->name])->sortBy('name')->values()->all()) : [],
            'can' => [
                'request_owner' => $canOwner && $corrections->where('status', SaleCorrection::PENDING)->isEmpty(),
                'request_rate' => $canRate && $corrections->where('status', SaleCorrection::PENDING)->isEmpty(),
            ],
        ];
    }

    /**
     * @param  Collection<int, SaleCorrection>  $corrections
     * @return list<array<string, mixed>>
     */
    private function corrections(Collection $corrections, User $viewer): array
    {
        $names = User::query()->whereIn('id', $corrections->flatMap(fn (SaleCorrection $c) => [$c->from_user_id, $c->to_user_id, $c->requested_by, $c->decided_by])->filter()->unique())
            ->pluck('name', 'id');

        return array_values($corrections->map(fn (SaleCorrection $c) => [
            'id' => $c->id,
            'order' => ['id' => $c->order_id, 'number' => $c->order->number],
            'kind' => $c->kind,
            'status' => $c->status,
            'from' => $names[$c->from_user_id] ?? null,
            'to' => $names[$c->to_user_id] ?? null,
            'from_rate_bp' => $c->from_rate_bp,
            'to_rate_bp' => $c->to_rate_bp,
            'reason' => $c->reason,
            'decision_reason' => $c->decision_reason,
            'requested_by' => $names[$c->requested_by] ?? null,
            'decided_by' => $c->decided_by === null ? null : ($names[$c->decided_by] ?? null),
            'created_at' => $c->created_at->toIso8601String(),
            'lock_version' => $c->lock_version,
            'can' => [
                'decide' => $c->status === SaleCorrection::PENDING && $c->requested_by !== $viewer->id
                    && ! in_array($viewer->id, $c->affectedUserIds(), true) && Access::can($viewer, Permission::P97, $c->sale),
                'cancel' => $c->status === SaleCorrection::PENDING && $c->requested_by === $viewer->id,
            ],
        ])->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function payouts(User $member, User $viewer): array
    {
        $files = app(AttachmentService::class);
        $pay = self::canPay($viewer, $member);
        $correct = $viewer->id !== $member->id && Access::can($viewer, Permission::P97, new CommissionPayout(['user_id' => $member->id]));

        return array_values(CommissionPayout::query()->with('attachments')->where('user_id', $member->id)->orderByDesc('id')->limit(50)->get()
            ->map(fn (CommissionPayout $p) => [
                'id' => $p->id,
                'status' => $p->status->value,
                'period_from' => $p->period_from->toDateString(),
                'period_to' => $p->period_to->toDateString(),
                'amount_minor' => $p->amount_minor,
                'corrected_minor' => $p->corrected_minor,
                'method' => $p->method,
                'paid_on' => $p->paid_on?->toDateString(),
                'reference' => $p->reference,
                'note' => $p->note,
                'reason' => $p->reason,
                'lock_version' => $p->lock_version,
                'files' => array_values($p->attachments->map(fn (Attachment $a) => [
                    'uuid' => $a->uuid, 'name' => $a->original_name,
                    'url' => $files->canDownload($viewer, $a) ? $files->downloadUrl($a, $viewer) : null,
                ])->all()),
                'can' => [
                    'approve' => $pay && $p->status === PayoutStatus::Draft,
                    'record' => $pay && $p->status === PayoutStatus::Reserved,
                    'cancel' => $pay && in_array($p->status, [PayoutStatus::Draft, PayoutStatus::Reserved], true),
                    'correct' => $correct && $p->status === PayoutStatus::Paid,
                ],
            ])->all());
    }

    /**
     * The sale page needs one of P-94, P-97 or P-98 over the sale (before a sale exists: over the order).
     */
    public static function canViewSale(User $viewer, Order $order): bool
    {
        $sale = SaleOwnership::active($order);
        $sale?->setRelation('order', $order);
        foreach ([Permission::P94, Permission::P97, Permission::P98] as $permission) {
            if (Access::can($viewer, $permission, $sale ?? $order)) {
                return true;
            }
        }

        return false;
    }

    public static function canPay(User $viewer, User $member): bool
    {
        return $viewer->id !== $member->id && Access::can($viewer, Permission::P96, new CommissionPayout(['user_id' => $member->id]));
    }

    private static function fullScope(User $viewer): bool
    {
        foreach (Access::grantsFor($viewer, Permission::P94) as $grant) {
            if ($grant->scope === Scope::Tenant) {
                return true;
            }
        }

        return false;
    }
}
