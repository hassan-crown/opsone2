import { useEffect, useState } from 'react';
import OrderProgressController from '@/actions/App/Http/Controllers/Flow/OrderProgressController';
import { TextareaField } from '@/components/opsone/field';
import { notify } from '@/components/opsone/toast';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { api } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { clone } from './definition';
import { explain } from './progress-api';
import type { ProgressResponse } from './progress-api';
import { StagesWorkspace } from './stages-workspace';
import type { Definition, FlowIssue, FlowOptions, StageDef } from './types';

/**
 * `OrderEditUpcoming`: edits only the stages of THIS order that have not started, with a written reason. Started and
 * completed work, results and the facility flow are not touched; FlowCheck re-checks the result on the server.
 */
export function UpcomingEditor({
    orderId,
    lockVersion,
    onCancel,
    onSaved,
}: {
    orderId: number;
    lockVersion: number;
    onCancel: () => void;
    onSaved: (next: ProgressResponse) => void;
}) {
    const t = useT();
    const [loaded, setLoaded] = useState<{
        stages: StageDef[];
        options: FlowOptions;
    } | null>(null);
    const [def, setDef] = useState<Definition>({ stages: [] });
    const [stage, setStage] = useState(0);
    const [reason, setReason] = useState('');
    const [issues, setIssues] = useState<FlowIssue[]>([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [reasonError, setReasonError] = useState<string | undefined>();

    useEffect(() => {
        let alive = true;
        void api<{ stages: StageDef[]; options: FlowOptions }>(
            'GET',
            OrderProgressController.upcoming.url(orderId),
        ).then((result) => {
            if (!alive) {
                return;
            }
            if (result.ok && result.data) {
                setLoaded(result.data);
                setDef({ stages: clone(result.data.stages) });
            } else {
                setError(explain(result, t));
            }
        });

        return () => {
            alive = false;
        };
    }, [orderId, t]);

    const save = async () => {
        setBusy(true);
        setError(null);
        setReasonError(undefined);
        const result = await api<ProgressResponse>(
            'PUT',
            OrderProgressController.editUpcoming.url(orderId),
            {
                lock_version: lockVersion,
                reason,
                stages: def.stages,
            },
        );
        setBusy(false);
        if (result.ok && result.data) {
            notify('success', { title: t('progress.edit_upcoming.done') });
            onSaved(result.data);

            return;
        }
        const found = result.body?.issues;
        setIssues(Array.isArray(found) ? (found as FlowIssue[]) : []);
        setReasonError(result.errors.reason?.[0]);
        if (!result.errors.reason) {
            setError(
                Array.isArray(found)
                    ? t('progress.edit_upcoming.fix_issues')
                    : explain(result, t),
            );
        }
    };

    return (
        <div className="grid gap-4" data-test="upcoming-editor">
            <Card className="gap-1">
                <h3 className="text-subtitle text-strong">
                    {t('progress.edit_upcoming.title')}
                </h3>
                <p className="text-body text-weak">
                    {t('progress.edit_upcoming.intro')}
                </p>
            </Card>
            {error && (
                <Alert variant="danger" role="alert">
                    <AlertBody>{error}</AlertBody>
                </Alert>
            )}
            {loaded === null ? (
                <Skeleton className="h-40 w-full" />
            ) : (
                <>
                    <StagesWorkspace
                        def={def}
                        onChange={(next) => {
                            setDef(next);
                            setIssues([]);
                        }}
                        options={loaded.options}
                        issues={issues}
                        readOnly={false}
                        selected={stage}
                        onSelect={setStage}
                    />
                    <Card className="gap-3">
                        <TextareaField
                            label={t('progress.edit_upcoming.reason')}
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            maxLength={1000}
                            error={reasonError}
                            required
                        />
                        <div className="flex flex-wrap justify-end gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={onCancel}
                            >
                                {t('flow.cancel')}
                            </Button>
                            <Button
                                type="button"
                                loading={busy}
                                disabled={reason.trim() === ''}
                                onClick={save}
                                data-test="upcoming-save"
                            >
                                {t('progress.edit_upcoming.save')}
                            </Button>
                        </div>
                    </Card>
                </>
            )}
        </div>
    );
}
