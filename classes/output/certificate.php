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
 * Safe certificate presentation without remote calls.
 *
 * @package    mod_xertifycertificate
 * @copyright  2026 Xertify
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_xertifycertificate\output;

/**
 * Render only the shared service's safe DTO using standard Moodle markup.
 */
class certificate {
    /**
     * Render state and on-demand links; no iframe or eager PDF request.
     *
     * @param array $data Safe shared-service DTO.
     * @param int $cmid Authorized activity ID.
     * @param \stdClass $course Current course.
     * @param \context_module $context Authorized module context.
     * @return string HTML.
     */
    public static function render(array $data, int $cmid, \stdClass $course, \context_module $context): string {
        $component = 'xertifycertificate';
        $manager = has_capability('local/xertify:manage', $context);
        $state = $data['state'];
        $html = \html_writer::tag('p', format_string($course->fullname, true, ['context' => $context]));
        $message = $state === 'dryrun' && $manager ? 'dryrunmanager' : $state;
        $html .= \html_writer::div(get_string($message, $component), 'alert alert-info');
        if ($state === 'issued') {
            $html .= \html_writer::tag('p', get_string('certificatecode', $component) . ': ' . s($data['certificatecode']));
            $links = [];
            foreach (['viewcertificate' => 0, 'downloadpdf' => 1] as $label => $download) {
                $url = new \moodle_url('/mod/xertifycertificate/pdf.php', ['id' => $cmid, 'download' => $download]);
                $links[] = \html_writer::link($url, get_string($label, $component), ['class' => 'btn btn-primary mb-2']);
            }
            if ($data['walleturl'] !== '') {
                $links[] = \html_writer::link($data['walleturl'], get_string('wallet', $component), [
                    'class' => 'btn btn-secondary mb-2', 'target' => '_blank', 'rel' => 'noopener noreferrer',
                ]);
            }
            $html .= \html_writer::div(implode(' ', $links), 'mb-3');
        }
        if ($manager && $state === 'failed') {
            $items = '';
            foreach (['issueid', 'status', 'responsecode', 'generationid', 'timemodified'] as $field) {
                $items .= \html_writer::tag('dt', get_string($field, $component));
                $items .= \html_writer::tag('dd', s((string)($data[$field] ?? '')));
            }
            $html .= \html_writer::tag('dl', $items);
        }
        return $html;
    }
}
