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
 * Define portable activity fields and intro files.
 *
 * @package    mod_xertifycertificate
 * @copyright  2026 Xertify
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * No certificate, credential or user data belongs in this structure.
 */
class backup_xertifycertificate_activity_structure_step extends backup_activity_structure_step {
    /**
     * Define the activity's own XML, wrapped in core activity metadata.
     *
     * @return backup_nested_element Root structure.
     */
    protected function define_structure() {
        $activity = new backup_nested_element('xertifycertificate', ['id'], [
            'name', 'intro', 'introformat', 'timecreated', 'timemodified',
        ]);
        $activity->set_source_table('xertifycertificate', ['id' => backup::VAR_ACTIVITYID]);
        $activity->annotate_files('mod_xertifycertificate', 'intro', null);
        return $this->prepare_activity_structure($activity);
    }
}
