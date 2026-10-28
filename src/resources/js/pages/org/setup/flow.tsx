import { Head, router } from '@inertiajs/react';
import { Check, Info } from 'lucide-react';
import FlowController from '@/actions/App/Http/Controllers/Flow/FlowController';
import { pick } from '@/components/flow/definition';
import { TemplatePicker } from '@/components/flow/template-picker';
import type { FlowOptions, FlowState } from '@/components/flow/types';
import { Icon } from '@/components/opsone/icon';
import { StepFrame } from '@/components/org/step-frame';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { useLocale, useT } from '@/lib/i18n';

type Props = {
    flow: FlowState;
    flowOptions: FlowOptions;
    canChooseFlow: boolean;
    canEditFlow: boolean;
};

/** `WizardFlow`: the starting template of the facility flow; the full editor stays available from settings. */
export default function SetupFlow({
    flow,
    flowOptions,
    canChooseFlow,
    canEditFlow,
}: Props) {
    const t = useT();
    const { locale } = useLocale();

    return (
        <>
            <Head title={t('org.setup.step_flow')} />
            <StepFrame
                step="flow"
                description={t('org.setup.flow_intro')}
                where="org.setup.where_flow"
            >
                <div className="flex flex-col gap-5">
                    <div
                        className="rounded-md border border-brand bg-brand-subtle p-4"
                        data-test="wizard-flow-current"
                    >
                        <div className="mb-3 flex flex-wrap items-center gap-2">
                            <span className="font-semibold text-strong">
                                {t('org.setup.flow_default_title')}
                            </span>
                            <Badge variant="neutral">
                                {t('flow.version.current', {
                                    n: flow.version.number,
                                })}
                            </Badge>
                        </div>
                        <ol className="m-0 flex list-none flex-wrap gap-2 p-0">
                            {flow.published.stages.map((s, i) => (
                                <li
                                    key={s.key}
                                    className="rounded-sm bg-surface px-2.5 py-1 text-label text-strong"
                                >
                                    {i + 1}. {pick(s.name, locale)}
                                </li>
                            ))}
                        </ol>
                    </div>

                    <TemplatePicker
                        flow={flow}
                        options={flowOptions}
                        url={FlowController.chooseTemplate.url()}
                        canChoose={canChooseFlow}
                        onDone={() => router.reload()}
                    />

                    {canEditFlow && (
                        <div>
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() =>
                                    router.visit(FlowController.edit.url())
                                }
                                data-test="wizard-flow-editor"
                            >
                                {t('flow.wizard.open_editor')}
                            </Button>
                        </div>
                    )}

                    <div>
                        <h2 className="mb-2 text-label text-strong">
                            {t('org.setup.flow_after')}
                        </h2>
                        <ul className="m-0 flex list-none flex-col gap-2 p-0">
                            {(
                                [
                                    ['org.setup.flow_note_1', Check],
                                    ['org.setup.flow_note_2', Check],
                                    ['org.setup.flow_note_3', Info],
                                ] as const
                            ).map(([key, icon]) => (
                                <li
                                    key={key}
                                    className="flex items-start gap-2 text-body text-strong"
                                >
                                    <Icon
                                        icon={icon}
                                        size="sm"
                                        className="mt-1 text-weak"
                                    />
                                    {t(key)}
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            </StepFrame>
        </>
    );
}
