<?php

namespace App\Workflow\Catalogue;

/**
 * The fixed action catalogue (WF-03, D-03): eleven system actions with their inputs, system dependencies and completion
 * rule. A facility only chooses actions from here and sets required/optional/condition, assignment and reference;
 * it never writes fields, rules or scripts. Renaming a stage or an action never changes what the action means.
 */
enum ActionType: string
{
    case CaptureNeeds = 'capture_needs';
    case RecordMeasurements = 'record_measurements';
    case PrepareDesign = 'prepare_design';
    case PrepareDocument = 'prepare_document';
    case CustomerApproval = 'customer_approval';
    case WorkTask = 'work_task';
    case SupplyFollowUp = 'supply_follow_up';
    case PaymentCheck = 'payment_check';
    case RequiredDocument = 'required_document';
    case RecordDelivery = 'record_delivery';
    // ADR-0038: the order is made by an external workshop; verified from its purchase order (sent, then received).
    case WorkshopOrder = 'workshop_order';

    public const DOCUMENTS = ['quote', 'contract'];

    public const APPROVAL_SUBJECTS = ['design', 'quote', 'contract'];

    /**
     * How a payment check is met (ADR-0037): `due` — the order's schedule is collected through the due with this
     * reference (amounts come from the payment plan, the single source); `amount` — a fixed sum; `remaining` — the
     * whole agreed value. `percent` is no longer offered: it duplicated the plan's share and is only read on actions
     * already pinned to an order.
     */
    public const PAYMENT_BASES = ['due', 'amount', 'remaining'];

    public const MEASUREMENT_KINDS = ['estimated', 'final'];

    /** A workshop order is verified twice from its purchase order: when it is sent (T01) and when fully received. */
    public const WORKSHOP_MILESTONES = ['sent', 'received'];

    public function group(): ActionGroup
    {
        return match ($this) {
            self::CaptureNeeds, self::RecordMeasurements, self::PrepareDesign => ActionGroup::InfoMeasurement,
            self::PrepareDocument, self::CustomerApproval, self::RequiredDocument => ActionGroup::AgreementApproval,
            self::WorkTask, self::SupplyFollowUp, self::WorkshopOrder => ActionGroup::ExecutionSupply,
            self::PaymentCheck => ActionGroup::Finance,
            self::RecordDelivery => ActionGroup::Delivery,
        };
    }

    public function completion(): CompletionMode
    {
        return match ($this) {
            self::CaptureNeeds, self::PrepareDesign, self::WorkTask, self::RequiredDocument => CompletionMode::Task,
            self::RecordMeasurements => CompletionMode::Measurement,
            self::PrepareDocument, self::CustomerApproval, self::SupplyFollowUp, self::PaymentCheck,
            self::RecordDelivery, self::WorkshopOrder => CompletionMode::Source,
        };
    }

    /**
     * License capability the action needs (none today: custom-order work is in every plan). FlowCheck refuses an
     * action whose capability is off; ready-goods limits never block custom work.
     */
    // @phpstan-ignore return.unusedType (typed for a future licensed action)
    public function capability(): ?string
    {
        return null;
    }

    /**
     * Validation rules of the action's inputs (Laravel rules, keys relative to `inputs`).
     *
     * @return array<string, list<string>>
     */
    public function inputRules(): array
    {
        return match ($this) {
            self::CaptureNeeds, self::RecordDelivery => [],
            self::RecordMeasurements => [
                'purpose' => ['required', 'in:initial,final'],
                'measurement_kind' => ['required', 'in:'.implode(',', self::MEASUREMENT_KINDS)],
            ],
            self::PrepareDesign => ['requires_attachment' => ['required', 'boolean']],
            self::PrepareDocument => ['document' => ['required', 'in:'.implode(',', self::DOCUMENTS)]],
            self::CustomerApproval => ['subject' => ['required', 'in:'.implode(',', self::APPROVAL_SUBJECTS)]],
            self::WorkTask => [
                'label' => ['required', 'array'],
                'label.ar' => ['required', 'string', 'max:80'],
                'label.en' => ['nullable', 'string', 'max:80'],
                'requires_attachment' => ['required', 'boolean'],
            ],
            self::SupplyFollowUp => ['reference' => ['required', 'string', 'regex:/^[a-z0-9_]{1,30}$/']],
            self::WorkshopOrder => [
                'reference' => ['required', 'string', 'regex:/^[a-z0-9_]{1,30}$/'],
                'milestone' => ['required', 'in:'.implode(',', self::WORKSHOP_MILESTONES)],
            ],
            self::PaymentCheck => [
                'reference' => ['required', 'string', 'regex:/^[a-z0-9_]{1,30}$/'],
                'basis' => ['required', 'in:'.implode(',', self::PAYMENT_BASES)],
                'value' => ['nullable', 'integer', 'min:1', 'max:1000000000000'],
            ],
            self::RequiredDocument => [
                'document_type' => ['required', 'string', 'regex:/^[a-z0-9_]{1,30}$/'],
                'review_required' => ['required', 'boolean'],
            ],
        };
    }

    /**
     * Default inputs when an action is added without any (the editor starts from these).
     *
     * @return array<string, mixed>
     */
    public function defaultInputs(): array
    {
        return match ($this) {
            self::CaptureNeeds, self::RecordDelivery => [],
            self::RecordMeasurements => ['purpose' => 'final', 'measurement_kind' => 'final'],
            self::PrepareDesign => ['requires_attachment' => true],
            self::PrepareDocument => ['document' => 'quote'],
            self::CustomerApproval => ['subject' => 'quote'],
            self::WorkTask => ['label' => ['ar' => 'مهمة عمل', 'en' => 'Work task'], 'requires_attachment' => false],
            self::SupplyFollowUp => ['reference' => 'materials'],
            self::WorkshopOrder => ['reference' => 'workshop', 'milestone' => 'sent'],
            self::PaymentCheck => ['reference' => 'first', 'basis' => 'due', 'value' => null],
            self::RequiredDocument => ['document_type' => 'document', 'review_required' => false],
        };
    }

    /**
     * The input that tells two uses of the same action apart (initial/final measurement, first/last payment). An
     * action may repeat only with a different purpose; collection, receipt and delivery are never counted twice.
     *
     * @param  array<string, mixed>  $inputs
     */
    public function referenceOf(array $inputs): ?string
    {
        return match ($this) {
            self::RecordMeasurements => 'purpose:'.($inputs['purpose'] ?? ''),
            self::PrepareDocument => 'document:'.($inputs['document'] ?? ''),
            self::CustomerApproval => 'subject:'.($inputs['subject'] ?? ''),
            self::SupplyFollowUp, self::PaymentCheck => 'reference:'.($inputs['reference'] ?? ''),
            self::WorkshopOrder => 'reference:'.($inputs['reference'] ?? '').':'.($inputs['milestone'] ?? ''),
            self::RequiredDocument => 'document_type:'.($inputs['document_type'] ?? ''),
            self::RecordDelivery => 'delivery',
            self::CaptureNeeds, self::PrepareDesign, self::WorkTask => null,
        };
    }

    /**
     * Whether `$other` (placed earlier, or in the same stage) is a system prerequisite of this action. These are the
     * only links the engine knows; the editor never draws arrows (domain 03).
     *
     * @param  array<string, mixed>  $inputs
     * @param  array<string, mixed>  $otherInputs
     */
    public function dependsOn(array $inputs, self $other, array $otherInputs): bool
    {
        return match ($this) {
            // An approval needs its document: a design for a design approval, the quote/contract for theirs.
            self::CustomerApproval => ($inputs['subject'] ?? null) === 'design'
                ? $other === self::PrepareDesign
                : $other === self::PrepareDocument && ($otherInputs['document'] ?? null) === ($inputs['subject'] ?? null),
            // A due, a percentage or the remaining balance needs a known value: the agreement's approval comes first.
            self::PaymentCheck => in_array($inputs['basis'] ?? null, ['due', 'percent', 'remaining'], true)
                && $other === self::CustomerApproval && in_array($otherInputs['subject'] ?? null, self::DOCUMENTS, true),
            // A design is prepared after the measurements it relies on, when the flow takes them.
            self::PrepareDesign => $other === self::RecordMeasurements,
            // Receiving from a workshop follows sending the same workshop order.
            self::WorkshopOrder => ($inputs['milestone'] ?? null) === 'received' && $other === self::WorkshopOrder
                && ($otherInputs['milestone'] ?? null) === 'sent' && ($otherInputs['reference'] ?? null) === ($inputs['reference'] ?? null),
            default => false,
        };
    }

    /**
     * Prerequisite the flow MUST contain (at the same stage or before), or null: publishing without it is refused.
     *
     * @param  array<string, mixed>  $inputs
     */
    public function requiredPrerequisite(array $inputs): ?string
    {
        return match ($this) {
            self::CustomerApproval => 'approval_before_document',
            self::PaymentCheck => in_array($inputs['basis'] ?? null, ['due', 'percent', 'remaining'], true) ? 'payment_without_agreement' : null,
            self::WorkshopOrder => ($inputs['milestone'] ?? null) === 'received' ? 'receipt_without_workshop_order' : null,
            default => null,
        };
    }

    /**
     * @return array{ar: string, en: string}
     */
    public function label(): array
    {
        return match ($this) {
            self::CaptureNeeds => ['ar' => 'تثبيت الاحتياج والمواصفات', 'en' => 'Confirm needs and specifications'],
            self::RecordMeasurements => ['ar' => 'تسجيل القياسات', 'en' => 'Record measurements'],
            self::PrepareDesign => ['ar' => 'إعداد التصميم', 'en' => 'Prepare the design'],
            self::PrepareDocument => ['ar' => 'إعداد عرض سعر أو عقد', 'en' => 'Prepare a quote or contract'],
            self::CustomerApproval => ['ar' => 'موافقة على عرض أو تصميم أو عقد', 'en' => 'Approval of a quote, design or contract'],
            self::WorkTask => ['ar' => 'مهمة عمل', 'en' => 'Work task'],
            self::SupplyFollowUp => ['ar' => 'متابعة شراء أو استلام', 'en' => 'Follow a purchase or receipt'],
            self::PaymentCheck => ['ar' => 'تحقق التحصيل', 'en' => 'Verify collection'],
            self::RequiredDocument => ['ar' => 'إرفاق مستند مطلوب', 'en' => 'Attach a required document'],
            self::RecordDelivery => ['ar' => 'تسجيل تسليم', 'en' => 'Record a delivery'],
            self::WorkshopOrder => ['ar' => 'طلب من المصنع (إرسال أو استلام)', 'en' => 'Factory order (sent or received)'],
        };
    }

    /**
     * Catalogue for the flow editor: every action with its group, completion rule and input rules.
     *
     * @return list<array{type: string, group: string, completion: string, label: array{ar: string, en: string}, inputs: array<string, list<string>>, defaults: array<string, mixed>}>
     */
    public static function catalogue(): array
    {
        return array_map(fn (self $t) => [
            'type' => $t->value,
            'group' => $t->group()->value,
            'completion' => $t->completion()->value,
            'label' => $t->label(),
            'inputs' => $t->inputRules(),
            'defaults' => $t->defaultInputs(),
        ], self::cases());
    }
}
