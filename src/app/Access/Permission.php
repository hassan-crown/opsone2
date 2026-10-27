<?php

namespace App\Access;

use App\Access\Enums\Audience;
use App\Access\Enums\Scope;

/**
 * Stable permission identifiers from the permission matrix. Metadata lives in PermissionCatalogue.
 */
enum Permission: string
{
    case P01 = 'P-01';
    case P02 = 'P-02';
    case P03 = 'P-03';
    case P04 = 'P-04';
    case P05 = 'P-05';
    case P06 = 'P-06';
    case P07 = 'P-07';
    case P08 = 'P-08';
    case P09 = 'P-09';
    case P10 = 'P-10';
    case P11 = 'P-11';
    case P12 = 'P-12';
    case P13 = 'P-13';
    case P14 = 'P-14';
    case P15 = 'P-15';
    case P16 = 'P-16';
    case P17 = 'P-17';
    case P18 = 'P-18';
    case P19 = 'P-19';
    case P20 = 'P-20';
    case P21 = 'P-21';
    case P22 = 'P-22';
    case P23 = 'P-23';
    case P24 = 'P-24';
    case P25 = 'P-25';
    case P26 = 'P-26';
    case P27 = 'P-27';
    case P28 = 'P-28';
    case P29 = 'P-29';
    case P30 = 'P-30';
    case P31 = 'P-31';
    case P32 = 'P-32';
    case P33 = 'P-33';
    case P34 = 'P-34';
    case P35 = 'P-35';
    case P36 = 'P-36';
    case P37 = 'P-37';
    case P38 = 'P-38';
    case P39 = 'P-39';
    case P40 = 'P-40';
    case P41 = 'P-41';
    case P42 = 'P-42';
    case P43 = 'P-43';
    case P44 = 'P-44';
    case P45 = 'P-45';
    case P46 = 'P-46';
    case P47 = 'P-47';
    case P48 = 'P-48';
    case P49 = 'P-49';
    case P50 = 'P-50';
    case P51 = 'P-51';
    case P52 = 'P-52';
    case P53 = 'P-53';
    case P54 = 'P-54';
    case P55 = 'P-55';
    case P56 = 'P-56';
    case P57 = 'P-57';
    case P58 = 'P-58';
    case P59 = 'P-59';
    case P60 = 'P-60';
    case P61 = 'P-61';
    case P62 = 'P-62';
    case P63 = 'P-63';
    case P64 = 'P-64';
    case P65 = 'P-65';
    case P66 = 'P-66';
    case P67 = 'P-67';
    case P68 = 'P-68';
    case P69 = 'P-69';
    case P70 = 'P-70';
    case P71 = 'P-71';
    case P72 = 'P-72';
    case P73 = 'P-73';
    case P74 = 'P-74';
    case P75 = 'P-75';
    case P76 = 'P-76';
    case P77 = 'P-77';
    case P78 = 'P-78';
    case P79 = 'P-79';
    case P80 = 'P-80';
    case P81 = 'P-81';
    case P82 = 'P-82';
    case P83 = 'P-83';
    case P84 = 'P-84';
    case P85 = 'P-85';
    case P86 = 'P-86';
    case P87 = 'P-87';
    case P88 = 'P-88';
    case P89 = 'P-89';
    case P90 = 'P-90';
    case P91 = 'P-91';
    case P92 = 'P-92';
    case P93 = 'P-93';
    case P94 = 'P-94';
    case P95 = 'P-95';
    case P96 = 'P-96';
    case P97 = 'P-97';
    case P98 = 'P-98';
    case P99 = 'P-99';
    case P100 = 'P-100';
    case P101 = 'P-101';
    case P102 = 'P-102';

    public static function fromCode(string $code): ?self
    {
        return self::tryFrom($code);
    }

    public function label(?string $locale = null): string
    {
        $definition = PermissionCatalogue::definition($this);

        return ($locale ?? app()->getLocale()) === 'en' ? $definition['en'] : $definition['ar'];
    }

    public function group(): string
    {
        return PermissionCatalogue::definition($this)['group'];
    }

    public function audience(): Audience
    {
        return Audience::from(PermissionCatalogue::definition($this)['audience']);
    }

    /**
     * @return list<Scope>
     */
    public function allowedScopes(): array
    {
        return array_map(fn (string $scope) => Scope::from($scope), PermissionCatalogue::definition($this)['scopes']);
    }

    public function allowsScope(Scope $scope): bool
    {
        return in_array($scope->value, PermissionCatalogue::definition($this)['scopes'], true);
    }

    public function capability(): ?string
    {
        return PermissionCatalogue::definition($this)['capability'];
    }

    /**
     * Staff actions still open when the license phase allows reading and export only (M-19: expired,
     * provisioned, unproven license): views, reports, export, and protective actions that only narrow access
     * (disabling a member, managing support authorizations). Data protection is never sold separately.
     */
    public function availableReadOnly(): bool
    {
        return in_array($this, [
            self::P01, self::P08, self::P16, self::P37, self::P48, self::P60, self::P61, self::P62, self::P63,
            self::P82, self::P94, self::P06, self::P79,
        ], true);
    }

    /**
     * Staff actions that start new work: new users or new orders, stopped during wind-down (M-19-T02).
     */
    public function startsNewWork(): bool
    {
        return in_array($this, [self::P05, self::P15, self::P92], true);
    }
}
