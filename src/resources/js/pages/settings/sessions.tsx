import { Form, Head, router } from '@inertiajs/react';
import { Monitor, Smartphone, Tablet } from 'lucide-react';
import { useState } from 'react';
import SessionController from '@/actions/App/Http/Controllers/Account/SessionController';
import Heading from '@/components/heading';
import PasswordInput from '@/components/password-input';
import { Field } from '@/components/opsone/field';
import { EmptyState } from '@/components/opsone/empty-state';
import { Icon } from '@/components/opsone/icon';
import { Alert, AlertBody, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { index } from '@/routes/sessions';
import { home } from '@/routes/settings';

type SessionRow = {
    id: string;
    device: 'desktop' | 'mobile' | 'tablet';
    browser: string;
    platform: string;
    ip: string | null;
    last_active_at: string;
    current: boolean;
};

const DEVICE_ICON = {
    desktop: Monitor,
    mobile: Smartphone,
    tablet: Tablet,
} as const;

export default function Sessions({
    supported,
    sessions,
}: {
    supported: boolean;
    sessions: SessionRow[];
}) {
    const t = useT();
    const { locale } = useLocale();
    const [endingId, setEndingId] = useState<string | null>(null);
    const others = sessions.filter((session) => !session.current);

    const end = (id: string) => {
        setEndingId(id);
        router.delete(SessionController.destroy.url(id), {
            preserveScroll: true,
            onFinish: () => setEndingId(null),
        });
    };

    return (
        <>
            <Head title={t('account.sessions.title')} />

            <h1 className="sr-only">{t('account.sessions.title')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('account.sessions.title')}
                    description={t('account.sessions.description')}
                />

                {!supported ? (
                    <Alert variant="info">
                        <AlertBody>
                            <AlertDescription>
                                {t('account.sessions.unsupported')}
                            </AlertDescription>
                        </AlertBody>
                    </Alert>
                ) : (
                    <>
                        <ul
                            className="overflow-hidden rounded-lg border border-stroke-weak"
                            data-test="session-list"
                        >
                            {sessions.map((session) => {
                                const label = [
                                    session.browser,
                                    session.platform,
                                ]
                                    .filter(Boolean)
                                    .join(' · ');

                                return (
                                    <li
                                        key={session.id}
                                        className="flex flex-wrap items-center gap-3 border-b border-stroke-weak p-4 last:border-b-0"
                                    >
                                        <span className="inline-flex size-10 shrink-0 items-center justify-center rounded-md bg-fill text-weak">
                                            <Icon
                                                icon={
                                                    DEVICE_ICON[session.device]
                                                }
                                            />
                                        </span>
                                        <span className="min-w-0 flex-1 space-y-0.5">
                                            <span className="flex flex-wrap items-center gap-2 text-body font-semibold text-strong">
                                                {label ||
                                                    t(
                                                        'account.sessions.unknown',
                                                    )}
                                                <span className="font-normal text-weak">
                                                    {t(
                                                        `account.sessions.device.${session.device}`,
                                                    )}
                                                </span>
                                                {session.current && (
                                                    <Badge variant="success">
                                                        {t(
                                                            'account.sessions.current',
                                                        )}
                                                    </Badge>
                                                )}
                                            </span>
                                            <span className="block text-caption text-weak">
                                                {t(
                                                    'account.sessions.last_active',
                                                    {
                                                        when: formatDateTime(
                                                            session.last_active_at,
                                                            locale,
                                                        ),
                                                    },
                                                )}
                                                {session.ip && (
                                                    <>
                                                        {' · '}
                                                        <bdi dir="ltr">
                                                            {session.ip}
                                                        </bdi>
                                                    </>
                                                )}
                                            </span>
                                        </span>
                                        {!session.current && (
                                            <Button
                                                variant="secondary"
                                                size="sm"
                                                loading={
                                                    endingId === session.id
                                                }
                                                onClick={() => end(session.id)}
                                                data-test="sign-out-session"
                                            >
                                                {t('account.sessions.sign_out')}
                                            </Button>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>

                        {others.length === 0 ? (
                            <EmptyState
                                compact
                                icon={Monitor}
                                title={t('account.sessions.none_other')}
                            />
                        ) : (
                            <Dialog>
                                <DialogTrigger asChild>
                                    <Button
                                        variant="destructive"
                                        data-test="sign-out-others"
                                    >
                                        {t('account.sessions.sign_out_others')}
                                    </Button>
                                </DialogTrigger>
                                <DialogContent size="sm">
                                    <Form
                                        {...SessionController.destroyOthers.form()}
                                        options={{ preserveScroll: true }}
                                        resetOnError
                                        className="grid gap-4"
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <DialogHeader>
                                                    <DialogTitle>
                                                        {t(
                                                            'account.sessions.sign_out_others_title',
                                                        )}
                                                    </DialogTitle>
                                                    <DialogDescription>
                                                        {t(
                                                            'account.sessions.sign_out_others_text',
                                                        )}
                                                    </DialogDescription>
                                                </DialogHeader>
                                                <Field
                                                    label={t(
                                                        'account.sessions.password',
                                                    )}
                                                    required
                                                    error={
                                                        errors.current_password
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
                                                <DialogFooter className="gap-2 sm:space-x-0">
                                                    <DialogClose asChild>
                                                        <Button
                                                            type="button"
                                                            variant="secondary"
                                                        >
                                                            {t('ui.cancel')}
                                                        </Button>
                                                    </DialogClose>
                                                    <Button
                                                        type="submit"
                                                        variant="destructive"
                                                        loading={processing}
                                                    >
                                                        {processing && (
                                                            <Spinner />
                                                        )}
                                                        {t(
                                                            'account.sessions.sign_out_others',
                                                        )}
                                                    </Button>
                                                </DialogFooter>
                                            </>
                                        )}
                                    </Form>
                                </DialogContent>
                            </Dialog>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

Sessions.layout = {
    breadcrumbs: [
        { title: 'account.nav.home', href: home() },
        { title: 'account.sessions.title', href: index() },
    ],
};
