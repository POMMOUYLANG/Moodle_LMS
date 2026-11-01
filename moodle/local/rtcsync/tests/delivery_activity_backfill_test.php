<?php

namespace local_rtcsync;

use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/** Tests replay behavior for the scheduled delivery activity backfill. */
#[CoversClass(\local_rtcsync\task\delivery_activity_backfill::class)]
final class delivery_activity_backfill_test extends \advanced_testcase
{
    /** Build availability JSON containing one exact grouping condition. */
    private function groupingAvailability(int $groupingid): string
    {
        return json_encode(\core_availability\tree::get_root_json([
            \availability_grouping\condition::get_json($groupingid),
        ]), JSON_THROW_ON_ERROR);
    }

    public function test_retry_replays_a_partially_guarded_page_without_duplicate_or_cross_delivery_mapping(): void
    {
        global $CFG, $DB;

        $this->resetAfterTest();
        // Use a non-reentrant factory so a competing scheduler attempt in this
        // process observes the same per-course lock contention as another run.
        $CFG->lock_factory = '\\core\\lock\\file_lock_factory';
        set_config('enableavailability', 1);
        $now = time();
        $course = $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-subject:delivery-backfill-replay',
            'visible' => 1,
        ]);
        $firstmodule = $this->getDataGenerator()->create_module('quiz', [
            'course' => (int) $course->id,
            'name' => 'Previously reviewed delivery activity',
            'grade' => 100,
        ]);
        $secondmodule = $this->getDataGenerator()->create_module('quiz', [
            'course' => (int) $course->id,
            'name' => 'Unreviewed delivery activity',
            'grade' => 100,
        ]);
        $firstcmid = (int) $firstmodule->cmid;
        $secondcmid = (int) $secondmodule->cmid;
        $firstdeliveryid = 6601;
        $seconddeliveryid = 6602;
        $firstgrouping = $this->getDataGenerator()->create_grouping([
            'courseid' => (int) $course->id,
            'name' => 'Reviewed replay delivery',
            'idnumber' => 'rtc-delivery-grouping:' . $firstdeliveryid,
        ]);
        $secondgrouping = $this->getDataGenerator()->create_grouping([
            'courseid' => (int) $course->id,
            'name' => 'Unreviewed replay delivery',
            'idnumber' => 'rtc-delivery-grouping:' . $seconddeliveryid,
        ]);
        $firstavailability = $this->groupingAvailability((int) $firstgrouping->id);
        $secondavailability = $this->groupingAvailability((int) $secondgrouping->id);
        $DB->set_field('course_modules', 'groupingid', (int) $firstgrouping->id, ['id' => $firstcmid]);
        $DB->set_field('course_modules', 'availability', $firstavailability, ['id' => $firstcmid]);
        $DB->set_field('course_modules', 'groupingid', (int) $secondgrouping->id, ['id' => $secondcmid]);
        $DB->set_field('course_modules', 'availability', $secondavailability, ['id' => $secondcmid]);

        \local_rtcsync\local\delivery_activity_access::ensure_grouping_ui_enabled((int) $course->id);
        $firstmanifestid = hash('sha256', 'reviewed backfill replay fixture');
        $DB->insert_record('local_rtcsync_deliverycm', (object) [
            'cmid' => $firstcmid,
            'courseid' => (int) $course->id,
            'deliveryid' => $firstdeliveryid,
            'groupingid' => (int) $firstgrouping->id,
            'originalavailability' => $firstavailability,
            'managedavailability' => null,
            'lineagestatus' => \local_rtcsync\local\delivery_activity_access::LINEAGE_CURRENT,
            'lineagemanifestid' => $firstmanifestid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        // Reproduce a persisted prefix of a page that was interrupted before
        // its page cursor could be checkpointed. This is the same per-module
        // operation the scheduled task performs, with no production failpoint.
        $this->assertTrue(\local_rtcsync\local\delivery_activity_access::protect_course_module($firstcmid, false));
        $interruptedstate = $DB->get_record(
            'local_rtcsync_deliverycourse',
            ['courseid' => (int) $course->id],
            '*',
            MUST_EXIST
        );
        $this->assertSame(0, (int) $interruptedstate->ready);
        $this->assertSame(0, (int) $interruptedstate->lastcmid);
        $firstbefore = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => $firstcmid], '*', MUST_EXIST);
        $this->assertSame($firstdeliveryid, (int) $firstbefore->deliveryid);
        $this->assertSame(\local_rtcsync\local\delivery_activity_access::LINEAGE_CURRENT, (int) $firstbefore->lineagestatus);
        $this->assertSame($firstmanifestid, (string) $firstbefore->lineagemanifestid);
        $this->assertStringContainsString('"id":' . (int) $firstgrouping->id, (string) $firstbefore->managedavailability);
        $this->assertSame($firstbefore->managedavailability, $DB->get_field(
            'course_modules',
            'availability',
            ['id' => $firstcmid]
        ));
        $this->assertSame(0, (int) $DB->get_field('course', 'visible', ['id' => (int) $course->id]));

        $lockfactory = \core\lock\lock_config::get_lock_factory('local_rtcsync_delivery_access');
        $concurrentlock = $lockfactory->get_lock('course:' . (int) $course->id, 0);
        $this->assertNotFalse($concurrentlock);
        $timedout = false;
        try {
            (new \local_rtcsync\task\delivery_activity_backfill())->execute();
        } catch (\moodle_exception $exception) {
            $timedout = $exception->errorcode === 'locktimeout';
        } finally {
            $concurrentlock->release();
        }
        $this->assertTrue($timedout, 'A competing scheduler attempt must wait for the course access lock.');
        $this->assertSame(0, (int) $DB->get_field('local_rtcsync_deliverycourse', 'ready', ['courseid' => (int) $course->id]));
        $this->assertSame(0, (int) $DB->get_field('local_rtcsync_deliverycourse', 'lastcmid', ['courseid' => (int) $course->id]));
        $this->assertSame(0, (int) $DB->get_field('course', 'visible', ['id' => (int) $course->id]));
        $this->assertEquals($firstbefore, $DB->get_record('local_rtcsync_deliverycm', ['cmid' => $firstcmid], '*', MUST_EXIST));
        $this->assertFalse($DB->record_exists('local_rtcsync_deliverycm', ['cmid' => $secondcmid]));

        // A ready course with its own exact mapping must not be touched by a
        // retry that only selects pending delivery-course states.
        $unrelatedcourse = $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-subject:unrelated-backfill-replay',
            'visible' => 1,
        ]);
        $unrelatedmodule = $this->getDataGenerator()->create_module('quiz', [
            'course' => (int) $unrelatedcourse->id,
            'name' => 'Unrelated reviewed activity',
            'grade' => 100,
        ]);
        $unrelatedcmid = (int) $unrelatedmodule->cmid;
        $unrelateddeliveryid = 6603;
        $unrelatedgrouping = $this->getDataGenerator()->create_grouping([
            'courseid' => (int) $unrelatedcourse->id,
            'name' => 'Unrelated delivery',
            'idnumber' => 'rtc-delivery-grouping:' . $unrelateddeliveryid,
        ]);
        $unrelatedavailability = $this->groupingAvailability((int) $unrelatedgrouping->id);
        $DB->set_field('course_modules', 'groupingid', (int) $unrelatedgrouping->id, ['id' => $unrelatedcmid]);
        $DB->set_field('course_modules', 'availability', $unrelatedavailability, ['id' => $unrelatedcmid]);
        $DB->insert_record('local_rtcsync_deliverycourse', (object) [
            'courseid' => (int) $unrelatedcourse->id,
            'coursevisible' => 1,
            'ready' => 1,
            'lastcmid' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $unrelatedmanifestid = hash('sha256', 'unrelated backfill replay fixture');
        $DB->insert_record('local_rtcsync_deliverycm', (object) [
            'cmid' => $unrelatedcmid,
            'courseid' => (int) $unrelatedcourse->id,
            'deliveryid' => $unrelateddeliveryid,
            'groupingid' => (int) $unrelatedgrouping->id,
            'originalavailability' => $unrelatedavailability,
            'managedavailability' => null,
            'lineagestatus' => \local_rtcsync\local\delivery_activity_access::LINEAGE_CURRENT,
            'lineagemanifestid' => $unrelatedmanifestid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $this->assertTrue(\local_rtcsync\local\delivery_activity_access::protect_course_module($unrelatedcmid, false));
        $unrelatedstatebefore = $DB->get_record(
            'local_rtcsync_deliverycourse',
            ['courseid' => (int) $unrelatedcourse->id],
            '*',
            MUST_EXIST
        );
        $unrelatedmappingbefore = $DB->get_record(
            'local_rtcsync_deliverycm',
            ['cmid' => $unrelatedcmid],
            '*',
            MUST_EXIST
        );

        (new \local_rtcsync\task\delivery_activity_backfill())->execute();

        $firstmappings = $DB->get_records('local_rtcsync_deliverycm', ['cmid' => $firstcmid]);
        $this->assertCount(1, $firstmappings);
        $firstafter = reset($firstmappings);
        $this->assertSame($firstdeliveryid, (int) $firstafter->deliveryid);
        $this->assertSame((int) $firstgrouping->id, (int) $firstafter->groupingid);
        $this->assertSame(\local_rtcsync\local\delivery_activity_access::LINEAGE_CURRENT, (int) $firstafter->lineagestatus);
        $this->assertSame($firstmanifestid, (string) $firstafter->lineagemanifestid);

        $secondmappings = $DB->get_records('local_rtcsync_deliverycm', ['cmid' => $secondcmid]);
        $this->assertCount(1, $secondmappings);
        $secondmapping = reset($secondmappings);
        $this->assertSame(0, (int) $secondmapping->deliveryid);
        $this->assertSame(-$secondcmid, (int) $secondmapping->groupingid);
        $this->assertSame(\local_rtcsync\local\delivery_activity_access::LINEAGE_UNREVIEWED, (int) $secondmapping->lineagestatus);
        $this->assertStringContainsString('"id":-' . $secondcmid, (string) $secondmapping->managedavailability);
        $this->assertSame($secondmapping->managedavailability, $DB->get_field(
            'course_modules',
            'availability',
            ['id' => $secondcmid]
        ));
        $this->assertSame(2, $DB->count_records('local_rtcsync_deliverycm', ['courseid' => (int) $course->id]));
        $this->assertSame(0, $DB->count_records('local_rtcsync_deliverycmhist', ['courseid' => (int) $course->id]));

        $stateafter = $DB->get_record('local_rtcsync_deliverycourse', ['courseid' => (int) $course->id], '*', MUST_EXIST);
        $this->assertSame(1, (int) $stateafter->ready);
        $this->assertSame($secondcmid, (int) $stateafter->lastcmid);
        $this->assertSame(1, (int) $DB->get_field('course', 'visible', ['id' => (int) $course->id]));

        $this->assertEquals($unrelatedstatebefore, $DB->get_record(
            'local_rtcsync_deliverycourse',
            ['courseid' => (int) $unrelatedcourse->id],
            '*',
            MUST_EXIST
        ));
        $this->assertEquals($unrelatedmappingbefore, $DB->get_record(
            'local_rtcsync_deliverycm',
            ['cmid' => $unrelatedcmid],
            '*',
            MUST_EXIST
        ));
        $this->assertSame(1, $DB->count_records('local_rtcsync_deliverycm', ['cmid' => $unrelatedcmid]));

        // A later scheduler replay sees no pending page and leaves all rows intact.
        (new \local_rtcsync\task\delivery_activity_backfill())->execute();
        $this->assertSame(2, $DB->count_records('local_rtcsync_deliverycm', ['courseid' => (int) $course->id]));
        $this->assertEquals($stateafter, $DB->get_record(
            'local_rtcsync_deliverycourse',
            ['courseid' => (int) $course->id],
            '*',
            MUST_EXIST
        ));
        $this->assertEquals($unrelatedmappingbefore, $DB->get_record(
            'local_rtcsync_deliverycm',
            ['cmid' => $unrelatedcmid],
            '*',
            MUST_EXIST
        ));
    }
}
