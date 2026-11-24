# Agreements

This page covers written agreements with payees: drafting one from a template, sending it for signature, how it is signed online, and what is kept as proof.

The application gives no legal advice. Whether an agreement is valid and says what you mean depends on its text, which is yours: see [Agreement templates](30-contracts-payees-and-ledgers.md#agreement-templates). The application records who agreed to which text and when.

## What an agreement is, and what it is not

An agreement is the document the parties sign. A contract under **Deals → Contracts** is the calculation: it holds the splits that statements are made from. The two are separate records. Signing an agreement does not change any split on its own: you make the contract from it with one click, see [The royalty contract](#the-royalty-contract).

## Before the first agreement

1. Under **Settings → Label**, fill in **Legal entity and signatories** and tick who signs for the label. See [Label settings](41-label-settings.md#legal-entity-and-signatories).
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
| **Release**, **Track**    | Optional links to the catalog, for your own reference.                                                                                          |
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

- **For the label:** every person ticked under **Signs for the label** when the agreement was sent. All of them, not one of them.
- **The payee:** one person whose account is linked to the payee, see below.
- **The artist:** likewise, when an artist grants the rights.

An account is linked to a payee in one of two ways under **Settings → Team**: a member with the access **Payee** is linked through **Payee**; an admin who is also an artist of the label is linked through **Signs agreements as**.

Someone who signs for the label cannot also sign the same agreement as its payee or artist, unless **May also sign the other side** is ticked for them under **Settings → Label**. The same person on both sides is self-dealing, which German law (§ 181 BGB) only allows with an exemption. Without one, another person has to sign one of the sides.

## Signing

A payee finds the agreement under **My agreements** in the sidebar, or through the link in the email. Admins open it from **Deals → Agreements** with **Read and sign**.

The page shows the whole text, who has signed so far, and under **Sign**:

1. The role you sign in, when you have more than one.
2. **Your full name**, typed.
3. The confirmation **I have read this agreement and want to be bound by it**.
4. **Code from your email**: choose **Email me a code** and enter the six digits. The code works once and for 15 minutes.

Choosing **Sign** records the signature. Once every party has signed, the agreement reads **Signed by everyone** and each of them is emailed a copy as a text file.

**Print or save as PDF** prints the text and the signatures through the browser.

## The royalty contract

On an agreement that everyone has signed, **Create the contract** under **Royalty contract** makes a contract named after the agreement with one split: the payee at the agreed share. It is set on the linked track, or else on the linked release.

A track or release that already has a contract keeps it. The new contract is still created, and the message says that it was not set: decide yourself which one applies. Without a linked track or release the contract is created and set nowhere.

The contract is made once. After that the panel shows **Open the contract**. It is an ordinary contract: add further splits or change it under **Deals → Contracts**, see [Contracts, payees and ledgers](30-contracts-payees-and-ledgers.md).

## What is kept as proof

For every signature: the typed name, the email address of the account, the role, the time, the internet address and browser, and the hash of the text that was signed. A signature is only accepted for the exact text the signer had open.

The copy sent to every party holds the text, the list of signatures and the hash. Anyone can check the hash: it is the SHA-256 of the text above the line in that file.

This is a simple electronic signature. It is not a qualified electronic signature and does not replace the written form where a law demands it. Whether your agreements need more than this is a question for your lawyer.

## Good to know

- An agreement is only sent to people who have an account. A payee without access under **Settings → Team** cannot sign.
- Asking for a signing code replaces an unused sign-in code of the same account.
- A payee who is linked to payees of several labels sees the agreements of all of them under **My agreements**.
