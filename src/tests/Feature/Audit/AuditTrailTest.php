<?php

/**
 * SHR-11 / ADR-0006: the audit trail is append-only (model and database), written in the same transaction as the
 * change, redacted, and wired into the P2 actions that change access, structure, settings and the license.
 */

use App\Access\TransitionRecorder;
use App\Actions\Organization\CreateBranch;
use App\Actions\Organization\UpdateBranch;
use App\Actions\Organization\UpdateFacilityProfile;
use App\Actions\Organization\UpdateRoleTemplate;
use App\Actions\StateMachines\M01Invitation\T00CreateInvitation;
use App\Actions\StateMachines\M01Invitation\T03CancelInvitation;
use App\Actions\StateMachines\M22Membership\T01DisableMembership;
use App\Actions\StateMachines\M22Membership\T02ReactivateMembership;
use App\Actions\StateMachines\M22Membership\T03UpdateMemberAccess;
use App\Actions\StateMachines\M37OwnershipTransfer\T00RequestOwnershipTransfer;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Audit\AuditRecorder;
use App\Enums\MembershipStatus;
use App\Licensing\LicenseInstaller;
use App\Models\AuditEvent;
use App\Models\RoleTemplate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\LicenseFactory;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
    $this->member = $this->org->member('sales', [$this->org->a]);
});

function lastAudit(AuditAction $action): ?AuditEvent
{
    return AuditEvent::query()->where('action', $action->value)->latest('id')->first();
}

describe('append-only', function () {
    beforeEach(function () {
        $this->event = DB::transaction(fn () => Audit::record(AuditAction::BranchUpdated, $this->org->a, $this->org->owner, ['code' => 'A'], ['code' => 'B']));
    });

    it('refuses updates and deletes through the model', function () {
        expect(fn () => $this->event->forceFill(['reason' => 'rewrite'])->save())->toThrow(LogicException::class)
            ->and(fn () => $this->event->delete())->toThrow(LogicException::class);
    });

    it('refuses raw updates and deletes at the database level', function () {
        expect(fn () => DB::table('audit_events')->where('id', $this->event->id)->update(['reason' => 'rewrite']))
            ->toThrow(QueryException::class)
            ->and(fn () => DB::table('audit_events')->where('id', $this->event->id)->delete())
            ->toThrow(QueryException::class)
            ->and(fn () => DB::table('audit_event_branches')->where('audit_event_id', $this->event->id)->delete())
            ->toThrow(QueryException::class);

        expect(AuditEvent::query()->find($this->event->id)?->reason)->toBeNull();
    });

    it('offers no route that edits or deletes an event', function () {
        $this->actingAs($this->org->owner);

        $this->put('/org/audit/'.$this->event->id)->assertNotFound();
        $this->delete('/org/audit')->assertStatus(405);
    });
});

describe('same transaction as the change', function () {
    it('refuses to record outside a transaction', function () {
        // RefreshDatabase keeps one transaction open; drop to level 0 to prove the guard.
        DB::rollBack(0);

        try {
            expect(fn () => Audit::record(AuditAction::BranchUpdated, null, null))->toThrow(LogicException::class);
        } finally {
            DB::beginTransaction();
        }
    });

    it('leaves no audit row when the change rolls back', function () {
        $membership = Org::membershipOf($this->member);

        try {
            DB::transaction(function () use ($membership) {
                app(T01DisableMembership::class)->handle($this->org->owner, $membership, 'left the company');

                throw new RuntimeException('a later step failed');
            });
        } catch (RuntimeException) {
        }

        expect(lastAudit(AuditAction::MembershipDisabled))->toBeNull()
            ->and($membership->refresh()->status)->toBe(MembershipStatus::Active);
    });

    it('records actor, before/after, reason, scope facts and request metadata', function () {
        $membership = Org::membershipOf($this->member);

        app()->instance('request', Request::create('/org/team', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'Pest/Agent']));

        app(T01DisableMembership::class)->handle($this->org->owner, $membership, 'left the company');

        $event = lastAudit(AuditAction::MembershipDisabled);
        expect($event)->not->toBeNull()
            ->and($event->actor_user_id)->toBe($this->org->owner->id)
            ->and($event->actor_name)->toBe($this->org->owner->name)
            ->and($event->subject_id)->toBe($membership->id)
            ->and($event->subject_label)->toBe($this->member->name)
            ->and($event->before)->toBe(['status' => 'active'])
            ->and($event->after)->toBe(['status' => 'disabled'])
            ->and($event->reason)->toBe('left the company')
            ->and($event->accessBranchIds())->toBe([$this->org->a->id])
            ->and($event->ip_hash)->toHaveLength(64)
            ->and($event->ip_hash)->not->toContain('203.0.113.9')
            ->and($event->user_agent)->toBe('Pest/Agent')
            ->and($event->request_id)->not->toBeEmpty();
    });
});

it('redacts secrets, tokens and codes but keeps ordinary fields', function () {
    $event = DB::transaction(fn () => Audit::record(AuditAction::BranchUpdated, null, null, after: [
        'code' => 'BR-1',
        'password' => 'hunter2',
        'api_token' => 'tok',
        'token_hash' => 'abc',
        'nested' => ['client_secret' => 's', 'name' => 'ok'],
        'two_factor_secret' => 'x',
    ]));

    expect($event->after)->toBe([
        'code' => 'BR-1',
        'password' => AuditRecorder::REDACTED,
        'api_token' => AuditRecorder::REDACTED,
        'token_hash' => AuditRecorder::REDACTED,
        'nested' => ['client_secret' => AuditRecorder::REDACTED, 'name' => 'ok'],
        'two_factor_secret' => AuditRecorder::REDACTED,
    ]);
});

describe('wired into the P2 actions', function () {
    it('audits membership reactivation and access changes', function () {
        $membership = Org::membershipOf($this->member);
        app(T01DisableMembership::class)->handle($this->org->owner, $membership, 'leave');
        app(T02ReactivateMembership::class)->handle($this->org->owner, $membership, 'back');
        app(T03UpdateMemberAccess::class)->handle($this->org->owner, $membership, [
            ['role_template_id' => Org::templateId('sales')],
            ['role_template_id' => Org::templateId('finance'), 'scope' => 'branches', 'targets' => [$this->org->a->id]],
        ], [], 'promotion');

        $access = lastAudit(AuditAction::MembershipAccessUpdated);
        expect(lastAudit(AuditAction::MembershipReactivated)?->reason)->toBe('back')
            ->and($access?->reason)->toBe('promotion')
            ->and($access?->before['roles'])->toHaveCount(1)
            ->and($access?->after['roles'])->toHaveCount(2);
    });

    it('audits invitations without storing the full email', function () {
        [$invitation] = app(T00CreateInvitation::class)->handle($this->org->owner, [
            'email' => 'new.person@example.com',
            'branch_ids' => [$this->org->b->id],
            'roles' => [['role_template_id' => Org::templateId('sales')]],
        ]);
        app(T03CancelInvitation::class)->handle($this->org->owner, $invitation, 'typo');

        $created = lastAudit(AuditAction::InvitationCreated);
        expect($created?->subject_label)->toBe('n***@example.com')
            ->and(json_encode($created?->after))->not->toContain('new.person')
            ->and($created?->accessBranchIds())->toBe([$this->org->b->id])
            ->and(lastAudit(AuditAction::InvitationCancelled)?->reason)->toBe('typo');
    });

    it('audits an ownership transfer request', function () {
        $manager = $this->org->member('manager', [$this->org->a]);

        app(T00RequestOwnershipTransfer::class)->handle($this->org->owner, 'password', Org::membershipOf($manager), 'retiring');

        expect(lastAudit(AuditAction::OwnershipTransferRequested)?->reason)->toBe('retiring');
    });

    it('audits branch, facility and role-template changes with before and after', function () {
        LicenseFactory::install(['limits' => ['seats' => 15, 'branches' => 5, 'storage_mb' => 25600]]);
        app(CreateBranch::class)->handle($this->org->owner, ['name_ar' => 'فرع', 'name_en' => 'Branch']);
        expect(lastAudit(AuditAction::BranchCreated)?->subject_label)->toBe('فرع / Branch');

        app(UpdateBranch::class)->handle($this->org->owner, $this->org->a, ['code' => 'NEW']);
        $update = lastAudit(AuditAction::BranchUpdated);
        expect($update?->after)->toBe(['code' => 'NEW'])
            ->and($update?->before)->toHaveKey('code');

        app(UpdateFacilityProfile::class)->handle($this->org->owner, ['name_en' => 'Renamed']);
        expect(lastAudit(AuditAction::FacilityProfileUpdated)?->after)->toBe(['name_en' => 'Renamed'])
            ->and(lastAudit(AuditAction::FacilityProfileUpdated)?->before)->toBe(['name_en' => 'Test facility']);

        $template = RoleTemplate::query()->where('key', 'sales')->firstOrFail();
        app(UpdateRoleTemplate::class)->handle($this->org->owner, $template, [['permission' => 'P-09', 'scope' => 'tenant']]);
        expect(lastAudit(AuditAction::RoleTemplateUpdated)?->after)->toBe(['grants' => ['P-09' => 'tenant']]);
    });

    it('audits a license installation', function () {
        app(LicenseInstaller::class)->install(
            LicenseFactory::signed(['issued_at' => now()->subDay()->toIso8601ZuluString()]),
            $this->org->owner,
            TransitionRecorder::SOURCE_WEB,
        );

        $event = lastAudit(AuditAction::LicenseInstalled);
        expect($event?->after['plan'])->toBe('growth')
            ->and($event?->before)->toHaveKey('license_id')
            ->and($event?->source)->toBe('web');
    });
});
