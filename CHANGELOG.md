# Changelog

## 1.0.0 - 2026-10-18 (release candidate)

- First stable candidate for the separate Xertify Certificate activity.
- Adds an activity chooser entry, core course index support, standard Moodle access restrictions and optional view completion.
- Displays the current user's certificate with View, Download PDF and Wallet actions.
- Supports multiple activities per course, backup/restore and activity/course duplication.
- Requires Xertify Certificates (`local_xertify`) 1.1.0, build 2026101802 or later; validated on Moodle 4.1–5.2.
- Reads existing certificates through the shared service; does not directly issue certificates or store tokens or individual certificate metadata.
- Uses a null privacy provider for the module's own data; certificate associations belong to the local plugin.
