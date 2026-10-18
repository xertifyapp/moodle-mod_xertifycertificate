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
 * Restore activity metadata and decode links using Moodle mappings.
 *
 * @package    mod_xertifycertificate
 * @copyright  2026 Xertify
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/xertifycertificate/backup/moodle2/restore_xertifycertificate_stepslib.php');

/**
 * Restore the viewer; core owns course module settings and completion data.
 */
class restore_xertifycertificate_activity_task extends restore_activity_task {
    /**
     * This activity has no additional restore settings.
     */
    protected function define_my_settings() {
    }

    /**
     * Restore the activity's own metadata.
     */
    protected function define_my_steps() {
        $this->add_step(new restore_xertifycertificate_activity_structure_step(
            'xertifycertificate_structure',
            'xertifycertificate.xml'
        ));
    }

    /**
     * Decode links in the activity description.
     *
     * @return array Content definitions.
     */
    public static function define_decode_contents() {
        return [new restore_decode_content('xertifycertificate', ['intro'], 'xertifycertificate')];
    }

    /**
     * Decode existing course and module routes using core ID mappings.
     *
     * @return array Link rules.
     */
    public static function define_decode_rules() {
        return [
            new restore_decode_rule('XERTIFYCERTIFICATEINDEX', '/mod/xertifycertificate/index.php?id=$1', 'course'),
            new restore_decode_rule('XERTIFYCERTIFICATEVIEW', '/mod/xertifycertificate/view.php?id=$1', 'course_module'),
            new restore_decode_rule('XERTIFYCERTIFICATEPDF', '/mod/xertifycertificate/pdf.php?id=$1', 'course_module'),
        ];
    }

    /**
     * There are no plugin-owned legacy logs to restore.
     *
     * @return array No legacy log rules.
     */
    public static function define_restore_log_rules() {
        return [];
    }
}
