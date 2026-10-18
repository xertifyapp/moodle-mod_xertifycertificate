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
 * Real archive, restoration and duplication regression tests.
 *
 * @package    mod_xertifycertificate
 * @copyright  2026 Xertify
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_xertifycertificate;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/course/externallib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/filelib.php');

/**
 * Exercise final MBZ archives and core restore/duplication with synthetic data.
 *
 * @covers \backup_xertifycertificate_activity_task
 * @covers \backup_xertifycertificate_activity_structure_step
 * @covers \restore_xertifycertificate_activity_task
 * @covers \restore_xertifycertificate_activity_structure_step
 */
final class backup_restore_test extends \advanced_testcase {
    /** @var \stdClass Source course. */
    private $course;
    /** @var \stdClass Source prerequisite. */
    private $prerequisite;
    /** @var \stdClass Source viewer. */
    private $viewer;
    /** @var \stdClass Synthetic learner. */
    private $student;
    /** @var \stdClass Source integration. */
    private $config;
    /** @var array Values which must never leave the source through a backup. */
    private $forbidden;

    /**
     * Create an enabled integration and an issued certificate only as fixtures.
     */
    protected function setUp(): void {
        global $CFG, $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->enablecompletion = 1;
        $CFG->enableavailability = 1;
        $CFG->keeptempdirectoriesonbackup = true;
        $CFG->local_xertify_api_url = 'https://example.invalid/v4createRequest';
        $g = $this->getDataGenerator();
        $this->course = $g->create_course(['enablecompletion' => 1, 'numsections' => 2]);
        $g->create_course_section(['course' => $this->course->id, 'section' => 2]);
        $DB->set_field('course_sections', 'name', 'Certificación', ['course' => $this->course->id, 'section' => 2]);
        $this->student = $g->create_user();
        $g->enrol_user($this->student->id, $this->course->id, 'student');
        $this->prerequisite = $g->create_module('page', [
            'course' => $this->course->id, 'name' => 'Prerequisite', 'section' => 2,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $this->viewer = $g->create_module('xertifycertificate', [
            'course' => $this->course->id, 'section' => 2, 'name' => 'Descarga aquí tu certificado',
            'intro' => '<p>Certificate description <img src="@@PLUGINFILE@@/intro.svg" alt="Fixture"></p>',
            'introformat' => FORMAT_HTML, 'showdescription' => 1, 'idnumber' => 'fixture-viewer',
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => COMPLETION_VIEW_REQUIRED,
            'availability' => $this->restriction($this->prerequisite->cmid),
        ]);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($this->viewer->cmid)->id,
            'component' => 'mod_xertifycertificate', 'filearea' => 'intro', 'itemid' => 0,
            'filepath' => '/', 'filename' => 'intro.svg',
        ], '<svg xmlns="http://www.w3.org/2000/svg"><title>Intro fixture</title></svg>');
        $this->config = (object)[
            'courseid' => $this->course->id, 'enabled' => 1, 'templateid' => 'TEST-TEMPLATE',
            'groupid' => 'TEST-GROUP', 'idtype' => 'TEST-ID', 'apitoken' => 'TEST_SECRET_XERTIFY_TOKEN_PHASE4',
            'emitrule' => 'course_completed', 'dynamicdata' => 'location=Test campus',
            'notification_email' => 1, 'notification_sms' => 0, 'notification_whatsapp' => 0,
            'timecreated' => 1700000000, 'timemodified' => 1700000010,
        ];
        $this->config->id = $DB->insert_record('local_xertify_course', $this->config);
        $pdf = '%PDF-1.7 TEST_PDF_PHASE4';
        $this->forbidden = [
            'TESTCERTIFICATE123', 'TEST-GENERATION-123456', 'TEST_SECRET_XERTIFY_TOKEN_PHASE4',
            'TEST_XERTIFY_RESPONSE_PAYLOAD_PHASE4', 'https://wallet.xertify.co/certificates/TESTCERTIFICATE123',
            $pdf, base64_encode($pdf), 'local_xertify_issue', 'certificatecode', 'generationid',
            'certificateurl', 'responsebody', 'apitoken',
        ];
        $DB->insert_record('local_xertify_issue', (object)[
            'courseid' => $this->course->id, 'userid' => $this->student->id, 'status' => 'issued',
            'requesthash' => 'synthetic-phase4', 'responsecode' => 200,
            'generationid' => $this->forbidden[1], 'certificatecode' => $this->forbidden[0],
            'certificateurl' => $this->forbidden[4],
            'responsebody' => json_encode(['message' => $this->forbidden[3], 'file' => base64_encode($pdf)]),
            'timecreated' => 1700000000, 'timemodified' => 1700000010,
        ]);
    }

    /**
     * Build a standard completion restriction.
     *
     * @param int $cmid Prerequisite course module.
     * @return string Core availability JSON.
     */
    private function restriction(int $cmid): string {
        return json_encode(['op' => '&', 'c' => [
            ['type' => 'completion', 'cm' => $cmid, 'e' => COMPLETION_COMPLETE],
        ], 'showc' => [true]]);
    }

    /**
     * Assert no issue writes, outbound HTTP or events that invoke the issuer.
     *
     * @param callable $operation Operation under test.
     * @return mixed Result.
     */
    private function guarded(callable $operation) {
        global $DB;
        $issues = $DB->get_records('local_xertify_issue', null, 'id');
        $property = new \ReflectionProperty(\curl::class, 'mockresponses');
        $property->setAccessible(true);
        $original = $property->getValue();
        $expected = ['UNEXPECTED_PHASE4_HTTP'];
        $property->setValue(null, $expected);
        $sink = $this->redirectEvents();
        try {
            $result = $operation();
            $this->assertSame($expected, $property->getValue());
            $this->assertEquals($issues, $DB->get_records('local_xertify_issue', null, 'id'));
            foreach ($sink->get_events() as $event) {
                $this->assertNotInstanceOf(\core\event\course_completed::class, $event);
            }
            return $result;
        } finally {
            $property->setValue(null, $original);
            $sink->close();
        }
    }

    /**
     * Optionally export assertion-backed synthetic evidence outside the plugin.
     *
     * @param string $name Evidence file name.
     * @param array $data Synthetic metadata only.
     */
    private function evidence(string $name, array $data): void {
        $directory = getenv('XERTIFY_PHASE4_EVIDENCE_DIR');
        if ($directory) {
            file_put_contents($directory . '/' . $name, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        }
    }

    /**
     * Generate, extract and scan the actual final archive, including binary files.
     *
     * @param bool $users Include core user information.
     * @param bool $single Back up just the viewer.
     * @return string Extracted backup identifier.
     */
    private function backup(bool $users = false, bool $single = false): string {
        global $CFG, $USER;
        return $this->guarded(function () use ($users, $single, $CFG, $USER) {
            $bc = new \backup_controller(
                $single ? \backup::TYPE_1ACTIVITY : \backup::TYPE_1COURSE,
                $single ? $this->viewer->cmid : $this->course->id,
                \backup::FORMAT_MOODLE,
                \backup::INTERACTIVE_NO,
                \backup::MODE_GENERAL,
                $USER->id
            );
            try {
                $bc->get_plan()->get_setting('users')->set_value($users);
                $bc->execute_plan();
                $id = $bc->get_backupid();
                $file = $bc->get_results()['backup_destination'];
                $this->assertInstanceOf(\stored_file::class, $file);
                $path = $CFG->tempdir . '/backup/' . $id;
                fulldelete($path);
                $this->assertNotFalse($file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $path));
                $list = [];
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
                foreach ($files as $entry) {
                    if (!$entry->isFile()) {
                        continue;
                    }
                    $list[] = substr($entry->getPathname(), strlen($path) + 1);
                    foreach ($this->forbidden as $marker) {
                        $this->assertStringNotContainsString($marker, file_get_contents($entry->getPathname()), end($list));
                    }
                }
                sort($list);
                $this->assertContains('moodle_backup.xml', $list);
                $this->assertContains('files.xml', $list);
                if (!$single) {
                    $this->assertContains('course/course.xml', $list);
                }
                $this->assertContains('activities/xertifycertificate_' . $this->viewer->cmid . '/xertifycertificate.xml', $list);
                $suffix = $users ? 'users-true' : 'users-false';
                $this->evidence('backup-file-list-' . $suffix . '.txt', $list);
                $this->evidence('backup-security-scan-' . $suffix . '.txt', [
                    'userinfo' => $users, 'single_activity' => $single, 'files_scanned' => count($list),
                    'markers' => array_fill_keys($this->forbidden, 'NOT FOUND'),
                ]);
                return $id;
            } finally {
                $bc->destroy();
            }
        });
    }

    /**
     * Restore using the real Moodle controller and optional activity selection.
     *
     * @param string $id Backup directory identifier.
     * @param int|null $destination Existing course or null for new.
     * @param bool $omitprerequisite Omit the page from the restore plan.
     * @return int Destination course ID.
     */
    private function restore(string $id, ?int $destination = null, bool $omitprerequisite = false): int {
        global $USER;
        return $this->guarded(function () use ($id, $destination, $omitprerequisite, $USER) {
            $target = $destination === null ? \backup::TARGET_NEW_COURSE : \backup::TARGET_EXISTING_ADDING;
            $destination = $destination ?? \restore_dbops::create_new_course('Restored', 'restored', $this->course->category);
            $rc = new \restore_controller($id, $destination, \backup::INTERACTIVE_NO, \backup::MODE_GENERAL, $USER->id, $target);
            try {
                if ($target === \backup::TARGET_EXISTING_ADDING) {
                    $rc->get_plan()->get_setting('overwrite_conf')->set_value(true);
                }
                if ($omitprerequisite) {
                    $rc->get_plan()->get_setting('page_' . $this->prerequisite->cmid . '_included')->set_value(false);
                }
                $this->assertTrue($rc->execute_precheck());
                $rc->execute_plan();
            } finally {
                $rc->destroy();
            }
            return (int)$destination;
        });
    }

    /**
     * Verify the local plugin's unchanged safe restore contract.
     *
     * @param int $courseid Destination course ID.
     */
    private function safe_destination(int $courseid): void {
        global $DB;
        $config = $DB->get_record('local_xertify_course', ['courseid' => $courseid], '*', MUST_EXIST);
        $this->assertSame(0, (int)$config->enabled);
        $this->assertSame('', $config->apitoken);
        $this->assertSame($this->config->templateid, $config->templateid);
        $this->assertSame(0, $DB->count_records('local_xertify_issue', ['courseid' => $courseid]));
        $this->assertEquals($this->config, $DB->get_record('local_xertify_course', ['id' => $this->config->id]));
    }

    /**
     * Resolve the restored viewer by its unique source name.
     *
     * @param int $courseid Destination course ID.
     * @return \stdClass Course module.
     */
    private function restored_viewer(int $courseid): \stdClass {
        global $DB;
        $instance = $DB->get_record('xertifycertificate', ['course' => $courseid, 'name' => $this->viewer->name], '*', MUST_EXIST);
        return get_coursemodule_from_instance('xertifycertificate', $instance->id, $courseid, false, MUST_EXIST);
    }

    /**
     * Validate common plugin fields, core module settings, files and restrictions.
     *
     * @param int $courseid Destination course ID.
     * @return array Restored viewer and prerequisite course modules.
     */
    private function assert_restored(int $courseid): array {
        global $DB;
        $cm = $this->restored_viewer($courseid);
        $instance = $DB->get_record('xertifycertificate', ['id' => $cm->instance], '*', MUST_EXIST);
        $source = $DB->get_record('xertifycertificate', ['id' => $this->viewer->id], '*', MUST_EXIST);
        $oldcm = get_coursemodule_from_id('xertifycertificate', $this->viewer->cmid);
        $this->assertNotEquals($source->id, $instance->id);
        $this->assertNotEquals($oldcm->id, $cm->id);
        $this->assertSame($courseid, (int)$instance->course);
        foreach (['name', 'intro', 'introformat', 'timecreated', 'timemodified'] as $field) {
            $this->assertSame($source->$field, $instance->$field);
        }
        foreach (['visible', 'completion', 'completionview', 'showdescription', 'idnumber', 'groupmode'] as $field) {
            $this->assertSame($oldcm->$field, $cm->$field, $field);
        }
        $section = $DB->get_record('course_sections', ['id' => $cm->section], '*', MUST_EXIST);
        $this->assertSame('Certificación', $section->name);
        $page = $DB->get_record('page', ['course' => $courseid, 'name' => 'Prerequisite'], '*', MUST_EXIST);
        $prerequisite = get_coursemodule_from_instance('page', $page->id, $courseid);
        $sequence = explode(',', $section->sequence);
        $this->assertLessThan(array_search($cm->id, $sequence), array_search($prerequisite->id, $sequence));
        $condition = json_decode($cm->availability)->c[0];
        $this->assertSame((int)$prerequisite->id, $condition->cm);
        $this->assertNotEquals($this->prerequisite->cmid, $condition->cm);
        $context = \context_module::instance($cm->id);
        $oldcontext = \context_module::instance($this->viewer->cmid);
        $file = get_file_storage()->get_file($context->id, 'mod_xertifycertificate', 'intro', 0, '/', 'intro.svg');
        $oldfile = get_file_storage()->get_file($oldcontext->id, 'mod_xertifycertificate', 'intro', 0, '/', 'intro.svg');
        $this->assertNotFalse($file);
        $this->assertSame($oldfile->get_content(), $file->get_content());
        $this->assertNotEquals($oldcontext->id, $file->get_contextid());
        $this->assertSame($context->id, (int)$file->get_contextid());
        $this->evidence('restore-activity-summary.txt', [
            'original_course' => $this->course->id, 'restored_course' => $courseid,
            'original_instance' => $source->id, 'restored_instance' => $instance->id,
            'original_cmid' => $oldcm->id, 'restored_cmid' => $cm->id,
            'original_dependency_cmid' => $this->prerequisite->cmid, 'restored_dependency_cmid' => $prerequisite->id,
            'original_context' => $oldcontext->id, 'restored_context' => $context->id,
            'intro_file_restored' => true, 'completion' => $cm->completion, 'completionview' => $cm->completionview,
        ]);
        return [$cm, $prerequisite];
    }

    /**
     * Final archives contain exactly portable metadata in both user-data modes.
     *
     * @dataProvider user_modes
     * @param bool $users Include core user data.
     */
    public function test_archive_structure_and_security(bool $users): void {
        global $CFG;
        $id = $this->backup($users);
        $path = $CFG->tempdir . '/backup/' . $id;
        $xml = simplexml_load_file($path . '/activities/xertifycertificate_' . $this->viewer->cmid . '/xertifycertificate.xml');
        $record = $xml->xertifycertificate;
        $this->assertSame((string)$this->viewer->id, (string)$record['id']);
        $this->assertSame(['name', 'intro', 'introformat', 'timecreated', 'timemodified'], array_keys((array)$record->children()));
        $this->assertSame($this->viewer->intro, (string)$record->intro);
        $this->assertSame((string)FORMAT_HTML, (string)$record->introformat);
        $this->assertCount(0, $record->xpath('course|userid|completion|availability'));
        $files = simplexml_load_file($path . '/files.xml');
        $introfiles = $files->xpath('//file[component="mod_xertifycertificate" and filearea="intro" and filename="intro.svg"]');
        $this->assertCount(1, $introfiles);
        $this->safe_destination($this->restore($id));
    }

    /**
     * Both global user-data modes.
     *
     * @return array Cases.
     */
    public static function user_modes(): array {
        return [[false], [true]];
    }

    /**
     * New-course restore preserves settings but never imports certificate data.
     *
     * @dataProvider visibility_modes
     * @param int $visible Module visibility.
     */
    public function test_new_course_restore_and_access(int $visible): void {
        global $DB;
        $DB->set_field('course_modules', 'visible', $visible, ['id' => $this->viewer->cmid]);
        rebuild_course_cache($this->course->id, true);
        $oldcompletion = new \completion_info($this->course);
        $oldcompletion->set_module_viewed(get_coursemodule_from_id('xertifycertificate', $this->viewer->cmid), $this->student->id);
        $id = $this->restore($this->backup());
        $this->safe_destination($id);
        [$cm, $prerequisite] = $this->assert_restored($id);
        $this->assertSame(0, $DB->count_records('course_modules_completion', ['coursemoduleid' => $cm->id]));
        $this->getDataGenerator()->enrol_user($this->student->id, $id, 'student');
        if (!$visible) {
            set_coursemodule_visible($cm->id, 1);
            rebuild_course_cache($id, true);
        }
        $this->setUser($this->student);
        foreach (['certificate', 'pdf'] as $method) {
            try {
                global $PAGE;
                $PAGE = new \moodle_page();
                \mod_xertifycertificate\local\activity::$method($cm->id);
                $this->fail('The prerequisite must block direct access.');
            } catch (\moodle_exception $exception) {
                $this->assertStringContainsString('Unsupported redirect detected', $exception->getMessage());
            }
        }
        $completion = new \completion_info(get_course($id));
        $completion->update_state($prerequisite, COMPLETION_COMPLETE, $this->student->id);
        rebuild_course_cache($id, true);
        $dto = $this->certificate($cm->id);
        $this->assertSame('noissue', $dto['state']);
        $this->guarded(function () use ($cm) {
            global $CFG, $DB, $USER, $PAGE, $OUTPUT;
            $PAGE = new \moodle_page();
            $OUTPUT = new \bootstrap_renderer();
            $_GET = ['id' => $cm->id];
            ob_start();
            try {
                include($CFG->dirroot . '/mod/xertifycertificate/view.php');
                $this->assertStringContainsString('Your certificate is not available yet.', ob_get_contents());
            } finally {
                ob_end_clean();
                $_GET = [];
            }
        });
        $this->assertSame(COMPLETION_VIEWED, (int)$completion->get_data($cm, false, $this->student->id)->viewed);
        $this->evidence('access-completion-summary.txt', ['before' => 'BLOCKED', 'after' => 'ALLOWED',
            'old_user_completion_copied' => false, 'restored_view_recorded' => true, 'cmid' => $cm->id]);
    }

    /**
     * Visible and hidden source metadata.
     *
     * @return array Cases.
     */
    public static function visibility_modes(): array {
        return [[1], [0]];
    }

    /**
     * Exercise the actual course duplication entry point.
     */
    public function test_duplicate_course(): void {
        $result = $this->guarded(function () {
            return \core_course_external::duplicate_course(
                $this->course->id,
                'Duplicated',
                'duplicated',
                $this->course->category,
                1,
                [['name' => 'users', 'value' => 0]]
            );
        });
        $id = (int)$result['id'];
        $this->safe_destination($id);
        [$cm] = $this->assert_restored($id);
        $this->evidence('duplicate-course-summary.txt', ['course' => $id, 'cmid' => $cm->id,
            'activity' => true, 'remapped_restriction' => true, 'completion_preserved' => true,
            'issues_copied' => false, 'token_copied' => false, 'emission' => false]);
    }

    /**
     * Core activity duplication preserves the same-course prerequisite and issue.
     */
    public function test_duplicate_activity_and_delete(): void {
        global $DB;
        $source = get_fast_modinfo($this->course)->get_cm($this->viewer->cmid);
        $copy = $this->guarded(function () use ($source) {
            return duplicate_module($this->course, $source);
        });
        $this->assertNotNull($copy);
        $this->assertNotEquals($source->instance, $copy->instance);
        $this->assertSame((int)$source->course, (int)$copy->course);
        $this->assertSame($this->prerequisite->cmid, json_decode($copy->availability)->c[0]->cm);
        $this->assertSame((int)$source->completionview, (int)$copy->completionview);
        $completion = new \completion_info($this->course);
        $prerequisite = get_coursemodule_from_id('page', $this->prerequisite->cmid);
        $completion->update_state($prerequisite, COMPLETION_COMPLETE, $this->student->id);
        $this->setUser($this->student);
        $originaldto = $this->certificate($source->id);
        $this->assertSame($originaldto, $this->certificate($copy->id));
        $this->assertSame('TESTCERTIFICATE123', $originaldto['certificatecode']);
        $this->setAdminUser();
        $config = $DB->get_record('local_xertify_course', ['id' => $this->config->id]);
        $this->guarded(function () use ($copy) {
            course_delete_module($copy->id);
        });
        $this->assertTrue($DB->record_exists('xertifycertificate', ['id' => $source->instance]));
        $this->assertFalse($DB->record_exists('xertifycertificate', ['id' => $copy->instance]));
        $this->assertEquals($config, $DB->get_record('local_xertify_course', ['id' => $config->id]));
        $this->evidence('duplicate-activity-summary.txt', ['original_instance' => $source->instance,
            'duplicated_instance' => $copy->instance, 'same_course' => true,
            'dependency_retained' => $this->prerequisite->cmid, 'same_certificate' => true,
            'new_issue' => false, 'emission' => false, 'delete_affects_issue' => false]);
    }

    /**
     * Partial restore relies on core's dependency handling.
     *
     * @dataProvider user_modes
     * @param bool $omit Omit the prerequisite from the restore selection.
     */
    public function test_partial_restore(bool $omit): void {
        global $DB;
        $id = $this->restore($this->backup(), null, $omit);
        $cm = $this->restored_viewer($id);
        if (!$omit) {
            $this->assert_restored($id);
        } else {
            $this->assertFalse($DB->record_exists('page', ['course' => $id]));
            // This assertion records Moodle core's actual missing-dependency policy.
            $this->assertSame(0, json_decode($cm->availability)->c[0]->cm);
            $this->getDataGenerator()->enrol_user($this->student->id, $id, 'student');
            $this->setUser($this->student);
            $this->assertFalse(get_fast_modinfo($id)->get_cm($cm->id)->uservisible);
        }
        $this->evidence('partial-' . ($omit ? 'without' : 'with') . '-dependency.txt', [
            'course' => $id, 'cmid' => $cm->id, 'availability' => $cm->availability,
            'dependency_included' => !$omit,
        ]);
    }

    /**
     * A single-activity archive also restores without the omitted prerequisite.
     */
    public function test_single_activity_archive(): void {
        $id = $this->restore($this->backup(false, true));
        $cm = $this->restored_viewer($id);
        $this->assertSame(0, json_decode($cm->availability)->c[0]->cm);
        $this->getDataGenerator()->enrol_user($this->student->id, $id, 'student');
        $this->setUser($this->student);
        $this->assertFalse(get_fast_modinfo($id)->get_cm($cm->id)->uservisible);
        $this->assertSame($id, (int)$cm->course);
    }

    /**
     * Existing activity, configuration, token and issues survive an adding restore.
     */
    public function test_existing_destination(): void {
        global $DB;
        $destination = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $existing = $this->getDataGenerator()->create_module('xertifycertificate', ['course' => $destination->id]);
        $config = clone $this->config;
        unset($config->id);
        $config->courseid = $destination->id;
        $config->apitoken = 'TEST_DESTINATION_TOKEN_PHASE4';
        $config->id = $DB->insert_record('local_xertify_course', $config);
        $issue = $DB->get_record('local_xertify_issue', ['courseid' => $this->course->id]);
        unset($issue->id);
        $issue->courseid = $destination->id;
        $issue->certificatecode = 'TEST_DESTINATION_CERT';
        $issue->id = $DB->insert_record('local_xertify_issue', $issue);
        $before = $DB->get_record('xertifycertificate', ['id' => $existing->id]);
        $this->restore($this->backup(), (int)$destination->id);
        $this->assertEquals($before, $DB->get_record('xertifycertificate', ['id' => $existing->id]));
        $this->assertEquals($config, $DB->get_record('local_xertify_course', ['id' => $config->id]));
        $this->assertEquals($issue, $DB->get_record('local_xertify_issue', ['id' => $issue->id]));
        $this->assertSame(2, $DB->count_records('xertifycertificate', ['course' => $destination->id]));
        $this->evidence('existing-destination-summary.txt', ['course' => $destination->id,
            'existing_activity_preserved' => true, 'configuration_preserved' => true,
            'token_preserved' => true, 'issues_preserved' => true, 'emission' => false]);
    }

    /**
     * Independent conditions survive while every instance reads one association.
     */
    public function test_multiple_activities(): void {
        global $DB;
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $this->course->id, 'name' => 'Other prerequisite', 'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $other = $this->getDataGenerator()->create_module('xertifycertificate', [
            'course' => $this->course->id, 'name' => 'Other viewer', 'availability' => $this->restriction($page->cmid),
        ]);
        $id = $this->restore($this->backup());
        $this->safe_destination($id);
        $this->assertSame(2, $DB->count_records('xertifycertificate', ['course' => $id]));
        [$first, $firstpage] = $this->assert_restored($id);
        $instance = $DB->get_record('xertifycertificate', ['course' => $id, 'name' => 'Other viewer']);
        $second = get_coursemodule_from_instance('xertifycertificate', $instance->id, $id);
        $this->assertNotEquals($other->id, $instance->id);
        $newpage = $DB->get_record('page', ['course' => $id, 'name' => 'Other prerequisite']);
        $secondpage = get_coursemodule_from_instance('page', $newpage->id, $id);
        $this->assertSame((int)$secondpage->id, json_decode($second->availability)->c[0]->cm);
        $this->assertNotEquals($firstpage->id, $secondpage->id);
        $this->getDataGenerator()->enrol_user($this->student->id, $id, 'student');
        $completion = new \completion_info(get_course($id));
        $completion->update_state($firstpage, COMPLETION_COMPLETE, $this->student->id);
        $completion->update_state($secondpage, COMPLETION_COMPLETE, $this->student->id);
        $this->setUser($this->student);
        $this->assertSame('noissue', $this->certificate($first->id)['state']);
        $this->assertSame('noissue', $this->certificate($second->id)['state']);
        // A fresh synthetic destination association demonstrates shared reads; it is not restored or emitted.
        $issue = $DB->get_record('local_xertify_issue', ['courseid' => $this->course->id]);
        unset($issue->id);
        $issue->courseid = $id;
        $issue->certificatecode = 'TEST_FRESH_DESTINATION_ASSOCIATION';
        $DB->insert_record('local_xertify_issue', $issue);
        $this->assertSame(
            $this->certificate($first->id),
            $this->certificate($second->id)
        );
        $this->assertSame(1, $DB->count_records('local_xertify_issue', ['courseid' => $id]));
        $this->evidence('multiple-activities-summary.txt', ['original_count' => 2, 'restored_count' => 2,
            'independent_restrictions' => true, 'same_fresh_association' => true, 'copied_issues' => 0]);
    }

    /**
     * Core may restore selected user completion; the module has no such structure.
     */
    public function test_user_completion_is_owned_by_core(): void {
        global $CFG, $DB;
        $completion = new \completion_info($this->course);
        $source = get_coursemodule_from_id('xertifycertificate', $this->viewer->cmid);
        $completion->set_module_viewed($source, $this->student->id);
        $backupid = $this->backup(true);
        $path = $CFG->tempdir . '/backup/' . $backupid . '/activities/xertifycertificate_' . $source->id;
        $this->assertFileExists($path . '/completion.xml');
        $ownxml = simplexml_load_file($path . '/xertifycertificate.xml');
        $this->assertCount(0, $ownxml->xpath('//userid|//completion|//viewed'));
        $id = $this->restore($backupid);
        $cm = $this->restored_viewer($id);
        $destination = new \completion_info(get_course($id));
        $this->assertSame(COMPLETION_VIEWED, (int)$destination->get_data($cm, false, $this->student->id)->viewed);
        $this->assertSame(0, $DB->count_records('local_xertify_issue', ['courseid' => $id]));
        $this->evidence('core-user-completion-summary.txt', ['userinfo' => true,
            'core_restored_selected_user_completion' => true, 'plugin_user_completion_structure' => false]);
    }

    /**
     * Existing view, PDF and index links in authored content use new core IDs.
     */
    public function test_content_links_remap(): void {
        global $CFG, $DB;
        $intro = '<a href="' . $CFG->wwwroot . '/mod/xertifycertificate/view.php?id=' . $this->viewer->cmid . '">View</a>';
        $intro .= '<a href="' . $CFG->wwwroot . '/mod/xertifycertificate/pdf.php?id='
            . $this->viewer->cmid . '&amp;download=1">PDF</a>';
        $intro .= '<a href="' . $CFG->wwwroot . '/mod/xertifycertificate/index.php?id=' . $this->course->id . '">Index</a>';
        $DB->set_field('xertifycertificate', 'intro', $intro, ['id' => $this->viewer->id]);
        $id = $this->restore($this->backup());
        $cm = $this->restored_viewer($id);
        $actual = $DB->get_field('xertifycertificate', 'intro', ['id' => $cm->instance]);
        $this->assertStringContainsString('/view.php?id=' . $cm->id . '"', $actual);
        $this->assertStringContainsString('/pdf.php?id=' . $cm->id . '&amp;download=1', $actual);
        $this->assertStringContainsString('/index.php?id=' . $id . '"', $actual);
        $this->assertStringNotContainsString('$@XERTIFYCERTIFICATE', $actual);
    }

    /**
     * Simulate a fresh request before resolving each activity.
     *
     * @param int $cmid Course module ID.
     * @return array Certificate DTO.
     */
    private function certificate(int $cmid): array {
        global $PAGE;
        $PAGE = new \moodle_page();
        return \mod_xertifycertificate\local\activity::certificate($cmid);
    }

    /**
     * Backup code has no direct issuance dependency or plugin-owned user data.
     */
    public function test_feature_and_no_issuance_dependencies(): void {
        require_once(__DIR__ . '/../lib.php');
        $this->assertTrue(xertifycertificate_supports(FEATURE_BACKUP_MOODLE2));
        foreach (glob(__DIR__ . '/../backup/moodle2/*.php') as $file) {
            foreach (token_get_all(file_get_contents($file)) as $token) {
                if (!is_array($token) || ($token[0] !== T_STRING && strpos(token_name($token[0]), 'T_NAME_') !== 0)) {
                    continue;
                }
                foreach (explode('\\', strtolower($token[1])) as $part) {
                    $forbidden = ['issuer', 'client', 'issue', 'issue_for_course_completion', 'course_completed'];
                    $this->assertNotContains($part, $forbidden);
                }
            }
            $this->assertStringNotContainsString('local_xertify_issue', file_get_contents($file));
        }
    }
}
