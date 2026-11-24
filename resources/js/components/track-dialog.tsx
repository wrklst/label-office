import { useHttp } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import TrackController from '@/actions/App/Http/Controllers/TrackController';
import { FormDialog } from '@/components/form-dialog';
import { TrackFields } from '@/components/track-fields';
import type { EditableTrack } from '@/components/track-fields';
import { Button } from '@/components/ui/button';
import type { Option } from '@/types';

type ListedTrack = Omit<EditableTrack, 'lyrics' | 'credits'> &
    Partial<Pick<EditableTrack, 'lyrics' | 'credits'>>;

/**
 * The fields of a track from a long list come without lyrics and credits;
 * they are fetched when the dialog opens, which is when this is mounted.
 */
function LoadedTrackFields({
    track,
    ...props
}: Omit<Parameters<typeof TrackFields>[0], 'track'> & { track: ListedTrack }) {
    const http = useHttp();
    const [full, setFull] = useState<EditableTrack | null>(
        track.lyrics === undefined || track.credits === undefined
            ? null
            : (track as EditableTrack),
    );

    useEffect(() => {
        if (full !== null) {
            return;
        }

        http.get(TrackController.fields.url(track.id))
            .then((data) =>
                setFull({ ...track, ...(data as object) } as EditableTrack),
            )
            .catch(() => undefined);
        // Once, when the dialog opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    if (full === null) {
        return <p className="text-sm text-muted-foreground">Loading…</p>;
    }

    return <TrackFields track={full} {...props} />;
}

/**
 * The one dialog a track is edited in: from the tracks list, the release
 * page and the track page. Without a `trigger` it is the pencil of a table row.
 */
export function TrackDialog({
    track,
    inheritedContract,
    genreOptions,
    trigger,
}: {
    track: ListedTrack;
    inheritedContract?: Option | null;
    genreOptions?: string[];
    trigger?: ReactNode;
}) {
    return (
        <FormDialog
            title={`Edit ${track.name}`}
            form={TrackController.update.form(track.id)}
            submitLabel="Save track"
            trigger={
                trigger ?? (
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label={`Edit ${track.name}`}
                    >
                        <Pencil className="text-muted-foreground" />
                    </Button>
                )
            }
        >
            {(errors) => (
                <LoadedTrackFields
                    track={track}
                    inheritedContract={inheritedContract}
                    genreOptions={genreOptions}
                    errors={errors}
                />
            )}
        </FormDialog>
    );
}
