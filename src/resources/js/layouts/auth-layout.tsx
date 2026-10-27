import type { ReactNode } from 'react';
import AuthLayoutTemplate from '@/layouts/auth/auth-simple-layout';
import type { TranslationKey } from '@/lib/i18n';

export default function AuthLayout({
    title,
    description,
    children,
}: {
    title?: TranslationKey;
    description?: TranslationKey;
    children: ReactNode;
}) {
    return (
        <AuthLayoutTemplate title={title} description={description}>
            {children}
        </AuthLayoutTemplate>
    );
}
