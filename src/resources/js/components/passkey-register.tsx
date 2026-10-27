import { usePasskeyRegister } from '@laravel/passkeys/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { TextField } from '@/components/opsone/field';
import { useT } from '@/lib/i18n';

type Props = {
    onSuccess: () => void;
};

export default function PasskeyRegistration({ onSuccess }: Props) {
    const t = useT();
    const [name, setName] = useState(() => {
        const ua = navigator.userAgent;

        const browser = [
            { pattern: /Edg|Edge/, name: 'Edge' },
            { pattern: /OPR|Opera|OPiOS/, name: 'Opera' },
            { pattern: /Firefox|FxiOS/, name: 'Firefox' },
            { pattern: /Chrome|CriOS/, name: 'Chrome' },
            { pattern: /Safari/, name: 'Safari' },
        ].find(({ pattern }) => pattern.test(ua))?.name;

        const os = [
            { pattern: /iPhone/, name: 'iPhone' },
            { pattern: /iPad|Macintosh(?=.*Mobile)/, name: 'iPad' },
            { pattern: /Android/, name: 'Android' },
            { pattern: /Mac/, name: 'Mac' },
            { pattern: /Windows/, name: 'Windows' },
        ].find(({ pattern }) => pattern.test(ua))?.name;

        return [browser, os].filter(Boolean).join(' on ') || '';
    });

    const [showForm, setShowForm] = useState(false);
    const { register, isLoading, error, isSupported } = usePasskeyRegister({
        onSuccess: () => {
            setName('');
            setShowForm(false);
            onSuccess();
        },
    });

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();

        if (!name.trim()) {
            return;
        }

        await register(name);
    };

    const handleCancel = () => {
        setShowForm(false);
        setName('');
    };

    if (!isSupported) {
        return (
            <div className="text-body text-weak">
                {t('passkeys.unsupported')}
            </div>
        );
    }

    if (!showForm) {
        return (
            <Button variant="outline" onClick={() => setShowForm(true)}>
                {t('passkeys.add')}
            </Button>
        );
    }

    return (
        <form
            onSubmit={handleSubmit}
            className="space-y-4 rounded-lg border border-stroke-weak bg-fill p-4"
        >
            <TextField
                label={t('passkeys.name')}
                type="text"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder={t('passkeys.name_placeholder')}
                hint={t('passkeys.name_hint')}
                autoFocus
            />

            {error && <InputError message={error} />}

            <div className="flex gap-2">
                <Button type="submit" disabled={isLoading || !name.trim()}>
                    {isLoading
                        ? t('passkeys.registering')
                        : t('passkeys.register')}
                </Button>
                <Button type="button" variant="ghost" onClick={handleCancel}>
                    {t('ui.cancel')}
                </Button>
            </div>
        </form>
    );
}
