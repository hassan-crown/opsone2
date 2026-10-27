import { usePage } from '@inertiajs/react';
import { DirectionProvider } from '@radix-ui/react-direction';
import type { ReactNode } from 'react';
import { useEffect } from 'react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';

/** Keeps <html lang dir> and Radix direction in step with the server-resolved locale. */
export function Providers({ children }: { children: ReactNode }) {
    const { locale, dir } = usePage().props;

    useEffect(() => {
        document.documentElement.lang = locale;
        document.documentElement.dir = dir;
    }, [locale, dir]);

    return (
        <DirectionProvider dir={dir}>
            <TooltipProvider>
                {children}
                <Toaster />
            </TooltipProvider>
        </DirectionProvider>
    );
}
