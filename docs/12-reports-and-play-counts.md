# Reports and play counts

Every page that has income shows it in the same report: the dashboard for the whole label, and the pages of a release, a track, an artist, an import and a period for their part of it. Play counts are what the platforms themselves say was played; the report holds them against your statements, on the dashboard and on the release, track and artist pages once you imported some. The import and period pages show the income only.

## The dashboard

The first page after signing in. Until a first statement is imported it says **Nothing imported yet** and offers **Import a statement**. After that it shows, from top to bottom:

- **Needs attention before the next statements**, only when there is something to do. Each line is a link, and the counts cover the whole label, not the chosen dates:

    | Line                                     | Opens                                                                                 |
    | ---------------------------------------- | ------------------------------------------------------------------------------------- |
    | N items not assigned to a release        | **Catalog → Items**, filtered to **Unassigned**                                       |
    | N releases without a contract            | The catalog manager                                                                   |
    | N transactions without a conversion rate | **Accounting → Transactions**, filtered to those without a rate                       |
    | N imports flagged for cleanup            | **Importing → Data imports**; these are the imports with the status **Needs cleanup** |

- Four tiles for the chosen dates: **Income**, **Expenses**, **Net** (income plus expenses) and **Transactions** (how many).
- **Where the money went**: what customers paid and what was kept on the way; see [Importing statements](10-importing-statements.md).
- The report, and the panel **Releases by income**.

**By sale date** or **By paid date** decides which date of a transaction counts, and **From** and **To** the range. Until you choose other dates the dashboard shows the last twelve months; the note "Without dates: the last twelve months." next to the dates says so. An empty **From** or **To** goes back to that default. A **To** before **From** matches nothing. The dates are the browser's own date fields, so they are written the way your browser is set to; the rest of the page always writes dates as `19 Nov 2026`. The transaction list and the profitability report have the same control, but without dates they cover all time.

## The report

**How the income developed.** One column per month, stacked by platform. Three views:

| View           | Shows                                                          |
| -------------- | -------------------------------------------------------------- |
| **Income**     | What each platform brought in per month.                       |
| **Streams**    | How many streams the statements report per month.              |
| **Per stream** | What one stream paid on each platform, as a line per platform. |

Point at a month to read its figures, and at a platform to follow it through the chart. The eight platforms with the most income have a colour of their own; the rest are **Other**. A platform has the same colour on every page.

**Platforms.** One row per platform:

| Column                      | Meaning                                                                                                                   |
| --------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| **Income**, **Share**       | What the platform brought in, and its part of the whole.                                                                  |
| **Streams**                 | Streams the statements report.                                                                                            |
| **Per stream**              | Income from streams divided by streams.                                                                                   |
| **Trend**                   | How that rate went month by month.                                                                                        |
| **Downloads**               | Downloads sold.                                                                                                           |
| **Per download**            | Income from downloads divided by downloads.                                                                               |
| **Counted by the platform** | Plays from the platform's own figures, once you imported some (see below). Point at the number to see the days it covers. |
| **On statements**           | Streams the statements report for the same months, and their share of the count.                                          |

Statements name platforms in many ways. `Apple` and `Apple/iTunes` are one row, **Apple Music**. A platform the application does not know keeps the name the statement uses.

**What it was.** Income by kind of use: streams, downloads, use in videos (such as on TikTok or Facebook), records and merchandise, rights and licences.

**Where it was.** The twelve countries with the most income.

**Releases by income** (dashboard and artist page, the twelve with the most) and **Tracks by income** (release page, up to 30). Each row has a line of its last twelve months, the column **Last three months** held against the three before (with the change in per cent), **Streams**, **Per stream** and **Income**. A row that earned nothing in the last three months is marked **quiet**.

### Example

On the page of the track "Example Track" the Platforms table shows Spotify with 6,000 streams and €18.00, so **Per stream** reads €0.003. Apple Music shows 1,200 streams and €6.00, so €0.005.

## Play counts

A platform pays for streams months after they happened, and only for those it counts as paid. Its artist portal shows what it counted itself. Importing that count lets the report show both side by side.

No platform offers these figures to a label's own software. They have to be downloaded by hand:

| Platform                 | Where the figures are                                                                                    |
| ------------------------ | -------------------------------------------------------------------------------------------------------- |
| Spotify for Artists      | **Music → Songs**, then the download arrow above the table.                                              |
| Apple Music for Artists  | **Music → See All**; set the date range, then download the CSV.                                          |
| Amazon Music for Artists | Set the date range on a report page and export it.                                                       |
| Zebralution              | **Analytics → Datenexport**, set as described below. Only offered when the label works with Zebralution. |

### The Zebralution export

Zebralution counts every platform it delivers to, so one export covers most of them.

The short way: sign in to [Zebralution Tools](https://zebralution.tools), then on **Importing → Play counts** follow the link **download the streams since the last import**. The link is only there while the label works with Zebralution (**Settings → Connections**; see [Connections](42-connections.md)). It asks Zebralution for the streams by track, platform and day, from a week before the last counted day until yesterday, or for the last year when nothing was counted yet. The week is fetched again because the latest days are still filling up; the import replaces them.

By hand: in its analytics, set the date filter to the months you want and remove every other filter, then open **Datenexport** and tick:

| Section         | Tick                           | Leave empty                                             |
| --------------- | ------------------------------ | ------------------------------------------------------- |
| **Gruppierung** | **Tracks**, **DSP**, **Datum** | Releases, Land, Firma, Label, Künstler\*innen, Playlist |
| **Datenfelder** | **Streams**                    | Downloads, Views, Creations                             |

**Tracks** puts the ISRC on each line, which is what a line is matched by. **DSP** names the platform, so each count is held against that platform's statement lines. **Datum** gives the days, so a later export replaces exactly the days it covers.

With **DSP** ticked the export takes only the kind of figure that is active in the analytics view, so have streams showing there. If the export does not let **DSP** be combined with **Tracks**, tick **Tracks** and **Datum** only: the file then has all platforms in one count and is listed as **Unknown**. Country and playlist are not stored: ticking them only makes the file longer. An export filtered to one country or playlist would replace the full figures of those days with partial ones.

### Importing a file

1. Open **Importing → Play counts**.
2. In the panel **Import a file** choose the file: CSV, TSV, TXT or Excel (`.xlsx`), up to 50 MB.
3. Choose **Import play counts**.

Nothing else is asked: no platform, no dates, no paid date. Everything is read from the file. The platform is the one the lines name, otherwise the one in the file name (`spotify-songs.csv`), otherwise the one the columns give away (at present Spotify's **Songs** export). A file that names none, such as a distributor's export of all platforms together, is listed as **Unknown**. So is a platform the application has no name for. A file without dates counts as the total up to the day it is imported and replaces every earlier count of its tracks on that platform.

The file is read by what its columns are called, not by where it comes from:

| The file needs          | Accepted headers                                                          |
| ----------------------- | ------------------------------------------------------------------------- |
| The track               | `track`, `song`, `title`, `name`, or `isrc`                               |
| The count               | `plays`, `streams`, `play count`, `listens`, `views` (and a few variants) |
| Optional: listeners     | `listeners`                                                               |
| Optional: artist        | `artist`                                                                  |
| Optional: platform      | `platform`, `service`, `store`, `dsp`                                     |
| Optional: day or period | `date`, or `from` and `to`                                                |

A line is matched to a track by its ISRC, otherwise by its name. Punctuation does not matter: `Example Track - Example Remix` is the track `Example Track (Example Remix)`. Where several recordings share a name, the artist column decides. A recording on several releases is counted once.

Lines whose track is not in the catalog are kept and listed under the file as **Not in the catalog under that name**. They count for the label as a whole, not for a track.

Of those the first 30 names are listed; when a file has more, the line says how many, for example "30 of 112". Fix the names in the catalog, or the file, and import it again.

The result is a toast: "N play counts imported." or, when some lines have no track in the catalog, a warning: "N play counts imported, M of them for tracks the catalog does not have under that name." A file that has no column for the count and the track, or no line with both, is refused with the reason, and nothing is stored.

A newer file replaces earlier counts of the same track and platform for the days it covers, so nothing is counted twice. The table **Files** lists every upload, 50 to a page, with **File**, **Platform**, **Covers**, **Rows** (and how many of them matched a track), **Plays** and **Uploaded**; click a heading to sort. A file with lines for several platforms lists each. The bin at the end of a row, **Delete {file name}?**, removes everything one file brought; statements and income are not touched.

### The report on the page

Below the upload, **Importing → Play counts** shows what was counted, drawn like the income report:

- **How the plays developed**: the counted plays month by month, stacked by platform.
- **Platforms**: what each platform counted (**Counted by the platform**), its **Share**, its **Trend**, the streams the statements have for the same months (**On statements**, with their share of the count), and the days the count covers (**Counted from** and **to**).
- **Releases by plays** and **Tracks by plays**: the twelve that were played most, each with its last twelve months, the **Last three months** held against the three before and the **Counted** total. A recording on several releases counts for the release of its first entry.

Country and playlist are not kept, so there is no breakdown by them.

### Reading the comparison

The count and the statements rarely match:

- Statements arrive late. The last months of a count are often not on a statement yet.
- A platform counts every play; it pays only for plays above its thresholds.
- The comparison is by month. A count that starts or ends inside a month is held against the whole month.

A share far below 100% for months that are fully reported is worth a question to your distributor.
