# Team, roles and sign-in

This page covers how accounts and labels are created, what admins and payees can see, how you give team members and payees access, what only the installation admin may do, and the ways to sign in and protect an account.

## Accounts: nobody can register on their own

There is no sign-up page. An account comes into existence in one of two ways:

- someone with access to the server creates it on the command line, or
- an admin adds the person to a label under **Settings → Team**.

### Creating the first account

Run this on the server, in the directory of the installation:

```sh
php artisan royalties:create-user owner@example.com --name="Example Owner"
```

| Part                | Meaning                                                               |
| ------------------- | --------------------------------------------------------------------- |
| `owner@example.com` | The address the person signs in with.                                 |
| `--name=`           | Their name. Without it, the part of the address before the @ is used. |

The command answers with the address of the sign-in page. It refuses an address that is not valid or already has an account.

The account is made to set up a label: its owner may create one, see [Creating a label](#creating-a-label). The first account an installation gets also becomes the installation admin, see [Roles and security](#roles-and-security).

The new account has no password that anyone knows. The person signs in with a one-time code sent by email, see [Sign-in with an emailed code](#sign-in-with-an-emailed-code). That requires working mail, see [Configuration](03-configuration.md). More on commands in [Command line](50-command-line.md).

## Creating a label

Someone whose account was made with the command above, and who belongs to no label yet, is taken to **Set up your label** when they sign in. In the sidebar the switcher reads **No label yet** until the label exists.

1. Enter the **Label name**.
2. Enter the **Accounting currency** as a three-letter code such as `EUR`. Statements and balances are kept in this currency.
3. Choose **Create label**.

You become the admin of the new label, and your email address is stored as its contact address. Name, currency and contact address can be changed later under **Settings → Label**; the currency only until the label has transactions.

Someone who belongs to no label and was not made with the command, for example a person who was invited to a label and then removed from it, cannot create one. They see **You are not in a label** with **Ask the label to add you again** and **Log out**: the label's admins have to add them again under **Settings → Team**.

## Several labels in one installation

One installation can hold any number of labels. Each has its own catalog, contracts, transactions, statements and team. Nothing is shared between labels, and lists only ever show records of the label you are working in.

The **label switcher** is the button at the top of the sidebar. It shows the current label and, below it, its currency. Open it to:

- switch to another label you run,
- choose **New label** to create another one. The entry is shown to people who are an admin of a label, and to someone made with the command who has no label yet. It is not shown to people who were only invited as payees.

One account can belong to several labels with a different role in each, for example admin of your own label and payee of a label that releases your music. The switcher lists only the labels you run. The labels you are a payee of are never "worked in": you read them in **My statements**, whichever label you are working in.

Someone who is only a payee has no label switcher. The top of the sidebar shows their own name with **Payee** below it, and **My statements** is the only entry. Someone who runs a label and is a payee elsewhere keeps the switcher and finds **My statements** in a sidebar group of its own, titled **As a payee**.

## Roles

A role belongs to the combination of a person and a label. There are two.

|                                                 | Admin                | Payee               |
| ----------------------------------------------- | -------------------- | ------------------- |
| Dashboard, catalog, releases, files             | Yes                  | No                  |
| Imports, transactions, periods, payouts         | Yes                  | No                  |
| Contracts, payees, ledgers                      | Yes                  | No                  |
| Profitability, overheads, optional tools        | Yes                  | No                  |
| All statements, drafts and live figures         | Yes                  | No                  |
| **Settings → Label**, **Team**, **Connections** | Yes                  | No                  |
| Handbook and the **?** button                   | Yes                  | No                  |
| Own **published** statements and their download | Yes, for every payee | Yes, only their own |
| Payouts recorded for them                       | Yes, for every payee | Yes, only their own |
| Own **Profile**, **Security**, **Appearance**   | Yes                  | Yes                 |

**Admin** means full access to everything in that label. There are no finer permissions: every admin can see all contracts and balances, change settings and manage the team.

**Payee** means access to **My statements** and nothing else. A payee who opens any other page of the label is sent back to **My statements**. In every label a payee user is linked to exactly one payee record, and that link decides whose statements they see in that label. A payee role without a linked payee gives access to nothing. Someone who runs one label and is a payee of another is a full admin of the first and sees nothing of the second beyond their own published statements.

A label always keeps at least one admin: the last admin cannot be removed or turned into a payee.

Besides the role, one account in the whole installation is the **installation admin**. That is not a third role of a label: see [Roles and security](#roles-and-security).

## Inviting team members and payees

1. Open **Settings → Team**.
2. Under **Add a person**, enter the **Email**. **Name** is optional and only used when a new account is created.
3. Choose the **Access**:
    - **Admin – full access**, or
    - **Payee – own statements only**. Then also pick the **Payee** whose statements this person may see.
4. Choose **Add to label**.

What happens next:

- If the address has no account yet, one is created.
- The person receives an email saying that the label gave them access, with a **Sign in** button. The button leads to the code sign-in page with their address filled in. The link does not expire.
- They need no password. They ask for a code, enter it and are in.
- The email differs a little by role: an admin reads that they can manage the label's catalog, transactions, contracts and statements, a payee that they can read their statements and payouts.

Someone who already has access to the label is not added again; the page says "This person already has access to the label." An existing account whose email address was never confirmed is locked before it is added, see [Roles and security](#roles-and-security).

### Linking a user to a payee

A payee record under **Deals → Payees** and a user account are two different things. The payee record holds contracts and balances; the user account lets a person sign in. The link between them is made on the team page:

- when adding the person, through the **Payee** field, or
- later, with **Change** next to their name.

The list shows each member as **Admin** or as **Payee** followed by the linked payee's name.

To let several people see the same payee's statements, for example an artist and their manager, add each of them as a payee user linked to the same payee.

### Changing and removing access

- **Change** switches a member between admin and payee or links them to a different payee. Making someone an admin removes their payee link.
- The bin next to a person opens **Remove {name} from {label}?**. **Remove access** takes the person out of this label: they can no longer sign in to it. Their account and their access to other labels stay.

## My statements, the payee's area

A payee who signs in sees one menu entry, **My statements**, and their own name at the top of the sidebar. The page covers every label they are a payee of, so they never switch labels: each label is a section with its name and logo as the heading, in the label's currency. Amounts of different labels are never added up. Labels are listed by name; a label that has not published anything for them yet is listed with "Nothing published yet". Everything on the page comes from statements the labels have published. Live figures, drafts and archived revisions never reach **My statements**.

Each section of a label has the parts below. Payouts and statements are listed 25 to a page, newest first, and each list has its own page and can be sorted.

| Section                  | What the payee sees                                                                                                                                                                              |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Owed to you**          | What was still payable according to their latest published statement. If payouts were recorded after that statement was generated, they are shown next to it as paid since and not deducted yet. |
| **Earned in …**          | Their share in the period of that statement, after expenses and the contract split.                                                                                                              |
| **Paid out for …**       | The payouts for that period contained in the statement, with a link to open it.                                                                                                                  |
| **Balance per ledger**   | The balance of each of their ledgers. A ledger below zero is marked **Unrecouped**.                                                                                                              |
| **Payouts**              | Every payment the label recorded for them: date, period, memo and amount. Payouts appear as soon as they are recorded, whether or not a statement mentions them yet.                             |
| **Published statements** | Their published statements with period, publication date, balance and payable amount.                                                                                                            |

Opening a statement, also one of a label you are not currently working in, shows the full breakdown: a summary, one tab per ledger, and the income, expenses, transfers and payouts behind the figures. **Download CSV** saves it as a file. The content of a statement is described in [Periods, statements and payouts](32-periods-statements-and-payouts.md).

Before the first statement of a label is published, its section says "Nothing published yet". When someone is removed from a label under **Settings → Team**, that label's section and its statements are gone from **My statements** at once. If none of the labels a person is a payee of has a linked payee for them, for example because the payee record was deleted, the page says "Your access is not linked to a payee yet"; link them again on the team page.

### My statements for an admin

An admin opens the same page with the **My statements** button on **Accounting → Statements** or, for someone who is also a payee of another label, from the sidebar group **As a payee**. What they see depends on whether they are a payee elsewhere:

- **Only an admin:** the page previews what the label's payees see: every published, not archived statement of the label, 25 to a page, with the columns **Payee**, **Period**, **Published**, **Balance** and **Payable**. A **Period** and a **Payee** filter narrow it down, and every column sorts. Each payee only gets their own statement.
- **Also a payee of another label:** the page has two sections. **Your statements as a payee** is the payee view above for every label they are a payee of. **What the payees of {label} see** is the preview for the label they work in.
- An admin whose payee role elsewhere has no linked payee yet sees the preview only.

Opening a statement from the preview shows it as the payee sees it.

## Roles and security

What an operator needs to know in one place.

### The installation admin

One account in the installation looks after the installation itself. It is the account that `royalties:create-user` made first, when no installation admin existed; on an installation that is older than this setting it is the account with the lowest id. Only the installation admin may:

- choose where files are stored, on the **File storage** card under **Settings → Connections**,
- see **Settings → Backups**, and set the nightly backup and its bucket there,
- see **Settings → Updates**, and install or schedule an update there,
- enter a **Base URL** for receipt reading that is `http://` or that points to this server or its network, for a model you host yourself. Everyone else has to give an `https://` address on the internet, see [Connections](42-connections.md#receipt-reading).

The installation admin sees these pages only while they are also an admin of the label they work in, because they sit inside **Settings**.

The role is handed over on the server, in the directory of the installation:

```sh
php artisan royalties:hand-over-installation new-owner@example.com
```

The account has to exist. It becomes the installation admin and the previous one stops being it; only one account has the role at a time. Deleting the only installation admin's account is refused until that is done: **Settings → Profile → Delete account** answers "You are the installation admin. Hand the role over to another account first: run php artisan royalties:hand-over-installation with their email address on the server." Details in [Command line](50-command-line.md#royaltieshand-over-installation).

### Who may open a label

- A person works only in labels they belong to. Switching to any other label is refused.
- Someone who runs a label always works in one they run. The labels they are only a payee of are read in **My statements** and never become the label they work in.
- Access ends at the person's next click. When an admin is taken out of the label they are working in, or turned into a payee, they are moved to another label they run; a payee goes to **My statements**, and what they could do as an admin is refused.
- Someone who is in no label any more sees **Set up your label** if their account was made to run one, and otherwise **You are not in a label**, see [Creating a label](#creating-a-label).

### Inviting an account nobody has proven

An account whose email address was never confirmed, for example because the address was changed and the link not used, may belong to someone else than the address suggests. When an admin adds such an account to a label, its password is replaced by an unknown one, its passkeys and two-factor authentication are removed and all its sessions end. Only a sign-in code sent to the address opens it from then on. The same happens when someone signs in with a code before the address was confirmed. Accounts with a confirmed address are not touched.

### Limits on repeated requests

| What                                                  | Limit                                                                    |
| ----------------------------------------------------- | ------------------------------------------------------------------------ |
| Requests for a sign-in code                           | 5 per email address and 20 per network address in 15 minutes             |
| Wrong sign-in codes                                   | 10 per email address in an hour and 30 per network address in 15 minutes |
| Password sign-in                                      | 5 a minute per email address and network address                         |
| Changing a password                                   | 6 a minute                                                               |
| Invitations under **Settings → Team**                 | 30 an hour                                                               |
| Connecting or changing the model of receipt reading   | 10 a minute                                                              |
| Uploading receipts, and **Read with AI** on a receipt | 60 and 30 a minute                                                       |
| Starting an airplay research                          | 10 a minute                                                              |
| Delivery packages                                     | 12 a minute                                                              |

### The handbook

The handbook and the **?** button that opens the page for the screen you are on are for admins. A payee is sent to **My statements** instead.

## Signing in

The sign-in page offers three ways in. All of them lead to the same account.

### Sign-in with a password

Enter **Email address** and **Password** and choose **Log in**. **Remember me** keeps you signed in on that browser.

Accounts created by the command or by an invitation start without a known password. To get one, sign out and use **Forgot your password?** as described under [Password reset](#password-reset).

### Sign-in with an emailed code

1. On the sign-in page choose **Email me a sign-in code**.
2. Enter your **Email address** and choose **Email me a code**.
3. Type the 6-digit code from the email into **Sign-in code** and choose **Sign in**.

Details:

- A code is valid for 15 minutes and works once.
- A code allows five attempts. After that, ask for a new one with **Send a new code**.
- Asking for a new code makes the earlier one useless.
- The page always answers "If an account exists for this address, we sent a code." It does not reveal whether an address has an account.
- Requests are limited: after five requests for one address within 15 minutes you have to wait.
- **Code sign-in is switched off for accounts with two-factor authentication.** Such an account gets an email explaining that no code was sent and has to sign in with its password.

### Sign-in with a passkey

A passkey is a key stored on your device or in your password manager, unlocked by fingerprint, face or device PIN.

To add one:

1. Open **Settings → Security**. You are asked to confirm your password first.
2. Under **Passkeys**, choose **Add passkey**.
3. Give it a **Passkey name** such as "MacBook Pro" and choose **Register passkey**. Your browser or device takes over from there.

To use it, choose **Sign in with a passkey** on the sign-in page. You can register several passkeys and delete each of them in the same place. The button only appears in browsers that support passkeys.

## Two-factor authentication

Two-factor authentication asks for a code from an authenticator app in addition to your password.

1. Open **Settings → Security** and choose **Enable 2FA** under **Two-factor authentication**.
2. Scan the QR code with your authenticator app, or enter the setup key by hand.
3. Enter the 6-digit code the app shows to confirm.
4. Choose **View recovery codes** and store the codes somewhere safe, such as a password manager.

From then on, signing in with your password is followed by a page asking for the **Authentication code**. If you have lost the device, choose **login using a recovery code** on that page. Each recovery code works once; **Regenerate codes** creates a new set.

**Disable 2FA** switches it off again.

## Password reset

1. On the sign-in page choose **Forgot your password?**.
2. Enter your **Email address** and choose **Email password reset link**.
3. Open the link in the email, enter the new password twice and choose **Reset password**.

The link is valid for 60 minutes.

To change a password you know, open **Settings → Security**, enter the **Current password** and the **New password** twice, and choose **Save**.

On a production installation a password needs at least 12 characters with upper and lower case letters, numbers and symbols, and must not appear in known data leaks.

## Profile and appearance

Open the settings through your name at the bottom of the sidebar, then **Settings**.

- **Profile**: change your **Name** and **Email address**. A changed address counts as unverified, and the label pages are not available to you until it is verified. A confirmation link is sent to the new address when you save; **Resend verification email** on the page you are shown sends another one.
- **Profile → Delete account**: deletes your account for good after you enter your password. It is refused while you are the only admin of a label (make someone else an admin under **Settings → Team** first) and while you are the installation admin (hand the role over first, see [Roles and security](#roles-and-security)).
- **Appearance**: choose **Light**, **Dark** or **System**, which follows your device.

Payees see **Profile**, **Security** and **Appearance** only. Admins also see **Label**, **Team** and **Connections**, and the installation admin **Updates**. The Bandcamp page is reached from the Bandcamp card under **Connections**.

## Good to know

- Invitations, sign-in codes and reset links are emails. If mail is not configured or the queue worker is not running, nobody new can sign in. See [Configuration](03-configuration.md) and [Troubleshooting](52-troubleshooting.md).
- **Settings → Security** and **Delete account** ask for your password. An account that has only ever used codes has to set a password through **Forgot your password?** first.
- The **Email** field of a payee record under **Deals → Payees** gives no access and receives nothing. Access always goes through **Settings → Team**.
- Publishing a statement sends no notification. Tell your payees when there is something to read.
- Someone invited only as a payee cannot create a label, also not after being removed from the label. Someone who runs a label can create further ones with **New label**.
- A label always keeps at least one admin: neither the team page nor **Delete account** lets its last admin go.
- Removing someone from the team does not delete their account, and deleting a payee record does not remove the user: they keep signing in and, when no other label gives them a linked payee, see the "not linked" notice until you link or remove them.
- Passkeys are bound to the web address of the installation. If the address changes, they stop working and have to be registered again.
- Repeated failed attempts are limited: five password attempts a minute per email address and network address, and after ten wrong codes for one email address code sign-in pauses for up to an hour.
- Signing in with an emailed code while your email address is unverified verifies the address, and also replaces your password with an unknown one and removes your passkeys and two-factor authentication: nobody has proven yet that the address is theirs. After changing your address, use the confirmation link you were sent instead.
