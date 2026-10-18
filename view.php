<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Current-user certificate activity page.
 *
 * @package    mod_xertifycertificate
 * @copyright  2026 Xertify
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/completionlib.php');

$id = required_param('id', PARAM_INT);
[$cm, $course, $instance, $context] = \mod_xertifycertificate\local\activity::load($id);
require_login($course, true, $cm);
$certificate = (new \local_xertify\local\certificate_service())->get_for_user(
    (int)$course->id,
    (int)$USER->id,
    $context
);

$PAGE->set_url('/mod/xertifycertificate/view.php', ['id' => $id]);
$PAGE->set_cm($cm, $course);
$PAGE->set_context($context);
$PAGE->set_title(format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));

// Standard view completion only; never a certificate issuance or download signal.
$completion = new completion_info($course);
$completion->set_module_viewed($cm);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($instance->name));
if (trim($instance->intro ?? '') !== '') {
    echo $OUTPUT->box(format_module_intro('xertifycertificate', $instance, $cm->id), 'generalbox mod_introbox');
}
echo \mod_xertifycertificate\output\certificate::render($certificate, $cm->id, $course, $context);
echo $OUTPUT->footer();
