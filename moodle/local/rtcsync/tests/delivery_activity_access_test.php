<?php

namespace local_rtcsync;

use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/** Tests delivery-lineage evidence used by learner access guards. */
#[CoversClass(\local_rtcsync\local\delivery_activity_access::class)]
final class delivery_activity_access_test extends \advanced_testcase
{
    public function test_tracked_module_keeps_grade_reports_restricted_without_state_or_grouping(): void
    {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['idnumber' => 'rtc-subject:70031']);
        $student = $this->getDataGenerator()->create_user();
        $studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $this->getDataGenerator()->enrol_user((int) $student->id, (int) $course->id, $studentroleid);
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => (int) $course->id,
            'name' => 'Tracked but not ready activity',
        ]);
        $cmid = (int) $quiz->cmid;
        $now = time();

        $DB->insert_record('local_rtcsync_deliverycm', (object) [
            'cmid' => $cmid,
            'courseid' => (int) $course->id,
            'deliveryid' => 0,
            'groupingid' => -$cmid,
            'originalavailability' => null,
            'managedavailability' => null,
            'lineagestatus' => \local_rtcsync\local\delivery_activity_access::LINEAGE_UNREVIEWED,
            'lineagemanifestid' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $this->assertFalse($DB->record_exists('local_rtcsync_deliverycourse', ['courseid' => (int) $course->id]));
        $this->assertSame(0, $DB->count_records_select(
            'groupings',
            'courseid = :courseid AND idnumber LIKE :pattern',
            ['courseid' => (int) $course->id, 'pattern' => 'rtc-delivery-grouping:%']
        ));
        $this->assertTrue(\local_rtcsync\local\delivery_activity_access::should_restrict_grade_report(
            (int) $student->id,
            (int) $course->id
        ));
        $this->assertTrue(\local_rtcsync\local\delivery_activity_access::should_restrict_grade_report(
            (int) $student->id,
            0
        ));
    }
}
