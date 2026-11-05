<?php

namespace Database\Seeders\ShowcaseKit;

use App\Actions\StateMachines\M21Ticket\ReplyToTicket;
use App\Actions\StateMachines\M21Ticket\T01TakeTicket;
use App\Actions\StateMachines\M21Ticket\T02RequestInformation;
use App\Actions\StateMachines\M21Ticket\T04ResolveTicket;
use App\Actions\StateMachines\M21Ticket\T05CloseTicket;
use App\Actions\StateMachines\M21Ticket\T06ReopenTicket;
use App\Agreements\CustomerActor;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Tickets\Ticket;
use App\Models\Tickets\TicketMessage;
use App\Models\User;
use App\Portal\CustomerTickets;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * About 25 support tickets from customers' own accounts — questions, issues and requests on real orders — worked by
 * staff with public replies and internal notes, in every state (new, in progress, waiting for the customer, resolved,
 * closed, one reopened).
 */
final class Support
{
    /** @var list<array{0: string, 1: string, 2: string, 3: string, 4: string}> type, subject, body, staff reply, resolution */
    private const TOPICS = [
        ['issue', 'ضبط باب الخزانة العلوية', 'بعد التركيب بأسبوعين لاحظت أن باب الخزانة العلوية بجانب الشفاط لا يغلق بشكل مستقيم، أرجو إرسال فني لضبط المفصلات.', 'شكرًا لتواصلكم، سنرسل فني التركيب لضبط المفصلات. هل يناسبكم يوم الثلاثاء الصباح؟', 'تم ضبط المفصلات وتبديل مفصلة واحدة تالفة، والباب يعمل بشكل سليم.'],
        ['question', 'موعد التسليم المتوقع', 'أرغب بمعرفة الموعد المتوقع لانتهاء التصنيع وبدء التركيب، فلدينا حجز لمقاول السباكة.', 'مطبخكم في مرحلة التصنيع حاليًا، ونتوقع الانتهاء خلال أسبوعين تقريبًا وسنؤكد موعد التركيب قبلها بثلاثة أيام.', 'تم تأكيد موعد التركيب وإبلاغ العميل بالتفاصيل.'],
        ['request', 'طلب فاتورة ضريبية', 'نحتاج فاتورة ضريبية باسم الشركة ورقمها الضريبي للطلب الأخير لتقديمها للمحاسبة.', 'استلمنا طلبكم، يرجى تزويدنا بالرقم الضريبي واسم الشركة كما في السجل التجاري.', 'أُرسلت الفاتورة الضريبية على البريد الإلكتروني المسجل.'],
        ['issue', 'خدش في سطح الكوارتز', 'ظهر خدش صغير في سطح الكوارتز قرب الحوض بعد التركيب. أرفقت صورة.', 'نأسف للإزعاج. سنعاين الخدش ونتأكد إن كان يغطيه الضمان.', 'عاين الفني الخدش وتمت معالجته بالتلميع ضمن الضمان.'],
        ['question', 'تغيير لون المقابض', 'هل يمكن تغيير لون المقابض إلى الذهبي قبل التصنيع؟ وكم الفرق في السعر؟', 'نعم يمكن ذلك قبل بدء التصنيع بفرق بسيط، سنرسل لكم ملحق تعديل الاتفاق.', 'أُصدر ملحق تعديل الاتفاق واعتُمد من العميل.'],
        ['issue', 'صوت من الأدراج عند الإغلاق', 'درج الأواني يصدر صوتًا مزعجًا عند الإغلاق بقوة، هل هناك حل؟', 'سنضبط ماصّ الصدمات في الدرج ونتأكد من المجرى.', 'تم تركيب ماصّات جديدة وضبط المجرى.'],
        ['request', 'طلب إضافة رف للمطبخ', 'نرغب بإضافة رف خشبي مفتوح فوق الجزيرة، هل يمكن تنفيذه بعد التسليم؟', 'يمكن ذلك بملحق مستقل، سيتواصل معكم مستشار المبيعات بعرض السعر.', 'أُرسل عرض السعر للعميل وتم اعتماده.'],
        ['question', 'طريقة تنظيف الواجهات الأكريليك', 'ما أفضل طريقة لتنظيف واجهات الأكريليك اللامع دون خدشها؟', 'ننصح بقطعة قماش ميكروفايبر مبللة بالماء والصابون الخفيف وتجنب المواد الكاشطة.', 'تم الرد على الاستفسار وإرسال دليل العناية.'],
        ['issue', 'تأخر في التركيب', 'تأخر الفنيون عن موعد التركيب المتفق عليه بيومين دون اتصال مسبق.', 'نعتذر عن التأخير، حدث تعارض في جدول الفنيين. تم تثبيت موعد جديد لكم صباح الأحد.', 'تم التركيب في الموعد الجديد وقُدم اعتذار رسمي للعميل.'],
        ['request', 'نسخة من وثيقة الضمان', 'أحتاج نسخة مطبوعة من ضمان التصنيع والتركيب.', 'سنرسل نسخة موقعة مع مندوبنا خلال يومين.', 'سُلّمت نسخة الضمان.'],
        ['other', 'ملاحظة على نظافة الموقع', 'ترك الفنيون بعض بقايا التغليف في الممر بعد التركيب.', 'نعتذر، سنرسل فريق تنظيف اليوم.', 'تم تنظيف الموقع وتأكيد رضا العميل.'],
        ['question', 'إمكانية تقسيط الدفعة الأخيرة', 'هل يمكن تقسيم الدفعة الأخيرة على دفعتين؟', 'سنراجع الطلب مع المسؤول المالي ونوافيكم بالرد.', 'اعتُمد تقسيط الدفعة الأخيرة على دفعتين بموافقة المدير.'],
        ['issue', 'نقص في ملحقات الطلب الإلكتروني', 'استلمت الطلب من المعرض ولم أجد ماصّات الصدمات المذكورة في الفاتورة.', 'تم التأكد من الفاتورة وسنوفر القطع الناقصة حالًا في أقرب فرصة.', 'سُلّمت القطع الناقصة للعميل من الفرع.'],
        ['request', 'استبدال خلاط المطبخ', 'الخلاط الذي اشتريته يسرب الماء من القاعدة، أرغب باستبداله.', 'سنفحص الخلاط ونستبدله إذا تأكد العيب المصنعي، يرجى إحضاره للفرع.', 'استُبدل الخلاط بآخر جديد بعد فحص الفرع.'],
    ];

    public function __construct(private readonly Showcase $s) {}

    public function run(): void
    {
        $rng = $this->s->rng;
        $n = $this->s->n(25);
        $span = (int) $this->s->start->diffInDays($this->s->end);
        for ($i = 0; $i < $n; $i++) {
            $day = (int) floor(($span - 6) * sqrt($rng->int(1, 10000) / 10000));
            $when = $this->s->start->addDays(max(50, $day))->setTime($rng->int(9, 18), $rng->int(0, 59));
            while (in_array($when->dayOfWeek, [5, 6], true)) {
                $when = $when->addDay();
            }
            $topic = self::TOPICS[$i % count(self::TOPICS)];
            $fate = $rng->weighted(['closed' => 36, 'resolved' => 18, 'progress' => 16, 'awaiting' => 12, 'new' => 12, 'reopened' => 6]);
            $story = new Story('ticket-'.$i);
            $this->s->timeline->at($when, $story, 'open', fn (Story $st) => $this->open($st, $topic, $fate));
        }
    }

    /**
     * @param  array{0: string, 1: string, 2: string, 3: string, 4: string}  $topic
     */
    private function open(Story $st, array $topic, string $fate): void
    {
        [$type, $subject, $body, $reply, $resolution] = $topic;
        // Deterministic (never the database's RAND()): the ticket number spreads tickets over the completed orders.
        $ids = Order::query()->whereIn('status', ['execution_finished', 'delivered'])->orderBy('id')->pluck('id')->all();
        if ($ids === []) {
            throw new \RuntimeException('no completed order yet');
        }
        $order = Order::query()->findOrFail((int) $ids[crc32($st->name) % count($ids)]);
        $customer = Customer::query()->findOrFail($order->customer_id);
        $actor = new CustomerActor($customer->id, $customer->mobile);
        $ticket = app(CustomerTickets::class)->open($actor, ['type' => $type, 'subject' => $subject, 'body' => $body, 'order_id' => $order->id, 'client_key' => (string) Str::uuid()]);
        $st->data += ['ticket' => $ticket->id, 'reply' => $reply, 'resolution' => $resolution, 'customer' => $actor, 'fate' => $fate];
        $now = CarbonImmutable::now();
        $at = fn (float $h) => $now->addMinutes((int) ($h * 60));
        if ($fate === 'new') {
            return;
        }
        $this->s->timeline->at($at(3), $st, 'take', function (Story $st) {
            $ticket = Ticket::query()->findOrFail($st->int('ticket'));
            $agent = $this->agent($ticket);
            app(T01TakeTicket::class)->handle($agent, $ticket);
            app(ReplyToTicket::class)->handle($agent, $ticket->refresh(), TicketMessage::PUBLIC, $st->data['reply']);
            app(ReplyToTicket::class)->handle($agent, $ticket->refresh(), TicketMessage::INTERNAL, 'ملاحظة داخلية: تم التنسيق مع فريق التركيب وتسجيل الزيارة في جدول الأسبوع.');
        });
        if ($fate === 'progress') {
            return;
        }
        if ($fate === 'awaiting') {
            $this->s->timeline->at($at(20), $st, 'ask', function (Story $st) {
                $ticket = Ticket::query()->findOrFail($st->int('ticket'));
                app(T02RequestInformation::class)->handle($this->agent($ticket), $ticket, 'هل يمكنكم إرسال صورة واضحة للمشكلة ورقم الطلب؟');
            });

            return;
        }
        $this->s->timeline->at($at(30), $st, 'customer-reply', function (Story $st) {
            $ticket = Ticket::query()->findOrFail($st->int('ticket'));
            app(CustomerTickets::class)->reply($st->data['customer'], $ticket, 'شكرًا لكم، المعلومات كما في الطلب، ننتظر تواصل الفني.');
        });
        $this->s->timeline->at($at(52), $st, 'resolve', function (Story $st) {
            $ticket = Ticket::query()->findOrFail($st->int('ticket'));
            app(T04ResolveTicket::class)->handle($this->agent($ticket), $ticket, $st->data['resolution']);
        });
        if ($fate === 'resolved') {
            return;
        }
        $this->s->timeline->at($at(52 + 72), $st, 'close', function (Story $st) {
            $ticket = Ticket::query()->findOrFail($st->int('ticket'));
            app(T05CloseTicket::class)->handle($this->agent($ticket), $ticket);
        });
        if ($fate === 'reopened') {
            $this->s->timeline->at($at(52 + 72 + 48), $st, 'reopen', function (Story $st) {
                $ticket = Ticket::query()->findOrFail($st->int('ticket'));
                app(T06ReopenTicket::class)->handle($this->agent($ticket), $ticket, 'ملاحظة إضافية من العميل بعد الإغلاق.');
                app(ReplyToTicket::class)->handle($this->agent($ticket), $ticket->refresh(), TicketMessage::PUBLIC, 'أُعيد فتح البلاغ بناءً على ملاحظتكم الإضافية، وسنوافيكم خلال يوم عمل.');
            });
        }
    }

    private function agent(Ticket $ticket): User
    {
        if ($ticket->assignee_id !== null) {
            return User::query()->findOrFail($ticket->assignee_id);
        }

        return $this->s->users[['RUH' => 'sales_ruh1', 'JED' => 'sales_jed', 'DMM' => 'sales_dmm'][$this->codeOf($ticket->branch_id)] ?? 'sales_ruh1'];
    }

    private function codeOf(?int $branchId): string
    {
        foreach ($this->s->branches as $code => $branch) {
            if ($branch->id === $branchId) {
                return $code;
            }
        }

        return 'RUH';
    }
}
