# Contracts, payees and ledgers

This page explains who gets paid (payees), on what terms (contracts and their splits) and how balances are kept apart (ledgers and territories). It ends with a worked example.

## The pieces and how they fit

| Piece     | What it is                                                                                               | Where                 |
| --------- | -------------------------------------------------------------------------------------------------------- | --------------------- |
| Payee     | Someone you owe royalties to: an artist, remixer, producer, sub-label.                                   | **Deals → Payees**    |
| Contract  | A named deal. It holds splits and is assigned to tracks, items or single transactions.                   | **Deals → Contracts** |
| Split     | One line of a contract: a payee, a percentage, optionally a ledger and a territory.                      | On the contract       |
| Ledger    | A separate running balance for a payee. Every split books to the primary ledger unless you pick another. | **Deals → Ledgers**   |
| Territory | A named group of countries, used to limit a split to part of the world.                                  | **Settings → Label**  |

A payee earns nothing by existing. They earn through splits on contracts, and a contract earns nothing until it is assigned to something that has income.

## Payees

1. Open **Deals → Payees** and choose **New payee**.
2. Enter a **Name**. **Email**, **Tags** (separated by commas) and **Notes** are optional and only for your reference. **Legal name**, **Address**, **Country** (the two-letter code of the tax residence) and **VAT ID** say who the payee is as a party to an agreement; they are optional too.
3. Choose **Create payee**.

The list searches name, email and tags (**Search payees…**) and shows **Name** (with its tags), **Email**, **Splits**, **Statements** and **Payouts**; every one of them sorts, and the list pages 50 at a time. A payee on no contract shows a **no splits** badge. The pencil at the end of a row opens **Edit {payee name}**.

The email on a payee does not give anyone access. Access to **My statements** is granted under **Settings → Team**, see [Team, roles and sign-in](40-team-roles-and-sign-in.md).

Click a payee to open their page. It shows:

- four figures for the latest period (**Earned in {period}**, **Payable now**, **Paid for {period}** and **Balance across ledgers**) and the **Ledger balances** at the end of it, with an **Unrecouped** badge on a negative one and a link to the **Full breakdown**,
- **Splits**: every contract they earn from, with share, ledger, territory and the releases and tracks it applies to, sortable,
- **Statements** and **Payouts**, 25 to a page each and sortable,
- **Transfers**: advances, paybacks and opening balances, 25 to a page and sortable. A transfer whose currency has no rate shows an **unconverted** badge instead of an amount. **New transfer** opens a new transfer for this payee, see [Transactions](31-transactions.md).

**Edit** in the header changes the payee. **Delete** is disabled for a payee with transfers, payouts or statements, and its tooltip says why; for any other payee it opens **Delete {payee name}?**, which removes the payee's splits from every contract and unlinks a team member linked to the payee.

## Contracts and splits

A split is a percentage of **net income**: income minus the recoupable expenses booked on the same release or track. What the splits do not hand out stays with the label.

1. Open **Deals → Contracts** and choose **New contract**.
2. Give it a **Name** that describes the deal, for example "Example Artist album deal 50/50". **Memo** is a free note.
3. Under **Splits**, choose **Add split** for each payee and fill in:
    - **Payee**,
    - **Split %**, a number above 0 and up to 100, with up to seven decimals,
    - **Ledger**, left on **Primary ledger** unless the balance must be kept apart,
    - **Territory**, left on **Worldwide** unless the split only applies in some countries.
4. Choose **Create contract**. You land on the page of the new contract.

The form shows the sum as you type, for example "Payees get 60% worldwide, the label keeps 40%." A contract whose splits add up to more than 100% is refused. A contract without splits is allowed: the label keeps everything booked on it.

The contract list shows **Splits**, **Label keeps** and **Used by** for every contract. Search by name (**Search contracts…**), sort by **Contract** or **Used by**, or tick **Unused** to find contracts that no release, track, item or prorated contract relies on. The list pages 50 at a time. A bar shows how the splits divide the contract; a contract without splits shows **no splits**, one that pays out more than 100% shows **over 100%**, and a prorated contract shows **prorated · N contracts** and takes its splits **From child contracts**.

Click a contract name, here or anywhere a contract is named, to open its page. It shows the terms (name and memo), the splits with payee, share, ledger and territory, the contracts a prorated contract is made of or is part of, and the **Releases** (those with a track under the contract) and **Tracks** the contract applies to, 25 to a page each and sortable. **Edit** in the header opens the form; after you save you return to the page you came from, the contract's own page when there is none. **Delete** opens **Delete {contract name}?**, which says that the contract's splits go with it and that tracks and items fall back to the next contract up the chain.

A payee can appear in several splits of one contract, for example 40% worldwide plus 10% in one territory. On their statement the income then appears once, at the combined percentage.

## Where a contract can be set, and which one wins

Contracts belong to tracks. A release has no contract of its own. To put one contract on a whole release, use **Set contract on all tracks** on the release page: it writes the contract onto the tracks of the release. By default only the tracks without a contract get it; tick **Also replace the contracts tracks already have** to overwrite the others too. Leaving the contract empty with that box ticked removes the contract from all tracks. The release form has the same shortcut as **Contract for all tracks**, which fills the contract of every track row.

A contract can be set in three places. For each transaction the most specific one wins; the places are never added up:

| Order | Set on                                | Where you set it                                                                         |
| ----- | ------------------------------------- | ---------------------------------------------------------------------------------------- |
| 1     | The transaction                       | **Contract override** on the transaction form                                            |
| 2     | The item the transaction is booked on | **Contract override** under **Catalog → Items**                                          |
| 3     | The track                             | **Contract** on the track, or the track row of the release form (empty: **No contract**) |

Typical uses:

**What is booked on a release as a whole.** A sale of the album, or a cost for it, names no track. It is divided in equal parts between the tracks of the release, and each part is paid by that track's contract. A part that falls on a track without a contract is paid to nobody, and a release without tracks has no contract at all. In both cases the period lists the release under transactions without a contract.

Example: an album of four tracks sells for 10.00 as a whole. Three tracks have the album contract and one is a remix with its own. 7.50 is paid by the album contract and 2.50 by the remix contract.

- Track: the normal album or single deal on every track of the release, or a different deal for one track, such as a remix.
- Item: one product has its own deal, such as a licensed vinyl run. The mechanicals and PPD tools also rely on this, see [Mechanicals, PPD and reserves](34-mechanicals-ppd-and-reserves.md).
- Transaction: a single transaction follows a different deal than its track.

**Catalog → Catalog manager** lists every release with its tracks and lets you assign track contracts in one place. The contract chosen beside a release is written on the tracks of that release that have none. Changes there save immediately.

The contract page lists the tracks that use the contract and the releases they are on.

## Why a release has no contract of its own

A contract on a release cannot say "this payee, but only for that track". If a featured artist or remixer is written into the contract of a release, they are paid on every track that falls back to it, also on tracks they have nothing to do with. That is wrong, and it is the reason release contracts were removed.

Leaving that person out of the release's contract was wrong the other way. A sale of the whole album was paid by the release's contract alone, so the featured artist got nothing from album sales unless someone worked out their share of the album by hand (10% of one track in four is 2.5%) and redid it whenever the track list changed.

With contracts on tracks only, each deal sits on exactly the tracks it is for. A track's streams are paid by its contract. A sale of the whole release is divided in equal parts between the tracks, and each part is paid by that track's contract. Nothing has to be worked out by hand, and every amount on a statement traces back to one track and one contract.

Example: Example Release has four tracks and sells once as a whole for 10.00. Tracks 1, 2 and 4 carry the contract of Example Artist (50%). Track 3 has its own contract because of a feature: Example Artist 40% and Example Feature 10%. Each track's part is 2.50.

| Tracks     | Part of the sale | Example Artist | Example Feature | Label keeps |
| ---------- | ---------------- | -------------- | --------------- | ----------- |
| 1, 2 and 4 | 7.50             | 3.75           | 0.00            | 3.75        |
| 3          | 2.50             | 1.00           | 0.25            | 1.25        |
| Together   | 10.00            | 4.75           | 0.25            | 5.00        |

One contract for a whole release is still one step: **Set contract on all tracks** on the release page writes it on every track, and a track with a different deal then gets its own contract in place of it. The division of a whole-release sale is always equal per track; contracts are percentages and nothing weights a track by its length or anything else.

## Income without a contract

- A transaction on a release, with no contract at any of the three levels (a release counts as having none when it has no tracks or a track without one): the label keeps the amount, or of a sale of the whole release the part that falls on the tracks without a contract. The period page lists these releases under **1. Check the data**.
- A transaction with no release at all: it is "unassigned" and the period page reports it. It counts as income of the label, and no payee earns from it unless the transaction or its item has a contract of its own.

Nothing is lost in either case. As soon as you assign the contract or the release, the figures of every period that is not yet frozen in a statement change accordingly. See [Periods, statements and payouts](32-periods-statements-and-payouts.md).

## Ledgers

A ledger is a running balance for one payee:

```text
balance = previous balance + share earned + transfers − payouts
```

Every payee has a primary ledger automatically. Add another under **Deals → Ledgers** with **New ledger** (the list shows each ledger's **Payees** and where it is **Used in contracts**, sortable; the pencil renames a ledger) when a deal's balance must not mix with the payee's other earnings. The usual case is an advance that should be recouped only from the release it was paid for.

Three rules apply to every ledger:

- **A negative balance is unrecouped.** Nothing is payable from that ledger until earnings have brought it back above zero.
- **Unrecouped balances carry forward.** The negative balance is the starting point of the next period.
- **Ledgers never offset one another.** A payee who is 100 below zero on one ledger and 60 above zero on another is owed 60, not −40.

To recoup an advance on its own:

1. Create a ledger, for example "EX001 advance".
2. In the contract of the release's tracks, pick this ledger in the payee's split.
3. Book the advance as a transfer for the payee with the release selected and **Ledger** left on **Automatic**. The transfer then lands on the ledger of the payee's split in the contract of the release's tracks. You can also pick the ledger by hand.

Deleting a ledger (**Delete {ledger name}?**) moves its splits and any payouts booked to it to the primary ledger, and a transfer booked to it by hand goes back to **Automatic**, so balances change.

## Territories and territory splits

Territories are created under **Settings → Label**, in the **Territories** section:

1. Enter a **Territory name**, for example "GSA".
2. Enter the **Countries** as two-letter codes separated by spaces or commas, for example `DE AT CH`.
3. Choose **Add territory**.

A split with a territory only earns from transactions whose **Country** is one of those codes. A split without a territory applies everywhere.

The 100% rule looks at each country: the worldwide splits plus all territory splits that cover one country may not exceed 100%.

A territory that contract splits still use cannot be deleted: its bin is disabled and the tooltip says why. Change those splits first. Otherwise the bin opens **Delete {territory name}?**.

## Prorated contracts for compilations

A compilation or bundle earns as one product, but the money belongs to several deals. A prorated contract divides its income between other contracts by weight, and each of those pays its own splits.

1. Create the contracts of the individual deals as usual.
2. Create one more contract for the compilation.
3. In the panel **Prorated across other contracts**, choose **Add contract** for each deal and give it a **Weight**. The form shows the resulting percentage next to each line. A good weight is the number of tracks each deal contributes.
4. Set the compilation contract on the tracks of the release.

While a contract has contracts in this panel, its own splits are ignored.

Example: a compilation earns 400. Contract A (weight 3) pays Example Artist 50%. Contract B (weight 1) pays Second Payee 40%.

|             | Share of the 400 | Split | Payee gets         |
| ----------- | ---------------- | ----- | ------------------ |
| Contract A  | 3 of 4 = 300     | 50%   | Example Artist 150 |
| Contract B  | 1 of 4 = 100     | 40%   | Second Payee 40    |
| Label keeps |                  |       | 210                |

A contract cannot be prorated across itself, directly or through other contracts.

## Agreement templates

**Deals → Agreement templates** holds the texts your written agreements are made from. A template is the label's own text: the label answers for what it says. This application gives no legal advice and guarantees no legal compliance: not for the tool, not for signing electronically, and not for any text, example or template in it. Whether an agreement signed here is valid and enforceable depends on the law that applies to you. Electronic signatures are not accepted for every kind of agreement or in every country. Clear the use of this tool and of your texts with your legal adviser before you rely on them.

### The examples

Under **Examples** the application ships three texts: a remix agreement where the label commissions the remix, a remix agreement where the original artist commissions it and the label releases it, and a release agreement between an artist and the label.

They are examples only. They are not legal advice, come without any warranty, were not written or reviewed by a lawyer and may be wrong, incomplete or unenforceable. They are drafted with German law and a German GmbH as the label in mind and do not fit other countries or legal forms without changes. Do not use one before a qualified lawyer has reviewed and adapted it for you.

Nothing is ever sent from an example. **Read** shows its text. **Copy to my templates** makes a copy that is yours, after you tick **I understand that this is an unreviewed example**.

### Your own templates

**New template** takes a **Name** and the **Text**. The pencil opens a template to change it; the bin deletes it.

The text can leave open what differs from deal to deal, as a field in double braces such as `{{ share }}` or `{{ payee.legal_name }}`. **Fields an agreement fills in**, below the text, lists every field. A text that uses a field that does not exist is not saved.

## Worked example

Example Records accounts in EUR and has one release, Example Release (EX001).

Setup:

- Ledger "EX001 advance".
- Contract "EX001 album deal", set on all tracks of the release: Example Artist 50% on ledger "EX001 advance", Second Payee 10% on the primary ledger. The label keeps 40%.
- Contract "EX001 remix deal", assigned to one track, the remix: Example Artist 25% and Second Payee 25%, both on the primary ledger. The label keeps 50%.

Transactions paid in the period:

| Transaction                                                              | Amount |
| ------------------------------------------------------------------------ | ------ |
| Income on the release and its other tracks                               | 1,000  |
| Expense "Mastering" on the release, recouped from payees                 | −200   |
| Income on the remix track                                                | 240    |
| Transfer "Advance" to Example Artist, release selected, ledger automatic | −500   |

Album deal: net is 1,000 − 200 = 800.

|                | Split | Share | Ledger        |
| -------------- | ----- | ----- | ------------- |
| Example Artist | 50%   | 400   | EX001 advance |
| Second Payee   | 10%   | 80    | Primary       |
| Label keeps    | 40%   | 320   |               |

Remix deal: net is 240. The remix track's own contract is used for it, not the album deal.

|                | Split | Share | Ledger  |
| -------------- | ----- | ----- | ------- |
| Example Artist | 25%   | 60    | Primary |
| Second Payee   | 25%   | 60    | Primary |
| Label keeps    | 50%   | 120   |         |

Ledger balances at the end of the period:

| Payee          | Ledger        | Share | Transfers | Balance           | Payable |
| -------------- | ------------- | ----- | --------- | ----------------- | ------- |
| Example Artist | Primary       | 60    | 0         | 60                | 60      |
| Example Artist | EX001 advance | 400   | −500      | −100 (unrecouped) | 0       |
| Second Payee   | Primary       | 140   | 0         | 140               | 140     |

Example Artist is paid 60 now. The −100 stays on "EX001 advance" and is the starting balance of that ledger in the next period: the next 100 that the album deal earns for Example Artist goes towards it before anything from that ledger becomes payable.

Check: net income is 1,000 − 200 + 240 = 1,040. Payees earn 400 + 80 + 60 + 60 = 600, and the label keeps 320 + 120 = 440.

## Good to know

- Splits are stored with nine decimals and amounts are calculated with fifteen, so streaming lines worth fractions of a cent are not rounded away.
- Deleting a contract removes its splits. Tracks, items and transactions that used it fall back to the next contract in the order above, or to none. Statements already generated keep their figures.
- A territory split is checked line by line against the **Country** of each transaction. Income without a country earns nothing for a territory split, and an expense without a country is not charged to it either. When you enter an expense by hand that a territory-limited payee should carry their share of, fill in its **Country**.
- A recoupable expense is deducted from the release or track it is booked on. An expense booked on the whole release is divided between its tracks like a sale of the release, so a track with its own contract carries its part.
- A transfer with **Ledger** on **Automatic** uses the first split the payee has in the contract of a track of the selected release. Without a release, or when the payee has no split there, it lands on the primary ledger.
- Editing a contract changes the live figures of every period, including past ones. Only generated statements are frozen.
- Prorated contracts can be nested, but only up to five levels below the top contract. Anything deeper pays nothing.
- The **Email** field of a payee is never used to send anything.
