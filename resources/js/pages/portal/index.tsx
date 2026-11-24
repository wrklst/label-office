import { Link, setLayoutProps, usePage } from '@inertiajs/react';
import { Amount } from '@/components/amount';
import { FilterBar } from '@/components/filter-bar';
import { EmptyState, Page, Panel, Stat } from '@/components/page';
import { Pagination } from '@/components/pagination';
import { SortableHead } from '@/components/sortable-head';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useFilters } from '@/hooks/use-filters';
import { serverSorter, useTableSort } from '@/hooks/use-table-sort';
import type { Sorter } from '@/hooks/use-table-sort';
import { date } from '@/lib/format';
import { index, show } from '@/routes/portal';
import type { Paginated } from '@/types';

type Statement = {
    id: number;
    name: string;
    balance: string;
    payable: string;
    period: string | null;
    payee: string | null;
    published_at: string | null;
};

type Overview = {
    statement_id: number;
    period: string;
    published_at: string | null;
    currency: string;
    owed: string;
    earned: string;
    paid: string;
    paid_since: string;
    ledgers: { name: string; balance: string; unrecouped: boolean }[];
};

type Payout = {
    id: number;
    paid_on: string;
    period: string | null;
    total: string;
    memo: string | null;
};

type Group = {
    label: {
        id: number;
        name: string;
        currency: string;
        logo_url: string | null;
    };
    overview: Overview | null;
    payouts: Paginated<Payout>;
    statements: Paginated<Statement>;
};

type Props = {
    view: 'admin' | 'payee' | 'unlinked';
    groups: Group[];
    statements: Paginated<Statement> | null;
    filters: Record<string, string | number | null>;
};

const LEDGER_COLUMNS = {
    name: (row: Overview['ledgers'][number]) => row.name,
    balance: (row: Overview['ledgers'][number]) => row.balance,
};

function OverviewSection({ overview }: { overview: Overview }) {
    const { rows: ledgerRows, sorter } = useTableSort(
        overview.ledgers,
        LEDGER_COLUMNS,
    );
    const currency = overview.currency;

    return (
        <>
            <div className="grid gap-4 sm:grid-cols-3">
                <Stat
                    label="Owed to you"
                    value={<Amount value={overview.owed} currency={currency} />}
                    hint={
                        <>
                            As of {overview.period}
                            {Number(overview.paid_since) > 0 && (
                                <>
                                    . Paid since and not deducted yet:{' '}
                                    <Amount
                                        value={overview.paid_since}
                                        currency={currency}
                                    />
                                </>
                            )}
                        </>
                    }
                />
                <Stat
                    label={`Earned in ${overview.period}`}
                    value={
                        <Amount value={overview.earned} currency={currency} />
                    }
                    hint="Your share after expenses and the contract split."
                />
                <Stat
                    label={`Paid out for ${overview.period}`}
                    value={<Amount value={overview.paid} currency={currency} />}
                    hint={
                        <Link
                            href={show(overview.statement_id)}
                            className="underline underline-offset-4"
                        >
                            Open the statement
                        </Link>
                    }
                />
            </div>

            {overview.ledgers.length > 0 && (
                <Panel
                    flush
                    title="Balance per ledger"
                    description="A ledger below zero is unrecouped: your earnings go towards it first, and it is never set off against your other ledgers."
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <SortableHead column="name" sorter={sorter}>
                                    Ledger
                                </SortableHead>
                                <SortableHead
                                    column="balance"
                                    sorter={sorter}
                                    first="desc"
                                    className="text-right"
                                >
                                    Balance
                                </SortableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {ledgerRows.map((ledger, index) => (
                                <TableRow key={`${index}-${ledger.name}`}>
                                    <TableCell>
                                        <span className="flex items-center gap-2">
                                            {ledger.name}
                                            {ledger.unrecouped && (
                                                <Badge variant="warning">
                                                    Unrecouped
                                                </Badge>
                                            )}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount
                                            value={ledger.balance}
                                            currency={currency}
                                        />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Panel>
            )}
        </>
    );
}

function PayoutsSection({
    payouts,
    sorter,
    currency,
}: {
    payouts: Paginated<Payout>;
    sorter: Sorter;
    currency: string;
}) {
    return (
        <Panel
            flush={payouts.total > 0}
            title="Payouts"
            description="Payments the label has made to you."
        >
            {payouts.total === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No payouts have been recorded for you yet.
                </p>
            ) : (
                <>
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
                                <SortableHead column="period" sorter={sorter}>
                                    For period
                                </SortableHead>
                                <SortableHead column="memo" sorter={sorter}>
                                    Memo
                                </SortableHead>
                                <SortableHead
                                    column="total"
                                    sorter={sorter}
                                    first="desc"
                                    className="text-right"
                                >
                                    Amount
                                </SortableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {payouts.data.map((payout) => (
                                <TableRow key={payout.id}>
                                    <TableCell>
                                        {date(payout.paid_on)}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {payout.period ?? '–'}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {payout.memo ?? '–'}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount
                                            value={payout.total}
                                            currency={currency}
                                        />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <Pagination page={payouts} noun="payouts" />
                </>
            )}
        </Panel>
    );
}

function StatementsTable({
    statements,
    sorter,
    currency,
    withPayee = false,
}: {
    statements: Paginated<Statement>;
    sorter: Sorter;
    currency?: string;
    /** The preview of a label's admin lists every payee's statements, so the rows say whose they are. */
    withPayee?: boolean;
}) {
    return (
        <>
            <Table>
                <TableHeader>
                    <TableRow>
                        {withPayee && (
                            <SortableHead column="payee" sorter={sorter}>
                                Payee
                            </SortableHead>
                        )}
                        <SortableHead column="period" sorter={sorter}>
                            Period
                        </SortableHead>
                        <SortableHead
                            column="published_at"
                            sorter={sorter}
                            first="desc"
                        >
                            Published
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
                        <TableRow key={statement.id}>
                            {withPayee && (
                                <TableCell>{statement.payee ?? '–'}</TableCell>
                            )}
                            <TableCell>
                                <Link
                                    href={show(statement.id)}
                                    className="font-medium underline decoration-muted-foreground/40 underline-offset-4"
                                >
                                    {statement.period ?? statement.name}
                                </Link>
                            </TableCell>
                            <TableCell className="text-muted-foreground">
                                {date(statement.published_at)}
                            </TableCell>
                            <TableCell className="text-right">
                                <Amount
                                    value={statement.balance}
                                    currency={currency}
                                />
                            </TableCell>
                            <TableCell className="text-right">
                                <Amount
                                    value={statement.payable}
                                    currency={currency}
                                />
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
            <Pagination page={statements} noun="statements" />
        </>
    );
}

/**
 * Everything of one label the payee has a statement from: where they
 * stand, what was paid and the statements. Amounts are in the label's
 * currency; they are never added up across labels.
 */
function LabelGroup({
    group,
    filters,
}: {
    group: Group;
    filters: Props['filters'];
}) {
    const { label, overview, payouts, statements } = group;
    const { apply } = useFilters(index.url(), filters);
    const statementSorter = serverSorter(
        filters,
        apply,
        `statements_${label.id}`,
    );
    const payoutSorter = serverSorter(filters, apply, `payouts_${label.id}`);

    return (
        <section
            className="grid gap-6"
            aria-labelledby={`label-${label.id}`}
            data-label-group={label.id}
        >
            <div className="flex items-center gap-3 border-b pb-3">
                {label.logo_url && (
                    <img
                        src={label.logo_url}
                        alt=""
                        className="max-h-10 max-w-32 object-contain"
                    />
                )}
                <h2
                    id={`label-${label.id}`}
                    className="text-lg font-semibold tracking-tight"
                >
                    {label.name}
                </h2>
                <Badge variant="outline">{label.currency}</Badge>
            </div>

            {overview ? (
                <OverviewSection overview={overview} />
            ) : (
                <EmptyState
                    title="Nothing published yet"
                    description="As soon as this label publishes your first statement, you will see here what you earned in that period, what you are owed and the balance of each of your ledgers. Payouts made to you are listed as well."
                />
            )}

            {(overview || payouts.total > 0) && (
                <PayoutsSection
                    payouts={payouts}
                    sorter={payoutSorter}
                    currency={label.currency}
                />
            )}

            {statements.total > 0 && (
                <Panel
                    flush
                    title="Published statements"
                    description="Open a statement for the full breakdown per release and track."
                >
                    <StatementsTable
                        statements={statements}
                        sorter={statementSorter}
                        currency={label.currency}
                    />
                </Panel>
            )}
        </section>
    );
}

/**
 * What the payees of the label the user runs see: the published statements
 * of every payee, to check before they look. Not the user's own.
 */
function Preview({
    statements,
    filters,
    heading,
}: {
    statements: Paginated<Statement>;
    filters: Props['filters'];
    heading?: string;
}) {
    const { apply } = useFilters(index.url(), filters);
    const sorter = serverSorter(filters, apply, 'statements');
    const filtered = filters.period !== null || filters.payee !== null;

    return (
        <section className="grid gap-4" aria-label={heading} data-preview>
            {heading && (
                <h2 className="border-b pb-3 text-lg font-semibold tracking-tight">
                    {heading}
                </h2>
            )}
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
            {statements.total === 0 && !filtered ? (
                <EmptyState
                    title="No statements yet"
                    description="Statements appear here, and in My statements of the payee, as soon as you publish them."
                />
            ) : statements.total === 0 ? (
                <EmptyState title="No statement matches" />
            ) : (
                <Panel flush>
                    <StatementsTable
                        statements={statements}
                        sorter={sorter}
                        withPayee
                    />
                </Panel>
            )}
        </section>
    );
}

export default function Portal({ view, groups, statements, filters }: Props) {
    const title = 'My statements';
    const { label } = usePage().props;

    setLayoutProps({ breadcrumbs: [{ title, href: index() }] });

    if (view === 'admin' && statements) {
        return (
            <Page
                title={title}
                description="What payees can see: every published statement of this label. Each payee only gets their own."
            >
                <Preview statements={statements} filters={filters} />
            </Page>
        );
    }

    if (view === 'unlinked') {
        return (
            <Page
                title={title}
                description="Your royalties from the labels you are a payee of."
            >
                <EmptyState
                    title="Your access is not linked to a payee yet"
                    description="The label has to connect your account to the payee your royalties are booked on. Ask them to do that in their team settings; your statements and payouts will show up here afterwards."
                />
            </Page>
        );
    }

    const both = statements !== null;

    return (
        <Page
            title={title}
            description={
                both
                    ? 'Your own statements from the labels you are a payee of, and a preview of what the payees of the label you work in see.'
                    : 'What you earned, what you are owed and what you have been paid, according to the statements each of your labels has published.'
            }
            className="gap-10"
        >
            {both && (
                <h2 className="-mb-6 text-xl font-semibold tracking-tight">
                    Your statements as a payee
                </h2>
            )}
            {groups.map((group) => (
                <LabelGroup
                    key={group.label.id}
                    group={group}
                    filters={filters}
                />
            ))}
            {statements && (
                <Preview
                    heading={`What the payees of ${label?.name ?? 'this label'} see`}
                    statements={statements}
                    filters={filters}
                />
            )}
        </Page>
    );
}

Portal.layout = { breadcrumbs: [{ title: 'My statements', href: index() }] };
