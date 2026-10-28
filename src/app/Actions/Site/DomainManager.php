<?php

namespace App\Actions\Site;

use App\Access\Authorizer;
use App\Access\Permission;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\Site\SiteDomain;
use App\Models\User;
use App\Site\Domain\DnsTxtResolver;
use App\Site\Domain\TrustedHosts;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The custom-domain record and its ownership proof (SUB-19, Q-46 a). One custom domain per facility. The proof is a
 * DNS TXT record `_opsone-verify.<host>` holding `opsone-site-verification=<token>`; when it is found the status
 * becomes `verified`. Routing the host to this deployment and its TLS certificate are the server admin's work
 * (Traefik) and never done from here.
 */
final class DomainManager
{
    public function __construct(private readonly Authorizer $access, private readonly DnsTxtResolver $dns) {}

    public function current(): ?SiteDomain
    {
        return SiteDomain::query()->orderBy('id')->first();
    }

    /**
     * Normalizes to a bare lower-case host name or throws a validation error (no scheme, path, port, IP or reserved zone).
     */
    public static function normalize(string $input): string
    {
        $host = Str::lower(trim($input));
        $error = null;
        if ($host === '' || preg_match('#[/:?\#@\s]#', $host) === 1) {
            $error = 'site.domain.error.format';
        } elseif (! preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
            $error = 'site.domain.error.format';
        } else {
            foreach ((array) config('site.reserved_domains', []) as $zone) {
                $zone = Str::lower((string) $zone);
                if ($host === $zone || str_ends_with($host, '.'.$zone)) {
                    $error = 'site.domain.error.reserved';
                }
            }
        }
        if ($error !== null) {
            throw ValidationException::withMessages(['hostname' => __($error)]);
        }

        return $host;
    }

    public function request(User $actor, string $hostname): SiteDomain
    {
        $this->access->authorize($actor, Permission::P101);
        $host = self::normalize($hostname);

        return DB::transaction(function () use ($actor, $host) {
            $existing = SiteDomain::query()->lockForUpdate()->orderBy('id')->first();
            if ($existing !== null && $existing->hostname !== $host) {
                throw ValidationException::withMessages(['hostname' => __('site.domain.error.one_domain')]);
            }
            if ($existing !== null) {
                return $existing;
            }
            $domain = SiteDomain::query()->create([
                'hostname' => $host, 'status' => SiteDomain::PENDING, 'verification_token' => Str::random(40), 'requested_by' => $actor->id,
            ]);
            Audit::record(AuditAction::SiteDomainRequested, $domain, $actor, after: ['hostname' => $host]);

            return $domain;
        });
    }

    /**
     * Looks the TXT proof up. A lookup failure keeps the previous status and records why.
     */
    public function check(User $actor): SiteDomain
    {
        $this->access->authorize($actor, Permission::P101);

        // The DNS lookup runs before the row lock, so a slow resolver never holds the domain row.
        $records = $this->dns->txt(SiteDomain::query()->orderBy('id')->firstOrFail()->txtName());

        return DB::transaction(function () use ($actor, $records) {
            DB::afterCommit(fn () => TrustedHosts::forget());
            $domain = SiteDomain::query()->lockForUpdate()->orderBy('id')->firstOrFail();
            $domain->last_checked_at = Carbon::now();
            if ($records === null) {
                $domain->last_error = 'lookup_failed';
            } elseif (in_array($domain->txtValue(), array_map('trim', $records), true)) {
                $wasVerified = $domain->status === SiteDomain::VERIFIED;
                $domain->forceFill(['status' => SiteDomain::VERIFIED, 'last_error' => null, 'verified_at' => $domain->verified_at ?? now()])->save();
                if (! $wasVerified) {
                    Audit::record(AuditAction::SiteDomainVerified, $domain, $actor, after: ['hostname' => $domain->hostname]);
                }

                return $domain;
            } else {
                $domain->status = $domain->status === SiteDomain::VERIFIED ? SiteDomain::VERIFIED : SiteDomain::FAILED;
                $domain->last_error = 'txt_missing';
            }
            $domain->save();

            return $domain;
        });
    }

    public function remove(User $actor): void
    {
        $this->access->authorize($actor, Permission::P101);

        DB::transaction(function () use ($actor) {
            DB::afterCommit(fn () => TrustedHosts::forget());
            $domain = SiteDomain::query()->lockForUpdate()->orderBy('id')->first();
            if ($domain === null) {
                return;
            }
            Audit::record(AuditAction::SiteDomainRemoved, $domain, $actor, before: ['hostname' => $domain->hostname]);
            $domain->delete();
        });
    }
}
