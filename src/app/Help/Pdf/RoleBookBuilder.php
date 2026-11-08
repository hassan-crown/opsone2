<?php

namespace App\Help\Pdf;

use App\Access\Enums\Audience;
use App\Access\Permission;
use App\Access\PermissionCatalogue;
use App\Help\HelpAudience;
use App\Help\HelpCategory;
use App\Help\KnowledgeBase\ArticleFile;
use InvalidArgumentException;

/**
 * Chooses a role's articles from the knowledge base files with the same rules as HelpReader::query():
 *
 * - audience: a staff role reads `staff` articles, `customer` reads `customer` ones, never the other side;
 * - permissions: an article listing P-xx codes is included when the role's default grants (PermissionCatalogue, any
 *   scope; every role also holds the «self» permissions) contain any of them; none listed = everyone; the owner
 *   holds every staff permission, so no permission filter applies to it;
 * - capability: the book documents the whole product, so articles of unlicensed features are kept (the owner and
 *   managers see them too, marked «higher plan», in the app).
 *
 * «Role guide» = role_guide articles whose `roles` name the role (every customer role_guide for `customer`).
 * Other roles' guides are left out.
 */
final class RoleBookBuilder
{
    public const CUSTOMER = 'customer';

    /**
     * @return list<string>
     */
    public static function roles(): array
    {
        return [...array_keys(PermissionCatalogue::ROLES), self::CUSTOMER];
    }

    public static function title(string $role): string
    {
        return $role === self::CUSTOMER ? 'العميل (بوابة العميل)' : PermissionCatalogue::ROLES[$role]['ar'];
    }

    /**
     * @param  list<ArticleFile>  $articles  every article of the library
     */
    public function build(string $role, array $articles): RoleBook
    {
        if (! in_array($role, self::roles(), true)) {
            throw new InvalidArgumentException("Unknown role «{$role}» (use: ".implode(', ', self::roles()).').');
        }

        $audience = $role === self::CUSTOMER ? HelpAudience::Customer : HelpAudience::Staff;
        $held = $this->held($role);

        $mine = array_values(array_filter(
            $articles,
            fn (ArticleFile $a) => $a->audience === $audience
                && ($held === null || $a->permissions === [] || array_intersect($a->permissions, $held) !== []),
        ));
        usort($mine, fn (ArticleFile $a, ArticleFile $b) => [$a->order, $a->slug] <=> [$b->order, $b->slug]);

        $guide = array_values(array_filter(
            $mine,
            fn (ArticleFile $a) => $a->category === HelpCategory::RoleGuide
                && ($audience === HelpAudience::Customer || in_array($role, $a->roles, true)),
        ));

        $sections = [];
        foreach (RoleBook::categoryOrder() as $category) {
            $inSection = array_values(array_filter($mine, fn (ArticleFile $a) => $a->category === $category));
            if ($inSection !== []) {
                $sections[$category->value] = $inSection;
            }
        }

        return new RoleBook($role, self::title($role), $audience, $guide, $sections);
    }

    /**
     * @return list<string>|null null = no permission filter (the owner holds everything; customers list none)
     */
    private function held(string $role): ?array
    {
        if ($role === 'owner' || $role === self::CUSTOMER) {
            return null;
        }

        $codes = array_map(fn (Permission $p) => $p->value, PermissionCatalogue::forRole($role));
        foreach (Permission::cases() as $permission) {
            if ($permission->audience() === Audience::Self) {
                $codes[] = $permission->value;
            }
        }

        return array_values(array_unique($codes));
    }
}
