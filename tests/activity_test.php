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
 * Integration tests for the activity and optional legacy access policy.
 *
 * @package    mod_xertifycertificate
 * @copyright  2026 Xertify
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_xertifycertificate;

use local_xertify\local\activity_access;
use local_xertify\local\certificate_service;
use mod_xertifycertificate\local\activity;

/**
 * Exercise real Moodle instances, controllers, capabilities and completion.
 *
 * @covers \mod_xertifycertificate\local\activity
 * @covers \mod_xertifycertificate\output\certificate
 * @covers \local_xertify\local\activity_access
 */
final class activity_test extends \advanced_testcase {
    /** @var \stdClass Test course. */
    private $course;
    /** @var \stdClass Current learner. */
    private $owner;
    /** @var \stdClass Other learner. */
    private $other;
    /** @var \stdClass Activity instance. */
    private $instance;
    /** @var array Original Moodle curl mocks. */
    private $curlmocks;

    /**
     * Install fixtures using Moodle's actual module generator.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->libdir . '/filelib.php');
        $property = new \ReflectionProperty(\curl::class, 'mockresponses');
        $property->setAccessible(true);
        $this->curlmocks = $property->getValue();
        $property->setValue(null, ['UNCONSUMED_NETWORK_SENTINEL']);
        require_once($CFG->dirroot . '/mod/xertifycertificate/lib.php');
        require_once($CFG->dirroot . '/local/xertify/lib.php');
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->dirroot . '/course/lib.php');
        $CFG->enablecompletion = 1;
        $CFG->enableavailability = 1;
        $this->setAdminUser();
        $g = $this->getDataGenerator();
        $this->course = $g->create_course(['enablecompletion' => 1]);
        $this->owner = $g->create_user();
        $this->other = $g->create_user();
        $g->enrol_user($this->owner->id, $this->course->id, 'student');
        $g->enrol_user($this->other->id, $this->course->id, 'student');
        $this->instance = $g->create_module('xertifycertificate', [
            'course' => $this->course->id, 'name' => 'My certificate',
            'intro' => '<p>Course certificate</p>', 'showdescription' => 1,
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => COMPLETION_VIEW_REQUIRED,
        ]);
        $this->setUser($this->owner);
    }


    /**
     * Every non-transport test must leave the HTTP sentinel unconsumed.
     */
    protected function tearDown(): void {
        $property = new \ReflectionProperty(\curl::class, 'mockresponses');
        $property->setAccessible(true);
        $remaining = $property->getValue();
        $property->setValue(null, $this->curlmocks);
        try {
            $this->assertSame(['UNCONSUMED_NETWORK_SENTINEL'], $remaining);
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Synthetic certificate fixture; only tests write local certificate records.
     *
     * @param array $changes Overrides.
     * @return \stdClass Issue record.
     */
    private function issue(array $changes = []): \stdClass {
        global $DB;
        $record = (object)array_merge([
            'courseid' => $this->course->id, 'userid' => $this->owner->id,
            'status' => 'issued', 'requesthash' => 'synthetic', 'responsecode' => 200,
            'responsebody' => 'PRIVATE_RESPONSE_AND_BASE64', 'certificatecode' => 'OWNER-CERT',
            'certificateurl' => 'https://wallet.xertify.co/certificates/OWNER-CERT',
            'generationid' => 'test-generation', 'timecreated' => 1700000000, 'timemodified' => 1700000010,
        ], $changes);
        $record->id = $DB->insert_record('local_xertify_issue', $record);
        return $record;
    }

    /**
     * Include an actual controller with a fresh Moodle page.
     *
     * @param string $path Path below Moodle root.
     * @param array $params Browser parameters.
     * @return string Rendered HTML.
     */
    private function page(string $path, array $params): string {
        global $CFG, $DB, $USER, $PAGE, $OUTPUT;
        $_GET = $params;
        $PAGE = new \moodle_page();
        $OUTPUT = new \bootstrap_renderer();
        ob_start();
        try {
            include($CFG->dirroot . '/' . $path);
            return ob_get_contents();
        } finally {
            ob_end_clean();
            $_GET = [];
        }
    }

    /**
     * Restrict the certificate by a real prerequisite activity's completion.
     *
     * @return \cm_info Prerequisite module.
     */
    private function restrict(): \cm_info {
        global $DB;
        $this->setAdminUser();
        $prerequisite = $this->getDataGenerator()->create_module('page', [
            'course' => $this->course->id, 'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $DB->set_field('course_modules', 'availability', json_encode([
            'op' => '&', 'c' => [['type' => 'completion', 'cm' => $prerequisite->cmid, 'e' => COMPLETION_COMPLETE]],
            'showc' => [true],
        ]), ['id' => $this->instance->cmid]);
        rebuild_course_cache($this->course->id, true);
        $this->setUser($this->owner);
        return get_fast_modinfo($this->course)->get_cm($prerequisite->cmid);
    }

    /**
     * Lifecycle changes leave issuance/configuration and other instances intact.
     */
    public function test_lifecycle(): void {
        global $DB;
        $this->setAdminUser();
        $issue = $this->issue();
        $configid = $DB->insert_record('local_xertify_course', (object)[
            'courseid' => $this->course->id, 'templateid' => 'test', 'groupid' => 'test',
        ]);
        $sink = $this->redirectEvents();
        $second = $this->getDataGenerator()->create_module('xertifycertificate', ['course' => $this->course->id]);
        $this->assertTrue(xertifycertificate_update_instance((object)[
            'instance' => $second->id, 'name' => 'Changed', 'intro' => 'Updated', 'introformat' => FORMAT_HTML,
        ]));
        $this->assertSame('Changed', $DB->get_field('xertifycertificate', 'name', ['id' => $second->id]));
        course_delete_module($second->cmid);
        $this->assertFalse($DB->record_exists('xertifycertificate', ['id' => $second->id]));
        $this->assertTrue($DB->record_exists('xertifycertificate', ['id' => $this->instance->id]));
        $this->assertEquals($issue, $DB->get_record('local_xertify_issue', ['id' => $issue->id]));
        $this->assertTrue($DB->record_exists('local_xertify_course', ['id' => $configid]));
        $this->assertSame(1, $DB->count_records('local_xertify_issue'));
        foreach ($sink->get_events() as $event) {
            $this->assertNotInstanceOf(\core\event\course_completed::class, $event);
        }
        $sink->close();
        $this->assertFalse(xertifycertificate_delete_instance($second->id));
    }

    /**
     * Core dependency validation rejects the old public local plugin version.
     */
    public function test_dependency_and_schema(): void {
        global $DB;
        $plugin = \core_plugin_manager::instance()->get_plugin_info('mod_xertifycertificate');
        $dependencies = $plugin->get_other_required_plugins();
        $this->assertSame(2026101800, $dependencies['local_xertify']);
        $this->assertGreaterThan(2026091500, $dependencies['local_xertify']);
        $manager = \core_plugin_manager::instance();
        $this->assertTrue($manager->are_dependencies_satisfied($dependencies));
        $local = $manager->get_plugin_info('local_xertify');
        $original = $local->versiondisk;
        try {
            $local->versiondisk = 2026091500;
            $this->assertFalse($manager->are_dependencies_satisfied($dependencies));
        } finally {
            $local->versiondisk = $original;
        }
        $this->assertSame(
            ['id', 'course', 'name', 'intro', 'introformat', 'timecreated', 'timemodified'],
            array_keys($DB->get_columns('xertifycertificate'))
        );
        $this->assertFalse(xertifycertificate_supports(FEATURE_BACKUP_MOODLE2));
        $this->assertTrue(xertifycertificate_supports(FEATURE_COMPLETION_TRACKS_VIEWS));
        $this->assertSame(MOD_ARCHETYPE_RESOURCE, xertifycertificate_supports(FEATURE_MOD_ARCHETYPE));
    }

    /**
     * Real view controller renders states with no PDF request or issuance.
     *
     * @dataProvider states
     * @param string|null $status Stored state.
     * @param string $code Certificate code.
     * @param string $message Expected language key.
     * @param bool $manager Test manager presentation.
     */
    public function test_view_states(?string $status, string $code, string $message, bool $manager): void {
        global $DB;
        if ($status !== null) {
            $this->issue(['status' => $status, 'certificatecode' => $code]);
        }
        if ($manager) {
            $this->getDataGenerator()->enrol_user($this->owner->id, $this->course->id, 'manager');
        }
        $before = $DB->get_records('local_xertify_issue');
        $sink = $this->redirectEvents();
        $html = $this->page('mod/xertifycertificate/view.php', ['id' => $this->instance->cmid]);
        $this->assertStringContainsString(get_string($message, 'xertifycertificate'), $html);
        $this->assertStringNotContainsString('PRIVATE_RESPONSE_AND_BASE64', $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertSame($message === 'issued', strpos($html, 'download=1') !== false);
        $this->assertEquals($before, $DB->get_records('local_xertify_issue'));
        foreach ($sink->get_events() as $event) {
            $this->assertNotInstanceOf(\core\event\course_completed::class, $event);
        }
        $sink->close();
    }

    /**
     * State cases, including manager-only diagnostics and dry-run text.
     *
     * @return array
     */
    public static function states(): array {
        return [
            [null, '', 'noissue', false], ['pending', '', 'pending', false],
            ['issued', 'OWNER-CERT', 'issued', false], ['issued', '', 'issuedwithoutcode', false],
            ['failed', '', 'failed', false], ['failed', '', 'failed', true],
            ['dryrun', '', 'dryrun', false], ['dryrun', '', 'dryrunmanager', true],
            ['unexpected', '', 'unknown', false],
        ];
    }

    /**
     * Browser extras cannot select another user's issue or code.
     *
     * @dataProvider forged_parameters
     * @param string $parameter Attacker-controlled URL field.
     */
    public function test_current_user_only(string $parameter): void {
        $this->issue();
        $other = $this->issue(['userid' => $this->other->id, 'certificatecode' => 'OTHER-CERT']);
        $values = ['userid' => $this->other->id, 'issueid' => $other->id, 'certificatecode' => 'OTHER-CERT'];
        $html = $this->page('mod/xertifycertificate/view.php', [
            'id' => $this->instance->cmid, $parameter => $values[$parameter],
        ]);
        $this->assertStringContainsString('OWNER-CERT', $html);
        $this->assertStringNotContainsString('OTHER-CERT', $html);
    }

    /**
     * Untrusted URL fields.
     *
     * @return array
     */
    public static function forged_parameters(): array {
        return [['userid'], ['issueid'], ['certificatecode']];
    }

    /**
     * Both controllers independently reject real completion restrictions and hiding.
     *
     * @dataProvider denied_routes
     * @param string $route Controller below Moodle root.
     * @param bool $hidden Hide instead of using prerequisite completion.
     */
    public function test_denied_routes(string $route, bool $hidden): void {
        global $DB;
        $issue = $this->issue();
        $this->restrict();
        if ($hidden) {
            $DB->set_field('course_modules', 'visible', 0, ['id' => $this->instance->cmid]);
            rebuild_course_cache($this->course->id, true);
        }
        $this->expectException(\moodle_exception::class);
        $this->page($route, ['id' => $this->instance->cmid, 'courseid' => $this->course->id, 'issueid' => $issue->id]);
    }

    /**
     * Direct new and legacy endpoints must enforce the same policy.
     *
     * @return array
     */
    public static function denied_routes(): array {
        $cases = [];
        foreach (
            ['mod/xertifycertificate/view.php', 'mod/xertifycertificate/pdf.php',
            'local/xertify/certificate.php', 'local/xertify/pdf.php'] as $route
        ) {
            foreach ([false, true] as $hidden) {
                $cases[] = [$route, $hidden];
            }
        }
        return $cases;
    }

    /**
     * Satisfying a real prerequisite grants access and preserves OR across instances.
     */
    public function test_completion_restriction_and_multiple_instances(): void {
        global $PAGE;
        $this->issue();
        $prerequisite = $this->restrict();
        $this->assertFalse(activity_access::legacy_target($this->course->id));
        $this->setAdminUser();
        $second = $this->getDataGenerator()->create_module('xertifycertificate', ['course' => $this->course->id]);
        $this->setUser($this->owner);
        $this->assertSame((int)$second->cmid, (int)activity_access::legacy_target($this->course->id)->id);
        $completion = new \completion_info($this->course);
        $completion->update_state($prerequisite, COMPLETION_COMPLETE, $this->owner->id);
        get_fast_modinfo($this->course, 0, true);
        $firstdata = activity::certificate($this->instance->cmid);
        $PAGE = new \moodle_page();
        $this->assertSame($firstdata, activity::certificate($second->cmid));
        $this->assertSame('OWNER-CERT', $firstdata['certificatecode']);
    }

    /**
     * Legacy navigation hides blocked entries and links to an accessible instance.
     */
    public function test_legacy_navigation(): void {
        $this->issue();
        $context = \context_course::instance($this->course->id);
        $nav = new \navigation_node(['text' => 'Course']);
        local_xertify_extend_navigation_course($nav, $this->course, $context);
        $node = $nav->get('local_xertify_certificate', \navigation_node::TYPE_CUSTOM);
        $this->assertSame(
            (new \moodle_url('/mod/xertifycertificate/view.php', ['id' => $this->instance->cmid]))->out(false),
            $node->action->out(false)
        );
        $this->restrict();
        $nav = new \navigation_node(['text' => 'Course']);
        local_xertify_extend_navigation_course($nav, $this->course, $context);
        $this->assertFalse($nav->get('local_xertify_certificate', \navigation_node::TYPE_CUSTOM));
        $this->getDataGenerator()->enrol_user($this->owner->id, $this->course->id, 'manager');
        $this->assertNull(activity_access::legacy_target($this->course->id));
        $html = $this->page('local/xertify/certificate.php', ['courseid' => $this->course->id]);
        $this->assertStringContainsString('OWNER-CERT', $html);
    }

    /**
     * Optional view completion records a view without completing a course or issuing.
     */
    public function test_view_completion_and_modinfo(): void {
        global $DB;
        $cm = get_fast_modinfo($this->course)->get_cm($this->instance->cmid);
        $this->assertSame('My certificate', $cm->name);
        $this->assertStringContainsString('Course certificate', $cm->content);
        $this->assertTrue($cm->uservisible);
        $this->page('mod/xertifycertificate/view.php', ['id' => $cm->id]);
        $completion = new \completion_info($this->course);
        $this->assertSame(COMPLETION_VIEWED, (int)$completion->get_data($cm)->viewed);
        $this->assertSame(0, $DB->count_records('local_xertify_issue'));
        $this->assertFalse($completion->is_course_complete($this->owner->id));
    }

    /**
     * Guest and missing view capability are rejected at both entry points.
     *
     * @dataProvider unauthorized_cases
     * @param bool $guest Use guest account.
     * @param bool $pdf Use PDF endpoint.
     */
    public function test_unauthorized(bool $guest, bool $pdf): void {
        global $DB;
        if ($guest) {
            $this->setGuestUser();
        } else {
            $role = $DB->get_record('role', ['shortname' => 'student']);
            assign_capability(
                'mod/xertifycertificate:view',
                CAP_PROHIBIT,
                $role->id,
                \context_module::instance($this->instance->cmid)->id
            );
        }
        $this->expectException(\moodle_exception::class);
        if ($pdf) {
            activity::pdf($this->instance->cmid);
        } else {
            activity::certificate($this->instance->cmid);
        }
    }

    /**
     * Authorization matrix.
     *
     * @return array
     */
    public static function unauthorized_cases(): array {
        return [[true, false], [true, true], [false, false], [false, true]];
    }

    /**
     * Exercise actual shared PDF service with a stub only at the remote client.
     *
     * @dataProvider pdf_responses
     * @param int $code HTTP status.
     * @param string $bytes Remote decoded bytes.
     * @param string|null $error Expected friendly error.
     */
    public function test_pdf(int $code, string $bytes, ?string $error): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/xertify/tests/fixtures/certificate_service_stub.php');
        $this->issue();
        $before = $DB->get_records('local_xertify_issue');
        $CFG->local_xertify_api_url = 'https://example.invalid/v4createRequest';
        $DB->insert_record('local_xertify_course', (object)[
            'courseid' => $this->course->id, 'templateid' => 'test', 'groupid' => 'test', 'apitoken' => 'SYNTHETIC_TOKEN',
        ]);
        $service = new \local_xertify\local\certificate_service_stub();
        $service->client = $this->getMockBuilder(\local_xertify\api\client::class)->disableOriginalConstructor()->getMock();
        $service->client->expects($this->never())->method('issue');
        $service->client->expects($this->once())->method('get_pdf')->with('OWNER-CERT')->willReturn([
            'ok' => $code === 200, 'code' => $code, 'pdf' => $bytes,
        ]);
        try {
            $result = activity::pdf($this->instance->cmid, $service);
            $this->assertNull($error);
            $this->assertSame($bytes, $result['pdf']);
            $this->assertSame('xertify-OWNER-CERT.pdf', $result['filename']);
        } catch (\moodle_exception $exception) {
            $this->assertSame($error, $exception->errorcode);
            $this->assertStringNotContainsString('SYNTHETIC_TOKEN', $exception->getMessage());
        }
        $this->assertEquals($before, $DB->get_records('local_xertify_issue'));
        $this->assertSame(1, $service->calls);
    }

    /**
     * Valid, unavailable, unauthorized, server and invalid-byte responses.
     *
     * @return array
     */
    public static function pdf_responses(): array {
        return [[200, '%PDF-1.7 synthetic', null], [404, '', 'pdfunavailable'], [410, '', 'pdfunavailable'],
            [401, '', 'pdferror'], [500, '', 'pdferror'],
            [503, '', 'pdferror'], [200, '<html>bad</html>', 'pdferror']];
    }

    /**
     * Runtime module code cannot query certificate tables or invoke emission.
     */
    public function test_read_only_architecture(): void {
        $paths = ['view.php', 'pdf.php', 'index.php', 'lib.php', 'classes/local/activity.php', 'classes/output/certificate.php'];
        foreach ($paths as $path) {
            $source = file_get_contents(__DIR__ . '/../' . $path);
            $forbiddenwords = ['local_xertify_issue', 'issue_for_course_completion', 'client::issue', '->issue(', 'apitoken'];
            foreach ($forbiddenwords as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, $path);
            }
        }
    }
    /**
     * Optional completion remains disabled when the teacher has not enabled it.
     */
    public function test_view_without_completion(): void {
        global $DB;
        $DB->set_field('course_modules', 'completion', COMPLETION_TRACKING_NONE, ['id' => $this->instance->cmid]);
        rebuild_course_cache($this->course->id, true);
        $this->page('mod/xertifycertificate/view.php', ['id' => $this->instance->cmid]);
        $this->assertFalse($DB->record_exists('course_modules_completion', ['coursemoduleid' => $this->instance->cmid]));
    }

    /**
     * A cmid from another module cannot be used as a certificate activity.
     */
    public function test_wrong_module_id(): void {
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $this->expectException(\moodle_exception::class);
        activity::certificate($page->cmid);
    }

    /**
     * Standard course index data follows Moodle moves and visibility settings.
     */
    public function test_move_and_hide(): void {
        global $DB;
        $this->setAdminUser();
        course_create_sections_if_missing($this->course, [2]);
        $cm = get_coursemodule_from_id('xertifycertificate', $this->instance->cmid);
        $section = $DB->get_record('course_sections', ['course' => $this->course->id, 'section' => 2]);
        moveto_module($cm, $section);
        $this->setUser($this->owner);
        $this->assertSame(2, (int)get_fast_modinfo($this->course)->get_cm($cm->id)->sectionnum);
        set_coursemodule_visible($cm->id, 0);
        rebuild_course_cache($this->course->id, true);
        $this->assertFalse(get_fast_modinfo($this->course)->get_cm($cm->id)->uservisible);
    }

    /**
     * Index lists accessible activities without consulting certificates.
     */
    public function test_index_availability(): void {
        $this->issue();
        $html = $this->page('mod/xertifycertificate/index.php', ['id' => $this->course->id]);
        $this->assertStringContainsString('My certificate', $html);
        $this->assertStringNotContainsString('OWNER-CERT', $html);
        $this->restrict();
        $html = $this->page('mod/xertifycertificate/index.php', ['id' => $this->course->id]);
        $this->assertStringNotContainsString('view.php?id=' . $this->instance->cmid . '"', $html);
    }

    /**
     * PDF browser parameters cannot select a different owner or code.
     *
     * @dataProvider forged_parameters
     * @param string $parameter Untrusted browser parameter.
     */
    public function test_pdf_ignores_browser_extras(string $parameter): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/xertify/tests/fixtures/certificate_service_stub.php');
        $this->issue();
        $other = $this->issue(['userid' => $this->other->id, 'certificatecode' => 'OTHER-CERT']);
        $_GET[$parameter] = $parameter === 'issueid' ? $other->id : 'OTHER-CERT';
        $service = $this->getMockBuilder(certificate_service::class)->onlyMethods(['get_pdf'])->getMock();
        $service->expects($this->once())->method('get_pdf')->with(
            $this->callback(function ($id) use ($other) {
                return (int)$id !== (int)$other->id;
            })
        )->willReturn(['ok' => true, 'pdf' => '%PDF-1.7 synthetic', 'filename' => 'test.pdf']);
        try {
            $this->assertTrue(activity::pdf($this->instance->cmid, $service)['ok']);
        } finally {
            $_GET = [];
        }
    }

    /**
     * Missing associations and configuration give safe errors without network calls.
     *
     * @dataProvider missing_pdf_cases
     * @param bool $issued Whether an issued record exists.
     */
    public function test_missing_pdf(bool $issued): void {
        if ($issued) {
            $this->issue();
        }
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string($issued ? 'pdferror' : 'pdfunavailable', 'xertifycertificate'));
        activity::pdf($this->instance->cmid);
    }

    /**
     * Missing association and missing configuration.
     *
     * @return array
     */
    public static function missing_pdf_cases(): array {
        return [[false], [true]];
    }

    /**
     * Module view capability can narrow legacy access even with no availability rule.
     */
    public function test_legacy_respects_module_capability(): void {
        global $DB;
        $role = $DB->get_record('role', ['shortname' => 'student']);
        assign_capability(
            'mod/xertifycertificate:view',
            CAP_PROHIBIT,
            $role->id,
            \context_module::instance($this->instance->cmid)->id
        );
        $this->assertFalse(activity_access::legacy_target($this->course->id));
    }

    /**
     * Anonymous callers cannot use either entry point.
     *
     * @dataProvider anonymous_routes
     * @param bool $pdf PDF instead of certificate route.
     */
    public function test_anonymous(bool $pdf): void {
        $this->setUser(null);
        $this->expectException(\moodle_exception::class);
        if ($pdf) {
            activity::pdf($this->instance->cmid);
        } else {
            activity::certificate($this->instance->cmid);
        }
    }

    /**
     * Entry points.
     *
     * @return array
     */
    public static function anonymous_routes(): array {
        return [[false], [true]];
    }

    /**
     * Core activity chooser exposes the module to teachers and not to students.
     */
    public function test_activity_chooser(): void {
        global $USER;
        $service = \core_course\local\factory\content_item_service_factory::get_content_item_service();
        $this->assertSame([], $service->get_content_items_for_user_in_course($USER, $this->course));
        $this->getDataGenerator()->enrol_user($this->owner->id, $this->course->id, 'editingteacher');
        $items = $service->get_content_items_for_user_in_course($USER, $this->course);
        $found = false;
        foreach ($items as $item) {
            if ($item->name === 'xertifycertificate') {
                $found = true;
                $this->assertSame(get_string('modulename', 'xertifycertificate'), $item->title);
                $this->assertStringContainsString('add=xertifycertificate', $item->link);
                $this->assertStringContainsString('/xertifycertificate/', $item->icon);
            }
        }
        $this->assertTrue($found);
    }
}
