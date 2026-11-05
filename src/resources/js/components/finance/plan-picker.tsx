import { useState } from 'react';
import { SelectField } from '@/components/opsone/field';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { newKey } from '@/components/agreement/money';
import type { FinancePayload } from './types';
import { useFinanceCall } from './use-finance-call';

/**
 * The order's payment plan (ADR-0037, P-38): which plan the agreement will copy, or which one the schedule came
 * from. Before the agreement choosing only records it; afterwards it replaces the schedule from the plan.
 */
export function PlanPicker({
    url,
    payload,
    onDone,
}: {
    url: string;
    payload: FinancePayload;
    onDone: (next: FinancePayload) => void;
}) {
    const t = useT();
    const call = useFinanceCall();
    const [key] = useState(newKey);
    const current = payload.plans.find((p) => p.key === payload.plan.key);
    const [planId, setPlanId] = useState<string>(
        String(current?.id ?? payload.plans[0]?.id ?? ''),
    );
    const name = payload.plan.name ?? t('finance.plan.builtin');

    const submit = async () => {
        const data = await call.run('PUT', url, {
            idempotency_key: key,
            lock_version: payload.lock_version,
            plan_id: Number(planId),
        });
        if (data) {
            onDone(data.finance);
        }
    };

    return (
        <div className="space-y-2 px-4 pb-2" data-test="payment-plan">
            <p className="text-weak">
                {payload.plan.copied
                    ? t('finance.plan.copied', { name })
                    : t('finance.plan.will_use', { name })}
            </p>
            {payload.can.plan && payload.plans.length > 0 && (
                <div className="flex flex-wrap items-end gap-2">
                    <SelectField
                        label={t('finance.plan.title')}
                        value={planId}
                        onChange={(e) => setPlanId(e.target.value)}
                        options={payload.plans.map((p) => ({
                            value: String(p.id),
                            label: p.name,
                        }))}
                    />
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        loading={call.busy}
                        onClick={submit}
                        title={
                            payload.has_agreement
                                ? t('finance.plan.apply_hint')
                                : undefined
                        }
                        data-test="choose-plan"
                    >
                        {payload.has_agreement
                            ? t('finance.plan.apply')
                            : t('finance.plan.choose')}
                    </Button>
                </div>
            )}
            {call.error && (
                <Alert variant="danger" role="alert">
                    <AlertBody>{call.error}</AlertBody>
                </Alert>
            )}
        </div>
    );
}
