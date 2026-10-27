import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import type { FlashToast } from '@/types/ui';

export function useFlashToast(): void {
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const data = flash?.toast as FlashToast | undefined;

            if (!data) {
                return;
            }

            // Errors stay until dismissed; everything else closes after 5s (design-system rule).
            toast[data.type](data.message, {
                duration: data.type === 'error' ? Infinity : 5000,
            });
        });
    }, []);
}
