import { Form, Link } from '@inertiajs/react';
import ReservesController from '@/actions/App/Http/Controllers/RoyaltyTools/ReservesController';
import { Amount } from '@/components/amount';
import { DateRange } from '@/components/date-range';
import { Field } from '@/components/field';
import { EmptyState, Notice, Page, Panel, Stat } from '@/components/page';
import { RecordLink } from '@/components/record-link';
import { SortableHead } from '@/components/sortable-head';
import {
    CreateTransactions,
    OverlapNotice,
    RunsPanel,
    plain,
} from '@/components/royalty-tool';
import type { ToolRun } from '@/components/royalty-tool';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTableSort } from '@/hooks/use-table-sort';
import type { SortDirection } from '@/hooks/use-table-sort';
import { today } from '@/lib/format';
import type { Paginated } from '@/types';
import { index as items } from '@/routes/items';
import { index } from '@/routes/royalty-tools/reserves';

type Row = {
    release_id: number;
    release_name: string;
    base: string;
    rate: string;
    amount: string;
    held: boolean;
};

const NO_ROWS: Row[] = [];

const ROW_COLUMNS = {
    release_name: (row: Row) => row.release_name,
    base: (row: Row) => row.base,
    rate: (row: Row) => row.rate,
    amount: (row: Row) => (row.held ? row.amount : null),
};

const BALANCE_COLUMNS = {
    release_name: (row: { release_name: string }) => row.release_name,
    held: (row: { held: string }) => row.held,
    liquidated: (row: { liquidated: string }) => row.liquidated,
    balance: (row: { balance: string }) => row.balance,
};

type Props = {
    currency: string;
    runs: Paginated<ToolRun>;
    runFilters: {
        runs_sort: string | null;
        runs_direction: SortDirection | null;
    };
    overlaps: ToolRun[];
    filters: { from: string | null; to: string | null };
    report: {
        rows: Row[];
        total: string;
        base: string;
        unconverted: number;
    } | null;
    balances: {
        release_id: number;
        release_name: string;
        held: string;
        liquidated: string;
        balance: string;
    }[];
};

export default function Reserves({
    currency,
    runs,
    runFilters,
    overlaps,
    filters,
    report,
    balances,
}: Props) {
    const held = report?.rows.filter((row) => row.held).length ?? 0;
    const { rows: rowRows, sorter: rowSorter } = useTableSort(
        report?.rows ?? NO_ROWS,
        ROW_COLUMNS,
    );
    const { rows: balanceRows, sorter: balanceSorter } = useTableSort(
        balances,
        BALANCE_COLUMNS,
    );

    return (
        <Page
            title="Reserves"
            description="Hold a percentage of income for custom periods of time to account for returns etc. Pick the paid dates, check the report, then book the transactions."
        >
            <Panel
                title="How it is booked"
                description="The transactions are ordinary ones and flow through contracts, statements and ledgers like any other."
            >
                <div className="grid max-w-3xl gap-2 text-sm text-muted-foreground">
                    <p>
                        The reserve of a release is its physical income paid in
                        the range × the “Reserves %” set on the release. Returns
                        in the range lower it.
                    </p>
                    <p>
                        Creating the transactions books two lines per release on
                        its “[Reserves]” item: the reserve as a negative amount
                        on the day it is held, and the same amount back on the
                        day it is liquidated. The release earns less in the
                        period of the first date and gets it back in the period
                        of the second, for payees and label alike. Nothing is
                        liquidated automatically; you choose both dates.
                    </p>
                    <p>
                        The item follows the contracts of the release's tracks.
                        Change that on the{' '}
                        <Link
                            href={items({ query: { search: '[Reserves]' } })}
                            className="underline"
                        >
                            Items page
                        </Link>{' '}
                        if a reserve should hit other payees.
                    </p>
                </div>
            </Panel>

            <Panel title="Report">
                <Form
                    action={index.url()}
                    method="get"
                    options={{ preserveScroll: true }}
                    className="grid gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <DateRange defaults={filters} errors={errors} />
                            </div>
                            <div>
                                <Button disabled={processing}>
                                    Show report
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </Panel>

            {report && (
                <>
                    <OverlapNotice overlaps={overlaps} />

                    {report.unconverted > 0 && (
                        <Notice>
                            {report.unconverted} physical income{' '}
                            {report.unconverted === 1
                                ? 'transaction has'
                                : 'transactions have'}{' '}
                            no converted amount and{' '}
                            {report.unconverted === 1 ? 'is' : 'are'} not
                            counted.
                        </Notice>
                    )}

                    <div className="grid gap-4 sm:grid-cols-3">
                        <Stat
                            label="Reserves to hold"
                            value={
                                <Amount
                                    value={report.total}
                                    currency={currency}
                                />
                            }
                        />
                        <Stat
                            label="Physical income"
                            value={
                                <Amount
                                    value={report.base}
                                    currency={currency}
                                />
                            }
                        />
                        <Stat label="Releases" value={held} />
                    </div>

                    <Panel
                        title="Calculated reserves"
                        actions={
                            held > 0 && (
                                <CreateTransactions
                                    form={ReservesController.store.form()}
                                    fields={filters}
                                    overlaps={overlaps}
                                    description={`Books a held and a liquidated line for each of the ${held} releases, as one data import.`}
                                >
                                    {(errors) => (
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <Field
                                                label="Held on"
                                                htmlFor="held_on"
                                                error={errors.held_on}
                                                hint="The reserve is taken out in the period of this date."
                                                required
                                            >
                                                <Input
                                                    id="held_on"
                                                    name="held_on"
                                                    type="date"
                                                    defaultValue={
                                                        filters.to ?? today()
                                                    }
                                                    required
                                                />
                                            </Field>
                                            <Field
                                                label="Liquidated on"
                                                htmlFor="liquidated_on"
                                                error={errors.liquidated_on}
                                                hint="It is paid back in the period of this date."
                                                required
                                            >
                                                <Input
                                                    id="liquidated_on"
                                                    name="liquidated_on"
                                                    type="date"
                                                    required
                                                />
                                            </Field>
                                        </div>
                                    )}
                                </CreateTransactions>
                            )
                        }
                        flush
                    >
                        {report.rows.length === 0 ? (
                            <EmptyState
                                compact
                                title="No physical income was paid in this range on a release that has a reserves rate."
                            />
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <SortableHead
                                            column="release_name"
                                            sorter={rowSorter}
                                        >
                                            Release
                                        </SortableHead>
                                        <SortableHead
                                            column="base"
                                            sorter={rowSorter}
                                            first="desc"
                                            className="text-right"
                                        >
                                            Physical income
                                        </SortableHead>
                                        <SortableHead
                                            column="rate"
                                            sorter={rowSorter}
                                            first="desc"
                                            className="text-right"
                                        >
                                            Rate
                                        </SortableHead>
                                        <SortableHead
                                            column="amount"
                                            sorter={rowSorter}
                                            first="desc"
                                            className="text-right"
                                        >
                                            Reserve
                                        </SortableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rowRows.map((row) => (
                                        <TableRow key={row.release_id}>
                                            <TableCell className="font-medium">
                                                <RecordLink
                                                    to="release"
                                                    id={row.release_id}
                                                >
                                                    {row.release_name}
                                                </RecordLink>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Amount
                                                    value={row.base}
                                                    currency={currency}
                                                />
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {plain(row.rate)}%
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {row.held ? (
                                                    <Amount
                                                        value={row.amount}
                                                        currency={currency}
                                                    />
                                                ) : (
                                                    <span className="text-xs text-muted-foreground">
                                                        nothing to hold
                                                    </span>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                                <TableFooter>
                                    <TableRow>
                                        <TableCell>Total held</TableCell>
                                        <TableCell className="text-right">
                                            <Amount
                                                value={report.base}
                                                currency={currency}
                                            />
                                        </TableCell>
                                        <TableCell />
                                        <TableCell className="text-right">
                                            <Amount
                                                value={report.total}
                                                currency={currency}
                                            />
                                        </TableCell>
                                    </TableRow>
                                </TableFooter>
                            </Table>
                        )}
                    </Panel>
                </>
            )}

            <Panel
                title="Reserves by release"
                description="Everything held so far, what has been liquidated up to today, and what is still held."
                flush
            >
                {balances.length === 0 ? (
                    <EmptyState
                        compact
                        title="No reserves have been booked yet."
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <SortableHead
                                    column="release_name"
                                    sorter={balanceSorter}
                                >
                                    Release
                                </SortableHead>
                                <SortableHead
                                    column="held"
                                    sorter={balanceSorter}
                                    first="desc"
                                    className="text-right"
                                >
                                    Held
                                </SortableHead>
                                <SortableHead
                                    column="liquidated"
                                    sorter={balanceSorter}
                                    first="desc"
                                    className="text-right"
                                >
                                    Liquidated so far
                                </SortableHead>
                                <SortableHead
                                    column="balance"
                                    sorter={balanceSorter}
                                    first="desc"
                                    className="text-right"
                                >
                                    Still held
                                </SortableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {balanceRows.map((balance) => (
                                <TableRow key={balance.release_id}>
                                    <TableCell className="font-medium">
                                        <RecordLink
                                            to="release"
                                            id={balance.release_id}
                                        >
                                            {balance.release_name}
                                        </RecordLink>
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount
                                            value={balance.held}
                                            currency={currency}
                                        />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount
                                            value={balance.liquidated}
                                            currency={currency}
                                        />
                                    </TableCell>
                                    <TableCell className="text-right font-medium">
                                        <Amount
                                            value={balance.balance}
                                            currency={currency}
                                        />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>

            <RunsPanel
                runs={runs}
                filters={runFilters}
                currency={currency}
                totalLabel="Reserves held"
            />
        </Page>
    );
}

Reserves.layout = { breadcrumbs: [{ title: 'Reserves', href: index() }] };
