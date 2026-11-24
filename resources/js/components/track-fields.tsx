import { AiField } from '@/components/ai-field';
import { ArtistCreditRows } from '@/components/artist-credit-rows';
import type { ArtistCredit } from '@/components/artist-credit-rows';
import { Field, SelectInput } from '@/components/field';
import { RecordSelect } from '@/components/record-select';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import type { Option } from '@/types';

export type TrackCredits = Partial<
    Record<
        'composers' | 'lyricists' | 'producers' | 'remixers' | 'publishers',
        string[]
    >
>;

/** Everything the track dialog edits; every page that opens it sends this. */
export type EditableTrack = {
    id: number;
    name: string;
    isrc: string | null;
    iswc: string | null;
    track_number: number | null;
    disc_number: number | null;
    duration: number | null;
    artist_id: number | null;
    contract_id: number | null;
    version: string | null;
    genre: string | null;
    language: string | null;
    explicit: string | null;
    bpm: string | null;
    musical_key: string | null;
    preview_start: number | null;
    p_line: string | null;
    lyrics: string | null;
    credits: TrackCredits | null;
    ai_recording: string | null;
    ai_composition: string | null;
    ai_lyrics: string | null;
    artists: ArtistCredit[];
};

/**
 * The fields of a track that every place that edits one shares: the track
 * dialog and the quick rows on the release form use these labels, hints
 * and blank options, and one request validates them.
 */
export const TRACK_FIELD = {
    name: { label: 'Title', placeholder: 'Track title' },
    version: { label: 'Version', hint: 'e.g. Radio Edit, Remix name' },
    artist_id: { label: 'Main artist', blank: 'Same as release' },
    contract_id: { label: 'Contract', blank: 'Same as release' },
    isrc: { label: 'ISRC', placeholder: 'DEXXX2600001' },
    iswc: { label: 'ISWC' },
    track_number: { label: 'Track number' },
    duration: { label: 'Length (seconds)' },
} as const;

export function TrackFields({
    track,
    inheritedContract,
    genreOptions = [],
    errors,
}: {
    track: EditableTrack;
    /** The contract the release gives the track while it has none of its own. */
    inheritedContract?: Option | null;
    /** Genres to suggest: Zebralution's list while the label delivers there. */
    genreOptions?: string[];
    errors: Record<string, string>;
}) {
    const credit = (role: keyof TrackCredits) =>
        (track.credits?.[role] ?? []).join(', ');

    return (
        <>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label={TRACK_FIELD.name.label}
                    htmlFor="name"
                    error={errors.name}
                    required
                >
                    <Input
                        id="name"
                        name="name"
                        defaultValue={track.name}
                        required
                    />
                </Field>
                <Field
                    label={TRACK_FIELD.version.label}
                    htmlFor="version"
                    error={errors.version}
                    hint={TRACK_FIELD.version.hint}
                >
                    <Input
                        id="version"
                        name="version"
                        defaultValue={track.version ?? ''}
                    />
                </Field>
                <Field
                    label={TRACK_FIELD.artist_id.label}
                    htmlFor="artist_id"
                    error={errors.artist_id}
                >
                    <RecordSelect
                        id="artist_id"
                        name="artist_id"
                        records="artists"
                        defaultValue={track.artist_id}
                        placeholder={TRACK_FIELD.artist_id.blank}
                    />
                </Field>
                <Field
                    label={TRACK_FIELD.contract_id.label}
                    htmlFor="contract_id"
                    error={errors.contract_id}
                >
                    <RecordSelect
                        id="contract_id"
                        name="contract_id"
                        records="contracts"
                        defaultValue={track.contract_id}
                        placeholder={
                            inheritedContract
                                ? `${TRACK_FIELD.contract_id.blank} (${inheritedContract.name})`
                                : TRACK_FIELD.contract_id.blank
                        }
                    />
                </Field>
            </div>
            <Field label="Further artist credits" error={errors.artists}>
                <ArtistCreditRows defaultValue={track.artists} />
            </Field>
            <div className="grid gap-4 sm:grid-cols-3">
                <Field
                    label={TRACK_FIELD.isrc.label}
                    htmlFor="isrc"
                    error={errors.isrc}
                >
                    <Input
                        id="isrc"
                        name="isrc"
                        defaultValue={track.isrc ?? ''}
                        className="font-mono"
                    />
                </Field>
                <Field
                    label={TRACK_FIELD.iswc.label}
                    htmlFor="iswc"
                    error={errors.iswc}
                >
                    <Input
                        id="iswc"
                        name="iswc"
                        defaultValue={track.iswc ?? ''}
                        className="font-mono"
                    />
                </Field>
                <Field
                    label={TRACK_FIELD.duration.label}
                    htmlFor="duration"
                    error={errors.duration}
                >
                    <Input
                        id="duration"
                        name="duration"
                        type="number"
                        min="0"
                        defaultValue={track.duration ?? ''}
                    />
                </Field>
                <Field
                    label="Disc"
                    htmlFor="disc_number"
                    error={errors.disc_number}
                >
                    <Input
                        id="disc_number"
                        name="disc_number"
                        type="number"
                        min="1"
                        defaultValue={track.disc_number ?? ''}
                    />
                </Field>
                <Field
                    label={TRACK_FIELD.track_number.label}
                    htmlFor="track_number"
                    error={errors.track_number}
                >
                    <Input
                        id="track_number"
                        name="track_number"
                        type="number"
                        min="1"
                        defaultValue={track.track_number ?? ''}
                    />
                </Field>
                <Field
                    label="Preview starts at (s)"
                    htmlFor="preview_start"
                    error={errors.preview_start}
                >
                    <Input
                        id="preview_start"
                        name="preview_start"
                        type="number"
                        min="0"
                        defaultValue={track.preview_start ?? ''}
                    />
                </Field>
                <Field label="BPM" htmlFor="bpm" error={errors.bpm}>
                    <Input
                        id="bpm"
                        name="bpm"
                        type="number"
                        step="0.01"
                        defaultValue={track.bpm ?? ''}
                    />
                </Field>
                <Field
                    label="Key"
                    htmlFor="musical_key"
                    error={errors.musical_key}
                >
                    <Input
                        id="musical_key"
                        name="musical_key"
                        defaultValue={track.musical_key ?? ''}
                        placeholder="F minor"
                    />
                </Field>
                <Field
                    label="Explicit"
                    htmlFor="explicit"
                    error={errors.explicit}
                >
                    <SelectInput
                        id="explicit"
                        name="explicit"
                        defaultValue={track.explicit ?? ''}
                        placeholder="Same as release"
                    >
                        <option value="explicit">Explicit</option>
                        <option value="clean">Clean version</option>
                    </SelectInput>
                </Field>
                <AiField
                    label="AI in the recording"
                    name="ai_recording"
                    error={errors.ai_recording}
                    defaultValue={track.ai_recording ?? ''}
                />
                <AiField
                    label="AI in the composition"
                    name="ai_composition"
                    error={errors.ai_composition}
                    defaultValue={track.ai_composition ?? ''}
                />
                <AiField
                    label="AI in the lyrics"
                    name="ai_lyrics"
                    error={errors.ai_lyrics}
                    defaultValue={track.ai_lyrics ?? ''}
                />
                <Field label="Genre" htmlFor="genre" error={errors.genre}>
                    <datalist id="track-genres">
                        {genreOptions.map((genre) => (
                            <option key={genre} value={genre} />
                        ))}
                    </datalist>
                    <Input
                        id="genre"
                        name="genre"
                        list="track-genres"
                        defaultValue={track.genre ?? ''}
                        placeholder="Same as release"
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
                        name="language"
                        defaultValue={track.language ?? ''}
                        maxLength={8}
                    />
                </Field>
                <Field label="℗ line" htmlFor="p_line" error={errors.p_line}>
                    <Input
                        id="p_line"
                        name="p_line"
                        defaultValue={track.p_line ?? ''}
                        placeholder="Same as release"
                    />
                </Field>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Composers"
                    htmlFor="credits_composers"
                    hint="Separate names with commas."
                >
                    <Input
                        id="credits_composers"
                        name="credits[composers]"
                        defaultValue={credit('composers')}
                    />
                </Field>
                <Field label="Lyricists" htmlFor="credits_lyricists">
                    <Input
                        id="credits_lyricists"
                        name="credits[lyricists]"
                        defaultValue={credit('lyricists')}
                    />
                </Field>
                <Field label="Producers" htmlFor="credits_producers">
                    <Input
                        id="credits_producers"
                        name="credits[producers]"
                        defaultValue={credit('producers')}
                    />
                </Field>
                <Field label="Publishers" htmlFor="credits_publishers">
                    <Input
                        id="credits_publishers"
                        name="credits[publishers]"
                        defaultValue={credit('publishers')}
                    />
                </Field>
            </div>
            <Field label="Lyrics" htmlFor="lyrics" error={errors.lyrics}>
                <Textarea
                    id="lyrics"
                    name="lyrics"
                    defaultValue={track.lyrics ?? ''}
                />
            </Field>
        </>
    );
}
