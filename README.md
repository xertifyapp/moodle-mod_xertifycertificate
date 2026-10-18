# Xertify Certificate activity — development preview

`mod_xertifycertificate` is a course certificate viewer. Install this directory as
`mod/xertifycertificate`, independently of `local/xertify`. This local repository
has no remote and is not a published release.

Requires Moodle 4.1 (`2022112800`) and `local_xertify >= 2026101800`
(`1.1.0-dev`). The public `local_xertify` 1.0.3 (`2026091500`) does not provide the
shared service contract and is rejected by Moodle's dependency validation.
The module development version is `2026101800`, release `0.1.0-dev` (alpha).

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

`FEATURE_BACKUP_MOODLE2` is explicitly false. Activity backup, restore and core
activity duplication are not supported until Phase 4; manually creating a
second activity is supported and does not issue a second certificate.

The implementation uses Moodle 4.1 APIs and PHP 7.4-compatible syntax. Runtime
validation is Moodle 4.5.11+, PHP 8.3.23, MariaDB 10.11.13. A full Moodle 4.1–5.2
version matrix is still required. No custom JS or CSS is used. Web responsive
visual QA remains pending where browser access to the disposable site is
blocked. Native Moodle App integration is out of scope.

## Tests

Install both development plugins in an isolated Moodle PHPUnit environment:

```sh
php -d max_input_vars=5000 admin/tool/phpunit/cli/init.php
php -d max_input_vars=5000 vendor/bin/phpunit --testsuite local_xertify_testsuite
php -d max_input_vars=5000 vendor/bin/phpunit mod/xertifycertificate/tests/activity_test.php
```

The module suite uses real course modules, users, capabilities, prerequisite
completion restrictions and controllers. Remote transport is mocked; an HTTP
sentinel detects accidental requests. Never run these fixtures against a real
Moodle database. See the Phase 3 report for separate synthetic HTTP controller
checks and the outstanding visual evidence.
