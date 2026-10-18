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
 * Activity lifecycle and cached course metadata.
 *
 * @package    mod_xertifycertificate
 * @copyright  2026 Xertify
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Declare only implemented features.
 *
 * @param string $feature Core feature constant.
 * @return mixed Feature value, or null for unsupported features.
 */
function xertifycertificate_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
        case FEATURE_SHOW_DESCRIPTION:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_MOD_ARCHETYPE:
            return MOD_ARCHETYPE_RESOURCE;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_CONTENT;
        case FEATURE_BACKUP_MOODLE2:
        case FEATURE_GROUPS:
        case FEATURE_GROUPINGS:
        case FEATURE_GRADE_HAS_GRADE:
        case FEATURE_GRADE_OUTCOMES:
            return false;
        default:
            return null;
    }
}

/**
 * Create activity metadata only; emission belongs to the local plugin.
 *
 * @param stdClass $data Form data.
 * @param moodleform_mod|null $mform Activity form.
 * @return int Instance ID.
 */
function xertifycertificate_add_instance($data, $mform = null) {
    global $DB;
    return $DB->insert_record('xertifycertificate', (object)[
        'course' => $data->course,
        'name' => $data->name,
        'intro' => $data->intro ?? '',
        'introformat' => $data->introformat ?? FORMAT_HTML,
        'timecreated' => time(),
        'timemodified' => time(),
    ]);
}

/**
 * Update activity metadata without touching certificate associations.
 *
 * @param stdClass $data Form data.
 * @param moodleform_mod|null $mform Activity form.
 * @return bool Success.
 */
function xertifycertificate_update_instance($data, $mform = null) {
    global $DB;
    return $DB->update_record('xertifycertificate', (object)[
        'id' => $data->instance,
        'name' => $data->name,
        'intro' => $data->intro ?? '',
        'introformat' => $data->introformat ?? FORMAT_HTML,
        'timemodified' => time(),
    ]);
}

/**
 * Delete only this activity's own metadata; core removes its context/files.
 *
 * @param int $id Instance ID.
 * @return bool Whether the instance existed.
 */
function xertifycertificate_delete_instance($id) {
    global $DB;
    if (!$DB->record_exists('xertifycertificate', ['id' => $id])) {
        return false;
    }
    $DB->delete_records('xertifycertificate', ['id' => $id]);
    return true;
}

/**
 * Supply cacheable metadata without querying certificates or remote APIs.
 *
 * @param stdClass $coursemodule Core course module record.
 * @return cached_cm_info|null Course metadata.
 */
function xertifycertificate_get_coursemodule_info($coursemodule) {
    global $DB;
    $instance = $DB->get_record('xertifycertificate', ['id' => $coursemodule->instance]);
    if (!$instance) {
        return null;
    }
    $info = new cached_cm_info();
    $info->name = $instance->name;
    if ($coursemodule->showdescription) {
        $info->content = format_module_intro('xertifycertificate', $instance, $coursemodule->id, false);
    }
    return $info;
}
