import { Head } from '@inertiajs/react';
import { Lock, Pencil, Save, ShieldCheck, Undo2 } from 'lucide-react';
import { useState } from 'react';
import FlowController from '@/actions/App/Http/Controllers/Flow/FlowController';
import { CheckDialog } from '@/components/flow/check-dialog';
import { clone, pick } from '@/components/flow/definition';
import { StagesWorkspace } from '@/components/flow/stages-workspace';
import { TemplatePicker } from '@/components/flow/template-picker';
import type {
    Definition,
    FlowIssue,
    FlowOptions,
    FlowState,
    FlowVersionRow,
} from '@/components/flow/types';
import { Icon } from '@/components/opsone/icon';
import { notify } from '@/components/opsone/toast';
import { PageHeader } from '@/components/opsone/page-header';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { api } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';

type Props = {
    flow: FlowState;
    versions: FlowVersionRow[];
    options: FlowOptions;
    can: { edit: boolean; publish: boolean };
    selected: { stage: string | null; action: string | null };
};

type FlowResponse = { flow: FlowState; versions: FlowVersionRow[] };

/**
 * `FlowEdit` with `FlowStage1-5`, `FlowAddAction`, `FlowAction_*` and `FlowCheck`: the facility's single flow. The
 * published version is shown read-only; «Edit» opens a draft that can be saved half-done, checked and published.
 * New orders use the published version; running orders keep the one they started with.
 */
export default function FlowEdit({
    flow: initial,
    versions: initialVersions,
    options,
    can,
    selected,
}: Props) {
    const t = useT();
    const { locale } = useLocale();
    const [flow, setFlow] = useState(initial);
    const [versions, setVersions] = useState(initialVersions);
    const hasDraft = flow.status === 'published_with_draft';
    const [localEditing, setLocalEditing] = useState(false);
    const editing = can.edit && (hasDraft || localEditing);
    const [def, setDef] = useState<Definition>(
        clone(initial.draft ?? initial.published),
    );
    const [dirty, setDirty] = useState(false);
    const [stage, setStage] = useState(() => {
        const found = def.stages.findIndex((s) => s.key === selected.stage);

        return found === -1 ? 0 : found;
    });
    const [openAction, setOpenAction] = useState<number | null>(() => {
        const s = def.stages.find((x) => x.key === selected.stage);
        const i = s?.actions.findIndex((a) => a.key === selected.action) ?? -1;

        return i === -1 ? null : i;
    });
    const [issues, setIssues] = useState<FlowIssue[]>([]);
    const [checkOpen, setCheckOpen] = useState(false);
    const [busy, setBusy] = useState<string | null>(null);
    const [message, setMessage] = useState<string | null>(null);
    const [publishError, setPublishError] = useState<string | null>(null);

    const shown: Definition = editing ? def : flow.published;
    const absorb = (data: FlowResponse) => {
        setFlow(data.flow);
        setVersions(data.versions);
    };
    const failure = (result: Awaited<ReturnType<typeof api>>) =>
        result.stale ? t('flow.stale') : (result.message ?? t('flow.failed'));

    const startEditing = () => {
        setDef(clone(flow.draft ?? flow.published));
        setDirty(false);
        setIssues([]);
        setLocalEditing(true);
    };

    const saveDraft = async (): Promise<boolean> => {
        setBusy('save');
        setMessage(null);
        const result = await api<FlowResponse>(
            'PUT',
            FlowController.saveDraft.url(),
            { lock_version: flow.lock_version, stages: def.stages },
        );
        setBusy(null);
        if (result.ok && result.data) {
            absorb(result.data);
            setDirty(false);
            setLocalEditing(false);

            return true;
        }
        setMessage(failure(result));

        return false;
    };

    const saveClicked = async () => {
        if (await saveDraft()) {
            notify('success', { title: t('flow.draft.saved') });
        }
    };

    const discard = async () => {
        if (!hasDraft) {
            setLocalEditing(false);
            setDirty(false);
            setIssues([]);

            return;
        }
        setBusy('discard');
        setMessage(null);
        const result = await api<FlowResponse>(
            'DELETE',
            FlowController.discardDraft.url(),
            { lock_version: flow.lock_version },
        );
        setBusy(null);
        if (result.ok && result.data) {
            absorb(result.data);
            setDef(clone(result.data.flow.published));
            setDirty(false);
            setIssues([]);
            setLocalEditing(false);
            notify('success', { title: t('flow.draft.discarded') });

            return;
        }
        setMessage(failure(result));
    };

    const checkAndPublish = async () => {
        if ((dirty || !hasDraft) && !(await saveDraft())) {
            return;
        }
        setBusy('check');
        const result = await api<{ passes: boolean; issues: FlowIssue[] }>(
            'POST',
            FlowController.check.url(),
            { stages: def.stages },
        );
        setBusy(null);
        if (result.ok && result.data) {
            setIssues(result.data.issues);
            setPublishError(null);
            setCheckOpen(true);

            return;
        }
        setMessage(failure(result));
    };

    const publish = async () => {
        setBusy('publish');
        setPublishError(null);
        const result = await api<FlowResponse>(
            'POST',
            FlowController.publish.url(),
            { lock_version: flow.lock_version },
        );
        setBusy(null);
        if (result.ok && result.data) {
            absorb(result.data);
            setDef(clone(result.data.flow.published));
            setIssues([]);
            setCheckOpen(false);
            setLocalEditing(false);
            notify('success', { title: t('flow.publish.done') });

            return;
        }
        const found = result.body?.issues;
        if (Array.isArray(found)) {
            setIssues(found as FlowIssue[]);
        }
        setPublishError(failure(result));
    };

    const where = (issue: FlowIssue): string => {
        const s = def.stages.find((x) => x.key === issue.stage);
        const a = s?.actions.find((x) => x.key === issue.action);
        const stageName = s ? pick(s.name, locale) : '';

        return (
            [stageName, a ? pick(a.name, locale) : null]
                .filter(Boolean)
                .join(' · ') || t('flow.check.general')
        );
    };

    const go = (issue: FlowIssue) => {
        const si = def.stages.findIndex((x) => x.key === issue.stage);
        if (si === -1) {
            return;
        }
        setStage(si);
        const ai = def.stages[si].actions.findIndex(
            (a) => a.key === issue.action,
        );
        setOpenAction(ai === -1 ? null : ai);
        setCheckOpen(false);
    };

    return (
        <>
            <Head title={t('flow.title')} />
            <PageHeader
                title={t('flow.title')}
                description={t('flow.intro')}
                actions={
                    editing ? (
                        <>
                            <Button
                                type="button"
                                variant="secondary"
                                loading={busy === 'discard'}
                                onClick={discard}
                                data-test="flow-discard"
                            >
                                <Icon icon={Undo2} />
                                {t('flow.draft.discard')}
                            </Button>
                            <Button
                                type="button"
                                variant="secondary"
                                loading={busy === 'save'}
                                onClick={saveClicked}
                                data-test="flow-save"
                            >
                                <Icon icon={Save} />
                                {t('flow.draft.save')}
                            </Button>
                            <Button
                                type="button"
                                loading={busy === 'check'}
                                onClick={checkAndPublish}
                                data-test="flow-check-open"
                            >
                                <Icon icon={ShieldCheck} />
                                {can.publish
                                    ? t('flow.check.and_publish')
                                    : t('flow.check.only')}
                            </Button>
                        </>
                    ) : (
                        <Button
                            type="button"
                            onClick={startEditing}
                            data-test="flow-edit"
                        >
                            <Icon icon={Pencil} />
                            {t('flow.edit')}
                        </Button>
                    )
                }
            />

            <div
                className="mb-4 flex flex-wrap items-center gap-2"
                data-test="flow-status"
            >
                <Badge variant={hasDraft ? 'warning' : 'success'}>
                    {hasDraft
                        ? t('flow.status.published_with_draft')
                        : t('flow.status.published')}
                </Badge>
                <Badge variant="neutral">
                    {t('flow.version.current', { n: flow.version.number })}
                </Badge>
                <span className="text-weak">
                    {t('flow.version.published_at', {
                        date: formatDate(flow.version.published_at, locale),
                    })}
                </span>
                {!editing && (
                    <span
                        className="inline-flex items-center gap-1 text-weak"
                        data-test="flow-readonly"
                    >
                        <Icon icon={Lock} size="sm" />
                        {t('flow.readonly')}
                    </span>
                )}
            </div>

            {hasDraft && flow.draft_by && (
                <Alert variant="info" className="mb-4">
                    <AlertBody>
                        {t('flow.draft.by', { name: flow.draft_by })}
                    </AlertBody>
                </Alert>
            )}
            <p className="mb-4 text-body text-weak">{t('flow.effect')}</p>
            {message && (
                <Alert variant="danger" className="mb-4" role="alert">
                    <AlertBody>{message}</AlertBody>
                </Alert>
            )}

            <StagesWorkspace
                def={shown}
                onChange={(next) => {
                    setDef(next);
                    setDirty(true);
                    setIssues([]);
                }}
                options={options}
                issues={editing ? issues : []}
                readOnly={!editing}
                selected={stage}
                onSelect={setStage}
                openAction={openAction}
                onOpenActionHandled={() => setOpenAction(null)}
                fixedFirst
            />

            <div className="mt-4 grid gap-4 lg:grid-cols-2">
                <Card className="content-start gap-3" data-test="flow-versions">
                    <h2 className="text-subtitle text-strong">
                        {t('flow.versions.title')}
                    </h2>
                    <p className="text-caption text-weak">
                        {t('flow.versions.hint')}
                    </p>
                    <ul className="m-0 grid list-none gap-2 p-0">
                        {[...versions].reverse().map((v) => (
                            <li
                                key={v.number}
                                className="flex flex-wrap items-center justify-between gap-2 border-b border-stroke-weak pb-2 last:border-0"
                            >
                                <span className="text-strong">
                                    {t('flow.version.current', { n: v.number })}
                                    {v.number === flow.version.number && (
                                        <Badge variant="brand" className="ms-2">
                                            {t('flow.version.active')}
                                        </Badge>
                                    )}
                                </span>
                                <span className="text-caption text-weak">
                                    {formatDate(v.published_at, locale)} ·{' '}
                                    {t('flow.version.running', {
                                        n: v.running_orders,
                                    })}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Card>
                {can.publish && (
                    <Card className="content-start gap-3">
                        <h2 className="text-subtitle text-strong">
                            {t('flow.template.title')}
                        </h2>
                        <TemplatePicker
                            flow={flow}
                            options={options}
                            url={FlowController.chooseTemplate.url()}
                            canChoose={can.publish}
                            onDone={(data) => {
                                absorb(data);
                                setDef(clone(data.flow.published));
                                setDirty(false);
                                setIssues([]);
                                setLocalEditing(false);
                            }}
                        />
                    </Card>
                )}
            </div>

            <CheckDialog
                open={checkOpen}
                onClose={() => setCheckOpen(false)}
                issues={issues}
                where={where}
                onGo={go}
                canPublish={can.publish}
                publishing={busy === 'publish'}
                onPublish={publish}
                error={publishError}
            />
        </>
    );
}
