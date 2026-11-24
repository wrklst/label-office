import { Link, router, usePage } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useState } from 'react';
import MechanicalsController from '@/actions/App/Http/Controllers/RoyaltyTools/MechanicalsController';
import RatesController from '@/actions/App/Http/Controllers/RoyaltyTools/RatesController';
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
import { quantity, today } from '@/lib/format';
import { index as items } from '@/routes/items';
import { edit as labelSettings } from '@/routes/label-settings';
import { index } from '@/routes/royalty-tools/mechanicals';
import type { Paginated } from '@/types';

type Row = {
    release_id: number;
    release_name: string;
    track_id: number;
    track_name: string;
    sold: string;
    manufactured: string;
    units: string;
    rate: string;
    rate_source: 'track' | 'release' | 'statutory';
    amount: string;
};

type Report = {
    rows: Row[];
    total: string;
    units: string;
    uncalculable: {
        release_id: number;
        release_name: string;
        track_id: number;
        track_name: string;
        reason: string;
    }[];
    unconverted: number;
    without_quantity: number;
    unused_manufacturing: string[];
};

type Manufacturing = { release_id: number | string; quantity: number | string };

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
        rates: string;
        manufacturing: Manufacturing[];
    };
    settings: {
        usd_rate: string | null;
        include_physical: boolean;
        ignore_negative_quantities: boolean;
    };
    rateTables: { key: string; per_work: string; per_minute: string }[];
    report: Report | null;
};

const NO_ROWS: Row[] = [];

const ROW_COLUMNS = {
    release_name: (row: Row) => row.release_name,
    track_name: (row: Row) => row.track_name,
    sold: (row: Row) => row.sold,
    manufactured: (row: Row) => row.manufactured,
    units: (row: Row) => row.units,
    rate: (row: Row) => row.rate,
    amount: (row: Row) => row.amount,
};

const SOURCE = {
    track: 'track rate',
    release: 'release rate',
    statutory: 'statutory',
};

const toCents = (dollars: string) => plain((Number(dollars) * 100).toFixed(4));

export default function Mechanicals({
    currency,
    runs,
    runFilters,
    overlaps,
    filters,
    settings,
    rateTables,
    report,
}: Props) {
    const [manufacturing, setManufacturing] = useState<Manufacturing[]>(
        filters.manufacturing,
    );
    const [ratesRelease, setRatesRelease] = useState('');
    const { rows, sorter } = useTableSort(report?.rows ?? NO_ROWS, ROW_COLUMNS);
    const [loading, setLoading] = useState(false);
    const errors = usePage().props.errors as Record<string, string>;

    // The rows of manufacturing runs must reach the server with their
    // indexes, so the query is built from the fields as they are named.
    const showReport = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        const query = new URLSearchParams();
        new FormData(event.currentTarget).forEach((value, name) => {
            if (typeof value === 'string' && value !== '') {
                query.append(name, value);
            }
        });

        router.get(
            `${index.url()}?${query.toString()}`,
            {},
            {
                preserveScroll: true,
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
            },
        );
    };

    const booked: Record<string, string | number | null> = {
        from: filters.from,
        to: filters.to,
        rates: filters.rates,
    };
    filters.manufacturing.forEach((run, position) => {
        booked[`manufacturing[${position}][release_id]`] = run.release_id;
        booked[`manufacturing[${position}][quantity]`] = run.quantity;
    });

    return (
        <Page
            title="Mechanicals"
            description="Calculate mechanical royalties owed to songwriters for digital downloads and physical manufacturing or sales. Nothing is calculated on its own: pick the paid dates, check the report, then book the transactions."
        >
            <Panel
                title="How it is booked"
                description="The transactions are ordinary ones and flow through contracts, statements and ledgers like any other."
            >
                <div className="grid max-w-3xl gap-2 text-sm text-muted-foreground">
                    <p>
                        For every track with units, the tool books an expense on
                        the item “Track [Mechanicals Expenses]” and the same
                        amount as income on “Track [Mechanicals Income]”. Both
                        items start without a contract of their own, so they
                        follow the track or release and the pair cancels out for
                        everyone.
                    </p>
                    <p>
                        To pay a songwriter, give the income item a publishing
                        contract, usually with its own ledger, on the{' '}
                        <Link
                            href={items({ query: { search: '[Mechanicals' } })}
                            className="underline"
                        >
                            Items page
                        </Link>
                        . The expense item keeps charging whoever bears the cost
                        under the contracts of the release's tracks.
                    </p>
                    <p>
                        Units are downloads
                        {settings.include_physical
                            ? ' and physical sales'
                            : ' (physical sales are not counted)'}{' '}
                        paid in the range
                        {settings.ignore_negative_quantities
                            ? '; returns are ignored'
                            : '; returns reduce them'}
                        . Change this in the{' '}
                        <Link href={labelSettings()} className="underline">
                            label settings
                        </Link>
                        . A Harry Fox Agency file is not produced.
                    </p>
                </div>
            </Panel>

            <Panel
                title="Rates and tracks"
                description="Switch releases or single tracks on and give them their own rate per unit. Without one, the statutory rate for the track’s length is used."
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
                                    tool: 'mechanicals',
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
                <form onSubmit={showReport} className="grid gap-4">
                    <div className="grid gap-4 sm:grid-cols-3">
                        <DateRange defaults={filters} errors={errors} />
                        <Field
                            label="Statutory rates"
                            htmlFor="rates"
                            error={errors.rates}
                            hint="US rates, used for tracks without their own rate."
                        >
                            <SelectInput
                                id="rates"
                                name="rates"
                                defaultValue={filters.rates}
                            >
                                {rateTables.map((table) => (
                                    <option key={table.key} value={table.key}>
                                        {table.key}: {toCents(table.per_work)}¢
                                        per work, {toCents(table.per_minute)}¢
                                        per minute over 5:00
                                    </option>
                                ))}
                            </SelectInput>
                        </Field>
                    </div>

                    <div className="grid gap-2">
                        <p className="text-sm font-medium">
                            Manufacturing runs
                        </p>
                        <p className="text-xs text-muted-foreground">
                            Copies manufactured in the range. They are added as
                            units for every track of the release that accounts
                            mechanicals.
                        </p>
                        {manufacturing.map((run, position) => (
                            <div
                                key={position}
                                className="grid gap-2 sm:grid-cols-[1fr_10rem_2.25rem]"
                            >
                                <RecordSelect
                                    aria-label="Manufactured release"
                                    name={`manufacturing[${position}][release_id]`}
                                    records="releases"
                                    value={run.release_id}
                                    onChange={(id) =>
                                        setManufacturing(
                                            manufacturing.map((current, at) =>
                                                at === position
                                                    ? {
                                                          ...current,
                                                          release_id:
                                                              String(id),
                                                      }
                                                    : current,
                                            ),
                                        )
                                    }
                                    placeholder="Release…"
                                    required
                                />
                                <Input
                                    aria-label="Copies manufactured"
                                    name={`manufacturing[${position}][quantity]`}
                                    type="number"
                                    min="1"
                                    step="1"
                                    placeholder="Copies"
                                    value={run.quantity}
                                    onChange={(event) =>
                                        setManufacturing(
                                            manufacturing.map((current, at) =>
                                                at === position
                                                    ? {
                                                          ...current,
                                                          quantity:
                                                              event.target
                                                                  .value,
                                                      }
                                                    : current,
                                            ),
                                        )
                                    }
                                    required
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Remove manufacturing run"
                                    onClick={() =>
                                        setManufacturing(
                                            manufacturing.filter(
                                                (_, at) => at !== position,
                                            ),
                                        )
                                    }
                                >
                                    <X />
                                </Button>
                            </div>
                        ))}
                        {Object.entries(errors)
                            .filter(([key]) => key.startsWith('manufacturing'))
                            .map(([key, message]) => (
                                <p
                                    key={key}
                                    className="text-sm text-red-600 dark:text-red-400"
                                >
                                    {message}
                                </p>
                            ))}
                        <div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    setManufacturing([
                                        ...manufacturing,
                                        {
                                            release_id: '',
                                            quantity: '',
                                        },
                                    ])
                                }
                            >
                                <Plus />
                                Add manufacturing run
                            </Button>
                        </div>
                    </div>

                    <div>
                        <Button disabled={loading}>Show report</Button>
                    </div>
                </form>
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
                    {report.without_quantity > 0 && (
                        <Notice>
                            {report.without_quantity} matching{' '}
                            {report.without_quantity === 1
                                ? 'transaction has'
                                : 'transactions have'}{' '}
                            no units and{' '}
                            {report.without_quantity === 1 ? 'is' : 'are'} not
                            counted.
                        </Notice>
                    )}
                    {report.unused_manufacturing.length > 0 && (
                        <Notice>
                            No track accounts mechanicals on{' '}
                            {report.unused_manufacturing.join(', ')}, so the
                            manufacturing run entered for it adds nothing.
                        </Notice>
                    )}
                    {report.uncalculable.length > 0 && (
                        <Notice>
                            <p className="font-medium">
                                {report.uncalculable.length}{' '}
                                {report.uncalculable.length === 1
                                    ? 'track cannot'
                                    : 'tracks cannot'}{' '}
                                be calculated.
                            </p>
                            <ul className="list-disc pl-4">
                                {report.uncalculable.map((track) => (
                                    <li key={track.track_id}>
                                        <Link
                                            href={RatesController.edit.url({
                                                tool: 'mechanicals',
                                                release: track.release_id,
                                            })}
                                            className="underline"
                                        >
                                            {track.release_name} –{' '}
                                            {track.track_name}
                                        </Link>
                                        : {track.reason}
                                    </li>
                                ))}
                            </ul>
                        </Notice>
                    )}

                    <div className="grid gap-4 sm:grid-cols-3">
                        <Stat
                            label="Mechanicals owed"
                            value={
                                <Amount
                                    value={report.total}
                                    currency={currency}
                                />
                            }
                        />
                        <Stat label="Units" value={quantity(report.units)} />
                        <Stat label="Tracks" value={report.rows.length} />
                    </div>

                    <Panel
                        title="Calculated mechanicals"
                        actions={
                            report.rows.length > 0 && (
                                <CreateTransactions
                                    form={MechanicalsController.store.form()}
                                    fields={booked}
                                    overlaps={overlaps}
                                    description={`Books an expense and an income line of the same amount for each of the ${report.rows.length} tracks, as one data import.`}
                                >
                                    {(errors) => (
                                        <Field
                                            label="Paid date of the transactions"
                                            htmlFor="paid_on"
                                            error={errors.paid_on}
                                            hint="Decides the period the mechanicals fall in."
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
                                title="No units were found for tracks that account mechanicals in this range."
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
                                            column="sold"
                                            sorter={sorter}
                                            first="desc"
                                            className="text-right"
                                        >
                                            Sold
                                        </SortableHead>
                                        <SortableHead
                                            column="manufactured"
                                            sorter={sorter}
                                            first="desc"
                                            className="text-right"
                                        >
                                            Manufactured
                                        </SortableHead>
                                        <SortableHead
                                            column="units"
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
                                            Mechanicals
                                        </SortableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rows.map((row) => (
                                        <TableRow key={row.track_id}>
                                            <TableCell>
                                                <RecordLink
                                                    to="release"
                                                    id={row.release_id}
                                                >
                                                    {row.release_name}
                                                </RecordLink>
                                            </TableCell>
                                            <TableCell className="font-medium">
                                                <RecordLink
                                                    to="track"
                                                    id={row.track_id}
                                                >
                                                    {row.track_name}
                                                </RecordLink>
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {quantity(row.sold)}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {quantity(row.manufactured)}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {quantity(row.units)}
                                            </TableCell>
                                            <TableCell className="text-right whitespace-nowrap tabular-nums">
                                                {plain(row.rate)}{' '}
                                                <span className="text-xs text-muted-foreground">
                                                    {SOURCE[row.rate_source]}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Amount
                                                    value={row.amount}
                                                    currency={currency}
                                                    precise
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                                <TableFooter>
                                    <TableRow>
                                        <TableCell colSpan={4}>Total</TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {quantity(report.units)}
                                        </TableCell>
                                        <TableCell />
                                        <TableCell className="text-right">
                                            <Amount
                                                value={report.total}
                                                currency={currency}
                                                precise
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
                totalLabel="Mechanicals"
            />
        </Page>
    );
}

Mechanicals.layout = {
    breadcrumbs: [{ title: 'Mechanicals', href: index() }],
};
