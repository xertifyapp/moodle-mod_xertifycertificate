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
 * Restore an independent viewer instance and its intro files.
 *
 * @package    mod_xertifycertificate
 * @copyright  2026 Xertify
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restore only the explicit activity metadata contract.
 */
class restore_xertifycertificate_activity_structure_step extends restore_activity_structure_step {
    /**
     * Define the single activity record path.
     *
     * @return array Structure paths.
     */
    protected function define_structure() {
        return $this->prepare_activity_structure([
            new restore_path_element('xertifycertificate', '/activity/xertifycertificate'),
        ]);
    }

    /**
     * Create a new instance in the destination course, never the source course.
     *
     * @param array $data Backed up activity fields.
     */
    protected function process_xertifycertificate($data) {
        global $DB;
        $data = (object)$data;
        $record = (object)[
            'course' => $this->get_courseid(),
            'name' => $data->name,
            'intro' => $data->intro,
            'introformat' => $data->introformat,
            'timecreated' => $data->timecreated,
            'timemodified' => $data->timemodified,
        ];
        $this->apply_activity_instance($DB->insert_record('xertifycertificate', $record));
    }

    /**
     * Core maps the source module context to the restored module context.
     */
    protected function after_execute() {
        $this->add_related_files('mod_xertifycertificate', 'intro', null);
    }
}
