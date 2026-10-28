import { useState } from 'react';
import OrderProgressController from '@/actions/App/Http/Controllers/Flow/OrderProgressController';
import {
    SelectField,
    TextField,
    TextareaField,
} from '@/components/opsone/field';
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
import type { Member, ProgressResponse } from './progress-api';

/** `OrderPause`: a reason, who follows the order meanwhile and an optional review date (WF-09). */
export function PauseDialog({
    orderId,
    lockVersion,
    members,
    onClose,
    onDone,
}: {
    orderId: number;
    lockVersion: number;
    members: Member[];
    onClose: () => void;
    onDone: (next: ProgressResponse) => void;
}) {
    const t = useT();
    const [reason, setReason] = useState('');
    const [follower, setFollower] = useState('');
    const [reviewOn, setReviewOn] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});

    const submit = async () => {
        setBusy(true);
        setError(null);
        setErrors({});
        const result = await api<ProgressResponse>(
            'POST',
            OrderProgressController.pause.url(orderId),
            {
                lock_version: lockVersion,
                reason,
                follow_up_user_id: follower === '' ? null : Number(follower),
                review_on: reviewOn === '' ? null : reviewOn,
            },
        );
        setBusy(false);
        if (result.ok && result.data) {
            onDone(result.data);

            return;
        }
        setErrors(result.errors);
        if (Object.keys(result.errors).length === 0) {
            setError(explain(result, t));
        }
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent size="md" aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>{t('progress.pause.title')}</DialogTitle>
                    <DialogDescription>
                        {t('progress.pause.intro')}
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4" data-test="pause-form">
                    {error && (
                        <Alert variant="danger" role="alert">
                            <AlertBody>{error}</AlertBody>
                        </Alert>
                    )}
                    <TextareaField
                        label={t('progress.pause.reason')}
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        maxLength={1000}
                        error={errors.reason?.[0]}
                        required
                    />
                    <SelectField
                        label={t('progress.pause.follower_label')}
                        value={follower}
                        onChange={(e) => setFollower(e.target.value)}
                        error={errors.follow_up_user_id?.[0]}
                        required
                        placeholder={t('progress.pause.follower_placeholder')}
                        options={members.map((m) => ({
                            value: String(m.id),
                            label: m.name,
                        }))}
                    />
                    <TextField
                        label={t('progress.pause.review_label')}
                        type="date"
                        value={reviewOn}
                        onChange={(e) => setReviewOn(e.target.value)}
                        error={errors.review_on?.[0]}
                        dir="ltr"
                        optional
                    />
                </div>
                <DialogFooter>
                    <Button type="button" variant="secondary" onClick={onClose}>
                        {t('flow.cancel')}
                    </Button>
                    <Button
                        type="button"
                        loading={busy}
                        disabled={reason.trim() === '' || follower === ''}
                        onClick={submit}
                        data-test="pause-confirm"
                    >
                        {t('progress.pause.confirm')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
