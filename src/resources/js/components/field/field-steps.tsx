import { router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { Icon } from '@/components/opsone/icon';
import { queued } from '@/lib/field-queue';
import type { QueuedUpload } from '@/lib/field-queue';
import { cn } from '@/lib/utils';

/** The real type decides the name: the server checks extension, MIME type and content together. */
export function extensionOf(blob: Blob, file: File): string {
    const byType: Record<string, string> = {
        'image/jpeg': 'jpg',
        'image/png': 'png',
        'image/webp': 'webp',
    };

    return byType[blob.type] ?? file.name.split('.').pop() ?? 'jpg';
}

/** One numbered step of a field visit screen. */
export function Step({
    n,
    title,
    done,
    children,
    disabled,
}: {
    n: number;
    title: string;
    done: boolean;
    disabled?: boolean;
    children: ReactNode;
}) {
    return (
        <section
            className={cn(
                'rounded-lg border border-stroke-weak bg-surface p-4',
                disabled && 'opacity-60',
            )}
            aria-labelledby={`step-${n}`}
        >
            <h2
                id={`step-${n}`}
                className="mb-3 flex items-center gap-2 text-title text-strong"
            >
                <span
                    className={cn(
                        'inline-flex size-7 shrink-0 items-center justify-center rounded-full text-label',
                        done
                            ? 'bg-success-bg text-success-text'
                            : 'bg-fill text-weak',
                    )}
                    aria-hidden="true"
                >
                    {done ? <Icon icon={Check} size="sm" /> : n}
                </span>
                {title}
            </h2>
            {children}
        </section>
    );
}

/** Pending local photos of this visit from the device queue, with object URLs for previews. */
export function useQueued(appointmentId: number) {
    const [items, setItems] = useState<(QueuedUpload & { preview: string })[]>(
        [],
    );
    const refresh = useCallback(async () => {
        const all = await queued().catch(() => []);
        setItems((previous) => {
            previous.forEach((p) => URL.revokeObjectURL(p.preview));

            return all
                .filter((item) => item.appointmentId === appointmentId)
                .map((item) => ({
                    ...item,
                    preview: URL.createObjectURL(item.blob),
                }));
        });
    }, [appointmentId]);

    useEffect(() => {
        void refresh();
        const onMessage = (event: MessageEvent) => {
            if (event.data?.type === 'uploads-flushed') {
                void refresh();
                router.reload({ only: ['visit'] });
            }
        };
        navigator.serviceWorker?.addEventListener('message', onMessage);

        return () =>
            navigator.serviceWorker?.removeEventListener('message', onMessage);
    }, [refresh]);

    return { items, refresh };
}
