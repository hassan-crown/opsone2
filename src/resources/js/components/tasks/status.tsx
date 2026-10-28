import { Badge } from '@/components/ui/badge';
import { formatDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslateFn, TranslationKey } from '@/lib/i18n';
import type { TaskRow } from './types';

const TONE = {
    unassigned: 'neutral',
    assigned: 'info',
    in_progress: 'brand',
    waiting: 'warning',
    completed: 'success',
    cancelled: 'danger',
} as const;

export function TaskStatusBadge({ status }: { status: TaskRow['status'] }) {
    const t = useT();

    return (
        <Badge variant={TONE[status]}>
            {t(`tasks.status.${status}` as TranslationKey)}
        </Badge>
    );
}

/** «متأخر يومين» / «اليوم» / the date; work with no date says so. */
export function useDueLabel() {
    const t = useT();
    const { locale } = useLocale();

    return (row: Pick<TaskRow, 'due_at' | 'overdue'>): string =>
        dueLabel(row, t, locale);
}

export function dueLabel(
    row: Pick<TaskRow, 'due_at' | 'overdue'>,
    t: TranslateFn,
    locale: 'ar' | 'en',
): string {
    if (row.due_at === null) {
        return t('tasks.due.none');
    }
    const due = new Date(row.due_at);
    const now = new Date();
    const sameDay = due.toDateString() === now.toDateString();

    if (sameDay) {
        return `${t('tasks.due.today')} ${formatDate(row.due_at, locale, { timeStyle: 'short' })}`;
    }
    if (row.overdue) {
        const days = Math.max(
            1,
            Math.round((now.getTime() - due.getTime()) / 86_400_000),
        );

        return t('tasks.due.overdue_days', { days });
    }

    return formatDate(row.due_at, locale, { dateStyle: 'medium' });
}
