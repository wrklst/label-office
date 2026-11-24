import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { AgreementStatus } from '@/components/agreement-document';
import { EmptyState, Page, Panel } from '@/components/page';
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
import type { Paginated } from '@/types';

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
}: {
    agreements: Paginated<Row>;
    hasTemplates: boolean;
    disclaimer: string;
}) {
    return (
        <Page
            title="Agreements"
            description="The written agreements with your payees: drafted from a template, sent for signature and signed online by every party."
            actions={
                hasTemplates && (
                    <Button asChild>
                        <Link href={create()}>
                            <Plus />
                            New agreement
                        </Link>
                    </Button>
                )
            }
        >
            <p className="rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm">
                {disclaimer}
            </p>
            {!hasTemplates ? (
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
