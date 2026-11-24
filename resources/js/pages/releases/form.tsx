import { Link, setLayoutProps, useForm, usePage } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useState } from 'react';
import ReleaseController from '@/actions/App/Http/Controllers/ReleaseController';
import { ArtistCreditRows } from '@/components/artist-credit-rows';
import type { ArtistCredit } from '@/components/artist-credit-rows';
import { CheckField, Field, SelectInput } from '@/components/field';
import InputError from '@/components/input-error';
import { Notice, Page, Panel } from '@/components/page';
import { RecordSelect } from '@/components/record-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { index, show } from '@/routes/releases';
import { AiField } from '@/components/ai-field';
import { TRACK_FIELD } from '@/components/track-fields';

type TrackRow = {
    id: number | null;
    name: string;
    artist_id: number | '';
    contract_id: number | '';
    isrc: string;
    iswc: string;
    track_number: number | '';
    duration: number | '';
};

type Release = {
    id: number;
    name: string;
    artist_id: number | null;
    catalog_number: string | null;
    upc: string | null;
    released_on: string | null;
    spotify_id: string | null;
    discogs_id: string | null;
    accounts_mechanicals: boolean;
    mechanicals_rate: string | null;
    ppd_rate: string | null;
    reserves_rate: string | null;
    notes: string | null;
    status: string;
    release_type: string | null;
    version: string | null;
    genre: string | null;
    subgenre: string | null;
    language: string | null;
    explicit: string | null;
    ai_artwork: string | null;
    p_line: string | null;
    c_line: string | null;
    original_released_on: string | null;
    preorder_on: string | null;
    press_release: string | null;
    key_facts: string | null;
    todo: string | null;
    files_url: string | null;
    blocked_services: string[];
    blocked_territories: string[];
    artists: ArtistCredit[];
    tracks: {
        id: number;
        name: string;
        artist_id: number | null;
        contract_id: number | null;
        isrc: string | null;
        iswc: string | null;
        track_number: number | null;
        duration: number | null;
    }[];
};

const numberOrBlank = (value: string): number | '' =>
    value === '' ? '' : Number(value);

export default function ReleaseForm({
    release,
    returnTo,
    statusOptions,
    typeOptions,
    genreOptions,
}: {
    release: Release | null;
    /** The page the form was opened from, where saving returns to. */
    returnTo: string | null;
    statusOptions: { value: string; label: string }[];
    typeOptions: { value: string; label: string }[];
    genreOptions: string[];
}) {
    const { label } = usePage().props;
    const form = useForm({
        return_to: returnTo ?? '',
        name: release?.name ?? '',
        artist_id: (release?.artist_id ?? '') as number | '',
        catalog_number: release?.catalog_number ?? '',
        upc: release?.upc ?? '',
        released_on: release?.released_on ?? '',
        spotify_id: release?.spotify_id ?? '',
        discogs_id: release?.discogs_id ?? '',
        accounts_mechanicals: release?.accounts_mechanicals ?? false,
        mechanicals_rate: release?.mechanicals_rate ?? '',
        ppd_rate: release?.ppd_rate ?? '',
        reserves_rate: release?.reserves_rate ?? '',
        notes: release?.notes ?? '',
        status: release?.status ?? 'todo',
        release_type: release?.release_type ?? '',
        version: release?.version ?? '',
        genre: release?.genre ?? '',
        subgenre: release?.subgenre ?? '',
        language: release?.language ?? '',
        explicit: release?.explicit ?? '',
        ai_artwork: release?.ai_artwork ?? '',
        p_line: release?.p_line ?? '',
        c_line: release?.c_line ?? '',
        original_released_on: release?.original_released_on ?? '',
        preorder_on: release?.preorder_on ?? '',
        press_release: release?.press_release ?? '',
        key_facts: release?.key_facts ?? '',
        todo: release?.todo ?? '',
        files_url: release?.files_url ?? '',
        blocked_services: (release?.blocked_services ?? []).join(', '),
        blocked_territories: (release?.blocked_territories ?? []).join(', '),
        artists: (release?.artists ?? []) as ArtistCredit[],
        tracks: (release?.tracks ?? []).map((track) => ({
            id: track.id,
            name: track.name,
            artist_id: track.artist_id ?? '',
            contract_id: track.contract_id ?? '',
            isrc: track.isrc ?? '',
            iswc: track.iswc ?? '',
            track_number: track.track_number ?? '',
            duration: track.duration ?? '',
        })) as TrackRow[],
    });

    const [allContract, setAllContract] = useState<number | ''>('');
    const errors = form.errors as Record<string, string>;
    const removedExisting =
        (release?.tracks.length ?? 0) >
        form.data.tracks.filter((track) => track.id !== null).length;

    const setTrack = (position: number, changes: Partial<TrackRow>) =>
        form.setData(
            'tracks',
            form.data.tracks.map((track, current) =>
                current === position ? { ...track, ...changes } : track,
            ),
        );

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (release) {
            form.put(ReleaseController.update.url(release.id));
        } else {
            form.post(ReleaseController.store.url());
        }
    };

    setLayoutProps({
        breadcrumbs: [
            { title: 'Releases', href: index() },
            ...(release
                ? [
                      { title: release.name, href: show(release.id) },
                      { title: 'Edit', href: '#' },
                  ]
                : [{ title: 'New release', href: '#' }]),
        ],
    });

    return (
        <Page title={release ? `Edit ${release.name}` : 'New release'}>
            <form onSubmit={submit} className="grid max-w-5xl gap-6">
                <Panel title="Release">
                    <div className="grid gap-4 md:grid-cols-2">
                        <Field
                            label="Name"
                            htmlFor="name"
                            error={errors.name}
                            required
                            className="md:col-span-2"
                        >
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                required
                            />
                        </Field>
                        <Field
                            label="Artist"
                            htmlFor="artist_id"
                            error={errors.artist_id}
                        >
                            <RecordSelect
                                id="artist_id"
                                records="artists"
                                value={form.data.artist_id}
                                onChange={(id) => form.setData('artist_id', id)}
                                placeholder="No artist / various"
                            />
                        </Field>
                        <Field
                            label="Catalog number"
                            htmlFor="catalog_number"
                            error={errors.catalog_number}
                        >
                            <Input
                                id="catalog_number"
                                value={form.data.catalog_number}
                                onChange={(event) =>
                                    form.setData(
                                        'catalog_number',
                                        event.target.value,
                                    )
                                }
                                placeholder="CAT015"
                            />
                        </Field>
                        <Field
                            label="UPC / EAN"
                            htmlFor="upc"
                            error={errors.upc}
                            hint="Imports find the release by this code."
                        >
                            <Input
                                id="upc"
                                inputMode="numeric"
                                value={form.data.upc}
                                onChange={(event) =>
                                    form.setData('upc', event.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Release date"
                            htmlFor="released_on"
                            error={errors.released_on}
                        >
                            <Input
                                id="released_on"
                                type="date"
                                value={form.data.released_on}
                                onChange={(event) =>
                                    form.setData(
                                        'released_on',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Spotify ID"
                                htmlFor="spotify_id"
                                error={errors.spotify_id}
                            >
                                <Input
                                    id="spotify_id"
                                    value={form.data.spotify_id}
                                    onChange={(event) =>
                                        form.setData(
                                            'spotify_id',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="Discogs ID"
                                htmlFor="discogs_id"
                                error={errors.discogs_id}
                            >
                                <Input
                                    id="discogs_id"
                                    value={form.data.discogs_id}
                                    onChange={(event) =>
                                        form.setData(
                                            'discogs_id',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                        </div>
                        <Field
                            label="Notes"
                            htmlFor="notes"
                            error={errors.notes}
                            className="md:col-span-2"
                        >
                            <Textarea
                                id="notes"
                                value={form.data.notes}
                                onChange={(event) =>
                                    form.setData('notes', event.target.value)
                                }
                            />
                        </Field>
                    </div>
                </Panel>

                <Panel
                    title="Release details"
                    description="What platforms and distributors ask for when the release is delivered."
                >
                    <div className="grid gap-4 md:grid-cols-3">
                        <Field
                            label="Status"
                            htmlFor="status"
                            error={errors.status}
                        >
                            <SelectInput
                                id="status"
                                value={form.data.status}
                                onChange={(event) =>
                                    form.setData('status', event.target.value)
                                }
                            >
                                {statusOptions.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </SelectInput>
                        </Field>
                        <Field
                            label="Format"
                            htmlFor="release_type"
                            error={errors.release_type}
                        >
                            <SelectInput
                                id="release_type"
                                value={form.data.release_type}
                                onChange={(event) =>
                                    form.setData(
                                        'release_type',
                                        event.target.value,
                                    )
                                }
                                placeholder="Not set"
                            >
                                {typeOptions.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </SelectInput>
                        </Field>
                        <Field
                            label="Version"
                            htmlFor="version"
                            error={errors.version}
                            hint="e.g. Deluxe Edition, The Remixes"
                        >
                            <Input
                                id="version"
                                value={form.data.version}
                                onChange={(event) =>
                                    form.setData('version', event.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Genre"
                            htmlFor="genre"
                            error={errors.genre}
                        >
                            <datalist id="genres">
                                {genreOptions.map((genre) => (
                                    <option key={genre} value={genre} />
                                ))}
                            </datalist>
                            <Input
                                id="genre"
                                list="genres"
                                value={form.data.genre}
                                onChange={(event) =>
                                    form.setData('genre', event.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Subgenre"
                            htmlFor="subgenre"
                            error={errors.subgenre}
                        >
                            <Input
                                id="subgenre"
                                list="genres"
                                value={form.data.subgenre}
                                onChange={(event) =>
                                    form.setData('subgenre', event.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Language"
                            htmlFor="language"
                            error={errors.language}
                            hint="e.g. en, de, zxx for instrumental"
                        >
                            <Input
                                id="language"
                                value={form.data.language}
                                onChange={(event) =>
                                    form.setData('language', event.target.value)
                                }
                                maxLength={8}
                            />
                        </Field>
                        <Field
                            label="℗ line"
                            htmlFor="p_line"
                            error={errors.p_line}
                            hint="Owner of the recordings, e.g. 2026 My Label"
                        >
                            <Input
                                id="p_line"
                                value={form.data.p_line}
                                onChange={(event) =>
                                    form.setData('p_line', event.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="© line"
                            htmlFor="c_line"
                            error={errors.c_line}
                            hint="Owner of artwork and packaging"
                        >
                            <Input
                                id="c_line"
                                value={form.data.c_line}
                                onChange={(event) =>
                                    form.setData('c_line', event.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Explicit"
                            htmlFor="explicit"
                            error={errors.explicit}
                        >
                            <SelectInput
                                id="explicit"
                                value={form.data.explicit}
                                onChange={(event) =>
                                    form.setData('explicit', event.target.value)
                                }
                                placeholder="Not explicit"
                            >
                                <option value="explicit">Explicit</option>
                                <option value="clean">Clean version</option>
                            </SelectInput>
                        </Field>
                        <AiField
                            label="AI in the artwork"
                            name="ai_artwork"
                            error={errors.ai_artwork}
                            value={form.data.ai_artwork}
                            onChange={(event) =>
                                form.setData('ai_artwork', event.target.value)
                            }
                        />
                        <Field
                            label="Original release date"
                            htmlFor="original_released_on"
                            error={errors.original_released_on}
                            hint="For reissues."
                        >
                            <Input
                                id="original_released_on"
                                type="date"
                                value={form.data.original_released_on}
                                onChange={(event) =>
                                    form.setData(
                                        'original_released_on',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Pre-order from"
                            htmlFor="preorder_on"
                            error={errors.preorder_on}
                        >
                            <Input
                                id="preorder_on"
                                type="date"
                                value={form.data.preorder_on}
                                onChange={(event) =>
                                    form.setData(
                                        'preorder_on',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Link to external files"
                            htmlFor="files_url"
                            error={errors.files_url}
                            hint="A shared folder, if files live elsewhere too."
                        >
                            <Input
                                id="files_url"
                                type="url"
                                value={form.data.files_url}
                                onChange={(event) =>
                                    form.setData(
                                        'files_url',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Do not deliver to"
                            htmlFor="blocked_services"
                            error={errors.blocked_services}
                            hint="Platforms, separated by commas."
                        >
                            <Input
                                id="blocked_services"
                                value={form.data.blocked_services}
                                onChange={(event) =>
                                    form.setData(
                                        'blocked_services',
                                        event.target.value,
                                    )
                                }
                                placeholder="Spotify"
                            />
                        </Field>
                        <Field
                            label="Blocked territories"
                            htmlFor="blocked_territories"
                            error={
                                errors.blocked_territories ??
                                Object.entries(errors).find(([key]) =>
                                    key.startsWith('blocked_territories.'),
                                )?.[1]
                            }
                            hint="Two-letter country codes, separated by commas."
                        >
                            <Input
                                id="blocked_territories"
                                value={form.data.blocked_territories}
                                onChange={(event) =>
                                    form.setData(
                                        'blocked_territories',
                                        event.target.value,
                                    )
                                }
                                placeholder="RU, IL"
                                className="uppercase"
                            />
                        </Field>
                    </div>
                    <div className="mt-4 grid gap-1.5">
                        <span className="text-sm font-medium">
                            Further artist credits
                        </span>
                        <p className="text-xs text-muted-foreground">
                            Other main artists, featured artists or remixers
                            credited on the whole release. The artist chosen
                            above stays the main one.
                        </p>
                        <ArtistCreditRows
                            value={form.data.artists}
                            onChange={(rows) => form.setData('artists', rows)}
                        />
                        <InputError
                            message={
                                Object.entries(errors).find(([key]) =>
                                    key.startsWith('artists'),
                                )?.[1]
                            }
                        />
                    </div>
                </Panel>

                <Panel title="Promotion and planning">
                    <div className="grid gap-4">
                        <Field
                            label="To do"
                            htmlFor="todo"
                            error={errors.todo}
                            hint="Open tasks for this release."
                        >
                            <Textarea
                                id="todo"
                                value={form.data.todo}
                                onChange={(event) =>
                                    form.setData('todo', event.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Key facts"
                            htmlFor="key_facts"
                            error={errors.key_facts}
                            hint="Selling points for platforms, press and playlists."
                        >
                            <Textarea
                                id="key_facts"
                                value={form.data.key_facts}
                                onChange={(event) =>
                                    form.setData(
                                        'key_facts',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Press release"
                            htmlFor="press_release"
                            error={errors.press_release}
                        >
                            <Textarea
                                id="press_release"
                                className="min-h-48"
                                value={form.data.press_release}
                                onChange={(event) =>
                                    form.setData(
                                        'press_release',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                    </div>
                </Panel>

                <Panel
                    title="Tracks"
                    description="Contracts belong to tracks. Give every track its own, or fill them all at once below."
                    actions={
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                form.setData('tracks', [
                                    ...form.data.tracks,
                                    {
                                        id: null,
                                        name: '',
                                        artist_id: form.data.artist_id,
                                        contract_id: allContract,
                                        isrc: '',
                                        iswc: '',
                                        track_number:
                                            form.data.tracks.length + 1,
                                        duration: '',
                                    },
                                ])
                            }
                        >
                            <Plus />
                            Add track
                        </Button>
                    }
                >
                    {form.data.tracks.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No tracks. Income can still be booked on the release
                            itself.
                        </p>
                    ) : (
                        <div className="grid gap-3">
                            <Field
                                label="Contract for all tracks"
                                htmlFor="all_contract_id"
                                hint="Fills the contract of every track row below; change a row afterwards for a track with a different deal."
                            >
                                <RecordSelect
                                    id="all_contract_id"
                                    records="contracts"
                                    value={allContract}
                                    onChange={(id) => {
                                        setAllContract(id);
                                        form.setData(
                                            'tracks',
                                            form.data.tracks.map((track) => ({
                                                ...track,
                                                contract_id: id,
                                            })),
                                        );
                                    }}
                                    placeholder="No contract"
                                />
                            </Field>
                            <div className="hidden grid-cols-[3.5rem_1.6fr_1fr_9rem_1.2fr_2.25rem] gap-2 text-xs font-medium text-muted-foreground lg:grid">
                                <span>#</span>
                                <span>{TRACK_FIELD.name.label}</span>
                                <span>{TRACK_FIELD.artist_id.label}</span>
                                <span>{TRACK_FIELD.isrc.label}</span>
                                <span>{TRACK_FIELD.contract_id.label}</span>
                                <span />
                            </div>
                            {form.data.tracks.map((track, position) => (
                                <div
                                    key={track.id ?? `new-${position}`}
                                    className="grid gap-2 lg:grid-cols-[3.5rem_1.6fr_1fr_9rem_1.2fr_2.25rem]"
                                >
                                    <Input
                                        aria-label={
                                            TRACK_FIELD.track_number.label
                                        }
                                        type="number"
                                        min="1"
                                        value={track.track_number}
                                        onChange={(event) =>
                                            setTrack(position, {
                                                track_number: numberOrBlank(
                                                    event.target.value,
                                                ),
                                            })
                                        }
                                    />
                                    <div>
                                        <Input
                                            aria-label={TRACK_FIELD.name.label}
                                            value={track.name}
                                            onChange={(event) =>
                                                setTrack(position, {
                                                    name: event.target.value,
                                                })
                                            }
                                            placeholder={
                                                TRACK_FIELD.name.placeholder
                                            }
                                            required
                                        />
                                        <InputError
                                            message={
                                                errors[
                                                    `tracks.${position}.name`
                                                ]
                                            }
                                        />
                                    </div>
                                    <RecordSelect
                                        aria-label={TRACK_FIELD.artist_id.label}
                                        records="artists"
                                        value={track.artist_id}
                                        onChange={(id) =>
                                            setTrack(position, {
                                                artist_id: id,
                                            })
                                        }
                                        placeholder={
                                            TRACK_FIELD.artist_id.blank
                                        }
                                    />
                                    <div>
                                        <Input
                                            aria-label={TRACK_FIELD.isrc.label}
                                            value={track.isrc}
                                            onChange={(event) =>
                                                setTrack(position, {
                                                    isrc: event.target.value,
                                                })
                                            }
                                            placeholder={
                                                TRACK_FIELD.isrc.placeholder
                                            }
                                            className="font-mono"
                                        />
                                        <InputError
                                            message={
                                                errors[
                                                    `tracks.${position}.isrc`
                                                ]
                                            }
                                        />
                                    </div>
                                    <RecordSelect
                                        aria-label={
                                            TRACK_FIELD.contract_id.label
                                        }
                                        records="contracts"
                                        value={track.contract_id}
                                        onChange={(id) =>
                                            setTrack(position, {
                                                contract_id: id,
                                            })
                                        }
                                        placeholder={
                                            TRACK_FIELD.contract_id.blank
                                        }
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Remove track"
                                        onClick={() =>
                                            form.setData(
                                                'tracks',
                                                form.data.tracks.filter(
                                                    (_, current) =>
                                                        current !== position,
                                                ),
                                            )
                                        }
                                    >
                                        <X />
                                    </Button>
                                </div>
                            ))}
                        </div>
                    )}
                    {removedExisting && (
                        <Notice>
                            Saving deletes the removed tracks. Their
                            transactions stay on the release but lose the track
                            and any track-level contract.
                        </Notice>
                    )}
                </Panel>

                {label?.role === 'admin' &&
                    (label.tools.mechanicals ||
                        label.tools.ppd ||
                        label.tools.reserves) && (
                        <Panel
                            title="Mechanicals, PPD and reserves"
                            description="Used by the tools under Accounting when you run them. Rates of single tracks and items are set from the tool pages."
                        >
                            <div className="grid gap-4 md:grid-cols-3">
                                {label.tools.mechanicals && (
                                    <Field
                                        label="Mechanicals rate"
                                        htmlFor="mechanicals_rate"
                                        error={errors.mechanicals_rate}
                                        hint={`${label.currency} per unit and track. Empty: statutory rate.`}
                                    >
                                        <Input
                                            id="mechanicals_rate"
                                            type="number"
                                            step="any"
                                            min="0"
                                            value={form.data.mechanicals_rate}
                                            onChange={(event) =>
                                                form.setData(
                                                    'mechanicals_rate',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                )}
                                {label.tools.ppd && (
                                    <Field
                                        label="PPD"
                                        htmlFor="ppd_rate"
                                        error={errors.ppd_rate}
                                        hint={`${label.currency} per unit sold. Empty: no PPD.`}
                                    >
                                        <Input
                                            id="ppd_rate"
                                            type="number"
                                            step="any"
                                            min="0"
                                            value={form.data.ppd_rate}
                                            onChange={(event) =>
                                                form.setData(
                                                    'ppd_rate',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                )}
                                {label.tools.reserves && (
                                    <Field
                                        label="Reserves %"
                                        htmlFor="reserves_rate"
                                        error={errors.reserves_rate}
                                        hint="Percentage of physical income to hold: 25 means 25%."
                                    >
                                        <Input
                                            id="reserves_rate"
                                            type="number"
                                            step="any"
                                            min="0"
                                            max="100"
                                            value={form.data.reserves_rate}
                                            onChange={(event) =>
                                                form.setData(
                                                    'reserves_rate',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                )}
                                {label.tools.mechanicals && (
                                    <CheckField
                                        label="Account for mechanicals"
                                        hint="Tracks follow this unless they are set otherwise."
                                        checked={form.data.accounts_mechanicals}
                                        onChange={(event) =>
                                            form.setData(
                                                'accounts_mechanicals',
                                                event.target.checked,
                                            )
                                        }
                                        className="md:col-span-3"
                                    />
                                )}
                            </div>
                        </Panel>
                    )}

                <div className="flex items-center gap-3">
                    <Button disabled={form.processing}>
                        {release ? 'Save release' : 'Create release'}
                    </Button>
                    <Button variant="ghost" asChild>
                        <Link
                            href={
                                returnTo ??
                                (release ? show(release.id) : index())
                            }
                        >
                            Cancel
                        </Link>
                    </Button>
                </div>
            </form>
        </Page>
    );
}
