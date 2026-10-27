import { Eye, EyeOff } from 'lucide-react';
import type { ComponentProps, Ref } from 'react';
import { useState } from 'react';
import { Icon } from '@/components/opsone/icon';
import { Input } from '@/components/ui/input';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export default function PasswordInput({
    className,
    ref,
    ...props
}: Omit<ComponentProps<'input'>, 'type'> & { ref?: Ref<HTMLInputElement> }) {
    const t = useT();
    const [showPassword, setShowPassword] = useState(false);

    return (
        <div className="relative" dir="ltr">
            <Input
                type={showPassword ? 'text' : 'password'}
                className={cn('pe-12', className)}
                ref={ref}
                {...props}
            />
            <button
                type="button"
                onClick={() => setShowPassword((prev) => !prev)}
                className="absolute inset-y-0 inset-e-0 flex w-11 items-center justify-center rounded-e-md text-weak hover:text-strong"
                aria-label={
                    showPassword
                        ? t('auth.hide_password')
                        : t('auth.show_password')
                }
                aria-pressed={showPassword}
            >
                <Icon icon={showPassword ? EyeOff : Eye} />
            </button>
        </div>
    );
}
