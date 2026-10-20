# Xertify Certificate activity 1.0.0

`mod_xertifycertificate` is a separate course certificate viewer and this is its
first stable release candidate. It requires Moodle 4.1–5.2 and **Xertify
Certificates (`local_xertify`) 1.1.0**, build `2026101802` or later.
The module build is `2026101802`. Local 1.0.3 is rejected by Moodle's dependency
validation. The local plugin must be installed or upgraded first.

## Installation and optional activity

1. Install or update **Xertify Certificates (`local_xertify`) 1.1.0** first.
   Upload `local_xertify_1.1.0.zip` in **Site administration > Plugins > Install plugins**
   and complete Moodle's upgrade. For manual installation, extract `xertify/`
   into `local/` before Moodle 5.1 or `public/local/` in Moodle 5.1 and later.
2. To provide a certificate activity, install the separate
   **`mod_xertifycertificate` 1.0.0** package next. Upload
   `mod_xertifycertificate_1.0.0.zip`, or extract `xertifycertificate/` into
   `mod/` before Moodle 5.1 or `public/mod/` in Moodle 5.1 and later,
   then complete the upgrade.
3. Configure the Xertify integration globally and in each intended course.
4. Turn course editing on and select **Add an activity or resource >
   Xertify Certificate / Certificado Xertify**.
5. Optionally put it in a **Certificación** section, then set its name,
   description, standard access restrictions and optional view completion.

Token, Template ID, Group ID and issuance settings belong only to the local
plugin. The activity package is installed separately and contains no duplicate
integration settings. Existing courses do not gain activities automatically.

## Upgrading from local_xertify 1.0.3

**Do not uninstall the existing local plugin. Upgrade it in place.**

1. Back up Moodle files, moodledata and the database according to the site's
   administration policy.
2. Replace/update `local_xertify` 1.0.3 with 1.1.0 and complete Moodle's upgrade.
3. Verify the existing course integration and certificate associations.
4. Install `mod_xertifycertificate` 1.0.0 after the local upgrade succeeds.
5. Add the activity to each course where teachers want it available.

The module requires local plugin build `2026101802` or later; local 1.0.3 is
insufficient. This upgrade preserves stored configuration and certificate
associations; it does not reissue certificates or create course activities.

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
responsibility and is covered by that plugin's complete Privacy API provider.

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
`local_xertify`. Its safe restore policy restores new course configuration disabled
with an empty token override, and preserves an existing destination configuration.
Review the destination course's Xertify settings before enabling integration.

In the tested Moodle 4.1–5.2 patches, a restored prerequisite is remapped to its new cmid. A same-course
activity duplicate retains its existing prerequisite. If the prerequisite is
omitted when restoring to another course, core changes the condition to `cm: 0`
and leaves the viewer inaccessible to students; review that restriction after
restore. This module does not replace core's dependency policy.

The backup encoder/decoder remaps authored links to the existing index, view and
PDF routes. Intro files are restored into the new module context. No emission
code, API client or local certificate data is used by these backup tasks.

Compatibility is validated on Moodle 4.1–5.2 with a supported PHP combination
for each family. Static PHPCompatibility also checks syntax across PHP 7.4–8.4;
use the PHP runtime required by the installed Moodle version. No custom JS or
CSS is used. Responsive web QA covers desktop and mobile-size layouts; dedicated
native Moodle App integration is outside the initial release's scope.

## Tests

Install both plugins in an isolated Moodle PHPUnit environment:

```sh
php -d max_input_vars=5000 admin/tool/phpunit/cli/init.php
php -d max_input_vars=5000 vendor/bin/phpunit --testsuite local_xertify_testsuite
php -d max_input_vars=5000 vendor/bin/phpunit --testsuite mod_xertifycertificate_testsuite
```

The module suite uses real course modules, users, capabilities, prerequisite
completion restrictions and controllers. Remote transport is mocked; an HTTP
sentinel detects accidental requests. Never run these fixtures against a real
Moodle database. Functional HTTP smoke checks complement these suites.

Backup regression tests use final `.mbz` archives and real `backup_controller`,
`restore_controller`, `duplicate_module` and course duplication APIs. They scan
all extracted files for synthetic secret/certificate markers in both user-data
modes. Event, HTTP and issue-write guards plus a direct-dependency check protect
against issuance. Set `XERTIFY_PHASE4_EVIDENCE_DIR` to an existing external test
directory to export optional synthetic assertion summaries.

On Moodle 5.1 and later, the PHPUnit initialization script is under
`public/admin/tool/phpunit/cli/init.php`; invoke PHPUnit from the Moodle root.
