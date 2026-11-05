<?php

namespace App\Workflow;

use App\Models\RoleTemplate;
use InvalidArgumentException;

/**
 * Starting templates (WF-01): alternatives that ease setup only. Each is valid by construction — it relies on the
 * order's responsible person, the stage's responsible or a role the facility has, never on departments or members a
 * facility may not have (M-23-T01). The retail flow with an external workshop is the default (ADR-0038); in-house
 * manufacturing is a template of its own and an add-on stage pack.
 */
final class FlowTemplates
{
    /** The retail default (ADR-0038): a showroom that has its orders made by an external workshop. */
    public const RETAIL = 'retail_outsourced';

    public const RETAIL_KITCHENS = 'retail_kitchens';

    public const RETAIL_ALUMINIUM = 'retail_aluminium';

    public const RETAIL_FURNITURE = 'retail_furniture';

    /** The previous default: everything made in the facility's own workshop. */
    public const IN_HOUSE = 'in_house';

    public const COMPACT = 'compact';

    /** What a new facility starts with. */
    public const DEFAULT = self::RETAIL;

    /** Stage pack the owner may insert into a draft for its own workshop (ADR-0038). */
    public const ADDON_IN_HOUSE = 'addon_in_house_manufacturing';

    /** Role template whose members follow the workshop stages when the facility has it (else the order's owner). */
    public const WORKSHOP_ROLE = 'inventory';

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return [self::RETAIL, self::RETAIL_KITCHENS, self::RETAIL_ALUMINIUM, self::RETAIL_FURNITURE, self::IN_HOUSE, self::COMPACT];
    }

    /**
     * The template a new facility is provisioned with: the configured one when it is known, else the default.
     */
    public static function provisioned(): string
    {
        $key = config('opsone.default_flow_template');

        return is_string($key) && in_array($key, self::keys(), true) ? $key : self::DEFAULT;
    }

    /**
     * @return list<string>
     */
    public static function addonKeys(): array
    {
        return [self::ADDON_IN_HOUSE];
    }

    /**
     * @return array{ar: string, en: string}
     */
    public static function label(string $key): array
    {
        return match ($key) {
            self::RETAIL => ['ar' => 'معرض يُسند التصنيع لورشة خارجية (افتراضي)', 'en' => 'Showroom with an external workshop (default)'],
            self::RETAIL_KITCHENS => ['ar' => 'معرض مطابخ (ورشة خشب + مورّد أسطح)', 'en' => 'Kitchen showroom (joinery + countertop supplier)'],
            self::RETAIL_ALUMINIUM => ['ar' => 'معرض ألمنيوم (قياس نهائي قبل القص)', 'en' => 'Aluminium showroom (final measurement before cutting)'],
            self::RETAIL_FURNITURE => ['ar' => 'معرض أثاث (طلب من المورّد أو استيراد)', 'en' => 'Furniture showroom (supplier order or import)'],
            self::IN_HOUSE => ['ar' => 'تصنيع داخلي (خمس مراحل)', 'en' => 'In-house manufacturing (five stages)'],
            self::COMPACT => ['ar' => 'مسار مختصر (ثلاث مراحل)', 'en' => 'Compact flow (three stages)'],
            self::ADDON_IN_HOUSE => ['ar' => 'إضافة: مراحل التصنيع الداخلي', 'en' => 'Add-on: in-house manufacturing stages'],
            default => throw new InvalidArgumentException("Unknown flow template {$key}."),
        };
    }

    /**
     * @return array{stages: list<array<string, mixed>>}
     */
    public static function definition(string $key): array
    {
        return match ($key) {
            self::RETAIL => ['stages' => self::retail()],
            self::RETAIL_KITCHENS => ['stages' => self::retail(
                workshopDays: 18,
                workshopName: ['ar' => 'الإرسال لورشة الخشب', 'en' => 'Send to the joinery'],
                countertop: true,
            )],
            self::RETAIL_ALUMINIUM => ['stages' => self::retail(
                workshopDays: 10,
                workshopName: ['ar' => 'الإرسال للقص والتصنيع', 'en' => 'Send for cutting and fabrication'],
                measureAlways: true,
            )],
            self::RETAIL_FURNITURE => ['stages' => self::retail(
                workshopDays: 30,
                workshopName: ['ar' => 'الطلب من المورّد أو الاستيراد', 'en' => 'Order from the supplier or import'],
                receiveName: ['ar' => 'الاستلام في المستودع وفحص الجودة', 'en' => 'Warehouse receipt and quality check'],
                installName: ['ar' => 'التوصيل والتركيب', 'en' => 'Delivery and installation'],
            )],
            self::IN_HOUSE => ['stages' => [
                self::stage('measure', 'القياس والمعاينة', 'Measurement and survey', 'المعاينة والقياس', 'Survey and measurement', 3, [
                    self::action('needs', 'capture_needs'),
                    self::action('measure', 'record_measurements', when: 'needs_measurement', name: ['ar' => 'زيارة القياس', 'en' => 'Measurement visit']),
                    self::action('site_photos', 'required_document', requirement: 'optional', inputs: ['document_type' => 'site_photos', 'review_required' => false], name: ['ar' => 'صور الموقع', 'en' => 'Site photos']),
                ]),
                self::stage('design', 'التصميم والعرض', 'Design and quote', 'التصميم والسعر', 'Design and price', 5, [
                    self::action('design', 'prepare_design', when: 'needs_design'),
                    self::action('design_approval', 'customer_approval', when: 'needs_design', inputs: ['subject' => 'design'], name: ['ar' => 'موافقة العميل على التصميم', 'en' => 'Customer approves the design']),
                    self::action('quote', 'prepare_document', inputs: ['document' => 'quote'], name: ['ar' => 'إعداد عرض السعر', 'en' => 'Prepare the quote']),
                ]),
                self::stage('agreement', 'الاتفاق والدفعة الأولى', 'Agreement and first payment', 'الاتفاق والدفعة', 'Agreement and payment', 3, [
                    self::action('quote_approval', 'customer_approval', inputs: ['subject' => 'quote'], name: ['ar' => 'موافقة العميل على العرض', 'en' => 'Customer approves the quote']),
                    self::action('contract', 'prepare_document', requirement: 'optional', inputs: ['document' => 'contract'], name: ['ar' => 'إعداد العقد', 'en' => 'Prepare the contract']),
                    self::action('contract_approval', 'customer_approval', requirement: 'optional', inputs: ['subject' => 'contract'], name: ['ar' => 'موافقة العميل على العقد', 'en' => 'Customer approves the contract']),
                    self::action('first_payment', 'payment_check', inputs: ['reference' => 'first', 'basis' => 'due', 'value' => null], name: ['ar' => 'الدفعة الأولى', 'en' => 'First payment']),
                ]),
                self::stage('manufacture', 'التصنيع', 'Manufacturing', 'التصنيع', 'Manufacturing', 21, [
                    self::action('materials', 'supply_follow_up', requirement: 'optional', inputs: ['reference' => 'materials'], name: ['ar' => 'خامات الطلب', 'en' => 'Order materials']),
                    self::action('manufacturing', 'work_task', inputs: ['label' => ['ar' => 'التصنيع', 'en' => 'Manufacturing'], 'requires_attachment' => false], name: ['ar' => 'التصنيع', 'en' => 'Manufacturing']),
                    self::action('quality_check', 'work_task', inputs: ['label' => ['ar' => 'فحص الجودة', 'en' => 'Quality check'], 'requires_attachment' => false], after: 'manufacturing', name: ['ar' => 'فحص الجودة قبل التسليم', 'en' => 'Quality check before delivery']),
                ]),
                self::stage('install', 'التركيب والتسليم', 'Installation and delivery', 'التركيب والتسليم', 'Installation and delivery', 3, [
                    self::action('tax_invoice', 'required_document', requirement: 'optional', inputs: ['document_type' => 'tax_invoice', 'review_required' => false], name: ['ar' => 'الفاتورة الضريبية', 'en' => 'Tax invoice']),
                    self::action('installation', 'work_task', inputs: ['label' => ['ar' => 'التركيب', 'en' => 'Installation'], 'requires_attachment' => false], name: ['ar' => 'التركيب', 'en' => 'Installation']),
                    self::action('final_payment', 'payment_check', inputs: ['reference' => 'final', 'basis' => 'due', 'value' => null], name: ['ar' => 'الدفعة الأخيرة', 'en' => 'Final payment']),
                    self::action('delivery', 'record_delivery', name: ['ar' => 'تسليم البنود', 'en' => 'Deliver the items']),
                ]),
            ]],
            self::COMPACT => ['stages' => [
                self::stage('agreement', 'الاتفاق', 'Agreement', 'الاتفاق', 'Agreement', 3, [
                    self::action('needs', 'capture_needs'),
                    self::action('quote', 'prepare_document', inputs: ['document' => 'quote'], name: ['ar' => 'إعداد عرض السعر', 'en' => 'Prepare the quote']),
                    self::action('quote_approval', 'customer_approval', inputs: ['subject' => 'quote'], name: ['ar' => 'موافقة العميل على العرض', 'en' => 'Customer approves the quote']),
                    self::action('first_payment', 'payment_check', inputs: ['reference' => 'first', 'basis' => 'due', 'value' => null], name: ['ar' => 'الدفعة الأولى', 'en' => 'First payment']),
                ]),
                self::stage('execution', 'التنفيذ', 'Execution', 'التنفيذ', 'Execution', 14, [
                    self::action('execution', 'work_task', inputs: ['label' => ['ar' => 'التنفيذ', 'en' => 'Execution'], 'requires_attachment' => false], name: ['ar' => 'التنفيذ', 'en' => 'Execution']),
                ]),
                self::stage('delivery', 'التسليم', 'Delivery', 'التسليم', 'Delivery', 3, [
                    self::action('final_payment', 'payment_check', inputs: ['reference' => 'final', 'basis' => 'due', 'value' => null], name: ['ar' => 'الدفعة الأخيرة', 'en' => 'Final payment']),
                    self::action('delivery', 'record_delivery', name: ['ar' => 'تسليم البنود', 'en' => 'Deliver the items']),
                ]),
            ]],
            self::ADDON_IN_HOUSE => ['stages' => self::inHouseAddon()],
            default => throw new InvalidArgumentException("Unknown flow template {$key}."),
        };
    }

    /**
     * The retail flow (analysis §4.6 a): needs → design → agreement → sent to the workshop → received and checked →
     * installation. The workshop stages are followed by the procurement role when the facility has it; every action
     * goes to its stage's responsible person. `workshop_order` is verified only from the purchase order (sent on
     * confirmation, received once fully accepted), and the receipt stage's due date becomes the workshop's date.
     * Payments check the order's payment-plan dues (basis `due`, ADR-0037): the plan, not the flow, holds the shares.
     *
     * @param  array{ar: string, en: string}|null  $workshopName
     * @param  array{ar: string, en: string}|null  $receiveName
     * @param  array{ar: string, en: string}|null  $installName
     * @return list<array<string, mixed>>
     */
    private static function retail(
        int $workshopDays = 14,
        ?array $workshopName = null,
        bool $countertop = false,
        bool $measureAlways = false,
        ?array $receiveName = null,
        ?array $installName = null,
    ): array {
        $workshop = self::workshopFollowUp();
        $workshopName ??= ['ar' => 'الإرسال للورشة', 'en' => 'Send to the workshop'];
        $receiveName ??= ['ar' => 'الاستلام من الورشة وفحص الجودة', 'en' => 'Workshop receipt and quality check'];
        $installName ??= ['ar' => 'التركيب والتسليم', 'en' => 'Installation and delivery'];

        $send = [self::action('workshop_sent', 'workshop_order', inputs: ['reference' => 'workshop', 'milestone' => 'sent'], name: ['ar' => 'إرسال الطلب للورشة', 'en' => 'Send the order to the workshop'], assign: 'stage')];
        $receive = [self::action('workshop_received', 'workshop_order', inputs: ['reference' => 'workshop', 'milestone' => 'received'], name: ['ar' => 'استلام الطلب من الورشة', 'en' => 'Receive the order from the workshop'], assign: 'stage')];
        if ($countertop) {
            $send[] = self::action('countertop_sent', 'workshop_order', requirement: 'optional', inputs: ['reference' => 'countertop', 'milestone' => 'sent'], name: ['ar' => 'إرسال الأسطح لمورّد الرخام', 'en' => 'Send the countertop to the stone supplier'], assign: 'stage');
            $receive[] = self::action('countertop_received', 'workshop_order', requirement: 'optional', inputs: ['reference' => 'countertop', 'milestone' => 'received'], name: ['ar' => 'استلام الأسطح', 'en' => 'Receive the countertop'], assign: 'stage');
        }
        $receive[] = self::action('quality_check', 'work_task', inputs: ['label' => ['ar' => 'فحص الجودة', 'en' => 'Quality check'], 'requires_attachment' => false], after: 'workshop_received', name: ['ar' => 'فحص الجودة عند الاستلام', 'en' => 'Quality check on receipt'], assign: 'stage');

        return [
            self::stage('measure', 'الاحتياج والقياس', 'Needs and measurement', 'المعاينة والقياس', 'Survey and measurement', 3, [
                self::action('needs', 'capture_needs', assign: 'stage'),
                self::action('measure', 'record_measurements', when: $measureAlways ? 'always' : 'needs_measurement', name: ['ar' => 'زيارة القياس', 'en' => 'Measurement visit'], assign: 'stage'),
                self::action('site_photos', 'required_document', requirement: 'optional', inputs: ['document_type' => 'site_photos', 'review_required' => false], name: ['ar' => 'صور الموقع', 'en' => 'Site photos'], assign: 'stage'),
            ]),
            self::stage('design', 'التصميم والعرض', 'Design and quote', 'التصميم والسعر', 'Design and price', 5, [
                self::action('design', 'prepare_design', when: 'needs_design', assign: 'stage'),
                self::action('design_approval', 'customer_approval', when: 'needs_design', inputs: ['subject' => 'design'], name: ['ar' => 'موافقة العميل على التصميم', 'en' => 'Customer approves the design'], assign: 'stage'),
                self::action('quote', 'prepare_document', inputs: ['document' => 'quote'], name: ['ar' => 'إعداد عرض السعر', 'en' => 'Prepare the quote'], assign: 'stage'),
            ]),
            self::stage('agreement', 'الاتفاق والدفعة الأولى', 'Agreement and first payment', 'الاتفاق والدفعة', 'Agreement and payment', 3, [
                self::action('quote_approval', 'customer_approval', inputs: ['subject' => 'quote'], name: ['ar' => 'موافقة العميل على العرض', 'en' => 'Customer approves the quote'], assign: 'stage'),
                self::action('contract', 'prepare_document', requirement: 'optional', inputs: ['document' => 'contract'], name: ['ar' => 'إعداد العقد', 'en' => 'Prepare the contract'], assign: 'stage'),
                self::action('contract_approval', 'customer_approval', requirement: 'optional', inputs: ['subject' => 'contract'], name: ['ar' => 'موافقة العميل على العقد', 'en' => 'Customer approves the contract'], assign: 'stage'),
                self::action('first_payment', 'payment_check', inputs: ['reference' => 'first', 'basis' => 'due', 'value' => null], name: ['ar' => 'الدفعة الأولى', 'en' => 'First payment'], assign: 'stage'),
            ]),
            self::stage('workshop', $workshopName['ar'], $workshopName['en'], 'قيد التصنيع', 'In production', 2, $send, $workshop),
            self::stage('receiving', $receiveName['ar'], $receiveName['en'], 'قيد التصنيع', 'In production', $workshopDays, $receive, $workshop),
            self::stage('install', $installName['ar'], $installName['en'], $installName['ar'], $installName['en'], 3, [
                self::action('tax_invoice', 'required_document', requirement: 'optional', inputs: ['document_type' => 'tax_invoice', 'review_required' => false], name: ['ar' => 'الفاتورة الضريبية', 'en' => 'Tax invoice'], assign: 'stage'),
                self::action('installation', 'work_task', inputs: ['label' => ['ar' => 'التركيب', 'en' => 'Installation'], 'requires_attachment' => false], name: ['ar' => 'التركيب', 'en' => 'Installation'], assign: 'stage'),
                self::action('final_payment', 'payment_check', inputs: ['reference' => 'final', 'basis' => 'due', 'value' => null], name: ['ar' => 'الدفعة الأخيرة', 'en' => 'Final payment'], assign: 'stage'),
                self::action('delivery', 'record_delivery', name: ['ar' => 'تسليم البنود', 'en' => 'Deliver the items'], assign: 'stage'),
            ]),
        ];
    }

    /**
     * The in-house manufacturing add-on (analysis §4.6 c): materials, fabrication, assembly and quality check, for a
     * facility with its own workshop. The owner inserts it into a draft (usually after the agreement) and renames,
     * reassigns or removes stages in the editor; it is checked and published like any change.
     *
     * @return list<array<string, mixed>>
     */
    private static function inHouseAddon(): array
    {
        return [
            self::stage('materials_prep', 'تجهيز المواد', 'Materials preparation', 'قيد التصنيع', 'In production', 3, [
                self::action('inhouse_materials', 'supply_follow_up', requirement: 'optional', inputs: ['reference' => 'inhouse_materials'], name: ['ar' => 'خامات التصنيع', 'en' => 'Manufacturing materials'], assign: 'stage'),
                self::action('materials_ready', 'work_task', inputs: ['label' => ['ar' => 'تجهيز المواد وحجزها', 'en' => 'Prepare and reserve materials'], 'requires_attachment' => false], name: ['ar' => 'تجهيز المواد', 'en' => 'Prepare the materials'], assign: 'stage'),
            ]),
            self::stage('fabrication', 'القص والتصنيع', 'Cutting and fabrication', 'قيد التصنيع', 'In production', 10, [
                self::action('fabrication_work', 'work_task', inputs: ['label' => ['ar' => 'القص والتصنيع', 'en' => 'Cutting and fabrication'], 'requires_attachment' => false], name: ['ar' => 'القص والتصنيع', 'en' => 'Cutting and fabrication'], assign: 'stage'),
            ]),
            self::stage('assembly', 'التجميع والتشطيب', 'Assembly and finishing', 'قيد التصنيع', 'In production', 5, [
                self::action('assembly_work', 'work_task', inputs: ['label' => ['ar' => 'التجميع والدهان', 'en' => 'Assembly and painting'], 'requires_attachment' => false], name: ['ar' => 'التجميع والتشطيب', 'en' => 'Assembly and finishing'], assign: 'stage'),
            ]),
            self::stage('inhouse_qc', 'فحص جودة الورشة', 'Workshop quality check', 'فحص الجودة', 'Quality check', 2, [
                self::action('inhouse_quality', 'work_task', inputs: ['label' => ['ar' => 'فحص الجودة قبل التسليم', 'en' => 'Quality check before delivery'], 'requires_attachment' => true], name: ['ar' => 'فحص الجودة', 'en' => 'Quality check'], assign: 'stage'),
            ]),
        ];
    }

    /**
     * The workshop stages' follower: the procurement role when the facility has it, else the order's owner.
     *
     * @return array<string, mixed>
     */
    private static function workshopFollowUp(): array
    {
        $role = RoleTemplate::query()->where('key', self::WORKSHOP_ROLE)->value('id');

        return $role === null ? ['type' => 'order_assignee'] : ['type' => 'role', 'id' => (int) $role];
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     * @param  array<string, mixed>|null  $followUp
     * @return array<string, mixed>
     */
    private static function stage(string $key, string $ar, string $en, string $publicAr, string $publicEn, int $days, array $actions, ?array $followUp = null): array
    {
        return [
            'key' => $key,
            'name' => ['ar' => $ar, 'en' => $en],
            'public_name' => ['ar' => $publicAr, 'en' => $publicEn],
            'show_to_customer' => true,
            'follow_up' => $followUp ?? ['type' => 'order_assignee'],
            'target_days' => $days,
            'actions' => $actions,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $inputs
     * @param  array{ar: string, en: string}|null  $name
     * @return array<string, mixed>
     */
    private static function action(
        string $key,
        string $type,
        string $requirement = 'required',
        string $when = 'always',
        ?array $inputs = null,
        ?string $after = null,
        ?array $name = null,
        string $assign = 'order_assignee',
    ): array {
        $actionType = Catalogue\ActionType::from($type);

        return [
            'key' => $key,
            'type' => $type,
            'name' => $name ?? $actionType->label(),
            'requirement' => $requirement,
            'applies_when' => $when,
            'assign' => ['type' => $assign],
            'inputs' => $inputs ?? $actionType->defaultInputs(),
            'after' => $after,
        ];
    }
}
