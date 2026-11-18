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
            'idnumber' => 'rtc-fixture:delivery-backfill-replay',
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
        // Build the interrupted state before marking the course as managed.
        // Otherwise the module-created observer completes the backfill while
        // the test is still constructing its fixture.
        $DB->set_field('course', 'idnumber', 'rtc-subject:delivery-backfill-replay', ['id' => (int) $course->id]);
        $DB->set_field('course', 'visible', 0, ['id' => (int) $course->id]);
        $DB->insert_record('local_rtcsync_deliverycourse', (object) [
            'courseid' => (int) $course->id,
            'coursevisible' => 1,
            'ready' => 0,
            'lastcmid' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        \local_rtcsync\local\delivery_activity_access::ensure_grouping_ui_enabled((int) $course->id);
        $firstmanifestid = hash('sha256', 'reviewed backfill replay fixture');
        $reviewedmapping = (object) [
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
        ];
        // Module creation in a delivery-managed course may already have created
        // an unreviewed guard mapping. Reuse that row to model a reviewed mapping
        // without conflicting with the unique cmid index.
        $existingmapping = $DB->get_record(
            'local_rtcsync_deliverycm',
            ['cmid' => $firstcmid],
            'id',
            IGNORE_MISSING
        );
        if ($existingmapping) {
            $reviewedmapping->id = (int) $existingmapping->id;
            $DB->update_record('local_rtcsync_deliverycm', $reviewedmapping);
        } else {
            $DB->insert_record('local_rtcsync_deliverycm', $reviewedmapping);
        }

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
        ob_start();
        try {
            (new \local_rtcsync\task\delivery_activity_backfill())->execute();
        } catch (\moodle_exception $exception) {
            $timedout = $exception->errorcode === 'deliveryactivitybackfillfailed'
                && str_contains((string) $exception->debuginfo, 'locktimeout');
        } finally {
            $taskoutput = (string) ob_get_clean();
            $concurrentlock->release();
        }
        $this->assertTrue($timedout, 'A competing scheduler attempt must wait for the course access lock.');
        $this->assertStringContainsString('locktimeout', $taskoutput);
        $this->assertSame(0, (int) $DB->get_field('local_rtcsync_deliverycourse', 'ready', ['courseid' => (int) $course->id]));
        $this->assertSame(0, (int) $DB->get_field('local_rtcsync_deliverycourse', 'lastcmid', ['courseid' => (int) $course->id]));
        $this->assertSame(0, (int) $DB->get_field('course', 'visible', ['id' => (int) $course->id]));
        $this->assertEquals($firstbefore, $DB->get_record('local_rtcsync_deliverycm', ['cmid' => $firstcmid], '*', MUST_EXIST));
        $this->assertFalse($DB->record_exists('local_rtcsync_deliverycm', ['cmid' => $secondcmid]));

        // A ready course with its own exact mapping must not be touched by a
        // retry that only selects pending delivery-course states.
        $unrelatedcourse = $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-fixture:unrelated-backfill-replay',
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
        $DB->set_field('course', 'idnumber', 'rtc-subject:unrelated-backfill-replay', [
            'id' => (int) $unrelatedcourse->id,
        ]);
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

    public function test_page_activity_current_mapping_is_quarantined_when_grouping_idnumber_is_duplicated(): void
    {
        global $DB;

        $this->resetAfterTest();
        set_config('enableavailability', 1);
        $course = $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-subject:duplicate-page-grouping',
        ]);
        $deliveryid = 6604;
        $grouping = $this->getDataGenerator()->create_grouping([
            'courseid' => (int) $course->id,
            'name' => 'Page activity delivery',
            'idnumber' => 'rtc-delivery-grouping:' . $deliveryid,
        ]);
        // The core grouping API rejects duplicate IDs; insert the legacy/corrupt row directly.
        $duplicategrouping = clone $grouping;
        unset($duplicategrouping->id);
        $duplicategrouping->name = 'Duplicate page activity delivery';
        $duplicategrouping->timecreated = time();
        $duplicategrouping->timemodified = time();
        $DB->insert_record('groupings', $duplicategrouping);
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => (int) $course->id,
            'name' => 'Mapped page activity',
        ]);
        $cmid = (int) $page->cmid;
        $availability = $this->groupingAvailability((int) $grouping->id);
        $DB->set_field('course_modules', 'availability', $availability, ['id' => $cmid]);
        $manifestid = hash('sha256', 'reviewed page delivery identity');
        $now = time();
        $mapping = (object) [
            'cmid' => $cmid,
            'courseid' => (int) $course->id,
            'deliveryid' => $deliveryid,
            'groupingid' => (int) $grouping->id,
            'originalavailability' => $availability,
            'managedavailability' => null,
            'lineagestatus' => \local_rtcsync\local\delivery_activity_access::LINEAGE_CURRENT,
            'lineagemanifestid' => $manifestid,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        // Moodle's module-created observer may already have inserted an unreviewed row.
        $existingmapping = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => $cmid], '*', IGNORE_MISSING);
        if ($existingmapping) {
            $mapping->id = (int) $existingmapping->id;
            $DB->update_record('local_rtcsync_deliverycm', $mapping);
        } else {
            $DB->insert_record('local_rtcsync_deliverycm', $mapping);
        }

        $this->assertTrue(\local_rtcsync\local\delivery_activity_access::protect_course_module($cmid, false));
        $mapping = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => $cmid], '*', MUST_EXIST);
        $historyconditions = [
            'cmid' => $cmid,
            'courseid' => (int) $course->id,
            'deliveryid' => $deliveryid,
        ];
        $this->assertSame(0, (int) $mapping->deliveryid);
        $this->assertSame(-$cmid, (int) $mapping->groupingid);
        $this->assertSame(\local_rtcsync\local\delivery_activity_access::LINEAGE_UNREVIEWED, (int) $mapping->lineagestatus);
        $this->assertNull($mapping->lineagemanifestid);
        $this->assertFalse($DB->record_exists('local_rtcsync_deliverycmhist', $historyconditions));
        $this->assertStringContainsString('"id":-' . $cmid, (string) $mapping->managedavailability);
    }
}
