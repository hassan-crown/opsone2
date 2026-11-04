<?php

namespace Database\Seeders\ShowcaseKit;

use App\Access\TransitionRecorder;
use App\Actions\StateMachines\M03Interest\T00CreateInterest;
use App\Actions\StateMachines\M03Interest\T01TakeFollowUp;
use App\Actions\StateMachines\M03Interest\T02ConvertInterest;
use App\Actions\StateMachines\M03Interest\T03CloseInterest;
use App\Actions\StateMachines\M08Document\T00CreateQuote;
use App\Actions\StateMachines\M08Document\T01SendDocument;
use App\Actions\StateMachines\M08Document\T02RecordExternalApproval;
use App\Actions\StateMachines\M08Document\UpdateDocumentDraft;
use App\Actions\StateMachines\M11Payment\T01RecordPayment;
use App\Actions\StateMachines\M16Appointment\T00ProposeAppointment;
use App\Actions\StateMachines\M16Appointment\T01SendToCustomer;
use App\Actions\StateMachines\M16Appointment\T02ConfirmAppointment;
use App\Actions\Workflow\RecordMeasurement;
use App\Enums\InterestCloseReason;
use App\FieldSales\FieldDispatch;
use App\FieldSales\FieldRepAccess;
use App\FieldSales\SalesVisits;
use App\Models\Agreements\Agreement;
use App\Models\Agreements\AgreementDocument;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\FieldSales\FieldAssignment;
use App\Models\FieldSales\SalesVisit;
use App\Models\Interest;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Field sales (ADR-0041) from release 2 (2026-08-02), in Riyadh: the sales supervisor dispatches leads to the field
 * rep, who takes them, converts most into field orders, visits the customer at home (notes, a site photo,
 * measurements), prepares and sends the quote; the manager records the customer's approval and finance the first
 * payment, so the rep's commission accrues through the existing policy. At the end of the story the rep has a late
 * open lead, a dispatched appointment request and a visit booked for the next day, and one lead waits on the board.
 * The rep's limits are set by the team: a 3% discount (owner's ceiling 5%) and no contract preparation.
 * Its own random sequence, so the rest of the showcase is unchanged by it.
 */
final class FieldSales
{
    /** Two-part demo names (first name + father's name). */
    private const LEADS = [
        ['at' => '2026-08-04', 'name' => 'سعد عبدالله', 'need' => 'مطبخ جديد لفيلا في حي الملقا مع جزيرة وسطية', 'path' => 'paid'],
        ['at' => '2026-08-17', 'name' => 'ماجد حسن', 'need' => 'تجديد مطبخ شقة: خزائن علوية وسفلية وسطح رخام', 'path' => 'paid'],
        ['at' => '2026-09-01', 'name' => 'نايف إبراهيم', 'need' => 'مطبخ على شكل حرف U مع مخزن جانبي', 'path' => 'agreed'],
        ['at' => '2026-09-15', 'name' => 'بندر صالح', 'need' => 'مطبخ استراحة خارجي مقاوم للرطوبة', 'path' => 'lost'],
        ['at' => '2026-09-29', 'name' => 'راشد محمد', 'need' => 'مطبخ حديث بلون داكن وإضاءة مخفية', 'path' => 'quoted'],
        ['at' => '2026-10-13', 'name' => 'عبدالرحمن فهد', 'need' => 'مطبخ مفتوح على الصالة مع طاولة طعام', 'path' => 'visited'],
        ['at' => '2026-10-27', 'name' => 'تركي سليمان', 'need' => 'تبديل أبواب المطبخ وسطح العمل', 'path' => 'booked'],
        ['at' => '2026-10-28', 'name' => 'وليد ناصر', 'need' => 'مطبخ لشقة جديدة، يفضّل الزيارة مساءً', 'path' => 'late'],
        ['at' => '2026-10-31', 'name' => 'فيصل عمر', 'need' => 'مطبخ مع غرفة غسيل ملحقة', 'path' => 'waiting'],
    ];

    private Rng $rng;

    public function __construct(private readonly Showcase $s)
    {
        $this->rng = new Rng(20261104);
    }

    public function run(): void
    {
        // The team's limits: the general manager gives the rep a 3% discount (owner's ceiling 5%), the supervisor
        // keeps contracts with the showroom.
        $this->s->timeline->at(CarbonImmutable::parse('2026-08-03 09:00', 'Asia/Riyadh'), new Story('field-sales-team'), 'limits', function () {
            $access = app(FieldRepAccess::class);
            $access->setDiscount($this->s->users['gm'], $this->rep(), 300, TransitionRecorder::SOURCE_SEEDER);
            $access->set($this->s->users['sup_sales'], $this->rep(), 'P-32', false, TransitionRecorder::SOURCE_SEEDER);
        });

        foreach (self::LEADS as $i => $lead) {
            $story = new Story('field-sales-'.$i);
            $day = CarbonImmutable::parse($lead['at'].' 09:20', 'Asia/Riyadh');
            $this->s->timeline->at($day, $story, 'lead', fn (Story $st) => $this->lead($st, $i, $lead));
            if ($lead['path'] === 'waiting') {
                continue;
            }
            $this->s->timeline->at($day->setTime(10, 0), $story, 'dispatch', fn (Story $st) => $this->dispatch($st));
            if ($lead['path'] === 'late') {
                continue;
            }
            $this->s->timeline->at($day->setTime(13, 30), $story, 'follow', fn (Story $st) => app(T01TakeFollowUp::class)->handle($this->rep(), $this->interest($st)));
            if ($lead['path'] === 'lost') {
                $this->s->timeline->at($day->addDay()->setTime(12, 0), $story, 'close', fn (Story $st) => app(T03CloseInterest::class)->handle(
                    $this->rep(), $this->interest($st), InterestCloseReason::Price, 'العرض الشفهي أعلى من ميزانيته؛ سيعيد التواصل بعد العيد.'));

                continue;
            }
            $this->s->timeline->at($day->addDay()->setTime(11, 0), $story, 'convert', fn (Story $st) => $this->convert($st));
            $visitDay = $lead['path'] === 'booked' ? CarbonImmutable::parse('2026-11-02 17:00', 'Asia/Riyadh') : $this->workday($day->addDays(3))->setTime(17, 0);
            $this->s->timeline->at($day->addDay()->setTime(12, 0), $story, 'book', fn (Story $st) => $this->book($st, $visitDay, $i % 2 === 0 ? 'measurement' : 'visit'));
            if ($lead['path'] === 'booked') {
                continue;
            }
            $this->s->timeline->at($visitDay->addMinutes(10), $story, 'visit', fn (Story $st) => $this->visit($st));
            if ($lead['path'] === 'visited') {
                continue;
            }
            $this->s->timeline->at($visitDay->addDay()->setTime(10, 30), $story, 'quote', fn (Story $st) => $this->quote($st));
            if ($lead['path'] === 'quoted') {
                continue;
            }
            $this->s->timeline->at($visitDay->addDays(3)->setTime(12, 0), $story, 'approve', fn (Story $st) => $this->approve($st));
            if ($lead['path'] === 'paid') {
                $this->s->timeline->at($visitDay->addDays(4)->setTime(11, 0), $story, 'pay', fn (Story $st) => $this->pay($st));
            }
        }

        // A customer's visit request from the site, handed to the rep at the end of the story.
        $story = new Story('field-sales-request');
        $this->s->timeline->at(CarbonImmutable::parse('2026-10-31 15:00', 'Asia/Riyadh'), $story, 'dispatch-request', function () {
            $request = AppointmentRequest::query()->where('kind', 'visit')->whereIn('status', ['new'])
                ->where('branch_id', $this->s->branches['RUH']->id)->orderBy('id')->first();
            if ($request !== null) {
                app(FieldDispatch::class)->assignRequest($this->s->users['sup_sales'], $request, $this->rep()->id, null, 'زيارة منزلية لعرض الخامات');
            }
        });
    }

    /**
     * @param  array{at: string, name: string, need: string, path: string}  $lead
     */
    private function lead(Story $st, int $i, array $lead): void
    {
        $interest = app(T00CreateInterest::class)->handle($this->s->users['gm'], [
            'name' => $lead['name'], 'mobile' => sprintf('05077%05d', 100 + $i), 'need' => $lead['need'],
            'channel' => $this->rng->pick(['phone', 'whatsapp', 'website']), 'branch_id' => $this->s->branches['RUH']->id,
        ]);
        $st->data['interest'] = $interest->id;
    }

    private function dispatch(Story $st): void
    {
        $assignment = app(FieldDispatch::class)->assignLead($this->s->users['sup_sales'], $this->interest($st), $this->rep()->id,
            $this->rng->pick(['العميل يفضّل الاتصال بعد العصر', 'يرغب بزيارة منزلية لأخذ المقاسات', null]));
        // The late lead: its response time ran out before the end of the story.
        if (self::LEADS[$this->index($st)]['path'] === 'late') {
            FieldAssignment::query()->whereKey($assignment->id)->update(['due_at' => CarbonImmutable::parse('2026-10-29 10:00', 'Asia/Riyadh')->utc()]);
        }
    }

    private function convert(Story $st): void
    {
        $interest = app(T02ConvertInterest::class)->handle($this->rep(), $this->interest($st));
        $st->data['order'] = $interest->converted_order_id;
    }

    private function book(Story $st, CarbonImmutable $start, string $kind): void
    {
        $gm = $this->s->users['gm'];
        $order = $this->order($st);
        $appointment = app(T00ProposeAppointment::class)->handle($gm, $order, [
            'kind' => $kind, 'starts_at' => $start->format('Y-m-d H:i').' Asia/Riyadh', 'ends_at' => $start->addHour()->format('Y-m-d H:i').' Asia/Riyadh',
            'address' => 'حي '.$this->rng->pick(['الملقا', 'النرجس', 'الياسمين', 'حطين', 'العارض']).'، الرياض — شارع '.$this->rng->int(5, 60),
            'member_ids' => [$this->rep()->id],
        ])['appointment'];
        $appointment = app(T01SendToCustomer::class)->handle($gm, $appointment);
        $appointment = app(T02ConfirmAppointment::class)->handle($gm, Appointment::query()->with('order')->findOrFail($appointment->id), 'phone');
        $st->data['appointment'] = $appointment->id;
    }

    private function visit(Story $st): void
    {
        $rep = $this->rep();
        $order = $this->order($st);
        $visits = app(SalesVisits::class);
        $visit = $visits->start($rep, Appointment::query()->findOrFail($st->int('appointment')));
        $visit = $visits->note($rep, $visit, $this->rng->pick([
            'صاحب القرار الزوج والزوجة معًا؛ يفضّلون الخشب الفاتح ومقابض مخفية.',
            'الموقع جاهز، تمديدات الماء على الجدار الشمالي، يحتاج فتحة شفاط.',
            'يقارن بعرض منافس؛ مهتم بالضمان ومدة التنفيذ أكثر من السعر.',
        ]));
        $this->s->assets->attach($rep, $visit, $this->rng->pick(array_merge(...array_values(Assets::KITCHENS))), SalesVisits::PHOTO_PURPOSE);
        $length = $this->rng->int(30, 62) / 10;
        $st->data['length'] = $length;
        foreach ([['الجدار الرئيسي', 'الطول', $length], ['ارتفاع السقف', 'الارتفاع', 2.8 + $this->rng->int(0, 5) / 10]] as [$place, $name, $value]) {
            app(RecordMeasurement::class)->handle($rep, $order, [
                'location' => $place, 'name' => $name, 'value' => number_format($value, 3, '.', ''), 'unit' => 'm', 'kind' => 'estimated',
                'measured_on' => CarbonImmutable::now('Asia/Riyadh')->toDateString(),
            ]);
        }
        $visits->finish($rep, SalesVisit::query()->findOrFail($visit->id), 'اتُّفق على إرسال عرض السعر غدًا مع خيارين للسطح.');
    }

    private function quote(Story $st): void
    {
        $rep = $this->rep();
        $order = $this->order($st);
        $length = (float) ($st->data['length'] ?? 4.2);
        $quote = app(T00CreateQuote::class)->handle($rep, $order, (string) Str::uuid());
        $quote = app(UpdateDocumentDraft::class)->handle($rep, $quote, [
            'lines' => [
                ['description' => 'مطبخ خشب MDF مقاوم للرطوبة', 'quantity' => (string) $length, 'unit' => 'm', 'unit_price' => (string) ($this->rng->int(26, 34) * 100), 'commissionable' => true],
                ['description' => 'سطح عمل كوارتز مع حوض مدمج', 'quantity' => '1', 'unit' => 'unit', 'unit_price' => (string) round($length * 650), 'commissionable' => true],
                ['description' => 'تركيب وتوصيل', 'quantity' => '1', 'unit' => 'unit', 'unit_price' => '1200', 'commissionable' => false],
            ],
            'discount' => ['type' => 'none'],
            'terms' => 'دفعة أولى 50% عند الاعتماد، والباقي عند اكتمال التركيب.',
            'execution_days' => 30,
        ], $quote->lock_version, (string) Str::uuid());
        $quote = app(T01SendDocument::class)->handle($rep, $quote->refresh(), 21, $quote->lock_version, (string) Str::uuid());
        $st->data['quote'] = $quote->id;
    }

    private function approve(Story $st): void
    {
        $doc = AgreementDocument::query()->findOrFail($st->int('quote'));
        app(T02RecordExternalApproval::class)->handle($this->s->users['gm'], $doc, [
            'channel' => 'in_person', 'decided_on' => CarbonImmutable::now('Asia/Riyadh')->toDateString(),
            'note' => 'وقّع العميل العرض أثناء زيارة البائع', 'content_hash' => (string) $doc->content_hash,
        ], Assets::pdf('field-approval.pdf', 'Signed quote'), $doc->lock_version, (string) Str::uuid());
    }

    private function pay(Story $st): void
    {
        $order = $this->order($st);
        $total = (int) Agreement::query()->where('order_id', $order->id)->where('status', 'active')->value('total_minor');
        if ($total <= 0) {
            return;
        }
        app(T01RecordPayment::class)->handle($this->s->users['finance'], $order->refresh(), [
            'amount' => number_format(intdiv($total, 200), 2, '.', ''), 'method' => 'transfer', 'paid_on' => CarbonImmutable::now('Asia/Riyadh')->toDateString(),
            'reference' => 'FT'.$this->rng->int(2600000000, 2699999999), 'note' => 'الدفعة الأولى — بيع ميداني', 'acknowledge_similar' => true,
        ], Assets::pdf('transfer-'.$order->number.'.pdf', 'Transfer'), null, (string) Str::uuid());
    }

    private function workday(CarbonImmutable $day): CarbonImmutable
    {
        while (in_array($day->dayOfWeek, [5, 6], true)) {
            $day = $day->addDay();
        }

        return $day;
    }

    private function rep(): User
    {
        return $this->s->users['field_rep'];
    }

    private function interest(Story $st): Interest
    {
        return Interest::query()->findOrFail($st->int('interest'));
    }

    private function order(Story $st): Order
    {
        return Order::query()->findOrFail($st->int('order'));
    }

    private function index(Story $st): int
    {
        return (int) substr($st->name, strlen('field-sales-'));
    }
}
