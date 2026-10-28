<?php

/**
 * SUB-19 / Q-46 a: custom-domain record with ownership proof by DNS TXT. The app never touches routing or TLS.
 */

use App\Actions\Site\DomainManager;
use App\Models\AuditEvent;
use App\Models\Site\SiteDomain;
use App\Site\Domain\DnsTxtResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
    $this->owner = $this->org->owner;
    $this->records = [];
    $this->lookupFails = false;
    $fake = new class($this) implements DnsTxtResolver
    {
        public function __construct(private $test) {}

        public function txt(string $name): ?array
        {
            return $this->test->lookupFails ? null : ($this->test->records[$name] ?? []);
        }
    };
    app()->instance(DnsTxtResolver::class, $fake);
});

it('normalizes hosts and refuses URLs, paths, ports, IPs and reserved zones', function (string $input) {
    expect(fn () => DomainManager::normalize($input))->toThrow(ValidationException::class);
})->with(['https://www.site.sa', 'www.site.sa/path', 'www.site.sa:8080', '127.0.0.1', 'localhost', 'nodots', 'a b.sa', 'shop.opsone.sa', 'foo.example.com', '-bad-.sa', '']);

it('lower-cases a valid host', function () {
    expect(DomainManager::normalize('  WWW.Mysite.SA '))->toBe('www.mysite.sa');
});

it('records the domain as pending with a token and the DNS proof to publish', function () {
    $this->actingAs($this->owner)->post(route('site.admin.domain.store'), ['hostname' => 'www.mysite.sa'])->assertSessionHasNoErrors();

    $d = SiteDomain::query()->firstOrFail();
    expect($d->status)->toBe('pending')->and(strlen($d->verification_token))->toBeGreaterThanOrEqual(32)
        ->and($d->txtName())->toBe('_opsone-verify.www.mysite.sa')
        ->and($d->txtValue())->toBe('opsone-site-verification='.$d->verification_token);
    $this->get(route('site.admin.domain.show'))->assertInertia(fn ($p) => $p->where('domain.txt_value', $d->txtValue())->where('domain.status', 'pending'));
});

it('allows one custom domain per facility', function () {
    $this->actingAs($this->owner)->post(route('site.admin.domain.store'), ['hostname' => 'www.mysite.sa']);
    $this->post(route('site.admin.domain.store'), ['hostname' => 'other.mysite.sa'])->assertSessionHasErrors('hostname');

    expect(SiteDomain::query()->count())->toBe(1);
});

it('verifies when the TXT proof is found and stays pending/failed when not', function () {
    $this->actingAs($this->owner)->post(route('site.admin.domain.store'), ['hostname' => 'www.mysite.sa']);
    $d = SiteDomain::query()->firstOrFail();

    $this->post(route('site.admin.domain.check'));
    expect($d->refresh()->status)->toBe('failed')->and($d->last_error)->toBe('txt_missing')->and($d->verified_at)->toBeNull();

    $this->records[$d->txtName()] = ['something else', $d->txtValue()];
    $this->post(route('site.admin.domain.check'));
    expect($d->refresh()->status)->toBe('verified')->and($d->verified_at)->not->toBeNull()->and($d->last_error)->toBeNull();
    expect(AuditEvent::query()->where('action', 'site_domain.verified')->count())->toBe(1);
});

it('a wrong token does not verify', function () {
    $this->actingAs($this->owner)->post(route('site.admin.domain.store'), ['hostname' => 'www.mysite.sa']);
    $d = SiteDomain::query()->firstOrFail();
    $this->records[$d->txtName()] = ['opsone-site-verification=not-the-token'];

    $this->post(route('site.admin.domain.check'));
    expect($d->refresh()->status)->toBe('failed');
});

it('a failed DNS lookup keeps a verified domain verified', function () {
    $this->actingAs($this->owner)->post(route('site.admin.domain.store'), ['hostname' => 'www.mysite.sa']);
    $d = SiteDomain::query()->firstOrFail();
    $this->records[$d->txtName()] = [$d->txtValue()];
    $this->post(route('site.admin.domain.check'));

    $this->lookupFails = true;
    $this->post(route('site.admin.domain.check'));
    expect($d->refresh()->status)->toBe('verified')->and($d->last_error)->toBe('lookup_failed');
});

it('can be removed, and the audit trail records request and removal', function () {
    $this->actingAs($this->owner)->post(route('site.admin.domain.store'), ['hostname' => 'www.mysite.sa']);
    $this->delete(route('site.admin.domain.destroy'))->assertSessionHasNoErrors();

    expect(SiteDomain::query()->count())->toBe(0)
        ->and(AuditEvent::query()->whereIn('action', ['site_domain.requested', 'site_domain.removed'])->count())->toBe(2);
});
