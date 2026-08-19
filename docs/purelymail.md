# Purelymail with Cypht

Purelymail is available in the quick-add wizard on Settings -> Servers, and its
contacts and calendar can be reached over CardDav and CalDav.

## What is wired up

| Purelymail service | Cypht module | Status |
| --- | --- | --- |
| IMAP (`imap.purelymail.com:993`, implicit TLS) | `imap` | Quick add |
| SMTP (`smtp.purelymail.com:465`, implicit TLS) | `smtp` | Quick add |
| ManageSieve (`mailserver.purelymail.com:4190`, STARTTLS) | `sievefilters` | Quick add |
| CardDav (`https://purelymail.com`) | `carddav_contacts` | Read-only |
| CalDav (`https://purelymail.com`) | `caldav_calendar` | Read-only |
| WebDav file storage (`https://purelymail.com/webdav/`) | none | Not supported |

Cypht has no file storage feature, so Purelymail's 50 MB-per-file WebDav space
has nothing to connect to. That is a gap in Cypht, not in the Purelymail
configuration.

## Setup

1. Enable the optional module sets. `caldav_calendar` must come after
   `calendar`, and `carddav_contacts` after `contacts`:

       CYPHT_MODULES=core,contacts,local_contacts,carddav_contacts,feeds,imap,smtp,account,idle_timer,calendar,caldav_calendar,themes,nux,developer,history,saved_searches,advanced_search,highlights,profiles,inline_message,imap_folders,keyboard_shortcuts,tags,brute_force,sievefilters

2. Settings -> Servers -> Quick Add, choose Purelymail, and enter the full
   mailbox address with an app password. This creates the IMAP, SMTP and Sieve
   entries in one step.

3. For contacts and calendar, fill in the Purelymail rows under
   *CardDav Addressbooks* and *CalDav Calendars* on the Settings page. Use the
   same full address and app password.

The DAV URLs come from RFC 6764 discovery against `https://purelymail.com`, so
the per-account URLs shown at `/manage/cardDavUrl` and `/manage/calDavUrl` are
not needed and should not be pasted into the config.

## Custom domains

A Purelymail mailbox on a custom domain needs no special handling anywhere in
Cypht.

- **Hostnames never change.** `imap.purelymail.com`, `smtp.purelymail.com` and
  `mailserver.purelymail.com` serve every customer. Your domain never appears in
  a hostname, so one provider entry covers all of your domains.
- **The username is the full address.** The quick-add wizard stores whatever
  address you type as the IMAP and SMTP username without rewriting it, and
  nothing in Cypht inspects the domain part of an address to pick a server. An
  address at your own domain behaves exactly like one at `purelymail.com`.
- **DAV is per mailbox, not per domain.** One CardDav and one CalDav credential
  covers a mailbox no matter how many domains route mail into it.

The case worth planning for is one mailbox that receives at several addresses,
which is the normal shape when aliases, routing rules, or a catch-all point
multiple domains at a single Purelymail user. That is *one* account in Cypht,
not several. Add the extra addresses with the `profiles` module so you can pick
a From address when composing; do not add a second IMAP account for the same
mailbox.

Autoconfig, Autodiscover and RFC 6186 SRV records on your domain do nothing for
Cypht, which has no discovery code and configures servers from the tables above.
Those records only matter for native clients such as Thunderbird or a phone's
mail app.

## Credentials and the management portal

Purelymail keeps two separate credentials: the **Account**, which signs in to
the management portal, and a **User**, which is the mailbox. Cypht is only ever
given a User credential, so nothing stored in Cypht can reach the portal.

Use an app password rather than the User's own password. Purelymail's
documentation states an app password "cannot be used to log into the admin
portal or change your password", which keeps a Cypht database compromise away
from account changes. Note that Purelymail documents app passwords for IMAP and
POP3 clients and says nothing about SMTP or DAV; if a DAV login is rejected,
that is the reason, and the fallback is the User's password, which still cannot
reach the portal.

Because `https://purelymail.com` hosts both the DAV endpoints and the management
UI, keep the CardDav and CalDav server values pointed at the account root and
never at a `/manage/` path.

## Verified against the live service

Checked 2026-08-19 from the command line, unauthenticated:

- `imap.purelymail.com:993` and `smtp.purelymail.com:465` accept implicit TLS;
  `mailserver.purelymail.com` ports 143 and 587 accept STARTTLS. All present a
  certificate for `CN=purelymail.com` with a `*.purelymail.com` SAN.
- `mailserver.purelymail.com:4190` reports `Apache ManageSieve v1.0` with
  STARTTLS and SASL PLAIN, advertising `fileinto`, `vacation`, `imap4flags` and
  `subaddress`.
- `/.well-known/carddav`, `/.well-known/caldav` and `/webdav/` on
  `purelymail.com` all answer `401 WWW-Authenticate: Basic`, so discovery is
  present and takes Basic auth.
