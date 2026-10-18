# Xertify Certificate activity — development preview

`mod_xertifycertificate` is a course certificate viewer. Install this directory as
`mod/xertifycertificate`, independently of `local/xertify`. This local repository
has no remote and is not a published release.

Requires Moodle 4.1 (`2022112800`) and `local_xertify >= 2026101800`
(`1.1.0-dev`). The public `local_xertify` 1.0.3 (`2026091500`) does not provide the
shared service contract and is rejected by Moodle's dependency validation.
The module development version is `2026101801`, release `0.2.0-dev` (alpha).

## Teacher workflow

Enable editing, add **Xertify Certificate / Certificado Xertify** to a section,
and choose its name and description. Configure availability and optional view
completion using Moodle's standard form. API tokens, templates, groups and
issuance settings remain in Xertify Certificates (`local_xertify`).

This module only reads the current user's course certificate using
`local_xertify\local\certificate_service`. Opening the activity does not fetch a
PDF. View/download buttons request it on demand, with independent module login,
availability and capability checks. Wallet links come exclusively from the
shared service's HTTPS allowlist. The module has no certificate table, token,
PDF storage, background task, observer or emission code.

Its own table contains only `id, course, name, intro, introformat, timecreated,
timemodified`. Core owns activity completion and logs; the privacy provider is a
`null_provider` for this activity's own student data. Teacher-authored activity
content is course configuration. Certificate data remains the local plugin's
responsibility; its pending privacy work is not addressed here.

## Access policy and multiple activities

Each activity enforces its own standard Moodle restrictions. Multiple instances
show the same course/current-user certificate. Browser-supplied `userid`,
`issueid` and `certificatecode` are ignored; only `id` (cmid) and, for PDF,
`download` are read.

Without activities in a course, the local plugin's existing navigation and
certificate/PDF behavior remain unchanged. When activities exist, students can
use the legacy routes only if **at least one** instance is accessible and grants
`mod/xertifycertificate:view` (OR, not AND). Navigation links to that instance.
Legacy administrative access is retained for `local/xertify:manage`, consistent
with the existing ability to inspect another user's issue. Module routes still
show only the current user's certificate. Core managers retain Moodle's normal
availability/hidden-activity bypass privileges.

Restrictions protect Moodle routes. A previously downloaded PDF or known public
Wallet URL is outside Moodle's access control.

## Completion and circular dependencies

View completion means **activity viewed**, not issued, downloaded or verified.
Leave it optional. Do not make this activity a course completion criterion when
its availability depends on that course's completion: it can create a circular
dependency. The form includes this warning in English and Spanish.

The activity never directly emits. If administrators include it in course
completion criteria, normal core completion can eventually produce
`course_completed`, which the existing local observer handles. Avoid that
configuration; no special emission path is introduced here.

## Backup and compatibility limits

Backup, restore and core activity/course duplication are supported. The module
backs up only its name, intro, format, timestamps and intro files. Core manages
section/order, visibility, module settings, restrictions, completion configuration
and any user completion selected by the general backup options. The module has
no student-data backup structure. Authored intro content is copied normally.

Duplicating an activity keeps the same course/current-user certificate; deleting
the copy does not delete that certificate. Restoring or duplicating a course does
not copy certificates, issues, PDF payloads or tokens. Certificate data remains in
`local_xertify`. Its Phase 2 policy restores new course configuration disabled
with an empty token override, and preserves an existing destination configuration.
Review the destination course's Xertify settings before enabling integration.

In Moodle 4.5, a restored prerequisite is remapped to its new cmid. A same-course
activity duplicate retains its existing prerequisite. If the prerequisite is
omitted when restoring to another course, core changes the condition to `cm: 0`
and leaves the viewer inaccessible to students; review that restriction after
restore. This module does not replace core's dependency policy.

The backup encoder/decoder remaps authored links to the existing index, view and
PDF routes. Intro files are restored into the new module context. No emission
code, API client or local certificate data is used by these backup tasks.

The implementation uses Moodle 4.1 APIs and PHP 7.4-compatible syntax. Runtime
validation is Moodle 4.5.11+, PHP 8.3.23, MariaDB 10.11.13. A full Moodle 4.1–5.2
version matrix is still required. No custom JS or CSS is used. Web responsive
visual QA was verified in Chrome/Boost at desktop size and a 390 x 844 viewport
using a disposable synthetic site. Native Moodle App integration is out of scope.

## Tests

Install both development plugins in an isolated Moodle PHPUnit environment:

```sh
php -d max_input_vars=5000 admin/tool/phpunit/cli/init.php
php -d max_input_vars=5000 vendor/bin/phpunit --testsuite local_xertify_testsuite
php -d max_input_vars=5000 vendor/bin/phpunit --testsuite mod_xertifycertificate_testsuite
```

The module suite uses real course modules, users, capabilities, prerequisite
completion restrictions and controllers. Remote transport is mocked; an HTTP
sentinel detects accidental requests. Never run these fixtures against a real
Moodle database. See the Phase 3 report for separate synthetic HTTP controller
checks and the completed visual evidence.

Backup regression tests use final `.mbz` archives and real `backup_controller`,
`restore_controller`, `duplicate_module` and course duplication APIs. They scan
all extracted files for synthetic secret/certificate markers in both user-data
modes. Event, HTTP and issue-write guards plus a direct-dependency check protect
against issuance. Set `XERTIFY_PHASE4_EVIDENCE_DIR` to an existing external test
directory to export optional synthetic assertion summaries.
