import { router } from '@inertiajs/react';
import { registerPlugin } from 'filepond';
import FilePondPluginFileValidateType from 'filepond-plugin-file-validate-type';
import FilePondPluginImagePreview from 'filepond-plugin-image-preview';
import { FilePond } from 'react-filepond';
import LabelBrandingController from '@/actions/App/Http/Controllers/Settings/LabelBrandingController';
import { DeleteButton } from '@/components/delete-button';
import { xsrfToken } from '@/lib/direct-upload';
import 'filepond/dist/filepond.min.css';
import 'filepond-plugin-image-preview/dist/filepond-plugin-image-preview.min.css';

registerPlugin(FilePondPluginFileValidateType, FilePondPluginImagePreview);

/**
 * One image of the label: what is stored now, and a drop area that
 * uploads a new one in its place.
 */
export function LabelImageUpload({
    kind,
    title,
    hint,
    current,
    urls,
}: {
    /** What the picture is called in the texts, such as "logo". */
    kind: string;
    /** Where the picture is stored and deleted, when it is not one of the label's own. */
    urls?: { store: string; destroy: string };
    title: string;
    hint: string;
    current: string | null;
}) {
    return (
        <div data-label-image={kind} className="grid content-start gap-2">
            <div className="flex items-center justify-between gap-2">
                <p className="text-sm font-medium">{title}</p>
                {current && (
                    <DeleteButton
                        url={
                            urls?.destroy ??
                            LabelBrandingController.destroy.url(kind)
                        }
                        title={`Delete ${kind}?`}
                        description="The image is deleted. You can upload another one."
                    />
                )}
            </div>
            {current && (
                <div className="flex h-24 items-center justify-center rounded-lg border bg-muted/40 p-3">
                    <img
                        src={current}
                        alt={`Current ${kind}`}
                        className="max-h-full max-w-full object-contain"
                    />
                </div>
            )}
            <FilePond
                key={current ?? 'none'}
                name="file"
                credits={false}
                acceptedFileTypes={['image/png', 'image/jpeg', 'image/webp']}
                labelFileTypeNotAllowed="Not a PNG, JPEG or WebP image"
                labelIdle={`Drop ${current ? 'a new' : 'the'} ${kind} here or <span class="filepond--label-action">browse</span>`}
                imagePreviewHeight={96}
                server={{
                    process: {
                        url:
                            urls?.store ??
                            LabelBrandingController.store.url(kind),
                        headers: {
                            'X-XSRF-TOKEN': xsrfToken(),
                            Accept: 'application/json',
                        },
                        // FilePond sends a text part of the same name beside the file; the server wants the file alone.
                        ondata: (data) => {
                            const upload = new FormData();
                            const file = data
                                .getAll('file')
                                .find((part) => part instanceof File);

                            if (file) {
                                upload.append('file', file);
                            }

                            return upload;
                        },
                        onerror: (response) => {
                            try {
                                return (
                                    Object.values(
                                        JSON.parse(response).errors ?? {},
                                    ).flat()[0] ?? 'The upload failed.'
                                );
                            } catch {
                                return 'The upload failed.';
                            }
                        },
                    },
                    revert: null,
                }}
                labelFileProcessingError={(error) =>
                    typeof error?.body === 'string'
                        ? error.body
                        : 'The upload failed.'
                }
                onprocessfile={(error) => {
                    if (!error) {
                        // The stored image takes the place of the upload: a new address remounts the field empty.
                        router.reload();
                    }
                }}
            />
            <p className="text-xs text-muted-foreground">{hint}</p>
        </div>
    );
}
