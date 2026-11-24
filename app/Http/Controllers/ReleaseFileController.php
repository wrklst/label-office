<?php

namespace App\Http\Controllers;

use App\Enums\AudioPreviewStatus;
use App\Enums\ReleaseFileKind;
use App\Models\Release;
use App\Models\ReleaseFile;
use App\Services\Releases\AudioPreviews;
use App\Services\Releases\ImageTooLarge;
use App\Services\Releases\ReleaseFileStore;
use App\Services\Releases\ReleaseImageSize;
use App\Services\Releases\ReleaseImageVariants;
use App\Support\CurrentLabel;
use App\Support\IncomingFile;
use App\Support\WorkingCopy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uploads and serves the files of a release. Files sit on a private
 * disk or in a private bucket; this controller is the only way to reach
 * them, so every request is checked against the signed-in label.
 */
class ReleaseFileController extends Controller
{
    /** Never stored: they could be mistaken for something to run. */
    public const array REFUSED_EXTENSIONS = ['php', 'phtml', 'phar', 'html', 'htm', 'js', 'mjs', 'exe', 'sh', 'bat', 'cmd', 'svg'];

    /** How long a browser may show a resized image before asking again, in seconds. */
    private const int VARIANT_MAX_AGE = 86400;

    /** How long a link to a file in a bucket works: long enough to start a download or a song. */
    private const int BUCKET_LINK_MINUTES = 10;

    public function store(Request $request, Release $release, ReleaseFileStore $files, CurrentLabel $currentLabel): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required_without:upload', 'file', 'max:'.config('royalties.uploads.max_kilobytes')],
            ...IncomingFile::RULES,
            'kind' => ['nullable', Rule::enum(ReleaseFileKind::class)],
            'track_id' => ['nullable', 'integer', Rule::exists('release_track', 'track_id')->where('release_id', $release->id)],
        ], [
            'file.max' => 'The file is larger than the '.round(config('royalties.uploads.max_kilobytes') / 1024).' MB this server accepts in one upload.',
            'file.uploaded' => 'The upload did not arrive completely. The file may be larger than the server accepts.',
        ]);

        $upload = IncomingFile::from($request, $release->label_id, (int) config('royalties.uploads.max_kilobytes'));

        if (in_array(strtolower(pathinfo($upload->name, PATHINFO_EXTENSION)), self::REFUSED_EXTENSIONS, true)) {
            $upload->discard();

            return back()->withErrors(['file' => 'Files of this type cannot be stored here.']);
        }

        $file = $files->store(
            $release,
            $upload,
            ReleaseFileKind::tryFrom((string) ($validated['kind'] ?? '')),
            isset($validated['track_id']) ? (int) $validated['track_id'] : null,
            $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name uploaded.', ['name' => $file->original_name])]);

        return back();
    }

    /**
     * Send a file to the browser. Audio and images are streamed in place
     * (with range requests, so the player can seek); anything else, or
     * any file when ?download=1 is given, is sent as a download. An image
     * can be asked for in a smaller size with ?size=thumb or ?size=medium,
     * and audio as its MP3 playback copy with ?stream=1.
     */
    public function show(Request $request, ReleaseFile $releaseFile, ReleaseImageVariants $variants, AudioPreviews $previews): Response
    {
        $validated = $request->validate(['size' => ['nullable', Rule::enum(ReleaseImageSize::class)]]);
        $disk = Storage::disk($releaseFile->disk);

        abort_unless($disk->exists($releaseFile->path), 404);

        $size = ReleaseImageSize::tryFrom((string) ($validated['size'] ?? ''));

        if ($size !== null && ! $request->boolean('download')) {
            try {
                $variant = $variants->path($releaseFile, $size);
            } catch (ImageTooLarge) {
                abort(404);
            }

            // Without a variant (not an image, or one that cannot be read) the original is sent.
            if ($variant !== null) {
                return $this->variant($request, $releaseFile, $variant);
            }
        }

        if ($request->boolean('stream') && ! $request->boolean('download') && $releaseFile->isAudio()) {
            return $this->stream($releaseFile, $previews);
        }

        $inline = ! $request->boolean('download') && $releaseFile->canBeShownInline();

        return $this->privateFile(
            $releaseFile->disk,
            $releaseFile->path,
            $inline ? (string) $releaseFile->mime_type : 'application/octet-stream',
            $this->disposition($inline, $releaseFile->original_name),
        );
    }

    /**
     * Audio for the player: the MP3 playback copy once it is made.
     * Until then the master itself, when it is in a format browsers
     * play; otherwise there is nothing to play yet.
     */
    private function stream(ReleaseFile $releaseFile, AudioPreviews $previews): Response
    {
        $disk = Storage::disk($releaseFile->disk);
        $preview = $previews->path($releaseFile);

        if ($preview !== null) {
            return $this->privateFile(
                $releaseFile->disk,
                $preview,
                'audio/mpeg',
                $this->disposition(true, pathinfo($releaseFile->original_name, PATHINFO_FILENAME).'.mp3'),
            );
        }

        if ($releaseFile->canBeShownInline() && str_starts_with((string) $releaseFile->mime_type, 'audio/')) {
            return $this->privateFile($releaseFile->disk, $releaseFile->path, (string) $releaseFile->mime_type, $this->disposition(true, $releaseFile->original_name));
        }

        return response()->json([
            'message' => $releaseFile->preview_status === AudioPreviewStatus::Failed
                ? 'This file could not be converted for playback.'
                : 'The playback copy of this file is not ready yet.',
            'preview_status' => $releaseFile->preview_status?->value,
        ], 409);
    }

    /**
     * A stored file, sent so that it is not kept by any cache and cannot
     * act as a page. Range requests are answered, so audio can be seeked.
     * A file in a bucket is not passed through this server: the browser is
     * sent to a link to it that only works for a few minutes.
     */
    private function privateFile(string $disk, string $path, string $contentType, string $disposition): Response
    {
        if (! WorkingCopy::isLocal($disk)) {
            return $this->bucketFile($disk, $path, $contentType, $disposition);
        }

        $response = response()->file(Storage::disk($disk)->path($path), [
            'Content-Type' => $contentType,
            'Content-Disposition' => $disposition,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);

        // Private files must not end up in shared caches.
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /**
     * A resized image. Its file name changes with its content, so the
     * browser of the signed-in user may keep it for a while and then ask
     * whether it changed; shared caches never may.
     */
    private function variant(Request $request, ReleaseFile $releaseFile, string $path): Response
    {
        $disposition = $this->disposition(true, pathinfo($releaseFile->original_name, PATHINFO_FILENAME).'.jpg');

        if (! WorkingCopy::isLocal($releaseFile->disk)) {
            return $this->bucketFile($releaseFile->disk, $path, 'image/jpeg', $disposition);
        }

        $response = response()->file(Storage::disk($releaseFile->disk)->path($path), [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => $disposition,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);

        $response->setEtag(pathinfo($path, PATHINFO_FILENAME));
        $response->setPrivate();
        $response->setMaxAge(self::VARIANT_MAX_AGE);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Send the browser to the file in its bucket. The link is signed, says
     * how the file is to be served and stops working after a few minutes.
     */
    private function bucketFile(string $disk, string $path, string $contentType, string $disposition): RedirectResponse
    {
        $response = redirect()->away(Storage::disk($disk)->temporaryUrl($path, now()->addMinutes(self::BUCKET_LINK_MINUTES), [
            'ResponseContentType' => $contentType,
            'ResponseContentDisposition' => $disposition,
            'ResponseCacheControl' => 'private, no-store',
        ]));

        // The link itself must not be remembered either.
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function disposition(bool $inline, string $name): string
    {
        return HeaderUtils::makeDisposition(
            $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
            $name,
            preg_replace('/[^\x20-\x7E]|[%\/\\\\]/', '_', $name) ?? 'file',
        );
    }

    /**
     * Change what a file is, or which track an audio file belongs to.
     */
    public function update(Request $request, ReleaseFile $releaseFile, AudioPreviews $previews): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::enum(ReleaseFileKind::class)],
            'track_id' => ['nullable', 'integer', Rule::exists('release_track', 'track_id')->where('release_id', $releaseFile->release_id)],
        ]);

        $kind = ReleaseFileKind::from($validated['kind']);

        if ($kind === ReleaseFileKind::Cover) {
            if (! in_array($releaseFile->mime_type, ReleaseFileStore::COVER_TYPES, true)) {
                return back()->withErrors(['kind' => 'A cover must be a JPEG, PNG or WebP image.']);
            }

            ReleaseFile::query()->where('release_id', $releaseFile->release_id)->where('kind', ReleaseFileKind::Cover)
                ->whereKeyNot($releaseFile->id)->update(['kind' => ReleaseFileKind::Artwork]);
        }

        $wasAudio = $releaseFile->isAudio();

        $releaseFile->update([
            'kind' => $kind,
            'track_id' => $kind === ReleaseFileKind::Audio ? ($validated['track_id'] ?? null) : null,
        ]);

        if ($kind === ReleaseFileKind::Audio && (! $wasAudio || $releaseFile->preview_status === null)) {
            $previews->queue($releaseFile);
        } elseif ($kind !== ReleaseFileKind::Audio && $wasAudio) {
            $previews->discard($releaseFile);
        }

        return back();
    }

    /**
     * Make the MP3 playback copy of an audio file (again).
     */
    public function preview(ReleaseFile $releaseFile, AudioPreviews $previews): RedirectResponse
    {
        abort_unless($releaseFile->isAudio(), 404);

        $previews->queue($releaseFile, force: true);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Converting :name for playback.', ['name' => $releaseFile->original_name])]);

        return back();
    }

    /**
     * How far the playback copies of a release's audio files are, for
     * the file table, which asks again while any is still being made.
     */
    public function previews(Release $release, AudioPreviews $previews): JsonResponse
    {
        return response()->json($release->files()->where('kind', ReleaseFileKind::Audio)->orderBy('id')->get()->map(fn (ReleaseFile $file): array => [
            'id' => $file->id,
            'status' => $file->preview_status?->value,
            'error' => $file->preview_error,
            'converted' => ! $previews->isOwnPreview($file),
        ])->values());
    }

    public function destroy(ReleaseFile $releaseFile): RedirectResponse
    {
        $releaseFile->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('File deleted.')]);

        return back();
    }
}
