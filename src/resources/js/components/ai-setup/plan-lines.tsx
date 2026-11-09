import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

export type ProposedPlan = {
    key: string;
    name: { ar: string; en: string | null };
    lines: {
        key: string;
        name: { ar: string; en: string | null };
        basis: 'percent' | 'rest';
        value: string | null;
        event: string;
        stage_key: string | null;
        offset_days: number;
        grace_days: number;
    }[];
};

/** The proposed payment plan as a short list: share, then when it falls due. */
export function PlanLines({ plan }: { plan: ProposedPlan }) {
    const t = useT();

    return (
        <div className="space-y-2" data-test="ai-setup-plan">
            <p className="text-body-strong text-strong">{plan.name.ar}</p>
            <ul className="space-y-1 ps-4 text-body">
                {plan.lines.map((line) => (
                    <li key={line.key} className="flex flex-wrap gap-2">
                        <span className="text-strong">{line.name.ar}</span>
                        <span className="text-weak">
                            {line.basis === 'rest'
                                ? t('ai_setup.plan_rest')
                                : t('ai_setup.plan_percent', {
                                      percent: line.value ?? '',
                                  })}
                        </span>
                        <span className="text-weak">
                            {t(
                                `ai_setup.event.${line.event}` as TranslationKey,
                            )}
                            {line.stage_key && ` · ${line.stage_key}`}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
