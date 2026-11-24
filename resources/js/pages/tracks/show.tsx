import { AgreementLinks } from '@/components/agreement-links';
import { PlatformLinks } from '@/components/platform-links';
import type { PlatformLinksProps } from '@/components/platform-links';
import { Link, router, setLayoutProps } from '@inertiajs/react';
import { ExternalLink, Pencil, Radar } from 'lucide-react';
import AirplayResearchController from '@/actions/App/Http/Controllers/AirplayResearchController';
import TrackController from '@/actions/App/Http/Controllers/TrackController';
import { Amount } from '@/components/amount';
import { DeleteButton } from '@/components/delete-button';
import { IncomeReport } from '@/components/income-report';
import type { Report } from '@/components/income-report';
import { EmptyState, Page, Panel, Stat } from '@/components/page';
import { Pagination } from '@/components/pagination';
import { TrackActions } from '@/components/player/track-actions';
import { RecordLink } from '@/components/record-link';
import { SortableHead } from '@/components/sortable-head';
import { TrackDialog } from '@/components/track-dialog';
import type { EditableTrack } from '@/components/track-fields';
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
import { count, country, date, dateTime, duration, plural } from '@/lib/format';
import { index as airplayIndex } from '@/routes/airplays';
import { index, show as showTrack } from '@/routes/tracks';
import type { Paginated } from '@/types';
import { index as transactions } from '@/routes/transactions';
import { ORIGINS } from '@/lib/origins';

type Named = { id: number; name: string };

type Airplay = {
    id: number;
    origin: 'statement' | 'search' | 'manual';
    station: string | null;
    media_type: string | null;
    region: string | null;
    society: string | null;
    amount: string | null;
    notes: string | null;
    data_import_id: number | null;
    period_start: string | null;
    period_end: string | null;
    links: { url: string; title: string | null }[];
};

type Props = {
    platformLinks: PlatformLinksProps;
    track: EditableTrack & {
        credited_artist: string | null;
        credited_artists: Named[];
        release: (Named & { catalog_number: string | null }) | null;
        cover_file_id: number | null;
        audio_file_id: number | null;
        contract: Named | null;
        researched_at: string | null;
        research_state: 'queued' | 'running' | 'failed' | null;
        research_step: string | null;
    };
    report: Report;
    income: {
        total: string;
        transactions: number;
    };
    releases: {
        track_id: number;
        id: number;
        name: string;
        catalog_number: string | null;
        income: string;
    }[];
    airplays: Paginated<Airplay>;
    airplayTotals: { plays: number; with_station: number; earned: string };
    filters: Record<string, string | null>;
    openFindings: number;
    searches: Paginated<{
        id: number;
        query: string;
        region: string | null;
        succeeded: boolean;
        new_findings: number;
        error: string | null;
        results: number;
        created_at: string;
    }>;
    searchAvailable: boolean;
    genreOptions: string[];
};

function Fact({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <dt className="text-xs font-medium text-muted-foreground">
                {label}
            </dt>
            <dd className="mt-0.5 text-sm">{children}</dd>
        </div>
    );
}

export default function ShowTrack({
    track,
    income,
    report,
    releases,
    airplays,
    airplayTotals,
    filters,
    openFindings,
    searches,
    searchAvailable,
    platformLinks,
    genreOptions,
}: Props) {
    const title = track.version
        ? `${track.name} (${track.version})`
        : track.name;
    const contract = track.contract;
    const { apply } = useFilters(showTrack.url(track.id), filters);
    const airplaySorter = serverSorter(filters, apply, 'airplays');
    const searchSorter = serverSorter(filters, apply, 'searches');
    const busy =
        track.research_state === 'queued' || track.research_state === 'running';

    setLayoutProps({
        breadcrumbs: [
            { title: 'Tracks', href: index() },
            { title, href: '#' },
        ],
    });

    return (
        <Page
            title={title}
            description={
                <span>
                    {track.credited_artists.length > 0
                        ? track.credited_artists.map((artist, position) => (
                              <span key={artist.id}>
                                  {position > 0 && ', '}
                                  <RecordLink to="artist" id={artist.id}>
                                      {artist.name}
                                  </RecordLink>
                              </span>
                          ))
                        : (track.credited_artist ?? 'No artist')}
                    {track.release && (
                        <>
                            {' · on '}
                            <RecordLink to="release" id={track.release.id}>
                                {track.release.name}
                            </RecordLink>
                        </>
                    )}
                </span>
            }
            actions={
                <>
                    {track.audio_file_id && (
                        <TrackActions
                            track={{
                                id: track.id,
                                fileId: track.audio_file_id,
                                title,
                                artist: track.credited_artist,
                                artistId: track.credited_artists[0]?.id ?? null,
                                release: track.release && {
                                    id: track.release.id,
                                    name: track.release.name,
                                },
                                coverFileId: track.cover_file_id,
                            }}
                        />
                    )}
                    {searchAvailable && (
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() =>
                                router.post(
                                    AirplayResearchController.store.url(),
                                    { scope: 'tracks', track_ids: [track.id] },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <Radar />
                            {busy ? 'Research queued' : 'Research airplay'}
                        </Button>
                    )}
                    <TrackDialog
                        track={track}
                        genreOptions={genreOptions}
                        trigger={
                            <Button variant="outline">
                                <Pencil />
                                Edit
                            </Button>
                        }
                    />
                    <DeleteButton
                        url={TrackController.destroy.url(track.id)}
                        label="Delete"
                        title={`Delete ${track.name}?`}
                        description="Its transactions stay on the release but lose the track and any track-level contract."
                    />
                </>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Stat
                    label="Income"
                    value={<Amount value={income.total} />}
                    hint={
                        <Link
                            href={transactions({ query: { track: track.id } })}
                            className="underline underline-offset-4"
                        >
                            {plural(income.transactions, 'transaction')}
                        </Link>
                    }
                />
                <Stat
                    label="Airplay"
                    value={count(airplayTotals.plays)}
                    hint={`${count(airplayTotals.with_station)} with a known station`}
                />
                <Stat
                    label="Earned from airplay"
                    value={<Amount value={airplayTotals.earned} />}
                />
                <Stat
                    label="Findings to review"
                    value={openFindings}
                    hint={
                        track.research_state === 'running'
                            ? (track.research_step ?? 'Researching')
                            : track.research_state === 'queued'
                              ? 'Waiting for research'
                              : track.research_state === 'failed'
                                ? `Research failed: ${track.research_step ?? ''}`
                                : track.researched_at
                                  ? `Researched ${date(track.researched_at)}`
                                  : 'Not researched yet'
                    }
                />
            </div>

            <Panel title="Details">
                <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Fact label="ISRC">
                        <span className="font-mono text-xs">
                            {track.isrc ?? '–'}
                        </span>
                    </Fact>
                    <Fact label="Release">
                        {track.release ? (
                            <RecordLink to="release" id={track.release.id}>
                                {track.release.name}
                            </RecordLink>
                        ) : (
                            '–'
                        )}
                        {track.release?.catalog_number && (
                            <span className="text-muted-foreground">
                                {' '}
                                · {track.release.catalog_number}
                            </span>
                        )}
                    </Fact>
                    <Fact label="Contract">
                        {contract ? (
                            <RecordLink to="contract" id={contract.id}>
                                {contract.name}
                            </RecordLink>
                        ) : (
                            '–'
                        )}
                    </Fact>
                    <Fact label="Length">{duration(track.duration)}</Fact>
                    <Fact label="Track number">
                        {track.track_number ?? '–'}
                    </Fact>
                    <Fact label="Genre">{track.genre ?? '–'}</Fact>
                    <Fact label="BPM / key">
                        {[track.bpm, track.musical_key]
                            .filter(Boolean)
                            .join(' · ') || '–'}
                    </Fact>
                    <Fact label="ISWC">{track.iswc ?? '–'}</Fact>
                </dl>
            </Panel>

            <PlatformLinks
                owner={{ track_id: track.id }}
                platformLinks={platformLinks}
            />

            {releases.length > 1 && (
                <Panel
                    title={`On ${releases.length} releases`}
                    description="This recording is on several releases. Airplay, findings and income on this page cover all of them."
                    flush
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Release</TableHead>
                                <TableHead>Catalog number</TableHead>
                                <TableHead className="text-right">
                                    Income there
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {releases.map((release) => (
                                <TableRow
                                    key={`${release.track_id}-${release.id}`}
                                >
                                    <TableCell className="font-medium">
                                        <RecordLink
                                            to="release"
                                            id={release.id}
                                        >
                                            {release.name}
                                        </RecordLink>
                                        {release.track_id === track.id &&
                                            releases.some(
                                                (other) =>
                                                    other.track_id !== track.id,
                                            ) && (
                                                <span className="font-normal text-muted-foreground">
                                                    {' '}
                                                    (this entry)
                                                </span>
                                            )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {release.catalog_number ?? '–'}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount value={release.income} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Panel>
            )}

            <Panel
                title="Airplay"
                description="Where this track was played and how we know."
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link
                            href={airplayIndex({ query: { track: track.id } })}
                        >
                            Manage airplay and findings
                        </Link>
                    </Button>
                }
                flush
            >
                {airplays.total === 0 ? (
                    <div className="p-4">
                        <EmptyState
                            title="No airplay known yet"
                            description="Import a neighbouring rights statement, start a research, or add a play by hand on the Airplay page."
                        />
                    </div>
                ) : (
                    <>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <SortableHead
                                        column="station"
                                        sorter={airplaySorter}
                                    >
                                        Station
                                    </SortableHead>
                                    <SortableHead
                                        column="origin"
                                        sorter={airplaySorter}
                                    >
                                        Known from
                                    </SortableHead>
                                    <SortableHead
                                        column="period"
                                        sorter={airplaySorter}
                                    >
                                        Played
                                    </SortableHead>
                                    <SortableHead
                                        column="amount"
                                        sorter={airplaySorter}
                                        className="text-right"
                                    >
                                        Earned
                                    </SortableHead>
                                    <TableHead>Links</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {airplays.data.map((airplay) => (
                                    <TableRow key={airplay.id}>
                                        <TableCell>
                                            {airplay.station ?? (
                                                <span className="text-muted-foreground italic">
                                                    Unknown
                                                </span>
                                            )}
                                            <p className="text-xs text-muted-foreground">
                                                {[
                                                    airplay.media_type,
                                                    airplay.region &&
                                                        country(airplay.region),
                                                    airplay.society,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </p>
                                        </TableCell>
                                        <TableCell>
                                            <RecordLink
                                                to="import"
                                                id={
                                                    airplay.origin ===
                                                    'statement'
                                                        ? airplay.data_import_id
                                                        : null
                                                }
                                                className="no-underline"
                                            >
                                                <Badge variant="secondary">
                                                    {ORIGINS[airplay.origin]}
                                                </Badge>
                                            </RecordLink>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {airplay.period_start
                                                ? airplay.period_end &&
                                                  airplay.period_end !==
                                                      airplay.period_start
                                                    ? `${date(airplay.period_start)} – ${date(airplay.period_end)}`
                                                    : date(airplay.period_start)
                                                : '–'}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {airplay.amount === null ? (
                                                '–'
                                            ) : (
                                                <Amount
                                                    value={airplay.amount}
                                                />
                                            )}
                                        </TableCell>
                                        <TableCell className="max-w-sm whitespace-normal">
                                            {airplay.links.length === 0
                                                ? '–'
                                                : airplay.links.map((link) => (
                                                      <a
                                                          key={link.url}
                                                          href={link.url}
                                                          target="_blank"
                                                          rel="noreferrer noopener"
                                                          className="flex items-center gap-1 text-sm hover:underline"
                                                      >
                                                          <span className="truncate">
                                                              {link.title ??
                                                                  link.url}
                                                          </span>
                                                          <ExternalLink className="size-3 shrink-0" />
                                                      </a>
                                                  ))}
                                            {airplay.notes && (
                                                <p className="text-xs text-muted-foreground">
                                                    {airplay.notes}
                                                </p>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <Pagination page={airplays} noun="plays" />
                    </>
                )}
            </Panel>

            <IncomeReport report={report} />

            {searches.total > 0 && (
                <Panel
                    title="Research log"
                    description="Every search run for this track."
                    flush
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <SortableHead
                                    column="created_at"
                                    sorter={searchSorter}
                                    first="desc"
                                >
                                    When
                                </SortableHead>
                                <SortableHead
                                    column="query"
                                    sorter={searchSorter}
                                >
                                    Searched
                                </SortableHead>
                                <SortableHead
                                    column="result"
                                    sorter={searchSorter}
                                    first="desc"
                                >
                                    Result
                                </SortableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {searches.data.map((entry) => (
                                <TableRow key={entry.id}>
                                    <TableCell className="text-muted-foreground">
                                        {dateTime(entry.created_at)}
                                    </TableCell>
                                    <TableCell className="max-w-md whitespace-normal">
                                        {entry.query}
                                        <span className="text-muted-foreground">
                                            {entry.region
                                                ? ` · ${country(entry.region)}`
                                                : ''}
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        {entry.succeeded ? (
                                            `${plural(entry.results, 'result')}, ${plural(entry.new_findings, 'new finding')}`
                                        ) : (
                                            <span className="text-red-600 dark:text-red-400">
                                                {entry.error ?? 'Failed'}
                                            </span>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <Pagination page={searches} noun="searches" />
                </Panel>
            )}
            <AgreementLinks />
        </Page>
    );
}
