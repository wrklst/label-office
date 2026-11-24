import { Form, router, useHttp } from '@inertiajs/react';
import {
    Check,
    ExternalLink,
    Link2,
    Pencil,
    Plus,
    Radar,
    Search,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import AirplayController from '@/actions/App/Http/Controllers/AirplayController';
import AirplayResearchController from '@/actions/App/Http/Controllers/AirplayResearchController';
import { FilterBar } from '@/components/filter-bar';
import { Amount } from '@/components/amount';
import { DeleteButton } from '@/components/delete-button';
import { Field, SelectInput } from '@/components/field';
import { FormDialog } from '@/components/form-dialog';
import InputError from '@/components/input-error';
import { EmptyState, Page, Panel } from '@/components/page';
import { Pagination } from '@/components/pagination';
import { RecordLink } from '@/components/record-link';
import { RecordSelect } from '@/components/record-select';
import { SortableHead } from '@/components/sortable-head';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
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
import { country, date, dateTime, plural } from '@/lib/format';
import { index } from '@/routes/airplays';
import type { Paginated } from '@/types';
import { ORIGINS } from '@/lib/origins';
import type { Origin } from '@/lib/origins';

type AirplayLink = {
    url: string;
    title: string | null;
    description: string | null;
    source?: Origin;
};

type Finding = {
    id: number;
    track_id: number | null;
    is_radio: boolean;
    station: string | null;
    url: string;
    title: string;
    description: string | null;
    domain: string | null;
    query: string;
    airplay_id: number | null;
    airplay_station: string | null;
    track: string | null;
    verification: 'confirmed' | 'partial' | 'absent' | 'unreachable' | null;
    page_title: string | null;
    show: string | null;
    excerpt: string | null;
};

/** What opening the found page itself showed. */
const VERIFICATIONS: Record<string, string> = {
    confirmed: 'Artist and title are on the page',
    partial: 'Only artist or title is on the page',
    absent: 'Page does not name the track',
    unreachable: 'Page could not be opened',
};

type Airplay = {
    id: number;
    artist_id: number | null;
    origin: Origin;
    track_id: number | null;
    track_name: string;
    artist_name: string | null;
    isrc: string | null;
    station: string | null;
    media_type: string | null;
    region: string | null;
    society: string | null;
    amount: string | null;
    notes: string | null;
    data_import_id: number | null;
    period_start: string | null;
    period_end: string | null;
    links: AirplayLink[];
    search_query: string;
    researched_at: string | null;
    research_state: ResearchStateName | null;
    research_step: string | null;
};

type ResearchStateName = 'queued' | 'running' | 'failed';

type TrackResearch = {
    id: number;
    name: string;
    state: ResearchStateName;
    step: string | null;
};

type SearchEntry = {
    id: number;
    track_id: number | null;
    query: string;
    region: string | null;
    succeeded: boolean;
    new_findings: number;
    error: string | null;
    airplay_id: number | null;
    track: string | null;
    station: string | null;
    results: SearchResult[];
    created_at: string;
};

/** The station of a play, or how to call a play whose station is not known. */
function stationName(airplay: Airplay): string {
    return airplay.station ?? 'an unknown station';
}

type SearchResult = {
    title: string;
    url: string;
    description: string | null;
    domain: string | null;
};

function period(airplay: Airplay): string {
    if (!airplay.period_start) {
        return '–';
    }

    return airplay.period_end && airplay.period_end !== airplay.period_start
        ? `${date(airplay.period_start)} – ${date(airplay.period_end)}`
        : date(airplay.period_start);
}

function AirplayFields({
    airplay,
    errors,
}: {
    airplay?: Airplay;
    errors: Record<string, string>;
}) {
    return (
        <>
            <Field
                label="Track"
                htmlFor="track_id"
                error={errors.track_id}
                hint="Pick a track from the catalog, or type the names below."
            >
                <RecordSelect
                    id="track_id"
                    name="track_id"
                    records="tracks"
                    placeholder="Not in the catalog"
                    defaultValue={airplay?.track_id}
                />
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Track name"
                    htmlFor="track_name"
                    error={errors.track_name}
                >
                    <Input
                        id="track_name"
                        name="track_name"
                        defaultValue={airplay?.track_name}
                    />
                </Field>
                <Field
                    label="Artist"
                    htmlFor="artist_name"
                    error={errors.artist_name}
                >
                    <Input
                        id="artist_name"
                        name="artist_name"
                        defaultValue={airplay?.artist_name ?? ''}
                    />
                </Field>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Station"
                    htmlFor="station"
                    error={errors.station}
                    hint="Leave empty when you do not know it yet."
                >
                    <Input
                        id="station"
                        name="station"
                        defaultValue={airplay?.station ?? ''}
                        placeholder="FluxFM"
                    />
                </Field>
                <Field
                    label="Country"
                    htmlFor="region"
                    error={errors.region}
                    hint="Two-letter code, e.g. DE."
                >
                    <Input
                        id="region"
                        name="region"
                        maxLength={2}
                        defaultValue={airplay?.region ?? ''}
                    />
                </Field>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Played on / from"
                    htmlFor="period_start"
                    error={errors.period_start}
                >
                    <Input
                        id="period_start"
                        name="period_start"
                        type="date"
                        defaultValue={airplay?.period_start ?? ''}
                    />
                </Field>
                <Field
                    label="Until"
                    htmlFor="period_end"
                    error={errors.period_end}
                    hint="Leave empty for a single day."
                >
                    <Input
                        id="period_end"
                        name="period_end"
                        type="date"
                        defaultValue={airplay?.period_end ?? ''}
                    />
                </Field>
            </div>
            <Field label="Notes" htmlFor="notes" error={errors.notes}>
                <Textarea
                    id="notes"
                    name="notes"
                    defaultValue={airplay?.notes ?? ''}
                    placeholder="Show, presenter, how you found out…"
                />
            </Field>
        </>
    );
}

/**
 * The links kept for a play, and a web search to find more.
 */
function LinksDialog({
    airplay,
    searchAvailable,
}: {
    airplay: Airplay;
    searchAvailable: boolean;
}) {
    const [open, setOpen] = useState(false);
    const search = useHttp<{ query: string }, { results: SearchResult[] }>({
        query: airplay.search_query,
    });
    const [results, setResults] = useState<SearchResult[] | null>(null);
    const saved = new Set(airplay.links.map((link) => link.url));

    const save = (link: AirplayLink) =>
        router.post(AirplayController.storeLink.url(airplay.id), link, {
            preserveScroll: true,
            preserveState: true,
        });

    const runSearch = async (event: React.FormEvent) => {
        event.preventDefault();

        try {
            const response = await search.post(
                AirplayController.search.url(airplay.id),
            );
            setResults(response.results);
        } catch {
            // The reason is in search.errors.query.
            setResults(null);
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    aria-label={`Links for ${airplay.track_name} on ${stationName(airplay)}`}
                >
                    <Link2 className="text-muted-foreground" />
                    {airplay.links.length}
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-x-hidden overflow-y-auto sm:max-w-2xl [&>*]:min-w-0">
                <DialogHeader>
                    <DialogTitle>
                        {airplay.track_name} on {stationName(airplay)}
                    </DialogTitle>
                    <DialogDescription>
                        Keep playlists, show pages and anything else you found
                        about this play.
                    </DialogDescription>
                </DialogHeader>

                {airplay.links.length > 0 && (
                    <ul className="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-2">
                        {airplay.links.map((link) => (
                            <li
                                key={link.url}
                                className="flex items-start gap-2 rounded-md border p-2"
                            >
                                <div className="min-w-0 flex-1">
                                    <a
                                        href={link.url}
                                        target="_blank"
                                        rel="noreferrer noopener"
                                        className="flex items-center gap-1 text-sm font-medium hover:underline"
                                    >
                                        <span className="truncate">
                                            {link.title ?? link.url}
                                        </span>
                                        <ExternalLink className="size-3 shrink-0" />
                                    </a>
                                    <p className="truncate text-xs text-muted-foreground">
                                        {ORIGINS[link.source ?? 'manual']}
                                        {' · '}
                                        {link.url}
                                    </p>
                                    {link.description && (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {link.description}
                                        </p>
                                    )}
                                </div>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Remove ${link.title ?? link.url}`}
                                    onClick={() =>
                                        router.delete(
                                            AirplayController.destroyLink.url(
                                                airplay.id,
                                            ),
                                            {
                                                data: { url: link.url },
                                                preserveScroll: true,
                                                preserveState: true,
                                            },
                                        )
                                    }
                                >
                                    <X className="text-muted-foreground" />
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}

                <Form
                    {...AirplayController.storeLink.form(airplay.id)}
                    options={{ preserveScroll: true, preserveState: true }}
                    resetOnSuccess
                    className="grid gap-2 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto]"
                >
                    {({ errors, processing }) => (
                        <>
                            <div>
                                <Input
                                    name="url"
                                    type="url"
                                    placeholder="https://…"
                                    aria-label="Link"
                                    required
                                />
                                <InputError message={errors.url} />
                            </div>
                            <Input
                                name="title"
                                placeholder="Title (optional)"
                                aria-label="Title"
                            />
                            <Button variant="secondary" disabled={processing}>
                                <Plus />
                                Add link
                            </Button>
                        </>
                    )}
                </Form>

                <div className="grid grid-cols-[minmax(0,1fr)] gap-2 border-t pt-4">
                    <h3 className="text-sm font-medium">Search the web</h3>
                    <p className="text-xs text-muted-foreground">
                        A search takes about half a minute.
                    </p>
                    {searchAvailable ? (
                        <form
                            onSubmit={runSearch}
                            className="flex min-w-0 gap-2 [&>input]:min-w-0"
                        >
                            <Input
                                value={search.data.query}
                                onChange={(event) =>
                                    search.setData('query', event.target.value)
                                }
                                aria-label="Search words"
                            />
                            <Button disabled={search.processing}>
                                <Search />
                                {search.processing ? 'Searching…' : 'Search'}
                            </Button>
                        </form>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Web search is not set up. Enter the DataForSEO login
                            under Settings → Connections.
                        </p>
                    )}
                    <InputError message={search.errors.query} />
                    {results?.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            Nothing found. Try fewer or other words.
                        </p>
                    )}
                    {results && results.length > 0 && (
                        <ul className="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-2">
                            {results.map((result) => (
                                <li
                                    key={result.url}
                                    className="flex items-start gap-2 rounded-md border p-2"
                                >
                                    <div className="min-w-0 flex-1">
                                        <a
                                            href={result.url}
                                            target="_blank"
                                            rel="noreferrer noopener"
                                            className="flex items-center gap-1 text-sm font-medium hover:underline"
                                        >
                                            <span className="truncate">
                                                {result.title}
                                            </span>
                                            <ExternalLink className="size-3 shrink-0" />
                                        </a>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {result.domain ?? result.url}
                                        </p>
                                        {result.description && (
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {result.description}
                                            </p>
                                        )}
                                    </div>
                                    <Button
                                        variant="secondary"
                                        size="sm"
                                        disabled={saved.has(result.url)}
                                        onClick={() =>
                                            save({
                                                url: result.url,
                                                title: result.title,
                                                description: result.description,
                                                source: 'search',
                                            })
                                        }
                                    >
                                        {saved.has(result.url)
                                            ? 'Saved'
                                            : 'Save'}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Start the automatic research for a group of tracks.
 */
function ResearchDialog({
    trackCount,
    staleCount,
    staleDays,
}: {
    trackCount: number;
    staleCount: number;
    staleDays: number;
}) {
    const [scope, setScope] = useState('stale');
    const [pickedTracks, setPickedTracks] = useState<(number | '')[]>(['']);

    return (
        <FormDialog
            title="Research airplay"
            description="Each track is searched on the web: once in general and once for every station a statement named. Pages that may show a play are listed as findings for you to keep or dismiss. Every search is billed by DataForSEO and takes about half a minute, so a large group runs in the background for a while."
            form={AirplayResearchController.store.form()}
            submitLabel="Start research"
            trigger={
                <Button variant="outline">
                    <Radar />
                    Research
                </Button>
            }
        >
            {(errors) => (
                <>
                    <Field label="Tracks" htmlFor="scope" error={errors.scope}>
                        <SelectInput
                            id="scope"
                            name="scope"
                            value={scope}
                            onChange={(event) => setScope(event.target.value)}
                        >
                            <option value="stale">
                                Not researched in the last {staleDays} days (
                                {staleCount})
                            </option>
                            <option value="release">One release</option>
                            <option value="tracks">Selected tracks</option>
                            <option value="all">
                                Every track ({trackCount})
                            </option>
                        </SelectInput>
                    </Field>
                    {scope === 'release' && (
                        <Field
                            label="Release"
                            htmlFor="release_id"
                            error={errors.release_id}
                            required
                        >
                            <RecordSelect
                                id="release_id"
                                name="release_id"
                                records="releases"
                                placeholder="Choose a release…"
                                required
                            />
                        </Field>
                    )}
                    {scope === 'tracks' && (
                        <Field
                            label="Tracks"
                            htmlFor="track_ids_0"
                            error={errors.track_ids}
                            hint="Add a row for every track."
                            required
                        >
                            <div className="grid gap-2">
                                {pickedTracks.map((picked, position) => (
                                    <div key={position} className="flex gap-2">
                                        <RecordSelect
                                            id={`track_ids_${position}`}
                                            aria-label={`Track ${position + 1}`}
                                            name="track_ids[]"
                                            records="tracks"
                                            value={picked}
                                            onChange={(id) =>
                                                setPickedTracks(
                                                    pickedTracks.map(
                                                        (current, at) =>
                                                            at === position
                                                                ? id
                                                                : current,
                                                    ),
                                                )
                                            }
                                            placeholder="Choose track…"
                                            required
                                            className="flex-1"
                                        />
                                        {pickedTracks.length > 1 && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Remove track ${position + 1}`}
                                                onClick={() =>
                                                    setPickedTracks(
                                                        pickedTracks.filter(
                                                            (_, at) =>
                                                                at !== position,
                                                        ),
                                                    )
                                                }
                                            >
                                                <X />
                                            </Button>
                                        )}
                                    </div>
                                ))}
                                <div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setPickedTracks([
                                                ...pickedTracks,
                                                '',
                                            ])
                                        }
                                    >
                                        <Plus />
                                        Add track
                                    </Button>
                                </div>
                            </div>
                        </Field>
                    )}
                </>
            )}
        </FormDialog>
    );
}

/**
 * Pages the research found, waiting to be kept or dismissed.
 */
function Findings({ findings }: { findings: Paginated<Finding> }) {
    const decide = (url: string) =>
        router.post(url, {}, { preserveScroll: true, preserveState: true });

    return (
        <Panel
            title={`Findings to review (${findings.total})`}
            description="Found by the automatic research. Keep a page to store it with the play, or as a new play when the station was not known yet. A dismissed page is not suggested again."
            flush
        >
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Track</TableHead>
                        <TableHead>Page</TableHead>
                        <TableHead>Why</TableHead>
                        <TableHead className="w-44" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {findings.data.map((finding) => (
                        <TableRow key={finding.id}>
                            <TableCell className="align-top font-medium">
                                <RecordLink to="track" id={finding.track_id}>
                                    {finding.track ?? '–'}
                                </RecordLink>
                            </TableCell>
                            <TableCell className="max-w-md align-top whitespace-normal">
                                <a
                                    href={finding.url}
                                    target="_blank"
                                    rel="noreferrer noopener"
                                    className="flex items-center gap-1 font-medium hover:underline"
                                >
                                    <span className="truncate">
                                        {finding.title}
                                    </span>
                                    <ExternalLink className="size-3 shrink-0" />
                                </a>
                                <p className="text-xs text-muted-foreground">
                                    {finding.domain}
                                </p>
                                {finding.show && (
                                    <p className="mt-1 text-xs font-medium">
                                        Show: {finding.show}
                                    </p>
                                )}
                                {(finding.excerpt ?? finding.description) && (
                                    <p className="mt-1 line-clamp-3 text-xs text-muted-foreground">
                                        {finding.excerpt ?? finding.description}
                                    </p>
                                )}
                            </TableCell>
                            <TableCell className="align-top whitespace-normal">
                                {finding.station ? (
                                    <Badge>{finding.station}</Badge>
                                ) : finding.is_radio ? (
                                    <Badge variant="success">
                                        Looks like radio
                                    </Badge>
                                ) : (
                                    <Badge variant="outline">Mention</Badge>
                                )}
                                <p
                                    className={
                                        finding.verification === 'confirmed'
                                            ? 'mt-1 text-xs font-medium text-green-700 dark:text-green-400'
                                            : 'mt-1 text-xs text-muted-foreground'
                                    }
                                >
                                    {finding.verification
                                        ? VERIFICATIONS[finding.verification]
                                        : 'Page not checked'}
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {finding.airplay_station &&
                                        `For the play on ${finding.airplay_station}. `}
                                    Searched: {finding.query}
                                </p>
                            </TableCell>
                            <TableCell className="align-top">
                                <div className="flex justify-end gap-1">
                                    <Button
                                        variant="secondary"
                                        size="sm"
                                        onClick={() =>
                                            decide(
                                                AirplayResearchController.keep.url(
                                                    finding.id,
                                                ),
                                            )
                                        }
                                    >
                                        <Check />
                                        Keep
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            decide(
                                                AirplayResearchController.dismiss.url(
                                                    finding.id,
                                                ),
                                            )
                                        }
                                    >
                                        <X />
                                        Dismiss
                                    </Button>
                                </div>
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
            <Pagination page={findings} noun="findings" />
        </Panel>
    );
}

/**
 * What the queue is doing right now: the research that runs and what waits behind it.
 */
function ResearchProgress({
    tracks,
    airplays,
}: {
    tracks: TrackResearch[];
    airplays: TrackResearch[];
}) {
    const entries = [
        ...tracks.map((track) => ({
            key: `track-${track.id}`,
            label: track.name,
            state: track.state,
            step: track.step,
        })),
        ...airplays.map((airplay) => ({
            key: `airplay-${airplay.id}`,
            label: airplay.name,
            state: airplay.state,
            step: airplay.step,
        })),
    ];
    const running = entries.filter((entry) => entry.state === 'running');
    const queued = entries.filter((entry) => entry.state === 'queued');
    const failed = entries.filter((entry) => entry.state === 'failed');

    if (entries.length === 0) {
        return null;
    }

    return (
        <Panel
            title={
                running.length + queued.length > 0
                    ? `Research in progress: ${running.length} running, ${queued.length} waiting`
                    : 'Research finished with problems'
            }
            description="This updates by itself every few seconds. A search takes about half a minute; findings appear below as they come in."
        >
            <ul className="grid gap-2 text-sm" aria-live="polite">
                {running.map((entry) => (
                    <li key={entry.key} className="flex items-start gap-2">
                        <Spinner className="mt-0.5 shrink-0" />
                        <span>
                            <span className="font-medium">{entry.label}</span>
                            <span className="block text-xs text-muted-foreground">
                                {entry.step ?? 'Starting'}
                            </span>
                        </span>
                    </li>
                ))}
                {queued.length > 0 && (
                    <li className="text-muted-foreground">
                        Waiting:{' '}
                        {queued
                            .slice(0, 8)
                            .map((entry) => entry.label)
                            .join(', ')}
                        {queued.length > 8 && ` and ${queued.length - 8} more`}
                        {running.length === 0 &&
                            '. Nothing is running: check that the queue worker (Horizon) is started.'}
                    </li>
                )}
                {failed.map((entry) => (
                    <li
                        key={entry.key}
                        className="text-red-600 dark:text-red-400"
                    >
                        <span className="font-medium">{entry.label}</span>:{' '}
                        {entry.step ?? 'failed'}
                    </li>
                ))}
            </ul>
        </Panel>
    );
}

/**
 * Every search that was run, newest first, so nothing is researched twice without need.
 */
function ResearchLog({ searches }: { searches: Paginated<SearchEntry> }) {
    return (
        <Panel
            title="Research log"
            description="Every search with what it returned, newest first."
            flush
        >
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>When</TableHead>
                        <TableHead>For</TableHead>
                        <TableHead>Searched</TableHead>
                        <TableHead>Result</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {searches.data.map((entry) => (
                        <TableRow key={entry.id}>
                            <TableCell className="align-top text-muted-foreground">
                                {dateTime(entry.created_at)}
                            </TableCell>
                            <TableCell className="align-top">
                                <RecordLink to="track" id={entry.track_id}>
                                    {entry.track ?? '–'}
                                </RecordLink>
                                {entry.airplay_id !== null && (
                                    <p className="text-xs text-muted-foreground">
                                        Play on{' '}
                                        {entry.station ?? 'an unknown station'}
                                    </p>
                                )}
                            </TableCell>
                            <TableCell className="max-w-sm align-top whitespace-normal">
                                {entry.query}
                                <p className="text-xs text-muted-foreground">
                                    {entry.region ? country(entry.region) : ''}
                                </p>
                            </TableCell>
                            <TableCell className="max-w-md align-top whitespace-normal">
                                {entry.succeeded ? (
                                    <details>
                                        <summary className="cursor-pointer">
                                            {plural(
                                                entry.results.length,
                                                'result',
                                            )}
                                            ,{' '}
                                            {plural(
                                                entry.new_findings,
                                                'new finding',
                                            )}
                                        </summary>
                                        <ul className="mt-1 grid gap-1 text-xs">
                                            {entry.results.map((result) => (
                                                <li key={result.url}>
                                                    <a
                                                        href={result.url}
                                                        target="_blank"
                                                        rel="noreferrer noopener"
                                                        className="hover:underline"
                                                    >
                                                        {result.title}
                                                    </a>{' '}
                                                    <span className="text-muted-foreground">
                                                        {result.domain}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    </details>
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
    );
}

export default function Airplays({
    airplays,
    findings,
    searches,
    filters,
    trackResearch,
    airplayResearch,
    trackCount,
    staleCount,
    staleDays,
    searchAvailable,
}: {
    airplays: Paginated<Airplay>;
    findings: Paginated<Finding>;
    searches: Paginated<SearchEntry>;
    filters: {
        search: string;
        track: number | null;
        origin: Origin | null;
        sort: string | null;
        direction: SortDirection | null;
    };
    trackResearch: TrackResearch[];
    airplayResearch: TrackResearch[];
    trackCount: number;
    staleCount: number;
    staleDays: number;
    searchAvailable: boolean;
}) {
    const active =
        trackResearch.some((track) => track.state !== 'failed') ||
        airplayResearch.some((airplay) => airplay.state !== 'failed');

    // While a research is queued or running, fetch its progress and what it found.
    useEffect(() => {
        if (!active) {
            return;
        }

        const timer = window.setInterval(
            () =>
                router.reload({
                    only: [
                        'airplays',
                        'findings',
                        'searches',
                        'trackResearch',
                        'airplayResearch',
                        'staleCount',
                    ],
                }),
            5000,
        );

        return () => window.clearInterval(timer);
    }, [active]);

    const { apply } = useFilters(index.url(), filters);
    const sorter = serverSorter(filters, apply);
    const filtered =
        filters.search !== '' ||
        filters.track !== null ||
        filters.origin !== null;

    return (
        <Page
            title="Airplay"
            description="Where your tracks were played, and how you know: from a neighbouring rights statement, from the web research, or entered by hand. Keep links to playlists and show pages with each play."
            actions={
                <>
                    {searchAvailable && (
                        <ResearchDialog
                            trackCount={trackCount}
                            staleCount={staleCount}
                            staleDays={staleDays}
                        />
                    )}
                    <FormDialog
                        title="New airplay"
                        form={AirplayController.store.form()}
                        submitLabel="Create airplay"
                        trigger={
                            <Button>
                                <Plus />
                                New airplay
                            </Button>
                        }
                    >
                        {(errors) => <AirplayFields errors={errors} />}
                    </FormDialog>
                </>
            }
        >
            <FilterBar
                url={index.url()}
                filters={filters}
                search="Search track, artist, station or ISRC…"
                selects={[
                    {
                        name: 'track',
                        label: 'Track',
                        placeholder: 'All tracks',
                        records: 'tracks',
                        className: 'w-full sm:w-72',
                    },
                    {
                        name: 'origin',
                        label: 'Known from',
                        placeholder: 'Any origin',
                        options: Object.entries(ORIGINS).map(([id, name]) => ({
                            id,
                            name,
                        })),
                        className: 'w-full sm:w-64',
                    },
                ]}
            />
            <ResearchProgress
                tracks={trackResearch}
                airplays={airplayResearch}
            />
            {findings.total > 0 && <Findings findings={findings} />}
            {airplays.total === 0 ? (
                <EmptyState
                    title={filtered ? 'No airplay matches' : 'No airplay yet'}
                    description={
                        filtered
                            ? undefined
                            : 'Import a Rightz Audio statement under Data imports, start a research, or add a play by hand.'
                    }
                />
            ) : (
                <Panel flush>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <SortableHead column="track" sorter={sorter}>
                                    Track
                                </SortableHead>
                                <SortableHead column="artist" sorter={sorter}>
                                    Artist
                                </SortableHead>
                                <SortableHead column="station" sorter={sorter}>
                                    Station
                                </SortableHead>
                                <SortableHead column="origin" sorter={sorter}>
                                    Known from
                                </SortableHead>
                                <SortableHead column="period" sorter={sorter}>
                                    Played
                                </SortableHead>
                                <SortableHead
                                    column="amount"
                                    sorter={sorter}
                                    className="text-right"
                                >
                                    Earned
                                </SortableHead>
                                <SortableHead column="links" sorter={sorter}>
                                    Links
                                </SortableHead>
                                <TableHead className="w-28" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {airplays.data.map((airplay) => (
                                <TableRow key={airplay.id}>
                                    <TableCell className="font-medium">
                                        <RecordLink
                                            to="track"
                                            id={airplay.track_id}
                                        >
                                            {airplay.track_name}
                                        </RecordLink>
                                        {airplay.notes && (
                                            <p className="max-w-md truncate text-xs font-normal text-muted-foreground">
                                                {airplay.notes}
                                            </p>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        <RecordLink
                                            to="artist"
                                            id={airplay.artist_id}
                                        >
                                            {airplay.artist_name ?? '–'}
                                        </RecordLink>
                                    </TableCell>
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
                                                airplay.origin === 'statement'
                                                    ? airplay.data_import_id
                                                    : null
                                            }
                                            className="no-underline"
                                        >
                                            <Badge
                                                variant={
                                                    airplay.origin ===
                                                    'statement'
                                                        ? 'success'
                                                        : airplay.origin ===
                                                            'search'
                                                          ? 'info'
                                                          : 'outline'
                                                }
                                            >
                                                {ORIGINS[airplay.origin]}
                                            </Badge>
                                        </RecordLink>
                                        <p className="mt-1 flex items-center gap-1 text-xs text-muted-foreground">
                                            {airplay.research_state ===
                                                'running' && <Spinner />}
                                            {airplay.research_state ===
                                            'running'
                                                ? (airplay.research_step ??
                                                  'Researching')
                                                : airplay.research_state ===
                                                    'queued'
                                                  ? 'Waiting for research'
                                                  : airplay.research_state ===
                                                      'failed'
                                                    ? `Research failed: ${airplay.research_step ?? ''}`
                                                    : airplay.researched_at
                                                      ? `Researched ${date(airplay.researched_at)}`
                                                      : 'Not researched yet'}
                                        </p>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {period(airplay)}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {airplay.amount === null ? (
                                            '–'
                                        ) : (
                                            <Amount value={airplay.amount} />
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <LinksDialog
                                            airplay={airplay}
                                            searchAvailable={searchAvailable}
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex justify-end">
                                            {searchAvailable && (
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    title="Research this play: the show, the playlist, what was written about it"
                                                    aria-label={`Research ${airplay.track_name} on ${stationName(airplay)}`}
                                                    disabled={
                                                        airplay.research_state ===
                                                            'queued' ||
                                                        airplay.research_state ===
                                                            'running'
                                                    }
                                                    onClick={() =>
                                                        router.post(
                                                            AirplayResearchController.airplay.url(
                                                                airplay.id,
                                                            ),
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <Radar className="text-muted-foreground" />
                                                </Button>
                                            )}
                                            <FormDialog
                                                title="Edit airplay"
                                                form={AirplayController.update.form(
                                                    airplay.id,
                                                )}
                                                trigger={
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={`Edit ${airplay.track_name} on ${stationName(airplay)}`}
                                                    >
                                                        <Pencil className="text-muted-foreground" />
                                                    </Button>
                                                }
                                            >
                                                {(errors) => (
                                                    <AirplayFields
                                                        airplay={airplay}
                                                        errors={errors}
                                                    />
                                                )}
                                            </FormDialog>
                                            <DeleteButton
                                                url={AirplayController.destroy.url(
                                                    airplay.id,
                                                )}
                                                title={`Delete ${airplay.track_name} on ${stationName(airplay)}?`}
                                                description="The play and its links and notes are removed. Income from the statement stays."
                                            />
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <Pagination page={airplays} noun="plays" />
                </Panel>
            )}
            {searches.total > 0 && <ResearchLog searches={searches} />}
        </Page>
    );
}

Airplays.layout = { breadcrumbs: [{ title: 'Airplay', href: index() }] };
