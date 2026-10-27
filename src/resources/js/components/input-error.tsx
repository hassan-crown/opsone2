import type { HTMLAttributes } from 'react';
import { FieldError } from '@/components/opsone/field';
import { cn } from '@/lib/utils';

export default function InputError({
    message,
    className = '',
    ...props
}: HTMLAttributes<HTMLDivElement> & { message?: string }) {
    return message ? (
        <div className={cn(className)} {...props}>
            <FieldError>{message}</FieldError>
        </div>
    ) : null;
}
