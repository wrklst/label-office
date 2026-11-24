# Configuration

Everything the server needs to know is in the `.env` file. This page lists each setting, what it is for and an example. Settings a label can change itself are not here: they are in the interface, see [Label settings](41-label-settings.md) and [Connections](42-connections.md).

After changing `.env` on a production server run `php artisan optimize` and restart the queue with `php artisan horizon:terminate`, otherwise the old values stay in use.

## Application

```ini
APP_NAME="Label Office"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://royalties.example.com
```

- `APP_URL` must be the address people open, including `https://`. Links in emails are built from it, and its host is the only host the application answers to: a request for another name is refused. Behind HTTPS the application also sends the usual security headers.
- `APP_DEBUG` must be `false` on a server anyone else can reach. With `true`, error pages show internals.
- `APP_ENV` also decides where files go in a shared bucket, see "File storage" below.

## Database

```ini
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=label_office
DB_USERNAME=label_office
DB_PASSWORD=your-password
```

For SQLite set `DB_CONNECTION=sqlite` and create the file `database/database.sqlite`.

## Mail

Sign-in codes, invitations and password resets are sent by email. Without working mail nobody can sign in with a code.

```ini
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=your-username
MAIL_PASSWORD=your-password
MAIL_FROM_ADDRESS=royalties@example.com
MAIL_FROM_NAME="Example Records"
```

`MAIL_MAILER=log` writes emails to `storage/logs/laravel.log` instead; useful locally.

## Queue

Emails, audio conversions and the airplay research run in the background. They are queued on Redis and worked off by Laravel Horizon.

```ini
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
```

Keep `php artisan horizon` running at all times. Under `composer dev` it is started for you; on a server a process manager has to keep it alive, see [Deployment](04-deployment.md). When it does not run, nothing is sent, converted or researched: jobs wait until it does.

The Horizon dashboard is at `/horizon`. Locally it is open; elsewhere only to signed-in users with a verified address who are listed here:

```ini
HORIZON_ALLOWED_EMAILS=you@example.com,colleague@example.com
```

### Without Redis

Set `QUEUE_CONNECTION=database` and keep `php artisan queue:work` running instead of Horizon. For a single-user installation `QUEUE_CONNECTION=sync` runs every job right away inside the web request; then set `AUDIO_PREVIEW_QUEUE=false` as well.

## Scheduler

One cron entry runs everything that happens on its own:

```
* * * * * cd /path/to/label-office && php artisan schedule:run >> /dev/null 2>&1
```

| When            | What                                                                                                                                                                     |
| --------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Every minute    | Checks whether an update was asked for under **Settings → Updates**, or automatic updates are due, and does nothing otherwise                                            |
| Every 5 minutes | Records queue metrics for the Horizon dashboard                                                                                                                          |
| Every minute    | Checks whether the nightly backup is due at the hour set under **Settings → Backups**, or one was asked for there; see [Deployment](04-deployment.md#the-nightly-backup) |
| Daily, 03:30    | Cleans up direct uploads of files that were never reported as finished                                                                                                   |
| Daily, 04:00    | Books recurring label costs that have come due                                                                                                                           |
| Monday, 05:00   | Fetches new Bandcamp sales for labels that work with Bandcamp and have **Sync automatically every week** ticked                                                          |
| Daily, 06:30    | Queues the airplay research for the ten tracks researched longest ago                                                                                                    |

Times are in the server's time zone.

## File storage

The file storage can also be chosen in the interface, by the installation's admin under **Settings → Connections**; see [Connections](42-connections.md). What is set there takes precedence over the values below.

Release files (covers, masters, documents) and uploaded statement files are stored either on the server or in a bucket.

**On the server** (the default):

```ini
ROYALTIES_FILES_DISK=local
```

Files are in `storage/app/private`. Include that folder in your backups; the [nightly backup](04-deployment.md#the-nightly-backup) does, once a bucket is connected.

**In an S3-compatible bucket** (Amazon S3, Hetzner Object Storage, Backblaze B2, MinIO …):

```ini
ROYALTIES_FILES_DISK=s3
AWS_ACCESS_KEY_ID=your-key
AWS_SECRET_ACCESS_KEY=your-secret
AWS_DEFAULT_REGION=eu-central
AWS_BUCKET=example-records
AWS_ENDPOINT=https://fsn1.your-objectstorage.com
AWS_USE_PATH_STYLE_ENDPOINT=false
```

- The bucket must be private. Files are handed to signed-in admins through links that expire after ten minutes.
- `AWS_ENDPOINT` is needed for every provider other than Amazon.
- Production works at the top of the bucket. Any other environment (`APP_ENV=local`, `staging` …) works in a folder named after it, so a development copy pointed at the same bucket cannot touch live files.

To move existing files from the server into the bucket, or back:

```sh
php artisan royalties:move-files --to=s3 --dry-run   # report only
php artisan royalties:move-files --to=s3
```

The command copies, checks the size of every copy and points the records at the new place. It never deletes the originals; remove them yourself once you have checked the result.

## Exchange rates

A cost or transaction in another currency without a rate you typed is converted at the European Central Bank's reference rate of its day, fetched from `api.frankfurter.dev` over HTTPS. The server therefore needs outbound HTTPS to that address. Only the two currencies and the day are sent. Nothing needs to be configured. If the address cannot be reached, the amount shows **no rate** and stays uncounted until a rate is entered or the address can be reached again; see [Profitability and overheads](33-profitability-and-label-costs.md) and [Troubleshooting](52-troubleshooting.md).

## Uploads

```ini
RELEASE_UPLOAD_MAX_KB=1048576
```

The largest single release file in kilobytes (default 1 GB). PHP's own limits must allow as much, in `php.ini`:

```ini
upload_max_filesize = 1024M
post_max_size = 1024M
```

and so must the web server (nginx: `client_max_body_size 1024m;`). Statement files are limited to 50 MB.

## Audio playback copies

Masters are kept exactly as uploaded. For listening in the browser an MP3 copy is made of each with ffmpeg.

```ini
FFMPEG_BINARY=/usr/bin/ffmpeg
AUDIO_PREVIEW_BITRATE=320
AUDIO_PREVIEW_SAMPLE_RATE=44100
AUDIO_PREVIEW_TIMEOUT=600
AUDIO_PREVIEW_QUEUE=true
```

- `FFMPEG_BINARY` is only needed when ffmpeg is not on the `PATH` of the user PHP runs as.
- `AUDIO_PREVIEW_TIMEOUT` is how many seconds one conversion may take.
- `AUDIO_PREVIEW_QUEUE=false` converts in the web process right after an upload, for installations without a queue worker.

To make the copies that are missing, or to make all of them again:

```sh
php artisan royalties:generate-previews
php artisan royalties:generate-previews --release=12 --force
```

## Web search for the airplay research

The login for DataForSEO is entered per label in the interface, under **Settings → Connections**. An installation that wants one login for all its labels can set it here instead; a label's own login takes precedence.

```ini
DATAFORSEO_LOGIN=you@example.com
DATAFORSEO_PASSWORD=your-api-password
```

## AI service for reading receipts

Nothing to configure here. Each label chooses its service and enters its key under **Settings → Connections**; see [Connections](42-connections.md). A service address on this server or its local network can only be entered by the installation admin. Reading PDF receipts needs `pdftotext` and `pdftoppm` on the server (package `poppler-utils`).

## Bandcamp

Nothing to configure here. Each label connects its own Bandcamp account from the **Bandcamp** card under **Settings → Connections**; see [Bandcamp](11-bandcamp.md).

## All settings at a glance

| Setting                                   | Default                 | Purpose                                                                                           |
| ----------------------------------------- | ----------------------- | ------------------------------------------------------------------------------------------------- |
| `APP_URL`                                 | `http://localhost:8000` | Public address                                                                                    |
| `DB_*`                                    | SQLite                  | Database connection                                                                               |
| `MAIL_*`                                  | `log`                   | Outgoing mail                                                                                     |
| `QUEUE_CONNECTION`                        | `redis`                 | Where background jobs wait                                                                        |
| `REDIS_*`                                 | local Redis             | Redis connection                                                                                  |
| `HORIZON_ALLOWED_EMAILS`                  | empty                   | Who may open `/horizon`                                                                           |
| `ROYALTIES_FILES_DISK`                    | `local`                 | `local` or `s3`                                                                                   |
| `AWS_*`                                   | empty                   | Bucket connection                                                                                 |
| `RELEASE_UPLOAD_MAX_KB`                   | `1048576`               | Largest release file                                                                              |
| `FFMPEG_BINARY`                           | `ffmpeg`                | Path to ffmpeg                                                                                    |
| `AUDIO_PREVIEW_BITRATE`                   | `320`                   | MP3 bitrate in kbit/s                                                                             |
| `AUDIO_PREVIEW_SAMPLE_RATE`               | `44100`                 | MP3 sample rate in Hz                                                                             |
| `AUDIO_PREVIEW_TIMEOUT`                   | `600`                   | Seconds per conversion                                                                            |
| `AUDIO_PREVIEW_QUEUE`                     | `true`                  | Convert in the background                                                                         |
| `DATAFORSEO_LOGIN`, `DATAFORSEO_PASSWORD` | empty                   | Installation-wide search login                                                                    |
| `HORIZON_MAX_PROCESSES`                   | `4`                     | Most queue workers Horizon runs                                                                   |
| `APP_PREVIOUS_KEYS`                       | empty                   | Old application keys, comma-separated, after a key change                                         |
| `SESSION_COOKIE`, `CACHE_PREFIX`          | from the app name       | Names of the cookie and cache keys; set them when several installations share a domain or a cache |

## Good to know

- Never commit `.env` and never paste it into a support request: it holds every password of the installation.
- Categories, formats and the US statutory mechanical rates are in `config/royalties.php`. Changing them means editing that file and deploying; they are the same for every label of the installation.
