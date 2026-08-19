## CalDav module set

Read-only support for calendar events from a CalDav server. Servers must be
defined in the caldav.php file, and credentials are entered per user on the
Settings page. Events are discovered with RFC 6764 (`current-user-principal`
then `calendar-home-set`), so only the account root URL is needed.

Requires the `calendar` module set, and must be listed after it in
`CYPHT_MODULES`.

### Limitations

- Read-only. Events added on the Calendar page are still stored locally and are
  not written back to the server.
- Remote events are merged in at output time and are never written to user
  settings, so they cannot go stale in a local copy.
- Cypht repeats an event forever once an interval is set, so only an RRULE that
  genuinely runs forever is mapped to a repeat. Anything bounded by `UNTIL` or
  `COUNT`, or using an `INTERVAL` other than 1, is shown as a single event.
- `VTODO` and `VJOURNAL` components are ignored.
