<?php

use App\Actions\FieldSales\UpdateFieldSalesSettings;
use App\Actions\StateMachines\M03Interest\T00CreateInterest;
use App\Actions\StateMachines\M16Appointment\T00ProposeAppointment;
use App\Actions\StateMachines\M16Appointment\T02ConfirmAppointment;
use App\Configuration\ConfigReleases;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\Branch;
use App\Models\Interest;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

require_once __DIR__.'/../Agreements/AgreementTestHelpers.php';
require_once __DIR__.'/../Rules/RulesTestHelpers.php';
require_once __DIR__.'/../Commissions/CommissionTestHelpers.php';

/**
 * The agreement fixture (owner, manager, supervisor of branch A, sales and an order in A) plus two field sales reps —
 * one in A, one in B — and field sales turned on and published (ADR-0041).
 */
function fieldSalesFixture(bool $enabled = true): object
{
    $f = agreementFixture();
    $f->rep = $f->org->member('field_sales', [$f->org->a]);
    $f->repB = $f->org->member('field_sales', [$f->org->b]);
    $f->supervisorB = $f->org->member('supervisor', [$f->org->b], $f->org->d1, 'branches');
    if ($enabled) {
        fieldSalesOn($f->owner);
    }

    return $f;
}

/**
 * @param  list<string>  $kinds
 */
function fieldSalesOn(User $owner, array $kinds = ['visit', 'measurement'], ?int $hours = 24, bool $publish = true): void
{
    app(UpdateFieldSalesSettings::class)->handle($owner, ['enabled' => true, 'kinds' => $kinds, 'response_hours' => $hours]);
    if ($publish) {
        publishRules($owner);
    }
    ConfigReleases::forget();
}

function fieldLead(User $actor, Branch $branch, string $name = 'سالم أحمد'): Interest
{
    return app(T00CreateInterest::class)->handle($actor, [
        'name' => $name, 'mobile' => '05'.random_int(10000000, 99999999), 'need' => 'مطبخ جديد مع جزيرة', 'channel' => 'phone',
        'branch_id' => $branch->id,
    ]);
}

function fieldRequest(Branch $branch, string $kind = 'visit'): AppointmentRequest
{
    return AppointmentRequest::query()->create([
        'reference' => 'AR-'.random_int(1000, 9999), 'submission_key' => (string) Str::uuid(), 'source' => 'site', 'status' => 'new',
        'kind' => $kind, 'name' => 'خالد سعد', 'phone' => '0551234567', 'city' => 'الرياض', 'branch_id' => $branch->id,
    ]);
}

/** A confirmed visit appointment on the order tomorrow with the given members. */
function fieldVisit(User $booker, Order $order, array $members, string $kind = 'visit'): Appointment
{
    $start = CarbonImmutable::parse('+1 day', 'Asia/Riyadh')->setTime(17, 0);
    $appointment = app(T00ProposeAppointment::class)->handle($booker, $order, [
        'kind' => $kind, 'starts_at' => $start->format('Y-m-d H:i').' Asia/Riyadh', 'ends_at' => $start->addHour()->format('Y-m-d H:i').' Asia/Riyadh',
        'address' => 'الرياض، حي الياسمين، شارع 4', 'member_ids' => array_map(fn (User $u) => $u->id, $members),
    ])['appointment'];

    return app(T02ConfirmAppointment::class)->handle($booker, $appointment, 'phone')->refresh();
}
