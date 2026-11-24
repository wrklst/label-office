# Command line

Every command the application adds, with its options and an example. Run them in the application's folder on the server. Where several PHP versions are installed, call the one the application uses, for example `php8.4 artisan …`.

`--label` always takes the label's slug (as in the address of its pages) or its numeric id.

## Accounts

### royalties:create-user

Creates an account for someone who will set up a label. Nobody can register on their own.

```sh
php artisan royalties:create-user you@example.com --name="Your Name"
```

The person signs in with a code sent by email. Without `--name` the part of the address before the @ is used. Further members of an existing label are invited in the interface instead, under **Settings → Team**.

| Part      | Meaning                                                                       |
| --------- | ----------------------------------------------------------------------------- |
| `email`   | The address the person signs in with. Refused when invalid or already in use. |
| `--name=` | Their name.                                                                   |

The account may create a label. When the installation has no installation admin yet, it becomes one, see [Team, roles and sign-in](40-team-roles-and-sign-in.md#roles-and-security).

### royalties:hand-over-installation

Hands the role of installation admin, which decides file storage, updates and an AI address on the local network, to another account. Only one account has the role: the previous one loses it.

```sh
php artisan royalties:hand-over-installation new-owner@example.com
```

The address has to belong to an existing account, otherwise the command says "has no account". The new installation admin sees **Settings → Updates** and the **File storage** card while they are an admin of the label they work in. The account of the installation admin cannot be deleted before the role has been handed over.

## Importing

### royalties:import

Imports a statement file, the same way the upload form does.

```sh
php artisan royalties:import statement.csv --label=example-records --paid=2026-11-15
```

| Option        | Meaning                                                                                                                           |
| ------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| `--label=`    | The label to import into                                                                                                          |
| `--importer=` | Source or importer key: `zebralution`, `bandcamp`, `rightz-audio`, `audio-rights`, `template`. Detected from the file by default. |
| `--paid=`     | Paid date (`Y-m-d`) for sources without one; those sources refuse the file without it                                             |
| `--rate=`     | Conversion rate for lines in another currency                                                                                     |

The file is stored with the other uploaded files and appears under **Importing → Data imports**. When possible duplicates are found, nothing is counted and the import waits for your decision there; the command then ends with a warning and an error status.

### royalties:sync-bandcamp

Fetches new Bandcamp sales through the Bandcamp API and imports them.

```sh
php artisan royalties:sync-bandcamp --label=example-records
```

| Option     | Meaning                                                                                                 |
| ---------- | ------------------------------------------------------------------------------------------------------- |
| `--label=` | Only this label. Without it every label whose Bandcamp connection has its weekly sync ticked is synced. |

With `--label` the label is synced even when its weekly sync is not ticked, as long as it has a Bandcamp connection. A label that switched Bandcamp off under **Settings → Connections** is skipped in both cases. Scheduled for Mondays at 05:00.

## Catalog and files

### royalties:generate-previews

Makes the MP3 playback copies of audio masters that have none yet.

```sh
php artisan royalties:generate-previews
php artisan royalties:generate-previews --label=example-records --release=12 --force
```

| Option       | Meaning                                                                       |
| ------------ | ----------------------------------------------------------------------------- |
| `--label=`   | Only this label                                                               |
| `--release=` | Only this release, by its id                                                  |
| `--force`    | Convert again what already has a copy, for example after changing the bitrate |

### royalties:move-files

Copies release files and statement files to another disk and points their records at it.

```sh
php artisan royalties:move-files --to=s3 --dry-run
php artisan royalties:move-files --to=s3
```

| Option      | Meaning                                                              |
| ----------- | -------------------------------------------------------------------- |
| `--to=`     | The disk to copy to, as named in `config/filesystems.php`, e.g. `s3` |
| `--dry-run` | Only report what would be copied                                     |

Every copy is checked by size. Originals are never deleted. Running it again continues where it stopped.

### royalties:backup

Backs up the database and the files to the bucket, then deletes the backups that are no longer kept. The scheduler runs it at the hour set under **Settings → Backups**. See [Deployment](04-deployment.md#the-nightly-backup) for what a backup holds and how many are kept.

```sh
php artisan royalties:backup
php artisan royalties:backup --list
```

| Option        | Meaning                                                                                            |
| ------------- | -------------------------------------------------------------------------------------------------- |
| `--list`      | Only list the backups there are, with their number of files                                        |
| `--scheduled` | Run only when a backup is due at the hour set under **Settings → Backups**, or was asked for there |

Without a bucket it stops with a message. Two runs at the same time are refused.

### royalties:restore-backup

Puts a backup back into this installation: its files where they were, and its database in place of the one there is. **Restore** under **Settings → Backups** does the same. See [Deployment](04-deployment.md#the-nightly-backup) for what stays and for restoring on a new server.

```sh
php artisan royalties:restore-backup
php artisan royalties:restore-backup 2026-11-24-020000-daily --force
```

| Argument or option | Meaning                                                                  |
| ------------------ | ------------------------------------------------------------------------ |
| `backup`           | The backup as `royalties:backup --list` names it; the newest if left out |
| `--force`          | Do not ask before the database is replaced                               |

A backup made with another `APP_KEY` is refused, and the database stays as it was.

### royalties:assemble-backup

Puts a backup together in an empty folder: the database dump, and under `files/` every file as it was when the backup was made.

```sh
php artisan royalties:assemble-backup --to=/tmp/restore
php artisan royalties:assemble-backup 2026-11-24-020000-daily --to=/tmp/restore
```

| Argument or option | Meaning                                                                  |
| ------------------ | ------------------------------------------------------------------------ |
| `backup`           | The backup as `royalties:backup --list` names it; the newest if left out |
| `--to=`            | The folder to write to. It must be empty or not exist yet.               |

Nothing of the running installation is changed.

### royalties:research-airplay

Queues a web research for tracks that have not been researched lately.

```sh
php artisan royalties:research-airplay --label=example-records --days=31 --limit=10
```

| Option     | Default    | Meaning                                                                           |
| ---------- | ---------- | --------------------------------------------------------------------------------- |
| `--label=` | all labels | Only this label                                                                   |
| `--days=`  | `31`       | Research tracks whose last research is older than this                            |
| `--limit=` | `10`       | The most tracks to queue per label; every track costs up to three billed searches |

Tracks never researched come first. Scheduled daily at 06:30. Labels without a web search login, their own or the server's, are skipped with a warning.

## Accounting

### royalties:book-recurring-expenses

Books the recurring label costs that have come due. Scheduled daily at 04:00; running it by hand is harmless, a cost is never booked twice for the same due date.

```sh
php artisan royalties:book-recurring-expenses
```

## Migrating from Infinite Catalog

### royalties:import-infinite-catalog

Loads a complete account export (catalog, contracts, periods, payouts and transactions) into a label.

```sh
php artisan royalties:import-infinite-catalog /path/to/export --label=example-records
php artisan royalties:import-infinite-catalog /path/to/export --label=example-records --rate=GBP=1.16 --timezone=Europe/London
```

| Option        | Default         | Meaning                                                                                                           |
| ------------- | --------------- | ----------------------------------------------------------------------------------------------------------------- |
| `directory`   |                 | The folder with the exported files, including `transactions.ndjson`                                               |
| `--label=`    |                 | The label to import into                                                                                          |
| `--timezone=` | `Europe/Berlin` | The time zone the dates were entered in                                                                           |
| `--rate=`     |                 | A rate for a currency Infinite Catalog converted itself, written `GBP=1.16`; repeat it for several currencies     |
| `--fresh`     |                 | Delete everything the label holds before importing. Without it the command refuses a label that already has data. |

> **Warning:** `--fresh` removes the catalog, contracts, transactions and the rest of the label first. Use it only on a label you can lose, or after a backup.

The import runs in one database transaction, and ends with a table of what was imported. It then credits remixers named in track titles, as [royalties:credit-remixers](#royaltiescredit-remixers) does.

### royalties:compare-infinite-catalog

Checks the calculated period figures against the ones Infinite Catalog reported.

```sh
php artisan royalties:compare-infinite-catalog /path/to/export --label=example-records --tolerance=0.01 --show=100
```

| Option         | Default    | Meaning                                                |
| -------------- | ---------- | ------------------------------------------------------ |
| `directory`    |            | The folder with the export, including `summaries.json` |
| `--label=`     |            | The label the export was imported into                 |
| `--tolerance=` | `0.000001` | The largest difference still counted as equal          |
| `--show=`      | `40`       | How many differences to list                           |

The export has to be produced from your own account; no export tool is included. This project is not affiliated with Infinite Catalog.

## For developers

### make:importer

Creates an importer class for a new statement source, or a new layout of an existing one. See [Extending](51-extending.md).

```sh
php artisan make:importer ExampleDistributor
php artisan make:importer Zebralution2027 --source=zebralution --layout="2027 onwards"
```

With `--source` the new class is another layout of an existing source, named by `--layout`.

## Updating

### royalties:update

Updates the installation to the newest tagged version of its repository, as described under [Installation](02-installation.md#updating).

```sh
php artisan royalties:update --check
php artisan royalties:update
```

| Option             | Meaning                                                                                                  |
| ------------------ | -------------------------------------------------------------------------------------------------------- |
| `--check`          | Only say whether a newer version exists.                                                                 |
| `--without-backup` | Do not copy the database first. Use it only after backing it up yourself.                                |
| `--scheduled`      | For the scheduler: run only when an update was asked for in the interface or an automatic update is due. |

The command ends with an error when the update was refused or failed, and says whether the previous version runs again.

### royalties:reset-code-cache

Has the web server forget the PHP code it has compiled (OPcache), so that it runs the code on disk. The updater runs it; run it yourself after changing code by hand on a server set up with `opcache.validate_timestamps=0`.

```sh
php artisan royalties:reset-code-cache
```

### royalties:credit-remixers

Credits as **Remixer** every artist of your catalog that a track title names, such as `Second Track - Example Artist Remix` or `Second Track (Example Artist Remix)`. A statement or an account export names one artist per track, so a remixer that came in that way is in the artist list with no tracks. The name in the title has to be the name of an artist exactly, apart from upper and lower case; other titles are left alone. Running it again adds nothing twice. The Infinite Catalog migration runs it by itself.

```sh
php artisan royalties:credit-remixers
```

## What runs by itself

`php artisan schedule:list` shows these and when each is next due. They need the scheduler, see [Configuration](03-configuration.md).

| Command                             | When                                                                |
| ----------------------------------- | ------------------------------------------------------------------- |
| `royalties:update --scheduled`      | Every minute; does nothing unless an update was asked for or is due |
| `royalties:backup --scheduled`      | Every minute; does nothing unless a backup is due or was asked for  |
| `royalties:book-recurring-expenses` | Daily at 04:00                                                      |
| `royalties:research-airplay`        | Daily at 06:30                                                      |
| `royalties:sync-bandcamp`           | Mondays at 05:00                                                    |
| `horizon:snapshot`                  | Every five minutes                                                  |

A daily job at 03:30 also removes files that were uploaded straight to a bucket and never reported.

## Useful framework commands

| Command                         | What it tells you                                                 |
| ------------------------------- | ----------------------------------------------------------------- |
| `php artisan about`             | Environment, drivers and versions at a glance                     |
| `php artisan migrate:status`    | Which database migrations have run                                |
| `php artisan schedule:list`     | The scheduled jobs and when each is next due                      |
| `php artisan horizon:status`    | Whether the queue worker runs                                     |
| `php artisan horizon:terminate` | Stops the worker so the process manager restarts it with new code |
| `php artisan queue:failed`      | Background jobs that failed                                       |
| `php artisan optimize`          | Caches configuration and routes; run after changing `.env`        |

## Good to know

- Commands that work with files use the configured file storage, so they behave the same with a local disk and a bucket.
- Each command accepts `--help` and prints its options.
