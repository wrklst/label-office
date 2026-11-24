import { Form, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import PpdController from '@/actions/App/Http/Controllers/RoyaltyTools/PpdController';
import RatesController from '@/actions/App/Http/Controllers/RoyaltyTools/RatesController';
import TransactionController from '@/actions/App/Http/Controllers/TransactionController';
import { Amount } from '@/components/amount';
import { DateRange } from '@/components/date-range';
import { Field, SelectInput } from '@/components/field';
import { EmptyState, Notice, Page, Panel, Stat } from '@/components/page';
import { RecordLink } from '@/components/record-link';
import { RecordSelect } from '@/components/record-select';
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
import { date, quantity, today } from '@/lib/format';
import { index as items } from '@/routes/items';
import { edit as labelSettings } from '@/routes/label-settings';
import { index } from '@/routes/royalty-tools/ppd';
import type { Paginated } from '@/types';

type Row = {
    release_id: number;
    release_name: string;
    track_id: number | null;
    track_name: string | null;
    item_id: number | null;
    item_name: string | null;
    quantity: string;
    rate: string;
    rate_source: 'item' | 'track' | 'release';
    amount: string;
};

const NO_ROWS: Row[] = [];

const ROW_COLUMNS = {
    release_name: (row: Row) => row.release_name,
    track_name: (row: Row) => row.track_name,
    rate_source: (row: Row) => row.rate_source,
    quantity: (row: Row) => row.quantity,
    rate: (row: Row) => row.rate,
    amount: (row: Row) => row.amount,
};

type Report = {
    rows: Row[];
    groups: { release_id: number; track_id: number | null }[];
    total: string;
    quantity: string;
    unconverted: number;
    unassigned: number;
    without_quantity_count: number;
    without_quantity: {
        id: number;
        paid_on: string;
        description: string;
        release_name: string | null;
        release_id: number | null;
    }[];
};

type Props = {
    currency: string;
    runs: Paginated<ToolRun>;
    runFilters: {
        runs_sort: string | null;
        runs_direction: SortDirection | null;
    };
    overlaps: ToolRun[];
    filters: {
        from: string | null;
        to: string | null;
        artist_id: number | null;
        release_id: number | null;
        format: string | null;
    };
    settings: { ignore_negative_quantities: boolean };
    report: Report | null;
    formatOptions: string[];
};

export default function Ppd({
    currency,
    runs,
    runFilters,
    overlaps,
    filters,
    settings,
    report,
    formatOptions,
}: Props) {
    const { url } = usePage();
    const [ratesRelease, setRatesRelease] = useState('');
    const { rows, sorter } = useTableSort(report?.rows ?? NO_ROWS, ROW_COLUMNS);

    return (
        <Page
            title="PPD"
            description="Calculate royalties based on PPD (Published Price to Dealer) rates and transaction units. Pick the paid dates, check the report, then book the transactions."
        >
            <Panel
                title="How it is booked"
                description="The transactions are ordinary ones and flow through contracts, statements and ledgers like any other."
            >
                <div className="grid max-w-3xl gap-2 text-sm text-muted-foreground">
                    <p>
                        A rate is an amount in {currency} per unit sold, set on
                        a release, a track or a single item; the most specific
                        one wins. Every income transaction paid in the range
                        whose item, track or release has a rate takes part: its
                        quantity × the rate, summed by release and track.
                    </p>
                    <p>
                        Each total is booked twice: as income on “[PPD Income]”
                        and with the opposite sign on “[PPD Income Offset]”, so
                        nothing changes until contracts say so. A payee owed 15%
                        of PPD gets it from a contract with a 15% split on the
                        PPD Income item; give the offset item to whoever bears
                        that cost. Both are set on the{' '}
                        <Link
                            href={items({ query: { search: '[PPD' } })}
                            className="underline"
                        >
                            Items page
                        </Link>
                        .
                    </p>
                    <p>
                        {settings.ignore_negative_quantities
                            ? 'Returns (negative units) are ignored.'
                            : 'Returns (negative units) reduce the units.'}{' '}
                        Change this in the{' '}
                        <Link href={labelSettings()} className="underline">
                            label settings
                        </Link>
                        .
                    </p>
                </div>
            </Panel>

            <Panel
                title="Rates"
                description="Set the PPD rate of a release, of single tracks, or of single items when formats have different prices."
            >
                <div className="flex flex-wrap items-end gap-3">
                    <Field
                        label="Release"
                        htmlFor="rates_release"
                        className="w-full max-w-sm"
                    >
                        <RecordSelect
                            id="rates_release"
                            records="releases"
                            value={ratesRelease}
                            onChange={(id) => setRatesRelease(String(id))}
                            placeholder="Choose a release…"
                        />
                    </Field>
                    {ratesRelease === '' ? (
                        <Button variant="outline" disabled>
                            Edit rates
                        </Button>
                    ) : (
                        <Button variant="outline" asChild>
                            <Link
                                href={RatesController.edit.url({
                                    tool: 'ppd',
                                    release: Number(ratesRelease),
                                })}
                            >
                                Edit rates
                            </Link>
                        </Button>
                    )}
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
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                                <DateRange defaults={filters} errors={errors} />
                                <Field
                                    label="Artist"
                                    htmlFor="artist_id"
                                    error={errors.artist_id}
                                >
                                    <RecordSelect
                                        id="artist_id"
                                        name="artist_id"
                                        records="artists"
                                        defaultValue={filters.artist_id}
                                        placeholder="All artists"
                                    />
                                </Field>
                                <Field
                                    label="Release"
                                    htmlFor="release_id"
                                    error={errors.release_id}
                                >
                                    <RecordSelect
                                        id="release_id"
                                        name="release_id"
                                        records="releases"
                                        defaultValue={filters.release_id}
                                        placeholder="All releases"
                                    />
                                </Field>
                                <Field
                                    label="Format"
                                    htmlFor="format"
                                    error={errors.format}
                                >
                                    <SelectInput
                                        id="format"
                                        name="format"
                                        defaultValue={filters.format ?? ''}
                                        options={formatOptions}
                                        placeholder="All formats"
                                    />
                                </Field>
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
                            {report.unconverted} matching{' '}
                            {report.unconverted === 1
                                ? 'transaction has'
                                : 'transactions have'}{' '}
                            no converted amount and{' '}
                            {report.unconverted === 1 ? 'is' : 'are'} not
                            counted.
                        </Notice>
                    )}
                    {report.unassigned > 0 && (
                        <Notice>
                            {report.unassigned} matching{' '}
                            {report.unassigned === 1
                                ? 'transaction belongs'
                                : 'transactions belong'}{' '}
                            to no release and{' '}
                            {report.unassigned === 1 ? 'is' : 'are'} not
                            counted.
                        </Notice>
                    )}
                    {report.without_quantity_count > 0 && (
                        <Notice>
                            <p className="font-medium">
                                {report.without_quantity_count} matching{' '}
                                {report.without_quantity_count === 1
                                    ? 'transaction has'
                                    : 'transactions have'}{' '}
                                no units and{' '}
                                {report.without_quantity_count === 1
                                    ? 'is'
                                    : 'are'}{' '}
                                not counted. Enter the units to include{' '}
                                {report.without_quantity_count === 1
                                    ? 'it'
                                    : 'them'}
                                .
                            </p>
                            <ul className="list-disc pl-4">
                                {report.without_quantity.map((transaction) => (
                                    <li key={transaction.id}>
                                        <Link
                                            href={TransactionController.edit.url(
                                                transaction.id,
                                                { query: { return_to: url } },
                                            )}
                                            className="underline"
                                        >
                                            {date(transaction.paid_on)} ·{' '}
                                            {transaction.description}
                                        </Link>
                                        {transaction.release_name && (
                                            <>
                                                {' ('}
                                                <RecordLink
                                                    to="release"
                                                    id={transaction.release_id}
                                                >
                                                    {transaction.release_name}
                                                </RecordLink>
                                                )
                                            </>
                                        )}
                                    </li>
                                ))}
                            </ul>
                            {report.without_quantity_count >
                                report.without_quantity.length && (
                                <p>
                                    The first {report.without_quantity.length}{' '}
                                    are listed.
                                </p>
                            )}
                        </Notice>
                    )}

                    <div className="grid gap-4 sm:grid-cols-3">
                        <Stat
                            label="PPD total"
                            value={
                                <Amount
                                    value={report.total}
                                    currency={currency}
                                />
                            }
                        />
                        <Stat label="Units" value={quantity(report.quantity)} />
                        <Stat
                            label="Releases and tracks"
                            value={report.groups.length}
                        />
                    </div>

                    <Panel
                        title="Calculated PPD"
                        actions={
                            report.rows.length > 0 && (
                                <CreateTransactions
                                    form={PpdController.store.form()}
                                    fields={filters}
                                    overlaps={overlaps}
                                    description={`Books the PPD total and its offset for each of the ${report.groups.length} releases and tracks, as one data import.`}
                                >
                                    {(errors) => (
                                        <Field
                                            label="Paid date of the transactions"
                                            htmlFor="paid_on"
                                            error={errors.paid_on}
                                            hint="Decides the period the PPD royalties fall in."
                                            required
                                        >
                                            <Input
                                                id="paid_on"
                                                name="paid_on"
                                                type="date"
                                                defaultValue={
                                                    filters.to ?? today()
                                                }
                                                required
                                            />
                                        </Field>
                                    )}
                                </CreateTransactions>
                            )
                        }
                        flush
                    >
                        {report.rows.length === 0 ? (
                            <EmptyState
                                compact
                                title="No income with units was paid in this range on anything that has a PPD rate."
                            />
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <SortableHead
                                            column="release_name"
                                            sorter={sorter}
                                        >
                                            Release
                                        </SortableHead>
                                        <SortableHead
                                            column="track_name"
                                            sorter={sorter}
                                        >
                                            Track
                                        </SortableHead>
                                        <SortableHead
                                            column="rate_source"
                                            sorter={sorter}
                                        >
                                            Rate from
                                        </SortableHead>
                                        <SortableHead
                                            column="quantity"
                                            sorter={sorter}
                                            first="desc"
                                            className="text-right"
                                        >
                                            Units
                                        </SortableHead>
                                        <SortableHead
                                            column="rate"
                                            sorter={sorter}
                                            first="desc"
                                            className="text-right"
                                        >
                                            Rate
                                        </SortableHead>
                                        <SortableHead
                                            column="amount"
                                            sorter={sorter}
                                            first="desc"
                                            className="text-right"
                                        >
                                            PPD
                                        </SortableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rows.map((row) => (
                                        <TableRow
                                            key={`${row.release_id}-${row.track_id}-${row.rate_source}-${row.item_id}-${row.rate}`}
                                        >
                                            <TableCell>
                                                <RecordLink
                                                    to="release"
                                                    id={row.release_id}
                                                >
                                                    {row.release_name}
                                                </RecordLink>
                                            </TableCell>
                                            <TableCell>
                                                {row.track_name ? (
                                                    <RecordLink
                                                        to="track"
                                                        id={row.track_id}
                                                    >
                                                        {row.track_name}
                                                    </RecordLink>
                                                ) : (
                                                    '–'
                                                )}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {row.rate_source === 'item'
                                                    ? `Item: ${row.item_name}`
                                                    : row.rate_source ===
                                                        'track'
                                                      ? 'Track'
                                                      : 'Release'}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {quantity(row.quantity)}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {plain(row.rate)}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Amount
                                                    value={row.amount}
                                                    currency={currency}
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                                <TableFooter>
                                    <TableRow>
                                        <TableCell colSpan={3}>Total</TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {quantity(report.quantity)}
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

            <RunsPanel
                runs={runs}
                filters={runFilters}
                currency={currency}
                totalLabel="PPD total"
            />
        </Page>
    );
}

Ppd.layout = { breadcrumbs: [{ title: 'PPD', href: index() }] };
