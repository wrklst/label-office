# Deployment

How to run Label Office on a production server. The steps are shown for a plain Ubuntu server and for Laravel Forge; any host that runs a Laravel application works the same way.

## What a production server needs

1. A web server (nginx or Caddy) with PHP-FPM, pointing at the `public` folder.
2. A database and Redis.
3. HTTPS. Sign-in with passkeys does not work without it.
4. A process manager that keeps **Horizon** running.
5. A **cron** entry for the scheduler.
6. Working **mail**.
7. **ffmpeg**, if audio should be playable.
8. **Backups** of the database, the files and the `.env` file.
9. **Outbound HTTPS** to `api.frankfurter.dev` (exchange rates) and to the services the labels connect.

`APP_URL` in `.env` must carry the exact address the site is opened at: the application answers to that host only.

The settings behind each of these are explained in [Configuration](03-configuration.md).

## First deployment

```sh
git clone https://github.com/wrklst/label-office.git /var/www/label-office
cd /var/www/label-office
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
# now edit .env: APP_ENV=production, APP_DEBUG=false, APP_URL, database, mail, Redis, storage
npm ci && npm run build
php artisan migrate --force
php artisan storage:link
php artisan optimize
php artisan royalties:create-user you@example.com --name="Your Name"
```

The web server user needs write access to `storage` and `bootstrap/cache`.

### nginx

```nginx
server {
    listen 443 ssl http2;
    server_name royalties.example.com;
    root /var/www/label-office/public;
    index index.php;

    client_max_body_size 1024m;   # audio masters

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 900;  # delivery packages take minutes
    }
}
```

### Horizon under Supervisor

`/etc/supervisor/conf.d/label-office-horizon.conf`:

```ini
[program:label-office-horizon]
process_name=%(program_name)s
command=php /var/www/label-office/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/label-office/storage/logs/horizon.log
stopwaitsecs=3600
```

```sh
sudo supervisorctl reread && sudo supervisorctl update
```

### Cron

```
* * * * * cd /var/www/label-office && php artisan schedule:run >> /dev/null 2>&1
```

## Laravel Forge

1. Create the site with the repository connected and the web directory `/public`.
2. Set the site's PHP version to one that has all extensions from [Installation](02-installation.md).
3. Fill in the environment in the site's **Environment** tab.
4. Under **Processes** add a daemon with the command `php artisan horizon`, running in the site directory.
5. Under **Scheduler** add the job `php artisan schedule:run`, every minute.
6. Use this deploy script:

```sh
$CREATE_RELEASE()

cd $FORGE_RELEASE_DIRECTORY

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

npm ci || npm install
npm run build
$FORGE_PHP artisan optimize
$FORGE_PHP artisan storage:link
$FORGE_PHP artisan migrate --force

$ACTIVATE_RELEASE()

$RESTART_QUEUES()
```

If Horizon still runs old code after a deployment, add `$FORGE_PHP artisan horizon:terminate` as the last line.

With this deploy script Forge puts each version in place itself, so the updater under **Settings → Updates** is switched off there: update by deploying.

7. Create the first account over SSH:

```sh
cd ~/royalties.example.com/current
php artisan royalties:create-user you@example.com --name="Your Name"
```

> **Note:** A Forge server often has several PHP versions. If `php artisan` fails with "could not find driver" or "Class DOMDocument not found", the command line uses a version without the needed extensions. Call the right one explicitly, for example `php8.4 artisan …`.

## Moving an existing installation to a new server

1. **Files.** If they are on the old server's disk, either copy `storage/app/private` to the same place on the new server, or move them into a bucket first with `php artisan royalties:move-files --to=s3` and give the new server the same bucket settings.
2. **Database.** Install the application on the new server and run the migrations, so the tables exist. Then copy the data only, leaving out sessions, cache and queue tables:

```sh
mysqldump --no-create-info --complete-insert --single-transaction \
  --ignore-table=old_db.migrations --ignore-table=old_db.sessions \
  --ignore-table=old_db.cache --ignore-table=old_db.cache_locks \
  --ignore-table=old_db.jobs --ignore-table=old_db.job_batches \
  --ignore-table=old_db.failed_jobs --ignore-table=old_db.login_codes \
  --ignore-table=old_db.password_reset_tokens \
  old_db > data.sql

mysql new_db < data.sql
```

3. **Application key.** Copy `APP_KEY` from the old `.env`. Without it the stored Bandcamp, DataForSEO and AI service credentials and a file storage set in the interface cannot be decrypted and have to be entered again.
4. **Passkeys** are bound to the address they were registered on. On a new domain they have to be registered again; sign in with a code or password first.
5. Compare a few figures (number of transactions, a period total) between old and new before switching over.

## Backups

Back up three things, and test restoring them:

| What     | How                                                                                            |
| -------- | ---------------------------------------------------------------------------------------------- |
| Database | The nightly backup below, or a daily dump (`mysqldump`) kept off the server                    |
| Files    | The nightly backup below, or copy `storage/app/private` and the bucket somewhere else yourself |
| `.env`   | Yourself, once and after every change. It holds `APP_KEY`, and it is never part of a backup.   |

Audio masters are the one thing that cannot be recreated from anywhere else. If the files are on the server's own disk, a disk failure without a backup loses them.

### The nightly backup

With an S3-compatible bucket connected, the application backs itself up every night. Everything about it is set by the installation's admin under **Settings → Backups**; nothing of it is in the environment file. Without a bucket there is no backup, and the page says so.

A backup is a folder under `backups/` in the bucket, for example `backups/2026-11-24-020000-daily`. It holds:

- the database as a packed dump (`database.sql.gz`; for SQLite a copy of the file, `database.sqlite.gz`),
- the files that are new or changed since the backup before it, from `storage/app/private` and from the bucket for files. The first backup holds all of them.
- `manifest.json`, the list of every file there was that night and which backup holds it.

**Settings → Backups** has four parts:

| Part                          | What it does                                                                                                                    |
| ----------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| **Last backup**               | What the last run did, or why it failed. **Back up now** has the scheduler make one within a minute.                            |
| **Schedule and what is kept** | **Make a backup every night**, the hour **At** (server time, 02:00 unless changed), and how many backups of each tier are kept. |
| **Where backups go**          | The bucket for files, or **Connect a bucket for backups**. The bucket is tested before it is used.                              |
| **Backups in the bucket**     | Every backup with its number of files and how many of them were new or changed.                                                 |

**Where it goes.** Into the bucket for files (the one from **Settings → Connections** or the `AWS_…` settings), unless the backups have their own. Their own bucket is not required, but it is the better choice: a backup beside the files is lost with them when the bucket or its access key is. Backups made before a change of bucket stay in the old one and are no longer listed or cleaned up.

**What is kept.** Each backup has a tier: the first of a year is yearly, the first of a month monthly, the first of a week (from Monday) weekly, every other one daily. After each backup the older ones of each tier are deleted:

| Tier    | Kept unless changed |
| ------- | ------------------- |
| Daily   | 6                   |
| Weekly  | 3                   |
| Monthly | 11                  |
| Yearly  | 7                   |

Deleting a backup removes its dump and its list. A file it holds stays for as long as a kept backup still needs it, so every kept backup remains complete.

**Getting a backup back.** `royalties:assemble-backup` puts one together in an empty folder on the server: the dump, and under `files/` every file as it was that night.

```sh
php artisan royalties:backup --list
php artisan royalties:assemble-backup 2026-11-24-020000-daily --to=/tmp/restore
```

It only writes into that folder. To go back to that state, load `database.sql` into the database (`mysql label_royalties < database.sql`), copy `files/local/` into `storage/app/private/` and `files/s3/` into the bucket for files. The folder needs room for all files of the installation.

Good to know:

- The settings, with the keys of the backups' own bucket, are stored encrypted in the database. On a new server they are read from the restored database with the old `APP_KEY`; without that key, connect the bucket again.
- A file counts as changed when its size or its time of last change differs. Stored files are never edited in place, so this is exact.
- A backup needs `mysqldump` or `mariadb-dump` on the server for MySQL and MariaDB; other databases than these and SQLite are not backed up.
- A run that fails leaves no backup behind and is not tried again that night; what it uploaded is removed by the next run that succeeds. The reason is on the settings page and in the log.
- Outside production the folder is `<APP_ENV>/backups`, as for files.

## When an update leaves the site in maintenance mode

The updater from [Installation](02-installation.md#updating) goes back to the previous version when a step fails. If going back fails as well, for example because the server has no disk space left, it leaves the site in maintenance mode instead of opening it half installed. **Settings → Updates** cannot be reached then. On the server:

1. Read the end of `storage/logs/update.log` to see which step failed.
2. Fix the cause.
3. Finish by hand:

```sh
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan optimize
php artisan horizon:terminate
php artisan up
```

The database copy made before the update is in `storage/app/update-backups`.

## After every deployment

- `php artisan migrate --force` has run.
- `php artisan optimize` has run, so configuration changes are in effect.
- Horizon was restarted, so background jobs use the new code.
- The interface was built (`npm run build`).

## Good to know

- Building a delivery package, a Zebralution ZIP, a Subvert ZIP or a Bandcamp ZIP writes to the system's temporary directory first. It needs as much free space as the release is large, and the web server has to allow the request to run for several minutes.
- Server-side rendering is switched off. The application sits behind a login, so it gains nothing from it, and no Node process has to run in production.
- Plan a switch-over for a time when you are at your desk. Nothing in it needs a night shift: importing a database dump of a small label takes seconds.
