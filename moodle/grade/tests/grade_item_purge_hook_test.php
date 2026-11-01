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

namespace core_grades;

use advanced_testcase;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests the early course-purge hook at grade deletion boundaries.
 *
 * @package    core_grades
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class grade_item_purge_hook_test extends advanced_testcase {
    /** Verify grade clearing is cancelled before grade rows or feedback files are changed. */
    public function test_grade_item_bulk_clear_can_be_cancelled(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        require_once($CFG->libdir . '/gradelib.php');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $item = new \grade_item([
            'courseid' => (int) $course->id,
            'itemname' => 'Purge hook test score',
            'itemtype' => 'manual',
            'itemmodule' => null,
            'iteminstance' => null,
            'itemnumber' => 0,
            'idnumber' => 'purge-hook-test-score',
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
        ], false);
        $item->insert('grade-item-purge-hook-test');
        $item->update_final_grade((int) $user->id, 73.0, 'grade-item-purge-hook-test');

        $receivedhook = null;
        $this->redirectHook(
            \core_course\hook\before_course_data_purge::class,
            static function (\core_course\hook\before_course_data_purge $hook) use (&$receivedhook): void {
                $receivedhook = $hook;
                $hook->stop_propagation();
            }
        );

        try {
            $item->delete_all_grades('grade-item-purge-hook-test');
            $this->fail('Grade clearing must stop when a purge listener cancels it.');
        } catch (moodle_exception $exception) {
            $this->assertSame('coursepurgestopped', $exception->errorcode);
        }

        $this->assertInstanceOf(\core_course\hook\before_course_data_purge::class, $receivedhook);
        $this->assertSame((int) $course->id, $receivedhook->courseid);
        $this->assertSame(
            \core_course\hook\before_course_data_purge::OPERATION_GRADE_ITEM_GRADES_DELETE,
            $receivedhook->operation
        );
        $this->assertSame(['gradeitemid' => (int) $item->id], $receivedhook->options);
        $this->assertTrue($DB->record_exists('grade_items', ['id' => (int) $item->id]));
        $this->assertSame(73.0, (float) $DB->get_field('grade_grades', 'finalgrade', [
            'itemid' => (int) $item->id,
            'userid' => (int) $user->id,
        ]));
    }
}
