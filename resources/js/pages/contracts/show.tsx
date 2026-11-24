import { AgreementLinks } from '@/components/agreement-links';
import { Link, setLayoutProps, usePage } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import ContractController from '@/actions/App/Http/Controllers/ContractController';
import { DeleteButton } from '@/components/delete-button';
import { EmptyState, Page, Panel } from '@/components/page';
import { Pagination } from '@/components/pagination';
import { RecordLink } from '@/components/record-link';
import { SortableHead } from '@/components/sortable-head';
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
import { percent, plural } from '@/lib/format';
import { edit, index, show } from '@/routes/contracts';
import type { Option, Paginated } from '@/types';

type Props = {
    contract: {
        id: number;
        name: string;
        memo: string | null;
        splits: {
            payee: Option | null;
            ledger: string | null;
            territory: string | null;
            percentage: string;
        }[];
        children: { id: number; name: string; weight: string }[];
        parents: Option[];
        items_count: number;
    };
    releases: Paginated<{
        id: number;
        name: string;
        catalog_number: string | null;
    }>;
    tracks: Paginated<{
        id: number;
        name: string;
        release: Option | null;
    }>;
    filters: Record<string, string | null>;
};

export default function ShowContract({
    contract,
    releases,
    tracks,
    filters,
}: Props) {
    const { url } = usePage();
    const { apply } = useFilters(show.url(contract.id), filters);
    const releaseSorter = serverSorter(filters, apply, 'releases');
    const trackSorter = serverSorter(filters, apply, 'tracks');
    const prorated = contract.children.length > 0;
    const weightTotal = contract.children.reduce(
        (sum, child) => sum + (Number(child.weight) || 0),
        0,
    );

    setLayoutProps({
        breadcrumbs: [
            { title: 'Contracts', href: index() },
            { title: contract.name, href: '#' },
        ],
    });

    return (
        <Page
            title={contract.name}
            description={
                contract.memo ??
                'Splits are percentages of net income: income minus the expenses booked on the same release or track.'
            }
            actions={
                <>
                    <Button variant="outline" asChild>
                        <Link
                            href={edit(contract.id, {
                                query: { return_to: url },
                            })}
                        >
                            <Pencil />
                            Edit
                        </Link>
                    </Button>
                    <DeleteButton
                        url={ContractController.destroy.url(contract.id)}
                        label="Delete"
                        title={`Delete ${contract.name}?`}
                        description="Its splits and its links to prorated contracts are removed. Releases, tracks and items using this contract fall back to the next contract up the chain, or to no contract at all. Past statements keep their figures."
                    />
                </>
            }
        >
            <Panel
                title={prorated ? 'Prorated from' : 'Splits'}
                description={
                    prorated
                        ? 'Net income is shared by the weight of each contract below, and each of them pays out by its own splits.'
                        : undefined
                }
                flush
            >
                {prorated ? (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Contract</TableHead>
                                <TableHead className="text-right">
                                    Weight
                                </TableHead>
                                <TableHead className="text-right">
                                    Share
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {contract.children.map((child) => (
                                <TableRow key={child.id}>
                                    <TableCell>
                                        <RecordLink to="contract" id={child.id}>
                                            {child.name}
                                        </RecordLink>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {child.weight}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {weightTotal > 0
                                            ? percent(
                                                  Number(child.weight) /
                                                      weightTotal,
                                                  2,
                                              )
                                            : '–'}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                ) : contract.splits.length === 0 ? (
                    <EmptyState compact title="No splits yet." />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Payee</TableHead>
                                <TableHead className="text-right">
                                    Share
                                </TableHead>
                                <TableHead>Ledger</TableHead>
                                <TableHead>Territory</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {contract.splits.map((split, position) => (
                                <TableRow key={position}>
                                    <TableCell>
                                        <RecordLink
                                            to="payee"
                                            id={split.payee?.id}
                                        >
                                            {split.payee?.name ?? '–'}
                                        </RecordLink>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {percent(
                                            Number(split.percentage) / 100,
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {split.ledger ?? 'Primary'}
                                    </TableCell>
                                    <TableCell>
                                        {split.territory ?? 'Worldwide'}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>

            {contract.parents.length > 0 && (
                <Panel title="Part of prorated contracts" flush>
                    <ul className="divide-y text-sm">
                        {contract.parents.map((parent) => (
                            <li key={parent.id} className="px-4 py-2">
                                <RecordLink to="contract" id={parent.id}>
                                    {parent.name}
                                </RecordLink>
                            </li>
                        ))}
                    </ul>
                </Panel>
            )}

            <Panel
                title="Releases"
                description="Releases that follow this contract."
                flush
            >
                {releases.total === 0 ? (
                    <EmptyState
                        compact
                        title="No release uses this contract."
                        description="Pick it on a release, or use the catalog manager to assign many at once."
                    />
                ) : (
                    <>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <SortableHead
                                        column="name"
                                        sorter={releaseSorter}
                                    >
                                        Release
                                    </SortableHead>
                                    <SortableHead
                                        column="catalog_number"
                                        sorter={releaseSorter}
                                    >
                                        Catalog number
                                    </SortableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {releases.data.map((release) => (
                                    <TableRow key={release.id}>
                                        <TableCell>
                                            <RecordLink
                                                to="release"
                                                id={release.id}
                                            >
                                                {release.name}
                                            </RecordLink>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {release.catalog_number ?? '–'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <Pagination page={releases} noun="releases" />
                    </>
                )}
            </Panel>

            <Panel
                title="Tracks"
                description="Tracks with this contract of their own, whatever their release follows."
                flush
            >
                {tracks.total === 0 ? (
                    <EmptyState
                        compact
                        title="No track has this contract of its own."
                    />
                ) : (
                    <>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <SortableHead
                                        column="name"
                                        sorter={trackSorter}
                                    >
                                        Track
                                    </SortableHead>
                                    <SortableHead
                                        column="release"
                                        sorter={trackSorter}
                                    >
                                        Release
                                    </SortableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {tracks.data.map((track) => (
                                    <TableRow key={track.id}>
                                        <TableCell>
                                            <RecordLink
                                                to="track"
                                                id={track.id}
                                            >
                                                {track.name}
                                            </RecordLink>
                                        </TableCell>
                                        <TableCell>
                                            <RecordLink
                                                to="release"
                                                id={track.release?.id}
                                            >
                                                {track.release?.name ?? '–'}
                                            </RecordLink>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <Pagination page={tracks} noun="tracks" />
                    </>
                )}
            </Panel>

            {contract.items_count > 0 && (
                <p className="text-sm text-muted-foreground">
                    {plural(contract.items_count, 'item')} on statements also
                    follow this contract.
                </p>
            )}
            <AgreementLinks />
        </Page>
    );
}
