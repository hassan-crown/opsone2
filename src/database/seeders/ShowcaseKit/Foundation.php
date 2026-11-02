<?php

namespace Database\Seeders\ShowcaseKit;

use App\Access\TransitionRecorder;
use App\Actions\Commissions\UpdateCommissionSettings;
use App\Actions\Inventory\ManageStockSetup;
use App\Actions\Organization\CreateBranch;
use App\Actions\Organization\EstablishOwner;
use App\Actions\Organization\UpdateFacilityProfile;
use App\Actions\Settings\UpdateDiscountLimits;
use App\Actions\Settings\UpdateFinanceSettings;
use App\Actions\Settings\UpdateWorkSettings;
use App\Actions\StateMachines\M02Setup\T01SaveSetupStep;
use App\Actions\StateMachines\M02Setup\T02CompleteSetup;
use App\Actions\StateMachines\M22Membership\T00CreateMembership;
use App\Actions\StateMachines\M23Flow\T01ChooseTemplate;
use App\Actions\StateMachines\M23Flow\T02SaveDraft;
use App\Actions\StateMachines\M23Flow\T03PublishFlow;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Flow;
use App\Models\Region;
use App\Models\RoleTemplate;
use App\Models\User;
use App\Workflow\FlowProvisioner;
use App\Workflow\FlowTemplates;
use Carbon\CarbonImmutable;

/**
 * The organisation of OpsOne: facility profile, two regions, three branches, five departments, 17 staff accounts
 * (every role template), discount limits, commission policy, work hours and the published flow with real department
 * routing. Done on 2026-04-20, before the first business day.
 */
final class Foundation
{
    public function __construct(private readonly Showcase $s) {}

    public function run(): void
    {
        Timeline::freeze(CarbonImmutable::parse('2026-04-20 09:00', 'Asia/Riyadh'));

        $this->owner();
        $this->structure();
        $this->staff();
        $this->settings();
        $this->flow();
        $this->finishSetup();
    }

    private function owner(): void
    {
        $owner = User::factory()->create(['name' => 'عبدالله بن سعد القحطاني', 'email' => 'owner@opsone-demo.test']);
        app(EstablishOwner::class)->handle($owner, TransitionRecorder::SOURCE_SEEDER);
        $this->s->users['owner'] = $owner;

        app(UpdateFacilityProfile::class)->handle($owner, [
            'name_ar' => 'أوبس ون', 'name_en' => 'OpsOne', 'contact_email' => 'hello@opsone-demo.test',
            'contact_phone' => '+966112345678', 'address' => 'طريق الملك فهد، حي العليا، الرياض 12211، المملكة العربية السعودية',
            'default_locale' => 'ar', 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh',
        ]);
    }

    private function structure(): void
    {
        $owner = $this->s->users['owner'];
        $central = Region::query()->create(['name_ar' => 'المنطقة الوسطى والشرقية', 'name_en' => 'Central & Eastern region']);
        $west = Region::query()->create(['name_ar' => 'المنطقة الغربية', 'name_en' => 'Western region']);

        $create = app(CreateBranch::class);
        $this->s->branches = [
            'RUH' => $create->handle($owner, ['name_ar' => 'فرع الرياض (المقر الرئيسي)', 'name_en' => 'Riyadh (HQ)', 'code' => 'RUH', 'region_id' => $central->id]),
            'JED' => $create->handle($owner, ['name_ar' => 'فرع جدة', 'name_en' => 'Jeddah', 'code' => 'JED', 'region_id' => $west->id]),
            'DMM' => $create->handle($owner, ['name_ar' => 'فرع الدمام', 'name_en' => 'Dammam', 'code' => 'DMM', 'region_id' => $central->id]),
        ];

        $all = array_map(fn (Branch $b) => $b->id, array_values($this->s->branches));
        foreach ([
            'sales' => ['المبيعات', 'Sales'], 'design' => ['التصميم', 'Design'], 'production' => ['الإنتاج', 'Production'],
            'installation' => ['التركيب', 'Installation'], 'finance' => ['المالية', 'Finance'],
        ] as $key => [$ar, $en]) {
            $department = Department::query()->create(['name_ar' => $ar, 'name_en' => $en]);
            $department->branches()->sync($all);
            $this->s->departments[$key] = $department;
        }
    }

    private function staff(): void
    {
        $b = fn (string ...$codes) => array_values(array_map(fn (string $c) => $this->s->branches[$c]->id, $codes));
        $d = $this->s->departments;

        // key => [name, email local part, role, department, branch codes, job title]
        $roster = [
            'gm' => ['فيصل بن ناصر المطيري', 'faisal.almutairi', 'manager', null, ['RUH', 'JED', 'DMM'], 'المدير العام'],
            'mgr_jed' => ['ماجد بن علي الزهراني', 'majed.alzahrani', 'manager', null, ['JED'], 'مدير فرع جدة'],
            'mgr_dmm' => ['سلطان بن محمد الدوسري', 'sultan.aldosari', 'manager', null, ['DMM'], 'مدير فرع الدمام'],
            'sales_ruh1' => ['نورة بنت عبدالعزيز العتيبي', 'noura.alotaibi', 'sales', $d['sales'], ['RUH'], 'مستشارة مبيعات'],
            'sales_ruh2' => ['خالد بن سعود الشمري', 'khaled.alshammari', 'sales', $d['sales'], ['RUH'], 'مستشار مبيعات'],
            'sales_jed' => ['ريم بنت أحمد الغامدي', 'reem.alghamdi', 'sales', $d['sales'], ['JED'], 'مستشارة مبيعات'],
            'sales_dmm' => ['ياسر بن فهد القحطاني', 'yasser.alqahtani', 'sales', $d['sales'], ['DMM'], 'مستشار مبيعات'],
            'sup_prod' => ['بدر بن عبدالله العنزي', 'badr.alanazi', 'supervisor', $d['production'], ['RUH', 'JED', 'DMM'], 'مشرف الإنتاج'],
            'design1' => ['لمى بنت سالم الحربي', 'lama.alharbi', 'executor', $d['design'], ['RUH', 'JED', 'DMM'], 'مصممة مطابخ'],
            'design2' => ['أريج بنت ناصر الدخيل', 'areej.aldakheel', 'executor', $d['design'], ['RUH', 'JED', 'DMM'], 'مصممة مطابخ'],
            'prod1' => ['عمر بن صالح السبيعي', 'omar.alsubaie', 'executor', $d['production'], ['RUH', 'JED', 'DMM'], 'فني تصنيع'],
            'prod2' => ['إبراهيم بن حسن الغامدي', 'ibrahim.alghamdi', 'executor', $d['production'], ['RUH', 'JED', 'DMM'], 'فني تصنيع'],
            'inst_ruh' => ['تركي بن مشعل البقمي', 'turki.albaqami', 'executor', $d['installation'], ['RUH', 'DMM'], 'فني تركيب'],
            'inst_ruh2' => ['حسن بن علي آل سالم', 'hassan.alsalem', 'executor', $d['installation'], ['RUH'], 'فني تركيب'],
            'inst_jed' => ['زياد بن عمر باعشن', 'ziyad.baeshen', 'executor', $d['installation'], ['JED'], 'فني تركيب'],
            'finance' => ['هند بنت سليمان الرشيد', 'hind.alrasheed', 'finance', $d['finance'], ['RUH', 'JED', 'DMM'], 'المحاسبة الرئيسية'],
            'inventory' => ['وليد بن عبدالرحمن الصالح', 'waleed.alsaleh', 'inventory', null, ['RUH', 'JED', 'DMM'], 'أمين المستودع والمشتريات'],
            'content' => ['دانة بنت فيصل الخالدي', 'dana.alkhalidi', 'content', null, [], 'مسؤولة المحتوى والموقع'],
        ];

        $create = app(T00CreateMembership::class);
        foreach ($roster as $key => [$name, $local, $role, $department, $codes, $title]) {
            $user = User::factory()->create(['name' => $name, 'email' => $local.'@opsone-demo.test']);
            $templateId = (int) RoleTemplate::query()->where('key', $role)->value('id');
            $create->handle($user, $department?->id, $b(...$codes), [['role_template_id' => $templateId]], $title, $this->s->users['owner'], TransitionRecorder::SOURCE_SEEDER);
            $this->s->users[$key] = $user;
            $this->s->roles[$key] = $role;
        }
    }

    private function settings(): void
    {
        $owner = $this->s->users['owner'];

        app(UpdateFinanceSettings::class)->handle($owner, ['vat_rate_bp' => 1500, 'prices_include_vat' => true, 'quote_validity_days' => 14, 'contracts_enabled' => true], null);

        $template = fn (string $key) => (int) RoleTemplate::query()->where('key', $key)->value('id');
        app(UpdateDiscountLimits::class)->handle($owner, ['limits' => [
            ['role_template_id' => $template('sales'), 'custom_bp' => 500, 'ready_bp' => 500, 'approval_bp' => 0],
            ['role_template_id' => $template('supervisor'), 'custom_bp' => 0, 'ready_bp' => 0, 'approval_bp' => 1000],
            ['role_template_id' => $template('manager'), 'custom_bp' => 1000, 'ready_bp' => 1000, 'approval_bp' => 1500],
        ]]);

        app(ManageStockSetup::class)->updateSettings($owner, ['piece', 'sheet', 'pair', 'meter', 'set', 'box', 'square_meter', 'linear_meter', 'roll', 'kilogram', 'liter'], true);

        app(UpdateWorkSettings::class)->handle($owner, [
            'working_days' => [7, 1, 2, 3, 4], 'work_starts_at' => '08:00', 'work_ends_at' => '17:00',
            'appointment_slot_minutes' => 60, 'reminder_from' => '09:00', 'reminder_until' => '20:00', 'conflicting_appointments' => 'allow_with_reason',
        ]);

        app(UpdateCommissionSettings::class)->handle($owner, [
            'enabled' => true, 'default_rate_bp' => 200, 'manual_lines_eligible' => true, 'roles' => [], 'members' => [],
        ], null);
    }

    /**
     * The published flow is the retail default (ADR-0038: kitchens made by external workshops, followed by their
     * purchase orders) with real routing: design work goes to the design department, the quality check on receipt
     * to production, measurements and installation to the installation department; the workshop stages follow the
     * procurement role.
     */
    private function flow(): void
    {
        $owner = $this->s->users['owner'];
        $flow = app(FlowProvisioner::class)->ensure();
        if ($flow->template_key !== FlowTemplates::RETAIL) {
            // A deployment provisioned with another template still shows the retail story.
            $flow = app(T01ChooseTemplate::class)->handle($owner, FlowTemplates::RETAIL, true, $flow->lock_version);
        }
        $definition = $flow->refresh()->publishedVersion->definition;

        $routing = [
            'measure' => 'installation', 'design' => 'design', 'quality_check' => 'production', 'installation' => 'installation',
        ];
        foreach ($definition['stages'] as &$stage) {
            foreach ($stage['actions'] as &$action) {
                if (isset($routing[$action['key']])) {
                    $action['assign'] = ['type' => 'department', 'id' => $this->s->departments[$routing[$action['key']]]->id];
                }
            }
            unset($action);
        }
        unset($stage);

        app(T02SaveDraft::class)->handle($owner, $definition, Flow::current()?->lock_version);
        app(T03PublishFlow::class)->handle($owner, Flow::current()?->lock_version);
    }

    private function finishSetup(): void
    {
        $owner = $this->s->users['owner'];
        $save = app(T01SaveSetupStep::class);
        foreach (['org', 'structure', 'flow', 'team'] as $step) {
            $save->handle($owner, $step, 'done');
        }
        app(T02CompleteSetup::class)->handle($owner);
    }
}
