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
 * Back up activity metadata using the standard Moodle activity task.
 *
 * @package    mod_xertifycertificate
 * @copyright  2026 Xertify
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/xertifycertificate/backup/moodle2/backup_xertifycertificate_stepslib.php');

/**
 * Back up the viewer without consulting certificate data.
 */
class backup_xertifycertificate_activity_task extends backup_activity_task {
    /**
     * This activity has no additional backup settings.
     */
    protected function define_my_settings() {
    }

    /**
     * Add the single activity metadata structure.
     */
    protected function define_my_steps() {
        $this->add_step(new backup_xertifycertificate_activity_structure_step(
            'xertifycertificate_structure',
            'xertifycertificate.xml'
        ));
    }

    /**
     * Encode links to the module's existing course index, view and PDF routes.
     *
     * @param string $content HTML content.
     * @return string Content with portable course/module references.
     */
    public static function encode_content_links($content) {
        global $CFG;
        $base = preg_quote($CFG->wwwroot, '~');
        foreach (['index' => 'INDEX', 'view' => 'VIEW', 'pdf' => 'PDF'] as $route => $token) {
            $content = preg_replace(
                '~' . $base . '/mod/xertifycertificate/' . $route . '\.php\?id=([0-9]+)~',
                '$@XERTIFYCERTIFICATE' . $token . '*$1@$',
                $content
            );
        }
        return $content;
    }
}
