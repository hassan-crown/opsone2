import { useState } from 'react';
import { TextareaField } from '@/components/opsone/field';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { api } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { explain } from './progress-api';
import type { ProgressResponse } from './progress-api';

/** A confirmation that needs a written reason (rework `OrderRework`, allow-to-proceed): POSTs `{reason, lock_version}`. */
export function ReasonDialog({
    title,
    description,
    reasonLabel,
    confirmLabel,
    url,
    lockVersion,
    onClose,
    onDone,
}: {
    title: string;
    description: string;
    reasonLabel: string;
    confirmLabel: string;
    url: string;
    lockVersion: number;
    onClose: () => void;
    onDone: (next: ProgressResponse) => void;
}) {
    const t = useT();
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [fieldError, setFieldError] = useState<string | undefined>();

    const submit = async () => {
        setBusy(true);
        setError(null);
        setFieldError(undefined);
        const result = await api<ProgressResponse>('POST', url, {
            lock_version: lockVersion,
            reason,
        });
        setBusy(false);
        if (result.ok && result.data) {
            onDone(result.data);

            return;
        }
        setFieldError(result.errors.reason?.[0]);
        if (!result.errors.reason) {
            setError(explain(result, t));
        }
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent size="md" aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <div className="grid gap-4" data-test="reason-form">
                    {error && (
                        <Alert variant="danger" role="alert">
                            <AlertBody>{error}</AlertBody>
                        </Alert>
                    )}
                    <TextareaField
                        label={reasonLabel}
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        maxLength={1000}
                        error={fieldError}
                        required
                    />
                </div>
                <DialogFooter>
                    <Button type="button" variant="secondary" onClick={onClose}>
                        {t('flow.cancel')}
                    </Button>
                    <Button
                        type="button"
                        loading={busy}
                        disabled={reason.trim() === ''}
                        onClick={submit}
                        data-test="reason-confirm"
                    >
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
