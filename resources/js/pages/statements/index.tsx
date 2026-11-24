import { Link } from '@inertiajs/react';
import { FilterBar } from '@/components/filter-bar';
import { Amount } from '@/components/amount';
import { EmptyState, Page, Panel } from '@/components/page';
import { Pagination } from '@/components/pagination';
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
import { date } from '@/lib/format';
import { index as portal } from '@/routes/portal';
import { index as periods } from '@/routes/periods';
import { index, show } from '@/routes/statements';
import type { Paginated } from '@/types';

type Statement = {
    id: number;
    name: string;
    archived: boolean;
    balance: string;
    payable: string;
    revision_of_id: number | null;
    payee: string | null;
    period: string | null;
    published: boolean;
    created_at: string;
};

export default function Statements({
    statements,
    filters,
}: {
    statements: Paginated<Statement>;
    filters: {
        archived: boolean;
        period: number | null;
        payee: number | null;
        sort: string | null;
        direction: SortDirection | null;
    };
}) {
    const filtered =
        filters.archived || filters.period !== null || filters.payee !== null;
    const { apply } = useFilters(index.url(), filters);
    const sorter = serverSorter(filters, apply);

    return (
        <Page
            title="Statements"
            description="A statement is a frozen copy of a payee's figures for a period. Later changes to transactions or contracts do not alter it; regenerate it to get a new revision. My statements previews what your payees see of the published ones, and also lists your own statements if you are a payee of another label."
            actions={
                <Button variant="outline" asChild>
                    <Link href={portal()}>My statements</Link>
                </Button>
            }
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
                checks={[{ name: 'archived', label: 'Archived' }]}
            />

            {statements.total === 0 ? (
                <EmptyState
                    title={
                        filtered
                            ? 'No statement matches these filters'
                            : 'No statements yet'
                    }
                    description={
                        filtered
                            ? undefined
                            : 'Statements are generated from a period.'
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
                                <SortableHead column="payee" sorter={sorter}>
                                    Payee
                                </SortableHead>
                                <SortableHead column="period" sorter={sorter}>
                                    Period
                                </SortableHead>
                                <TableHead>Status</TableHead>
                                <SortableHead
                                    column="created_at"
                                    sorter={sorter}
                                    first="desc"
                                >
                                    Generated
                                </SortableHead>
                                <SortableHead
                                    column="balance"
                                    sorter={sorter}
                                    first="desc"
                                    className="text-right"
                                >
                                    Balance
                                </SortableHead>
                                <SortableHead
                                    column="payable"
                                    sorter={sorter}
                                    first="desc"
                                    className="text-right"
                                >
                                    Payable
                                </SortableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {statements.data.map((statement) => (
                                <TableRow
                                    key={statement.id}
                                    className={
                                        statement.archived
                                            ? 'text-muted-foreground'
                                            : ''
                                    }
                                >
                                    <TableCell>
                                        <Link
                                            href={show(statement.id)}
                                            className="font-medium underline decoration-muted-foreground/40 underline-offset-4"
                                        >
                                            {statement.payee ?? statement.name}
                                        </Link>
                                    </TableCell>
                                    <TableCell>{statement.period}</TableCell>
                                    <TableCell>
                                        <span className="flex gap-1">
                                            {statement.archived ? (
                                                <Badge variant="outline">
                                                    Archived
                                                </Badge>
                                            ) : statement.published ? (
                                                <Badge variant="success">
                                                    Published
                                                </Badge>
                                            ) : (
                                                <Badge variant="warning">
                                                    Draft
                                                </Badge>
                                            )}
                                            {statement.revision_of_id &&
                                                !statement.archived && (
                                                    <Badge variant="info">
                                                        Revised
                                                    </Badge>
                                                )}
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        {date(statement.created_at)}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount value={statement.balance} />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount value={statement.payable} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <Pagination page={statements} noun="statements" />
                </Panel>
            )}
        </Page>
    );
}

Statements.layout = { breadcrumbs: [{ title: 'Statements', href: index() }] };
