import { CircleCheck, CircleX } from 'lucide-react';
import { Icon } from '@/components/opsone/icon';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useT } from '@/lib/i18n';
import type { FlowIssue } from './types';

/**
 * `FlowCheck`: what blocks publishing, each issue linked to the stage and action that causes it, plus what passed.
 * A flow with issues cannot be published; fixing them happens in the editor, one click away.
 */
export function CheckDialog({
    open,
    onClose,
    issues,
    where,
    onGo,
    canPublish,
    publishing,
    onPublish,
    error,
}: {
    open: boolean;
    onClose: () => void;
    issues: FlowIssue[];
    /** «Stage · Action» of an issue, in the editor's names. */
    where: (issue: FlowIssue) => string;
    onGo: (issue: FlowIssue) => void;
    canPublish: boolean;
    publishing: boolean;
    onPublish: () => void;
    error: string | null;
}) {
    const t = useT();
    const passes = issues.length === 0;

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent size="md" aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>{t('flow.check.title')}</DialogTitle>
                    <DialogDescription>
                        {passes
                            ? t('flow.check.passes')
                            : t('flow.check.count', { n: issues.length })}
                    </DialogDescription>
                </DialogHeader>

                <div
                    className="grid max-h-[60vh] gap-3 overflow-y-auto pe-1"
                    data-test="flow-check"
                >
                    {error && (
                        <Alert variant="danger" role="alert">
                            <AlertBody>{error}</AlertBody>
                        </Alert>
                    )}
                    {issues.map((issue, i) => (
                        <div
                            key={`${issue.path}-${issue.code}-${i}`}
                            className="flex items-start gap-3 rounded-md border border-danger bg-danger-bg p-3"
                            data-test="flow-issue"
                        >
                            <Icon
                                icon={CircleX}
                                className="mt-0.5 shrink-0 text-danger-text"
                            />
                            <div className="grid min-w-0 gap-1">
                                <p className="text-label text-danger-text">
                                    {where(issue)}
                                </p>
                                <p className="text-body text-strong">
                                    {issue.message}
                                </p>
                                {issue.stage !== null && (
                                    <div>
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            size="sm"
                                            onClick={() => onGo(issue)}
                                            data-test="flow-issue-go"
                                        >
                                            {issue.action !== null
                                                ? t('flow.check.go_action')
                                                : t('flow.check.go_stage')}
                                        </Button>
                                    </div>
                                )}
                            </div>
                        </div>
                    ))}
                    {passes && (
                        <ul className="m-0 grid list-none gap-2 p-0">
                            {(
                                [
                                    'flow.check.ok_start',
                                    'flow.check.ok_dependencies',
                                    'flow.check.ok_assignments',
                                ] as const
                            ).map((key) => (
                                <li
                                    key={key}
                                    className="flex items-start gap-2 text-body text-strong"
                                >
                                    <Icon
                                        icon={CircleCheck}
                                        size="sm"
                                        className="mt-1 text-success-text"
                                    />
                                    {t(key)}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <DialogFooter>
                    <Button type="button" variant="secondary" onClick={onClose}>
                        {t('flow.close')}
                    </Button>
                    {canPublish && (
                        <Button
                            type="button"
                            disabled={!passes}
                            loading={publishing}
                            onClick={onPublish}
                            data-test="flow-publish"
                        >
                            {t('flow.publish.action')}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
