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
 * List accessible activities using core course metadata.
 *
 * @package    mod_xertifycertificate
 * @copyright  2026 Xertify
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$course = get_course($id);
require_login($course);
$PAGE->set_url('/mod/xertifycertificate/index.php', ['id' => $id]);
$PAGE->set_context(context_course::instance($id));
$PAGE->set_course($course);
$PAGE->set_title(get_string('modulenameplural', 'xertifycertificate'));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'xertifycertificate'));
foreach (get_fast_modinfo($course)->get_instances_of('xertifycertificate') as $cm) {
    if (!isguestuser() && $cm->uservisible && has_capability('mod/xertifycertificate:view', $cm->context)) {
        echo html_writer::div(html_writer::link($cm->url, $cm->get_formatted_name()), 'mb-3');
    }
}
echo $OUTPUT->footer();
