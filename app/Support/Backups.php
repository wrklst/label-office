<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;
use RuntimeException;

/**
 * Backups of the whole installation in an S3-compatible bucket: the
 * database as a dump, and the stored files.
 *
 * A backup is a folder in the bucket, named by when it was made and by
 * its tier: "2026-11-24-021500-daily". It holds the dump, the files that
 * are new or changed since the backup before it (the first one holds
 * them all), and a manifest that lists every file there was, each with
 * the backup that holds its bytes. So any backup can be put together in
 * full, and one is only ever deleted down to the files no other backup
 * still points at. The manifest is written last: a folder without one
 * is a backup that did not finish, and is not counted.
 */
final class Backups
{
    /** From the longest kept to the shortest: a backup is of the first tier that has none yet in its year, month or week. */
    public const array TIERS = ['yearly', 'monthly', 'weekly', 'daily'];

    /** How many backups of each tier are kept unless set otherwise under Settings → Backups. */
    public const array KEEP = ['daily' => 6, 'weekly' => 3, 'monthly' => 11, 'yearly' => 7];

    /** The cache key of the list of backups the settings page shows. */
    public const string LISTED = 'backups:listed';

    private const string NAME = '/^\d{4}-\d{2}-\d{2}-\d{6}-(yearly|monthly|weekly|daily)$/';

    private const string FOLDER = 'backups';

    /**
     * What an installation admin has set under Settings → Backups, and
     * what the last backup did. Nothing of this is in the environment file.
     *
     * @return array{enabled: bool, hour: int, keep: array<string, int>, bucket: ?array{key?: ?string, secret?: ?string, region?: ?string, bucket?: ?string, endpoint?: ?string, path_style?: bool}, requested: bool, ran_on: ?string, last: ?array{status: string, at: string, name?: string, files?: int, copied?: int, message?: string}}
     */
    public static function settings(): array
    {
        $stored = InstallationSettings::get('backups') ?? [];
        $keep = [];

        foreach (self::KEEP as $tier => $default) {
            $keep[$tier] = (int) ($stored['keep'][$tier] ?? $default);
        }

        return [
            'enabled' => (bool) ($stored['enabled'] ?? true),
            'hour' => (int) ($stored['hour'] ?? 2),
            'keep' => $keep,
            'bucket' => is_array($stored['bucket'] ?? null) ? $stored['bucket'] : null,
            'requested' => (bool) ($stored['requested'] ?? false),
            'ran_on' => $stored['ran_on'] ?? null,
            'last' => $stored['last'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public static function remember(array $changes): void
    {
        InstallationSettings::put('backups', [...self::settings(), ...$changes]);
    }

    /**
     * Backups need a bucket: the one the files can be stored in, or one of their own.
     */
    public static function available(): bool
    {
        $bucket = self::bucket();

        return filled($bucket['bucket'] ?? null) && filled($bucket['key'] ?? null);
    }

    /**
     * The folder "backups" of the backup bucket. The bucket is the one
     * for files unless the backups were given their own.
     */
    public static function disk(): FilesystemAdapter
    {
        config(['filesystems.disks.backup' => self::bucket()]);

        return Storage::disk('backup');
    }

    /**
     * The finished backups, oldest first.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $disk = self::disk();
        $names = array_filter($disk->directories(), fn (string $name): bool => preg_match(self::NAME, $name) === 1 && $disk->exists("{$name}/manifest.json"));
        sort($names);

        return $names;
    }

    /**
     * @return array{name: string, made_at: string, database: string, copied: int, files: array<string, array{size: int, modified: int, in: string}>}
     */
    public static function manifest(string $name): array
    {
        $manifest = json_decode((string) self::disk()->get("{$name}/manifest.json"), true);

        if (! is_array($manifest) || ! is_array($manifest['files'] ?? null) || preg_match('/^database\.(sql|sqlite)\.gz$/', (string) ($manifest['database'] ?? '')) !== 1) {
            throw new RuntimeException("The backup [{$name}] has no readable manifest.");
        }

        $files = [];

        foreach ($manifest['files'] as $key => $file) {
            // A manifest is read from the bucket: it must not name a place outside the backups or the folder one is assembled in.
            if (! is_array($file) || preg_match(self::NAME, (string) ($file['in'] ?? '')) !== 1 || in_array('..', explode('/', (string) $key), true)) {
                throw new RuntimeException("The manifest of the backup [{$name}] is damaged at [{$key}].");
            }

            $files[(string) $key] = ['size' => (int) ($file['size'] ?? 0), 'modified' => (int) ($file['modified'] ?? 0), 'in' => $file['in']];
        }

        return ['name' => $name, 'made_at' => (string) ($manifest['made_at'] ?? ''), 'database' => $manifest['database'], 'copied' => (int) ($manifest['copied'] ?? 0), 'files' => $files];
    }

    /**
     * The tier of a backup made now: the first of the year is yearly,
     * the first of a month monthly, the first of a week weekly, any
     * other daily. A night that was missed therefore costs no tier.
     *
     * @param  list<string>  $names  The backups there are.
     */
    public static function tier(array $names, CarbonImmutable $now): string
    {
        $made = array_map(self::madeAt(...), $names);
        $none = fn (string $format): bool => ! in_array($now->format($format), array_map(fn (CarbonImmutable $at): string => $at->format($format), $made), true);

        return match (true) {
            $none('Y') => 'yearly',
            $none('Y-m') => 'monthly',
            $none('o-W') => 'weekly',
            default => 'daily',
        };
    }

    /**
     * The backups that are more than is kept of their tier.
     *
     * @param  list<string>  $names
     * @param  array<string, int>  $keep  How many to keep per tier.
     * @return list<string>
     */
    public static function expired(array $names, array $keep): array
    {
        $expired = [];
        rsort($names);

        foreach (self::TIERS as $tier) {
            $ofTier = array_values(array_filter($names, fn (string $name): bool => str_ends_with($name, "-{$tier}")));
            $expired = [...$expired, ...array_slice($ofTier, max(0, (int) ($keep[$tier] ?? 0)))];
        }

        sort($expired);

        return $expired;
    }

    /**
     * Make a backup and return its manifest.
     *
     * @return array{name: string, made_at: string, database: string, copied: int, files: array<string, array{size: int, modified: int, in: string}>}
     *
     * @throws RuntimeException when the database or a file could not be backed up
     */
    public static function make(): array
    {
        $disk = self::disk();
        $names = self::names();
        $now = CarbonImmutable::now();
        $name = $now->format('Y-m-d-His').'-'.self::tier($names, $now);
        $before = $names === [] ? [] : self::manifest(end($names))['files'];
        $files = [];
        $copied = 0;

        foreach (self::sources() as $source) {
            foreach (Storage::disk($source)->getDriver()->listContents('', true) as $item) {
                // The backups themselves when they share the bucket, and files still being written.
                if (! $item instanceof FileAttributes || str_starts_with($item->path(), self::FOLDER.'/') || str_ends_with($item->path(), '.tmp')) {
                    continue;
                }

                $path = $item->path();

                $key = "{$source}/{$path}";
                $file = ['size' => (int) $item->fileSize(), 'modified' => (int) $item->lastModified()];
                $known = $before[$key] ?? null;

                if ($known !== null && $known['size'] === $file['size'] && $known['modified'] === $file['modified']) {
                    $files[$key] = [...$file, 'in' => $known['in']];

                    continue;
                }

                $stream = Storage::disk($source)->readStream($path);

                if ($stream === null) {
                    // Deleted between the listing and the copy: it is not part of this backup.
                    continue;
                }

                $disk->writeStream("{$name}/files/{$key}", $stream);
                is_resource($stream) && fclose($stream);

                $files[$key] = [...$file, 'in' => $name];
                $copied++;
            }
        }

        ksort($files);
        $manifest = ['name' => $name, 'made_at' => $now->toIso8601String(), 'database' => self::dumpDatabase($name), 'copied' => $copied, 'files' => $files];
        $disk->put("{$name}/manifest.json", json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $manifest;
    }

    /**
     * Delete the backups beyond what is kept, and what unfinished
     * backups left behind. A file a kept backup still points at stays.
     *
     * @return list<string> The backups that were deleted.
     */
    public static function cleanUp(): array
    {
        $disk = self::disk();
        $names = self::names();
        $expired = self::expired($names, self::settings()['keep']);
        $kept = array_values(array_diff($names, $expired));
        $needed = [];

        foreach ($kept as $name) {
            foreach (self::manifest($name)['files'] as $key => $file) {
                $needed["{$file['in']}/files/{$key}"] = true;
            }
        }

        foreach ($disk->directories() as $folder) {
            if (preg_match(self::NAME, $folder) !== 1 || in_array($folder, $kept, true)) {
                continue;
            }

            $disk->delete(array_values(array_filter($disk->allFiles($folder), fn (string $path): bool => ! isset($needed[$path]))));
        }

        return $expired;
    }

    /**
     * Put a backup together in a folder on this machine: the database
     * dump, and under "files" every file as it was, by disk. Nothing of
     * the running installation is touched.
     *
     * @return int The number of files written.
     *
     * @throws RuntimeException when the folder is in use or a file did not arrive whole
     */
    public static function assemble(string $name, string $to): int
    {
        $disk = self::disk();
        $manifest = self::manifest($name);

        if (File::isDirectory($to) && File::files($to) + File::directories($to) !== []) {
            throw new RuntimeException("The folder [{$to}] is not empty.");
        }

        File::ensureDirectoryExists($to, 0700);

        $packed = "{$to}/{$manifest['database']}";
        self::download($disk, "{$name}/{$manifest['database']}", $packed);
        self::copy('compress.zlib://'.$packed, substr($packed, 0, -3));
        File::delete($packed);

        foreach ($manifest['files'] as $key => $file) {
            self::download($disk, "{$file['in']}/files/{$key}", "{$to}/files/{$key}");

            if (filesize("{$to}/files/{$key}") !== $file['size']) {
                throw new RuntimeException("The file [{$key}] did not arrive whole.");
            }
        }

        return count($manifest['files']);
    }

    /**
     * The disks that can hold files of the installation.
     *
     * @return list<string>
     */
    private static function sources(): array
    {
        return filled(config('filesystems.disks.s3.bucket')) || WorkingCopy::disk() === 's3' ? ['local', 's3'] : ['local'];
    }

    /**
     * Dump the database, pack it and store it with the backup.
     *
     * @return string The name of the file in the backup.
     */
    private static function dumpDatabase(string $name): string
    {
        $scratch = tempnam(sys_get_temp_dir(), 'backup-');

        if ($scratch === false) {
            throw new RuntimeException('No temporary file could be created.');
        }

        try {
            $dump = Updater::dump($scratch);
            self::copy($dump, 'compress.zlib://'.$dump.'.gz');
            $stored = 'database.'.pathinfo($dump, PATHINFO_EXTENSION).'.gz';
            $stream = fopen($dump.'.gz', 'rb');

            if ($stream === false) {
                throw new RuntimeException('The packed database dump could not be read.');
            }

            self::disk()->writeStream("{$name}/{$stored}", $stream);
            is_resource($stream) && fclose($stream);

            return $stored;
        } finally {
            File::delete([$scratch, $scratch.'.sql', $scratch.'.sqlite', $scratch.'.sql.gz', $scratch.'.sqlite.gz']);
        }
    }

    private static function download(FilesystemAdapter $disk, string $path, string $to): void
    {
        $stream = $disk->readStream($path);

        if ($stream === null) {
            throw new RuntimeException("The backup is missing [{$path}].");
        }

        File::ensureDirectoryExists(dirname($to), 0700);
        file_put_contents($to, $stream);
        fclose($stream);
    }

    private static function copy(string $from, string $to): void
    {
        $in = fopen($from, 'rb');
        $out = fopen($to, 'wb');

        if ($in === false || $out === false || stream_copy_to_stream($in, $out) === false) {
            throw new RuntimeException("[{$from}] could not be written to [{$to}].");
        }

        fclose($in);
        fclose($out);
    }

    private static function madeAt(string $name): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d-His', substr($name, 0, 17));
    }

    /**
     * The files' bucket, with whatever the backups were given of their own, and the backup folder in it.
     *
     * @return array<string, mixed>
     */
    private static function bucket(): array
    {
        $files = (array) config('filesystems.disks.s3');
        $own = self::settings()['bucket'];

        return [
            ...$files,
            ...($own === null ? [] : [
                'key' => $own['key'] ?? null,
                'secret' => $own['secret'] ?? null,
                'region' => $own['region'] ?? null,
                'bucket' => $own['bucket'] ?? null,
                'endpoint' => $own['endpoint'] ?? null,
                'use_path_style_endpoint' => (bool) ($own['path_style'] ?? false),
            ]),
            'driver' => 's3',
            'root' => trim(($files['root'] ?? '').'/'.self::FOLDER, '/'),
            'throw' => true,
        ];
    }
}
