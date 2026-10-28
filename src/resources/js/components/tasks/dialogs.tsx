import { useState } from 'react';
import { TextareaField, TextField } from '@/components/opsone/field';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useT } from '@/lib/i18n';
import type { TaskRow } from './types';
import { useTaskCall } from './use-task-call';

/** TaskWaiting: put the work «بانتظار حاجة» with what it waits for and the follow-up date (M-07-T03). */
export function WaitDialog({
    task,
    open,
    onOpenChange,
}: {
    task: TaskRow;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const t = useT();
    const { call, busy } = useTaskCall();
    const [need, setNeed] = useState('');
    const [date, setDate] = useState('');
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const today = new Date().toISOString().slice(0, 10);

    const save = async () => {
        const result = await call(
            'wait',
            task.id,
            { need, follow_up_on: date, lock_version: task.lock_version },
            { onDone: () => onOpenChange(false) },
        );
        setErrors(result.errors);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent size="md" aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>{t('tasks.wait.title')}</DialogTitle>
                    <DialogDescription>
                        {task.title}, <bdi dir="ltr">{task.order.number}</bdi>
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4" data-test="wait-form">
                    <p className="rounded-md bg-fill px-3 py-2 text-body text-weak">
                        {t('tasks.wait.effect')}
                    </p>
                    <TextareaField
                        label={t('tasks.wait.need')}
                        value={need}
                        onChange={(e) => setNeed(e.target.value)}
                        maxLength={1000}
                        error={errors.need?.[0]}
                        required
                    />
                    <TextField
                        type="date"
                        label={t('tasks.wait.follow_up')}
                        value={date}
                        min={today}
                        onChange={(e) => setDate(e.target.value)}
                        error={errors.follow_up_on?.[0]}
                        required
                    />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={() => onOpenChange(false)}
                    >
                        {t('ui.cancel')}
                    </Button>
                    <Button
                        type="button"
                        disabled={busy || need.trim() === '' || date === ''}
                        onClick={save}
                        data-test="wait-save"
                    >
                        {t('tasks.wait.save')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** M-07-T07: cancel with a reason (P-22 / P-28). */
export function CancelDialog({
    task,
    open,
    onOpenChange,
}: {
    task: TaskRow;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const t = useT();
    const { call, busy } = useTaskCall();
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string[]>>({});

    const save = async () => {
        const result = await call(
            'cancel',
            task.id,
            { reason, lock_version: task.lock_version },
            { onDone: () => onOpenChange(false) },
        );
        setErrors(result.errors);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent size="md" aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>{t('tasks.cancel.title')}</DialogTitle>
                    <DialogDescription>{task.title}</DialogDescription>
                </DialogHeader>
                <TextareaField
                    label={t('tasks.reason')}
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                    maxLength={1000}
                    error={errors.reason?.[0]}
                    required
                />
                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={() => onOpenChange(false)}
                    >
                        {t('ui.cancel')}
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={busy || reason.trim() === ''}
                        onClick={save}
                        data-test="cancel-save"
                    >
                        {t('tasks.cancel.save')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
