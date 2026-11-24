# Mechanicals, PPD and reserves

Three optional tools calculate mechanical royalties, royalties on the published price to dealer (PPD) and reserves against returns. Each is switched on per label, run by hand for a range of paid dates, and creates ordinary transactions that you can inspect and undo.

## What the three tools have in common

- **Nothing is calculated on its own.** None of the tools is part of the period calculation. You run a tool, check its report, then create the transactions.
- **A run reads income by paid date.** You choose **Paid from** and **Paid until**; the tool looks at the income transactions paid in that range.
- **A run creates one data import** holding ordinary transactions, in the label currency, booked on dedicated items. From there they flow through contracts, periods, statements and ledgers like everything else. See [Periods, statements and payouts](32-periods-statements-and-payouts.md).
- **What a tool booked is never counted as a sale again**, by the same tool or another one.
- **Deleting the import undoes the run.**

## Switching the tools on

1. Open **Settings → Label**.
2. Under **Accounting features**, tick **Mechanicals**, **PPD** and/or **Reserves**.
3. Set the options that appear (see below) and choose **Save**.

Each tool you switch on adds an entry under **Accounting** in the sidebar (**Mechanicals**, **PPD**, **Reserves**) and its fields to the release form. The three tools do not depend on the services under **Settings → Connections**.

| Setting                                      | Appears with       | What it does                                                                                                                         |
| -------------------------------------------- | ------------------ | ------------------------------------------------------------------------------------------------------------------------------------ |
| **Include physical sales**                   | Mechanicals        | Counts sold physical copies as units, next to downloads. Leave it off when you enter manufacturing runs instead.                     |
| **Statutory rate conversion: 1 USD = ? EUR** | Mechanicals        | What one US dollar is worth in your label currency. Needed for tracks without their own rate when the label does not account in USD. |
| **Ignore negative units**                    | Mechanicals or PPD | Off (default): returns reduce the units. On: lines with negative units count as zero.                                                |

## Running a tool

The steps are the same for all three:

1. Open the tool under **Accounting**.
2. In **Report**, enter **Paid from** and **Paid until** and choose **Show report**.
3. Read the report and the notices above it. They tell you what was left out and why: transactions without a converted amount (no rate, see [Currencies and conversion](31-transactions.md#currencies-and-conversion)), transactions without units, and what the tool cannot calculate.
4. Choose **Book transactions**, enter the date or dates asked for (**Paid date of the transactions**; **Held on** and **Liquidated on** for reserves), and choose **Book**.

The dates you enter in step 4 become the paid dates of the new transactions, so they decide which period the result falls in. Each tool page also has a panel **How it is booked** that says in short what the run creates, with a link to the **Items** page where you give those items a contract.

### The overlap warning

If the range shares at least one day with an earlier run of the same tool, the page says "This range overlaps an earlier run. Booking it again counts the same sales twice." To book anyway you have to tick **Book it although an earlier run overlaps this range**. If the new run replaces the old one, delete the old run instead.

Runs of different tools never overlap with each other.

### Sorting the report

The tables of a report, of **Reserves by release** and of the rates page sort by a click on a heading. The order is only for reading; booking always books the whole report.

### Undoing a run

Every tool page lists its **Earlier runs**, 25 to a page and sortable, with the paid dates it **Covers**, the **Paid date** it was booked on, the number of **Rows** (transactions), the total (**Mechanicals**, **PPD total** or **Reserves held**) and when it was **Uploaded**. Deleting a run there (**Delete run {from} – {to}?**) deletes its transactions; periods that included them change accordingly. The same runs appear under **Importing → Data imports**, named after the tool, and can be deleted there as well.

## Mechanicals

Mechanical royalties are owed to songwriters for every copy of a recording that is sold as a download or manufactured.

```text
mechanicals of a track = units × rate
```

**Which tracks take part.** A release takes part when **Account for mechanicals** is ticked on it. Each track follows its release unless it is set to **Account** or **Do not account** itself.

**Units** are, for each track:

- the units of income paid in the range with a download format (Download Album, Download Track, Download/Other),
- plus physical formats if **Include physical sales** is on,
- plus the copies of any **Manufacturing runs** you enter for the run.

A sale booked on the whole release counts once for each of its tracks that take part. A manufacturing run adds its copies to each of those tracks too.

**The rate** per unit is the first of:

1. the track's own rate,
2. the release's rate, which applies to each track of the release,
3. the US statutory rate for the track's length, from the table you choose for the run.

Rates on tracks and releases are amounts in your label currency and are not converted. The statutory rate is in US dollars and is multiplied by the conversion from the label settings.

### Where rates are set

On the tool page, under **Rates and tracks**, pick a release and choose **Edit rates**. One page then holds the **Mechanicals rate per unit** of the release, the switch **Account for mechanicals on this release**, and for every track its length, its setting (**Same as release**, **Account**, **Do not account**) and its own **Rate per unit**. The release form has the same release-level fields in the panel **Mechanicals, PPD and reserves**.

### The US statutory rate table

| Table    | Up to 5:00, per work | Over 5:00, per started minute |
| -------- | -------------------- | ----------------------------- |
| 2026     | 0.131 USD            | 0.0252 USD                    |
| 2025     | 0.127 USD            | 0.0245 USD                    |
| 2024     | 0.124 USD            | 0.0239 USD                    |
| 2023     | 0.12 USD             | 0.0231 USD                    |
| pre-2023 | 0.091 USD            | 0.0175 USD                    |

A run uses one table, chosen under **Statutory rates**; the table of the current year is preselected when there is one, otherwise the newest. A track of exactly 5:00 still gets the flat rate. The tables are defined in `config/royalties.php`.

With a conversion of 1 USD = 0.90 EUR and the 2026 table:

| Track length             | Statutory rate          | In EUR                  |
| ------------------------ | ----------------------- | ----------------------- |
| 3:20                     | 0.131 USD               | 0.131 × 0.90 = 0.1179   |
| 6:10 (7 started minutes) | 7 × 0.0252 = 0.1764 USD | 0.1764 × 0.90 = 0.15876 |

### What a run creates

For every track with units, two transactions of the same amount, with the category Mechanicals:

- an **expense** on the item "Track name [Mechanicals Expenses]",
- an **income** line on the item "Track name [Mechanicals Income]".

Both items are created on the first run and reused afterwards. They have no contract of their own, so both follow the contract of the track and **the pair cancels out**. Nothing changes for anyone until you give the income item a publishing contract under **Catalog → Items**.

### Example

Example Track (3:20) on Example Release earned 100 from 100 downloads paid in the first quarter. Example Artist has 50%. The label uses the statutory rate at 1 USD = 0.90 EUR.

The run calculates 100 × 0.1179 = 11.79 and books −11.79 and +11.79.

|                                                        | Before the run | After the run             | With a publishing contract on the income item |
| ------------------------------------------------------ | -------------- | ------------------------- | --------------------------------------------- |
| Net under the track contract                           | 100            | 100 − 11.79 + 11.79 = 100 | 100 − 11.79 = 88.21                           |
| Example Artist (50%)                                   | 50             | 50                        | 44.105                                        |
| Second Payee, the songwriter (100% of the income item) |                |                           | 11.79                                         |
| Label keeps                                            | 50             | 50                        | 44.105                                        |

In the last column the income item has a contract that pays Second Payee 100%, ideally on a ledger of its own. The expense stays under the track contract, so the artist and the label each bear half of the mechanicals.

## PPD

PPD is the published price to dealer. Some deals pay a royalty per unit sold based on it, rather than a share of net income.

```text
PPD of a release or track = units sold × rate per unit
```

**The rate** is an amount in your label currency per unit, taken from the item the sale was booked on, else its track, else its release. Having a rate is what makes a sale take part: anything without a rate at any of the three levels is ignored. Income of every format counts.

**Where rates are set.** On the tool page, under **Rates**, pick a release and choose **Edit rates**. The page holds the **PPD rate per unit** of the release, a rate per track, and a rate per item for when formats have different dealer prices. The release form also has the field **PPD**.

**The report** can be narrowed with **Artist**, **Release** and **Format**. It lists transactions without units (the first 50) so you can enter the units; they are not counted. Income on no release is not counted either, and the report says so.

### What a run creates

For every release and track with a PPD total, two income transactions with the category PPD:

- the total on the item "… [PPD Income]",
- the same amount with the opposite sign on the item "… [PPD Income Offset]".

Without contracts of their own the two cancel out. You decide who gets what under **Catalog → Items**:

- Give the **PPD Income** item a contract with the PPD royalty as its split.
- Give the **PPD Income Offset** item to whoever bears that cost. Left alone, it follows the track contract and reduces the net of the release for its payees.

### Example

Example Release sold 100 LPs, paid in the first quarter, with income of 1,000. Example Artist has 50% of the release. The PPD rate is 2.00, and Second Payee is owed 15% of PPD.

The run calculates 100 × 2.00 = 200 and books +200 and −200. The PPD Income item gets a contract with Second Payee at 15%: 200 × 15% = 30.

|                | No contracts on the PPD items | Offset left on the track contract | Offset on a contract without splits |
| -------------- | ----------------------------- | --------------------------------- | ----------------------------------- |
| Second Payee   | 0                             | 30                                | 30                                  |
| Example Artist | 500                           | (1,000 − 200) × 50% = 400         | 500                                 |
| Label keeps    | 500                           | 570                               | 470                                 |

The middle column charges the artist half of the PPD total, which is rarely what a deal says. If the label is meant to bear the PPD royalty, create a contract without splits and set it on the offset item, as in the right column.

## Reserves

A reserve holds back part of physical income for a while, because physical sales can come back as returns.

```text
reserve of a release = physical income paid in the range × Reserves %
```

**Reserves %** is set on the release form (25 means 25%). Only releases with a percentage take part. Physical income is income with a format of the Physical group, see [Transactions](31-transactions.md). Returns in the range are negative lines and lower the reserve; when returns outweigh sales, nothing is held.

### What a run creates

When creating the transactions you enter two dates: **Held on** and **Liquidated on**. For every release the run books two income lines on the item "Release name [Reserves]", with the category Reserves:

- the reserve as a **negative** amount, paid on the held date, format Reserves (Held),
- the same amount back as a **positive** amount, paid on the liquidation date, format Reserves (Liquidated).

The release earns that much less in the period of the first date and gets it back in the period of the second, for payees and label alike. The item follows the contract of the track.

The table **Reserves by release** on the tool page shows per release what was **Held**, what was **Liquidated so far** (up to today) and what is **Still held**. The report above it shows **Reserves to hold**, **Physical income** and the number of **Releases**; **Reserves %** is set on the release form, not on the tool page.

### Example

Example Release has **Reserves %** 25. In the first quarter, LP sales of 480 and returns of −80 were paid: physical income is 400. Example Artist has 50%.

The run calculates 400 × 25% = 100. You hold it on 31 March 2026 and liquidate it on 30 September 2026.

| Period                        | Without the reserve         | With the reserve                 |
| ----------------------------- | --------------------------- | -------------------------------- |
| First quarter: Example Artist | 400 × 50% = 200             | (400 − 100) × 50% = 150          |
| Third quarter: Example Artist | their share of that quarter | that share + 100 × 50% = 50 more |

## Good to know

- A tool that is switched off has no page. Switching it off later does not remove transactions it already created.
- The overlap check only compares dates. Two PPD runs over the same range for two different releases do not double count, but still ask for confirmation.
- Sales without units or without a conversion rate cannot be used. The report says how many were skipped.
- A track with neither a length nor a custom rate cannot be calculated, and neither can a statutory-rate track while the label has no USD conversion and does not account in USD. Both are listed in the mechanicals report.
- A manufacturing run entered for a release on which no track accounts mechanicals adds nothing; the report names the release.
- Mechanicals and PPD transactions carry the sale date and paid date you choose for the run, not the dates of the underlying sales.
- **Ignore negative units** does not apply to reserves: returns always lower the reserve.
- Reserves are not liquidated automatically after a number of periods. You choose both dates when you run the tool, and both lines are booked at once.
- Only the US statutory rates are included, and no Harry Fox Agency file is produced.
- Rates are read when the tool runs. Changing a rate afterwards does not change transactions already created; delete the run and run it again.
