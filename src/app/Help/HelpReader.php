<?php

namespace App\Help;

use App\Access\Authorizer;
use App\Access\Contracts\LicenseCapabilities;
use App\Access\Enums\Audience;
use App\Access\Grant;
use App\Access\LicensedCapabilities;
use App\Access\Permission;
use App\Models\Facility;
use App\Models\HelpArticle;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may read which help article (ADR-0048). Every list, article, image, search and contextual lookup goes through
 * query()/canRead(), so hiding a link in the UI is never the only guard.
 *
 * - Audience: staff read staff articles, customers read customer articles; never the other side.
 * - Permissions: an article listing P-xx codes is read by whoever holds any of them (any scope); none = everyone.
 *   Holding is read from the effective grants, not the license, so a feature the plan lacks still counts as held.
 * - Capability: when the license lacks it, only the owner and managers see the article, marked «on a higher plan»
 *   (locked); everyone else does not see it at all.
 */
final class HelpReader
{
    /** @var list<string>|null */
    private ?array $roleKeys = null;

    private ?bool $ownerOrManager = null;

    /**
     * @param  list<string>|null  $held  permission codes held (null = every staff permission, e.g. the owner)
     * @param  list<string>  $licensed  capabilities the license enables
     */
    private function __construct(
        public readonly HelpAudience $audience,
        private readonly ?array $held,
        private readonly array $licensed,
        private readonly ?User $user,
    ) {}

    public static function forStaff(User $user): self
    {
        $codes = array_values(array_unique(array_map(
            fn (Grant $grant) => $grant->permission->value,
            app(Authorizer::class)->effectiveGrants($user),
        )));

        $staff = array_values(array_map(
            fn (Permission $p) => $p->value,
            array_filter(Permission::cases(), fn (Permission $p) => in_array($p->audience(), [Audience::Staff, Audience::Self], true)),
        ));

        return new self(HelpAudience::Staff, array_diff($staff, $codes) === [] ? null : $codes, self::licensed(), $user);
    }

    public static function forCustomer(): self
    {
        return new self(HelpAudience::Customer, [], self::licensed(), null);
    }

    /**
     * Published articles this reader may see (locked ones included for the owner and managers).
     *
     * @return Builder<HelpArticle>
     */
    public function query(): Builder
    {
        $query = HelpArticle::query()->published()->where('audience', $this->audience->value);

        if ($this->held !== null) {
            $held = $this->held;
            $query->where(function (Builder $q) use ($held) {
                $q->whereNull('permissions')->orWhereJsonLength('permissions', 0);
                foreach ($held as $code) {
                    $q->orWhereJsonContains('permissions', $code);
                }
            });
        }

        if (! $this->seesLocked()) {
            $licensed = $this->licensed;
            $query->where(fn (Builder $q) => $q->whereNull('capability')->orWhereIn('capability', $licensed));
        }

        return $query;
    }

    /**
     * Role keys of the reader (the owner counts as `owner`), for «My guide». Loaded on first use only.
     *
     * @return list<string>
     */
    public function roles(): array
    {
        if ($this->roleKeys !== null) {
            return $this->roleKeys;
        }

        $roles = [];
        $owner = false;
        if ($this->user !== null && ! $this->user->is_support) {
            $membership = Membership::query()->with('roles.template')->where('user_id', $this->user->id)->first();
            if ($membership !== null && $membership->isActive()) {
                $owner = Facility::current()?->owner_membership_id === $membership->id;
                foreach ($membership->roles as $role) {
                    $key = $role->template->is_owner ? 'owner' : $role->template->key;
                    if (is_string($key)) {
                        $roles[] = $key;
                    }
                }
                if ($owner) {
                    $roles[] = 'owner';
                }
            }
        }

        $this->ownerOrManager = $owner || in_array('manager', $roles, true);

        return $this->roleKeys = array_values(array_unique($roles));
    }

    /**
     * The owner and managers also see articles whose capability the plan lacks (locked). Only looked up when the
     * plan actually lacks a capability.
     */
    public function seesLocked(): bool
    {
        if ($this->user === null || array_diff(LicensedCapabilities::KNOWN, $this->licensed) === []) {
            return false;
        }
        if ($this->ownerOrManager === null) {
            $this->roles();
        }

        return (bool) $this->ownerOrManager;
    }

    public function canRead(HelpArticle $article): bool
    {
        return $this->query()->whereKey($article->getKey())->exists();
    }

    /**
     * The article's feature is not in the plan (only the owner and managers ever get such an article).
     */
    public function isLocked(HelpArticle $article): bool
    {
        return $article->capability !== null && ! in_array($article->capability, $this->licensed, true);
    }

    /**
     * «My guide» lists an article when its `roles` name one of the reader's roles.
     */
    public function isForMyRoles(HelpArticle $article): bool
    {
        return array_intersect($article->roles ?? [], $this->roles()) !== [];
    }

    /**
     * @return list<string>
     */
    private static function licensed(): array
    {
        $license = app(LicenseCapabilities::class);

        return array_values(array_filter(
            LicensedCapabilities::KNOWN,
            fn (string $capability) => $license->allows($capability),
        ));
    }
}
