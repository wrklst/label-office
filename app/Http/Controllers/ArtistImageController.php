<?php

namespace App\Http\Controllers;

use App\Models\Artist;
use App\Support\WorkingCopy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The picture of an artist, shown wherever the artist is listed. It is
 * stored with the disk it is on, as "disk:path", like the label's logo.
 */
class ArtistImageController extends Controller
{
    /**
     * The address of the artist's picture, or null when there is none.
     * It changes with the file, so browsers may keep what they loaded.
     */
    public static function url(Artist $artist): ?string
    {
        return self::stored($artist) === null ? null : route('artists.image.show', ['artist' => $artist->id, 'v' => substr(md5((string) $artist->image_path), 0, 8)]);
    }

    /**
     * Store an uploaded picture and remove the one it replaces. Answers
     * with plain text, as the upload field expects.
     */
    public function store(Request $request, Artist $artist): Response
    {
        // No SVG: it can carry scripts.
        $request->validate(['file' => ['required', 'file', 'max:4096', 'mimetypes:image/png,image/jpeg,image/webp']], [
            'file.mimetypes' => __('The image has to be a PNG, JPEG or WebP file.'),
            'file.max' => __('The image can be 4 MB at most.'),
        ]);

        $disk = WorkingCopy::disk();
        $file = $request->file('file');
        $path = $file->storeAs("artists/{$artist->label_id}", Str::uuid().'.'.$file->extension(), $disk);

        $this->forget($artist);
        $artist->update(['image_path' => "{$disk}:{$path}"]);

        return response('image', 200, ['Content-Type' => 'text/plain']);
    }

    public function show(Artist $artist): Response
    {
        [$disk, $path] = self::stored($artist) ?? abort(404);

        abort_unless(Storage::disk($disk)->exists($path), 404);

        return Storage::disk($disk)->response($path, null, ['Cache-Control' => 'private, max-age=31536000, immutable', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function destroy(Artist $artist): Response
    {
        $this->forget($artist);
        $artist->update(['image_path' => null]);

        return back();
    }

    /**
     * Where the picture is, as disk and path. A value from before
     * pictures were uploaded here, such as an address, is not one.
     *
     * @return array{string, string}|null
     */
    private static function stored(Artist $artist): ?array
    {
        $parts = explode(':', (string) $artist->image_path, 2);

        return count($parts) === 2 && array_key_exists($parts[0], (array) config('filesystems.disks')) ? [$parts[0], $parts[1]] : null;
    }

    private function forget(Artist $artist): void
    {
        if ($stored = self::stored($artist)) {
            Storage::disk($stored[0])->delete($stored[1]);
        }
    }
}
