import { AgreementLinks } from '@/components/agreement-links';
import { Link, setLayoutProps, usePage } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import PayeeController from '@/actions/App/Http/Controllers/PayeeController';
import { Amount } from '@/components/amount';
import { DeleteButton } from '@/components/delete-button';
import { FormDialog } from '@/components/form-dialog';
import { EmptyState, Page, Panel, Stat } from '@/components/page';
import { Pagination } from '@/components/pagination';
import { RecordLink } from '@/components/record-link';
import { SortableHead } from '@/components/sortable-head';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useFilters } from '@/hooks/use-filters';
import { serverSorter, useTableSort } from '@/hooks/use-table-sort';
import { date, percent } from '@/lib/format';
import { show as showContract } from '@/routes/contracts';
import { index, show as showPayee } from '@/routes/payees';
import { payee as periodPayee } from '@/routes/periods';
import { show as showStatement } from '@/routes/statements';
import { create as createTransaction } from '@/routes/transactions';
import type { Paginated, PayeeTotals, StatementLedger } from '@/types';
import { PayeeFields } from './index';
import type { Payee } from './index';

type Props = {
    payee: Payee & { delete_block: string | null };
    period: { id: number; name: string } | null;
    summary: {
        totals: PayeeTotals;
        ledgers: LedgerRow[];
    } | null;
    splits: {
        id: number;
        contract_id: number;
        contract: string | null;
        split_amount: string;
        ledger: string | null;
        territory: string | null;
        used_by: string[];
        used_by_links: { to: 'release' | 'track'; id: number; name: string }[];
    }[];
    payouts: Paginated<{
        id: number;
        total: string;
        paid_on: string;
        period: string | null;
        memo: string | null;
    }>;
    statements: Paginated<{
        id: number;
        name: string;
        period: string | null;
        payable: string;
        published: boolean;
    }>;
    transfers: Paginated<{
        id: number;
        paid_timestamp: string;
        total: string;
        converted_total: string | null;
        currency: string;
        category: string | null;
        memo: string | null;
        release: string | null;
        release_id: number | null;
        ledger: string | null;
    }>;
    filters: Record<string, string | null>;
};

type LedgerRow = Omit<StatementLedger, 'releases'>;

const NO_LEDGERS: LedgerRow[] = [];

const LEDGER_COLUMNS = {
    ledger_name: (row: LedgerRow) => row.ledger_name,
    previous_balance: (row: LedgerRow) => row.previous_balance,
    payee_share: (row: LedgerRow) => row.payee_share,
    transfers: (row: LedgerRow) => row.transfers,
    payouts: (row: LedgerRow) => row.payouts,
    balance: (row: LedgerRow) => row.balance,
};

const SPLIT_COLUMNS = {
    contract: (row: Props['splits'][number]) => row.contract,
    split_amount: (row: Props['splits'][number]) => row.split_amount,
    ledger: (row: Props['splits'][number]) => row.ledger ?? 'Primary',
    territory: (row: Props['splits'][number]) => row.territory ?? 'Worldwide',
    used_by: (row: Props['splits'][number]) => row.used_by.join(', '),
};

export default function ShowPayee({
    payee,
    period,
    summary,
    splits,
    payouts,
    statements,
    transfers,
    filters,
}: Props) {
    const { url } = usePage();
    const { apply } = useFilters(showPayee.url(payee.id), filters);
    const statementSorter = serverSorter(filters, apply, 'statements');
    const payoutSorter = serverSorter(filters, apply, 'payouts');
    const transferSorter = serverSorter(filters, apply, 'transfers');
    const { rows: ledgerRows, sorter: ledgerSorter } = useTableSort(
        summary?.ledgers ?? NO_LEDGERS,
        LEDGER_COLUMNS,
    );
    const { rows: splitRows, sorter: splitSorter } = useTableSort(
        splits,
        SPLIT_COLUMNS,
    );

    setLayoutProps({
        breadcrumbs: [
            { title: 'Payees', href: index() },
            { title: payee.name, href: '#' },
        ],
    });

    return (
        <Page
            title={payee.name}
            description={payee.notes ?? payee.email ?? undefined}
            actions={
                <>
                    <Button variant="outline" asChild>
                        <Link
                            href={createTransaction({
                                query: {
                                    type: 'transfer',
                                    payee: payee.id,
                                    return_to: url,
                                },
                            })}
                        >
                            <Plus />
                            New transfer
                        </Link>
                    </Button>
                    <FormDialog
                        title={`Edit ${payee.name}`}
                        form={PayeeController.update.form(payee.id)}
                        trigger={
                            <Button variant="outline">
                                <Pencil />
                                Edit
                            </Button>
                        }
                    >
                        {(errors) => (
                            <PayeeFields payee={payee} errors={errors} />
                        )}
                    </FormDialog>
                    <DeleteButton
                        url={PayeeController.destroy.url(payee.id)}
                        label="Delete"
                        title={`Delete ${payee.name}?`}
                        blocked={payee.delete_block}
                        description="The payee's splits are removed from every contract, and a team member linked to the payee becomes unlinked."
                    />
                </>
            }
        >
            {summary && period ? (
                <>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Stat
                            label={`Earned in ${period.name}`}
                            value={
                                <Amount value={summary.totals.payee_share} />
                            }
                        />
                        <Stat
                            label="Payable now"
                            value={
                                <Amount value={summary.totals.outstanding} />
                            }
                            hint="Sum of ledgers with a positive balance"
                        />
                        <Stat
                            label={`Paid for ${period.name}`}
                            value={<Amount value={summary.totals.total_paid} />}
                        />
                        <Stat
                            label="Balance across ledgers"
                            value={<Amount value={summary.totals.balance} />}
                        />
                    </div>
                    <Panel
                        title="Ledger balances"
                        description={`As of the end of ${period.name}.`}
                        flush
                        actions={
                            <Button variant="outline" size="sm" asChild>
                                <Link href={periodPayee([period.id, payee.id])}>
                                    Full breakdown
                                </Link>
                            </Button>
                        }
                    >
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <SortableHead
                                        column="ledger_name"
                                        sorter={ledgerSorter}
                                    >
                                        Ledger
                                    </SortableHead>
                                    <SortableHead
                                        column="previous_balance"
                                        sorter={ledgerSorter}
                                        first="desc"
                                        className="text-right"
                                    >
                                        Previous balance
                                    </SortableHead>
                                    <SortableHead
                                        column="payee_share"
                                        sorter={ledgerSorter}
                                        first="desc"
                                        className="text-right"
                                    >
                                        Share
                                    </SortableHead>
                                    <SortableHead
                                        column="transfers"
                                        sorter={ledgerSorter}
                                        first="desc"
                                        className="text-right"
                                    >
                                        Transfers
                                    </SortableHead>
                                    <SortableHead
                                        column="payouts"
                                        sorter={ledgerSorter}
                                        first="desc"
                                        className="text-right"
                                    >
                                        Payouts
                                    </SortableHead>
                                    <SortableHead
                                        column="balance"
                                        sorter={ledgerSorter}
                                        first="desc"
                                        className="text-right"
                                    >
                                        Balance
                                    </SortableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {ledgerRows.map((ledger) => (
                                    <TableRow key={ledger.ledger_id ?? 0}>
                                        <TableCell className="font-medium">
                                            {ledger.ledger_name}
                                            {ledger.unrecouped && (
                                                <Badge
                                                    variant="warning"
                                                    className="ml-2"
                                                >
                                                    Unrecouped
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Amount
                                                value={ledger.previous_balance}
                                            />
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Amount
                                                value={ledger.payee_share}
                                            />
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Amount value={ledger.transfers} />
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Amount value={ledger.payouts} />
                                        </TableCell>
                                        <TableCell className="text-right font-medium">
                                            <Amount value={ledger.balance} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Panel>
                </>
            ) : (
                <p className="text-sm text-muted-foreground">
                    {period
                        ? `No earnings, transfers or balance up to ${period.name}.`
                        : 'Create a period to see what this payee has earned.'}
                </p>
            )}

            <Panel
                title="Splits"
                description="The contracts this payee earns from."
                flush
            >
                {splits.length === 0 ? (
                    <EmptyState
                        compact
                        title="Not on any contract yet. Open a contract and add a split for this payee."
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <SortableHead
                                    column="contract"
                                    sorter={splitSorter}
                                >
                                    Contract
                                </SortableHead>
                                <SortableHead
                                    column="split_amount"
                                    sorter={splitSorter}
                                    first="desc"
                                    className="text-right"
                                >
                                    Split
                                </SortableHead>
                                <SortableHead
                                    column="ledger"
                                    sorter={splitSorter}
                                >
                                    Ledger
                                </SortableHead>
                                <SortableHead
                                    column="territory"
                                    sorter={splitSorter}
                                >
                                    Territory
                                </SortableHead>
                                <SortableHead
                                    column="used_by"
                                    sorter={splitSorter}
                                >
                                    Applies to
                                </SortableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {splitRows.map((split) => (
                                <TableRow key={split.id}>
                                    <TableCell>
                                        <Link
                                            href={showContract(
                                                split.contract_id,
                                            )}
                                            className="font-medium underline decoration-muted-foreground/40 underline-offset-4"
                                        >
                                            {split.contract}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {percent(split.split_amount)}
                                    </TableCell>
                                    <TableCell>
                                        {split.ledger ?? 'Primary'}
                                    </TableCell>
                                    <TableCell>
                                        {split.territory ?? 'Worldwide'}
                                    </TableCell>
                                    <TableCell className="max-w-md truncate text-muted-foreground">
                                        {split.used_by_links.length === 0
                                            ? 'Not assigned to a release or track'
                                            : split.used_by_links.map(
                                                  (item, index) => (
                                                      <span
                                                          key={`${item.to}-${item.id}`}
                                                      >
                                                          {index > 0 && ', '}
                                                          <RecordLink
                                                              to={item.to}
                                                              id={item.id}
                                                          >
                                                              {item.name}
                                                          </RecordLink>
                                                      </span>
                                                  ),
                                              )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>

            <div className="grid gap-6 xl:grid-cols-2">
                <Panel title="Statements" flush>
                    {statements.total === 0 ? (
                        <EmptyState
                            compact
                            title="No statements generated yet."
                        />
                    ) : (
                        <>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <SortableHead
                                            column="period"
                                            sorter={statementSorter}
                                        >
                                            Period
                                        </SortableHead>
                                        <SortableHead
                                            column="status"
                                            sorter={statementSorter}
                                        >
                                            Status
                                        </SortableHead>
                                        <SortableHead
                                            column="payable"
                                            sorter={statementSorter}
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
                                            <TableCell>
                                                <Link
                                                    href={showStatement(
                                                        statement.id,
                                                    )}
                                                    className="font-medium underline decoration-muted-foreground/40 underline-offset-4"
                                                >
                                                    {statement.period}
                                                </Link>
                                            </TableCell>
                                            <TableCell>
                                                {statement.published ? (
                                                    <Badge variant="success">
                                                        Published
                                                    </Badge>
                                                ) : (
                                                    <Badge variant="warning">
                                                        Draft
                                                    </Badge>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Amount
                                                    value={statement.payable}
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                            <Pagination page={statements} noun="statements" />
                        </>
                    )}
                </Panel>
                <Panel title="Payouts" flush>
                    {payouts.total === 0 ? (
                        <EmptyState compact title="Nothing paid out yet." />
                    ) : (
                        <>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <SortableHead
                                            column="paid_on"
                                            sorter={payoutSorter}
                                            first="desc"
                                        >
                                            Paid date
                                        </SortableHead>
                                        <SortableHead
                                            column="period"
                                            sorter={payoutSorter}
                                        >
                                            Period
                                        </SortableHead>
                                        <SortableHead
                                            column="memo"
                                            sorter={payoutSorter}
                                        >
                                            Memo
                                        </SortableHead>
                                        <SortableHead
                                            column="total"
                                            sorter={payoutSorter}
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
                                                <Amount value={payout.total} />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                            <Pagination page={payouts} noun="payouts" />
                        </>
                    )}
                </Panel>
            </div>

            <Panel
                title="Transfers"
                description="Advances, paybacks and opening balances. Negative amounts were given to the payee and reduce what they are owed."
                flush
            >
                {transfers.total === 0 ? (
                    <EmptyState compact title="No transfers." />
                ) : (
                    <>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <SortableHead
                                        column="paid_timestamp"
                                        sorter={transferSorter}
                                        first="desc"
                                    >
                                        Paid date
                                    </SortableHead>
                                    <SortableHead
                                        column="category"
                                        sorter={transferSorter}
                                    >
                                        Category
                                    </SortableHead>
                                    <SortableHead
                                        column="release"
                                        sorter={transferSorter}
                                    >
                                        Release
                                    </SortableHead>
                                    <SortableHead
                                        column="ledger"
                                        sorter={transferSorter}
                                    >
                                        Ledger
                                    </SortableHead>
                                    <SortableHead
                                        column="memo"
                                        sorter={transferSorter}
                                    >
                                        Memo
                                    </SortableHead>
                                    <SortableHead
                                        column="amount"
                                        sorter={transferSorter}
                                        first="desc"
                                        className="text-right"
                                    >
                                        Amount
                                    </SortableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {transfers.data.map((transfer) => (
                                    <TableRow key={transfer.id}>
                                        <TableCell>
                                            {date(transfer.paid_timestamp)}
                                        </TableCell>
                                        <TableCell>
                                            {transfer.category ?? '–'}
                                        </TableCell>
                                        <TableCell>
                                            {transfer.release ? (
                                                <RecordLink
                                                    to="release"
                                                    id={transfer.release_id}
                                                >
                                                    {transfer.release}
                                                </RecordLink>
                                            ) : (
                                                '–'
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {transfer.ledger ?? 'Automatic'}
                                        </TableCell>
                                        <TableCell className="max-w-sm truncate text-muted-foreground">
                                            {transfer.memo}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {transfer.converted_total ===
                                            null ? (
                                                <Badge variant="destructive">
                                                    {transfer.total}{' '}
                                                    {transfer.currency}{' '}
                                                    unconverted
                                                </Badge>
                                            ) : (
                                                <Amount
                                                    value={
                                                        transfer.converted_total
                                                    }
                                                />
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <Pagination page={transfers} noun="transfers" />
                    </>
                )}
            </Panel>
            <AgreementLinks />
        </Page>
    );
}
