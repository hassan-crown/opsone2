import { useState } from 'react';
import { CheckboxField, RadioGroup } from '@/components/opsone/field';
import { notify } from '@/components/opsone/toast';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { api } from '@/lib/api';
import { useLocale, useT } from '@/lib/i18n';
import { pick } from './definition';
import type { FlowOptions, FlowState, FlowVersionRow } from './types';

/**
 * Starting template choice (M-23-T01): applies to NEW orders only. With an unpublished draft the choice must say
 * explicitly that the draft is discarded.
 */
export function TemplatePicker({
    flow,
    options,
    url,
    canChoose,
    onDone,
}: {
    flow: FlowState;
    options: FlowOptions;
    url: string;
    canChoose: boolean;
    onDone: (data: { flow: FlowState; versions: FlowVersionRow[] }) => void;
}) {
    const t = useT();
    const { locale } = useLocale();
    const [value, setValue] = useState(flow.template_key);
    const [discard, setDiscard] = useState(false);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const hasDraft = flow.status === 'published_with_draft';
    const changed = value !== flow.template_key;

    const apply = async () => {
        setBusy(true);
        setMessage(null);
        const result = await api<{
            flow: FlowState;
            versions: FlowVersionRow[];
        }>('POST', url, {
            lock_version: flow.lock_version,
            template: value,
            discard_draft: discard,
        });
        setBusy(false);
        if (result.ok && result.data) {
            notify('success', { title: t('flow.template.applied') });
            onDone(result.data);

            return;
        }
        setMessage(
            result.stale
                ? t('flow.stale')
                : (result.message ?? t('flow.template.failed')),
        );
    };

    return (
        <div className="grid gap-4" data-test="flow-template">
            <RadioGroup
                legend={t('flow.template.legend')}
                name="flow-template"
                value={value}
                onChange={setValue}
                options={options.templates.map((tpl) => ({
                    value: tpl.key,
                    label: `${pick(tpl.label, locale)} — ${t('flow.stage.count', { n: tpl.stages })}`,
                    disabled: !canChoose,
                }))}
            />
            <p className="text-caption text-weak">
                {t('flow.template.effect')}
            </p>
            {message && (
                <Alert variant="danger" role="alert">
                    <AlertBody>{message}</AlertBody>
                </Alert>
            )}
            {changed && hasDraft && (
                <CheckboxField
                    label={t('flow.template.discard_draft')}
                    checked={discard}
                    onChange={(e) => setDiscard(e.target.checked)}
                />
            )}
            {canChoose && changed && (
                <div>
                    <Button
                        type="button"
                        loading={busy}
                        disabled={hasDraft && !discard}
                        onClick={apply}
                        data-test="flow-template-apply"
                    >
                        {t('flow.template.apply')}
                    </Button>
                </div>
            )}
        </div>
    );
}
