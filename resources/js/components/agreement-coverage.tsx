import { X } from 'lucide-react';
import { useState } from 'react';
import AgreementController from '@/actions/App/Http/Controllers/AgreementController';
import { CheckField } from '@/components/field';
import { RecordLink } from '@/components/record-link';
import { RecordSelect } from '@/components/record-select';
import { Button } from '@/components/ui/button';

export type CoveredRelease = {
    id: number;
    name: string;
    catalog_number: string | null;
    tracks: { id: number; name: string; chosen: boolean }[];
};

/**
 * What an agreement covers, read-only: each release with the tracks
 * chosen from it, or as a whole when none is.
 */
export function CoverageList({ coverage }: { coverage: CoveredRelease[] }) {
    return (
        <ul className="divide-y rounded-lg border text-sm">
            {coverage.map((release) => {
                const chosen = release.tracks.filter((track) => track.chosen);

                return (
                    <li key={release.id} className="px-3 py-2">
                        <RecordLink
                            to="release"
                            id={release.id}
                            className="font-medium"
                        >
                            {release.name}
                        </RecordLink>
                        {release.catalog_number && (
                            <span className="text-muted-foreground">
                                {' '}
                                [{release.catalog_number}]
                            </span>
                        )}
                        <span className="block text-muted-foreground">
                            {chosen.length === 0
                                ? 'The whole release'
                                : chosen.map((track, index) => (
                                      <span key={track.id}>
                                          {index > 0 && ', '}
                                          <RecordLink to="track" id={track.id}>
                                              {track.name}
                                          </RecordLink>
                                      </span>
                                  ))}
                        </span>
                    </li>
                );
            })}
        </ul>
    );
}

/**
 * Chooses what an agreement covers: any number of releases, and of
 * each either everything or only the tracks that are ticked. The tracks
 * on offer are those of the chosen releases. Sends `release_ids[]` and
 * `track_ids[]` with the form around it.
 */
export function CoverageFields({
    initial,
    params = {},
}: {
    initial: CoveredRelease[];
    /** The contract and payee chosen beside it: the releases on offer are the ones they pay for, with those tracks ticked. */
    params?: { contract_id?: number | ''; payee_id?: number | '' };
}) {
    const [releases, setReleases] = useState(initial);

    const add = async (id: number | '') => {
        if (id === '' || releases.some((release) => release.id === id)) {
            return;
        }

        const query = Object.fromEntries(
            Object.entries(params).filter(([, value]) => value),
        );
        const response = await fetch(
            AgreementController.release.url(id, { query }),
            {
                headers: { Accept: 'application/json' },
            },
        ).catch(() => null);

        if (response?.ok) {
            const release: CoveredRelease = await response.json();

            setReleases((before) => [...before, release]);
        }
    };

    return (
        <div className="grid gap-3">
            {releases.map((release) => (
                <div key={release.id} className="rounded-lg border p-3">
                    <input
                        type="hidden"
                        name="release_ids[]"
                        value={release.id}
                    />
                    <div className="flex items-start justify-between gap-2">
                        <p className="text-sm font-medium">
                            {release.name}
                            {release.catalog_number && (
                                <span className="font-normal text-muted-foreground">
                                    {' '}
                                    [{release.catalog_number}]
                                </span>
                            )}
                        </p>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label={`Remove ${release.name}`}
                            onClick={() =>
                                setReleases(
                                    releases.filter(
                                        ({ id }) => id !== release.id,
                                    ),
                                )
                            }
                        >
                            <X className="text-muted-foreground" />
                        </Button>
                    </div>
                    {release.tracks.length > 0 && (
                        <>
                            <p className="mb-2 text-xs text-muted-foreground">
                                Tick the tracks the agreement is about. With
                                none ticked it is about the whole release.
                            </p>
                            <div className="grid gap-1.5 sm:grid-cols-2">
                                {release.tracks.map((track) => (
                                    <CheckField
                                        key={track.id}
                                        name="track_ids[]"
                                        value={track.id}
                                        label={track.name}
                                        defaultChecked={track.chosen}
                                    />
                                ))}
                            </div>
                        </>
                    )}
                </div>
            ))}
            <RecordSelect
                id="add-release"
                records="releases"
                value=""
                onChange={add}
                params={params}
                placeholder={
                    releases.length === 0
                        ? 'Add a release…'
                        : 'Add another release…'
                }
            />
        </div>
    );
}
