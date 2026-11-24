import { Link, router, usePage } from '@inertiajs/react';
import CatalogController from '@/actions/App/Http/Controllers/CatalogController';
import { FilterBar } from '@/components/filter-bar';
import { Pagination } from '@/components/pagination';
import { RecordLink } from '@/components/record-link';
import { RecordSelect } from '@/components/record-select';
import { EmptyState, Page, Panel } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/catalog';
import { create as createContract } from '@/routes/contracts';
import { create as createImport } from '@/routes/data-imports';
import type { Paginated } from '@/types';
import { plural } from '@/lib/format';

type Track = {
    id: number;
    name: string;
    isrc: string | null;
    contract_id: number | null;
    contract: string | null;
    artist: string | null;
    artist_id: number | null;
};
type Release = {
    id: number;
    name: string;
    catalog_number: string | null;
    contract_id: number | null;
    contract: string | null;
    artist: string | null;
    artist_id: number | null;
    tracks: Track[];
};

const contractOption = (id: number | null, name: string | null) =>
    id !== null && name !== null ? { id, name, hint: null, image: null } : null;

function assign(type: 'release' | 'track', id: number, value: number | '') {
    router.patch(
        CatalogController.assign.url(),
        { type, id, contract_id: value === '' ? null : value },
        { preserveScroll: true, preserveState: true },
    );
}

export default function CatalogManager({
    releases,
    filters,
    uncovered,
    hasReleases,
}: {
    releases: Paginated<Release>;
    filters: { search: string; without_contract: boolean };
    uncovered: number;
    hasReleases: boolean;
}) {
    const { url } = usePage();
    return (
        <Page
            title="Catalog manager"
            description="Assign contracts across the whole catalog in one place. A track uses its own contract if it has one, otherwise the contract of its release. Changes save immediately."
            actions={
                <Button variant="outline" asChild>
                    <Link href={createContract({ query: { return_to: url } })}>
                        New contract
                    </Link>
                </Button>
            }
        >
            {!hasReleases ? (
                <EmptyState
                    title="Your catalog is empty"
                    description="Import a distributor statement to build it automatically."
                    action={
                        <Button asChild>
                            <Link href={createImport()}>
                                Import a statement
                            </Link>
                        </Button>
                    }
                />
            ) : (
                <>
                    <div className="flex flex-wrap items-center gap-3">
                        <div className="min-w-0 flex-1">
                            <FilterBar
                                url={index.url()}
                                filters={filters}
                                search="Search releases and tracks…"
                                checks={[
                                    {
                                        name: 'without_contract',
                                        label: 'Without contract',
                                    },
                                ]}
                            />
                        </div>
                        <span className="text-sm text-muted-foreground">
                            {uncovered === 0
                                ? 'Every track is covered by a contract.'
                                : `${plural(uncovered, 'track')} without a contract`}
                        </span>
                    </div>

                    <div className="grid gap-4">
                        {releases.data.map((release) => (
                            <Panel
                                key={release.id}
                                flush
                                title={
                                    <span className="flex flex-wrap items-center gap-2">
                                        {release.catalog_number && (
                                            <span className="font-mono text-xs text-muted-foreground">
                                                {release.catalog_number}
                                            </span>
                                        )}
                                        <RecordLink
                                            to="release"
                                            id={release.id}
                                        >
                                            {release.name}
                                        </RecordLink>
                                        <span className="font-normal text-muted-foreground">
                                            <RecordLink
                                                to="artist"
                                                id={release.artist_id}
                                            >
                                                {release.artist}
                                            </RecordLink>
                                        </span>
                                    </span>
                                }
                                actions={
                                    <RecordSelect
                                        aria-label={`Contract for ${release.name}`}
                                        records="contracts"
                                        value={release.contract_id ?? ''}
                                        initial={contractOption(
                                            release.contract_id,
                                            release.contract,
                                        )}
                                        onChange={(id) =>
                                            assign('release', release.id, id)
                                        }
                                        placeholder="No release contract"
                                        className="w-72"
                                    />
                                }
                            >
                                {release.tracks.length === 0 ? (
                                    <p className="px-4 py-3 text-sm text-muted-foreground">
                                        No tracks.
                                    </p>
                                ) : (
                                    <ul className="divide-y">
                                        {release.tracks.map((track) => (
                                            <li
                                                key={track.id}
                                                className="flex flex-wrap items-center gap-3 px-4 py-2 text-sm"
                                            >
                                                <span className="min-w-0 flex-1">
                                                    <span className="font-medium">
                                                        <RecordLink
                                                            to="track"
                                                            id={track.id}
                                                        >
                                                            {track.name}
                                                        </RecordLink>
                                                    </span>
                                                    {track.artist &&
                                                        track.artist !==
                                                            release.artist && (
                                                            <span className="text-muted-foreground">
                                                                {' '}
                                                                ·{' '}
                                                                <RecordLink
                                                                    to="artist"
                                                                    id={
                                                                        track.artist_id
                                                                    }
                                                                >
                                                                    {
                                                                        track.artist
                                                                    }
                                                                </RecordLink>
                                                            </span>
                                                        )}
                                                    {track.isrc && (
                                                        <span className="ml-2 font-mono text-xs text-muted-foreground">
                                                            {track.isrc}
                                                        </span>
                                                    )}
                                                </span>
                                                {track.contract_id === null &&
                                                    release.contract_id ===
                                                        null && (
                                                        <Badge variant="destructive">
                                                            No contract
                                                        </Badge>
                                                    )}
                                                <RecordSelect
                                                    aria-label={`Contract for ${track.name}`}
                                                    records="contracts"
                                                    value={
                                                        track.contract_id ?? ''
                                                    }
                                                    initial={contractOption(
                                                        track.contract_id,
                                                        track.contract,
                                                    )}
                                                    onChange={(id) =>
                                                        assign(
                                                            'track',
                                                            track.id,
                                                            id,
                                                        )
                                                    }
                                                    placeholder={
                                                        release.contract_id
                                                            ? `Release: ${release.contract}`
                                                            : 'No contract'
                                                    }
                                                    className="w-72"
                                                />
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Panel>
                        ))}
                        {releases.total === 0 && (
                            <p className="text-sm text-muted-foreground">
                                Nothing matches the filters.
                            </p>
                        )}
                    </div>
                    <div className="rounded-xl border">
                        <Pagination page={releases} noun="releases" />
                    </div>
                </>
            )}
        </Page>
    );
}

CatalogManager.layout = {
    breadcrumbs: [{ title: 'Catalog manager', href: index() }],
};
