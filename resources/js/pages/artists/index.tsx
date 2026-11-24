import { FilterBar } from '@/components/filter-bar';
import { RecordLink, recordLinkClass } from '@/components/record-link';
import { Link } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import ArtistController from '@/actions/App/Http/Controllers/ArtistController';
import { DeleteButton } from '@/components/delete-button';
import { FormDialog } from '@/components/form-dialog';
import { EmptyState, Page, Panel } from '@/components/page';
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
import { serverSorter } from '@/hooks/use-table-sort';
import type { SortDirection } from '@/hooks/use-table-sort';
import { index } from '@/routes/artists';
import { index as releases } from '@/routes/releases';
import type { Paginated } from '@/types';
import { count } from '@/lib/format';
import { ArtistFields } from '@/components/artist-fields';

type Artist = {
    id: number;
    name: string;
    alias_of_id: number | null;
    alias_of: string | null;
    spotify_id: string | null;
    discogs_search_name: string | null;
    notes: string | null;
    releases_count: number;
    tracks_count: number;
    aliases_count: number;
    aliases: string[];
};

export default function Artists({
    artists,
    filters,
}: {
    artists: Paginated<Artist>;
    filters: {
        search: string;
        aliases: boolean;
        sort: string | null;
        direction: SortDirection | null;
    };
}) {
    const { apply } = useFilters(index.url(), filters);
    const sorter = serverSorter(filters, apply);

    return (
        <Page
            title="Artists"
            description="The performers on your releases and tracks. Artists are created automatically when an import mentions a new name. Other spellings of an artist are its aliases and are listed with it."
            actions={
                <FormDialog
                    title="New artist"
                    form={ArtistController.store.form()}
                    submitLabel="Create artist"
                    trigger={
                        <Button>
                            <Plus />
                            New artist
                        </Button>
                    }
                >
                    {(errors) => <ArtistFields errors={errors} />}
                </FormDialog>
            }
        >
            <FilterBar
                url={index.url()}
                filters={filters}
                search="Search artists…"
                checks={[{ name: 'aliases', label: 'Aliases' }]}
            />

            {artists.total === 0 ? (
                <EmptyState
                    title={
                        filters.search
                            ? 'No artist matches your search'
                            : 'No artists yet'
                    }
                    description={
                        filters.search
                            ? undefined
                            : 'Import a statement and the artists in it are added for you, or create one by hand.'
                    }
                />
            ) : (
                <Panel flush>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <SortableHead column="name" sorter={sorter}>
                                    Name
                                </SortableHead>
                                <SortableHead
                                    column="releases_count"
                                    sorter={sorter}
                                    first="desc"
                                    className="text-right"
                                >
                                    Releases
                                </SortableHead>
                                <SortableHead
                                    column="tracks_count"
                                    sorter={sorter}
                                    first="desc"
                                    className="text-right"
                                >
                                    Tracks
                                </SortableHead>
                                <SortableHead column="notes" sorter={sorter}>
                                    Notes
                                </SortableHead>
                                <TableHead className="w-20" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {artists.data.map((artist) => (
                                <TableRow key={artist.id}>
                                    <TableCell>
                                        <span className="font-medium">
                                            <RecordLink
                                                to="artist"
                                                id={artist.id}
                                            >
                                                {artist.name}
                                            </RecordLink>
                                        </span>
                                        {artist.alias_of && (
                                            <Badge
                                                variant="secondary"
                                                className="ml-2"
                                            >
                                                alias of {artist.alias_of}
                                            </Badge>
                                        )}
                                        {artist.aliases.length > 0 && (
                                            <span className="block text-xs text-muted-foreground">
                                                also{' '}
                                                {artist.aliases.join(' · ')}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {artist.releases_count > 0 ? (
                                            <Link
                                                href={releases({
                                                    query: {
                                                        artist: artist.id,
                                                    },
                                                })}
                                                className={recordLinkClass}
                                            >
                                                {count(artist.releases_count)}
                                            </Link>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                0
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {count(artist.tracks_count)}
                                    </TableCell>
                                    <TableCell className="max-w-xs truncate text-muted-foreground">
                                        {artist.notes ?? '–'}
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex justify-end">
                                            <FormDialog
                                                title={`Edit ${artist.name}`}
                                                form={ArtistController.update.form(
                                                    artist.id,
                                                )}
                                                trigger={
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={`Edit ${artist.name}`}
                                                    >
                                                        <Pencil className="text-muted-foreground" />
                                                    </Button>
                                                }
                                            >
                                                {(errors) => (
                                                    <ArtistFields
                                                        artist={artist}
                                                        errors={errors}
                                                    />
                                                )}
                                            </FormDialog>
                                            <DeleteButton
                                                url={ArtistController.destroy.url(
                                                    artist.id,
                                                )}
                                                title={`Delete ${artist.name}?`}
                                                description="Releases and tracks of this artist stay in the catalog but lose their artist."
                                            />
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <Pagination page={artists} noun="artists" />
                </Panel>
            )}
        </Page>
    );
}

Artists.layout = { breadcrumbs: [{ title: 'Artists', href: index() }] };
