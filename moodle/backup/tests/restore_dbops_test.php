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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace core_backup;

use advanced_testcase;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Tests for the restore course-content purge boundary.
 *
 * @package    core_backup
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class restore_dbops_test extends advanced_testcase {
    /** Verify restore-overwrite honors cancellation before removing course content. */
    public function test_restore_course_content_deletion_can_be_cancelled(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $notes = $this->getDataGenerator()->get_plugin_generator('core_notes');
        $note = $notes->create_instance(['courseid' => $course->id, 'userid' => $user->id]);

        $receivedhook = null;
        $options = ['keep_roles_and_enrolments' => true];
        $this->redirectHook(
            \core_course\hook\before_course_data_purge::class,
            static function (\core_course\hook\before_course_data_purge $hook) use (&$receivedhook): void {
                $receivedhook = $hook;
                $hook->stop_propagation();
            }
        );

        try {
            \restore_dbops::delete_course_content($course->id, $options);
            $this->fail('Restore-overwrite must stop when a purge listener cancels it.');
        } catch (moodle_exception $exception) {
            $this->assertSame('coursepurgestopped', $exception->errorcode);
        }

        $this->assertInstanceOf(\core_course\hook\before_course_data_purge::class, $receivedhook);
        $this->assertSame((int) $course->id, $receivedhook->courseid);
        $this->assertSame(
            \core_course\hook\before_course_data_purge::OPERATION_RESTORE_OVERWRITE,
            $receivedhook->operation
        );
        $this->assertSame($options, $receivedhook->options);
        $this->assertTrue($DB->record_exists('course', ['id' => $course->id]));
        $this->assertNotFalse(note_load($note->id));
    }
}
