<?php

namespace Database\Seeders\ShowcaseKit;

use App\Actions\AppointmentRequests\AssignAppointmentRequest;
use App\Actions\AppointmentRequests\CloseAppointmentRequest;
use App\Actions\AppointmentRequests\LinkScheduledAppointment;
use App\Actions\AppointmentRequests\SubmitAppointmentRequest;
use App\Actions\StateMachines\M16Appointment\T00ProposeAppointment;
use App\Actions\StateMachines\M16Appointment\T02ConfirmAppointment;
use App\AppointmentRequests\AppointmentRequests as Requests;
use App\Enums\AppointmentRequestCloseReason;
use App\Models\AppointmentRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Support\LockingTransaction;
use Carbon\CarbonImmutable;

/**
 * Customer appointment requests (ADR-0039) in the last days of the showcase: visitors asking from the public site,
 * workspace customers asking on their order, and the queue in every state — new, assigned (one late), booked from the
 * request and closed with a reason. Keys are fixed, so a re-run replays instead of duplicating.
 */
final class AppointmentRequests
{
    private const SITE = [
        ['at' => '2026-10-29 10:15', 'name' => 'ريم الحربي', 'phone' => '0551203344', 'kind' => 'measurement', 'city' => 'الرياض', 'address' => 'حي الياسمين، شارع الأمير سعود', 'note' => 'مطبخ على شكل حرف L مع جزيرة', 'time' => 'morning', 'days' => 4],
        ['at' => '2026-10-30 18:40', 'name' => 'فهد العتيبي', 'phone' => '0552304455', 'kind' => 'visit', 'city' => 'الرياض', 'address' => null, 'note' => 'أرغب بزيارة المعرض ومناقشة الخامات', 'time' => 'evening', 'days' => 3],
        ['at' => '2026-10-31 09:05', 'name' => 'Sarah Al-Qahtani', 'phone' => '0553405566', 'kind' => 'measurement', 'city' => 'جدة', 'address' => 'حي الشاطئ', 'note' => 'Renovating the kitchen of a villa', 'time' => 'afternoon', 'days' => 6],
        ['at' => '2026-11-01 08:30', 'name' => 'عبدالله الدوسري', 'phone' => '0554506677', 'kind' => 'visit', 'city' => 'الدمام', 'address' => null, 'note' => null, 'time' => 'any', 'days' => 2],
        ['at' => '2026-10-28 11:20', 'name' => 'منيرة الشمري', 'phone' => '0555607788', 'kind' => 'measurement', 'city' => 'الرياض', 'address' => 'حي النرجس', 'note' => 'تكرر الطلب من نفس الرقم', 'time' => 'morning', 'days' => 5],
    ];

    public function __construct(private readonly Showcase $s) {}

    public function run(): void
    {
        $story = new Story('appointment-requests');
        foreach (self::SITE as $i => $row) {
            $this->s->timeline->at(CarbonImmutable::parse($row['at'], 'Asia/Riyadh'), $story, 'site-request-'.$i, function () use ($row, $i) {
                app(SubmitAppointmentRequest::class)->fromSite(self::key('site', $i), [
                    'name' => $row['name'], 'phone' => $row['phone'], 'kind' => $row['kind'], 'city' => $row['city'], 'address' => $row['address'],
                    'note' => $row['note'], 'time_of_day' => $row['time'],
                    'preferred_from' => CarbonImmutable::now('Asia/Riyadh')->addDays($row['days'])->format('Y-m-d'),
                    'preferred_to' => CarbonImmutable::now('Asia/Riyadh')->addDays($row['days'] + 3)->format('Y-m-d'),
                ]);
            });
        }

        // Workspace customers asking on their open order (the portal accounts made earlier in the story).
        $this->s->timeline->at(CarbonImmutable::parse('2026-10-30 20:10', 'Asia/Riyadh'), $story, 'portal-requests', function () {
            $customers = Customer::query()->where('portal_login_enabled', true)->orderBy('id')->limit(2)->get();
            foreach ($customers as $i => $customer) {
                $order = Order::query()->where('customer_id', $customer->id)->orderByDesc('id')->get()->first(fn (Order $o) => ! $o->state()->isFinal());
                app(SubmitAppointmentRequest::class)->fromPortal($customer, self::key('portal', $i), [
                    'kind' => $i === 0 ? 'measurement' : 'visit', 'order_id' => $order?->id, 'city' => $i === 0 ? 'الرياض' : 'جدة',
                    'address' => $i === 0 ? 'نفس عنوان الطلب' : null, 'time_of_day' => 'afternoon',
                    'preferred_from' => CarbonImmutable::now('Asia/Riyadh')->addDays(3)->format('Y-m-d'),
                    'note' => $i === 0 ? 'أرجو التواصل بعد العصر' : null,
                ]);
            }
        });

        // The team works the queue: assign, book one from its request, close the duplicate.
        $this->s->timeline->at(CarbonImmutable::parse('2026-10-31 10:00', 'Asia/Riyadh'), $story, 'queue', function () {
            $gm = $this->s->users['gm'];
            $assign = app(AssignAppointmentRequest::class);
            $bySite = fn (int $i) => AppointmentRequest::query()->where('submission_key', self::key('site', $i))->first();
            // A salesperson of the request's branch when there is one, else the general manager.
            $worker = function (AppointmentRequest $request) use ($gm): int {
                $ids = array_column(Requests::assignable($request), 'id');
                $sales = array_values(array_intersect(array_map(fn (string $k) => $this->s->users[$k]->id, ['sales_ruh1', 'sales_ruh2', 'sales_jed', 'sales_dmm']), $ids));

                return $sales[0] ?? $gm->id;
            };

            if (($late = $bySite(0)) !== null) {
                $assign->handle($gm, $late, $worker($late), null);
                // Its response time passed before the end of the story: the queue shows it late.
                AppointmentRequest::query()->whereKey($late->id)->update(['due_at' => CarbonImmutable::parse('2026-10-31 18:00', 'Asia/Riyadh')->utc()]);
            }
            if (($duplicate = $bySite(4)) !== null) {
                app(CloseAppointmentRequest::class)->handle($gm, $duplicate, AppointmentRequestCloseReason::Duplicate, 'نفس العميل أرسل طلبًا سابقًا بالهاتف', null);
            }
            $portal = AppointmentRequest::query()->where('submission_key', self::key('portal', 0))->first();
            if ($portal !== null && $portal->order_id !== null) {
                $assign->handle($gm, $portal, $worker($portal), null);
                $this->book($portal->refresh());
            }
        });
    }

    private function book(AppointmentRequest $request): void
    {
        $booker = $this->s->users['gm'];
        $order = Order::query()->findOrFail($request->order_id);
        $branch = array_search($order->branch_id, array_map(fn ($b) => $b->id, $this->s->branches), true);
        $tech = $this->s->users[$branch === 'JED' ? 'inst_jed' : 'inst_ruh'];
        $start = CarbonImmutable::now('Asia/Riyadh')->addDays(3)->setTime(16, 0);
        while (in_array($start->dayOfWeek, [5, 6], true)) {
            $start = $start->addDay();
        }

        LockingTransaction::run(function () use ($request, $order, $booker, $tech, $start) {
            $appointment = app(T00ProposeAppointment::class)->handle($booker, $order, [
                'kind' => $request->kind, 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->addHour()->toIso8601String(),
                'address' => trim(implode('، ', array_filter([$request->city, $request->address]))), 'member_ids' => [$tech->id],
            ])['appointment'];
            $appointment = app(T02ConfirmAppointment::class)->handle($booker, $appointment, 'phone', null);
            app(LinkScheduledAppointment::class)->handle($booker, $request->id, $appointment);
        });
    }

    private static function key(string $source, int $i): string
    {
        return sprintf('0a9f0000-0000-4000-8000-%s%07d', $source === 'site' ? '00001' : '00002', $i);
    }
}
