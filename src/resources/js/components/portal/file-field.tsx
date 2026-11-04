import { Paperclip, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { Field } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';

/**
 * A file input in the interface language: the native control (whose «Choose File / No file chosen» follows the
 * browser, not the page) stays in the form, visually hidden, behind an outline button and the chosen file names.
 */
export function FileField({
    label,
    hint,
    error,
    name,
    accept,
    multiple = false,
    required = false,
    optional = false,
}: {
    label: string;
    hint?: string;
    error?: string;
    name: string;
    accept?: string;
    multiple?: boolean;
    required?: boolean;
    optional?: boolean;
}) {
    const t = useT();
    const id = useId();
    const input = useRef<HTMLInputElement>(null);
    const [names, setNames] = useState<string[]>([]);

    // An Inertia form resets its inputs after a successful submit; follow it so stale names do not linger.
    useEffect(() => {
        const form = input.current?.form;
        if (!form) {
            return;
        }
        const reset = () => setNames([]);
        form.addEventListener('reset', reset);

        return () => form.removeEventListener('reset', reset);
    }, []);

    const read = () =>
        setNames(Array.from(input.current?.files ?? [], (file) => file.name));

    const clear = () => {
        if (input.current) {
            input.current.value = '';
        }
        setNames([]);
    };

    return (
        <Field
            label={label}
            hint={hint}
            error={error}
            required={required}
            optional={optional}
            id={id}
        >
            {(control) => (
                <div className="flex flex-col gap-2">
                    <input
                        ref={input}
                        {...control}
                        type="file"
                        name={name}
                        accept={accept}
                        multiple={multiple}
                        required={required}
                        onChange={read}
                        className="peer sr-only"
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-focus"
                            onClick={() => input.current?.click()}
                        >
                            <Icon icon={Paperclip} size="sm" />
                            {names.length > 0
                                ? t('portal.file.change')
                                : t(
                                      multiple
                                          ? 'portal.file.choose_many'
                                          : 'portal.file.choose',
                                  )}
                        </Button>
                        {names.length === 0 && (
                            <span className="text-caption text-weak">
                                {t('portal.file.none')}
                            </span>
                        )}
                    </div>
                    {names.length > 0 && (
                        <div className="flex min-w-0 items-start gap-2 rounded-md bg-fill ps-3">
                            <ul
                                className="flex min-w-0 flex-1 flex-col py-2.5 text-body text-strong"
                                aria-live="polite"
                            >
                                {names.map((file, i) => (
                                    <li
                                        key={`${file}-${i}`}
                                        className="truncate"
                                    >
                                        <bdi>{file}</bdi>
                                    </li>
                                ))}
                            </ul>
                            <Button
                                type="button"
                                variant="ghost"
                                className="size-11 shrink-0 p-0"
                                aria-label={t('portal.file.remove')}
                                onClick={clear}
                            >
                                <Icon icon={X} />
                            </Button>
                        </div>
                    )}
                </div>
            )}
        </Field>
    );
}
