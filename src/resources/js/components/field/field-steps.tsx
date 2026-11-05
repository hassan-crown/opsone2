import { router } from '@inertiajs/react';
import { Camera, Check, CloudOff, ImagePlus, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Icon } from '@/components/opsone/icon';
import { notify } from '@/components/opsone/toast';
import { Button } from '@/components/ui/button';
import { compressImage, enqueue, flush, queued } from '@/lib/field-queue';
import type { QueuedUpload } from '@/lib/field-queue';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { useOnline } from '@/layouts/field-layout';

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
    needsNetwork,
}: {
    n: number;
    title: string;
    done: boolean;
    disabled?: boolean;
    /** Offline, the step says why its buttons are off (they post to the server; only photos queue on the device). */
    needsNetwork?: boolean;
    children: ReactNode;
}) {
    const t = useT();
    const online = useOnline();

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
            {needsNetwork && !online && !done && (
                <p
                    className="mt-0 mb-3 flex items-center gap-2 text-caption text-warning-text"
                    data-test="step-offline"
                >
                    <Icon icon={CloudOff} size="sm" />
                    {t('field.net.step')}
                </p>
            )}
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

/**
 * Compresses a photo and writes it to the device queue, then tries to send it. Saving on the device is the part that
 * must not fail silently (storage full, an image the browser cannot read); sending is retried later on its own.
 */
export async function queuePhoto(
    file: File,
    upload: Pick<QueuedUpload, 'url' | 'appointmentId' | 'phase'>,
    refresh: () => Promise<void>,
    messages: { title: string; message: string },
): Promise<void> {
    try {
        const blob = await compressImage(file);
        await enqueue({
            ...upload,
            id: crypto.randomUUID(),
            blob,
            name: `${upload.phase}.${extensionOf(blob, file)}`,
            xsrf: '',
            created: Date.now(),
        });
    } catch {
        notify('danger', messages);

        return;
    }
    await refresh();
    if (!navigator.onLine) {
        return;
    }
    await flush().catch(() => 0);
    await refresh();
    router.reload({ only: ['visit'] });
}

/** «Take a photo» opens the camera; «From gallery» opens the picker for a photo taken earlier. */
export function PhotoButtons({
    label,
    busy,
    disabled,
    onPick,
}: {
    label: string;
    busy: boolean;
    disabled: boolean;
    onPick: (file: File) => void;
}) {
    const t = useT();
    const camera = useRef<HTMLInputElement>(null);
    const gallery = useRef<HTMLInputElement>(null);
    const input = (ref: typeof camera, capture: boolean) => (
        <input
            ref={ref}
            type="file"
            accept="image/*"
            capture={capture ? 'environment' : undefined}
            className="sr-only"
            tabIndex={-1}
            aria-hidden="true"
            onChange={(e) => {
                const file = e.target.files?.[0];
                e.target.value = '';
                if (file) {
                    onPick(file);
                }
            }}
        />
    );

    return (
        <div className="grid grid-cols-[1fr_auto] gap-2">
            {input(camera, true)}
            {input(gallery, false)}
            <Button
                type="button"
                variant="secondary"
                loading={busy}
                disabled={disabled}
                onClick={() => camera.current?.click()}
            >
                <Icon icon={Camera} />
                {label}
            </Button>
            <Button
                type="button"
                variant="secondary"
                disabled={disabled || busy}
                onClick={() => gallery.current?.click()}
                data-test="photo-gallery"
            >
                <Icon icon={ImagePlus} />
                {t('field.photos.gallery')}
            </Button>
        </div>
    );
}

/** A photo still on the device: waiting to be sent, or refused (then it can be removed with a 44px target). */
export function PendingThumb({
    item,
    onDiscard,
}: {
    item: QueuedUpload & { preview: string };
    onDiscard: (id: string) => void;
}) {
    const t = useT();

    return (
        <div className="relative">
            <img
                src={item.preview}
                alt=""
                className="aspect-square w-full rounded-md border border-dashed border-stroke-control object-cover opacity-70"
            />
            <span
                className={cn(
                    'absolute inset-x-1 bottom-1 rounded-sm px-1 text-center text-caption',
                    item.failed
                        ? 'bg-danger-bg text-danger-text'
                        : 'bg-surface text-strong',
                )}
            >
                {item.failed
                    ? t('field.photos.refused')
                    : t('field.photos.waiting')}
            </span>
            {item.failed && (
                <button
                    type="button"
                    onClick={() => onDiscard(item.id)}
                    className="absolute end-0 top-0 grid size-11 place-items-center"
                    aria-label={t('field.photos.discard')}
                >
                    <span className="grid size-8 place-items-center rounded-full border border-stroke-weak bg-surface text-danger-text shadow-xs">
                        <Icon icon={Trash2} size="sm" />
                    </span>
                </button>
            )}
        </div>
    );
}
