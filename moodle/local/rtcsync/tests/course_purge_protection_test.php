<?php

namespace local_rtcsync;

use advanced_testcase;
use core_course\hook\before_course_data_purge;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests RTC Sync protection at Moodle course-data purge boundaries.
 *
 * @package    local_rtcsync
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_purge_protection_test extends advanced_testcase
{
    protected function setUp(): void
    {
        parent::setUp();

        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
    }

    /** Route the core hook through RTC Sync's production callback. */
    private function redirectPurgeHookToRtcSync(): void
    {
        if (!class_exists(before_course_data_purge::class)) {
            return;
        }

        $this->redirectHook(
            before_course_data_purge::class,
            static function (before_course_data_purge $hook): void {
                \local_rtcsync\hook_callbacks::before_course_data_purge($hook);
            }
        );
    }

    /** Skip scenarios without a supported operation-cancelling purge hook. */
    private function requireGenericPurgeHook(): void
    {
        if (!class_exists(before_course_data_purge::class)) {
            $this->markTestSkipped(
                'This purge path has no supported operation-cancelling hook in the installed Moodle core.'
            );
        }
    }

    /** Mark a course as delivery-aware using the plugin's persisted state. */
    private function markDeliveryCourse(\stdClass $course): void
    {
        global $DB;

        $now = time();
        $DB->insert_record('local_rtcsync_deliverycourse', (object) [
            'courseid' => (int) $course->id,
            'coursevisible' => 1,
            'ready' => 1,
            'lastcmid' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /** Add unresolved historical lineage without a course marker. */
    private function addUnresolvedHistory(\stdClass $course, int $cmid = 0): void
    {
        global $DB;

        $DB->insert_record('local_rtcsync_deliverycmhist', (object) [
            'cmid' => $cmid,
            'courseid' => (int) $course->id,
            'deliveryid' => 900001,
            'timecreated' => time(),
            'lineageverified' => 0,
            'lineagemanifestid' => null,
        ]);
    }

    /** Use Moodle's stable public course-module deletion API across core versions. */
    private function deleteCourseModule(int $cmid): void
    {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        \course_delete_module($cmid);
    }

    /** Assert the public core emitter throws the expected cancellation exception. */
    private function assertPurgeBlocked(callable $purge, array $allowederrorcodes = ['coursepurgestopped']): void
    {
        try {
            $purge();
            $this->fail('Course data purge should have been stopped by RTC Sync.');
        } catch (moodle_exception $exception) {
            $this->assertContains($exception->errorcode, $allowederrorcodes);
        }
    }

    public function test_course_delete_is_blocked_for_unresolved_history_before_content_removal(): void
    {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->addUnresolvedHistory($course);
        $user = $this->getDataGenerator()->create_user();
        $notes = $this->getDataGenerator()->get_plugin_generator('core_notes');
        $note = $notes->create_instance(['courseid' => $course->id, 'userid' => $user->id]);
        $this->redirectPurgeHookToRtcSync();

        $this->assertPurgeBlocked(static function () use ($course): void {
            delete_course($course->id, false);
        });

        $this->assertTrue($DB->record_exists('course', ['id' => $course->id]));
        $this->assertNotFalse(note_load($note->id));
    }

    public function test_restore_overwrite_is_blocked_for_delivery_grouping_before_content_removal(): void
    {
        $this->requireGenericPurgeHook();
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_grouping([
            'courseid' => (int) $course->id,
            'name' => 'Delivery 900002',
            'idnumber' => 'rtc-delivery-grouping:900002',
        ]);
        $user = $this->getDataGenerator()->create_user();
        $notes = $this->getDataGenerator()->get_plugin_generator('core_notes');
        $note = $notes->create_instance(['courseid' => $course->id, 'userid' => $user->id]);
        $this->redirectPurgeHookToRtcSync();

        $this->assertPurgeBlocked(static function () use ($course): void {
            \restore_dbops::delete_course_content($course->id, ['keep_roles_and_enrolments' => true]);
        });

        $this->assertTrue($DB->record_exists('course', ['id' => $course->id]));
        $this->assertNotFalse(note_load($note->id));
    }

    public function test_course_reset_is_blocked_for_delivery_state_before_grade_or_role_reset(): void
    {
        $this->requireGenericPurgeHook();
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->markDeliveryCourse($course);
        $user = $this->getDataGenerator()->create_user();
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $roleid);
        $coursecontext = \context_course::instance($course->id);
        assign_capability('mod/forum:viewdiscussion', CAP_PREVENT, $roleid, $coursecontext->id);
        $this->assertFalse(has_capability('mod/forum:viewdiscussion', $coursecontext, $user->id));
        $this->redirectPurgeHookToRtcSync();

        $this->assertPurgeBlocked(static function () use ($course): void {
            reset_course_userdata((object) [
                'id' => $course->id,
                'reset_roles_overrides' => true,
            ]);
        });

        $this->assertFalse(has_capability('mod/forum:viewdiscussion', $coursecontext, $user->id));
    }

    public function test_activity_delete_is_blocked_for_history_only_and_restores_queued_visibility(): void
    {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->addUnresolvedHistory($course, (int) $page->cmid);
        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => (int) $page->cmid]);
        $this->redirectPurgeHookToRtcSync();

        $this->assertPurgeBlocked(function () use ($page): void {
            $this->deleteCourseModule((int) $page->cmid);
        }, ['coursepurgestopped', 'deliverymoduledeletionblocked']);

        $this->assertTrue($DB->record_exists('course_modules', ['id' => (int) $page->cmid]));
        $this->assertSame(0, (int) $DB->get_field('course_modules', 'deletioninprogress', ['id' => (int) $page->cmid]));
        $this->assertTrue($DB->record_exists('page', ['id' => (int) $page->id]));
        $this->assertTrue($DB->record_exists('local_rtcsync_deliverycmhist', [
            'cmid' => (int) $page->cmid,
            'courseid' => (int) $course->id,
            'deliveryid' => 900001,
        ]));
    }

    public function test_activity_delete_is_scoped_to_its_own_lineage(): void
    {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->markDeliveryCourse($course);
        $historypage = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->addUnresolvedHistory($course, (int) $historypage->cmid);
        $unrelatedpage = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        // Module creation in a managed course adds an unreviewed guard row. Remove it here so
        // this fixture represents an activity with no direct delivery mapping or history.
        $unrelatedmapping = $DB->get_record('local_rtcsync_deliverycm', [
            'cmid' => (int) $unrelatedpage->cmid,
        ], '*', MUST_EXIST);
        $this->assertSame(
            \local_rtcsync\local\delivery_activity_access::LINEAGE_UNREVIEWED,
            (int) $unrelatedmapping->lineagestatus
        );
        $DB->delete_records('local_rtcsync_deliverycm', ['id' => (int) $unrelatedmapping->id]);
        $this->redirectPurgeHookToRtcSync();

        $this->deleteCourseModule((int) $unrelatedpage->cmid);

        $this->assertFalse($DB->record_exists('course_modules', ['id' => (int) $unrelatedpage->cmid]));
        $this->assertTrue($DB->record_exists('course_modules', ['id' => (int) $historypage->cmid]));
        $this->assertTrue($DB->record_exists('local_rtcsync_deliverycmhist', [
            'cmid' => (int) $historypage->cmid,
            'courseid' => (int) $course->id,
            'deliveryid' => 900001,
        ]));
    }

    public function test_unmanaged_course_can_use_all_purge_paths(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->redirectPurgeHookToRtcSync();

        $deletecourse = $this->getDataGenerator()->create_course();
        $this->assertTrue(delete_course($deletecourse->id, false));
        $this->assertFalse($DB->record_exists('course', ['id' => $deletecourse->id]));

        $restorecourse = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $notes = $this->getDataGenerator()->get_plugin_generator('core_notes');
        $note = $notes->create_instance(['courseid' => $restorecourse->id, 'userid' => $user->id]);
        \restore_dbops::delete_course_content($restorecourse->id);
        $this->assertFalse(note_load($note->id));

        $activitycourse = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $activitycourse->id]);
        $this->deleteCourseModule((int) $page->cmid);
        $this->assertFalse($DB->record_exists('course_modules', ['id' => (int) $page->cmid]));

        $resetcourse = $this->getDataGenerator()->create_course();
        $resetuser = $this->getDataGenerator()->create_user();
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $this->getDataGenerator()->enrol_user($resetuser->id, $resetcourse->id, $roleid);
        $coursecontext = \context_course::instance($resetcourse->id);
        assign_capability('mod/forum:viewdiscussion', CAP_PREVENT, $roleid, $coursecontext->id);
        $this->assertFalse(has_capability('mod/forum:viewdiscussion', $coursecontext, $resetuser->id));
        reset_course_userdata((object) [
            'id' => $resetcourse->id,
            'reset_roles_overrides' => true,
        ]);
        $this->assertTrue(has_capability('mod/forum:viewdiscussion', $coursecontext, $resetuser->id));

        $gradecourse = $this->getDataGenerator()->create_course();
        $gradeitem = new \grade_item([
            'courseid' => (int) $gradecourse->id,
            'itemname' => 'Ordinary grade item',
            'itemtype' => 'manual',
            'itemmodule' => null,
            'iteminstance' => null,
            'itemnumber' => 0,
            'idnumber' => 'ordinary-grade-item',
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
        ], false);
        $gradeitem->insert('course-purge-protection-test');
        $gradeitem->delete('course-purge-protection-test');
        $this->assertFalse($DB->record_exists('grade_items', ['id' => (int) $gradeitem->id]));
    }

    public function test_unmapped_rtc_delivery_grade_identifier_blocks_course_delete(): void
    {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $item = new \grade_item([
            'courseid' => (int) $course->id,
            'itemname' => 'Unmapped RTC delivery score',
            'itemtype' => 'manual',
            'itemmodule' => null,
            'iteminstance' => null,
            'itemnumber' => 0,
            'idnumber' => 'rtc-subject-score:900003:delivery:900003',
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
        ], false);
        $item->insert('course-purge-protection-test');
        $item->update_final_grade((int) $user->id, 82.0, 'course-purge-protection-test');

        $this->assertPurgeBlocked(static function () use ($course): void {
            delete_course((int) $course->id, false);
        });

        $this->assertTrue($DB->record_exists('course', ['id' => (int) $course->id]));
        $this->assertTrue($DB->record_exists('grade_items', ['id' => (int) $item->id]));
        $this->assertSame(82.0, (float) $DB->get_field('grade_grades', 'finalgrade', [
            'itemid' => (int) $item->id,
            'userid' => (int) $user->id,
        ]));
    }

    public function test_delivery_grade_item_delete_is_blocked_before_grade_rows_are_removed(): void
    {
        $this->requireGenericPurgeHook();
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $item = new \grade_item([
            'courseid' => (int) $course->id,
            'itemname' => 'RTC delivery score to retain',
            'itemtype' => 'manual',
            'itemmodule' => null,
            'iteminstance' => null,
            'itemnumber' => 0,
            'idnumber' => 'rtc-subject-score:900005:delivery:900005',
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
        ], false);
        $item->insert('course-purge-protection-test');
        $item->update_final_grade((int) $user->id, 82.0, 'course-purge-protection-test');
        $this->redirectPurgeHookToRtcSync();

        $this->assertPurgeBlocked(static function () use ($item): void {
            $item->delete('course-purge-protection-test');
        });

        $this->assertTrue($DB->record_exists('grade_items', ['id' => (int) $item->id]));
        $this->assertSame(82.0, (float) $DB->get_field('grade_grades', 'finalgrade', [
            'itemid' => (int) $item->id,
            'userid' => (int) $user->id,
        ]));
    }

    public function test_final_unenrol_preserves_mapped_and_unmapped_delivery_grade_candidates(): void
    {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->markDeliveryCourse($course);
        $user = $this->getDataGenerator()->create_user();
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $roleid);
        $instance = $DB->get_record('enrol', [
            'courseid' => (int) $course->id,
            'enrol' => 'manual',
        ], '*', MUST_EXIST);

        $item = new \grade_item([
            'courseid' => (int) $course->id,
            'itemname' => 'Retained delivery result',
            'itemtype' => 'manual',
            'itemnumber' => 0,
            'idnumber' => 'rtc-subject-score:900007:delivery:900007',
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
        ], false);
        $item->insert('course-purge-protection-test');
        $item->update_final_grade((int) $user->id, 76.0, 'course-purge-protection-test');
        $unmappeditem = new \grade_item([
            'courseid' => (int) $course->id,
            'itemname' => 'Unmapped legacy candidate',
            'itemtype' => 'manual',
            'itemnumber' => 0,
            'idnumber' => 'legacy-grade-item',
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
        ], false);
        $unmappeditem->insert('course-purge-protection-test');
        $unmappeditem->update_final_grade((int) $user->id, 38.0, 'course-purge-protection-test');
        $DB->insert_record('local_rtcsync_deliverygrade', (object) [
            'gradeitemid' => (int) $item->id,
            'courseid' => (int) $course->id,
            'deliveryid' => 900007,
            'timecreated' => time(),
            'timemodified' => time(),
            'lineageverified' => 1,
            'lineagemanifestid' => 'retained-grade-manifest',
        ]);
        $DB->insert_record('local_rtcsync_history', (object) [
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'sourcetype' => 'delivery_participation',
            'sourceid' => 900008,
            'deliveryid' => 900007,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $this->redirectPurgeHookToRtcSync();

        try {
            enrol_get_plugin('manual')->unenrol_user($instance, (int) $user->id);
            $this->fail('Final unenrolment must stop before it removes mapped or ambiguous delivery-grade candidates.');
        } catch (moodle_exception $exception) {
            $this->assertSame('coursepurgestopped', $exception->errorcode);
        }

        $this->assertTrue($DB->record_exists('user_enrolments', [
            'enrolid' => (int) $instance->id,
            'userid' => (int) $user->id,
        ]));
        $this->assertSame(76.0, (float) $DB->get_field('grade_grades', 'finalgrade', [
            'itemid' => (int) $item->id,
            'userid' => (int) $user->id,
        ]));
        $this->assertSame(38.0, (float) $DB->get_field('grade_grades', 'finalgrade', [
            'itemid' => (int) $unmappeditem->id,
            'userid' => (int) $user->id,
        ]));
        $this->assertTrue($DB->record_exists('local_rtcsync_history', [
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'sourcetype' => 'delivery_participation',
            'sourceid' => 900008,
            'deliveryid' => 900007,
        ]));
    }

    public function test_final_unenrol_preserves_exact_delivery_grade_without_participation_marker(): void
    {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->markDeliveryCourse($course);
        $user = $this->getDataGenerator()->create_user();
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $roleid);
        $instance = $DB->get_record('enrol', [
            'courseid' => (int) $course->id,
            'enrol' => 'manual',
        ], '*', MUST_EXIST);

        $item = new \grade_item([
            'courseid' => (int) $course->id,
            'itemname' => 'Unmarked retained delivery result',
            'itemtype' => 'manual',
            'itemnumber' => 0,
            'idnumber' => 'reviewed-retention-grade-id',
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
        ], false);
        $item->insert('course-purge-protection-test');
        $item->update_final_grade((int) $user->id, 76.0, 'course-purge-protection-test');
        $DB->insert_record('local_rtcsync_deliverygrade', (object) [
            'gradeitemid' => (int) $item->id,
            'courseid' => (int) $course->id,
            'deliveryid' => 900009,
            'timecreated' => time(),
            'timemodified' => time(),
            'lineageverified' => 0,
            'lineagemanifestid' => null,
        ]);
        $this->assertFalse($DB->record_exists('local_rtcsync_history', [
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'sourcetype' => 'delivery_participation',
        ]));
        $this->redirectPurgeHookToRtcSync();

        $this->assertPurgeBlocked(static function () use ($instance, $user): void {
            enrol_get_plugin('manual')->unenrol_user($instance, (int) $user->id);
        });

        $this->assertTrue($DB->record_exists('user_enrolments', [
            'enrolid' => (int) $instance->id,
            'userid' => (int) $user->id,
        ]));
        $this->assertSame(76.0, (float) $DB->get_field('grade_grades', 'finalgrade', [
            'itemid' => (int) $item->id,
            'userid' => (int) $user->id,
        ]));
    }

    public function test_unrelated_grade_clear_is_scoped_away_from_other_delivery_mappings(): void
    {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->markDeliveryCourse($course);
        $user = $this->getDataGenerator()->create_user();
        $deliveryitem = new \grade_item([
            'courseid' => (int) $course->id,
            'itemname' => 'Mapped RTC score',
            'itemtype' => 'manual',
            'itemnumber' => 0,
            'idnumber' => 'mapped-rtc-score',
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
        ], false);
        $deliveryitem->insert('course-purge-protection-test');
        $deliveryitem->update_final_grade((int) $user->id, 91.0, 'course-purge-protection-test');
        $DB->insert_record('local_rtcsync_deliverygrade', (object) [
            'gradeitemid' => (int) $deliveryitem->id,
            'courseid' => (int) $course->id,
            'deliveryid' => 900006,
            'timecreated' => time(),
            'timemodified' => time(),
            'lineageverified' => 0,
            'lineagemanifestid' => null,
        ]);

        $unrelateditem = new \grade_item([
            'courseid' => (int) $course->id,
            'itemname' => 'Unrelated grade item',
            'itemtype' => 'manual',
            'itemnumber' => 0,
            'idnumber' => 'unrelated-score',
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
        ], false);
        $unrelateditem->insert('course-purge-protection-test');
        $unrelateditem->update_final_grade((int) $user->id, 64.0, 'course-purge-protection-test');
        $this->redirectPurgeHookToRtcSync();

        $unrelateditem->delete_all_grades('course-purge-protection-test');

        $this->assertFalse($DB->record_exists('grade_grades', [
            'itemid' => (int) $unrelateditem->id,
            'userid' => (int) $user->id,
        ]));
        $this->assertSame(91.0, (float) $DB->get_field('grade_grades', 'finalgrade', [
            'itemid' => (int) $deliveryitem->id,
            'userid' => (int) $user->id,
        ]));
        $this->assertTrue($DB->record_exists('local_rtcsync_deliverygrade', [
            'gradeitemid' => (int) $deliveryitem->id,
            'courseid' => (int) $course->id,
        ]));
    }

    public function test_released_student_history_alone_blocks_purge(): void
    {
        $this->requireGenericPurgeHook();
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_rtcsync_history', (object) [
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'sourcetype' => 'legacy',
            'sourceid' => 900004,
            'deliveryid' => null,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $hook = new before_course_data_purge(
            (int) $course->id,
            before_course_data_purge::OPERATION_RESET,
            []
        );
        hook_callbacks::before_course_data_purge($hook);

        $this->assertTrue($hook->isPropagationStopped());
    }
}
