import { createInertiaApp } from '@inertiajs/react';
import { Providers } from '@/components/opsone/providers';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import WizardLayout from '@/layouts/wizard-layout';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
            case name === 'design':
                return Providers;
            case name.startsWith('auth/'):
                return [Providers, AuthLayout];
            case name.startsWith('org/setup/'):
                return [Providers, WizardLayout];
            case name.startsWith('settings/'):
                return [Providers, AppLayout, SettingsLayout];
            default:
                return [Providers, AppLayout];
        }
    },
    strictMode: true,
    progress: {
        color: '#0E7C72',
    },
});

// This will set light / dark mode on load...
initializeTheme();
