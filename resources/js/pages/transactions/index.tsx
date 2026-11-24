import { Link, usePage } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import TransactionController from '@/actions/App/Http/Controllers/TransactionController';
import { Amount } from '@/components/amount';
import { DeleteButton } from '@/components/delete-button';
import { FilterBar } from '@/components/filter-bar';
import { EmptyState, Page, Panel } from '@/components/page';
import { Pagination } from '@/components/pagination';
import { RecordLink } from '@/components/record-link';
import { SortableHead } from '@/components/sortable-head';
import { Badge } from '@/components/ui/badge';
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
import { count, date, money, quantity } from '@/lib/format';
import { create, edit, index } from '@/routes/transactions';
import type { Option, Paginated } from '@/types';

type Transaction = {
    id: number;
    type: 'income' | 'expense' | 'transfer';
    timestamp: string;
    paid_timestamp: string;
    total: string;
    converted_total: string | null;
    currency: string;
    quantity: string | null;
    category: string | null;
    format: string | null;
    region: string | null;
    retailer: string | null;
    memo: string | null;
    recoupable: boolean;
    release: Option | null;
    track: string | null;
    track_id: number | null;
    payee: string | null;
    payee_id: number | null;
};

type Filters = {
    search?: string;
    type?: string;
    release?: string;
    track?: string;
    retailer?: string;
    import?: string;
    from?: string;
    to?: string;
    date?: string;
    unassigned?: string;
    unconverted?: string;
    sort?: string | null;
    direction?: SortDirection | null;
};

type Props = {
    transactions: Paginated<Transaction>;
    summary: { transactions: number; total: string; quantity: string };
    filters: Filters;
    platformOptions: { id: string; name: string }[];
    importOptions: Option[];
};

export default function Transactions({
    transactions,
    summary,
    filters,
    platformOptions,
    importOptions,
}: Props) {
    const { url } = usePage();
    const { apply } = useFilters(index.url(), filters);
    const sorter = serverSorter(filters, apply);
    const filtered = Object.entries(filters).some(
        ([key, value]) =>
            key !== 'sort' &&
            key !== 'direction' &&
            value !== undefined &&
            value !== null &&
            value !== '',
    );

    return (
        <Page
            title="Transactions"
            description="Every line of income, every expense and every transfer. Expenses are listed as negative amounts, though you type them as positive; a negative transfer was given to the payee. A label cost is an expense the label bears alone."
            actions={
                <>
                    <Button variant="outline" asChild>
                        <Link
                            href={create({
                                query: {
                                    type: 'expense',
                                    recoupable: 0,
                                    return_to: url,
                                },
                            })}
                        >
                            <Plus />
                            New label cost
                        </Link>
                    </Button>
                    <Button variant="outline" asChild>
                        <Link
                            href={create({
                                query: { type: 'expense', return_to: url },
                            })}
                        >
                            <Plus />
                            New expense
                        </Link>
                    </Button>
                    <Button variant="outline" asChild>
                        <Link
                            href={create({
                                query: { type: 'transfer', return_to: url },
                            })}
                        >
                            <Plus />
                            New transfer
                        </Link>
                    </Button>
                    <Button asChild>
                        <Link
                            href={create({
                                query: { type: 'income', return_to: url },
                            })}
                        >
                            <Plus />
                            New income
                        </Link>
                    </Button>
                </>
            }
        >
            <FilterBar
                url={index.url()}
                filters={filters}
                search="Search memo, platform, track, release, ISRC…"
                selects={[
                    {
                        name: 'type',
                        label: 'Type',
                        placeholder: 'All types',
                        options: ['income', 'expense', 'transfer'],
                        className: 'w-full capitalize sm:w-36',
                    },
                    {
                        name: 'release',
                        label: 'Release',
                        placeholder: 'All releases',
                        records: 'releases',
                        className: 'w-full sm:w-64',
                    },
                    {
                        name: 'track',
                        label: 'Track',
                        placeholder: 'All tracks',
                        records: 'tracks',
                        className: 'w-full sm:w-64',
                    },
                    {
                        name: 'retailer',
                        label: 'Platform',
                        placeholder: 'All platforms',
                        options: platformOptions,
                        className: 'w-full sm:w-48',
                    },
                    {
                        name: 'import',
                        label: 'Import',
                        placeholder: 'All imports',
                        options: importOptions,
                        className: 'w-full sm:w-56',
                    },
                ]}
                checks={[
                    { name: 'unassigned', label: 'Unassigned' },
                    { name: 'unconverted', label: 'Unconverted' },
                ]}
                dates
            />

            {transactions.total === 0 ? (
                <EmptyState
                    title={
                        filtered
                            ? 'No transaction matches these filters'
                            : 'No transactions yet'
                    }
                    description={
                        filtered
                            ? undefined
                            : 'Import a statement or add income, an expense or a transfer by hand.'
                    }
                />
            ) : (
                <Panel flush>
                    <div className="flex flex-wrap gap-x-8 gap-y-1 border-b px-4 py-3 text-sm">
                        <span>
                            <span className="text-muted-foreground">
                                Matching{' '}
                            </span>
                            <span className="font-medium tabular-nums">
                                {count(summary.transactions)}
                            </span>
                        </span>
                        <span>
                            <span className="text-muted-foreground">
                                Units{' '}
                            </span>
                            <span className="font-medium tabular-nums">
                                {quantity(summary.quantity)}
                            </span>
                        </span>
                        <span>
                            <span className="text-muted-foreground">
                                Total{' '}
                            </span>
                            <Amount
                                value={summary.total}
                                className="font-medium"
                            />
                        </span>
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <SortableHead
                                    column="timestamp"
                                    sorter={sorter}
                                    first="desc"
                                >
                                    Sold
                                </SortableHead>
                                <SortableHead
                                    column="paid_timestamp"
                                    sorter={sorter}
                                    first="desc"
                                >
                                    Paid date
                                </SortableHead>
                                <SortableHead column="type" sorter={sorter}>
                                    Type
                                </SortableHead>
                                <SortableHead column="release" sorter={sorter}>
                                    Release / track
                                </SortableHead>
                                <SortableHead column="retailer" sorter={sorter}>
                                    Platform
                                </SortableHead>
                                <SortableHead column="format" sorter={sorter}>
                                    Format / category
                                </SortableHead>
                                <SortableHead column="region" sorter={sorter}>
                                    Country
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
                                    column="converted_total"
                                    sorter={sorter}
                                    first="desc"
                                    className="text-right"
                                >
                                    Amount
                                </SortableHead>
                                <TableHead className="w-20" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {transactions.data.map((transaction) => (
                                <TableRow key={transaction.id}>
                                    <TableCell className="whitespace-nowrap">
                                        {date(transaction.timestamp)}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap text-muted-foreground">
                                        {date(transaction.paid_timestamp)}
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant={
                                                transaction.type === 'income'
                                                    ? 'success'
                                                    : transaction.type ===
                                                        'transfer'
                                                      ? 'info'
                                                      : 'outline'
                                            }
                                            className="capitalize"
                                        >
                                            {transaction.type}
                                        </Badge>
                                        {transaction.type === 'expense' &&
                                            !transaction.recoupable && (
                                                <Badge
                                                    variant="outline"
                                                    className="ml-1"
                                                    title="Borne by the label alone, not recouped from payees"
                                                >
                                                    label cost
                                                </Badge>
                                            )}
                                    </TableCell>
                                    <TableCell className="max-w-xs">
                                        {transaction.type === 'transfer' ? (
                                            <span>
                                                <RecordLink
                                                    to="payee"
                                                    id={transaction.payee_id}
                                                >
                                                    {transaction.payee}
                                                </RecordLink>
                                                {transaction.release && (
                                                    <span className="block truncate text-xs text-muted-foreground">
                                                        <RecordLink
                                                            to="release"
                                                            id={
                                                                transaction
                                                                    .release.id
                                                            }
                                                        >
                                                            {
                                                                transaction
                                                                    .release
                                                                    .name
                                                            }
                                                        </RecordLink>
                                                    </span>
                                                )}
                                            </span>
                                        ) : transaction.release ? (
                                            <span>
                                                <span className="block truncate">
                                                    {transaction.track ? (
                                                        <RecordLink
                                                            to="track"
                                                            id={
                                                                transaction.track_id
                                                            }
                                                        >
                                                            {transaction.track}
                                                        </RecordLink>
                                                    ) : (
                                                        <RecordLink
                                                            to="release"
                                                            id={
                                                                transaction
                                                                    .release.id
                                                            }
                                                        >
                                                            {
                                                                transaction
                                                                    .release
                                                                    .name
                                                            }
                                                        </RecordLink>
                                                    )}
                                                </span>
                                                {transaction.track && (
                                                    <span className="block truncate text-xs text-muted-foreground">
                                                        <RecordLink
                                                            to="release"
                                                            id={
                                                                transaction
                                                                    .release.id
                                                            }
                                                        >
                                                            {
                                                                transaction
                                                                    .release
                                                                    .name
                                                            }
                                                        </RecordLink>
                                                    </span>
                                                )}
                                            </span>
                                        ) : transaction.type === 'expense' &&
                                          !transaction.recoupable ? (
                                            <Badge variant="outline">
                                                Overhead
                                            </Badge>
                                        ) : (
                                            <Badge variant="destructive">
                                                Unassigned
                                            </Badge>
                                        )}
                                        {transaction.memo && (
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {transaction.memo}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {transaction.retailer ?? '–'}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {transaction.format ??
                                            transaction.category ??
                                            '–'}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {transaction.region ?? '–'}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {quantity(transaction.quantity)}
                                    </TableCell>
                                    <TableCell className="text-right whitespace-nowrap">
                                        {transaction.converted_total ===
                                        null ? (
                                            <Badge variant="destructive">
                                                {money(
                                                    transaction.total,
                                                    transaction.currency,
                                                )}{' '}
                                                · no rate
                                            </Badge>
                                        ) : (
                                            <Amount
                                                value={
                                                    transaction.converted_total
                                                }
                                                precise
                                            />
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex justify-end">
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                asChild
                                            >
                                                <Link
                                                    href={edit(transaction.id, {
                                                        query: {
                                                            return_to: url,
                                                        },
                                                    })}
                                                    aria-label="Edit transaction"
                                                >
                                                    <Pencil className="text-muted-foreground" />
                                                </Link>
                                            </Button>
                                            <DeleteButton
                                                url={TransactionController.destroy.url(
                                                    transaction.id,
                                                )}
                                                title={`Delete ${transaction.type} of ${date(transaction.paid_timestamp)}?`}
                                                description="It is removed from every period and balance it counted in. Statements already generated keep their figures."
                                            />
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <Pagination page={transactions} noun="transactions" />
                </Panel>
            )}
        </Page>
    );
}

Transactions.layout = {
    breadcrumbs: [{ title: 'Transactions', href: index() }],
};
