import { Link, usePage } from '@inertiajs/react';
import { FileSpreadsheet, Plus } from 'lucide-react';
import { FilterBar } from '@/components/filter-bar';
import { Amount } from '@/components/amount';
import { EmptyState, Page, Panel } from '@/components/page';
import { Pagination } from '@/components/pagination';
import type { PlayerTrack } from '@/components/player/player-store';
import { ReleaseActions } from '@/components/player/track-actions';
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
import { count, date } from '@/lib/format';
import { create as createImport } from '@/routes/data-imports';
import { show as showFile } from '@/routes/release-files';
import { create, index, metadata } from '@/routes/releases';
import type { Option, Paginated } from '@/types';
import { ReleaseStatusBadge } from './show';

type Release = {
    id: number;
    name: string;
    catalog_number: string | null;
    upc: string | null;
    tracks_count: number;
    released_on: string | null;
    artist: string | null;
    artist_id: number | null;
    contract: Option | null;
    income: string;
    status: 'todo' | 'in_progress' | 'done';
    release_type: string | null;
    cover_file_id: number | null;
    player_tracks: PlayerTrack[];
};

type Filters = {
    search: string;
    artist: number | null;
    without_contract: boolean;
    status: string | null;
    sort: string | null;
    direction: SortDirection | null;
};

export default function Releases({
    releases,
    filters,
}: {
    releases: Paginated<Release>;
    filters: Filters;
}) {
    const { url } = usePage();
    const { apply } = useFilters(index.url(), filters);
    const sorter = serverSorter(filters, apply);
    const filtered =
        filters.search !== '' ||
        filters.artist !== null ||
        filters.without_contract ||
        filters.status !== null;

    return (
        <Page
            title="Releases"
            description="Albums, EPs and singles. Releases are created from your imports, matched by UPC/EAN and catalog number."
            actions={
                <>
                    <Button variant="outline" asChild>
                        <a href={metadata.url()}>
                            <FileSpreadsheet />
                            Metadata CSV
                        </a>
                    </Button>
                    <Button asChild>
                        <Link href={create({ query: { return_to: url } })}>
                            <Plus />
                            New release
                        </Link>
                    </Button>
                </>
            }
        >
            <FilterBar
                url={index.url()}
                filters={filters}
                search="Search name, catalog number or UPC…"
                selects={[
                    {
                        name: 'artist',
                        label: 'Artist',
                        placeholder: 'All artists',
                        records: 'artists',
                    },
                    {
                        name: 'status',
                        label: 'Status',
                        placeholder: 'Any status',
                        className: 'w-full sm:w-36',
                        children: (
                            <>
                                <option value="todo">To do</option>
                                <option value="in_progress">In progress</option>
                                <option value="done">Done</option>
                            </>
                        ),
                    },
                ]}
                checks={[
                    { name: 'without_contract', label: 'Without contract' },
                ]}
            />

            {releases.total === 0 ? (
                <EmptyState
                    title={filtered ? 'No release matches' : 'No releases yet'}
                    description={
                        filtered
                            ? undefined
                            : 'Import a distributor statement and the releases and tracks in it are created for you.'
                    }
                    action={
                        !filtered ? (
                            <Button asChild>
                                <Link href={createImport()}>
                                    Import a statement
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />
            ) : (
                <Panel flush>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-12" />
                                <TableHead className="w-20">
                                    <span className="sr-only">Play</span>
                                </TableHead>
                                <SortableHead
                                    column="catalog_number"
                                    sorter={sorter}
                                >
                                    Cat. no.
                                </SortableHead>
                                <SortableHead column="name" sorter={sorter}>
                                    Release
                                </SortableHead>
                                <SortableHead column="artist" sorter={sorter}>
                                    Artist
                                </SortableHead>
                                <SortableHead column="upc" sorter={sorter}>
                                    UPC / EAN
                                </SortableHead>
                                <SortableHead
                                    column="released_on"
                                    sorter={sorter}
                                    first="desc"
                                >
                                    Released
                                </SortableHead>
                                <SortableHead
                                    column="tracks_count"
                                    sorter={sorter}
                                    first="desc"
                                    className="text-right"
                                >
                                    Tracks
                                </SortableHead>
                                <SortableHead column="contract" sorter={sorter}>
                                    Contract
                                </SortableHead>
                                <SortableHead
                                    column="income"
                                    sorter={sorter}
                                    first="desc"
                                    className="text-right"
                                >
                                    Income to date
                                </SortableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {releases.data.map((release) => (
                                <TableRow key={release.id}>
                                    <TableCell>
                                        <div className="size-9 overflow-hidden rounded border bg-muted">
                                            {release.cover_file_id && (
                                                <img
                                                    src={showFile.url(
                                                        release.cover_file_id,
                                                        {
                                                            query: {
                                                                size: 'thumb',
                                                            },
                                                        },
                                                    )}
                                                    alt=""
                                                    width={96}
                                                    height={96}
                                                    loading="lazy"
                                                    decoding="async"
                                                    className="size-full object-cover"
                                                />
                                            )}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex items-center gap-1">
                                            <ReleaseActions
                                                tracks={release.player_tracks}
                                                name={release.name}
                                            />
                                        </div>
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {release.catalog_number ?? '–'}
                                    </TableCell>
                                    <TableCell>
                                        <RecordLink
                                            to="release"
                                            id={release.id}
                                            className="font-medium"
                                        >
                                            {release.name}
                                        </RecordLink>
                                        <span className="ml-2 inline-flex gap-1 align-middle">
                                            {release.status !== 'done' && (
                                                <ReleaseStatusBadge
                                                    status={release.status}
                                                />
                                            )}
                                            {release.release_type && (
                                                <Badge variant="outline">
                                                    {release.release_type}
                                                </Badge>
                                            )}
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        <RecordLink
                                            to="artist"
                                            id={release.artist_id}
                                        >
                                            {release.artist ?? '–'}
                                        </RecordLink>
                                    </TableCell>
                                    <TableCell className="font-mono text-xs text-muted-foreground">
                                        {release.upc ?? '–'}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {date(release.released_on)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {count(release.tracks_count)}
                                    </TableCell>
                                    <TableCell>
                                        {release.contract ? (
                                            <RecordLink
                                                to="contract"
                                                id={release.contract.id}
                                            >
                                                {release.contract.name}
                                            </RecordLink>
                                        ) : (
                                            <Badge variant="warning">
                                                No contract
                                            </Badge>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount value={release.income} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <Pagination page={releases} noun="releases" />
                </Panel>
            )}
        </Page>
    );
}

Releases.layout = { breadcrumbs: [{ title: 'Releases', href: index() }] };
