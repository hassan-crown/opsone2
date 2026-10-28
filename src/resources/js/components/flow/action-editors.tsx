import type { ComponentType } from 'react';
import {
    CheckboxField,
    SelectField,
    TextField,
} from '@/components/opsone/field';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type { ActionTypeKey } from './types';

/**
 * One editor per catalogue action (`FlowAction_info`, `_meas`, `_design`, `_doc`, `_appr`, `_attach`, `_task`,
 * `_supply`, `_pay`, `_deliv`). They edit only the action's inputs; the facility never writes rules or scripts.
 */
export type InputsEditorProps = {
    inputs: Record<string, unknown>;
    onChange: (inputs: Record<string, unknown>) => void;
    disabled: boolean;
    /** Issue message for `inputs.<field>`. */
    error: (field: string) => string | undefined;
};

const str = (v: unknown): string => (typeof v === 'string' ? v : '');

function NoInputs({ type }: { type: ActionTypeKey }) {
    const t = useT();

    return (
        <p className="text-body text-weak">
            {t(`flow.action.${type}.fixed` as TranslationKey)}
        </p>
    );
}

/** FlowAction_info: confirm needs and specifications. */
function InfoEditor() {
    return <NoInputs type="capture_needs" />;
}

/** FlowAction_meas: record measurements. */
function MeasurementsEditor({
    inputs,
    onChange,
    disabled,
    error,
}: InputsEditorProps) {
    const t = useT();

    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <SelectField
                label={t('flow.input.purpose')}
                value={str(inputs.purpose)}
                disabled={disabled}
                error={error('purpose')}
                onChange={(e) =>
                    onChange({ ...inputs, purpose: e.target.value })
                }
                options={[
                    {
                        value: 'initial',
                        label: t('flow.input.purpose_initial'),
                    },
                    { value: 'final', label: t('flow.input.purpose_final') },
                ]}
            />
            <SelectField
                label={t('flow.input.measurement_kind')}
                value={str(inputs.measurement_kind)}
                disabled={disabled}
                error={error('measurement_kind')}
                onChange={(e) =>
                    onChange({ ...inputs, measurement_kind: e.target.value })
                }
                options={[
                    {
                        value: 'estimated',
                        label: t('flow.input.kind_estimated'),
                    },
                    { value: 'final', label: t('flow.input.kind_final') },
                ]}
            />
        </div>
    );
}

/** FlowAction_design: prepare the design. */
function DesignEditor({ inputs, onChange, disabled }: InputsEditorProps) {
    const t = useT();

    return (
        <CheckboxField
            label={t('flow.input.requires_attachment_design')}
            checked={inputs.requires_attachment === true}
            disabled={disabled}
            onChange={(e) =>
                onChange({ ...inputs, requires_attachment: e.target.checked })
            }
        />
    );
}

/** FlowAction_doc: prepare a quote or a contract. */
function DocumentEditor({
    inputs,
    onChange,
    disabled,
    error,
}: InputsEditorProps) {
    const t = useT();

    return (
        <SelectField
            label={t('flow.input.document')}
            value={str(inputs.document)}
            disabled={disabled}
            error={error('document')}
            onChange={(e) => onChange({ ...inputs, document: e.target.value })}
            options={[
                { value: 'quote', label: t('flow.input.document_quote') },
                { value: 'contract', label: t('flow.input.document_contract') },
            ]}
        />
    );
}

/** FlowAction_appr: customer approval of a design, quote or contract. */
function ApprovalEditor({
    inputs,
    onChange,
    disabled,
    error,
}: InputsEditorProps) {
    const t = useT();

    return (
        <SelectField
            label={t('flow.input.subject')}
            value={str(inputs.subject)}
            disabled={disabled}
            error={error('subject')}
            onChange={(e) => onChange({ ...inputs, subject: e.target.value })}
            options={[
                { value: 'design', label: t('flow.input.subject_design') },
                { value: 'quote', label: t('flow.input.subject_quote') },
                { value: 'contract', label: t('flow.input.subject_contract') },
            ]}
        />
    );
}

/** FlowAction_attach: attach a required document. */
function AttachEditor({
    inputs,
    onChange,
    disabled,
    error,
}: InputsEditorProps) {
    const t = useT();

    return (
        <div className="grid gap-4">
            <TextField
                label={t('flow.input.document_type')}
                hint={t('flow.input.identifier_hint')}
                value={str(inputs.document_type)}
                disabled={disabled}
                error={error('document_type')}
                dir="ltr"
                maxLength={30}
                onChange={(e) =>
                    onChange({ ...inputs, document_type: e.target.value })
                }
            />
            <CheckboxField
                label={t('flow.input.review_required')}
                checked={inputs.review_required === true}
                disabled={disabled}
                onChange={(e) =>
                    onChange({ ...inputs, review_required: e.target.checked })
                }
            />
        </div>
    );
}

/** FlowAction_task: a work task with its own label. */
function TaskEditor({ inputs, onChange, disabled, error }: InputsEditorProps) {
    const t = useT();
    const label = (inputs.label ?? {}) as { ar?: string; en?: string | null };

    return (
        <div className="grid gap-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <TextField
                    label={t('flow.input.task_label_ar')}
                    value={str(label.ar)}
                    disabled={disabled}
                    error={error('label.ar') ?? error('label')}
                    maxLength={80}
                    onChange={(e) =>
                        onChange({
                            ...inputs,
                            label: { ...label, ar: e.target.value },
                        })
                    }
                />
                <TextField
                    label={t('flow.input.task_label_en')}
                    value={str(label.en)}
                    disabled={disabled}
                    error={error('label.en')}
                    dir="ltr"
                    maxLength={80}
                    onChange={(e) =>
                        onChange({
                            ...inputs,
                            label: { ...label, en: e.target.value },
                        })
                    }
                />
            </div>
            <CheckboxField
                label={t('flow.input.requires_attachment_task')}
                checked={inputs.requires_attachment === true}
                disabled={disabled}
                onChange={(e) =>
                    onChange({
                        ...inputs,
                        requires_attachment: e.target.checked,
                    })
                }
            />
        </div>
    );
}

/** FlowAction_supply: follow a purchase or a receipt. */
function SupplyEditor({
    inputs,
    onChange,
    disabled,
    error,
}: InputsEditorProps) {
    const t = useT();

    return (
        <TextField
            label={t('flow.input.reference')}
            hint={t('flow.input.reference_hint')}
            value={str(inputs.reference)}
            disabled={disabled}
            error={error('reference')}
            dir="ltr"
            maxLength={30}
            onChange={(e) => onChange({ ...inputs, reference: e.target.value })}
        />
    );
}

/** FlowAction_pay: verify a collection by amount, percentage or the remaining balance. */
function PaymentEditor({
    inputs,
    onChange,
    disabled,
    error,
}: InputsEditorProps) {
    const t = useT();
    const basis = str(inputs.basis);
    const value = typeof inputs.value === 'number' ? inputs.value : null;
    // Amounts are stored in minor units; the field shows the main unit.
    const shown =
        value === null ? '' : String(basis === 'amount' ? value / 100 : value);

    const setValue = (raw: string) => {
        if (raw === '') {
            onChange({ ...inputs, value: null });

            return;
        }
        const n = Number(raw);
        onChange({
            ...inputs,
            value: Number.isFinite(n)
                ? basis === 'amount'
                    ? Math.round(n * 100)
                    : Math.round(n)
                : null,
        });
    };

    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <TextField
                label={t('flow.input.reference')}
                hint={t('flow.input.reference_hint')}
                value={str(inputs.reference)}
                disabled={disabled}
                error={error('reference')}
                dir="ltr"
                maxLength={30}
                onChange={(e) =>
                    onChange({ ...inputs, reference: e.target.value })
                }
            />
            <SelectField
                label={t('flow.input.basis')}
                value={basis}
                disabled={disabled}
                error={error('basis')}
                onChange={(e) =>
                    onChange({
                        ...inputs,
                        basis: e.target.value,
                        value:
                            e.target.value === 'remaining'
                                ? null
                                : e.target.value === 'percent'
                                  ? 50
                                  : null,
                    })
                }
                options={[
                    { value: 'percent', label: t('flow.input.basis_percent') },
                    { value: 'amount', label: t('flow.input.basis_amount') },
                    {
                        value: 'remaining',
                        label: t('flow.input.basis_remaining'),
                    },
                ]}
            />
            {basis !== 'remaining' && (
                <TextField
                    label={
                        basis === 'amount'
                            ? t('flow.input.value_amount')
                            : t('flow.input.value_percent')
                    }
                    type="number"
                    inputMode="decimal"
                    min={basis === 'percent' ? 1 : 0}
                    max={basis === 'percent' ? 100 : undefined}
                    value={shown}
                    disabled={disabled}
                    error={error('value')}
                    dir="ltr"
                    onChange={(e) => setValue(e.target.value)}
                />
            )}
        </div>
    );
}

/** FlowAction_deliv: record a delivery. */
function DeliveryEditor() {
    return <NoInputs type="record_delivery" />;
}

export const INPUT_EDITORS: Record<
    ActionTypeKey,
    ComponentType<InputsEditorProps>
> = {
    capture_needs: InfoEditor,
    record_measurements: MeasurementsEditor,
    prepare_design: DesignEditor,
    prepare_document: DocumentEditor,
    customer_approval: ApprovalEditor,
    required_document: AttachEditor,
    work_task: TaskEditor,
    supply_follow_up: SupplyEditor,
    payment_check: PaymentEditor,
    record_delivery: DeliveryEditor,
};
