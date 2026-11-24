import { setLayoutProps } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import ArtistController from '@/actions/App/Http/Controllers/ArtistController';
import ArtistImageController from '@/actions/App/Http/Controllers/ArtistImageController';
import { LabelImageUpload } from '@/components/label-image-upload';
import { Amount } from '@/components/amount';
import { ArtistFields } from '@/components/artist-fields';
import type { EditableArtist } from '@/components/artist-fields';
import { DeleteButton } from '@/components/delete-button';
import { FormDialog } from '@/components/form-dialog';
import { EmptyState, Page, Panel, Stat } from '@/components/page';
import { Pagination } from '@/components/pagination';
import { SortableHead } from '@/components/sortable-head';
import type { PlayerTrack } from '@/components/player/player-store';
import { ReleaseActions } from '@/components/player/track-actions';
import { RecordLink } from '@/components/record-link';
import { IncomeReport, MoversPanel } from '@/components/income-report';
import type { Movers, Report } from '@/components/income-report';
import { SegmentedBar } from '@/components/segmented-bar';
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
import { count, date, percent, plural } from '@/lib/format';
import { index, show } from '@/routes/artists';
import type { Paginated } from '@/types';

type Props = {
    artist: EditableArtist & {
        image_url: string | null;
        alias_of: { id: number; name: string } | null;
        aliases: { id: number; name: string }[];
    };
    totals: {
        income: string;
        expense: string;
        net: string;
        units: string;
        transactions: number;
        airplays: number;
    };
    incomeByRelease: { name: string; income: string }[];
    releases: Paginated<{
        id: number;
        name: string;
        catalog_number: string | null;
        released_on: string | null;
        income: string;
        player_tracks: PlayerTrack[];
    }>;
    filters: Record<string, string | null>;
    report: Report;
    movers: Movers;
    contracts: {
        id: number;
        name: string;
        splits: {
            payee: { id: number; name: string } | null;
            split_amount: string;
        }[];
    }[];
    tracks: Paginated<{
        id: number;
        name: string;
        isrc: string | null;
        releases: { id: number; name: string }[];
        airplays_count: number;
        income: string;
    }>;
};

export default function ShowArtist({
    artist,
    totals,
    incomeByRelease,
    releases,
    filters,
    contracts,
    report,
    movers,
    tracks,
}: Props) {
    const { apply } = useFilters(show.url(artist.id), filters);
    const releaseSorter = serverSorter(filters, apply, 'releases');
    const trackSorter = serverSorter(filters, apply, 'tracks');

    setLayoutProps({
        breadcrumbs: [
            { title: 'Artists', href: index() },
            { title: artist.name, href: '#' },
        ],
    });

    return (
        <Page
            title={artist.name}
            description={`${plural(releases.total, 'release')}, ${plural(tracks.total, 'track')}.`}
            actions={
                <>
                    <FormDialog
                        title={`Edit ${artist.name}`}
                        form={ArtistController.update.form(artist.id)}
                        trigger={
                            <Button variant="outline">
                                <Pencil />
                                Edit
                            </Button>
                        }
                    >
                        {(errors) => (
                            <ArtistFields artist={artist} errors={errors} />
                        )}
                    </FormDialog>
                    <DeleteButton
                        url={ArtistController.destroy.url(artist.id)}
                        label="Delete"
                        title={`Delete ${artist.name}?`}
                        description="Releases and tracks of this artist stay in the catalog but lose their artist."
                    />
                </>
            }
        >
            {(artist.alias_of || artist.aliases.length > 0) && (
                <p className="text-sm text-muted-foreground">
                    {artist.alias_of ? (
                        <>
                            Alias of{' '}
                            <RecordLink to="artist" id={artist.alias_of.id}>
                                {artist.alias_of.name}
                            </RecordLink>
                            . What statements book under this name belongs to
                            that artist.
                        </>
                    ) : (
                        <>
                            Also named on statements as:{' '}
                            {artist.aliases.map((alias, position) => (
                                <span key={alias.id}>
                                    {position > 0 && ' · '}
                                    <RecordLink to="artist" id={alias.id}>
                                        {alias.name}
                                    </RecordLink>
                                </span>
                            ))}
                        </>
                    )}
                </p>
            )}
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Stat
                    label="Income to date"
                    value={<Amount value={totals.income} />}
                    hint={`${count(totals.units)} units in ${count(totals.transactions)} transactions`}
                />
                <Stat
                    label="Expenses to date"
                    value={<Amount value={totals.expense} />}
                />
                <Stat
                    label="Net"
                    value={<Amount value={totals.net} />}
                    hint="Income less expenses, before any split"
                />
                <Stat
                    label="Airplay"
                    value={count(totals.airplays)}
                    hint="Plays known across all tracks"
                />
            </div>
            <p className="-mt-2 text-xs text-muted-foreground">
                Counts everything booked on this artist&apos;s releases and what
                their tracks earned on other releases. A release shared with
                other artists counts in full for each of them.
            </p>

            {incomeByRelease.length > 1 && (
                <Panel
                    title="Income by release"
                    description="What each release has brought in to date."
                >
                    <SegmentedBar
                        format={(value) => <Amount value={value} />}
                        segments={[...incomeByRelease]
                            .sort((a, b) => Number(b.income) - Number(a.income))
                            .map((release) => ({
                                label: release.name,
                                value: Number(release.income),
                            }))}
                    />
                </Panel>
            )}

            <IncomeReport report={report} />

            <MoversPanel
                movers={movers}
                to="release"
                title="Releases by income"
            />

            <Panel
                title="Picture"
                description="Shown beside the artist in lists and dropdowns. Without one, the cover of their latest release stands in."
            >
                <div className="max-w-sm">
                    <LabelImageUpload
                        kind="picture"
                        title="Artist picture"
                        hint="PNG, JPEG or WebP, up to 4 MB."
                        current={artist.image_url}
                        urls={{
                            store: ArtistImageController.store.url(artist.id),
                            destroy: ArtistImageController.destroy.url(
                                artist.id,
                            ),
                        }}
                    />
                </div>
            </Panel>

            <Panel title="Releases" flush>
                {releases.total === 0 ? (
                    <div className="p-4">
                        <EmptyState title="No releases credited to this artist" />
                    </div>
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
                                    <SortableHead
                                        column="released_on"
                                        sorter={releaseSorter}
                                        first="desc"
                                    >
                                        Released
                                    </SortableHead>
                                    <SortableHead
                                        column="income"
                                        sorter={releaseSorter}
                                        first="desc"
                                        className="text-right"
                                    >
                                        Income
                                    </SortableHead>
                                    <TableHead>
                                        <span className="sr-only">Play</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {releases.data.map((release) => (
                                    <TableRow key={release.id}>
                                        <TableCell className="font-medium">
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
                                        <TableCell className="text-muted-foreground">
                                            {release.released_on
                                                ? date(release.released_on)
                                                : '–'}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Amount value={release.income} />
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center justify-end gap-1">
                                                <ReleaseActions
                                                    tracks={
                                                        release.player_tracks
                                                    }
                                                    name={release.name}
                                                />
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <Pagination page={releases} noun="releases" />
                    </>
                )}
            </Panel>

            <Panel title="Contracts and payees" flush>
                {contracts.length === 0 ? (
                    <div className="p-4">
                        <EmptyState title="No contract on this artist's releases or tracks" />
                    </div>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Contract</TableHead>
                                <TableHead>Payees and their share</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {contracts.map((contract) => (
                                <TableRow key={contract.id}>
                                    <TableCell className="font-medium">
                                        <RecordLink
                                            to="contract"
                                            id={contract.id}
                                        >
                                            {contract.name}
                                        </RecordLink>
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex flex-wrap gap-x-4 gap-y-1">
                                            {contract.splits.map(
                                                (split, position) => (
                                                    <span key={position}>
                                                        <RecordLink
                                                            to="payee"
                                                            id={split.payee?.id}
                                                        >
                                                            {split.payee
                                                                ?.name ?? '–'}
                                                        </RecordLink>{' '}
                                                        <span className="text-muted-foreground tabular-nums">
                                                            {percent(
                                                                split.split_amount,
                                                            )}
                                                        </span>
                                                    </span>
                                                ),
                                            )}
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>

            <Panel title="Tracks" flush>
                {tracks.total === 0 ? (
                    <div className="p-4">
                        <EmptyState title="No tracks credited to this artist" />
                    </div>
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
                                    <SortableHead
                                        column="isrc"
                                        sorter={trackSorter}
                                    >
                                        ISRC
                                    </SortableHead>
                                    <SortableHead
                                        column="airplays_count"
                                        sorter={trackSorter}
                                        first="desc"
                                        className="text-right"
                                    >
                                        Airplay
                                    </SortableHead>
                                    <SortableHead
                                        column="income"
                                        sorter={trackSorter}
                                        first="desc"
                                        className="text-right"
                                    >
                                        Income
                                    </SortableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {tracks.data.map((track) => (
                                    <TableRow key={track.id}>
                                        <TableCell className="font-medium">
                                            <RecordLink
                                                to="track"
                                                id={track.id}
                                            >
                                                {track.name}
                                            </RecordLink>
                                        </TableCell>
                                        <TableCell>
                                            {track.releases.length === 0 && '–'}
                                            {track.releases.map(
                                                (release, position) => (
                                                    <span key={release.id}>
                                                        {position > 0 && ', '}
                                                        <RecordLink
                                                            to="release"
                                                            id={release.id}
                                                        >
                                                            {release.name}
                                                        </RecordLink>
                                                    </span>
                                                ),
                                            )}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {track.isrc ?? '–'}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {count(track.airplays_count)}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Amount value={track.income} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <Pagination page={tracks} noun="tracks" />
                    </>
                )}
            </Panel>
        </Page>
    );
}
