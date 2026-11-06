import { Copy, Landmark } from 'lucide-react';
import type { ReactNode } from 'react';
import { notify } from '@/components/opsone/toast';
import { Icon } from '@/components/opsone/icon';
import { Money } from '@/components/portal/parts';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import { useT } from '@/lib/i18n';
import type { PortalBankDetails } from '@/types/portal';

function Row({
    label,
    children,
    copy,
    copyLabel,
}: {
    label: string;
    children: ReactNode;
    copy?: string;
    copyLabel?: string;
}) {
    const t = useT();
    const [, write] = useClipboard();
    const onCopy = async () =>
        (await write(copy ?? ''))
            ? notify('success', {
                  title: t('portal.bank.copied', { what: label }),
              })
            : notify('danger', { title: t('portal.bank.copy_failed') });

    return (
        <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 py-2">
            <div className="flex min-w-0 flex-col gap-0.5">
                <span className="text-caption text-weak">{label}</span>
                <span className="text-body break-words text-strong">
                    {children}
                </span>
            </div>
            {copy !== undefined && (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="shrink-0"
                    aria-label={copyLabel}
                    onClick={() => void onCopy()}
                    data-test="bank-copy"
                >
                    <Icon icon={Copy} />
                    {t('ui.copy')}
                </Button>
            )}
        </div>
    );
}

/**
 * «Pay by bank transfer» (ADR-0046): the facility's default account, the amount and the reference the customer writes
 * on the transfer, each with a copy button. The IBAN is copied without spaces and shown in groups of four.
 */
export function BankTransferCard({
    bank,
    amountMinor,
    reference,
}: {
    bank: PortalBankDetails;
    amountMinor?: number | null;
    reference: string;
}) {
    const t = useT();
    const hasAmount =
        amountMinor !== undefined && amountMinor !== null && amountMinor > 0;

    return (
        <div className="rounded-lg bg-fill p-4" data-test="bank-transfer">
            <p className="mb-1 flex items-center gap-2 text-subtitle text-strong">
                <Icon icon={Landmark} />
                {bank.bank_name}
            </p>
            <div className="divide-y divide-stroke-weak">
                {hasAmount && (
                    <Row
                        label={t('portal.bank.amount')}
                        copy={(amountMinor / 100).toFixed(2)}
                        copyLabel={t('portal.bank.copy_amount')}
                    >
                        <Money minor={amountMinor} />
                    </Row>
                )}
                <Row
                    label={t('portal.bank.beneficiary')}
                    copy={bank.beneficiary_name}
                    copyLabel={t('portal.bank.copy_beneficiary')}
                >
                    {bank.beneficiary_name}
                </Row>
                <Row
                    label={t('portal.bank.iban')}
                    copy={bank.iban}
                    copyLabel={t('portal.bank.copy_iban')}
                >
                    <bdi dir="ltr" className="font-mono whitespace-nowrap">
                        {bank.iban_grouped}
                    </bdi>
                </Row>
                {bank.account_number && (
                    <Row label={t('portal.bank.account_number')}>
                        <bdi dir="ltr" className="font-mono">
                            {bank.account_number}
                        </bdi>
                    </Row>
                )}
                {bank.swift_code && (
                    <Row label={t('portal.bank.swift')}>
                        <bdi dir="ltr" className="font-mono">
                            {bank.swift_code}
                        </bdi>
                    </Row>
                )}
                <Row
                    label={t('portal.bank.reference')}
                    copy={reference}
                    copyLabel={t('portal.bank.copy_reference')}
                >
                    <bdi dir="ltr" className="font-mono">
                        {reference}
                    </bdi>
                </Row>
            </div>
            <p className="mt-2 text-caption text-weak">
                {t('portal.bank.reference_hint')}
            </p>
            {bank.transfer_note && (
                <p className="mt-1 text-caption text-strong">
                    {bank.transfer_note}
                </p>
            )}
        </div>
    );
}
