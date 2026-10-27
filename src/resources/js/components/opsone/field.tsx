import { ChevronDown, CircleAlert } from 'lucide-react';
import { useId } from 'react';
import type { ComponentProps, ReactNode } from 'react';
import { Icon } from '@/components/opsone/icon';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type FieldControlProps = {
    id: string;
    'aria-describedby': string | undefined;
    'aria-invalid': true | undefined;
    'aria-required': true | undefined;
};

export type FieldProps = {
    label: ReactNode;
    hint?: ReactNode;
    error?: ReactNode;
    required?: boolean;
    optional?: boolean;
    id?: string;
    className?: string;
    children: (control: FieldControlProps) => ReactNode;
};

export function FieldError({
    id,
    children,
}: {
    id?: string;
    children: ReactNode;
}) {
    const t = useT();

    return (
        <p
            id={id}
            className="flex items-start gap-1 text-caption text-danger-text"
        >
            <Icon icon={CircleAlert} size="sm" className="mt-0.5" />
            <span>
                <span className="sr-only">{t('ui.error')} </span>
                {children}
            </span>
        </p>
    );
}

/** Visible label, `*`/optional marker, hint and error wired to the control with aria-describedby. */
export function Field({
    label,
    hint,
    error,
    required,
    optional,
    id,
    className,
    children,
}: FieldProps) {
    const t = useT();
    const generated = useId();
    const controlId = id ?? generated;
    const hintId = hint ? `${controlId}-hint` : undefined;
    const errorId = error ? `${controlId}-error` : undefined;
    const describedBy =
        [hintId, errorId].filter(Boolean).join(' ') || undefined;

    return (
        <div className={cn('flex min-w-0 flex-col gap-2', className)}>
            <Label htmlFor={controlId}>
                {label}
                {required && (
                    <span className="text-danger-text" aria-hidden="true">
                        *
                    </span>
                )}
                {optional && !required && (
                    <span className="font-normal text-weak">
                        ({t('ui.optional')})
                    </span>
                )}
            </Label>
            {children({
                id: controlId,
                'aria-describedby': describedBy,
                'aria-invalid': error ? true : undefined,
                'aria-required': required ? true : undefined,
            })}
            {hint && (
                <p id={hintId} className="text-caption text-weak">
                    {hint}
                </p>
            )}
            {error && <FieldError id={errorId}>{error}</FieldError>}
        </div>
    );
}

type CommonFieldProps = Omit<FieldProps, 'children' | 'id'>;

export type TextFieldProps = CommonFieldProps &
    Omit<ComponentProps<typeof Input>, 'id'> & { id?: string };

export function TextField({
    label,
    hint,
    error,
    required,
    optional,
    id,
    className,
    ...input
}: TextFieldProps) {
    return (
        <Field
            label={label}
            hint={hint}
            error={error}
            required={required}
            optional={optional}
            id={id}
            className={className}
        >
            {(control) => <Input {...control} required={required} {...input} />}
        </Field>
    );
}

export type TextareaFieldProps = CommonFieldProps &
    Omit<ComponentProps<'textarea'>, 'id'> & { id?: string };

export function TextareaField({
    label,
    hint,
    error,
    required,
    optional,
    id,
    className,
    ...textarea
}: TextareaFieldProps) {
    return (
        <Field
            label={label}
            hint={hint}
            error={error}
            required={required}
            optional={optional}
            id={id}
            className={className}
        >
            {(control) => (
                <textarea
                    {...control}
                    required={required}
                    {...textarea}
                    className="field-control min-h-24 w-full resize-y py-2.5 text-control"
                />
            )}
        </Field>
    );
}

export type SelectOption = { value: string; label: string; disabled?: boolean };

export type SelectFieldProps = CommonFieldProps &
    Omit<ComponentProps<'select'>, 'id' | 'children'> & {
        id?: string;
        options: SelectOption[];
        placeholder?: string;
    };

/** Native <select>: best on mobile and with screen readers (design-system decision). */
export function SelectField({
    label,
    hint,
    error,
    required,
    optional,
    id,
    className,
    options,
    placeholder,
    ...select
}: SelectFieldProps) {
    return (
        <Field
            label={label}
            hint={hint}
            error={error}
            required={required}
            optional={optional}
            id={id}
            className={className}
        >
            {(control) => (
                <div className="relative block">
                    <select
                        {...control}
                        required={required}
                        {...select}
                        className="field-control w-full cursor-pointer appearance-none pe-10 text-control"
                    >
                        {placeholder && (
                            <option value="" disabled>
                                {placeholder}
                            </option>
                        )}
                        {options.map((option) => (
                            <option
                                key={option.value}
                                value={option.value}
                                disabled={option.disabled}
                            >
                                {option.label}
                            </option>
                        ))}
                    </select>
                    <Icon
                        icon={ChevronDown}
                        className="pointer-events-none absolute inset-e-3 top-1/2 -translate-y-1/2 text-weak"
                    />
                </div>
            )}
        </Field>
    );
}

export type CheckboxFieldProps = Omit<
    ComponentProps<'input'>,
    'type' | 'id'
> & {
    label: ReactNode;
    hint?: ReactNode;
    error?: ReactNode;
    id?: string;
};

/** Native checkbox in a 44px row; the whole label is clickable. */
export function CheckboxField({
    label,
    hint,
    error,
    id,
    className,
    ...input
}: CheckboxFieldProps) {
    const generated = useId();
    const controlId = id ?? generated;
    const hintId = hint ? `${controlId}-hint` : undefined;
    const errorId = error ? `${controlId}-error` : undefined;

    return (
        <div className={cn('flex flex-col gap-1', className)}>
            <div className="flex min-h-11 items-center gap-3">
                <input
                    type="checkbox"
                    id={controlId}
                    aria-describedby={
                        [hintId, errorId].filter(Boolean).join(' ') || undefined
                    }
                    aria-invalid={error ? true : undefined}
                    className="size-5 shrink-0 cursor-pointer accent-brand disabled:cursor-not-allowed"
                    {...input}
                />
                <label
                    htmlFor={controlId}
                    className="cursor-pointer text-control font-normal text-strong"
                >
                    {label}
                </label>
            </div>
            {hint && (
                <p id={hintId} className="ps-8 text-caption text-weak">
                    {hint}
                </p>
            )}
            {error && (
                <div className="ps-8">
                    <FieldError id={errorId}>{error}</FieldError>
                </div>
            )}
        </div>
    );
}

export type RadioOption = { value: string; label: string; disabled?: boolean };

export type RadioGroupProps = {
    legend: ReactNode;
    name: string;
    options: RadioOption[];
    value?: string;
    defaultValue?: string;
    onChange?: (value: string) => void;
    inline?: boolean;
    error?: ReactNode;
    hint?: ReactNode;
    required?: boolean;
};

/** Native radios inside a <fieldset>/<legend>. */
export function RadioGroup({
    legend,
    name,
    options,
    value,
    defaultValue,
    onChange,
    inline,
    error,
    hint,
    required,
}: RadioGroupProps) {
    const base = useId();

    return (
        <fieldset className="m-0 flex min-w-0 flex-col gap-1 border-0 p-0">
            <legend className="mb-1 inline-flex gap-1 p-0 text-label text-strong">
                {legend}
                {required && (
                    <span className="text-danger-text" aria-hidden="true">
                        *
                    </span>
                )}
            </legend>
            <div
                className={cn(
                    'flex',
                    inline ? 'flex-row flex-wrap gap-x-6' : 'flex-col',
                )}
            >
                {options.map((option) => {
                    const id = `${base}-${option.value}`;

                    return (
                        <div
                            key={option.value}
                            className="flex min-h-11 items-center gap-3"
                        >
                            <input
                                type="radio"
                                id={id}
                                name={name}
                                value={option.value}
                                disabled={option.disabled}
                                {...(value !== undefined
                                    ? { checked: value === option.value }
                                    : {
                                          defaultChecked:
                                              defaultValue === option.value,
                                      })}
                                onChange={() => onChange?.(option.value)}
                                className="size-5 shrink-0 cursor-pointer accent-brand disabled:cursor-not-allowed"
                            />
                            <label
                                htmlFor={id}
                                className="cursor-pointer text-control font-normal text-strong"
                            >
                                {option.label}
                            </label>
                        </div>
                    );
                })}
            </div>
            {hint && <p className="text-caption text-weak">{hint}</p>}
            {error && <FieldError>{error}</FieldError>}
        </fieldset>
    );
}

export type SwitchFieldProps = {
    label: ReactNode;
    hint?: ReactNode;
    checked?: boolean;
    defaultChecked?: boolean;
    onCheckedChange?: (checked: boolean) => void;
    disabled?: boolean;
    name?: string;
    id?: string;
};

export function SwitchField({
    label,
    hint,
    id,
    ...switchProps
}: SwitchFieldProps) {
    const generated = useId();
    const controlId = id ?? generated;

    return (
        <div className="flex min-h-11 items-center justify-between gap-4">
            <div className="flex min-w-0 flex-col gap-0.5">
                <label
                    htmlFor={controlId}
                    className="cursor-pointer text-control font-normal text-strong"
                >
                    {label}
                </label>
                {hint && <p className="text-caption text-weak">{hint}</p>}
            </div>
            <Switch id={controlId} {...switchProps} />
        </div>
    );
}
