# Label Office

**The open-source back office for independent labels.**
Self-hosted and MIT-licensed. Royalties, releases and payouts for every label
you run, in one install. Website: [labeloffice.org](https://labeloffice.org).

Import the statements your distributor sends, split the income by contract,
give your artists statements they can read, and keep every release together
with its metadata, cover and audio.

Label Office comes from the management of
[Never Not Now](https://nevernotnow.net), an independent label in Berlin,
Germany, and is the software the label runs on: its royalty accounting, its
catalog and its release files are kept in it, in production. It was built
for the day-to-day work of an indie label and is used for exactly that.

Self-hosted, open source (MIT), built with Laravel, Inertia and React.

- [Who is this for?](#who-is-this-for)
- [What it does](#what-it-does)
- [Server requirements](#server-requirements)
- [Installation](#installation)
- [Trying it locally with Laravel Herd](#trying-it-locally-with-laravel-herd)
- [Running it in production](#running-it-in-production)
- [Configuration](#configuration)
- [Documentation](#documentation)
- [Command line](#command-line)
- [Not included](#not-included)
- [Development](#development)
- [Support](#support)
- [Keeping the culture alive](#keeping-the-culture-alive)
- [Security](#security)
- [License](#license)

## Who is this for?

Label Office is for labels that can run their own software, or have
someone who does. You need basic knowledge of how to run a web application
and keep it updated. The application needs continuous security updates, and
so does the server you run it on. An installation that is not kept up to date
can become a serious security risk, and it holds confidential royalty data.

If you don't feel up to that, you can hire professionals to do it for you, or
use one of the proven managed platforms that work well, such as
[Infinite Catalog](https://infinitecatalog.com), which has fair pricing for
small indie labels. If your situation changes later, you can still move to
Label Office: an importer for an Infinite Catalog account export is
included; see [Command line](docs/50-command-line.md#migrating-from-infinite-catalog).

## What it does

**Royalty accounting**

- Several labels in one installation, each with its own catalog, contracts,
  transactions and team. Label admins see everything; payees only their own
  published statements and payouts, for every label they are paid by, under
  My statements.
- Contracts with percentage splits per payee, optional separate ledgers (to
  recoup an advance on its own), territory splits and prorated contracts for
  compilations. A track can have its own contract, e.g. for a remix.
- Income, expenses and transfers (advances, paybacks) at full precision:
  streaming lines worth fractions of a cent are never rounded away.
- Expenses can be recouped from payees or borne by the label alone.
- Costs and transactions in other currencies, converted at a rate you enter or,
  when you leave it empty, at the European Central Bank's reference rate of the day
  (fetched from api.frankfurter.dev; the server needs outbound HTTPS to it).
- Accounting periods with a check for unassigned income, releases without a
  contract and unconverted currencies; statements as frozen snapshots with
  revisions; payouts that cannot exceed what is payable.
- A profitability report: what the label keeps per release after payee
  shares and its own costs.
- Overheads, the label costs that belong to no release, entered once or as
  recurring costs, with receipts uploaded and matched to costs. You enter a
  receipt by hand, or have an AI service of your choice (Infomaniak, OpenAI,
  Anthropic, Mistral, Gemini or any OpenAI-compatible one) read it; what it
  reads is a proposal you check.
- Optional tools for mechanicals, PPD and reserves.
- My statements and My agreements for payees, with passwordless sign-in by
  emailed one-time code or a passkey.
- Each service the label works with (Zebralution, Bandcamp, Subvert, Rightz
  Audio) has a switch; what a label does not work with is hidden.

**Agreements**

- Written agreements with payees for a remix or a release, drafted from your
  own templates before the release comes out: the parties, the share, the
  term and what has to be delivered.
- Signed online by every party: each of the people who may sign for the
  label (all of them together or any one, as the label is set up), the payee,
  and the original artist when they grant the rights. A signature is a typed
  name, a confirmation and a one-time code sent by email, recorded with time,
  address and the SHA-256 hash of the text, which is frozen when the agreement
  is sent.
- The same person cannot sign for the label and as its other party unless the
  label marked them as exempt from the ban on self-dealing.
- Every party is emailed a PDF of the signed agreement with the record of the
  signatures.
- Deliverables per agreement: the payee hands in a file for each item (master,
  artwork, texts) under My agreements, it is filed with the release, and the
  label accepts it or hands it back with a note.
- The royalty contract is made from the signed share with one click.
- Agreements signed on paper or elsewhere are filed with their signed copy.
- Three example templates for German law and a GmbH (remix through the label,
  remix through the artist, release). They are unreviewed examples, see
  [No legal guarantee](#no-legal-guarantee).
- An admin can also be a payee of the label: an artist who helps run it reads
  their own share and signs their own agreements.

**Importing statements**

- Zebralution (current and pre-2024 layout), Bandcamp sales reports,
  Rightz Audio and other neighbouring rights statements, and a generic
  spreadsheet template, as CSV or Excel.
- The source and layout of a file are detected from its columns. Releases,
  tracks and artists are created from the file; lines imported before are
  skipped.
- Every import is compared with what is booked already. Possible duplicates
  are shown with a proposal to merge before anything is counted.
- Play counts files, such as Zebralution's analytics export, are imported on
  their own and shown beside the income on the dashboard and on track and
  artist pages.
- Bandcamp sales can be fetched automatically through the Bandcamp API.
- Buyer names, emails and addresses in Bandcamp reports are never stored.

**Release management**

- Full release and track metadata: versions, credits, ISRC/ISWC, UPC, genre,
  language, ℗/© lines, pre-order and original release dates, platforms not to
  deliver to and territories, declarations on the use of AI in artwork,
  recording, composition and lyrics, press release, key facts and to-dos.
- Files per release (cover, audio masters, artwork, documents), on the server
  or in an S3-compatible bucket, served only to signed-in admins of the label.
- An audio player that keeps playing while you work: play a track, a whole
  release, or build a queue. Masters are kept exactly as uploaded; an MP3
  copy is made for listening.
- The pages of every release and track on platforms, found by UPC, ISRC, Bandcamp sales and web search, or added by hand.
- Export of all metadata as a CSV sheet, a delivery package per release, a
  ZIP for Zebralution, a ZIP for [Subvert](https://subvert.fm)'s uploader and
  one with everything to put a release on Bandcamp.

**Airplay**

- Radio plays from neighbouring rights statements listed per track, with the
  station when the collecting society names it.
- A web research (through [DataForSEO](https://dataforseo.com)) that looks
  for playlists and show pages, reads the pages it finds and proposes them
  for review. Every search is logged.

**A handbook inside the application**

- The **?** button opens the documentation for the screen you are on, in a
  panel beside your work.

## Server requirements

| Component      | Requirement                                                                                                                                                                                                                                                  |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| PHP            | 8.3 or newer                                                                                                                                                                                                                                                 |
| PHP extensions | `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `iconv`, `intl`, `mbstring`, `openssl`, `pcntl`, `pdo` with your database's driver, `posix`, `redis`, `session`, `simplexml`, `sockets`, `tokenizer`, `xml`, `xmlreader`, `xmlwriter`, `zip` |
| Optional       | `gd` (cover thumbnails), `exif` (photo rotation)                                                                                                                                                                                                             |
| Composer       | 2.x                                                                                                                                                                                                                                                          |
| Node.js        | 22 or newer, to build the interface                                                                                                                                                                                                                          |
| Database       | MySQL 8 or SQLite                                                                                                                                                                                                                                            |
| Redis          | For the queue (optional, see [Configuration](docs/03-configuration.md))                                                                                                                                                                                      |
| ffmpeg         | Built with `libmp3lame`, for playback copies of audio (optional)                                                                                                                                                                                             |
| poppler-utils  | `pdftotext`, `pdftoppm`, for reading PDF receipts (optional)                                                                                                                                                                                                 |
| Cron           | One entry for the scheduler                                                                                                                                                                                                                                  |
| Mail           | SMTP or a mail service: sign-in codes are sent by email                                                                                                                                                                                                      |

## Installation

```sh
git clone https://github.com/wrklst/label-office.git
cd label-office
touch database/database.sqlite   # only when using SQLite, the default
composer setup                   # installs, creates .env, migrates, builds
composer dev                     # serves the app, runs the queue and watches assets
```

Then create your account. Nobody can register on their own:

```sh
php artisan royalties:create-user you@example.com --name="Your Name"
```

Open `http://localhost:8000`, sign in with the code sent to that address and
create your label. Locally, emails are written to `storage/logs/laravel.log`
instead of being sent, so read the code from there.

`php artisan db:seed` adds a demo user (`test@example.com`, password
`password`) and a "Demo Label". Do not run it on a public server.

Step by step, including MySQL: [docs/02-installation.md](docs/02-installation.md).

## Trying it locally with Laravel Herd

The easiest way to try Label Office on your own computer is
[Laravel Herd](https://herd.laravel.com). The free version brings PHP, a web
server, Composer and Node.js, and serves every folder inside its `Herd`
folder at `http://<folder name>.test`. You also need
[Git](https://git-scm.com/downloads).

The steps below set the application up for trying it out: with an SQLite
database, and with background work done right away instead of by a queue
worker, so that no Redis is needed.

### macOS

1. Install Herd and open it once.
2. In the Terminal:

```sh
cd ~/Herd
git clone https://github.com/wrklst/label-office.git
cd label-office
touch database/database.sqlite
composer setup
```

3. Open the file `.env` in the folder `label-office` and set these three
   lines, adding any that is not there yet:

```ini
APP_URL=http://label-office.test
QUEUE_CONNECTION=sync
AUDIO_PREVIEW_QUEUE=false
```

4. Create your account and open `http://label-office.test`:

```sh
php artisan royalties:create-user you@example.com --name="Your Name"
```

The sign-in code is not sent by email locally. Read it from the end of
`storage/logs/laravel.log`.

### Windows

1. Install Herd and Git for Windows, and open Herd once.
2. In PowerShell:

```powershell
cd $HOME\Herd
git clone https://github.com/wrklst/label-office.git
cd label-office
New-Item database\database.sqlite
composer install --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate --force
npm install
npm run build
```

3. Set the three lines in `.env` as for macOS, then create your account and
   open `http://label-office.test` the same way.

Windows is for trying it out only. The queue worker (Horizon) and the updater
need Linux or macOS, which is why two requirements are skipped during the
installation. We have not tested these Windows steps ourselves yet; please
tell us when one of them fails.

## Running it in production

A production server needs, beyond the above:

1. **HTTPS** and a web server pointing at `public/`.
2. **Horizon** kept running by a process manager (`php artisan horizon`).
   Without it no email is sent, no audio converted and no research run.
3. **The scheduler**: `* * * * * php artisan schedule:run` in cron.
4. **Mail** that actually delivers.
5. **Backups** of the database, the files and `.env` (it holds `APP_KEY`,
   which encrypts stored credentials). With an S3-compatible bucket
   connected, the database and the files are backed up there every night,
   set up under Settings → Backups; `.env` is yours to keep.

Ready-to-use configurations for nginx, Supervisor and Laravel Forge, a deploy
script, and how to move an installation to another server:
[docs/04-deployment.md](docs/04-deployment.md).

The application updates itself to each new tagged version, on demand under
**Settings → Updates** (for the installation admin) or automatically once a
day. It backs up the database first and goes back to the previous version when
a step fails. GitHub tags `main` with the next version once a day when it has
changed and its tests passed, so an installation can receive at most one new
version a day: [docs/02-installation.md](docs/02-installation.md#updating).

## Configuration

`.env` holds only the basic connections of the server: database, Redis, mail
and file storage. Everything a label can change is in the interface under
**Settings**:

- **Label**: name, currency, contact, logo and signet, accounting features and
  territories.
- **Team**: who has access to the label, and in which role.
- **Connections**: a switch and the options for Zebralution, Bandcamp, Subvert
  and Rightz Audio, the Bandcamp account, the DataForSEO login and research
  countries for the airplay research, the AI service that reads receipts and,
  for the installation admin, the file storage.
- **Updates** (installation admin only), **Profile**, **Security** and
  **Appearance**.

The settings you are most likely to touch:

| Setting                     | Purpose                                                         |
| --------------------------- | --------------------------------------------------------------- |
| `APP_URL`                   | The public address, with `https://`                             |
| `DB_*`, `REDIS_*`, `MAIL_*` | Database, queue and mail                                        |
| `ROYALTIES_FILES_DISK`      | `local` (default) or `s3` for a bucket, with the `AWS_*` values |
| `RELEASE_UPLOAD_MAX_KB`     | Largest release file; PHP and the web server must allow as much |
| `FFMPEG_BINARY`             | Path to ffmpeg when it is not on the `PATH`                     |
| `HORIZON_ALLOWED_EMAILS`    | Who may open the queue dashboard at `/horizon`                  |

Every setting with examples: [docs/03-configuration.md](docs/03-configuration.md).

## Documentation

The handbook lives in [`docs/`](docs) and is the same text the application
shows in its help panel, to label admins.

**Setup**

- [Introduction](docs/01-introduction.md): the concepts and a first session
- [Installation](docs/02-installation.md)
- [Configuration](docs/03-configuration.md)
- [Deployment](docs/04-deployment.md)

**Importing**

- [Importing statements](docs/10-importing-statements.md)
- [Bandcamp](docs/11-bandcamp.md)
- [Reports and play counts](docs/12-reports-and-play-counts.md)

**Accounting**

- [Transactions](docs/31-transactions.md)
- [Periods, statements and payouts](docs/32-periods-statements-and-payouts.md)
- [Mechanicals, PPD and reserves](docs/34-mechanicals-ppd-and-reserves.md)

**Deals**

- [Contracts, payees and ledgers](docs/30-contracts-payees-and-ledgers.md)
- [Agreements](docs/35-agreements.md)

**Catalog**

- [Releases](docs/20-releases.md)
- [Tracks, artists and items](docs/21-tracks-artists-and-items.md)
- [Files, audio and delivery](docs/22-files-audio-and-delivery.md)
- [Airplay](docs/23-airplay.md)

**Label**

- [Profitability and overheads](docs/33-profitability-and-label-costs.md)

**People and settings**

- [Team, roles and sign-in](docs/40-team-roles-and-sign-in.md)
- [Label settings](docs/41-label-settings.md)
- [Connections](docs/42-connections.md)

**Reference**

- [Command line](docs/50-command-line.md)
- [Extending](docs/51-extending.md): importers, station drivers, tests
- [Troubleshooting](docs/52-troubleshooting.md)

## Command line

```sh
php artisan royalties:create-user you@example.com
php artisan royalties:import statement.csv --label=example-records --paid=2026-11-15
php artisan royalties:sync-bandcamp --label=example-records
php artisan royalties:generate-previews
php artisan royalties:move-files --to=s3 --dry-run
php artisan royalties:backup --list
php artisan royalties:restore-backup
php artisan royalties:research-airplay --label=example-records
php artisan royalties:update --check
php artisan royalties:hand-over-installation new-admin@example.com
```

All commands and options: [docs/50-command-line.md](docs/50-command-line.md).

## Not included

- Mechanicals: no Harry Fox Agency file is produced, and the statutory
  rates are the US ones only.
- Mechanicals, PPD and reserves are never calculated on their own or as
  part of a period. Reserves are not liquidated automatically.
- Statements are downloaded as CSV, not Excel or PDF.
- Imports and delivery packages are built while you wait, not in the
  background.
- Uploads are sent in one request per file; there is no resumable upload.
- The interface is in English only.

## No legal guarantee

Label Office can draft agreements from templates and have them signed online. This application gives no legal advice and guarantees no legal compliance: not for the tool, not for signing electronically, and not for any text, example or template in it. Whether an agreement signed here is valid and enforceable depends on the law that applies to you. Electronic signatures are not accepted for every kind of agreement or in every country. Clear the use of this tool and of your texts with your legal adviser before you rely on them. The example agreement texts that ship with it are illustrations for German law and a German GmbH, not written or reviewed by a lawyer, and must not be used without legal review. See [Agreements](docs/35-agreements.md).

## Development

```sh
composer ci:check      # everything CI runs: lint, types, Pint, PHPStan, Pest
vendor/bin/pest tests/Feature
npx playwright install chromium                  # once, for the interface tests
npm run build && vendor/bin/pest tests/Browser   # interface tests need built assets
```

Tests use an in-memory database and fake file storage; they never touch your
data. They also use only invented names, codes and amounts. Please keep it
that way: never commit real statements, real artist names or real sales
figures.

How to add an importer for another distributor or a radio station for the
airplay research: [docs/51-extending.md](docs/51-extending.md).

## Support

For support, write to [tobias@wrklst.art](mailto:tobias@wrklst.art).

If you need an importer for another distributor or collecting society, send a
sample statement to the same address and I will work on it as soon as I can.
Please send it by email and not in a public issue: statements hold
confidential figures.

## Keeping the culture alive

Small independent labels all over the world are part of the backbone of a
diverse and humanistic music culture, one that is continually undermined by
big label deals and the streaming giants. Many of them are run as side
projects, paid for by day jobs and a passion for music.

Let's keep that culture alive by sharing what we know about improving the
infrastructure these labels work with. This tool is a first step: it is what
we learned over the last years. We appreciate every pointer, whether it is an
improvement, a feature, a trick for getting better airplay feedback or any
other industry knowledge.

Our vision is that this open-source tool grows into a far more powerful
system that really helps independent labels.

## Security

Royalty data is confidential. If you find a vulnerability, please report it
privately to the maintainers instead of opening a public issue.

## License

MIT. See [LICENSE](LICENSE).
