import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AiSetupController from '@/actions/App/Http/Controllers/Ai/AiSetupController';
import {
    CheckboxField,
    SelectField,
    TextareaField,
} from '@/components/opsone/field';
import { FlowDiff } from '@/components/ai-setup/flow-diff';
import type { FlowStageChange } from '@/components/ai-setup/flow-diff';
import { PlanLines } from '@/components/ai-setup/plan-lines';
import type { ProposedPlan } from '@/components/ai-setup/plan-lines';
import { PageHeader } from '@/components/opsone/page-header';
import { ReleaseChanges } from '@/components/rules/release-changes';
import type { ReleaseChange } from '@/components/rules/release-changes';
import { Alert, AlertBody, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { edit as flowEdit } from '@/routes/flow';
import { automation, releases } from '@/routes/rules';

type Status =
    | 'pending'
    | 'ready'
    | 'failed'
    | 'applied'
    | 'rejected'
    | 'superseded';

type Question = { question: string; about: string | null };

type Issue = { code: string; path: string; message: string };

type Proposal = {
    base_template: string;
    summary: string;
    questions: Question[];
    payment_plan: ProposedPlan | null;
    automation: {
        key: string;
        name: string;
        trigger: string;
        params: Record<string, number>;
        why: string;
    }[];
    checks: {
        passes: boolean;
        flow_issues: Issue[];
        config_errors: Record<string, string>;
    } | null;
    diff: { config: ReleaseChange[]; flow: FlowStageChange[] };
};

type Setup = {
    id: number;
    status: Status;
    outcome: string | null;
    base_template: string | null;
    description: string;
    answers: { question: string; answer: string }[];
    created_at: string;
    expires_at: string | null;
    proposal: Proposal | null;
};

type Props = {
    available: boolean;
    enabled: boolean;
    driver: string;
    templates: { key: string; label: string }[];
    flow: { lock_version: number; has_draft: boolean; template: string };
    setup: Setup | null;
    history: { id: number; status: Status; created_at: string }[];
};

const STATUS_VARIANT: Record<
    Status,
    'info' | 'success' | 'warning' | 'danger' | 'neutral'
> = {
    pending: 'info',
    ready: 'warning',
    failed: 'danger',
    applied: 'success',
    rejected: 'neutral',
    superseded: 'neutral',
};

export default function AiSetup({
    available,
    templates,
    flow,
    setup,
    history,
}: Props) {
    const t = useT();
    const { locale } = useLocale();
    const proposal = setup?.status === 'ready' ? setup.proposal : null;

    const [description, setDescription] = useState(setup?.description ?? '');
    const [base, setBase] = useState(setup?.base_template ?? '');
    const [answers, setAnswers] = useState<Record<number, string>>({});
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const [includePlan, setIncludePlan] = useState(true);
    const [rules, setRules] = useState<string[]>([]);

    // Poll while the model works (the request runs on the queue).
    useEffect(() => {
        if (setup?.status !== 'pending') {
            return;
        }
        const timer = window.setInterval(
            () => router.reload({ only: ['setup', 'history'] }),
            4000,
        );

        return () => window.clearInterval(timer);
    }, [setup?.status, setup?.id]);

    useEffect(() => {
        setRules(proposal?.automation.map((r) => r.key) ?? []);
        setIncludePlan(true);
        setAnswers({});
    }, [setup?.id, proposal?.automation]);

    const propose = (withAnswers: boolean) => {
        setBusy(true);
        const questions = proposal?.questions ?? [];
        router.post(
            AiSetupController.store.url(),
            {
                description,
                base_template: base === '' ? null : base,
                answers: withAnswers
                    ? [
                          ...(setup?.answers ?? []),
                          ...questions
                              .map((q, i) => ({
                                  question: q.question,
                                  answer: (answers[i] ?? '').trim(),
                              }))
                              .filter((a) => a.answer !== ''),
                      ]
                    : [],
            },
            {
                preserveScroll: true,
                onError: (e) => setErrors(e),
                onSuccess: () => setErrors({}),
                onFinish: () => setBusy(false),
            },
        );
    };

    const apply = () => {
        if (!setup) {
            return;
        }
        setBusy(true);
        router.post(
            AiSetupController.apply.url(setup.id),
            {
                include_plan: includePlan,
                rules,
                lock_version: flow.lock_version,
            },
            {
                preserveScroll: true,
                onError: (e) => setErrors(e),
                onFinish: () => setBusy(false),
            },
        );
    };

    const reject = () => {
        if (!setup) {
            return;
        }
        setBusy(true);
        router.post(
            AiSetupController.reject.url(setup.id),
            {},
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    const templateLabel = (key: string) =>
        templates.find((tpl) => tpl.key === key)?.label ?? key;

    const checks = proposal?.checks;
    const configErrors = Object.entries(checks?.config_errors ?? {});
    const applyErrors = Object.entries(errors).filter(
        ([k]) => k !== 'description',
    );

    return (
        <>
            <Head title={t('ai_setup.title')} />
            <PageHeader
                title={t('ai_setup.title')}
                description={t('ai_setup.description')}
            />

            <div className="max-w-3xl space-y-10">
                {!available && (
                    <Alert variant="warning" data-test="ai-setup-unavailable">
                        <AlertBody>
                            <AlertDescription>
                                {t('ai_setup.unavailable')}
                            </AlertDescription>
                        </AlertBody>
                    </Alert>
                )}

                <Alert variant="info">
                    <AlertBody>
                        <AlertDescription>
                            {t('ai_setup.drafts_only')}
                        </AlertDescription>
                    </AlertBody>
                </Alert>

                {/* 1. The description */}
                <section className="space-y-4" data-test="ai-setup-describe">
                    <h2 className="text-subtitle text-strong">
                        {t('ai_setup.step.describe')}
                    </h2>
                    <TextareaField
                        label={t('ai_setup.field.description')}
                        hint={t('ai_setup.field.description_hint')}
                        value={description}
                        onChange={(e) => setDescription(e.target.value)}
                        error={errors.description}
                        rows={7}
                        maxLength={6000}
                        required
                    />
                    <SelectField
                        label={t('ai_setup.field.base_template')}
                        hint={t('ai_setup.field.base_template_hint')}
                        value={base}
                        onChange={(e) => setBase(e.target.value)}
                        placeholder={t('ai_setup.field.base_template_auto')}
                        options={templates.map((tpl) => ({
                            value: tpl.key,
                            label: tpl.label,
                        }))}
                        optional
                    />
                    <Button
                        onClick={() => propose(false)}
                        disabled={
                            !available ||
                            busy ||
                            description.trim().length < 20 ||
                            setup?.status === 'pending'
                        }
                    >
                        {t('ai_setup.propose')}
                    </Button>
                </section>

                {setup && (
                    <section
                        className="space-y-6 border-t border-stroke-weak pt-8"
                        data-test="ai-setup-result"
                    >
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="text-subtitle text-strong">
                                {t('ai_setup.step.result')}
                            </h2>
                            <Badge variant={STATUS_VARIANT[setup.status]}>
                                {t(
                                    `ai_setup.status.${setup.status}` as TranslationKey,
                                )}
                            </Badge>
                        </div>

                        {setup.status === 'pending' && (
                            <p className="text-body text-weak">
                                {t('ai_setup.pending')}
                            </p>
                        )}

                        {setup.status === 'failed' && (
                            <Alert variant="danger">
                                <AlertBody>
                                    <AlertDescription>
                                        {t('ai_setup.failed')}
                                        {setup.outcome && (
                                            <span dir="ltr">
                                                {' '}
                                                ({setup.outcome})
                                            </span>
                                        )}
                                    </AlertDescription>
                                </AlertBody>
                            </Alert>
                        )}

                        {setup.status === 'applied' && (
                            <Alert variant="success" data-test="ai-setup-applied">
                                <AlertBody>
                                    <AlertDescription>
                                        {t('ai_setup.applied_note')}{' '}
                                        <Link
                                            href={flowEdit().url}
                                            className="underline"
                                        >
                                            {t('ai_setup.open_flow')}
                                        </Link>
                                        {' · '}
                                        <Link
                                            href={automation().url}
                                            className="underline"
                                        >
                                            {t('ai_setup.open_rules')}
                                        </Link>
                                        {' · '}
                                        <Link
                                            href={releases().url}
                                            className="underline"
                                        >
                                            {t('ai_setup.open_releases')}
                                        </Link>
                                    </AlertDescription>
                                </AlertBody>
                            </Alert>
                        )}

                        {proposal && (
                            <>
                                <div className="space-y-2">
                                    <p className="text-caption text-weak">
                                        {t('ai_setup.base', {
                                            template: templateLabel(
                                                proposal.base_template,
                                            ),
                                        })}
                                    </p>
                                    <p className="text-body whitespace-pre-line text-strong">
                                        {proposal.summary}
                                    </p>
                                </div>

                                {/* 2. Questions */}
                                {proposal.questions.length > 0 && (
                                    <div
                                        className="space-y-4"
                                        data-test="ai-setup-questions"
                                    >
                                        <h3 className="text-body-strong text-strong">
                                            {t('ai_setup.step.questions')}
                                        </h3>
                                        {proposal.questions.map((q, i) => (
                                            <TextareaField
                                                key={i}
                                                label={q.question}
                                                hint={q.about ?? undefined}
                                                rows={2}
                                                maxLength={1000}
                                                value={answers[i] ?? ''}
                                                onChange={(e) =>
                                                    setAnswers({
                                                        ...answers,
                                                        [i]: e.target.value,
                                                    })
                                                }
                                                optional
                                            />
                                        ))}
                                        <Button
                                            variant="outline"
                                            onClick={() => propose(true)}
                                            disabled={
                                                !available ||
                                                busy ||
                                                Object.values(answers).every(
                                                    (a) => a.trim() === '',
                                                )
                                            }
                                        >
                                            {t('ai_setup.repropose')}
                                        </Button>
                                    </div>
                                )}

                                {/* 3. Checks and differences */}
                                <div className="space-y-3" data-test="ai-setup-checks">
                                    <h3 className="text-body-strong text-strong">
                                        {t('ai_setup.step.checks')}
                                    </h3>
                                    {checks?.passes ? (
                                        <Badge variant="success">
                                            {t('ai_setup.checks.pass')}
                                        </Badge>
                                    ) : (
                                        <Alert variant="warning">
                                            <AlertBody>
                                                <AlertDescription>
                                                    {t('ai_setup.checks.fail')}
                                                    <ul className="mt-2 list-disc ps-5">
                                                        {checks?.flow_issues.map(
                                                            (issue, i) => (
                                                                <li key={`f${i}`}>
                                                                    {issue.message}
                                                                </li>
                                                            ),
                                                        )}
                                                        {configErrors.map(
                                                            ([path, message]) => (
                                                                <li key={path}>
                                                                    {message}
                                                                </li>
                                                            ),
                                                        )}
                                                    </ul>
                                                </AlertDescription>
                                            </AlertBody>
                                        </Alert>
                                    )}
                                </div>

                                <div className="space-y-3">
                                    <h3 className="text-body-strong text-strong">
                                        {t('ai_setup.flow_changes')}
                                    </h3>
                                    <FlowDiff stages={proposal.diff.flow} />
                                </div>

                                {proposal.payment_plan && (
                                    <div className="space-y-3">
                                        <h3 className="text-body-strong text-strong">
                                            {t('ai_setup.plan')}
                                        </h3>
                                        <PlanLines plan={proposal.payment_plan} />
                                        <CheckboxField
                                            label={t('ai_setup.include_plan')}
                                            checked={includePlan}
                                            onChange={(e) =>
                                                setIncludePlan(e.target.checked)
                                            }
                                        />
                                    </div>
                                )}

                                {proposal.automation.length > 0 && (
                                    <div className="space-y-3">
                                        <h3 className="text-body-strong text-strong">
                                            {t('ai_setup.rules')}
                                        </h3>
                                        <p className="text-caption text-weak">
                                            {t('ai_setup.rules_off')}
                                        </p>
                                        {proposal.automation.map((rule) => (
                                            <CheckboxField
                                                key={rule.key}
                                                label={rule.name}
                                                hint={rule.why || undefined}
                                                checked={rules.includes(rule.key)}
                                                onChange={(e) =>
                                                    setRules(
                                                        e.target.checked
                                                            ? [...rules, rule.key]
                                                            : rules.filter(
                                                                  (k) =>
                                                                      k !==
                                                                      rule.key,
                                                              ),
                                                    )
                                                }
                                            />
                                        ))}
                                    </div>
                                )}

                                <div className="space-y-3">
                                    <h3 className="text-body-strong text-strong">
                                        {t('ai_setup.config_changes')}
                                    </h3>
                                    <ReleaseChanges
                                        changes={proposal.diff.config}
                                        empty={t('ai_setup.no_config_changes')}
                                    />
                                </div>

                                {/* 4. Apply as drafts */}
                                <div
                                    className="space-y-3 border-t border-stroke-weak pt-6"
                                    data-test="ai-setup-apply"
                                >
                                    {flow.has_draft && (
                                        <Alert variant="warning">
                                            <AlertBody>
                                                <AlertDescription>
                                                    {t('ai_setup.replaces_draft')}
                                                </AlertDescription>
                                            </AlertBody>
                                        </Alert>
                                    )}
                                    {applyErrors.length > 0 && (
                                        <Alert variant="danger">
                                            <AlertBody>
                                                <AlertDescription>
                                                    <ul className="list-disc ps-5">
                                                        {applyErrors.map(
                                                            ([k, m]) => (
                                                                <li key={k}>{m}</li>
                                                            ),
                                                        )}
                                                    </ul>
                                                </AlertDescription>
                                            </AlertBody>
                                        </Alert>
                                    )}
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            onClick={apply}
                                            disabled={busy || !checks?.passes}
                                        >
                                            {t('ai_setup.apply')}
                                        </Button>
                                        <Button
                                            variant="outline"
                                            onClick={reject}
                                            disabled={busy}
                                        >
                                            {t('ai_setup.reject')}
                                        </Button>
                                    </div>
                                    <p className="text-caption text-weak">
                                        {t('ai_setup.apply_note')}
                                    </p>
                                </div>
                            </>
                        )}
                    </section>
                )}

                {history.length > 1 && (
                    <section className="space-y-3 border-t border-stroke-weak pt-8">
                        <h2 className="text-subtitle text-strong">
                            {t('ai_setup.history')}
                        </h2>
                        <ul className="space-y-1 text-body">
                            {history.map((h) => (
                                <li
                                    key={h.id}
                                    className="flex items-center gap-2"
                                >
                                    <span className="text-weak">
                                        {formatDateTime(h.created_at, locale)}
                                    </span>
                                    <Badge variant={STATUS_VARIANT[h.status]}>
                                        {t(
                                            `ai_setup.status.${h.status}` as TranslationKey,
                                        )}
                                    </Badge>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}
