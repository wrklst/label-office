import { Link, usePage } from '@inertiajs/react';
import PayoutController from '@/actions/App/Http/Controllers/PayoutController';
import { FilterBar } from '@/components/filter-bar';
import { Amount } from '@/components/amount';
import { DeleteButton } from '@/components/delete-button';
import { EmptyState, Page, Panel } from '@/components/page';
import { Pagination } from '@/components/pagination';
import { SortableHead } from '@/components/sortable-head';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useFilters } from '@/hooks/use-filters';
import { serverSorter } from '@/hooks/use-table-sort';
import type { SortDirection } from '@/hooks/use-table-sort';
import { date, money } from '@/lib/format';
import { show as showPayee } from '@/routes/payees';
import { index } from '@/routes/payouts';
import { index as periods, show as showPeriod } from '@/routes/periods';
import type { Paginated } from '@/types';
import { recordLinkClass } from '@/components/record-link';

type Payout = {
    id: number;
    total: string;
    memo: string | null;
    payee_id: number;
    period_id: number;
    paid_on: string;
    payee: string | null;
    period: string | null;
    user: string | null;
    ledgers: { name: string; amount: string }[];
};

export default function Payouts({
    payouts,
    filters,
}: {
    payouts: Paginated<Payout>;
    filters: {
        period: number | null;
        payee: number | null;
        sort: string | null;
        direction: SortDirection | null;
    };
}) {
    const filtered = filters.period !== null || filters.payee !== null;
    const currency = usePage().props.label?.currency ?? 'EUR';
    const { apply } = useFilters(index.url(), filters);
    const sorter = serverSorter(filters, apply);

    return (
        <Page
            title="Payouts"
            description="Payments you have made to payees. A payout is booked from its period and reduces what the payee is still owed. Nothing is sent to a bank from here."
        >
            <FilterBar
                url={index.url()}
                filters={filters}
                selects={[
                    {
                        name: 'period',
                        label: 'Period',
                        placeholder: 'All periods',
                        records: 'periods',
                    },
                    {
                        name: 'payee',
                        label: 'Payee',
                        placeholder: 'All payees',
                        records: 'payees',
                    },
                ]}
            />

            {payouts.total === 0 ? (
                <EmptyState
                    title={
                        filtered
                            ? 'No payout matches these filters'
                            : 'No payouts booked yet'
                    }
                    description={
                        filtered
                            ? undefined
                            : 'Open a period and book a payout next to the payee you have paid.'
                    }
                    action={
                        filtered ? undefined : (
                            <Button asChild>
                                <Link href={periods()}>Go to periods</Link>
                            </Button>
                        )
                    }
                />
            ) : (
                <Panel flush>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <SortableHead
                                    column="paid_on"
                                    sorter={sorter}
                                    first="desc"
                                >
                                    Paid date
                                </SortableHead>
                                <SortableHead column="payee" sorter={sorter}>
                                    Payee
                                </SortableHead>
                                <SortableHead column="period" sorter={sorter}>
                                    Period
                                </SortableHead>
                                <TableHead>Booked to</TableHead>
                                <SortableHead column="memo" sorter={sorter}>
                                    Memo
                                </SortableHead>
                                <SortableHead column="user" sorter={sorter}>
                                    Recorded by
                                </SortableHead>
                                <SortableHead
                                    column="total"
                                    sorter={sorter}
                                    first="desc"
                                    className="text-right"
                                >
                                    Amount
                                </SortableHead>
                                <TableHead className="w-12" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {payouts.data.map((payout) => (
                                <TableRow key={payout.id}>
                                    <TableCell className="whitespace-nowrap">
                                        {date(payout.paid_on)}
                                    </TableCell>
                                    <TableCell>
                                        <Link
                                            href={showPayee(payout.payee_id)}
                                            className="font-medium underline decoration-muted-foreground/40 underline-offset-4"
                                        >
                                            {payout.payee}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        <Link
                                            href={showPeriod(payout.period_id)}
                                            className={recordLinkClass}
                                        >
                                            {payout.period}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {payout.ledgers.length === 1
                                            ? payout.ledgers[0].name
                                            : payout.ledgers
                                                  .map(
                                                      (ledger) =>
                                                          `${ledger.name} ${money(ledger.amount, currency)}`,
                                                  )
                                                  .join(', ')}
                                    </TableCell>
                                    <TableCell className="max-w-xs truncate text-muted-foreground">
                                        {payout.memo}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {payout.user ?? '–'}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount value={payout.total} />
                                    </TableCell>
                                    <TableCell>
                                        <DeleteButton
                                            url={PayoutController.destroy.url(
                                                payout.id,
                                            )}
                                            title={`Delete payout to ${payout.payee}?`}
                                            description={`The ${money(payout.total, currency)} paid to ${payout.payee} for ${payout.period} becomes payable again. Statements already generated keep showing it until you regenerate them.`}
                                        />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <Pagination page={payouts} noun="payouts" />
                </Panel>
            )}
        </Page>
    );
}

Payouts.layout = { breadcrumbs: [{ title: 'Payouts', href: index() }] };
