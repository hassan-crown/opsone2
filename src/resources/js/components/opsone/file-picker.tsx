import { FileText, Paperclip, X } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import type { ChangeEvent, ComponentProps } from 'react';
import { Icon } from '@/components/opsone/icon';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type FilePickerProps = Omit<
    ComponentProps<'input'>,
    'type' | 'value' | 'defaultValue' | 'onChange' | 'children'
> & {
    /** Button text; defaults to «اختيار صورة أو PDF». */
    buttonLabel?: string;
    /** Called with the chosen files (empty after «remove»). */
    onFilesChange?: (files: File[]) => void;
};

/**
 * Localised file input: the native control stays in the form (so `name`, `required`, `accept` and
 * FormData keep working) but is visually hidden, and a real button plus the chosen file names replace
 * the browser's English «Choose File / No file chosen». Pass Field's control props straight through.
 */
export function FilePicker({
    buttonLabel,
    onFilesChange,
    multiple,
    disabled,
    className,
    id,
    ...input
}: FilePickerProps) {
    const t = useT();
    const ref = useRef<HTMLInputElement>(null);
    const fallbackId = useId();
    const inputId = id ?? fallbackId;
    const [files, setFiles] = useState<File[]>([]);

    const change = (event: ChangeEvent<HTMLInputElement>) => {
        const next = Array.from(event.target.files ?? []);
        setFiles(next);
        onFilesChange?.(next);
    };

    const clear = () => {
        if (ref.current) {
            ref.current.value = '';
        }

        setFiles([]);
        onFilesChange?.([]);
        ref.current?.focus();
    };

    return (
        <div
            data-slot="file-picker"
            className={cn('flex flex-col gap-2', className)}
        >
            <input
                {...input}
                ref={ref}
                id={inputId}
                type="file"
                multiple={multiple}
                disabled={disabled}
                onChange={change}
                className="peer sr-only"
            />
            <Button
                type="button"
                variant="outline"
                disabled={disabled}
                className="self-start peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-focus"
                onClick={() => ref.current?.click()}
                aria-hidden
                tabIndex={-1}
            >
                <Icon icon={Paperclip} />
                {buttonLabel ?? t('ui.file.choose')}
            </Button>
            {files.length === 0 ? (
                <p className="m-0 text-caption text-weak" aria-live="polite">
                    {t('ui.file.none')}
                </p>
            ) : (
                <ul
                    className="m-0 flex list-none flex-col gap-1 p-0"
                    aria-live="polite"
                >
                    {files.map((file) => (
                        <li
                            key={`${file.name}-${file.size}`}
                            className="flex min-h-11 min-w-0 items-center gap-2 rounded-md bg-fill ps-3"
                        >
                            <Icon
                                icon={FileText}
                                size="sm"
                                className="text-weak"
                            />
                            <span
                                className="min-w-0 flex-1 truncate text-body text-strong"
                                title={file.name}
                            >
                                <bdi>{file.name}</bdi>
                            </span>
                            {!multiple && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="text-weak"
                                    disabled={disabled}
                                    aria-label={t('ui.file.remove', {
                                        name: file.name,
                                    })}
                                    onClick={clear}
                                >
                                    <Icon icon={X} />
                                </Button>
                            )}
                        </li>
                    ))}
                    {multiple && (
                        <li>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                disabled={disabled}
                                onClick={clear}
                            >
                                {t('ui.file.remove_all')}
                            </Button>
                        </li>
                    )}
                </ul>
            )}
        </div>
    );
}
