import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import AgreementController from '@/actions/App/Http/Controllers/Agreements/AgreementController';
import DiscountDecisionController from '@/actions/App/Http/Controllers/Agreements/DiscountDecisionController';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import { bpToPercent, newKey, toMajor } from '@/components/agreement/money';
import { useAgreementCall } from '@/components/agreement/use-agreement-call';
import { Amount } from '@/components/opsone/amount';
import { TextareaField } from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { notify } from '@/components/opsone/toast';
import { Alert, AlertBody, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type Decision = {
    id: number;
    status: string;
    lock_version: number;
    order: { id: number; number: string; customer: string };
    subject: { type: string; id: number; number: string | null };
    requester: string;
    requested_at: string;
    reason: string;
    limit_bp: number;
    share_bp: number;
    base_minor: number;
    discount_minor: number;
    over_minor: number;
    total_minor: number | null;
    vat_minor: number | null;
    content_current: boolean;
    decider: string | null;
    decided_at: string | null;
    decision_reason: string | null;
    can: { decide: boolean; cancel: boolean };
    own_request: boolean;
    above_my_limit: boolean;
};

function Fact({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-wrap justify-between gap-2 border-b border-stroke-weak py-2 last:border-0">
            <dt className="text-weak">{label}</dt>
            <dd className="font-semibold text-strong">{children}</dd>
        </div>
    );
}

/**
 * AgrDiscountDecision / AgrDecisionMobile (M-26, AGR-02): the supervisor sees this request's base, discount and total
 * only (no P-37 needed), approves or rejects with a reason. The requester never decides their own request; approval
 * does not send the quote.
 */
export default function DiscountDecisionPage({
    decision: initial,
    canOpenOrder,
}: {
    decision: Decision;
    canOpenOrder: boolean;
}) {
    const t = useT();
    const { locale } = useLocale();
    const [decision, setDecision] = useState(initial);
    const [fromProps, setFromProps] = useState(initial);
    if (fromProps !== initial) {
        setFromProps(initial);
        setDecision(initial);
    }
    const [reason, setReason] = useState('');
    const [key] = useState(newKey);
    const call = useAgreementCall<{ decision: Decision }>();

    const decide = async (approve: boolean) => {
        const url = approve
            ? DiscountDecisionController.approve.url(decision.id)
            : DiscountDecisionController.reject.url(decision.id);
        const data = await call.run('POST', url, {
            idempotency_key: key,
            lock_version: decision.lock_version,
            reason: reason === '' ? null : reason,
        });
        if (data) {
            setDecision(data.decision);
            notify('success', {
                title: approve
                    ? t('agreements.decision_page.approved')
                    : t('agreements.decision_page.rejected'),
            });
        }
    };

    const cancel = async () => {
        const data = await call.run(
            'POST',
            DiscountDecisionController.cancel.url(decision.id),
            {
                lock_version: decision.lock_version,
            },
        );
        if (data) {
            setDecision(data.decision);
        }
    };

    return (
        <>
            <Head title={t('agreements.decision_page.title')} />
            <PageHeader
                title={t('agreements.decision_page.heading', {
                    percent: bpToPercent(decision.share_bp),
                    number: decision.subject.number ?? '',
                })}
                description={t('agreements.decision_page.requested_by', {
                    name: decision.requester,
                    date: formatDateTime(decision.requested_at, locale),
                    order: decision.order.number,
                    customer: decision.order.customer,
                })}
                actions={
                    canOpenOrder ? (
                        <Button asChild variant="secondary">
                            <Link
                                href={AgreementController.show.url(
                                    decision.order.id,
                                )}
                            >
                                {t('agreements.decision_page.open_order')}
                            </Link>
                        </Button>
                    ) : undefined
                }
            />

            <div className="grid max-w-3xl gap-6" data-test="discount-decision">
                <Card>
                    <CardHeader>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <CardTitle>
                                {t('agreements.decision_page.request')}
                            </CardTitle>
                            <Badge
                                variant={
                                    decision.status === 'approved'
                                        ? 'success'
                                        : decision.status === 'requested'
                                          ? 'warning'
                                          : 'neutral'
                                }
                            >
                                {t(
                                    `agreements.decision_status.${decision.status}` as TranslationKey,
                                )}
                            </Badge>
                        </div>
                    </CardHeader>
                    <dl>
                        <Fact
                            label={t('agreements.decision_page.limit', {
                                name: decision.requester,
                            })}
                        >
                            <bdi dir="ltr">
                                {bpToPercent(decision.limit_bp)}%
                            </bdi>
                        </Fact>
                        <Fact label={t('agreements.decision_page.requested')}>
                            <bdi dir="ltr">
                                {bpToPercent(decision.share_bp)}%
                            </bdi>
                        </Fact>
                        <Fact label={t('agreements.decision_page.base')}>
                            <Amount value={toMajor(decision.base_minor)} />
                        </Fact>
                        <Fact label={t('agreements.decision_page.discount')}>
                            <Amount value={toMajor(decision.discount_minor)} />
                        </Fact>
                        <Fact label={t('agreements.decision_page.over')}>
                            <Amount value={toMajor(decision.over_minor)} />
                        </Fact>
                        {decision.total_minor !== null && (
                            <Fact label={t('agreements.decision_page.total')}>
                                <Amount
                                    value={toMajor(decision.total_minor)}
                                    strong
                                />
                            </Fact>
                        )}
                    </dl>
                    <div>
                        <h3 className="mb-1 font-semibold text-strong">
                            {t('agreements.decision_page.reason_of', {
                                name: decision.requester,
                            })}
                        </h3>
                        <p className="text-body text-strong">
                            {decision.reason}
                        </p>
                    </div>
                </Card>

                {!decision.content_current &&
                    decision.status === 'requested' && (
                        <Alert variant="warning">
                            <AlertBody>
                                {t('agreements.decision_page.content_changed')}
                            </AlertBody>
                        </Alert>
                    )}

                {decision.status === 'requested' ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t('agreements.decision_page.impact')}
                            </CardTitle>
                        </CardHeader>
                        <ul className="grid list-disc gap-1 ps-5 text-body text-weak">
                            <li>
                                {t('agreements.decision_page.note_content')}
                            </li>
                            <li>{t('agreements.decision_page.note_send')}</li>
                        </ul>
                        {decision.own_request && (
                            <Alert variant="info">
                                <AlertBody>
                                    {t('agreements.decision_page.own')}
                                </AlertBody>
                            </Alert>
                        )}
                        {!decision.own_request && decision.above_my_limit && (
                            <Alert variant="warning">
                                <AlertBody>
                                    {t('agreements.decision_page.above_mine')}
                                </AlertBody>
                            </Alert>
                        )}
                        {decision.can.decide && (
                            <TextareaField
                                label={t(
                                    'agreements.decision_page.decision_reason',
                                )}
                                hint={t(
                                    'agreements.decision_page.decision_reason_hint',
                                )}
                                value={reason}
                                error={call.fieldError('reason')}
                                onChange={(e) => setReason(e.target.value)}
                            />
                        )}
                        {call.error && (
                            <Alert variant="danger" role="alert">
                                <AlertBody>{call.error}</AlertBody>
                            </Alert>
                        )}
                        <div className="flex flex-wrap gap-2">
                            {decision.can.decide && (
                                <>
                                    <Button
                                        type="button"
                                        variant="destructive"
                                        onClick={() => decide(false)}
                                        loading={call.busy}
                                        data-test="reject-discount"
                                    >
                                        {t('agreements.decision_page.reject')}
                                    </Button>
                                    <Button
                                        type="button"
                                        onClick={() => decide(true)}
                                        loading={call.busy}
                                        data-test="approve-discount"
                                    >
                                        {t('agreements.decision_page.approve')}
                                    </Button>
                                </>
                            )}
                            {decision.can.cancel && (
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={cancel}
                                    loading={call.busy}
                                >
                                    {t('agreements.decision_page.withdraw')}
                                </Button>
                            )}
                        </div>
                    </Card>
                ) : (
                    decision.decider && (
                        <Alert
                            variant={
                                decision.status === 'approved'
                                    ? 'success'
                                    : 'info'
                            }
                        >
                            <AlertBody>
                                <AlertTitle>
                                    {t('agreements.decision_page.decided', {
                                        name: decision.decider,
                                        date: decision.decided_at
                                            ? formatDateTime(
                                                  decision.decided_at,
                                                  locale,
                                              )
                                            : '',
                                    })}
                                </AlertTitle>
                                {decision.decision_reason && (
                                    <p>{decision.decision_reason}</p>
                                )}
                            </AlertBody>
                        </Alert>
                    )
                )}
            </div>
        </>
    );
}

DiscountDecisionPage.layout = {
    breadcrumbs: [{ title: 'orders.nav', href: OrderController.index() }],
};
