import { Progress } from '@/components/org/progress';
import type { Seats } from '@/components/org/format';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';

/** Seats in use (members + pending invitations) against the plan, with a warning from 80%. */
export function SeatMeter({ seats }: { seats: Seats }) {
    const t = useT();
    const label =
        seats.limit === null
            ? t('org.team.seats_unlimited', { used: seats.used })
            : t('org.team.seats', { used: seats.used, limit: seats.limit });
    const percent = seats.limit
        ? Math.round((seats.used / seats.limit) * 100)
        : 0;

    return (
        <Card className="mb-4 gap-2 p-4 md:p-4" data-test="seat-meter">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <span className="font-semibold text-strong">{label}</span>
                <span className="text-caption text-weak">
                    {t('org.team.seats_note')}
                </span>
            </div>
            {seats.limit !== null && (
                <Progress label={label} value={seats.used} max={seats.limit} />
            )}
            {percent >= 80 && (
                <p className="text-caption text-warning-text">
                    {t('org.team.seats_warn', { percent })}
                </p>
            )}
        </Card>
    );
}
