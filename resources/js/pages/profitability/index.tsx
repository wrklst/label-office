import { Link } from '@inertiajs/react';
import { useMemo } from 'react';
import { Download } from 'lucide-react';
import { FilterBar } from '@/components/filter-bar';
import { Amount } from '@/components/amount';
import { EmptyState, Notice, Page, Panel, Stat } from '@/components/page';
import { RecordLink } from '@/components/record-link';
import { SortableHead } from '@/components/sortable-head';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTableSort } from '@/hooks/use-table-sort';
import { percent, plural } from '@/lib/format';
import { download, index } from '@/routes/profitability';
import { show as showRelease } from '@/routes/releases';
import { index as transactions } from '@/routes/transactions';

type Figures = {
    income: string;
    expense: string;
    net: string;
    payee_share: string;
    label_costs: string;
    label_result: string;
    margin: string | null;
    advances: string;
};

type Row = Figures & {
    release_id: number | null;
    release_name: string;
    catalog_number: string | null;
    artist_name: string | null;
    artist_id: number | null;
};

type Filters = {
    from: string | null;
    to: string | null;
    date: 'paid' | null;
    artist: number | null;
};

type Props = {
    currency: string;
    rows: Row[];
    totals: Figures;
    unconverted: number;
    filters: Filters;
};

const COLUMNS: { key: keyof Figures; label: string; hint: string }[] = [
    {
        key: 'income',
        label: 'Income',
        hint: 'Everything the release brought in.',
    },
    {
        key: 'expense',
        label: 'Recoupable expenses',
        hint: 'Costs deducted before the split, so payees carry their share.',
    },
    { key: 'net', label: 'Net', hint: 'Income plus recoupable expenses.' },
    {
        key: 'payee_share',
        label: 'Payee share',
        hint: 'What payees earn from the net under the contracts that apply.',
    },
    {
        key: 'label_costs',
        label: 'Label costs',
        hint: 'Every expense the label bears alone, with or without a release.',
    },
    {
        key: 'label_result',
        label: 'Label result',
        hint: 'Net minus payee share, less the label costs.',
    },
    {
        key: 'margin',
        label: 'Margin',
        hint: 'Label result as a share of income.',
    },
    {
        key: 'advances',
        label: 'Advances given',
        hint: 'Prepaid royalties, not a cost: not part of the label result.',
    },
];

const SORT_COLUMNS = {
    release: (row: Row) => row.release_name,
    income: (row: Row) => row.income,
    expense: (row: Row) => row.expense,
    net: (row: Row) => row.net,
    payee_share: (row: Row) => row.payee_share,
    label_costs: (row: Row) => row.label_costs,
    label_result: (row: Row) => row.label_result,
    margin: (row: Row) => row.margin,
    advances: (row: Row) => row.advances,
};

function FigureCells({
    figures,
    currency,
}: {
    figures: Figures;
    currency: string;
}) {
    return (
        <>
            <TableCell className="text-right">
                <Amount value={figures.income} currency={currency} />
            </TableCell>
            <TableCell className="text-right">
                <Amount value={figures.expense} currency={currency} />
            </TableCell>
            <TableCell className="text-right">
                <Amount value={figures.net} currency={currency} />
            </TableCell>
            <TableCell className="text-right">
                <Amount value={figures.payee_share} currency={currency} />
            </TableCell>
            <TableCell className="text-right">
                <Amount value={figures.label_costs} currency={currency} />
            </TableCell>
            <TableCell className="text-right font-medium">
                <Amount value={figures.label_result} currency={currency} />
            </TableCell>
            <TableCell className="text-right text-muted-foreground tabular-nums">
                {percent(figures.margin)}
            </TableCell>
            <TableCell className="text-right">
                <Amount value={figures.advances} currency={currency} muted />
            </TableCell>
        </>
    );
}

export default function Profitability({
    currency,
    rows,
    totals,
    unconverted,
    filters,
}: Props) {
    const filtered =
        filters.from !== null || filters.to !== null || filters.artist !== null;
    // "Not assigned to a release" and "Overheads" stay below the
    // releases however those are sorted.
    const releaseRows = useMemo(
        () => rows.filter((row) => row.release_id !== null),
        [rows],
    );
    const otherRows = rows.filter((row) => row.release_id === null);
    const { rows: sortedRows, sorter } = useTableSort(
        releaseRows,
        SORT_COLUMNS,
    );
    const query = Object.fromEntries(
        Object.entries(filters).filter(([, value]) => value !== null),
    );

    return (
        <Page
            title="Profitability"
            description="What the label is left with per release: income, minus recoupable expenses, minus what payees earn, minus the costs the label bears alone such as one-off costs. Advances are prepaid royalties, not costs, so they are listed for information and are not part of the label result."
            actions={
                <Button variant="outline" asChild>
                    <a href={download.url({ query })}>
                        <Download />
                        Download CSV
                    </a>
                </Button>
            }
        >
            <FilterBar
                url={index.url()}
                filters={filters}
                dates
                selects={[
                    {
                        name: 'artist',
                        label: 'Artist',
                        placeholder: 'All artists',
                        records: 'artists',
                    },
                ]}
            />

            {unconverted > 0 && (
                <Notice>
                    <Link
                        href={transactions({ query: { unconverted: 1 } })}
                        className="underline underline-offset-4"
                    >
                        {plural(unconverted, 'transaction')} without a
                        conversion rate
                    </Link>{' '}
                    {unconverted === 1 ? 'is' : 'are'} not counted here.
                </Notice>
            )}

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Stat
                    label="Income"
                    value={<Amount value={totals.income} currency={currency} />}
                    hint={
                        <>
                            Recoupable expenses{' '}
                            <Amount
                                value={totals.expense}
                                currency={currency}
                            />
                        </>
                    }
                />
                <Stat
                    label="Payee share"
                    value={
                        <Amount
                            value={totals.payee_share}
                            currency={currency}
                        />
                    }
                    hint={
                        <>
                            of <Amount value={totals.net} currency={currency} />{' '}
                            net
                        </>
                    }
                />
                <Stat
                    label="Label costs"
                    value={
                        <Amount
                            value={totals.label_costs}
                            currency={currency}
                        />
                    }
                    hint="Borne by the label alone"
                />
                <Stat
                    label="Label result"
                    value={
                        <Amount
                            value={totals.label_result}
                            currency={currency}
                        />
                    }
                    hint={
                        totals.margin === null
                            ? 'No income in this range'
                            : `${percent(totals.margin)} of income`
                    }
                />
            </div>

            {rows.length === 0 ? (
                <EmptyState
                    title={
                        filtered
                            ? 'Nothing was booked in this range'
                            : 'No transactions yet'
                    }
                    description="Income, expenses and label costs show up here once they are booked."
                />
            ) : (
                <Panel flush>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <SortableHead column="release" sorter={sorter}>
                                    Release
                                </SortableHead>
                                {COLUMNS.map((column) => (
                                    <SortableHead
                                        key={column.key}
                                        column={column.key}
                                        sorter={sorter}
                                        first="desc"
                                        title={column.hint}
                                        className="text-right"
                                    >
                                        {column.label}
                                    </SortableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {[...sortedRows, ...otherRows].map((row) => (
                                <TableRow
                                    key={row.release_id ?? row.release_name}
                                >
                                    <TableCell className="max-w-xs">
                                        {row.release_id === null ? (
                                            <span className="text-muted-foreground">
                                                {row.release_name}
                                            </span>
                                        ) : (
                                            <Link
                                                href={showRelease(
                                                    row.release_id,
                                                )}
                                                className="block truncate underline decoration-muted-foreground/40 underline-offset-4"
                                            >
                                                {row.release_name}
                                            </Link>
                                        )}
                                        {(row.artist_name ||
                                            row.catalog_number) && (
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {row.artist_name && (
                                                    <RecordLink
                                                        to="artist"
                                                        id={row.artist_id}
                                                    >
                                                        {row.artist_name}
                                                    </RecordLink>
                                                )}
                                                {row.artist_name &&
                                                    row.catalog_number &&
                                                    ' · '}
                                                {row.catalog_number}
                                            </span>
                                        )}
                                    </TableCell>
                                    <FigureCells
                                        figures={row}
                                        currency={currency}
                                    />
                                </TableRow>
                            ))}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell className="font-medium">
                                    Total
                                </TableCell>
                                <FigureCells
                                    figures={totals}
                                    currency={currency}
                                />
                            </TableRow>
                        </TableFooter>
                    </Table>
                    <p className="border-t px-4 py-3 text-xs text-muted-foreground">
                        Label result = net − payee share + label costs (costs
                        are negative). Advances given are royalties paid ahead;
                        they are recouped from the payee share and are money the
                        label has out until then, not a cost of the release.
                    </p>
                </Panel>
            )}
        </Page>
    );
}

Profitability.layout = {
    breadcrumbs: [{ title: 'Profitability', href: index() }],
};
