import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';

type Touch = {
    source: string;
    medium: string;
    campaign: string | null;
    landing_path: string | null;
    touched_at: string | null;
};

export type Attribution = { first?: Touch; last?: Touch };

/** «Came from» (ADR-0045): the visit that first brought the record and the last campaign or referral. Read only. */
export function AttributionCard({ attribution }: { attribution: Attribution }) {
    const t = useT();
    const rows = (['last', 'first'] as const).filter((k) => attribution[k]);
    if (rows.length === 0) {
        return null;
    }

    return (
        <Card data-test="attribution-card">
            <h2 className="text-subtitle text-strong">
                {t('marketing.attribution.title')}
            </h2>
            <dl className="grid gap-3 text-control">
                {rows.map((kind) => {
                    const touch = attribution[kind] as Touch;

                    return (
                        <div key={kind}>
                            <dt className="text-caption text-weak">
                                {t(`marketing.attribution.${kind}`)}
                            </dt>
                            <dd className="text-strong">
                                <bdi dir="ltr">
                                    {touch.source} / {touch.medium}
                                    {touch.campaign
                                        ? ` · ${touch.campaign}`
                                        : ''}
                                </bdi>
                                {touch.landing_path && (
                                    <span className="block text-caption text-weak">
                                        {t('marketing.attribution.landing')}:{' '}
                                        <bdi dir="ltr">
                                            {touch.landing_path}
                                        </bdi>
                                    </span>
                                )}
                            </dd>
                        </div>
                    );
                })}
            </dl>
        </Card>
    );
}
