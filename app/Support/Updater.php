<?php

namespace App\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Brings this installation to the newest tagged version of the
 * repository it was cloned from. The work itself is done by bin/update;
 * this class decides whether it may run, backs up the database first and
 * keeps what happened.
 */
final class Updater
{
    /** Tags that count as versions: 1.2.3 or v1.2.3, also as alpha, beta or rc, such as v0.0.2beta. */
    private const string VERSION = '/^v?\d+\.\d+\.\d+([-.]?(alpha|beta|rc)[-.]?\d*)?$/i';

    private const int BACKUPS_KEPT = 3;

    /**
     * What an installation admin has set, and what the last update did.
     *
     * @return array{auto: bool, hour: int, requested: bool, checked_on: ?string, last: ?array{tag: string, from: string, status: string, at: string, backup: ?string, message?: string}}
     */
    public static function settings(): array
    {
        $stored = InstallationSettings::get('updates') ?? [];

        return [
            'auto' => (bool) ($stored['auto'] ?? false),
            'hour' => (int) ($stored['hour'] ?? 13),
            'requested' => (bool) ($stored['requested'] ?? false),
            'checked_on' => $stored['checked_on'] ?? null,
            'last' => $stored['last'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public static function remember(array $changes): void
    {
        InstallationSettings::put('updates', [...self::settings(), ...$changes]);
    }

    /**
     * The version this installation runs: its tag, or the commit when it is on none.
     */
    public static function current(): string
    {
        return trim(self::git('describe', '--tags', '--always')->output()) ?: 'unknown';
    }

    /**
     * The newest tagged version of the repository, after asking it for its tags.
     *
     * @throws RuntimeException when the repository cannot be reached
     */
    public static function latest(): ?string
    {
        $fetched = self::git('fetch', '--quiet', '--tags', 'origin');

        if ($fetched->failed()) {
            throw new RuntimeException('The repository could not be reached: '.mb_substr(trim($fetched->errorOutput()), 0, 300));
        }

        $versions = array_filter(explode("\n", self::git('tag', '--list')->output()), fn (string $tag): bool => preg_match(self::VERSION, $tag) === 1);
        usort($versions, fn (string $a, string $b): int => version_compare(ltrim($a, 'v'), ltrim($b, 'v')));

        return $versions === [] ? null : end($versions);
    }

    /**
     * Whether the installation does not contain $tag yet.
     */
    public static function isBehind(string $tag): bool
    {
        return self::git('merge-base', '--is-ancestor', $tag, 'HEAD')->failed();
    }

    /**
     * Why this installation cannot update itself, as a sentence for the
     * user. Null when nothing stands in the way.
     */
    public static function obstacle(): ?string
    {
        if (! is_dir(base_path('.git'))) {
            return 'This installation is not a git clone of the repository, so there is nothing to fetch new versions from.';
        }

        if (basename(dirname(base_path())) === 'releases') {
            return 'This installation is put in place by a deployment tool such as Forge or Envoyer. Update it by deploying the new version there.';
        }

        $changed = rtrim(self::git('status', '--porcelain', '--untracked-files=no')->output());

        if ($changed !== '') {
            return 'Files of the application were changed on this server, and an update would overwrite them: '
                .implode(', ', array_map(fn (string $line): string => trim(substr($line, 3)), array_slice(explode("\n", $changed), 0, 5))).'.';
        }

        foreach (['bash', 'composer', 'npm'] as $program) {
            if ((new ExecutableFinder)->find($program) === null) {
                return "The program “{$program}” was not found on this server.";
            }
        }

        return null;
    }

    /**
     * Update to $tag. Returns whether the new version runs now; when it
     * does not, the previous one does, unless the status says "broken".
     *
     * @throws RuntimeException when the update cannot be started safely
     */
    public static function update(string $tag, bool $withBackup = true): bool
    {
        if (self::git('merge-base', '--is-ancestor', 'HEAD', $tag)->failed()) {
            throw new RuntimeException("This installation has commits that {$tag} does not contain, so it cannot simply move forward to it.");
        }

        $from = self::current();
        $backup = $withBackup ? self::backup($tag) : null;
        $record = ['tag' => $tag, 'from' => $from, 'backup' => $backup];

        self::remember(['last' => [...$record, 'status' => 'running', 'at' => now()->toIso8601String()]]);

        // Nothing below may need a class that was not loaded above: the code is replaced underneath this process.
        $result = Process::path(base_path())->forever()->env(['UPDATE_PHP' => PHP_BINARY])->run(['bash', 'bin/update', $tag]);

        File::append(storage_path('logs/update.log'), $result->output().$result->errorOutput());

        $status = match ($result->exitCode()) {
            0 => 'ok',
            1 => 'failed',
            default => 'broken',
        };

        self::remember(['last' => [...$record, 'status' => $status, 'at' => now()->toIso8601String()]]);

        return $status === 'ok';
    }

    /**
     * Copy the database to storage/app/update-backups and return the
     * path. Only the newest few copies are kept.
     *
     * @throws RuntimeException when no copy could be made
     */
    public static function backup(string $tag): string
    {
        $directory = storage_path('app/update-backups');

        File::ensureDirectoryExists($directory, 0700);

        $path = self::dump($directory.'/'.now()->format('Y-m-d-His').'-before-'.preg_replace('/[^A-Za-z0-9.]/', '', $tag));

        $backups = File::files($directory);
        usort($backups, fn ($a, $b): int => $b->getMTime() <=> $a->getMTime());
        File::delete(array_map(fn ($file): string => $file->getPathname(), array_slice($backups, self::BACKUPS_KEPT)));

        return $path;
    }

    /**
     * Write the whole database to one file and return its path: $path
     * with ".sql" for MySQL and MariaDB, with ".sqlite" for SQLite.
     *
     * @throws RuntimeException when no copy could be made
     */
    public static function dump(string $path): string
    {
        $connection = config('database.connections.'.config('database.default'));

        if ($connection['driver'] === 'sqlite') {
            $path .= '.sqlite';

            if ($connection['database'] === ':memory:' || ! File::copy($connection['database'], $path)) {
                throw new RuntimeException('The database file could not be copied.');
            }
        } elseif (in_array($connection['driver'], ['mysql', 'mariadb'], true)) {
            $path .= '.sql';
            $finder = new ExecutableFinder;
            $program = $finder->find('mysqldump') ?? $finder->find('mariadb-dump');

            if ($program === null) {
                throw new RuntimeException('The database cannot be backed up: neither mysqldump nor mariadb-dump was found on this server.');
            }

            $dump = Process::timeout(3600)->env(['MYSQL_PWD' => (string) $connection['password']])->run([
                $program, '--single-transaction', '--no-tablespaces', '--result-file='.$path,
                '--host='.$connection['host'], '--port='.$connection['port'], '--user='.$connection['username'], $connection['database'],
            ]);

            if ($dump->failed()) {
                File::delete($path);

                throw new RuntimeException('The database could not be backed up: '.mb_substr(trim($dump->errorOutput()), 0, 300));
            }
        } else {
            throw new RuntimeException("A {$connection['driver']} database cannot be backed up from here. Back it up yourself; for an update, then run “php artisan royalties:update --without-backup”.");
        }

        @chmod($path, 0600);

        return $path;
    }

    private static function git(string ...$arguments): ProcessResult
    {
        return Process::path(base_path())->timeout(120)->run(['git', ...$arguments]);
    }
}
