import { router } from '@inertiajs/react';
import { registerPlugin } from 'filepond';
import FilePondPluginFileValidateType from 'filepond-plugin-file-validate-type';
import { useRef } from 'react';
import { FilePond } from 'react-filepond';
import { sendToBucket } from '@/lib/direct-upload';
import 'filepond/dist/filepond.min.css';

registerPlugin(FilePondPluginFileValidateType);

/**
 * Requests to `url` wait for each other on every drop area of the page:
 * a page visit that starts while another runs would cancel it.
 */
let reporting: Promise<void> = Promise.resolve();

/**
 * A drop area that uploads each file as soon as it is added. A file goes
 * straight to the bucket where there is one, three at a time, and is then
 * reported to `url`; otherwise it is posted to `url` itself, one after
 * the other.
 */
export function FileDrop({
    url,
    data,
    accept,
    maxMegabytes,
    label,
    signUrl,
    multiple = true,
}: {
    url: string;
    /** Sent along with every file. */
    data?: Record<string, string | null>;
    /** MIME types; without it every file is taken. */
    accept?: string[];
    maxMegabytes: number;
    label: string;
    /** Where the address for a direct upload is asked for, when not the label's own. */
    signUrl?: string;
    multiple?: boolean;
}) {
    const pond = useRef<FilePond | null>(null);
    // The upload of a file added earlier must send what is chosen now.
    const extra = useRef(data);
    extra.current = data;

    return (
        <FilePond
            ref={pond}
            name="file"
            credits={false}
            allowMultiple={multiple}
            maxParallelUploads={3}
            acceptedFileTypes={accept}
            labelFileTypeNotAllowed="This type of file is not accepted"
            fileValidateTypeLabelExpectedTypes=""
            labelIdle={`${label} or <span class="filepond--label-action">browse</span>`}
            labelFileProcessingError={(error) =>
                typeof error?.body === 'string'
                    ? error.body
                    : 'The upload failed.'
            }
            server={{
                process: (_field, blob, _metadata, load, error, progress) => {
                    const file = blob as File;
                    const stop = new AbortController();

                    if (file.size > maxMegabytes * 1024 * 1024) {
                        error(`Larger than ${maxMegabytes} MB`);

                        return { abort: () => undefined };
                    }

                    sendToBucket(
                        file,
                        (loaded, total) => progress(true, loaded, total),
                        stop.signal,
                        signUrl,
                    ).then(
                        (key) => {
                            reporting = reporting.then(
                                () =>
                                    new Promise<void>((done) => {
                                        if (stop.signal.aborted) {
                                            return done();
                                        }

                                        router.post(
                                            url,
                                            key
                                                ? {
                                                      ...extra.current,
                                                      upload: key,
                                                      upload_name: file.name,
                                                  }
                                                : { ...extra.current, file },
                                            {
                                                forceFormData: !key,
                                                preserveScroll: true,
                                                preserveState: true,
                                                onProgress: (event) =>
                                                    event &&
                                                    !key &&
                                                    progress(
                                                        true,
                                                        event.loaded,
                                                        event.total ??
                                                            file.size,
                                                    ),
                                                onSuccess: () =>
                                                    load(key ?? file.name),
                                                onError: (errors) =>
                                                    error(
                                                        Object.values(
                                                            errors,
                                                        ).join(' '),
                                                    ),
                                                // Leaving the page cancels the request.
                                                onCancel: () =>
                                                    error('Not uploaded'),
                                                onFinish: () => done(),
                                            },
                                        );
                                    }),
                            );
                        },
                        () => error('The upload failed.'),
                    );

                    return { abort: () => stop.abort() };
                },
                revert: null,
            }}
            onprocessfile={(error, item) => {
                // The stored file shows up in the list below; the drop area is for what is still to come.
                if (!error) {
                    pond.current?.removeFile(item.id);
                }
            }}
        />
    );
}
