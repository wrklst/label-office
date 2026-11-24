import UploadController from '@/actions/App/Http/Controllers/UploadController';

/** Laravel hands the token for write requests to the browser in a cookie. */
export const xsrfToken = () =>
    decodeURIComponent(
        /(?:^|; )XSRF-TOKEN=([^;]*)/.exec(document.cookie)?.[1] ?? '',
    );

/**
 * Send a file straight to the bucket. Answers with the key it is stored
 * under, to be reported to the server as "upload". Answers with null when
 * files are kept on the server or the bucket does not take the file from
 * this page: the file then has to be posted to the server itself.
 */
export async function sendToBucket(
    file: File,
    onProgress: (loaded: number, total: number) => void,
    signal?: AbortSignal,
    /** Where to ask for the address, when it is not the label's own upload page. */
    signUrl: string = UploadController.sign.url(),
): Promise<string | null> {
    const response = await fetch(signUrl, {
        method: 'POST',
        headers: {
            'X-XSRF-TOKEN': xsrfToken(),
            'Content-Type': 'application/json',
            Accept: 'application/json',
        },
        body: JSON.stringify({ name: file.name }),
        signal,
    }).catch(() => null);

    if (!response?.ok) {
        return null;
    }

    const target: {
        url: string | null;
        headers?: Record<string, string>;
        key?: string;
    } = await response.json();

    if (!target.url || !target.key) {
        return null;
    }

    return new Promise((resolve) => {
        const request = new XMLHttpRequest();

        request.open('PUT', target.url as string);
        Object.entries(target.headers ?? {}).forEach(([name, value]) =>
            request.setRequestHeader(name, value),
        );
        request.upload.onprogress = (event) =>
            onProgress(event.loaded, event.total);
        request.onload = () =>
            resolve(
                request.status >= 200 && request.status < 300
                    ? (target.key as string)
                    : null,
            );
        // A bucket that does not know this page's address refuses before any of the file is sent.
        request.onerror = () => resolve(null);
        request.onabort = () => resolve(null);
        signal?.addEventListener('abort', () => request.abort());
        request.send(file);
    });
}
