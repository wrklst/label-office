<?php

namespace App\Console\Commands;

use App\Support\Backups;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('royalties:assemble-backup
    {backup? : The backup, as royalties:backup --list names it; the newest when left out}
    {--to= : The empty folder to put it in}')]
#[Description('Put a backup together in a folder: the database dump and every file as it was then')]
class AssembleBackup extends Command
{
    /**
     * Only ever writes into the folder given. Putting the dump and the
     * files back into a running installation is a decision for a person.
     */
    public function handle(): int
    {
        $to = (string) $this->option('to');

        if (! Backups::available() || $to === '') {
            $this->error($to === '' ? 'Pass --to with an empty folder, e.g. --to=/tmp/restore.' : 'No bucket is connected, so there are no backups.');

            return self::FAILURE;
        }

        $names = Backups::names();
        $name = $this->argument('backup') ?? end($names);

        if (! in_array($name, $names, true)) {
            $this->error($names === [] ? 'There is no backup yet.' : "There is no backup [{$name}]. See royalties:backup --list.");

            return self::FAILURE;
        }

        try {
            $files = Backups::assemble($name, $to);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Backup {$name} is in {$to}: the database dump and ".trans_choice(':count file|:count files', $files).' under files/.');

        return self::SUCCESS;
    }
}
