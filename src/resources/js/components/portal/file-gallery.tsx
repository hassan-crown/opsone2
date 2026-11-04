import { useState } from 'react';
import { FileLinks } from '@/components/portal/parts';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { useT } from '@/lib/i18n';
import type { PortalFile } from '@/types/portal';

const IMAGE = /\.(jpe?g|png|webp|gif|avif)$/i;

/**
 * The files of a document as the customer has to judge them: pictures as thumbnails that open full size (a design is
 * approved by looking at it, not at its file name), anything else as a link.
 */
export function FileGallery({ files }: { files: PortalFile[] }) {
    const t = useT();
    const [open, setOpen] = useState<PortalFile | null>(null);
    const images = files.filter((file) => IMAGE.test(file.name));
    const others = files.filter((file) => !IMAGE.test(file.name));

    return (
        <div className="flex flex-col gap-3">
            {images.length > 0 && (
                <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    {images.map((file) => (
                        <li key={file.url}>
                            <button
                                type="button"
                                title={file.name}
                                aria-label={t('portal.file.view', {
                                    name: file.name,
                                })}
                                onClick={() => setOpen(file)}
                                className="block w-full cursor-zoom-in overflow-hidden rounded-lg border border-stroke-weak bg-fill"
                            >
                                <img
                                    src={file.url}
                                    alt=""
                                    loading="lazy"
                                    decoding="async"
                                    className="aspect-[4/3] w-full object-cover"
                                />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            <FileLinks files={others} />
            <Dialog
                open={open !== null}
                onOpenChange={(next) => !next && setOpen(null)}
            >
                <DialogContent size="lg" className="gap-3 p-3 md:p-4">
                    <DialogTitle className="truncate pe-10 text-label">
                        <bdi>{open?.name}</bdi>
                    </DialogTitle>
                    {open && (
                        <img
                            src={open.url}
                            alt={open.name}
                            className="max-h-[75dvh] w-full rounded-md object-contain"
                        />
                    )}
                    {open && (
                        <a
                            href={open.url}
                            className="text-label text-link underline-offset-4 hover:underline"
                        >
                            {t('portal.file.download')}
                        </a>
                    )}
                </DialogContent>
            </Dialog>
        </div>
    );
}
