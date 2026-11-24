# Releases

This page covers the release list, creating and editing releases, every field on the release form, the release page with its figures, and the Catalog manager. Only label admins can open these pages.

## Where releases come from

Most releases are created for you: when you import a statement, every release, track and artist named in the file that is not in your catalog yet is added. See [Importing statements](10-importing-statements.md).

You can also create a release by hand, for example before its first statement arrives. An import later finds a hand-made release by its UPC / EAN, then by its catalog number. If the release has a catalog number but no UPC yet, the first statement line that names both fills in the UPC.

## The release list

Open **Catalog → Releases**. The list shows 50 releases per page.

| Column         | What it shows                                                                                                                                 |
| -------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| (cover)        | A small thumbnail of the cover, if one is uploaded.                                                                                           |
| (play)         | **Play release** and **Queue release** buttons, only when at least one track has audio.                                                       |
| Cat. no.       | The catalog number.                                                                                                                           |
| Release        | The name, linked to the release page. A status badge appears unless the status is Done, and a badge with the release type if one is set.      |
| Artist         | The credited artists, for example `Example Artist feat. Guest Artist`.                                                                        |
| UPC / EAN      | The barcode number.                                                                                                                           |
| Released       | The release date.                                                                                                                             |
| Tracks         | The number of tracks.                                                                                                                         |
| Contract       | The contract of the release's tracks when they all have the same one, or the badge **No contract**, or the badge **Several or some missing**. |
| Income to date | The sum of all income transactions of the release, in your label currency. Expenses are not subtracted here.                                  |

**Search.** The box **Search name, catalog number or UPC…** matches any part of those three values. The list updates while you type.

**Filters.**

- **All artists** narrows the list to one artist. It matches a release when the artist is its main artist, a further artist credit of the release, or on one of its tracks, in any role. This is also how the release count in the artist list is made, and that count links here.
- **Any status** narrows the list to **To do**, **In progress** or **Done**.
- **Without contract** shows only releases with at least one track that has no contract. A release without tracks is not listed: it has nothing a contract could be set on.
- **Clear filters** appears when any search or filter is set and resets all of them.

**Sorting.** Without a sort the list is ordered by catalog number, with releases that have no catalog number last, then by name. Click a column heading to sort by it, click again to reverse, and a third time to go back to the default order. Every column with a heading text can be sorted, and the sort applies to all pages, not only the one you see.

The **Metadata CSV** button above the list downloads the metadata of all releases; see [Files, audio and delivery](22-files-audio-and-delivery.md).

## Create a release

1. Open **Catalog → Releases** and click **New release**.
2. Enter at least a **Name**.
3. Fill in what you know; all other fields are optional and can be added later.
4. In the **Tracks** panel, click **Add track** for each track and enter its title.
5. Click **Create release**.

You return to the page you opened the form from: the release list with its search and filters when you clicked **New release** there, otherwise the page of the new release. Every new release also gets an item called `[Additionals]`, which carries income and costs you enter by hand; see [Tracks, artists and items](21-tracks-artists-and-items.md).

Example: for the label Example Records you create a release named `Example Release` by `Example Artist` with catalog number `EX001`, add the tracks `Opening` and `Second Track`, and leave everything else empty. The release appears in the list with the status **To do** and the contract **No contract**.

## Edit or delete a release

1. Open the release and click **Edit**.
2. Change the fields and click **Save release**.

After you save you return to the page you opened the form from (usually the release itself; or the release list with its filters, or the Catalog manager). If the form was opened without a page to return to, you land on the release.

To delete, click **Delete** in the header of the release page, next to **Edit**; the edit form has no delete button. The dialog is titled **Delete {release name}?** and lists what is removed with the release: its tracks, its items without transactions, its platform links and all its files, which are also removed from storage for good. Its transactions are kept, but they become unassigned. See [Good to know](#good-to-know).

## Fields: Release

| Field                  | What it is for                                                                                                                |
| ---------------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| Name                   | The title. Required.                                                                                                          |
| Artist                 | The main artist. Leave it at **No artist / various** for compilations.                                                        |
| Catalog number         | Your own number, for example `EX001`. Imports use it to find the release when a line has no known UPC.                        |
| UPC / EAN              | The barcode number, 8 to 14 digits. Spaces and dashes are removed when you save. Imports find the release by this code first. |
| Release date           | The date the release comes out.                                                                                               |
| Spotify ID, Discogs ID | Free text fields for the identifiers on those services.                                                                       |
| Notes                  | Internal notes, shown at the bottom of the release page.                                                                      |

A UPC / EAN can belong to only one release of your label. A 12-digit UPC and its 13-digit EAN form (the same digits with a leading zero) count as the same code.

## Fields: Release details

These are the values platforms and distributors ask for. They are shown on the release page and written into the metadata CSV and the delivery package.

| Field                  | What it is for                                                                                                                                           |
| ---------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Status                 | Where the release stands in your own workflow. See [Status](#status).                                                                                    |
| Format                 | The release type. See [Release types](#release-types).                                                                                                   |
| Version                | For example `Deluxe Edition` or `The Remixes`. Shown in brackets after the name.                                                                         |
| Genre, Subgenre        | Free text. While the label works with Zebralution, the genre field suggests Zebralution's genres; see [Connections](42-connections.md#services).         |
| Language               | A short language code such as `en`, `de`, or `zxx` for instrumental. At most 8 characters.                                                               |
| ℗ line                 | The owner of the recordings, for example `2026 Example Records`.                                                                                         |
| © line                 | The owner of artwork and packaging.                                                                                                                      |
| Explicit               | **Not explicit** (empty), **Explicit** or **Clean version**.                                                                                             |
| AI in the artwork      | **Not declared** (empty), **None**, **Partly AI-generated** or **Fully AI-generated**. See [AI declarations](#ai-declarations).                          |
| Original release date  | For reissues.                                                                                                                                            |
| Pre-order from         | The date pre-orders start.                                                                                                                               |
| Link to external files | A web address (`http` or `https`) of a shared folder, if files also live somewhere else.                                                                 |
| Do not deliver to      | Platforms that must not get the release, separated by commas, for example `Spotify, Tidal`.                                                              |
| Blocked territories    | Two-letter country codes separated by commas, for example `RU, IL`. They are stored in upper case; anything that is not exactly two letters is rejected. |
| Further artist credits | Additional artists on the whole release, each with a role. See [Tracks, artists and items](21-tracks-artists-and-items.md#artist-credits).               |

The blocked platforms and territories are recorded and exported. The software does not deliver releases itself, so it does not enforce them.

### AI declarations

Distributors ask whether AI was used. The release has one declaration, **AI in the artwork**; each track has three, **AI in the recording**, **AI in the composition** and **AI in the lyrics**, edited in the track dialog (see [Tracks, artists and items](21-tracks-artists-and-items.md#ai-declarations)). Each takes **None**, **Partly AI-generated** or **Fully AI-generated**; **Not declared** leaves it empty. The label states what is true; nothing is checked against the files.

- The artwork declaration is shown on the release page under **Release details**.
- All four are written into the metadata CSV, in the columns `ai_artwork`, `ai_recording`, `ai_composition` and `ai_lyrics` as `none`, `partly` or `all`, and into the Zebralution sheet. The Zebralution ZIP is refused while the release or one of its tracks has an empty declaration; see [Files, audio and delivery](22-files-audio-and-delivery.md#the-zebralution-zip).
- A track's declarations do not default to the release's, and the release's artwork declaration does not apply to tracks.

## Fields: Promotion and planning

| Field         | What it is for                                     |
| ------------- | -------------------------------------------------- |
| To do         | Open tasks for this release.                       |
| Key facts     | Selling points for platforms, press and playlists. |
| Press release | The full press text.                               |

All three are shown on the release page when filled. Key facts and the press release are also written into the `release.txt` of the delivery package.

## Fields: Tracks

Each row is one track with these columns: `#` (track number), **Title**, **Main artist**, **ISRC** and **Contract**.

- **Add track** adds a row. The number is prefilled with the next position and the main artist with the artist of the release.
- A title is required for every row.
- An ISRC must have 12 letters and digits. Dashes and spaces are ignored, so `DE-A1B-26-00001` and `DEA1B2600001` are the same. It is stored without dashes.
- **Contract for all tracks** above the rows fills the contract of every row at once; change a row afterwards for a track with its own deal. Leave a row at **No contract** when no deal covers it. The contracts are saved with the tracks.
- The **X** at the end of a row removes the track. When you remove a track that was already saved, a warning appears: saving deletes the track, and its transactions stay on the release but lose the track and any track-level contract.

These rows are the quick way to add tracks while you create a release. Further track details (version, disc number, ISWC, length, credits, lyrics and more) are edited in the track dialog, which opens from the release page, the track list and the track page. The rows use the same labels and are checked by the same rules as the dialog. See [Tracks, artists and items](21-tracks-artists-and-items.md).

## Fields: Mechanicals, PPD and reserves

This panel only appears when at least one of the three tools is switched on for your label under **Settings → Label** (**Accounting features**). It holds **Mechanicals rate**, **PPD**, **Reserves %** and the checkbox **Account for mechanicals**. See [Mechanicals, PPD and reserves](34-mechanicals-ppd-and-reserves.md).

## Status

The status is a marker for your own workflow. It does not change any calculation.

| Status      | Meaning                                                                    |
| ----------- | -------------------------------------------------------------------------- |
| To do       | The default for every new release, also for releases created by an import. |
| In progress | You are working on it.                                                     |
| Done        | Nothing left to do. The badge is hidden in the release list for these.     |

## Release types

The field is labelled **Format** on the form and on the release page. The values are **Album**, **EP**, **Single** and **Compilation**, or **Not set**. The type is shown as a badge in the list and is written to the metadata CSV and the delivery package. It has no effect on royalties.

## Contract on a release

A release has no contract of its own, see [why](30-contracts-payees-and-ledgers.md#why-a-release-has-no-contract-of-its-own). Contracts belong to its tracks, and a track without a contract earns nothing for any payee: the label keeps all of its income.

The most specific contract wins: a contract set on a single transaction, then on the item, then on the track. See [Contracts, payees and ledgers](30-contracts-payees-and-ledgers.md).

You can set the contract of a track in four places:

- per track on the release form or in the track dialog,
- for every track of the release at once with **Contract for all tracks** on the release form,
- for every track of the release at once with **Set contract on all tracks** on the release page, which writes the contract on the tracks that have none, or on all of them when you tick **Also replace the contracts tracks already have**,
- for many releases and tracks at once in the [Catalog manager](#catalog-manager).

## The release page

Click a release name anywhere to open its page. The heading shows the name (with the version in brackets), the status, the artist, the type, the catalog number, the UPC and the release date.

**Buttons.**

- **Delivery** downloads the release as a package or as a spreadsheet; see [Files, audio and delivery](22-files-audio-and-delivery.md).
- **New expense** opens a new expense transaction with this release filled in; type the amount positive. After you save, you are back on this release. See [Transactions](31-transactions.md).
- **Edit** opens the release form; after you save you return to this page.
- **Delete** opens the dialog **Delete {release name}?**; see [Edit or delete a release](#edit-or-delete-a-release).

**Cover and figures.** Next to the cover there are four tiles:

| Tile             | What it shows                                                                                                                  |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| Income to date   | The sum of all income transactions of the release.                                                                             |
| Expenses to date | The sum of all expense transactions (shown negative).                                                                          |
| Net              | Income plus expenses.                                                                                                          |
| Contract         | The contract of the tracks, linked, when they all have the same one; otherwise **No contract** or **Several or some missing**. |

Example: with 1,250.00 of income and −400.00 of expenses, **Net** shows 850.00.

**Tracks.** The table has the columns `#`, **Title**, **Artist**, **ISRC**, **Length**, **Listen**, **Contract**, **Units** and **Income**. Click a heading to sort.

- **Units** and **Income** are the units and amount of the income transactions booked on that track.
- **Contract** shows the track's own contract, or **No contract**.
- **Listen** holds the play buttons, or **No audio** when no audio file is attached to the track.
- The pencil at the end of a row opens the track dialog **Edit {track title}** with all track details, the same one as in the track list and on the track page.
- If income was booked on the release without a track, a last line reads **Booked on the release as a whole (album sales, additionals)** with that amount.
- The button **… transactions** above the table opens the transaction list filtered to this release.

**Files.** Upload and manage the cover, audio and other files; see [Files, audio and delivery](22-files-audio-and-delivery.md).

**Release details.** The values from the form: Format, Genre, Language, Explicit, ℗ line, © line, Original release, Pre-order from, Not delivered to, Blocked territories and External files.

**To do, Key facts, Press release.** Each is shown only when it has text.

**The report.** How the income of the release developed, which platforms it came from and what each paid per stream, followed by **Tracks by income**; see [Reports and play counts](12-reports-and-play-counts.md).

**Items.** Every name a statement, platform or manual entry used for the release or one of its tracks, 25 to a page and sortable. The columns are **Name on statements** (with the sources that used the name and its format group below), **Booked on** (the track, or **Whole release**, with the contract override if set), **Transactions** and **Amount**; see [Tracks, artists and items](21-tracks-artists-and-items.md#items).

**Notes.** Shown when the release has notes.

## Where to find it

The panel **Where to find it** on the release page collects the pages of the release on the platforms where it is streamed or sold: Spotify, Apple Music, Bandcamp, Subvert, Deezer, Tidal, YouTube, Beatport and so on.

### Finding links

**Find links** looks the release and each of its tracks up in the background. Links appear in the panel as they are found.

| Source                                                     | What it finds                                                                                  | Shown as        |
| ---------------------------------------------------------- | ---------------------------------------------------------------------------------------------- | --------------- |
| The **UPC** of the release                                 | Its album page on Deezer and on Apple Music                                                    | found by code   |
| The **Spotify ID** stored on the release                   | Its album page on Spotify                                                                      | found by code   |
| The **ISRC** of each track                                 | The track's page on Deezer, and its song page on Apple Music                                   | found by code   |
| **Bandcamp sales** fetched through the Bandcamp connection | The Bandcamp page the sales name                                                               | from sales      |
| **Subvert**                                                | The address Subvert builds from artist and release name, kept when that page names the release | found           |
| One **web search**, when the label has a web search login  | Results on known platforms whose title names the release                                       | found by search |

At most one link per platform is found. The web search is one billed search per release; without a login under **Settings → Connections** it is skipped.

### Adding and correcting

- **Add link** opens the dialog **Add a link**: paste the address under **Link**. The platform is recognised from it (**Automatic**); choose one yourself in **Platform** for an address that is not recognised. Save with **Save link**.
- The pencil corrects a link. A corrected link counts as your own.
- The bin of a link opens the dialog **Delete {platform} link?**. Removing a link that was found keeps it out for good: **Find links** does not bring it back. Add it by hand if you want it again.

Example: for `Example Release` (EX001, UPC 4000000000001), **Find links** adds Deezer and Apple Music by the UPC and the Subvert page `subvert.fm/example-artist/example-release`. The Bandcamp page is not found, because no sale came in through the sync yet, so you paste `https://example.bandcamp.com/album/example-release` with **Add link**.

## Play or queue a whole release

When at least one track of a release has audio, two buttons appear above the track table:

- **Play release** replaces the current queue with the tracks of the release and starts the first one.
- **Set contract on all tracks** above the table opens a dialog with a contract field and the box **Also replace the contracts tracks already have**. Without the box only the tracks without a contract get it. An empty contract with the box ticked removes the contract from all tracks. A message says on how many tracks the contract was set and how many kept their own.
- **Queue release** adds the tracks to the end of the queue. Tracks that are already queued are not added twice.

The same two actions are available as icon buttons in the release list and on an artist's page. Tracks without audio are left out. See [Files, audio and delivery](22-files-audio-and-delivery.md#the-audio-player) for the player itself.

## Catalog manager

Open **Catalog → Catalog manager** (`/catalog`). It is the place to assign contracts across the whole catalog without opening each release.

Every release is a box with its catalog number, name and artist, and a field on the right that sets a contract on the tracks of the release that have none. Below it, each track has its own contract field.

1. Choose a contract in the field of a release to write it on the tracks of that release that have none. Tracks with a contract keep theirs.
2. Choose a contract in the field of a track to give that track its own contract. When left empty, the field shows **No contract**.
3. Nothing else is needed: every change is saved immediately.

Tools on the page:

- **Search releases and tracks…** narrows the page to releases where the release name, catalog number, artist, a track title, a track ISRC or a track artist contains your text.
- **Without contract** shows only releases with at least one track that has no contract. A release without tracks is not listed: it has nothing a contract could be set on.
- The counter on the right says how many tracks have no contract at all, or **Every track is covered by a contract.**
- A badge **No contract** marks each track that has no contract.
- **New contract** opens the contract form.

Example: `Example Release` has two tracks without a contract. The counter shows `2 tracks without a contract`. You pick the contract `Example Artist 50/50` in the field of the release; both badges disappear and the counter drops by two. `Second Track` is a remix with its own deal, so you pick `Remixer deal` on that track only.

## Good to know

- The **All artists** filter and the release count in the artist list use one rule: main artist, further credit, or an artist on one of the tracks. The artist's own page lists the same releases.
- **Income to date** in the list counts income only. Use the **Net** tile on the release page to see income minus expenses.
- Amounts are shown in your label currency, using the converted value of each transaction.
- The track order in the form and on the release page is by track number. The disc number is not shown on the release form; set it in the track dialog.
- Saving the release form replaces the track list: tracks whose row you removed are deleted. Their transactions stay on the release.
- Deleting a release deletes its tracks, its files (from storage, for good) and its items without transactions. Its transactions are kept but become unassigned, so they no longer earn for any payee. Items that carry transactions stay behind as unassigned items, and the `[Additionals]` item is renamed to `[Additionals] Example Release`, so you can assign that income to another release on the **Items** page.
- A release can be saved without tracks. Income can still be booked on the release itself.
- The Catalog manager shows 50 releases per page. Search and **Without contract** work on the whole catalog, not only the page you are on, and **Clear filters** resets them.
- The status, the release type, the AI declarations and the blocked platforms and territories are information only for statements and payouts. They do not affect statements or payouts.
