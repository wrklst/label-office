import { Link } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import PayeeController from '@/actions/App/Http/Controllers/PayeeController';
import { FilterBar } from '@/components/filter-bar';
import { Field } from '@/components/field';
import { FormDialog } from '@/components/form-dialog';
import { EmptyState, Page, Panel } from '@/components/page';
import { Pagination } from '@/components/pagination';
import { SortableHead } from '@/components/sortable-head';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { useFilters } from '@/hooks/use-filters';
import { serverSorter } from '@/hooks/use-table-sort';
import type { SortDirection } from '@/hooks/use-table-sort';
import { index, show } from '@/routes/payees';
import type { Paginated } from '@/types';
import { count } from '@/lib/format';

export type Payee = {
    id: number;
    name: string;
    email: string | null;
    tags: string[] | null;
    notes: string | null;
    legal_name: string | null;
    address: string | null;
    country: string | null;
    vat_id: string | null;
    splits_count?: number;
    payouts_count?: number;
    statements_count?: number;
};

export function PayeeFields({
    payee,
    errors,
}: {
    payee?: Payee;
    errors: Record<string, string>;
}) {
    return (
        <>
            <Field label="Name" htmlFor="name" error={errors.name} required>
                <Input
                    id="name"
                    name="name"
                    defaultValue={payee?.name}
                    required
                />
            </Field>
            <Field
                label="Email"
                htmlFor="email"
                error={errors.email}
                hint="Only for your reference. Access to My statements is granted under Settings → Team."
            >
                <Input
                    id="email"
                    name="email"
                    type="email"
                    defaultValue={payee?.email ?? ''}
                />
            </Field>
            <Field
                label="Tags"
                htmlFor="tags"
                error={errors.tags}
                hint="Separate tags with commas."
            >
                <Input
                    id="tags"
                    name="tags"
                    defaultValue={payee?.tags?.join(', ') ?? ''}
                    placeholder="artist, remixer"
                />
            </Field>
            <Field label="Notes" htmlFor="notes" error={errors.notes}>
                <Textarea
                    id="notes"
                    name="notes"
                    defaultValue={payee?.notes ?? ''}
                    placeholder="Bank details, invoicing notes…"
                />
            </Field>
            <Field
                label="Legal name"
                htmlFor="legal_name"
                error={errors.legal_name}
                hint="The person or company that signs agreements, when it is not the name above."
            >
                <Input
                    id="legal_name"
                    name="legal_name"
                    defaultValue={payee?.legal_name ?? ''}
                />
            </Field>
            <Field label="Address" htmlFor="address" error={errors.address}>
                <Textarea
                    id="address"
                    name="address"
                    rows={3}
                    defaultValue={payee?.address ?? ''}
                />
            </Field>
            <div className="grid grid-cols-2 gap-4">
                <Field
                    label="Country"
                    htmlFor="country"
                    error={errors.country}
                    hint="Two-letter code of the tax residence."
                >
                    <Input
                        id="country"
                        name="country"
                        maxLength={2}
                        className="w-20 uppercase"
                        defaultValue={payee?.country ?? ''}
                        placeholder="DE"
                    />
                </Field>
                <Field label="VAT ID" htmlFor="vat_id" error={errors.vat_id}>
                    <Input
                        id="vat_id"
                        name="vat_id"
                        defaultValue={payee?.vat_id ?? ''}
                    />
                </Field>
            </div>
        </>
    );
}

export default function Payees({
    payees,
    filters,
}: {
    payees: Paginated<Payee>;
    filters: {
        search: string;
        sort: string | null;
        direction: SortDirection | null;
    };
}) {
    const { apply } = useFilters(index.url(), filters);
    const sorter = serverSorter(filters, apply);

    return (
        <Page
            title="Payees"
            description="Everyone you owe royalties to: artists, remixers, producers, sub-labels. A payee earns through the splits on contracts."
            actions={
                <FormDialog
                    title="New payee"
                    form={PayeeController.store.form()}
                    submitLabel="Create payee"
                    trigger={
                        <Button>
                            <Plus />
                            New payee
                        </Button>
                    }
                >
                    {(errors) => <PayeeFields errors={errors} />}
                </FormDialog>
            }
        >
            <FilterBar
                url={index.url()}
                filters={filters}
                search="Search payees…"
            />

            {payees.total === 0 ? (
                <EmptyState
                    title={
                        filters.search
                            ? 'No payee matches your search'
                            : 'No payees yet'
                    }
                    description={
                        filters.search
                            ? undefined
                            : 'Create the people you pay, then give them a share in a contract.'
                    }
                />
            ) : (
                <Panel flush>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <SortableHead column="name" sorter={sorter}>
                                    Name
                                </SortableHead>
                                <SortableHead column="email" sorter={sorter}>
                                    Email
                                </SortableHead>
                                <SortableHead
                                    column="splits_count"
                                    sorter={sorter}
                                    first="desc"
                                    className="text-right"
                                >
                                    Splits
                                </SortableHead>
                                <SortableHead
                                    column="statements_count"
                                    sorter={sorter}
                                    first="desc"
                                    className="text-right"
                                >
                                    Statements
                                </SortableHead>
                                <SortableHead
                                    column="payouts_count"
                                    sorter={sorter}
                                    first="desc"
                                    className="text-right"
                                >
                                    Payouts
                                </SortableHead>
                                <TableHead className="w-12" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {payees.data.map((payee) => (
                                <TableRow key={payee.id}>
                                    <TableCell>
                                        <Link
                                            href={show(payee.id)}
                                            className="font-medium underline decoration-muted-foreground/40 underline-offset-4"
                                        >
                                            {payee.name}
                                        </Link>
                                        {payee.tags?.map((tag) => (
                                            <Badge
                                                key={tag}
                                                variant="secondary"
                                                className="ml-2"
                                            >
                                                {tag}
                                            </Badge>
                                        ))}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {payee.email ?? '–'}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {payee.splits_count === 0 ? (
                                            <Badge variant="warning">
                                                no splits
                                            </Badge>
                                        ) : (
                                            count(payee.splits_count)
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {count(payee.statements_count)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {count(payee.payouts_count)}
                                    </TableCell>
                                    <TableCell>
                                        <FormDialog
                                            title={`Edit ${payee.name}`}
                                            form={PayeeController.update.form(
                                                payee.id,
                                            )}
                                            trigger={
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Edit ${payee.name}`}
                                                >
                                                    <Pencil className="text-muted-foreground" />
                                                </Button>
                                            }
                                        >
                                            {(errors) => (
                                                <PayeeFields
                                                    payee={payee}
                                                    errors={errors}
                                                />
                                            )}
                                        </FormDialog>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <Pagination page={payees} noun="payees" />
                </Panel>
            )}
        </Page>
    );
}

Payees.layout = { breadcrumbs: [{ title: 'Payees', href: index() }] };
