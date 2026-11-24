<?php

namespace App\Console\Commands;

use App\Support\Backups;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

#[Signature('royalties:backup
    {--scheduled : Run only when a backup is due at the hour set under Settings → Backups, or was asked for there}
    {--list : Only list the backups there are}')]
#[Description('Back up the database and the files to the bucket, then delete the backups that are no longer kept')]
class Backup extends Command
{
    public function handle(): int
    {
        $settings = Backups::settings();

        if ($this->option('scheduled') && $settings['restore'] !== null) {
            Backups::remember(['restore' => null]);

            return $this->call('royalties:restore-backup', ['backup' => $settings['restore'], '--force' => true]);
        }

        if ($this->option('scheduled')) {
            $due = $settings['enabled'] && now()->hour === $settings['hour'] && $settings['ran_on'] !== now()->toDateString();

            if (! $settings['requested'] && ! $due) {
                return self::SUCCESS;
            }
        }

        if (! Backups::available()) {
            if ($settings['requested']) {
                Backups::remember(['requested' => false]);
            }

            if ($this->option('scheduled')) {
                return self::SUCCESS;
            }

            $this->error('Backups need an S3-compatible bucket: connect one for the files under Settings → Connections, or one for the backups under Settings → Backups.');

            return self::FAILURE;
        }

        if ($this->option('list')) {
            $this->table(['Backup', 'Files', 'New or changed'], array_map(function (string $name): array {
                $manifest = Backups::manifest($name);

                return [$name, count($manifest['files']), $manifest['copied']];
            }, Backups::names()));

            return self::SUCCESS;
        }

        // Two runs at once would clean up each other's unfinished backup.
        $lock = Cache::lock('royalties-backup', 12 * 3600);

        if (! $lock->get()) {
            $this->error('A backup is running already.');

            return self::FAILURE;
        }

        // Noted before the work: a night's backup that fails is not tried again every minute of its hour.
        Backups::remember(['requested' => false, 'ran_on' => now()->toDateString(), 'last' => ['status' => 'running', 'at' => now()->toIso8601String()]]);

        try {
            $manifest = Backups::make();
            $deleted = Backups::cleanUp();
        } catch (Throwable $exception) {
            Backups::remember(['last' => ['status' => 'failed', 'at' => now()->toIso8601String(), 'message' => mb_substr($exception->getMessage(), 0, 500)]]);
            report($exception);
            $this->error('The backup failed: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
            Cache::forget(Backups::LISTED);
        }

        Backups::remember(['last' => ['status' => 'ok', 'at' => now()->toIso8601String(), 'name' => $manifest['name'], 'files' => count($manifest['files']), 'copied' => $manifest['copied']]]);

        $this->info("Backup {$manifest['name']}: the database and ".trans_choice(':count file|:count files', count($manifest['files'])).", {$manifest['copied']} of them new or changed.");

        if ($deleted !== []) {
            $this->line('No longer kept and deleted: '.implode(', ', $deleted).'.');
        }

        return self::SUCCESS;
    }
}
