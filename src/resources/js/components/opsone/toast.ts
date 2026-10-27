import { toast } from 'sonner';

export type ToastTone = 'success' | 'info' | 'warning' | 'danger';

type ToastOptions = {
    title: string;
    message?: string;
    action?: { label: string; onClick: () => void };
};

/** Success/info close after 5s; warnings with actions and all errors stay until dismissed. */
export function notify(
    tone: ToastTone,
    { title, message, action }: ToastOptions,
): string | number {
    const sticky = tone === 'danger' || Boolean(action);
    const options = {
        description: message,
        action,
        duration: sticky ? Infinity : 5000,
    };

    switch (tone) {
        case 'success':
            return toast.success(title, options);
        case 'info':
            return toast.info(title, options);
        case 'warning':
            return toast.warning(title, options);
        case 'danger':
            return toast.error(title, options);
    }
}
