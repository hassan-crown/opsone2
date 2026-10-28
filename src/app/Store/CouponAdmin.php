<?php

namespace App\Store;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\StateMachine;
use App\Models\Catalog\CatalogReadyVariant;
use App\Models\Store\Coupon;
use App\Models\User;
use App\Money\Money;
use App\Support\StaleRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Coupons and CouponNew (SAL-06, P-91, `ready_goods`): create, edit and pause/resume. A unique upper-case code of 3..32
 * letters, digits or dashes; percent up to 100% or a positive amount; the end after the start; caps positive. Editing
 * never touches a use already reserved or consumed (each keeps its own discount). Pausing stops new uses at once and
 * honours a use reserved for a payment still inside its window (M-32-T05).
 */
final class CouponAdmin
{
    public function __construct(private readonly StoreGate $gate) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{3,32}$/'],
            'title_ar' => ['required', 'string', 'max:120'],
            'title_en' => ['nullable', 'string', 'max:120'],
            'kind' => ['required', 'in:percent,amount'],
            'value' => ['required', 'string', 'max:20'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'variant_ids' => ['nullable', 'array', 'max:100'],
            'variant_ids.*' => ['integer', 'distinct'],
            'min_subtotal' => ['nullable', 'string', 'max:20'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'max_uses_per_customer' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(User $actor, array $data, ?Coupon $coupon = null, ?int $lockVersion = null): Coupon
    {
        $this->authorize($actor);
        /** @var array{code: string, title_ar: string, title_en?: string|null, kind: string, value: string, starts_at: string, ends_at?: string|null, variant_ids?: list<int>|null, min_subtotal?: string|null, max_uses?: int|null, max_uses_per_customer?: int|null} $data */
        $data = Validator::make($data, self::rules())->validate();
        $value = $data['kind'] === Coupon::PERCENT ? self::percent($data['value']) : self::amount($data['value'], 'value');
        $variants = $data['variant_ids'] ?? [];
        if ($variants !== [] && CatalogReadyVariant::query()->whereIn('id', $variants)->count() !== count($variants)) {
            throw ValidationException::withMessages(['variant_ids' => __('coupons.validation.variants')]);
        }
        $attributes = [
            'code' => Coupon::normalize($data['code']),
            'title' => array_filter(['ar' => $data['title_ar'], 'en' => $data['title_en'] ?? null], fn ($v) => $v !== null && $v !== ''),
            'kind' => $data['kind'],
            'value' => $value,
            'starts_at' => CarbonImmutable::parse($data['starts_at']),
            'ends_at' => isset($data['ends_at']) ? CarbonImmutable::parse($data['ends_at']) : null,
            'variant_ids' => $variants === [] ? null : array_map('intval', $variants),
            'min_subtotal_minor' => isset($data['min_subtotal']) && $data['min_subtotal'] !== '' ? self::amount($data['min_subtotal'], 'min_subtotal') : null,
            'max_uses' => $data['max_uses'] ?? null,
            'max_uses_per_customer' => $data['max_uses_per_customer'] ?? null,
        ];

        return DB::transaction(function () use ($actor, $attributes, $coupon, $lockVersion) {
            if (Coupon::query()->where('code', $attributes['code'])->when($coupon !== null, fn ($q) => $q->whereKeyNot($coupon?->id))->exists()) {
                throw ValidationException::withMessages(['code' => __('coupons.validation.code_taken')]);
            }
            if ($coupon === null) {
                $coupon = Coupon::query()->create([...$attributes, 'created_by' => $actor->id]);
                Audit::record(AuditAction::CouponCreated, $coupon, $actor, after: self::snapshot($coupon), label: $coupon->code);

                return $coupon;
            }
            $coupon = Coupon::query()->lockForUpdate()->findOrFail($coupon->id);
            if ($lockVersion !== null && $coupon->lock_version !== $lockVersion) {
                throw new StaleRecord($coupon->lock_version);
            }
            $before = self::snapshot($coupon);
            $coupon->forceFill([...$attributes, 'lock_version' => $coupon->lock_version + 1])->save();
            Audit::record(AuditAction::CouponUpdated, $coupon, $actor, before: $before, after: self::snapshot($coupon), label: $coupon->code);

            return $coupon;
        });
    }

    public function pause(User $actor, Coupon $coupon, bool $paused): Coupon
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $coupon, $paused) {
            $coupon = Coupon::query()->lockForUpdate()->findOrFail($coupon->id);
            if (($coupon->paused_at !== null) === $paused) {
                return $coupon;
            }
            $coupon->forceFill(['paused_at' => $paused ? now() : null, 'lock_version' => $coupon->lock_version + 1])->save();
            Audit::record($paused ? AuditAction::CouponPaused : AuditAction::CouponResumed, $coupon, $actor, label: $coupon->code);

            return $coupon;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(Coupon $coupon): array
    {
        return [
            'code' => $coupon->code, 'kind' => $coupon->kind, 'value' => $coupon->value,
            'starts_at' => $coupon->starts_at->toIso8601String(), 'ends_at' => $coupon->ends_at?->toIso8601String(),
            'variants' => $coupon->variant_ids === null ? null : count($coupon->variant_ids),
            'min_subtotal_minor' => $coupon->min_subtotal_minor, 'max_uses' => $coupon->max_uses, 'max_uses_per_customer' => $coupon->max_uses_per_customer,
        ];
    }

    private function authorize(User $actor): void
    {
        Access::authorize($actor, Permission::P91);
        if (! $this->gate->licensed()) {
            throw new InvalidTransition(StateMachine::M32, 'T00', 'store_closed');
        }
    }

    private static function percent(string $text): int
    {
        if (preg_match('/^\d{1,3}(\.\d{1,2})?$/', trim($text)) !== 1) {
            throw ValidationException::withMessages(['value' => __('coupons.validation.percent')]);
        }
        $bp = (int) round((float) trim($text) * 100);
        if ($bp < 1 || $bp > Money::BP) {
            throw ValidationException::withMessages(['value' => __('coupons.validation.percent')]);
        }

        return $bp;
    }

    private static function amount(string $text, string $field): int
    {
        try {
            $money = Money::fromInput(trim($text));
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => __('coupons.validation.amount')]);
        }
        if ($money->isNegative() || $money->isZero()) {
            throw ValidationException::withMessages([$field => __('coupons.validation.amount')]);
        }

        return $money->minor;
    }
}
