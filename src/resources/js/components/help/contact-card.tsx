import { Mail, MessageCircle, Phone } from 'lucide-react';
import { Icon } from '@/components/opsone/icon';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';

export type SupportContact = {
    email?: string | null;
    whatsapp?: { number: string; url: string } | null;
    phone?: { number: string; url: string } | null;
};

/** OpsOne's direct channels, set by OpsOne at hand-over. Renders nothing when none is configured. */
export function ContactCard({ contact }: { contact: SupportContact | null }) {
    const t = useT();

    if (!contact) {
        return null;
    }

    const rows = [
        contact.email && {
            key: 'email',
            icon: Mail,
            label: t('help.contact.email'),
            text: contact.email,
            href: `mailto:${contact.email}`,
        },
        contact.whatsapp && {
            key: 'whatsapp',
            icon: MessageCircle,
            label: t('help.contact.whatsapp'),
            text: contact.whatsapp.number,
            href: contact.whatsapp.url,
        },
        contact.phone && {
            key: 'phone',
            icon: Phone,
            label: t('help.contact.phone'),
            text: contact.phone.number,
            href: contact.phone.url,
        },
    ].filter((row) => !!row);

    return (
        <Card className="mb-6 p-5" data-test="support-contact-card">
            <h2 className="text-subtitle text-strong">
                {t('help.contact.title')}
            </h2>
            <p className="mb-3 text-weak">{t('help.contact.description')}</p>
            <ul className="flex flex-col gap-2">
                {rows.map((row) => (
                    <li key={row.key}>
                        <a
                            href={row.href}
                            className="inline-flex items-center gap-2"
                            data-test={`support-contact-${row.key}`}
                            {...(row.key === 'whatsapp'
                                ? { target: '_blank', rel: 'noopener noreferrer' }
                                : {})}
                        >
                            <Icon icon={row.icon} size="sm" />
                            <span>{row.label}:</span>
                            <bdi dir="ltr">{row.text}</bdi>
                        </a>
                    </li>
                ))}
            </ul>
        </Card>
    );
}
