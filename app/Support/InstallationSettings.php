<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Settings of the whole installation that are changed in the interface
 * instead of the environment file. Stored encrypted; the environment
 * stays the fallback for anything not set here.
 */
class InstallationSettings
{
    private const string STORAGE = 'storage';

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $key): ?array
    {
        try {
            $value = DB::table('installation_settings')->where('key', $key)->value('value');
        } catch (QueryException) {
            // Before the first migration there is no table yet.
            return null;
        }

        try {
            return is_string($value) ? json_decode(Crypt::decryptString($value), true) : null;
        } catch (DecryptException) {
            // Written with another APP_KEY: as good as not set, and the environment decides again.
            return null;
        }
    }

    /**
     * @param  array<string, mixed>|null  $value  Null removes the setting.
     */
    public static function put(string $key, ?array $value): void
    {
        if ($value === null) {
            DB::table('installation_settings')->where('key', $key)->delete();

            return;
        }

        DB::table('installation_settings')->updateOrInsert(['key' => $key], [
            'value' => Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR)),
            'updated_at' => now(),
            'created_at' => now(),
        ]);
    }

    /**
     * The file storage as set in the interface, or null while the
     * environment decides.
     *
     * @return array{disk: string, key?: string, secret?: string, region?: string, bucket?: string, endpoint?: string, path_style?: bool}|null
     */
    public static function storage(): ?array
    {
        $storage = self::get(self::STORAGE);

        return is_string($storage['disk'] ?? null) ? $storage : null;
    }

    /**
     * @param  array{disk: string, key?: string, secret?: string, region?: string, bucket?: string, endpoint?: string, path_style?: bool}|null  $storage
     */
    public static function putStorage(?array $storage): void
    {
        self::put(self::STORAGE, $storage);
    }

    /**
     * Put the stored file storage into effect for this request or job.
     * ponytail: one small query per request; cache it if that ever shows up in a profile.
     */
    public static function apply(): void
    {
        $storage = self::storage();

        if ($storage === null) {
            return;
        }

        config(['royalties.storage.disk' => $storage['disk']]);

        if ($storage['disk'] === 's3') {
            config(['filesystems.disks.s3' => self::bucket($storage)]);
        }
    }

    /**
     * Prove that the bucket takes, returns and removes a file.
     *
     * @param  array{key?: string, secret?: string, region?: string, bucket?: string, endpoint?: string, path_style?: bool}  $storage
     *
     * @throws RuntimeException with what the storage answered
     */
    public static function probe(array $storage): void
    {
        $disk = Storage::build([...self::bucket($storage), 'throw' => true]);
        $path = '.connection-test-'.bin2hex(random_bytes(8));

        try {
            $disk->put($path, 'ok');
            $read = $disk->get($path);
            $disk->delete($path);
        } catch (Throwable $exception) {
            throw new RuntimeException('The bucket could not be used: '.mb_substr($exception->getMessage(), 0, 300), previous: $exception);
        }

        if ($read !== 'ok') {
            throw new RuntimeException('The bucket did not return the test file it was given.');
        }
    }

    /**
     * The disk configuration for a bucket, on top of what the environment defines.
     *
     * @param  array{key?: string, secret?: string, region?: string, bucket?: string, endpoint?: string, path_style?: bool}  $storage
     * @return array<string, mixed>
     */
    private static function bucket(array $storage): array
    {
        return [
            ...config('filesystems.disks.s3'),
            'key' => $storage['key'] ?? null,
            'secret' => $storage['secret'] ?? null,
            'region' => $storage['region'] ?? null,
            'bucket' => $storage['bucket'] ?? null,
            'endpoint' => $storage['endpoint'] ?? null,
            'use_path_style_endpoint' => (bool) ($storage['path_style'] ?? false),
        ];
    }
}
