import { Head } from '@inertiajs/react';
import { Check, Info } from 'lucide-react';
import { Icon } from '@/components/opsone/icon';
import { StepFrame } from '@/components/org/step-frame';
import { useT } from '@/lib/i18n';

export default function SetupFlow() {
    const t = useT();

    return (
        <>
            <Head title={t('org.setup.step_flow')} />
            <StepFrame
                step="flow"
                description={t('org.setup.flow_intro')}
                where="org.setup.where_flow"
            >
                <div className="flex flex-col gap-5">
                    <div className="flex items-start gap-3 rounded-md border border-brand bg-brand-subtle p-4">
                        <input
                            type="radio"
                            checked
                            readOnly
                            aria-labelledby="flow-default"
                            className="mt-1 size-5 shrink-0 accent-brand"
                        />
                        <div className="flex flex-col gap-1">
                            <span
                                id="flow-default"
                                className="font-semibold text-strong"
                            >
                                {t('org.setup.flow_default_title')}
                            </span>
                            <span className="text-body text-weak">
                                {t('org.setup.flow_default_desc')}
                            </span>
                        </div>
                    </div>
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
