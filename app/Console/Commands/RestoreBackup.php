<?php

namespace App\Console\Commands;

use App\Support\Backups;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

#[Signature('royalties:restore-backup
    {backup? : The backup, as royalties:backup --list names it; the newest when left out}
    {--force : Do not ask before the database is replaced}')]
#[Description('Put a backup back into this installation: its files, and its database in place of the one there is')]
class RestoreBackup extends Command
{
    public function handle(): int
    {
        if (! Backups::available()) {
            $this->error('No bucket is connected, so there are no backups.');

            return self::FAILURE;
        }

        $names = Backups::names();
        $name = $this->argument('backup') ?? end($names);

        if (! in_array($name, $names, true)) {
            $this->error($names === [] ? 'There is no backup yet.' : "There is no backup [{$name}]. See royalties:backup --list.");

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Replace the whole database of this installation with the one from {$name}, and put its files back?")) {
            return self::FAILURE;
        }

        // A backup made meanwhile would be of half of each.
        $lock = Cache::lock('royalties-backup', 12 * 3600);

        if (! $lock->get()) {
            $this->error('A backup is running.');

            return self::FAILURE;
        }

        Backups::remember(['last' => ['status' => 'restoring', 'at' => now()->toIso8601String(), 'name' => $name]]);

        try {
            $files = Backups::restore($name);
        } catch (Throwable $exception) {
            Backups::remember(['last' => ['status' => 'failed', 'at' => now()->toIso8601String(), 'name' => $name, 'message' => mb_substr($exception->getMessage(), 0, 500)]]);
            report($exception);
            $this->error('The backup could not be put back: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
            Cache::forget(Backups::LISTED);
        }

        Backups::remember(['last' => ['status' => 'restored', 'at' => now()->toIso8601String(), 'name' => $name, 'files' => $files]]);

        $this->info("This installation is as it was in {$name}: the database and ".trans_choice(':count file|:count files', $files).'.');

        return self::SUCCESS;
    }
}
