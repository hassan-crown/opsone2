<?php

namespace App\Workflow;

use InvalidArgumentException;

/**
 * Starting templates (WF-01): alternatives that ease setup only. Each is valid by construction — it relies on the
 * order's responsible person, never on departments or members a facility may not have (M-23-T01).
 */
final class FlowTemplates
{
    public const DEFAULT = 'default';

    public const COMPACT = 'compact';

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return [self::DEFAULT, self::COMPACT];
    }

    /**
     * @return array{ar: string, en: string}
     */
    public static function label(string $key): array
    {
        return match ($key) {
            self::DEFAULT => ['ar' => 'المسار الافتراضي (خمس مراحل)', 'en' => 'Default flow (five stages)'],
            self::COMPACT => ['ar' => 'مسار مختصر (ثلاث مراحل)', 'en' => 'Compact flow (three stages)'],
            default => throw new InvalidArgumentException("Unknown flow template {$key}."),
        };
    }

    /**
     * @return array{stages: list<array<string, mixed>>}
     */
    public static function definition(string $key): array
    {
        return match ($key) {
            self::DEFAULT => ['stages' => [
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
                    self::action('first_payment', 'payment_check', inputs: ['reference' => 'first', 'basis' => 'percent', 'value' => 50], name: ['ar' => 'الدفعة الأولى', 'en' => 'First payment']),
                ]),
                self::stage('manufacture', 'التصنيع', 'Manufacturing', 'التصنيع', 'Manufacturing', 21, [
                    self::action('materials', 'supply_follow_up', requirement: 'optional', inputs: ['reference' => 'materials'], name: ['ar' => 'خامات الطلب', 'en' => 'Order materials']),
                    self::action('manufacturing', 'work_task', inputs: ['label' => ['ar' => 'التصنيع', 'en' => 'Manufacturing'], 'requires_attachment' => false], name: ['ar' => 'التصنيع', 'en' => 'Manufacturing']),
                    self::action('quality_check', 'work_task', inputs: ['label' => ['ar' => 'فحص الجودة', 'en' => 'Quality check'], 'requires_attachment' => false], after: 'manufacturing', name: ['ar' => 'فحص الجودة قبل التسليم', 'en' => 'Quality check before delivery']),
                ]),
                self::stage('install', 'التركيب والتسليم', 'Installation and delivery', 'التركيب والتسليم', 'Installation and delivery', 3, [
                    self::action('tax_invoice', 'required_document', requirement: 'optional', inputs: ['document_type' => 'tax_invoice', 'review_required' => false], name: ['ar' => 'الفاتورة الضريبية', 'en' => 'Tax invoice']),
                    self::action('installation', 'work_task', inputs: ['label' => ['ar' => 'التركيب', 'en' => 'Installation'], 'requires_attachment' => false], name: ['ar' => 'التركيب', 'en' => 'Installation']),
                    self::action('final_payment', 'payment_check', inputs: ['reference' => 'final', 'basis' => 'remaining', 'value' => null], name: ['ar' => 'الدفعة الأخيرة', 'en' => 'Final payment']),
                    self::action('delivery', 'record_delivery', name: ['ar' => 'تسليم البنود', 'en' => 'Deliver the items']),
                ]),
            ]],
            self::COMPACT => ['stages' => [
                self::stage('agreement', 'الاتفاق', 'Agreement', 'الاتفاق', 'Agreement', 3, [
                    self::action('needs', 'capture_needs'),
                    self::action('quote', 'prepare_document', inputs: ['document' => 'quote'], name: ['ar' => 'إعداد عرض السعر', 'en' => 'Prepare the quote']),
                    self::action('quote_approval', 'customer_approval', inputs: ['subject' => 'quote'], name: ['ar' => 'موافقة العميل على العرض', 'en' => 'Customer approves the quote']),
                    self::action('first_payment', 'payment_check', inputs: ['reference' => 'first', 'basis' => 'percent', 'value' => 50], name: ['ar' => 'الدفعة الأولى', 'en' => 'First payment']),
                ]),
                self::stage('execution', 'التنفيذ', 'Execution', 'التنفيذ', 'Execution', 14, [
                    self::action('execution', 'work_task', inputs: ['label' => ['ar' => 'التنفيذ', 'en' => 'Execution'], 'requires_attachment' => false], name: ['ar' => 'التنفيذ', 'en' => 'Execution']),
                ]),
                self::stage('delivery', 'التسليم', 'Delivery', 'التسليم', 'Delivery', 3, [
                    self::action('final_payment', 'payment_check', inputs: ['reference' => 'final', 'basis' => 'remaining', 'value' => null], name: ['ar' => 'الدفعة الأخيرة', 'en' => 'Final payment']),
                    self::action('delivery', 'record_delivery', name: ['ar' => 'تسليم البنود', 'en' => 'Deliver the items']),
                ]),
            ]],
            default => throw new InvalidArgumentException("Unknown flow template {$key}."),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     * @return array<string, mixed>
     */
    private static function stage(string $key, string $ar, string $en, string $publicAr, string $publicEn, int $days, array $actions): array
    {
        return [
            'key' => $key,
            'name' => ['ar' => $ar, 'en' => $en],
            'public_name' => ['ar' => $publicAr, 'en' => $publicEn],
            'show_to_customer' => true,
            'follow_up' => ['type' => 'order_assignee'],
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
    ): array {
        $actionType = Catalogue\ActionType::from($type);

        return [
            'key' => $key,
            'type' => $type,
            'name' => $name ?? $actionType->label(),
            'requirement' => $requirement,
            'applies_when' => $when,
            'assign' => ['type' => 'order_assignee'],
            'inputs' => $inputs ?? $actionType->defaultInputs(),
            'after' => $after,
        ];
    }
}
