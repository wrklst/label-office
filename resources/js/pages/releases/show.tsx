import ReleaseController from '@/actions/App/Http/Controllers/ReleaseController';
import { DeleteButton } from '@/components/delete-button';
import { PlatformLinks } from '@/components/platform-links';
import type { PlatformLinksProps } from '@/components/platform-links';
import { Link, setLayoutProps, usePage } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import { Amount } from '@/components/amount';
import { IncomeReport, MoversPanel } from '@/components/income-report';
import type { Movers, Report } from '@/components/income-report';
import { DeliveryMenu } from '@/components/delivery-menu';
import { EmptyState, Page, Panel, Stat } from '@/components/page';
import type { PlayerTrack } from '@/components/player/player-store';
import {
    ReleaseActions,
    TrackActions,
} from '@/components/player/track-actions';
import { RecordLink } from '@/components/record-link';
import { TrackDialog } from '@/components/track-dialog';
import type { EditableTrack } from '@/components/track-fields';
import { ReleaseFiles } from '@/components/release-files';
import type { ReleaseFile } from '@/components/release-files';
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
import { serverSorter, useTableSort } from '@/hooks/use-table-sort';
import { count, country, date, duration, quantity } from '@/lib/format';
import { show as showFile } from '@/routes/release-files';
import { edit, index, show } from '@/routes/releases';
import {
    create as createTransaction,
    index as transactions,
} from '@/routes/transactions';
import type { Option, Paginated } from '@/types';
import { AI_LEVELS } from '@/components/ai-field';

type Track = EditableTrack & {
    artist: string | null;
    audio_file_id: number | null;
    contract: Option | null;
    income: string;
    quantity: string;
};

type Props = {
    platformLinks: PlatformLinksProps;
    release: {
        id: number;
        name: string;
        artist_id: number | null;
        version: string | null;
        catalog_number: string | null;
        upc: string | null;
        released_on: string | null;
        original_released_on: string | null;
        preorder_on: string | null;
        status: 'todo' | 'in_progress' | 'done';
        release_type: string | null;
        artist: string | null;
        genre: string | null;
        subgenre: string | null;
        language: string | null;
        explicit: string | null;
        ai_artwork: string | null;
        p_line: string | null;
        c_line: string | null;
        press_release: string | null;
        key_facts: string | null;
        todo: string | null;
        files_url: string | null;
        blocked_services: string[] | null;
        blocked_territories: string[] | null;
        notes: string | null;
        cover_file_id: number | null;
        contract: Option | null;
        files: ReleaseFile[];
        tracks: Track[];
    };
    items: Paginated<{
        id: number;
        name: string;
        format_group: string | null;
        is_additionals: boolean;
        track: string | null;
        contract: Option | null;
        sources: string[];
        transactions: number;
        total: string;
    }>;
    filters: Record<string, string | null>;
    totals: {
        income: string;
        expense: string;
        transactions: number;
        release_level_income: string;
    };
    report: Report;
    movers: Movers;
    genreOptions: string[];
    fileKinds: { value: string; label: string }[];
    maxUploadMegabytes: number;
};

const STATUS = {
    todo: { label: 'To do', variant: 'warning' },
    in_progress: { label: 'In progress', variant: 'info' },
    done: { label: 'Done', variant: 'success' },
} as const;

export function ReleaseStatusBadge({
    status,
}: {
    status: keyof typeof STATUS;
}) {
    return (
        <Badge variant={STATUS[status].variant}>{STATUS[status].label}</Badge>
    );
}

function Detail({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm">{children || '–'}</dd>
        </div>
    );
}

const TRACK_COLUMNS = {
    track_number: (row: Track) => row.track_number,
    name: (row: Track) => row.name,
    artist: (row: Track) => row.artist,
    isrc: (row: Track) => row.isrc,
    duration: (row: Track) => row.duration,
    contract: (row: Track) => row.contract?.name,
    quantity: (row: Track) => row.quantity,
    income: (row: Track) => row.income,
};

export default function ShowRelease({
    platformLinks,
    release,
    items,
    filters,
    totals,
    report,
    movers,
    genreOptions,
    fileKinds,
    maxUploadMegabytes,
}: Props) {
    const { url } = usePage();
    const { apply } = useFilters(show.url(release.id), filters);
    const itemSorter = serverSorter(filters, apply, 'items');
    const { rows: trackRows, sorter } = useTableSort(
        release.tracks,
        TRACK_COLUMNS,
    );
    const title = release.version
        ? `${release.name} (${release.version})`
        : release.name;
    const playerTrack = (track: Track, fileId: number): PlayerTrack => ({
        id: track.id,
        fileId,
        title: track.version ? `${track.name} (${track.version})` : track.name,
        artist: track.artist ?? release.artist,
        artistId: track.artist ? track.artist_id : release.artist_id,
        release: { id: release.id, name: release.name },
        coverFileId: release.cover_file_id,
    });
    const playableTracks = release.tracks.flatMap((track) =>
        track.audio_file_id ? [playerTrack(track, track.audio_file_id)] : [],
    );

    setLayoutProps({
        breadcrumbs: [
            { title: 'Releases', href: index() },
            { title, href: '#' },
        ],
    });

    return (
        <Page
            title={title}
            description={
                <span className="flex flex-wrap items-center gap-2">
                    <ReleaseStatusBadge status={release.status} />
                    {release.artist && (
                        <RecordLink to="artist" id={release.artist_id}>
                            {release.artist}
                        </RecordLink>
                    )}
                    {[
                        release.release_type,
                        release.catalog_number,
                        release.upc && `UPC ${release.upc}`,
                        release.released_on &&
                            `released ${date(release.released_on)}`,
                    ]
                        .filter(Boolean)
                        .map((part, index) =>
                            release.artist || index > 0 ? ` · ${part}` : part,
                        )
                        .join('')}
                </span>
            }
            actions={
                <>
                    <DeliveryMenu releaseId={release.id} />
                    <Button variant="outline" asChild>
                        <Link
                            href={createTransaction({
                                query: {
                                    type: 'expense',
                                    release: release.id,
                                    return_to: url,
                                },
                            })}
                        >
                            <Plus />
                            New expense
                        </Link>
                    </Button>
                    <Button variant="outline" asChild>
                        <Link
                            href={edit(release.id, {
                                query: { return_to: url },
                            })}
                        >
                            <Pencil />
                            Edit
                        </Link>
                    </Button>
                    <DeleteButton
                        url={ReleaseController.destroy.url(release.id)}
                        label="Delete"
                        title={`Delete ${release.name}?`}
                        description="Deleted with the release: its tracks, its items that have no transactions, its platform links and all its files, which are also removed from storage. Its transactions are kept, with the items they were booked on, but become unassigned, so they stop earning for any payee."
                    />
                </>
            }
        >
            <div className="grid gap-4 lg:grid-cols-[13rem_1fr]">
                <div className="aspect-square w-52 overflow-hidden rounded-xl border bg-muted">
                    {release.cover_file_id ? (
                        <a
                            href={showFile.url(release.cover_file_id)}
                            target="_blank"
                            rel="noreferrer"
                            title="Open the original"
                            className="block size-full"
                        >
                            <img
                                src={showFile.url(release.cover_file_id, {
                                    query: { size: 'medium' },
                                })}
                                alt={`Cover of ${release.name}`}
                                width={600}
                                height={600}
                                loading="lazy"
                                decoding="async"
                                className="size-full object-cover"
                            />
                        </a>
                    ) : (
                        <div className="flex size-full items-center justify-center p-4 text-center text-xs text-muted-foreground">
                            No cover yet. Upload one under Files.
                        </div>
                    )}
                </div>
                <div className="grid content-start gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Stat
                        label="Income to date"
                        value={<Amount value={totals.income} />}
                    />
                    <Stat
                        label="Expenses to date"
                        value={<Amount value={totals.expense} />}
                    />
                    <Stat
                        label="Net"
                        value={
                            <Amount
                                value={
                                    Number(totals.income) +
                                    Number(totals.expense)
                                }
                            />
                        }
                    />
                    <Stat
                        label="Contract"
                        value={
                            release.contract ? (
                                <RecordLink
                                    to="contract"
                                    id={release.contract.id}
                                    className="text-base"
                                >
                                    {release.contract.name}
                                </RecordLink>
                            ) : (
                                <span className="text-base">No contract</span>
                            )
                        }
                        hint={
                            release.contract
                                ? undefined
                                : 'The label keeps all income from tracks without their own contract.'
                        }
                    />
                </div>
            </div>

            <Panel
                title="Tracks"
                flush
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <ReleaseActions tracks={playableTracks} />
                        <Button variant="outline" size="sm" asChild>
                            <Link
                                href={transactions({
                                    query: { release: release.id },
                                })}
                            >
                                {count(totals.transactions)} transactions
                            </Link>
                        </Button>
                    </div>
                }
            >
                {release.tracks.length === 0 ? (
                    <EmptyState compact title="No tracks on this release." />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <SortableHead
                                    column="track_number"
                                    sorter={sorter}
                                    className="w-10"
                                >
                                    #
                                </SortableHead>
                                <SortableHead column="name" sorter={sorter}>
                                    Title
                                </SortableHead>
                                <SortableHead column="artist" sorter={sorter}>
                                    Artist
                                </SortableHead>
                                <SortableHead column="isrc" sorter={sorter}>
                                    ISRC
                                </SortableHead>
                                <SortableHead
                                    column="duration"
                                    sorter={sorter}
                                    first="desc"
                                >
                                    Length
                                </SortableHead>
                                <TableHead>Listen</TableHead>
                                <SortableHead column="contract" sorter={sorter}>
                                    Contract
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
                                    column="income"
                                    sorter={sorter}
                                    first="desc"
                                    className="text-right"
                                >
                                    Income
                                </SortableHead>
                                <TableHead className="w-10" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {trackRows.map((track) => (
                                <TableRow key={track.id}>
                                    <TableCell className="text-muted-foreground tabular-nums">
                                        {track.track_number ?? '–'}
                                    </TableCell>
                                    <TableCell className="font-medium">
                                        <RecordLink to="track" id={track.id}>
                                            {track.name}
                                        </RecordLink>
                                        {track.version && (
                                            <span className="font-normal text-muted-foreground">
                                                {' '}
                                                ({track.version})
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <RecordLink
                                            to="artist"
                                            id={track.artist_id}
                                        >
                                            {track.artist ?? '–'}
                                        </RecordLink>
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {track.isrc ?? '–'}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {duration(track.duration)}
                                    </TableCell>
                                    <TableCell>
                                        {track.audio_file_id ? (
                                            <TrackActions
                                                track={playerTrack(
                                                    track,
                                                    track.audio_file_id,
                                                )}
                                            />
                                        ) : (
                                            <span className="text-xs text-muted-foreground">
                                                No audio
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {track.contract ? (
                                            <RecordLink
                                                to="contract"
                                                id={track.contract.id}
                                            >
                                                {track.contract.name}
                                            </RecordLink>
                                        ) : release.contract ? (
                                            <span className="text-muted-foreground">
                                                Release contract
                                            </span>
                                        ) : (
                                            <Badge variant="warning">
                                                No contract
                                            </Badge>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {quantity(track.quantity)}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount value={track.income} />
                                    </TableCell>
                                    <TableCell>
                                        <TrackDialog
                                            track={track}
                                            genreOptions={genreOptions}
                                        />
                                    </TableCell>
                                </TableRow>
                            ))}
                            {Number(totals.release_level_income) !== 0 && (
                                <TableRow>
                                    <TableCell />
                                    <TableCell
                                        colSpan={7}
                                        className="text-muted-foreground"
                                    >
                                        Booked on the release as a whole (album
                                        sales, additionals)
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount
                                            value={totals.release_level_income}
                                        />
                                    </TableCell>
                                    <TableCell />
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                )}
            </Panel>

            <Panel
                title="Files"
                description="Cover, audio masters, artwork and documents."
                flush
            >
                <ReleaseFiles
                    releaseId={release.id}
                    files={release.files}
                    tracks={release.tracks.map((track) => ({
                        id: track.id,
                        name: `${track.track_number ?? '–'}. ${track.name}`,
                    }))}
                    kinds={fileKinds}
                    maxMegabytes={maxUploadMegabytes}
                    player={{
                        release: { id: release.id, name: release.name },
                        artist: release.artist ?? null,
                        artistId: release.artist_id ?? null,
                        coverFileId: release.cover_file_id,
                    }}
                />
            </Panel>

            <Panel title="Release details">
                <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Detail label="Format">{release.release_type}</Detail>
                    <Detail label="Genre">
                        {[release.genre, release.subgenre]
                            .filter(Boolean)
                            .join(' / ')}
                    </Detail>
                    <Detail label="Language">{release.language}</Detail>
                    <Detail label="Explicit">
                        {release.explicit &&
                            (release.explicit === 'explicit'
                                ? 'Explicit'
                                : 'Clean version')}
                    </Detail>
                    <Detail label="AI in the artwork">
                        {release.ai_artwork &&
                            AI_LEVELS[
                                release.ai_artwork as keyof typeof AI_LEVELS
                            ]}
                    </Detail>
                    <Detail label="℗ line">{release.p_line}</Detail>
                    <Detail label="© line">{release.c_line}</Detail>
                    <Detail label="Original release">
                        {release.original_released_on &&
                            date(release.original_released_on)}
                    </Detail>
                    <Detail label="Pre-order from">
                        {release.preorder_on && date(release.preorder_on)}
                    </Detail>
                    <Detail label="Not delivered to">
                        {release.blocked_services?.join(', ')}
                    </Detail>
                    <Detail label="Blocked territories">
                        {release.blocked_territories
                            ?.map((code) => country(code))
                            .join(', ')}
                    </Detail>
                    <Detail label="External files">
                        {release.files_url && (
                            <a
                                href={release.files_url}
                                target="_blank"
                                rel="noreferrer"
                                className="break-all underline underline-offset-4"
                            >
                                {release.files_url}
                            </a>
                        )}
                    </Detail>
                </dl>
            </Panel>

            {(release.todo || release.key_facts || release.press_release) && (
                <div className="grid gap-6 xl:grid-cols-2">
                    {release.todo && (
                        <Panel title="To do">
                            <p className="text-sm whitespace-pre-wrap">
                                {release.todo}
                            </p>
                        </Panel>
                    )}
                    {release.key_facts && (
                        <Panel title="Key facts">
                            <p className="text-sm whitespace-pre-wrap">
                                {release.key_facts}
                            </p>
                        </Panel>
                    )}
                    {release.press_release && (
                        <Panel title="Press release" className="xl:col-span-2">
                            <p className="max-w-3xl text-sm whitespace-pre-wrap">
                                {release.press_release}
                            </p>
                        </Panel>
                    )}
                </div>
            )}

            <PlatformLinks
                owner={{ release_id: release.id }}
                platformLinks={platformLinks}
                includesTracks
            />

            <IncomeReport report={report} />

            <MoversPanel movers={movers} to="track" title="Tracks by income" />

            <div className="grid gap-6">
                <Panel
                    title="Items"
                    description="Every name a statement, platform or manual entry used for this release or one of its tracks. A line with that name is booked on the track shown, and counts for that track's contract."
                    flush
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <SortableHead column="name" sorter={itemSorter}>
                                    Name on statements
                                </SortableHead>
                                <SortableHead
                                    column="track"
                                    sorter={itemSorter}
                                >
                                    Booked on
                                </SortableHead>
                                <SortableHead
                                    column="transactions_count"
                                    sorter={itemSorter}
                                    first="desc"
                                    className="text-right"
                                >
                                    Transactions
                                </SortableHead>
                                <SortableHead
                                    column="total"
                                    sorter={itemSorter}
                                    first="desc"
                                    className="text-right"
                                >
                                    Amount
                                </SortableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {items.data.map((item) => (
                                <TableRow
                                    key={item.id}
                                    className={
                                        item.transactions === 0
                                            ? 'text-muted-foreground'
                                            : ''
                                    }
                                >
                                    <TableCell>
                                        {item.name}
                                        <span className="block text-xs text-muted-foreground">
                                            {[
                                                item.is_additionals
                                                    ? 'manual entries'
                                                    : item.sources.join(', ') ||
                                                      'nothing booked yet',
                                                item.format_group,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        {item.track ?? 'Whole release'}
                                        {item.contract && (
                                            <span className="block text-xs text-muted-foreground">
                                                <RecordLink
                                                    to="contract"
                                                    id={item.contract.id}
                                                >
                                                    {item.contract.name}
                                                </RecordLink>
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {item.transactions}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount value={item.total} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <Pagination page={items} noun="items" />
                </Panel>
            </div>

            {release.notes && <Panel title="Notes">{release.notes}</Panel>}
        </Page>
    );
}
