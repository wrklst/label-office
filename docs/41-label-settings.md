# Label settings

What a label decides for itself about its name, images, accounting and territories is under **Settings → Label**, open to admins of the label. Nothing here needs access to the server. This page goes through the sections of that screen in the order the page has them: **Logo and signet**, **Details**, **Accounting features**, **Legal entity and signatories** and **Territories**. The services the label works with, their options and the logins of outside services are on their own page: **Settings → Connections**, see [Connections](42-connections.md).

## Logo and signet

Two images of the label, each uploaded by dropping a file on its field or choosing one. PNG, JPEG or WebP, up to 4 MB.

| Image      | Where it is shown                                                     |
| ---------- | --------------------------------------------------------------------- |
| **Logo**   | At the top of statements, for you and for payees in **My statements** |
| **Signet** | The small square mark in the sidebar, next to the label's name        |

Dropping a new file replaces the stored image. The bin removes it, after a confirmation. A transparent PNG works on light and dark backgrounds. SVG files are not accepted.

## Details

| Field                   | What it does                                                                                                                                             |
| ----------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Label name**          | Shown in the label switcher, on statements and in emails.                                                                                                |
| **Accounting currency** | The currency all totals are shown in. Changing it does not convert existing transactions, so it can only be changed while the label has no transactions. |
| **Contact email**       | The label's contact address. It starts as the address of whoever created the label.                                                                      |

## Accounting features

Three optional tools. Each one adds an entry under **Accounting** in the sidebar. Nothing is calculated on its own: you run a tool for a range of paid dates and it books ordinary transactions. The extra settings of a tool appear when its box is ticked.

| Feature         | Extra settings                                                                                                                                                                                                          |
| --------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Mechanicals** | **Include physical sales**: count sold physical copies as units, next to downloads. **Statutory rate conversion**: what one US dollar is in the label's currency, needed when the label does not account in US dollars. |
| **PPD**         | none                                                                                                                                                                                                                    |
| **Reserves**    | none                                                                                                                                                                                                                    |

**Ignore negative units** is shown once Mechanicals or PPD is ticked and applies to both: by default returns reduce the units they are calculated on.

The tools are explained in [Mechanicals, PPD and reserves](34-mechanicals-ppd-and-reserves.md).

## Legal entity and signatories

Who the label is when it is a party to an agreement, and whose signatures it needs. The section saves with its own **Save** button.

| Field              | Meaning                                                                                           |
| ------------------ | ------------------------------------------------------------------------------------------------- |
| **Legal name**     | The name as registered, for example `Example Records GmbH`.                                       |
| **Legal form**     | **GmbH**, **GbR** or **Other**.                                                                   |
| **Address**        | The address of the company.                                                                       |
| **Register entry** | Court and number, for example `Amtsgericht Charlottenburg, HRB 123456`. Empty when there is none. |

Under **Signs for the label**, tick every admin whose signature the label needs. All of them are needed, not any one of them: a GmbH with one managing director ticks that one person, a GbR whose partners only sign together ticks every partner. Only admins are listed, and someone who stops being an admin stops signing.

An admin account is not authority to sign. Tick only people who are entitled to represent the label: the managing directors of a GmbH as registered, the partners of a GbR as its partnership agreement says.

**May also sign the other side** appears for each person who signs. Tick it only when that person is exempt from the ban on self-dealing (§ 181 of the German Civil Code), for example by the articles of the company and its register entry. Without it, the same person cannot sign one agreement both for the label and as the other party to it.

## Territories

Groups of countries for contract splits that only apply in part of the world. A split without a territory applies everywhere.

1. Enter a **Territory name**, for example `GSA`.
2. Enter the **Countries** as two-letter codes: `DE AT CH`.
3. Choose **Add territory**.

Separate the codes with spaces or commas. The bin next to a territory opens **Delete {name}?**. A territory that contract splits refer to cannot be deleted, and its bin is disabled with the reason: without it those splits would apply worldwide and pay out on every sale. Change the splits first. See [Contracts, payees and ledgers](30-contracts-payees-and-ledgers.md).

## Other settings pages

| Page                                                 | What is there                                                                                                                                                                                                                                                       |
| ---------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Settings → Team**                                  | Who has access to the label, and as what. See [Team, roles and sign-in](40-team-roles-and-sign-in.md).                                                                                                                                                              |
| **Settings → Connections**                           | The services the label works with (Zebralution, Bandcamp, Subvert, Rightz Audio) with their options, the web search with the airplay research countries, receipt reading and, for the installation's admin, the file storage. See [Connections](42-connections.md). |
| **Settings → Backups**                               | The nightly backup of the database and the files: when it runs, how many are kept and which bucket they go to; only for the installation admin. See [Deployment](04-deployment.md#the-nightly-backup).                                                              |
| **Settings → Updates**                               | The version the installation runs and how it gets new ones; only for the installation admin. See [Installation](02-installation.md#updating) and [Team, roles and sign-in](40-team-roles-and-sign-in.md#roles-and-security).                                        |
| **Settings → Profile**, **Security**, **Appearance** | Your own account: name, email, password, passkeys, two-factor authentication, light or dark mode.                                                                                                                                                                   |

## Good to know

- Settings belong to one label. In an installation with several labels each has its own currency, its own services and its own connections.
- Categories and formats are the same for every label of an installation; they are not editable here.
- What the server itself needs (database, mail) is not in the interface: see [Configuration](03-configuration.md). Where files are stored is set under [Connections](42-connections.md) by the person who runs the installation.
