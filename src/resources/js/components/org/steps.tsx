import { Check } from 'lucide-react';
import { Icon } from '@/components/opsone/icon';
import { cn } from '@/lib/utils';

export type StepItem = {
    key: string;
    label: string;
    state: 'done' | 'skipped' | null;
    stateLabel: string;
    href?: string;
};

/** Ordered list of wizard steps; the current one carries aria-current="step". */
export function Steps({
    label,
    steps,
    current,
    renderLink,
}: {
    label: string;
    steps: StepItem[];
    current: string;
    renderLink: (step: StepItem, children: React.ReactNode) => React.ReactNode;
}) {
    return (
        <nav aria-label={label}>
            <ol className="m-0 flex list-none flex-col gap-1 p-0 max-lg:flex-row max-lg:gap-2 max-lg:overflow-x-auto">
                {steps.map((step, index) => {
                    const isCurrent = step.key === current;

                    return (
                        <li
                            key={step.key}
                            aria-current={isCurrent ? 'step' : undefined}
                            className="max-lg:shrink-0"
                        >
                            {renderLink(
                                step,
                                <span
                                    className={cn(
                                        'flex min-h-11 items-center gap-3 rounded-md px-3 text-control',
                                        isCurrent
                                            ? 'bg-brand-subtle font-semibold text-brand-subtle-text'
                                            : 'text-weak hover:bg-hover hover:text-strong',
                                    )}
                                >
                                    <span
                                        aria-hidden="true"
                                        className={cn(
                                            'inline-flex size-7 shrink-0 items-center justify-center rounded-full border text-label',
                                            step.state === 'done'
                                                ? 'border-transparent bg-success-bg text-success-text'
                                                : isCurrent
                                                  ? 'border-brand text-brand-subtle-text'
                                                  : 'border-stroke-control',
                                        )}
                                    >
                                        {step.state === 'done' ? (
                                            <Icon icon={Check} size="sm" />
                                        ) : (
                                            index + 1
                                        )}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate">
                                            {step.label}
                                        </span>
                                        <span className="block text-caption font-normal text-weak max-lg:sr-only">
                                            {step.stateLabel}
                                        </span>
                                    </span>
                                </span>,
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
