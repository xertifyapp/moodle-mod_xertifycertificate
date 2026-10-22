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
 * Regression coverage for the activity upgrade entry point.
 *
 * @package    mod_xertifycertificate
 * @copyright  2026 Xertify
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_xertifycertificate;

/**
 * Verify the upgrade preserves the installed schema and activity records.
 *
 * @covers ::xmldb_xertifycertificate_upgrade
 */
final class upgrade_test extends \advanced_testcase {
    /**
     * Execute the real entry point against a populated Moodle test database.
     */
    public function test_upgrade_preserves_schema_and_activity(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $path = $CFG->dirroot . '/mod/xertifycertificate/db/upgrade.php';
        $this->assertFileExists($path);
        require_once($path);
        $this->assertTrue(function_exists('xmldb_xertifycertificate_upgrade'));
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('xertifycertificate', ['course' => $course->id]);
        $records = $DB->get_records('xertifycertificate', null, 'id');
        $tables = $DB->get_tables(false);
        $columns = $DB->get_columns('xertifycertificate', false);
        $indexes = $DB->get_indexes('xertifycertificate');
        $writes = $DB->perf_get_writes();
        $this->assertTrue(xmldb_xertifycertificate_upgrade(2026102100));
        $this->assertSame($writes, $DB->perf_get_writes());
        $this->assertSame($tables, $DB->get_tables(false));
        $this->assertEquals($columns, $DB->get_columns('xertifycertificate', false));
        $this->assertEquals($indexes, $DB->get_indexes('xertifycertificate'));
        $this->assertEquals($records, $DB->get_records('xertifycertificate', null, 'id'));
    }
}
