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
 * Authorization shared by both module controllers.
 *
 * @package    mod_xertifycertificate
 * @copyright  2026 Xertify
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_xertifycertificate\local;

use local_xertify\local\certificate_service;

/**
 * Resolve only current-user certificates after full Moodle module login.
 */
class activity {
    /**
     * Resolve a cmid and enforce course, availability, guest and capability checks.
     *
     * @param int $cmid Course module ID, never an issue or user ID.
     * @return array Course module, course, instance and module context.
     */
    public static function load(int $cmid): array {
        global $DB;
        $cm = get_coursemodule_from_id('xertifycertificate', $cmid, 0, false, MUST_EXIST);
        $course = get_course($cm->course);
        require_login($course, true, $cm);
        $context = \context_module::instance($cm->id);
        require_capability('mod/xertifycertificate:view', $context);
        if (isguestuser()) {
            throw new \required_capability_exception($context, 'mod/xertifycertificate:view', 'nopermissions', '');
        }
        $instance = $DB->get_record('xertifycertificate', ['id' => $cm->instance, 'course' => $course->id], '*', MUST_EXIST);
        return [$cm, $course, $instance, $context];
    }

    /**
     * Resolve the authenticated user's certificate, regardless of URL extras.
     *
     * @param int $cmid Course module ID.
     * @param certificate_service|null $service Shared service, injectable in tests.
     * @return array Safe certificate DTO.
     */
    public static function certificate(int $cmid, ?certificate_service $service = null): array {
        global $USER;
        [$cm, $course, $instance, $context] = self::load($cmid);
        $service = $service ?? new certificate_service();
        return $service->get_for_user((int)$course->id, (int)$USER->id, $context);
    }

    /**
     * Fetch a PDF on demand, repeating authorization independently of view.php.
     *
     * @param int $cmid Course module ID.
     * @param certificate_service|null $service Shared service, injectable in tests.
     * @return array Valid PDF bytes and safe filename; never the transport body.
     */
    public static function pdf(int $cmid, ?certificate_service $service = null): array {
        $service = $service ?? new certificate_service();
        $certificate = self::certificate($cmid, $service);
        if (!$certificate['canrequestpdf']) {
            throw new \moodle_exception('pdfunavailable', 'xertifycertificate');
        }
        try {
            $result = $service->get_pdf(
                $certificate['issueid'],
                current_language(),
                $certificate['courseid'],
                \context_module::instance($cmid)
            );
        } catch (\moodle_exception $exception) {
            // Configuration and transport exceptions must not disclose credentials.
            throw new \moodle_exception('pdferror', 'xertifycertificate');
        }
        if (!$result['ok']) {
            $message = in_array($result['httpcode'], [404, 410], true) ? 'pdfunavailable' : 'pdferror';
            throw new \moodle_exception($message, 'xertifycertificate');
        }
        return $result;
    }
}
