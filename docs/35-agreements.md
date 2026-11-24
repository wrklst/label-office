# Agreements

This page covers written agreements with payees: drafting one from a template, sending it for signature, how it is signed online, what the payee delivers afterwards, agreements signed outside the application, and what is kept as proof.

**No legal guarantee.** This application gives no legal advice and guarantees no legal compliance: not for the tool, not for signing electronically, and not for any text, example or template in it. Whether an agreement signed here is valid and enforceable depends on the law that applies to you. Electronic signatures are not accepted for every kind of agreement or in every country. Clear the use of this tool and of your texts with your legal adviser before you rely on them.

The text of an agreement is yours: see [Agreement templates](30-contracts-payees-and-ledgers.md#agreement-templates). The application records who agreed to which text and when, and nothing more.

## What an agreement is, and what it is not

An agreement is the document the parties sign. A contract under **Deals → Contracts** is the calculation: it holds the splits that statements are made from. The two are separate records. Signing an agreement does not change any split on its own: you make the contract from it with one click, see [The royalty contract](#the-royalty-contract).

## Before the first agreement

1. Under **Settings → Label**, fill in **Legal entity and signatories**: tick who has the right to sign for the label and choose whether all of them are needed or one is enough. See [Label settings](41-label-settings.md#legal-entity-and-signatories).
2. Under **Deals → Agreement templates**, have at least one template of your own.
3. Give the payee a **Legal name** and **Address** under **Deals → Payees**, and access under **Settings → Team**, so they have an account to sign with.

## Drafting

Open **Deals → Agreements** and choose **New agreement**.

| Field                     | Meaning                                                                                                                                         |
| ------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| **Name**                  | For your own lists. It is not part of the text.                                                                                                 |
| **Template**              | The text the agreement is made from.                                                                                                            |
| **Who grants the rights** | **The label**, or **An artist of the label, as a third party**. For a remix: who commissions it and holds the rights in the original recording. |
| **Artist**                | Only for the second case: the payee who grants the rights. They sign too.                                                                       |
| **Payee**                 | The other party: the remixer, or the artist of a release.                                                                                       |
| **Release**, **Track**    | What the agreement is about. A release is needed when the agreement names deliverables: their files are filed with it.                          |
| **Terms**                 | What the chosen template leaves open, such as the share, the titles and the deliverables. Only the fields the template uses are asked.          |

The label is a party to every agreement, also when an artist grants the rights: it releases the music and pays the share. Choose a template that is written for the case you pick.

The page of a draft shows its text as it stands. A field that is still empty shows in square brackets, such as `[share]`. The text of a draft follows the template, the payee and the label's legal details: change any of them and the draft changes with it.

## Sending for signature

**Send for signature** is offered once nothing is missing. Otherwise the page says what is: an empty field, a deleted template, or nobody who signs for the label.

Sending freezes the agreement:

- The text is stored as it is at that moment, with its SHA-256 hash. Later changes to the template, the payee or the label do not reach it.
- The people who sign for the label are fixed as they are at that moment.
- Everyone who can sign through an account is emailed that an agreement is waiting.

A sent agreement cannot be edited or deleted. **Withdraw** ends it while it is out for signature: it can then no longer be signed. To change something, withdraw it and draft a new one. An agreement that everyone has signed cannot be withdrawn here: ending it is a matter between the parties.

## Who has to sign

- **For the label:** the people ticked under **Signs for the label** when the agreement was sent: all of them, or any one of them, as **What the label needs on an agreement** said at that moment. An admin who is not ticked cannot sign for the label. The page of a draft names who is needed before you send it.
- **The payee:** one person whose account is linked to the payee, see below.
- **The artist:** likewise, when an artist grants the rights.

An account is linked to a payee under **Settings → Team**: a member with the access **Payee** through **Payee**, an admin who is also an artist of the label through **Also a payee**.

Someone who signs for the label cannot also sign the same agreement as its payee or artist, unless **May also sign the other side** is ticked for them under **Settings → Label**. The same person on both sides is self-dealing, which German law (§ 181 BGB) only allows with an exemption. Without one, another person has to sign one of the sides.

## Signing

A payee finds the agreement under **My agreements** in the sidebar, or through the link in the email. Admins open it from **Deals → Agreements** with **Read and sign**.

The page shows the whole text, who has signed so far, and under **Sign**:

1. The role you sign in, when you have more than one.
2. **Your full name**, typed.
3. The confirmation **I have read this agreement and want to be bound by it**.
4. **Code from your email**: choose **Email me a code** and enter the six digits. The code works once and for 15 minutes.

Choosing **Sign** records the signature. Once every party has signed, the agreement reads **Signed by everyone** and each of them is emailed a copy as a PDF, with the same as a text file next to it.

**PDF**, at the top of a sent agreement, downloads the text with the signatures given so far. Admins find the same button under **Deals → Agreements**.

## Deliverables

What the payee has to hand in is entered under **Deliverables** in the terms, one item per line, for example:

```
Master as WAV, 24 bit, 44.1 kHz, not limited
Artwork, 3000 × 3000 px
Short biography
```

The lines are part of the text of the agreement where the template has the field `{{ deliverables }}`. The page of a draft lists them under **To be delivered**. An agreement with deliverables needs a **Release**: it is not sent without one.

Sending makes one item of every line. Once everyone has signed:

1. The payee opens the agreement under **My agreements** and drops one file on each item under **What you deliver**. Large files such as masters go straight to the file storage where the label uses a bucket, see [Files, audio and delivery](22-files-audio-and-delivery.md).
2. The file is filed with the release at once, where you find it among the other files of the release. An audio file is attached to the track of the agreement. A picture is filed as artwork: it never replaces the cover.
3. On the agreement, under **Deliverables**, the item reads **Delivered** with a link to the file. **Accept** says it is what was agreed. **Hand back** asks for it again with a note: the payee is emailed the note and can deliver once more.

A file that was handed back stays with the release; delete it there if you do not want it. An accepted item can no longer be replaced by the payee. To reopen it, hand it back.

## Agreements signed outside the application

**File a signed agreement**, on **Deals → Agreements**, is for an agreement that was signed elsewhere, such as an older one on paper. Enter a **Name**, the **Payee**, the day it was **Signed on** and upload the **Signed copy** (PDF, JPEG or PNG, up to 20 MB). The copy is the agreement: there is no text, no hash and nothing is sent to anyone. The payee finds it under **My agreements** and can download it.

**Signed elsewhere**, on an agreement that is out for signature, closes it with a copy the parties signed on paper or with another service, after you confirm that every party has signed it. The agreement then counts as signed by everyone and can no longer be signed here. Signatures already given here stay listed. Use this where an agreement needs more than a simple electronic signature.

The application does not check a copy: that it is complete, genuine and signed by the right people is for you to make sure.

## The royalty contract

On an agreement that everyone has signed, **Create the contract** under **Royalty contract** makes a contract named after the agreement with one split: the payee at the agreed share. It is set on the linked track, or else on the linked release.

A track or release that already has a contract keeps it. The new contract is still created, and the message says that it was not set: decide yourself which one applies. Without a linked track or release the contract is created and set nowhere.

The contract is made once. After that the panel shows **Open the contract**. It is an ordinary contract: add further splits or change it under **Deals → Contracts**, see [Contracts, payees and ledgers](30-contracts-payees-and-ledgers.md).

## What is kept as proof

For every signature: the typed name, the email address of the account, the role, the time, the internet address and browser, and the hash of the text that was signed. A signature is only accepted for the exact text the signer had open.

The copy sent to every party holds the text, the list of signatures and the hash. Anyone can check the hash: it is the SHA-256 of the text above the line in the text file. The PDF shows the same text and signatures for reading and filing; it is made from the stored record each time and carries no cryptographic signature of its own.

This is a simple electronic signature. It is not a qualified electronic signature and does not replace the written form where a law demands it. Whether this is enough for your agreements, in your country and in the country of the other party, is a question for your legal adviser: the application does not claim that it is.

## Good to know

- The page where a payee signs says that the text is the label's and that the application guarantees nothing about its validity where they live.
- An agreement is only sent to people who have an account. A payee without access under **Settings → Team** cannot sign.
- Asking for a signing code replaces an unused sign-in code of the same account.
- A payee who is linked to payees of several labels sees the agreements of all of them under **My agreements**.
