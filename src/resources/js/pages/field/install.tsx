import { Head, Link } from '@inertiajs/react';
import { Smartphone } from 'lucide-react';
import FieldController from '@/actions/App/Http/Controllers/Field/FieldController';
import { Icon } from '@/components/opsone/icon';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

const STEPS: Record<'android' | 'iphone', TranslationKey[]> = {
    android: [
        'field.install.android.1',
        'field.install.android.2',
        'field.install.android.3',
    ],
    iphone: [
        'field.install.iphone.1',
        'field.install.iphone.2',
        'field.install.iphone.3',
        'field.install.iphone.4',
    ],
};

/** How to add the field app to the home screen (Android Chrome, iPhone Safari). */
export default function FieldInstall() {
    const t = useT();

    return (
        <>
            <Head title={t('field.install.title')} />
            <h1 className="text-heading mb-2 text-strong">
                {t('field.install.title')}
            </h1>
            <p className="mt-0 mb-4 text-body text-weak">
                {t('field.install.desc')}
            </p>
            <div className="flex flex-col gap-4">
                {(['android', 'iphone'] as const).map((os) => (
                    <section
                        key={os}
                        className="rounded-lg border border-stroke-weak bg-surface p-4"
                    >
                        <h2 className="mt-0 mb-2 flex items-center gap-2 text-title text-strong">
                            <Icon icon={Smartphone} />
                            {t(`field.install.${os}.title` as TranslationKey)}
                        </h2>
                        <ol className="m-0 flex flex-col gap-1 ps-5 text-body">
                            {STEPS[os].map((key) => (
                                <li key={key}>{t(key)}</li>
                            ))}
                        </ol>
                    </section>
                ))}
                <p className="m-0 text-caption text-weak">
                    {t('field.install.session')}
                </p>
                <Link
                    href={FieldController.index.url()}
                    className="text-label text-link"
                >
                    {t('field.back')}
                </Link>
            </div>
        </>
    );
}
