import { Head, Link } from '@inertiajs/react';
import { UsersRound } from 'lucide-react';
import FieldDispatchController from '@/actions/App/Http/Controllers/FieldSales/FieldDispatchController';
import FieldTeamController from '@/actions/App/Http/Controllers/FieldSales/FieldTeamController';
import { EmptyState } from '@/components/opsone/empty-state';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';

type RepRow = {
    id: number;
    name: string;
    leads: { new: number; following: number };
    visits_today: number;
    visits_upcoming: number;
    overdue: number;
    conversions: number;
    open_orders: number;
};

/**
 * «فريقي الميداني» (ADR-0041): each rep the supervisor manages — open leads, today's and upcoming visits, what is late,
 * conversions in the last 30 days and open orders. A row opens the rep's follow-up and permissions.
 */
export default function FieldTeam({ reps }: { reps: RepRow[] }) {
    const t = useT();

    return (
        <>
            <Head title={t('field_sales.team.title')} />
            <PageHeader
                title={t('field_sales.team.title')}
                description={t('field_sales.team.description')}
                actions={
                    <Button asChild variant="secondary">
                        <Link href={FieldDispatchController.index.url()}>
                            {t('field_sales.dispatch.title')}
                        </Link>
                    </Button>
                }
            />
            {reps.length === 0 ? (
                <Card>
                    <EmptyState
                        icon={UsersRound}
                        title={t('field_sales.team.empty')}
                    />
                </Card>
            ) : (
                <Card className="gap-0 p-0 md:p-0">
                    <TableWrap>
                        <Table caption={t('field_sales.team.title')}>
                            <thead>
                                <tr>
                                    <Th>{t('field_sales.team.col.rep')}</Th>
                                    <Th>{t('field_sales.team.col.leads')}</Th>
                                    <Th>{t('field_sales.team.col.today')}</Th>
                                    <Th>
                                        {t('field_sales.team.col.upcoming')}
                                    </Th>
                                    <Th>{t('field_sales.team.col.overdue')}</Th>
                                    <Th>
                                        {t('field_sales.team.col.conversions')}
                                    </Th>
                                    <Th>{t('field_sales.team.col.orders')}</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {reps.map((rep) => (
                                    <tr key={rep.id} data-test="team-rep">
                                        <Td>
                                            <Link
                                                href={FieldTeamController.show.url(
                                                    rep.id,
                                                )}
                                                className="font-semibold text-link hover:underline"
                                            >
                                                {rep.name}
                                            </Link>
                                        </Td>
                                        <Td>
                                            {t('field_sales.team.leads', {
                                                new: rep.leads.new,
                                                following: rep.leads.following,
                                            })}
                                        </Td>
                                        <Td className="tabular-nums">
                                            {rep.visits_today}
                                        </Td>
                                        <Td className="tabular-nums">
                                            {rep.visits_upcoming}
                                        </Td>
                                        <Td>
                                            <Badge
                                                variant={
                                                    rep.overdue > 0
                                                        ? 'danger'
                                                        : 'success'
                                                }
                                            >
                                                {rep.overdue}
                                            </Badge>
                                        </Td>
                                        <Td className="tabular-nums">
                                            {rep.conversions}
                                        </Td>
                                        <Td className="tabular-nums">
                                            {rep.open_orders}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </TableWrap>
                </Card>
            )}
        </>
    );
}
