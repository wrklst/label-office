import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { AgreementStatus } from '@/components/agreement-document';
import AgreementController from '@/actions/App/Http/Controllers/AgreementController';
import { FileDrop } from '@/components/file-drop';
import { Notice } from '@/components/notice';
import { EmptyState, Page, Panel } from '@/components/page';
import { Pagination } from '@/components/pagination';
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
import { date } from '@/lib/format';
import { index as templates } from '@/routes/agreement-templates';
import { create, index, show } from '@/routes/agreements';
import type { Paginated } from '@/types';

type Row = {
    id: number;
    name: string;
    status: string;
    payee: string | null;
    unmatched: boolean;
    covers: { release: string; tracks: string[] }[];
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
            <Notice id="legal">{disclaimer}</Notice>
            <Panel
                title="Already signed?"
                description="Drop agreements that were signed elsewhere, such as older ones on paper: PDF, JPEG or PNG, up to 20 MB each. Each file is filed as it is, named after its file, and you match it to its payee, contract and release afterwards."
            >
                <FileDrop
                    url={AgreementController.storeFiled.url()}
                    accept={['application/pdf', 'image/jpeg', 'image/png']}
                    maxMegabytes={20}
                    label="Drop signed agreements here"
                />
            </Panel>
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
                                <TableHead>Releases and tracks</TableHead>
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
                                    <TableCell>
                                        {agreement.unmatched ? (
                                            <Badge variant="secondary">
                                                To match
                                            </Badge>
                                        ) : (
                                            agreement.payee
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {agreement.covers.length === 0
                                            ? '–'
                                            : agreement.covers.map((cover) => (
                                                  <span
                                                      key={cover.release}
                                                      className="block"
                                                  >
                                                      <span className="text-foreground">
                                                          {cover.release}
                                                      </span>
                                                      {cover.tracks.length >
                                                          0 &&
                                                          `: ${cover.tracks.join(', ')}`}
                                                  </span>
                                              ))}
                                    </TableCell>
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
