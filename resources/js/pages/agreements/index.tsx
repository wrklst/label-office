import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { AgreementStatus } from '@/components/agreement-document';
import AgreementController from '@/actions/App/Http/Controllers/AgreementController';
import { Field, SelectInput } from '@/components/field';
import { FormDialog } from '@/components/form-dialog';
import { EmptyState, Page, Panel } from '@/components/page';
import { Input } from '@/components/ui/input';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { date } from '@/lib/format';
import { index as templates } from '@/routes/agreement-templates';
import { create, index, show } from '@/routes/agreements';
import type { Option, Paginated } from '@/types';

type Row = {
    id: number;
    name: string;
    status: string;
    payee: string | null;
    sent_at: string | null;
    signed_at: string | null;
};

export default function Agreements({
    agreements,
    hasTemplates,
    disclaimer,
    payeeOptions,
}: {
    agreements: Paginated<Row>;
    hasTemplates: boolean;
    disclaimer: string;
    payeeOptions: Option[];
}) {
    return (
        <Page
            title="Agreements"
            description="The written agreements with your payees: drafted from a template, sent for signature and signed online by every party."
            actions={
                <>
                    <FormDialog
                        title="File a signed agreement"
                        description="For an agreement that was signed outside the application, such as an older one on paper. The copy you upload is the agreement; nothing is sent to anyone."
                        form={AgreementController.storeFiled.form()}
                        submitLabel="File it"
                        trigger={
                            <Button variant="outline">
                                File a signed agreement
                            </Button>
                        }
                    >
                        {(errors) => (
                            <>
                                <Field
                                    label="Name"
                                    htmlFor="filed-name"
                                    error={errors.name}
                                    required
                                >
                                    <Input
                                        id="filed-name"
                                        name="name"
                                        required
                                    />
                                </Field>
                                <Field
                                    label="Payee"
                                    htmlFor="filed-payee"
                                    error={errors.payee_id}
                                    required
                                >
                                    <SelectInput
                                        id="filed-payee"
                                        name="payee_id"
                                        options={payeeOptions}
                                        placeholder="Choose payee…"
                                        required
                                    />
                                </Field>
                                <Field
                                    label="Signed on"
                                    htmlFor="filed-signed-on"
                                    error={errors.signed_on}
                                    required
                                >
                                    <Input
                                        id="filed-signed-on"
                                        name="signed_on"
                                        type="date"
                                        className="w-48"
                                        required
                                    />
                                </Field>
                                <Field
                                    label="Signed copy"
                                    htmlFor="filed-file"
                                    error={errors.file}
                                    required
                                    hint="PDF, JPEG or PNG, up to 20 MB."
                                >
                                    <Input
                                        id="filed-file"
                                        name="file"
                                        type="file"
                                        accept=".pdf,.jpg,.jpeg,.png"
                                        required
                                    />
                                </Field>
                            </>
                        )}
                    </FormDialog>
                    {hasTemplates && (
                        <Button asChild>
                            <Link href={create()}>
                                <Plus />
                                New agreement
                            </Link>
                        </Button>
                    )}
                </>
            }
        >
            <p className="rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm">
                {disclaimer}
            </p>
            {!hasTemplates && agreements.data.length === 0 ? (
                <EmptyState
                    title="Start with a template"
                    description="An agreement is made from one of your templates."
                    action={
                        <Button asChild>
                            <Link href={templates()}>Agreement templates</Link>
                        </Button>
                    }
                />
            ) : agreements.data.length === 0 ? (
                <EmptyState
                    title="No agreements yet"
                    description="Draft one for a remix or a release before it comes out."
                />
            ) : (
                <Panel flush>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Payee</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Sent</TableHead>
                                <TableHead>Signed</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {agreements.data.map((agreement) => (
                                <TableRow key={agreement.id}>
                                    <TableCell className="font-medium">
                                        <Link
                                            href={show(agreement.id)}
                                            className="hover:underline"
                                        >
                                            {agreement.name}
                                        </Link>
                                    </TableCell>
                                    <TableCell>{agreement.payee}</TableCell>
                                    <TableCell>
                                        <AgreementStatus
                                            status={agreement.status}
                                        />
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {agreement.sent_at
                                            ? date(agreement.sent_at)
                                            : '–'}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {agreement.signed_at
                                            ? date(agreement.signed_at)
                                            : '–'}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <Pagination page={agreements} />
                </Panel>
            )}
        </Page>
    );
}

Agreements.layout = { breadcrumbs: [{ title: 'Agreements', href: index() }] };
