import { Head, Link } from '@inertiajs/react';
import {
    CalendarClock,
    ChevronRight,
    CreditCard,
    KeyRound,
    LifeBuoy,
    MonitorSmartphone,
    Bell,
    Archive,
    Mail,
    Palette,
    Percent,
    ShieldCheck,
    Store,
    User,
    ReceiptText,
    Wallet,
    BellRing,
    ScrollText,
    MessageSquareText,
    Timer,
    CalendarPlus,
    MapPinned,
    Hash,
    Landmark,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ComponentProps } from 'react';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Badge } from '@/components/ui/badge';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { edit as integrations } from '@/routes/integrations';
import { preferences as notificationPrefs } from '@/routes/notifications';
import { show as ownership } from '@/routes/ownership';
import { show as plan } from '@/routes/plan';
import { edit as appearance } from '@/routes/appearance';
import { show as closure } from '@/routes/closure';
import { edit as einvoicing } from '@/routes/einvoicing';
import { edit as profile } from '@/routes/profile';
import {
    appointment_requests as appointmentRequests,
    clauses,
    field_sales as fieldSales,
    messages,
    numbering,
    plans,
    reminders,
    sla,
} from '@/routes/rules';
import { edit as security } from '@/routes/security';
import { bank, basic, home, sales, work } from '@/routes/settings';
import { index as sessions } from '@/routes/sessions';
import { index as supportAccess } from '@/routes/support-access';

/** Plan keys from the signed license; an unknown key (a plan added later) shows as it is. */
const PLAN_NAMES: Record<string, TranslationKey> = {
    basic: 'account.plan.name.basic',
    growth: 'account.plan.name.growth',
    scale: 'account.plan.name.scale',
};

type Props = {
    facility: {
        name_ar: string;
        name_en: string | null;
        default_locale: 'ar' | 'en';
        currency: string;
        timezone: string;
    };
    plan: {
        name: string | null;
        status: string;
        seats_used: number;
        seats_limit: number | null;
    };
    can: {
        editProfile: boolean;
        viewPlan: boolean;
        transferOwnership: boolean;
        manageSupportAccess: boolean;
        closure: boolean;
        einvoicing: boolean;
        rules: boolean;
    };
    pendingTransferForMe: boolean;
    pendingSupportRequests: number;
    bankMissing: boolean;
};

type Card = {
    key: string;
    href: ComponentProps<typeof Link>['href'];
    icon: LucideIcon;
    title: string;
    description: string;
    badge?: string;
};

function CardGrid({ cards }: { cards: Card[] }) {
    return (
        <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {cards.map((card) => (
                <li key={card.key} className="min-w-0">
                    <Link
                        href={card.href}
                        className="flex h-full min-h-11 items-start gap-3 rounded-lg border border-stroke-weak bg-surface p-4 text-inherit no-underline hover:border-stroke-control"
                    >
                        <span className="inline-flex size-10 shrink-0 items-center justify-center rounded-md bg-brand-subtle text-brand-subtle-text">
                            <Icon icon={card.icon} />
                        </span>
                        <span className="flex min-w-0 flex-1 flex-col gap-1">
                            <span className="flex flex-wrap items-center gap-2 text-subtitle text-strong">
                                {card.title}
                                {card.badge && (
                                    <Badge variant="warning">
                                        {card.badge}
                                    </Badge>
                                )}
                            </span>
                            <span className="text-body text-weak">
                                {card.description}
                            </span>
                        </span>
                        <Icon icon={ChevronRight} className="mt-2 text-weak" />
                    </Link>
                </li>
            ))}
        </ul>
    );
}

function Group({ title, cards }: { title: string; cards: Card[] }) {
    if (cards.length === 0) {
        return null;
    }

    return (
        <section className="space-y-3" aria-label={title}>
            <h2 className="text-section text-strong">{title}</h2>
            <CardGrid cards={cards} />
        </section>
    );
}

export default function SettingsHome({
    facility,
    plan: planInfo,
    can,
    pendingTransferForMe,
    pendingSupportRequests,
    bankMissing,
}: Props) {
    const t = useT();

    const planName =
        planInfo.name === null
            ? t('account.plan.name.none')
            : planInfo.name in PLAN_NAMES
              ? t(PLAN_NAMES[planInfo.name])
              : planInfo.name;

    const facilityCards: Card[] = [];
    if (can.editProfile || can.viewPlan) {
        facilityCards.push({
            key: 'facility',
            href: basic(),
            icon: Store,
            title: t('account.home.facility.title'),
            description: t('account.home.facility.summary', {
                currency: facility.currency,
                timezone: facility.timezone,
                language: t(
                    `account.profile.locale.${facility.default_locale}`,
                ),
            }),
        });
    }
    if (can.editProfile || can.viewPlan) {
        facilityCards.push({
            key: 'bank',
            href: bank(),
            icon: Landmark,
            title: t('bank.title'),
            description: t('bank.card'),
            badge: bankMissing ? t('bank.missing.badge') : undefined,
        });
        facilityCards.push({
            key: 'work',
            href: work(),
            icon: CalendarClock,
            title: t('appointments.work.title'),
            description: t('appointments.work.card'),
        });
        facilityCards.push({
            key: 'sales',
            href: sales(),
            icon: Percent,
            title: t('agreements.settings.title'),
            description: t('agreements.settings.card'),
        });
    }
    if (can.editProfile) {
        facilityCards.push({
            key: 'integrations',
            href: integrations(),
            icon: Mail,
            title: t('account.home.integrations.title'),
            description: t('account.home.integrations.description'),
        });
    }
    if (can.einvoicing) {
        facilityCards.push({
            key: 'einvoicing',
            href: einvoicing(),
            icon: ReceiptText,
            title: t('einvoicing.title'),
            description: t('einvoicing.card'),
        });
    }
    if (can.transferOwnership || pendingTransferForMe) {
        facilityCards.push({
            key: 'ownership',
            href: ownership(),
            icon: ShieldCheck,
            title: t('account.home.ownership.title'),
            description: pendingTransferForMe
                ? t('account.home.ownership.pending')
                : t('account.home.ownership.description'),
            badge: pendingTransferForMe ? '1' : undefined,
        });
    }

    if (can.manageSupportAccess) {
        facilityCards.push({
            key: 'support-access',
            href: supportAccess(),
            icon: LifeBuoy,
            title: t('account.home.support_access.title'),
            description:
                pendingSupportRequests > 0
                    ? t('account.home.support_access.pending')
                    : t('account.home.support_access.description'),
            badge:
                pendingSupportRequests > 0
                    ? String(pendingSupportRequests)
                    : undefined,
        });
    }

    // ADR-0037: the owner's business rules.
    const rulesCards: Card[] = can.rules
        ? [
              { key: 'plans', href: plans(), icon: Wallet },
              { key: 'reminders', href: reminders(), icon: BellRing },
              { key: 'clauses', href: clauses(), icon: ScrollText },
              { key: 'messages', href: messages(), icon: MessageSquareText },
              { key: 'sla', href: sla(), icon: Timer },
              { key: 'numbering', href: numbering(), icon: Hash },
              {
                  key: 'appointment_requests',
                  href: appointmentRequests(),
                  icon: CalendarPlus,
              },
              { key: 'field_sales', href: fieldSales(), icon: MapPinned },
          ].map((c) => ({
              ...c,
              title: t(`rules.nav.${c.key}` as TranslationKey),
              description: t(`rules.card.${c.key}` as TranslationKey),
          }))
        : [];

    const subscriptionCards: Card[] = [];
    if (can.viewPlan) {
        subscriptionCards.push({
            key: 'plan',
            href: plan(),
            icon: CreditCard,
            title: t('account.home.plan.title'),
            description:
                planInfo.seats_limit === null
                    ? t('account.home.plan.summary_unknown', {
                          plan: planName,
                          used: planInfo.seats_used,
                      })
                    : t('account.home.plan.summary', {
                          plan: planName,
                          used: planInfo.seats_used,
                          limit: planInfo.seats_limit,
                      }),
        });
    }
    if (can.closure) {
        subscriptionCards.push({
            key: 'closure',
            href: closure(),
            icon: Archive,
            title: t('closure.title'),
            description: t('closure.card'),
        });
    }

    const accountCards: Card[] = [
        {
            key: 'profile',
            href: profile(),
            icon: User,
            title: t('account.home.profile.title'),
            description: t('account.home.profile.description'),
        },
        {
            key: 'security',
            href: security(),
            icon: KeyRound,
            title: t('account.home.security.title'),
            description: t('account.home.security.description'),
        },
        {
            key: 'sessions',
            href: sessions(),
            icon: MonitorSmartphone,
            title: t('account.home.sessions.title'),
            description: t('account.home.sessions.description'),
        },
        {
            key: 'notifications',
            href: notificationPrefs(),
            icon: Bell,
            title: t('account.home.notifications.title'),
            description: t('account.home.notifications.description'),
        },
        {
            key: 'appearance',
            href: appearance(),
            icon: Palette,
            title: t('account.home.appearance.title'),
            description: t('account.home.appearance.description'),
        },
    ];

    return (
        <>
            <Head title={t('account.home.title')} />

            <PageHeader
                title={t('account.home.title')}
                description={t('account.home.description')}
            />

            <div className="space-y-8">
                {facilityCards.length === 0 && (
                    <p className="text-body text-weak">
                        {t('account.home.no_facility')}
                    </p>
                )}
                <Group
                    title={t('account.home.group.facility')}
                    cards={facilityCards}
                />
                <Group title={t('rules.section')} cards={rulesCards} />
                <Group
                    title={t('account.home.group.subscription')}
                    cards={subscriptionCards}
                />
                <Group
                    title={t('account.home.group.account')}
                    cards={accountCards}
                />
            </div>
        </>
    );
}

SettingsHome.layout = {
    breadcrumbs: [{ title: 'account.nav.home', href: home() }],
};
