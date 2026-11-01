import { FileText, Send, ShieldCheck, X } from 'lucide-react';
import DocumentController from '@/actions/App/Http/Controllers/Agreements/DocumentController';
import { Icon } from '@/components/opsone/icon';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { AgreementDocument, AgreementPayload } from './types';
import { useAgreementCall } from './use-agreement-call';

/**
 * Branded PDF and e-signature of one quote/contract (ADR-0029): download/print in either language, the signature
 * state with its evidence, and the signing link staff send or revoke. A signed document has no edit action at all.
 */
export function EsignBlock({
    document: d,
    onDone,
}: {
    document: AgreementDocument;
    onDone: (next: AgreementPayload, message?: string) => void;
}) {
    const t = useT();
    const { locale } = useLocale();
    const call = useAgreementCall();
    const esign = d.esign;
    if (!esign) {
        return null;
    }
    const signature = esign.signature;
    const pdf = (lang: 'ar' | 'en', extra = '') =>
        `${esign.pdf_url}?locale=${lang}${extra}`;

    const sendLink = async () => {
        const data = await call.run(
            'POST',
            DocumentController.sendSignLink.url(d.id),
            {},
        );
        if (data) {
            onDone(data.agreement, t('agreements.esign.link_sent'));
        }
    };
    const revokeLink = async () => {
        const data = await call.run(
            'POST',
            DocumentController.revokeSignLinks.url(d.id),
            {},
        );
        if (data) {
            onDone(data.agreement, t('agreements.esign.link_revoked'));
        }
    };

    return (
        <div className="bg-surface-sunken grid gap-2 rounded-md p-2 text-caption">
            <div className="flex flex-wrap items-center gap-2">
                {signature ? (
                    <Badge variant="success">
                        <Icon icon={ShieldCheck} size="sm" />
                        {t('agreements.esign.status.signed')}
                    </Badge>
                ) : esign.link ? (
                    <Badge variant="info">
                        {t('agreements.esign.status.link')}
                    </Badge>
                ) : d.status === 'awaiting_customer' ? (
                    <Badge variant="warning">
                        {t('agreements.esign.status.pending')}
                    </Badge>
                ) : null}
                {esign.link && (
                    <span className="text-weak">
                        {t('agreements.esign.link_detail', {
                            date: formatDateTime(esign.link.expires_at, locale),
                        })}
                    </span>
                )}
            </div>
            {signature && (
                <div className="grid gap-0.5">
                    <p className="text-strong">
                        {t('agreements.esign.signed_detail', {
                            name: signature.signer_name,
                            date: formatDateTime(signature.signed_at, locale),
                        })}
                    </p>
                    <p className="text-weak">
                        {t('agreements.esign.verification', {
                            channel: signature.otp_channel.toUpperCase(),
                            ref: signature.otp_reference,
                            ip: signature.ip ?? '—',
                        })}
                    </p>
                    <p className="break-all text-weak" dir="ltr">
                        SHA-256 {signature.pdf_sha256}
                    </p>
                    <p className="text-weak">{t('agreements.esign.locked')}</p>
                </div>
            )}
            <div className="flex flex-wrap gap-2">
                {esign.pdf_url && signature && (
                    <Button asChild size="sm" variant="secondary">
                        <a href={pdf(signature.locale, '&download=1')}>
                            <Icon icon={FileText} size="sm" />
                            {t('agreements.esign.signed_pdf')}
                        </a>
                    </Button>
                )}
                {esign.pdf_url &&
                    (['ar', 'en'] as const).map((lang) => (
                        <Button key={lang} asChild size="sm" variant="ghost">
                            <a
                                href={pdf(
                                    lang,
                                    signature ? '&version=original' : '',
                                )}
                                target="_blank"
                                rel="noreferrer"
                            >
                                <Icon icon={FileText} size="sm" />
                                {t(
                                    lang === 'ar'
                                        ? 'agreements.esign.pdf_ar'
                                        : 'agreements.esign.pdf_en',
                                )}
                            </a>
                        </Button>
                    ))}
                {esign.can.send_link && (
                    <Button
                        type="button"
                        size="sm"
                        disabled={call.busy}
                        onClick={sendLink}
                        data-test="send-sign-link"
                    >
                        <Icon icon={Send} size="sm" />
                        {esign.link
                            ? t('agreements.esign.resend_link')
                            : t('agreements.esign.send_link')}
                    </Button>
                )}
                {esign.can.revoke_link && (
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        disabled={call.busy}
                        onClick={revokeLink}
                    >
                        <Icon icon={X} size="sm" />
                        {t('agreements.esign.revoke_link')}
                    </Button>
                )}
            </div>
            {call.error && <p className="text-danger-text">{call.error}</p>}
        </div>
    );
}
