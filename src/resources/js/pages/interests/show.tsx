import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useState } from 'react';
import CustomerController from '@/actions/App/Http/Controllers/Customers/CustomerController';
import InterestController from '@/actions/App/Http/Controllers/Customers/InterestController';
import type {
    InterestCan,
    InterestRow,
    InterestStatus,
    Option,
} from '@/components/customers/types';
import { SelectField, TextareaField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type Props = {
    interest: InterestRow;
    customer: { id: number; name: string } | null;
    can: InterestCan;
    members: Option[];
};

const TONE: Record<InterestStatus, 'info' | 'neutral' | 'success'> = {
    new: 'info',
    following: 'neutral',
    converted: 'success',
    closed: 'neutral',
};

const REASONS = [
    'price',
    'competitor',
    'no_reply',
    'later',
    'duplicate',
    'other',
] as const;

/** Interest detail with the M-03 actions: follow, convert (once), close with a reason, resume. */
export default function InterestShow({
    interest,
    customer,
    can,
    members,
}: Props) {
    const t = useT();
    const { locale } = useLocale();
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [dialog, setDialog] = useState<'convert' | 'close' | 'reopen' | null>(
        null,
    );
    const [busy, setBusy] = useState(false);
    const close = () => setDialog(null);

    return (
        <>
            <Head title={interest.reference} />
            <PageHeader
                title={`${interest.reference} — ${interest.name}`}
                description={
                    interest.created_at
                        ? `${t('interests.detail.recorded')}: ${formatDateTime(interest.created_at, locale)}`
                        : undefined
                }
                actions={
                    <>
                        {can.edit && (
                            <Button asChild variant="secondary">
                                <Link
                                    href={InterestController.edit(interest.id)}
                                >
                                    <Icon icon={Pencil} />
                                    {t('interests.action.edit')}
                                </Link>
                            </Button>
                        )}
                        {can.follow && (
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() =>
                                    router.post(
                                        InterestController.follow.url(
                                            interest.id,
                                        ),
                                    )
                                }
                                data-test="follow"
                            >
                                {t('interests.action.follow')}
                            </Button>
                        )}
                        {can.close && (
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setDialog('close')}
                                data-test="close"
                            >
                                {t('interests.action.close')}
                            </Button>
                        )}
                        {can.reopen && (
                            <Button
                                type="button"
                                onClick={() => setDialog('reopen')}
                                data-test="reopen"
                            >
                                {t('interests.action.reopen')}
                            </Button>
                        )}
                        {can.convert && (
                            <Button
                                type="button"
                                onClick={() => setDialog('convert')}
                                data-test="convert"
                            >
                                {t('interests.action.convert')}
                            </Button>
                        )}
                    </>
                }
            />
            {(errors.interest || errors.transition) && (
                <Alert
                    variant="danger"
                    className="mb-4"
                    data-test="interest-error"
                >
                    <AlertBody>
                        {errors.interest ?? errors.transition}
                    </AlertBody>
                </Alert>
            )}
            <Card>
                <div className="flex flex-wrap items-center gap-2">
                    <Badge variant={TONE[interest.status]}>
                        {t(
                            `interests.status.${interest.status}` as TranslationKey,
                        )}
                    </Badge>
                    <span className="text-weak">
                        {t(
                            `interests.channel.${interest.channel}` as TranslationKey,
                        )}
                    </span>
                    {interest.branch && (
                        <span className="text-weak">· {interest.branch}</span>
                    )}
                </div>
                <p className="whitespace-pre-line text-strong">
                    {interest.need}
                </p>
                <dl className="grid gap-2 sm:grid-cols-2">
                    <div>
                        <dt className="text-caption text-weak">
                            {t('interests.form.mobile')}
                        </dt>
                        <dd dir="ltr" className="text-start">
                            {interest.mobile ?? '—'}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-caption text-weak">
                            {t('interests.form.email')}
                        </dt>
                        <dd dir="ltr" className="text-start">
                            {interest.email ?? '—'}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-caption text-weak">
                            {t('interests.col.assignee')}
                        </dt>
                        <dd>{interest.assignee ?? t('interests.no_owner')}</dd>
                    </div>
                    {customer && (
                        <div>
                            <dt className="text-caption text-weak">
                                {t('interests.detail.customer')}
                            </dt>
                            <dd>
                                <Link
                                    href={CustomerController.show(customer.id)}
                                    className="text-link hover:underline"
                                >
                                    {customer.name}
                                </Link>
                            </dd>
                        </div>
                    )}
                    {interest.close_reason && (
                        <div>
                            <dt className="text-caption text-weak">
                                {t('interests.detail.close_reason')}
                            </dt>
                            <dd>
                                {t(
                                    `interests.reason.${interest.close_reason}` as TranslationKey,
                                )}
                                {interest.close_note
                                    ? ` — ${interest.close_note}`
                                    : ''}
                            </dd>
                        </div>
                    )}
                </dl>
            </Card>

            <Dialog
                open={dialog === 'convert'}
                onOpenChange={(o) => !o && close()}
            >
                <DialogContent size="md" aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>
                            {t('interests.dialog.convert_title', {
                                reference: interest.reference,
                            })}
                        </DialogTitle>
                        <DialogDescription>
                            {t('interests.detail.convert_note')}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            {t('interests.dialog.cancel')}
                        </Button>
                        <Button
                            type="button"
                            loading={busy}
                            data-test="convert-confirm"
                            onClick={() =>
                                router.post(
                                    InterestController.convert.url(interest.id),
                                    {},
                                    {
                                        onStart: () => setBusy(true),
                                        onFinish: () => {
                                            setBusy(false);
                                            close();
                                        },
                                    },
                                )
                            }
                        >
                            {t('interests.dialog.convert_confirm')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={dialog === 'close'}
                onOpenChange={(o) => !o && close()}
            >
                <DialogContent size="md" aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>
                            {t('interests.dialog.close_title', {
                                reference: interest.reference,
                            })}
                        </DialogTitle>
                        <DialogDescription>
                            {t('interests.dialog.close_desc')}
                        </DialogDescription>
                    </DialogHeader>
                    <Form
                        {...InterestController.close.form(interest.id)}
                        className="grid gap-4"
                        onSuccess={close}
                    >
                        {({ errors: e, processing }) => (
                            <>
                                <SelectField
                                    label={t('interests.dialog.close_reason')}
                                    name="reason"
                                    defaultValue="price"
                                    options={REASONS.map((r) => ({
                                        value: r,
                                        label: t(
                                            `interests.reason.${r}` as TranslationKey,
                                        ),
                                    }))}
                                    error={e.reason}
                                    required
                                />
                                <TextareaField
                                    label={t('interests.dialog.close_note')}
                                    name="note"
                                    error={e.note}
                                    optional
                                />
                                <DialogFooter>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={close}
                                    >
                                        {t('interests.dialog.cancel')}
                                    </Button>
                                    <Button
                                        variant="destructive"
                                        loading={processing}
                                        data-test="close-confirm"
                                    >
                                        {t('interests.dialog.close_confirm')}
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={dialog === 'reopen'}
                onOpenChange={(o) => !o && close()}
            >
                <DialogContent size="md" aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>
                            {t('interests.dialog.reopen_title', {
                                reference: interest.reference,
                            })}
                        </DialogTitle>
                        <DialogDescription className="sr-only">
                            {t('interests.description')}
                        </DialogDescription>
                    </DialogHeader>
                    <Form
                        {...InterestController.reopen.form(interest.id)}
                        className="grid gap-4"
                        onSuccess={close}
                    >
                        {({ errors: e, processing }) => (
                            <>
                                <TextareaField
                                    label={t('interests.dialog.reopen_reason')}
                                    name="reason"
                                    error={e.reason}
                                    required
                                />
                                <SelectField
                                    label={t(
                                        'interests.dialog.reopen_assignee',
                                    )}
                                    name="assignee_id"
                                    defaultValue=""
                                    options={[
                                        {
                                            value: '',
                                            label: t(
                                                'interests.form.no_assignee',
                                            ),
                                        },
                                        ...members.map((m) => ({
                                            value: String(m.id),
                                            label: m.name,
                                        })),
                                    ]}
                                    error={e.assignee_id}
                                    optional
                                />
                                <DialogFooter>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={close}
                                    >
                                        {t('interests.dialog.cancel')}
                                    </Button>
                                    <Button
                                        loading={processing}
                                        data-test="reopen-confirm"
                                    >
                                        {t('interests.dialog.reopen_confirm')}
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </>
    );
}

InterestShow.layout = {
    breadcrumbs: [{ title: 'interests.nav', href: InterestController.index() }],
};
