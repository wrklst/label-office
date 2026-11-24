import { Link } from '@inertiajs/react';
import TrackController from '@/actions/App/Http/Controllers/TrackController';
import { FilterBar } from '@/components/filter-bar';
import { DeleteButton } from '@/components/delete-button';
import { EmptyState, Page, Panel } from '@/components/page';
import { Pagination } from '@/components/pagination';
import { TrackActions } from '@/components/player/track-actions';
import { RecordLink, recordLinkClass } from '@/components/record-link';
import { SortableHead } from '@/components/sortable-head';
import { TrackDialog } from '@/components/track-dialog';
import type { EditableTrack } from '@/components/track-fields';
import { Badge } from '@/components/ui/badge';
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
import { show as showRelease } from '@/routes/releases';
import { index } from '@/routes/tracks';
import type { Option, Paginated } from '@/types';

type Track = EditableTrack & {
    artist: string | null;
    credited_artist: string | null;
    audio_file_id: number | null;
    cover_file_id: number | null;
    release: { id: number; name: string; catalog_number: string | null } | null;
    releases: { id: number; name: string }[];
    without_contract_on: string[];
    contract: Option | null;
    inherited_contract: Option | null;
};

type Props = {
    tracks: Paginated<Track>;
    filters: {
        search: string;
        release: number | null;
        sort: string | null;
        direction: SortDirection | null;
    };
    genreOptions: string[];
};

export default function Tracks({ tracks, filters, genreOptions }: Props) {
    const { apply } = useFilters(index.url(), filters);
    const sorter = serverSorter(filters, apply);
    const filtered = filters.search !== '' || filters.release !== null;

    return (
        <Page
            title="Tracks"
            description="Every recording across all releases. A recording on several releases, such as a single and an album, is listed once with all of them; pick a release to see and edit its own entry."
        >
            <FilterBar
                url={index.url()}
                filters={filters}
                search="Search title or ISRC…"
                selects={[
                    {
                        name: 'release',
                        label: 'Release',
                        placeholder: 'All releases',
                        records: 'releases',
                        className: 'w-full sm:w-72',
                    },
                ]}
            />

            {tracks.total === 0 ? (
                <EmptyState
                    title={filtered ? 'No track matches' : 'No tracks yet'}
                    description={
                        filtered
                            ? undefined
                            : 'Tracks are added with their release or created from your imports.'
                    }
                />
            ) : (
                <Panel flush>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <SortableHead column="name" sorter={sorter}>
                                    Title
                                </SortableHead>
                                <SortableHead column="artist" sorter={sorter}>
                                    Artist
                                </SortableHead>
                                <SortableHead column="release" sorter={sorter}>
                                    Release
                                </SortableHead>
                                <SortableHead column="isrc" sorter={sorter}>
                                    ISRC
                                </SortableHead>
                                <SortableHead column="contract" sorter={sorter}>
                                    Contract
                                </SortableHead>
                                <TableHead>Listen</TableHead>
                                <TableHead className="w-20" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {tracks.data.map((track) => (
                                <TableRow key={track.id}>
                                    <TableCell className="font-medium">
                                        <RecordLink to="track" id={track.id}>
                                            {track.name}
                                        </RecordLink>
                                    </TableCell>
                                    <TableCell>
                                        <RecordLink
                                            to="artist"
                                            id={track.artist_id}
                                        >
                                            {track.artist ?? '–'}
                                        </RecordLink>
                                    </TableCell>
                                    <TableCell>
                                        {track.releases.map(
                                            (release, position) => (
                                                <span key={release.id}>
                                                    {position > 0 && ', '}
                                                    <Link
                                                        href={showRelease(
                                                            release.id,
                                                        )}
                                                        className={
                                                            recordLinkClass
                                                        }
                                                    >
                                                        {release.name}
                                                    </Link>
                                                </span>
                                            ),
                                        )}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {track.isrc ?? '–'}
                                    </TableCell>
                                    <TableCell>
                                        {track.contract ? (
                                            track.contract.name
                                        ) : track.inherited_contract ? (
                                            <span className="text-muted-foreground">
                                                {track.inherited_contract.name}{' '}
                                                (release)
                                            </span>
                                        ) : (
                                            <Badge variant="warning">
                                                No contract
                                            </Badge>
                                        )}
                                        {track.without_contract_on.map(
                                            (release) => (
                                                <Badge
                                                    key={release}
                                                    variant="warning"
                                                    className="ml-2"
                                                >
                                                    No contract on {release}
                                                </Badge>
                                            ),
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {track.audio_file_id ? (
                                            <TrackActions
                                                track={{
                                                    id: track.id,
                                                    fileId: track.audio_file_id,
                                                    title: track.version
                                                        ? `${track.name} (${track.version})`
                                                        : track.name,
                                                    artist: track.credited_artist,
                                                    artistId: track.artist_id,
                                                    release: track.release && {
                                                        id: track.release.id,
                                                        name: track.release
                                                            .name,
                                                    },
                                                    coverFileId:
                                                        track.cover_file_id,
                                                }}
                                            />
                                        ) : (
                                            <span className="text-xs text-muted-foreground">
                                                No audio
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex justify-end">
                                            <TrackDialog
                                                track={track}
                                                genreOptions={genreOptions}
                                                inheritedContract={
                                                    track.inherited_contract
                                                }
                                            />
                                            <DeleteButton
                                                url={TrackController.destroy.url(
                                                    track.id,
                                                )}
                                                title={`Delete ${track.name}?`}
                                                description="Its transactions stay on the release but lose the track and any track-level contract."
                                            />
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <Pagination page={tracks} noun="tracks" />
                </Panel>
            )}
        </Page>
    );
}

Tracks.layout = { breadcrumbs: [{ title: 'Tracks', href: index() }] };
