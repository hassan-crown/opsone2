import { Badge } from '@/components/ui/badge';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type Change = 'added' | 'removed' | 'changed' | 'same';

export type FlowStageChange = {
    key: string;
    name: string;
    change: Change;
    origin: 'template' | 'description' | 'both' | null;
    target_days: number | null;
    before_days: number | null;
    actions: { key: string; name: string; type: string; change: Change }[];
};

const VARIANT: Record<Change, 'success' | 'danger' | 'warning' | 'neutral'> = {
    added: 'success',
    removed: 'danger',
    changed: 'warning',
    same: 'neutral',
};

/** The proposed flow against the published one, stage by stage, with where each stage came from. */
export function FlowDiff({ stages }: { stages: FlowStageChange[] }) {
    const t = useT();

    return (
        <ol className="space-y-3" data-test="ai-setup-flow-diff">
            {stages.map((stage) => (
                <li
                    key={stage.key}
                    className="rounded-lg border border-stroke-weak p-3"
                >
                    <div className="flex flex-wrap items-center gap-2">
                        <span
                            className={
                                stage.change === 'removed'
                                    ? 'text-body-strong text-weak line-through'
                                    : 'text-body-strong text-strong'
                            }
                        >
                            {stage.name}
                        </span>
                        <Badge variant={VARIANT[stage.change]}>
                            {t(`ai_setup.change.${stage.change}` as TranslationKey)}
                        </Badge>
                        {stage.origin && (
                            <Badge variant="info">
                                {t(`ai_setup.origin.${stage.origin}` as TranslationKey)}
                            </Badge>
                        )}
                        {stage.target_days !== null && (
                            <span className="text-caption text-weak">
                                {t('ai_setup.days', { days: stage.target_days })}
                                {stage.before_days !== null &&
                                    stage.before_days !== stage.target_days &&
                                    ` (${t('ai_setup.days_before', { days: stage.before_days })})`}
                            </span>
                        )}
                    </div>
                    {stage.actions.length > 0 && (
                        <ul className="mt-2 space-y-1 ps-4 text-body">
                            {stage.actions.map((action) => (
                                <li
                                    key={action.key}
                                    className="flex flex-wrap items-center gap-2"
                                >
                                    <span
                                        className={
                                            action.change === 'removed'
                                                ? 'text-weak line-through'
                                                : 'text-strong'
                                        }
                                    >
                                        {action.name}
                                    </span>
                                    {action.change !== 'same' && (
                                        <Badge variant={VARIANT[action.change]}>
                                            {t(`ai_setup.change.${action.change}` as TranslationKey)}
                                        </Badge>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </li>
            ))}
        </ol>
    );
}
