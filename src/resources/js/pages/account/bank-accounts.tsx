import { Form, Head, router } from '@inertiajs/react';
import { Landmark, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import BankAccountController from '@/actions/App/Http/Controllers/Account/BankAccountController';
import { BankMissingAlert } from '@/components/bank/bank-missing-alert';
import {
    CheckboxField,
    SelectField,
    TextField,
    TextareaField,
} from '@/components/opsone/field';
import { EmptyState } from '@/components/opsone/empty-state';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Alert, AlertBody, AlertDescription } from '@/components/ui/alert';
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
import { useT } from '@/lib/i18n';
import { bank, home } from '@/routes/settings';

type Account = {
    id: number;
    bank_name: string;
    beneficiary_name: string;
    iban: string;
    iban_grouped: string;
    account_number: string | null;
    swift_code: string | null;
    currency: string;
    transfer_note: string | null;
    is_default: boolean;
    is_active: boolean;
};

type Props = {
    accounts: Account[];
    currencies: string[];
    defaultCurrency: string;
    canEdit: boolean;
};

/**
 * Bank accounts for customer transfers (ADR-0046). The active default account is what customers see on the pay page,
 * documents and due reminders; without an active account nothing is shown to them.
 */
export default function BankAccounts({
    accounts,
    currencies,
    defaultCurrency,
    canEdit,
}: Props) {
    const t = useT();
    const [editing, setEditing] = useState<Account | null>(null);
    const [formOpen, setFormOpen] = useState(false);
    const [deleting, setDeleting] = useState<Account | null>(null);
    const [busy, setBusy] = useState(false);
    const hasActive = accounts.some((a) => a.is_active);

    const openForm = (account: Account | null) => {
        setEditing(account);
        setFormOpen(true);
    };

    const confirmDelete = () => {
        if (!deleting) {
            return;
        }
        router.delete(
            BankAccountController.destroy.url({ account: deleting.id }),
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => {
                    setBusy(false);
                    setDeleting(null);
                },
            },
        );
    };

    return (
        <>
            <Head title={t('bank.title')} />
            <PageHeader
                title={t('bank.title')}
                description={t('bank.description')}
                actions={
                    canEdit && (
                        <Button
                            type="button"
                            onClick={() => openForm(null)}
                            data-test="add-bank-account"
                        >
                            <Icon icon={Plus} />
                            {t('bank.add')}
                        </Button>
                    )
                }
            />

            <div className="flex max-w-3xl flex-col gap-4">
                {!canEdit && (
                    <Alert variant="info">
                        <AlertBody>
                            <AlertDescription>
                                {t('account.profile.view_only')}
                            </AlertDescription>
                        </AlertBody>
                    </Alert>
                )}
                {canEdit && !hasActive && accounts.length > 0 && (
                    <BankMissingAlert />
                )}

                {accounts.length === 0 ? (
                    <Card>
                        <EmptyState
                            icon={Landmark}
                            title={t('bank.empty')}
                            description={t('bank.empty_desc')}
                        />
                    </Card>
                ) : (
                    <ul className="flex flex-col gap-3">
                        {accounts.map((account) => (
                            <li key={account.id} data-test="bank-account">
                                <Card className="gap-3 p-4 md:p-4">
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <div className="flex min-w-0 flex-col gap-1">
                                            <span className="flex flex-wrap items-center gap-2 text-subtitle text-strong">
                                                {account.bank_name}
                                                {account.is_default &&
                                                    account.is_active && (
                                                        <Badge variant="success">
                                                            {t('bank.default')}
                                                        </Badge>
                                                    )}
                                                {!account.is_active && (
                                                    <Badge variant="neutral">
                                                        {t('bank.inactive')}
                                                    </Badge>
                                                )}
                                            </span>
                                            <span className="text-body text-weak">
                                                {account.beneficiary_name}
                                            </span>
                                        </div>
                                        {canEdit && (
                                            <div className="flex gap-1">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={t('bank.edit')}
                                                    onClick={() =>
                                                        openForm(account)
                                                    }
                                                >
                                                    <Icon icon={Pencil} />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={t(
                                                        'bank.delete',
                                                    )}
                                                    onClick={() =>
                                                        setDeleting(account)
                                                    }
                                                >
                                                    <Icon icon={Trash2} />
                                                </Button>
                                            </div>
                                        )}
                                    </div>
                                    <dl className="grid gap-2 text-body sm:grid-cols-2">
                                        <div className="min-w-0">
                                            <dt className="text-caption text-weak">
                                                {t('bank.iban')}
                                            </dt>
                                            <dd className="font-mono break-all">
                                                <bdi dir="ltr">
                                                    {account.iban_grouped}
                                                </bdi>
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-caption text-weak">
                                                {t('bank.currency')}
                                            </dt>
                                            <dd>
                                                <bdi dir="ltr">
                                                    {account.currency}
                                                </bdi>
                                            </dd>
                                        </div>
                                        {account.account_number && (
                                            <div>
                                                <dt className="text-caption text-weak">
                                                    {t('bank.account_number')}
                                                </dt>
                                                <dd className="font-mono">
                                                    <bdi dir="ltr">
                                                        {account.account_number}
                                                    </bdi>
                                                </dd>
                                            </div>
                                        )}
                                        {account.swift_code && (
                                            <div>
                                                <dt className="text-caption text-weak">
                                                    {t('bank.swift')}
                                                </dt>
                                                <dd className="font-mono">
                                                    <bdi dir="ltr">
                                                        {account.swift_code}
                                                    </bdi>
                                                </dd>
                                            </div>
                                        )}
                                    </dl>
                                    {account.transfer_note && (
                                        <p className="text-caption text-weak">
                                            {account.transfer_note}
                                        </p>
                                    )}
                                </Card>
                            </li>
                        ))}
                    </ul>
                )}
                <p className="text-caption text-weak">
                    {t('bank.shown_where')}
                </p>
            </div>

            <Dialog open={formOpen} onOpenChange={setFormOpen}>
                <DialogContent size="md" aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>
                            {editing ? t('bank.edit') : t('bank.add')}
                        </DialogTitle>
                        <DialogDescription className="sr-only">
                            {t('bank.description')}
                        </DialogDescription>
                    </DialogHeader>
                    <Form
                        {...(editing
                            ? BankAccountController.update.form({
                                  account: editing.id,
                              })
                            : BankAccountController.store.form())}
                        key={editing?.id ?? 'new'}
                        options={{ preserveScroll: true }}
                        className="grid gap-4"
                        data-test="bank-form"
                        onSuccess={() => setFormOpen(false)}
                    >
                        {({ processing, errors }) => (
                            <>
                                <TextField
                                    label={t('bank.bank_name')}
                                    name="bank_name"
                                    defaultValue={editing?.bank_name ?? ''}
                                    required
                                    maxLength={100}
                                    autoFocus
                                    error={errors.bank_name}
                                />
                                <TextField
                                    label={t('bank.beneficiary')}
                                    hint={t('bank.beneficiary_hint')}
                                    name="beneficiary_name"
                                    defaultValue={
                                        editing?.beneficiary_name ?? ''
                                    }
                                    required
                                    maxLength={150}
                                    error={errors.beneficiary_name}
                                />
                                <TextField
                                    label={t('bank.iban')}
                                    hint={t('bank.iban_hint')}
                                    name="iban"
                                    dir="ltr"
                                    autoCapitalize="characters"
                                    spellCheck={false}
                                    placeholder="SA00 0000 0000 0000 0000 0000"
                                    defaultValue={editing?.iban_grouped ?? ''}
                                    required
                                    maxLength={34}
                                    error={errors.iban}
                                />
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <TextField
                                        label={t('bank.account_number')}
                                        name="account_number"
                                        dir="ltr"
                                        inputMode="numeric"
                                        defaultValue={
                                            editing?.account_number ?? ''
                                        }
                                        optional
                                        maxLength={40}
                                        error={errors.account_number}
                                    />
                                    <TextField
                                        label={t('bank.swift')}
                                        name="swift_code"
                                        dir="ltr"
                                        autoCapitalize="characters"
                                        spellCheck={false}
                                        defaultValue={editing?.swift_code ?? ''}
                                        optional
                                        maxLength={11}
                                        error={errors.swift_code}
                                    />
                                </div>
                                <SelectField
                                    label={t('bank.currency')}
                                    name="currency"
                                    defaultValue={
                                        editing?.currency ?? defaultCurrency
                                    }
                                    required
                                    options={currencies.map((code) => ({
                                        value: code,
                                        label: code,
                                    }))}
                                    error={errors.currency}
                                />
                                <TextareaField
                                    label={t('bank.note')}
                                    hint={t('bank.note_hint')}
                                    name="transfer_note"
                                    defaultValue={editing?.transfer_note ?? ''}
                                    optional
                                    maxLength={300}
                                    rows={2}
                                    error={errors.transfer_note}
                                />
                                <CheckboxField
                                    label={t('bank.make_default')}
                                    name="is_default"
                                    value="1"
                                    defaultChecked={
                                        editing
                                            ? editing.is_default
                                            : !hasActive
                                    }
                                    error={errors.is_default}
                                />
                                <CheckboxField
                                    label={t('bank.active')}
                                    hint={t('bank.active_hint')}
                                    name="is_active"
                                    value="1"
                                    defaultChecked={editing?.is_active ?? true}
                                    error={errors.is_active}
                                />
                                <DialogFooter>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() => setFormOpen(false)}
                                    >
                                        {t('ui.cancel')}
                                    </Button>
                                    <Button
                                        loading={processing}
                                        data-test="bank-save"
                                    >
                                        {t('ui.save')}
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
            >
                <DialogContent size="sm" aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>
                            {t('bank.delete_title', {
                                name: deleting?.bank_name ?? '',
                            })}
                        </DialogTitle>
                        <DialogDescription>
                            {t('bank.delete_desc')}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setDeleting(null)}
                        >
                            {t('ui.cancel')}
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            loading={busy}
                            onClick={confirmDelete}
                            data-test="bank-delete-confirm"
                        >
                            {t('bank.delete')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

BankAccounts.layout = {
    breadcrumbs: [
        { title: 'account.nav.home', href: home() },
        { title: 'bank.title', href: bank() },
    ],
};
