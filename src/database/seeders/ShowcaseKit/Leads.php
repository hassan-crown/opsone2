<?php

namespace Database\Seeders\ShowcaseKit;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\ManageContacts;
use App\Actions\Site\SubmitLead;
use App\Actions\StateMachines\M03Interest\T00CreateInterest;
use App\Actions\StateMachines\M03Interest\T01TakeFollowUp;
use App\Actions\StateMachines\M03Interest\T02ConvertInterest;
use App\Actions\StateMachines\M03Interest\T03CloseInterest;
use App\Enums\InterestCloseReason;
use App\Models\Customer;
use App\Models\Interest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Interests (site form, showroom, phone/WhatsApp, referrals), their follow-up and outcome — converted into a
 * made-to-order kitchen, lost with a reason, or still open — plus the companies and repeat customers that order
 * directly. Converting an interest creates the customer and the order exactly as the screen does (M-03-T02).
 */
final class Leads
{
    /** @var array<string, list<int>> branch => customer ids available to repeat orders */
    public array $pool = ['RUH' => [], 'JED' => [], 'DMM' => []];

    /** @var list<array{id: int, branch: string}> companies */
    public array $companies = [];

    public function __construct(private readonly Showcase $s) {}

    public function run(): void
    {
        $this->interests();
        $this->directCustomOrders();
    }

    private function workday(CarbonImmutable $d): CarbonImmutable
    {
        while (in_array($d->dayOfWeek, [5, 6], true)) {
            $d = $d->addDay();
        }

        return $d;
    }

    private function salesFor(string $branch): User
    {
        $keys = ['RUH' => ['sales_ruh1', 'sales_ruh2'], 'JED' => ['sales_jed'], 'DMM' => ['sales_dmm']][$branch];

        return $this->s->users[$this->s->rng->pick($keys)];
    }

    private function interests(): void
    {
        $rng = $this->s->rng;
        $total = $this->s->n(300);
        $span = (int) $this->s->start->diffInDays($this->s->end);
        $needs = People::needs();

        for ($i = 0; $i < $total; $i++) {
            // Growing business: more recent leads are more frequent.
            $day = (int) floor($span * sqrt($rng->int(1, 10000) / 10000)) - 1;
            $when = $this->workday($this->s->start->addDays(max(0, min($span - 1, $day)))->setTime($rng->int(9, 19), $rng->int(0, 59)));
            $channel = $rng->weighted(['website' => 38, 'walk_in' => 28, 'whatsapp' => 14, 'phone' => 8, 'other' => 12]);
            $branch = $channel === 'website' ? 'RUH' : $rng->weighted(['RUH' => 46, 'JED' => 30, 'DMM' => 24]);
            [$name] = $this->s->people->person();
            $mobile = $this->s->people->mobile();
            $need = $rng->pick($needs);
            if ($channel === 'other') {
                $need = 'إحالة من عميل سابق: '.$need;
            }
            $age = (int) $when->diffInDays($this->s->end);
            // Outcome mix: the older the lead the more settled it is.
            $outcome = $age < 4 ? $rng->weighted(['new' => 70, 'following' => 30])
                : ($age < 12 ? $rng->weighted(['new' => 15, 'following' => 35, 'convert' => 35, 'lost' => 15])
                    : $rng->weighted(['convert' => 40, 'lost' => 33, 'following' => 17, 'new' => 10]));
            $story = new Story('interest-'.$i);
            $plan = ['channel' => $channel, 'branch' => $branch, 'name' => $name, 'mobile' => $mobile, 'need' => $need, 'outcome' => $outcome,
                'follow' => $rng->int(0, 1), 'resolve' => $rng->int(2, 12), 'reason' => $rng->weighted(['price' => 35, 'competitor' => 20, 'no_reply' => 25, 'later' => 15, 'other' => 5]),
                'email' => $rng->chance(55) ? 'lead'.$i.'@mail-demo.test' : null];

            $this->s->timeline->at($when, $story, 'create', fn (Story $st) => $this->create($st, $plan));
        }
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function create(Story $st, array $p): void
    {
        $sales = $this->salesFor($p['branch']);
        if ($p['channel'] === 'website') {
            $interest = app(SubmitLead::class)->handle((string) Str::uuid(), [
                'name' => $p['name'], 'mobile' => $p['mobile'], 'email' => $p['email'], 'need' => $p['need'],
            ]);
        } else {
            $interest = app(T00CreateInterest::class)->handle($sales, [
                'name' => $p['name'], 'mobile' => $p['mobile'], 'email' => $p['email'], 'need' => $p['need'],
                'channel' => $p['channel'], 'branch_id' => $this->s->branches[$p['branch']]->id,
            ]);
        }
        $st->data['interest'] = $interest->id;
        $now = CarbonImmutable::now();
        $at = fn (float $days) => $this->workday($now->addMinutes((int) ($days * 1440))->setTime($this->s->rng->int(9, 18), $this->s->rng->int(0, 59)));

        if ($p['outcome'] === 'new') {
            return;
        }
        $this->s->timeline->at($at($p['follow'] + 0.2), $st, 'follow', function (Story $s) use ($sales) {
            $i = Interest::query()->findOrFail($s->data['interest']);
            if ($i->status->value === 'new') {
                app(T01TakeFollowUp::class)->handle($this->salesFor($this->branchCode($i->branch_id)), $i);
            }
        });
        if ($p['outcome'] === 'following') {
            return;
        }
        $this->s->timeline->at($at($p['follow'] + $p['resolve']), $st, $p['outcome'], function (Story $s) use ($p) {
            $i = Interest::query()->findOrFail($s->data['interest']);
            $actor = User::query()->find($i->assignee_id) ?? $this->salesFor($this->branchCode($i->branch_id));
            if ($p['outcome'] === 'lost') {
                app(T03CloseInterest::class)->handle($actor, $i, InterestCloseReason::from($p['reason']), match ($p['reason']) {
                    'price' => 'العرض الشفهي أعلى من ميزانية العميل.', 'competitor' => 'اختار مصنعًا آخر بسعر أقل.',
                    'no_reply' => 'لم يرد بعد ثلاث محاولات اتصال.', 'later' => 'المشروع مؤجل لحين استلام الفيلا.', default => null,
                });

                return;
            }
            $converted = app(T02ConvertInterest::class)->handle($actor, $i);
            $order = \App\Models\Order::query()->findOrFail($converted->converted_order_id);
            $this->pool[$this->branchCode($i->branch_id)][] = (int) $converted->converted_customer_id;
            $this->s->customOrders->continueOrder($order, $this->branchCode($i->branch_id), []);
        });
    }

    /**
     * A returning customer of the branch when there is one, otherwise a new walk-in.
     */
    public function customerFor(string $branch, int $newPercent = 0): int
    {
        $pool = $this->pool[$branch];
        if ($pool !== [] && ! $this->s->rng->chance($newPercent)) {
            return $this->s->rng->pick($pool);
        }
        $sales = $this->salesFor($branch);
        [$name] = $this->s->people->person();
        $customer = app(CreateCustomer::class)->handle($sales, [
            'kind' => 'person', 'name' => $name, 'mobile' => $this->s->people->mobile(), 'email' => $this->s->rng->chance(60) ? 'walkin'.$this->s->rng->int(1, 99999999).'@mail-demo.test' : null,
            'country' => 'SA', 'branch_id' => $this->s->branches[$branch]->id, 'assignee_id' => $sales->id, 'preferred_channel' => $this->s->rng->pick(['whatsapp', 'phone']),
        ]);
        $this->pool[$branch][] = $customer->id;

        return $customer->id;
    }

    private function branchCode(int $branchId): string
    {
        foreach ($this->s->branches as $code => $branch) {
            if ($branch->id === $branchId) {
                return $code;
            }
        }

        return 'RUH';
    }

    /**
     * Companies (developers, contractors, design offices) and repeat customers ordering a kitchen directly.
     */
    private function directCustomOrders(): void
    {
        $rng = $this->s->rng;
        $companies = $this->s->n(25);
        $repeat = $this->s->n(15);
        $span = (int) $this->s->start->diffInDays($this->s->end);

        for ($i = 0; $i < $companies; $i++) {
            $when = $this->workday($this->s->start->addDays($rng->int(3, max(4, $span - 20)))->setTime($rng->int(9, 15), 0));
            $branch = $rng->weighted(['RUH' => 50, 'JED' => 28, 'DMM' => 22]);
            $story = new Story('company-'.$i);
            $company = $this->s->people->company();
            $seed = $rng->int(1, 1_000_000);
            $this->s->timeline->at($when, $story, 'company', function (Story $st) use ($branch, $company, $i, $seed) {
                $sales = $this->salesFor($branch);
                $customer = app(CreateCustomer::class)->handle($sales, [
                    'kind' => 'organization', 'name' => $company['name'], 'mobile' => $this->s->people->mobile(), 'email' => 'company'.$i.'@mail-demo.test',
                    'country' => 'SA', 'branch_id' => $this->s->branches[$branch]->id, 'assignee_id' => $sales->id, 'preferred_channel' => 'whatsapp',
                    'commercial_registration' => $this->s->people->crNumber(), 'vat_number' => $this->s->people->vatNumber(),
                    'office_phone' => '+96611'.str_pad((string) (4000000 + $seed % 999999), 7, '0', STR_PAD_LEFT),
                    'internal_notes' => match ($company['kind']) {
                        'developer' => 'مطوّر عقاري: مشاريع متكررة، شروط دفع خاصة بعد توقيع العقد الإطاري.', 'design' => 'مكتب تصميم يحيل عملاءه إلينا، عمولة إحالة متفق عليها.',
                        default => 'مقاول: يطلب أسعار كميات لمشاريع الفلل.',
                    },
                ]);
                $contacts = app(ManageContacts::class);
                [$contactName] = $this->s->people->person();
                $contacts->add($sales, $customer, ['name' => $contactName, 'mobile' => $this->s->people->mobile(), 'job_title' => 'مدير المشاريع', 'email' => 'pm'.$customer->id.'@mail-demo.test', 'is_primary' => true]);
                [$second] = $this->s->people->person();
                $contacts->add($sales, $customer, ['name' => $second, 'mobile' => $this->s->people->mobile(), 'job_title' => 'مسؤول المشتريات', 'is_primary' => false]);
                $this->pool[$branch][] = $customer->id;
                $this->companies[] = ['id' => $customer->id, 'branch' => $branch];
                $this->s->customOrders->openDirect(CarbonImmutable::now()->addHours(3), $branch, $customer->id, [
                    'need' => 'مشروع '.$this->s->rng->pick([4, 6, 8, 12]).' وحدات سكنية: مطابخ متطابقة بمواصفات موحدة، مع توريد على دفعات.',
                ]);
            });
        }

        for ($i = 0; $i < $repeat; $i++) {
            $when = $this->workday($this->s->start->addDays($rng->int(40, max(41, $span - 25)))->setTime($rng->int(9, 15), 0));
            $branch = $rng->weighted(['RUH' => 50, 'JED' => 28, 'DMM' => 22]);
            $story = new Story('repeat-'.$i);
            $this->s->timeline->at($when, $story, 'repeat', function (Story $st) use ($branch) {
                $this->s->customOrders->openDirect(CarbonImmutable::now()->addHours(2), $branch, $this->customerFor($branch), [
                    'need' => 'طلب مطبخ جديد لعقار ثانٍ لنفس العميل — إحالة داخلية.',
                ]);
            });
        }
    }
}
