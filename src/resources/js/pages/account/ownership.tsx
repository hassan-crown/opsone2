import { Form, Head, usePage } from '@inertiajs/react';
import OwnershipTransferController from '@/actions/App/Http/Controllers/Account/OwnershipTransferController';
import PasswordInput from '@/components/password-input';
import { Field, SelectField, TextareaField } from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import {
    Alert,
    AlertBody,
    AlertDescription,
    AlertTitle,
} from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { home } from '@/routes/settings';
import { show } from '@/routes/ownership';

type Person = { name: string; email: string };

type Props = {
    owner: Person | null;
    isOwner: boolean;
    open: {
        id: number;
        role: 'requester' | 'recipient';
        from: Person;
        to: Person;
        reason: string | null;
        expires_at: string;
        created_at: string | null;
    } | null;
    candidates: {
        id: number;
        name: string;
        email: string;
        job_title: string | null;
    }[];
    history: {
        id: number;
        from: string;
        to: string;
        status: 'accepted' | 'rejected' | 'cancelled' | 'expired';
        at: string;
    }[];
};

const STATUS_BADGE = {
    accepted: 'success',
    rejected: 'danger',
    cancelled: 'neutral',
    expired: 'warning',
} as const;

function DecisionDialog({
    kind,
    transferId,
    destructive,
}: {
    kind: 'accept' | 'reject' | 'cancel';
    transferId: number;
    destructive?: boolean;
}) {
    const t = useT();
    const form =
        kind === 'accept'
            ? OwnershipTransferController.accept.form(transferId)
            : kind === 'reject'
              ? OwnershipTransferController.reject.form(transferId)
              : OwnershipTransferController.cancel.form(transferId);

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button
                    variant={
                        kind === 'accept'
                            ? 'default'
                            : destructive
                              ? 'destructive'
                              : 'secondary'
                    }
                    data-test={`ownership-${kind}`}
                >
                    {t(`account.ownership.${kind}`)}
                </Button>
            </DialogTrigger>
            <DialogContent size="sm">
                <Form
                    {...form}
                    options={{ preserveScroll: true }}
                    resetOnError={['current_password']}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    {t(`account.ownership.${kind}_title`)}
                                </DialogTitle>
                                <DialogDescription>
                                    {t(`account.ownership.${kind}_text`)}
                                </DialogDescription>
                            </DialogHeader>
                            {kind === 'accept' && (
                                <Field
                                    label={t('account.ownership.password')}
                                    hint={t('account.ownership.password_hint')}
                                    required
                                    error={errors.current_password}
                                >
                                    {(control) => (
                                        <PasswordInput
                                            {...control}
                                            name="current_password"
                                            autoComplete="current-password"
                                        />
                                    )}
                                </Field>
                            )}
                            {kind === 'reject' && (
                                <TextareaField
                                    label={t('account.ownership.reason')}
                                    name="reason"
                                    rows={3}
                                    maxLength={500}
                                    optional
                                    error={errors.reason}
                                />
                            )}
                            <DialogFooter className="gap-2 sm:space-x-0">
                                <DialogClose asChild>
                                    <Button type="button" variant="secondary">
                                        {t('ui.cancel')}
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant={
                                        kind === 'accept'
                                            ? 'default'
                                            : 'destructive'
                                    }
                                    loading={processing}
                                >
                                    {processing && <Spinner />}
                                    {t('ui.confirm')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export default function Ownership({
    owner,
    isOwner,
    open,
    candidates,
    history,
}: Props) {
    const t = useT();
    const { locale } = useLocale();
    const { errors } = usePage().props as { errors: Record<string, string> };

    return (
        <>
            <Head title={t('account.ownership.title')} />

            <PageHeader
                title={t('account.ownership.title')}
                description={t('account.ownership.description')}
            />

            <div className="max-w-2xl space-y-6">
                {errors.transfer && (
                    <Alert variant="danger">
                        <AlertBody>
                            <AlertDescription>
                                {errors.transfer}
                            </AlertDescription>
                        </AlertBody>
                    </Alert>
                )}

                {owner && (
                    <Card>
                        <CardContent className="space-y-1">
                            <p className="text-label text-weak">
                                {t('account.ownership.current_owner')}
                            </p>
                            <p className="text-subtitle text-strong">
                                {owner.name}
                            </p>
                            <p className="text-body text-weak" dir="ltr">
                                {owner.email}
                            </p>
                        </CardContent>
                    </Card>
                )}

                {open && (
                    <Card data-test="open-transfer">
                        <CardHeader>
                            <CardTitle>
                                {open.role === 'requester'
                                    ? t(
                                          'account.ownership.open_title_requester',
                                          { name: open.to.name },
                                      )
                                    : t(
                                          'account.ownership.open_title_recipient',
                                      )}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <p className="text-body text-strong">
                                {open.role === 'requester'
                                    ? t('account.ownership.open_requester', {
                                          name: open.to.name,
                                      })
                                    : t('account.ownership.open_recipient', {
                                          name: open.from.name,
                                      })}
                            </p>
                            {open.reason && (
                                <p className="text-body text-weak">
                                    {open.reason}
                                </p>
                            )}
                            <p className="text-caption text-weak">
                                {t('account.ownership.expires', {
                                    date: formatDateTime(
                                        open.expires_at,
                                        locale,
                                    ),
                                })}
                            </p>
                            <div className="flex flex-wrap gap-2">
                                {open.role === 'recipient' ? (
                                    <>
                                        <DecisionDialog
                                            kind="accept"
                                            transferId={open.id}
                                        />
                                        <DecisionDialog
                                            kind="reject"
                                            transferId={open.id}
                                            destructive
                                        />
                                    </>
                                ) : (
                                    <DecisionDialog
                                        kind="cancel"
                                        transferId={open.id}
                                        destructive
                                    />
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {isOwner && !open && (
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t('account.ownership.request_title')}
                            </CardTitle>
                            <p className="text-body text-weak">
                                {t('account.ownership.request_description')}
                            </p>
                        </CardHeader>
                        <CardContent>
                            {candidates.length === 0 ? (
                                <p className="text-body text-weak">
                                    {t('account.ownership.no_candidates')}
                                </p>
                            ) : (
                                <Form
                                    {...OwnershipTransferController.store.form()}
                                    options={{ preserveScroll: true }}
                                    resetOnSuccess
                                    resetOnError={['current_password']}
                                    className="grid gap-6"
                                >
                                    {({ processing, errors: formErrors }) => (
                                        <>
                                            <SelectField
                                                label={t(
                                                    'account.ownership.recipient',
                                                )}
                                                name="recipient_id"
                                                required
                                                placeholder={t(
                                                    'account.ownership.recipient_placeholder',
                                                )}
                                                options={candidates.map(
                                                    (candidate) => ({
                                                        value: String(
                                                            candidate.id,
                                                        ),
                                                        label: candidate.job_title
                                                            ? `${candidate.name} · ${candidate.job_title}`
                                                            : candidate.name,
                                                    }),
                                                )}
                                                error={formErrors.recipient_id}
                                            />
                                            <TextareaField
                                                label={t(
                                                    'account.ownership.reason',
                                                )}
                                                name="reason"
                                                rows={3}
                                                maxLength={500}
                                                optional
                                                error={formErrors.reason}
                                            />
                                            <Field
                                                label={t(
                                                    'account.ownership.password',
                                                )}
                                                hint={t(
                                                    'account.ownership.password_hint',
                                                )}
                                                required
                                                error={
                                                    formErrors.current_password
                                                }
                                            >
                                                {(control) => (
                                                    <PasswordInput
                                                        {...control}
                                                        name="current_password"
                                                        autoComplete="current-password"
                                                    />
                                                )}
                                            </Field>
                                            <Alert variant="warning">
                                                <AlertBody>
                                                    <AlertTitle>
                                                        {t(
                                                            'account.ownership.request_title',
                                                        )}
                                                    </AlertTitle>
                                                    <AlertDescription>
                                                        {t(
                                                            'account.ownership.warning',
                                                        )}
                                                    </AlertDescription>
                                                </AlertBody>
                                            </Alert>
                                            <div>
                                                <Button
                                                    type="submit"
                                                    loading={processing}
                                                    data-test="ownership-request"
                                                >
                                                    {processing && <Spinner />}
                                                    {t(
                                                        'account.ownership.submit',
                                                    )}
                                                </Button>
                                            </div>
                                        </>
                                    )}
                                </Form>
                            )}
                        </CardContent>
                    </Card>
                )}

                {history.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t('account.ownership.history')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul className="divide-y divide-stroke-weak">
                                {history.map((row) => (
                                    <li
                                        key={row.id}
                                        className="flex flex-wrap items-center justify-between gap-2 py-3 first:pt-0 last:pb-0"
                                    >
                                        <span className="min-w-0">
                                            <span className="block text-body text-strong">
                                                {t(
                                                    'account.ownership.history_row',
                                                    {
                                                        from: row.from,
                                                        to: row.to,
                                                    },
                                                )}
                                            </span>
                                            <span className="block text-caption text-weak">
                                                {formatDateTime(row.at, locale)}
                                            </span>
                                        </span>
                                        <Badge
                                            variant={STATUS_BADGE[row.status]}
                                        >
                                            {t(
                                                `account.ownership.status.${row.status}`,
                                            )}
                                        </Badge>
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

Ownership.layout = {
    breadcrumbs: [
        { title: 'account.nav.home', href: home() },
        { title: 'account.ownership.title', href: show() },
    ],
};
