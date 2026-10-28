import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { pick } from './definition';
import type { CatalogueEntry, FlowOptions } from './types';

/** `FlowAddAction`: the ten system actions in their five fixed groups; the facility only picks, never invents. */
export function AddActionDialog({
    open,
    onClose,
    options,
    onPick,
}: {
    open: boolean;
    onClose: () => void;
    options: FlowOptions;
    onPick: (entry: CatalogueEntry) => void;
}) {
    const t = useT();
    const { locale } = useLocale();

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent size="lg" aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>{t('flow.add_action.title')}</DialogTitle>
                    <DialogDescription>
                        {t('flow.add_action.intro')}
                    </DialogDescription>
                </DialogHeader>
                <div
                    className="grid max-h-[65vh] gap-5 overflow-y-auto pe-1"
                    data-test="flow-add-action"
                >
                    {options.groups.map((group) => (
                        <section key={group} className="grid gap-2">
                            <h3 className="text-label text-weak">
                                {t(`flow.group.${group}` as TranslationKey)}
                            </h3>
                            <ul className="m-0 grid list-none gap-2 p-0">
                                {options.catalogue
                                    .filter((c) => c.group === group)
                                    .map((entry) => (
                                        <li key={entry.type}>
                                            <button
                                                type="button"
                                                onClick={() => onPick(entry)}
                                                data-test={`add-${entry.type}`}
                                                className="flex w-full cursor-pointer flex-col gap-1 rounded-md border border-stroke-control bg-surface p-3 text-start hover:bg-hover"
                                            >
                                                <span className="font-semibold text-strong">
                                                    {pick(entry.label, locale)}
                                                </span>
                                                <span className="text-caption text-weak">
                                                    {t(
                                                        `flow.action.${entry.type}.completes` as TranslationKey,
                                                    )}
                                                </span>
                                            </button>
                                        </li>
                                    ))}
                            </ul>
                        </section>
                    ))}
                </div>
            </DialogContent>
        </Dialog>
    );
}
