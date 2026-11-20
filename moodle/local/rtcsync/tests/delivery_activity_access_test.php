<?php

namespace local_rtcsync;

use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/** Database proxy for delivery activity cache and visibility restoration failures. */
final class delivery_activity_visibility_restore_fault_database
{
    /** @var moodle_database */
    private $database;

    /** @var bool */
    private $throwonvisibilityupdate;

    /** @var bool */
    private $nooponvisibilityupdate;

    /** @var bool */
    private $throwoncacherevisionupdate;

    /** @var int */
    private $cacherevisioncourseid;

    /** @var bool */
    private $throwoncacherevisionpersistently;

    /** @var bool */
    public $visibilityupdateattempted = false;

    /** @var int */
    public $cacherevisionupdateattempts = 0;

    public function __construct(
        $database,
        bool $throwonvisibilityupdate = false,
        bool $throwoncacherevisionupdate = false,
        int $cacherevisioncourseid = 0,
        bool $throwoncacherevisionpersistently = false,
        bool $nooponvisibilityupdate = false
    ) {
        $this->database = $database;
        $this->throwonvisibilityupdate = $throwonvisibilityupdate;
        $this->nooponvisibilityupdate = $nooponvisibilityupdate;
        $this->throwoncacherevisionupdate = $throwoncacherevisionupdate;
        $this->cacherevisioncourseid = $cacherevisioncourseid;
        $this->throwoncacherevisionpersistently = $throwoncacherevisionpersistently;
    }

    public function __call(string $name, array $arguments)
    {
        if ($name === 'execute' && isset($arguments[0])
                && stripos((string) $arguments[0], 'UPDATE {course}') !== false
                && stripos((string) $arguments[0], 'SET cacherev') !== false) {
            $params = $arguments[1] ?? [];
            $updatedcourseid = (int) ($params['id'] ?? 0);
            if ($this->cacherevisioncourseid === 0 || $updatedcourseid === $this->cacherevisioncourseid) {
                $this->cacherevisionupdateattempts++;
            }
            if ($this->throwoncacherevisionupdate
                    && ($this->cacherevisioncourseid === 0 || $updatedcourseid === $this->cacherevisioncourseid)) {
                if (!$this->throwoncacherevisionpersistently) {
                    $this->throwoncacherevisionupdate = false;
                }
                throw new \coding_exception('Simulated course cache invalidation failure.');
            }
        }

        if ($name === 'execute' && isset($arguments[0])
                && strpos((string) $arguments[0], 'UPDATE {course}') !== false
                && strpos((string) $arguments[0], 'accessstate.timemodified') !== false) {
            $this->visibilityupdateattempted = true;
            if ($this->throwonvisibilityupdate) {
                throw new \coding_exception('Unexpected visibility restore inside an outer transaction.');
            }
            if ($this->nooponvisibilityupdate) {
                return false;
            }
        }

        return call_user_func_array([$this->database, $name], $arguments);
    }
}

/** Tests delivery-lineage evidence used by learner access guards. */
#[CoversClass(\local_rtcsync\local\delivery_activity_access::class)]
final class delivery_activity_access_test extends \advanced_testcase
{
    private function assertUnreviewedModuleGuard(int $cmid): void
    {
        global $DB;

        $mapping = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => $cmid], '*', MUST_EXIST);
        $this->assertSame(0, (int) $mapping->deliveryid);
        $this->assertSame(-$cmid, (int) $mapping->groupingid);
        $this->assertSame(
            \local_rtcsync\local\delivery_activity_access::LINEAGE_UNREVIEWED,
            (int) $mapping->lineagestatus
        );
        $this->assertNull($mapping->lineagemanifestid);
        $this->assertNotSame('', trim((string) $mapping->managedavailability));
        $this->assertSame(
            (string) $mapping->managedavailability,
            (string) $DB->get_field('course_modules', 'availability', ['id' => $cmid], MUST_EXIST)
        );
    }

    public function test_final_page_visibility_restore_failure_rolls_back_readiness(): void
    {
        global $DB;

        $this->resetAfterTest();
        set_config('enableavailability', 1);
        if (!\local_rtcsync\local\delivery_activity_access::supports_standard_grouping_restrictions()) {
            $this->markTestSkipped('Standard grouping restrictions are required for course preparation.');
        }

        $course = $this->getDataGenerator()->create_course([
            'idnumber' => 'unmanaged:visibility-restore',
            'visible' => 1,
        ]);
        $firstmodule = $this->getDataGenerator()->create_module('page', [
            'course' => (int) $course->id,
            'name' => 'First visibility restoration fixture',
        ]);
        $lastmodule = $this->getDataGenerator()->create_module('page', [
            'course' => (int) $course->id,
            'name' => 'Final visibility restoration fixture',
        ]);
        $DB->set_field('course', 'idnumber', 'rtc-subject:70032', ['id' => (int) $course->id]);
        $firstcmid = (int) $firstmodule->cmid;
        $lastcmid = (int) $lastmodule->cmid;
        $this->assertGreaterThan($firstcmid, $lastcmid);

        // Persist one full page, then fault the nonempty final page's visibility restore.
        $this->assertFalse(\local_rtcsync\local\delivery_activity_access::prepare_course((int) $course->id, 1));
        $this->assertSame(0, (int) $DB->get_field('course', 'visible', ['id' => (int) $course->id], MUST_EXIST));
        $this->assertSame(0, (int) $DB->get_field('local_rtcsync_deliverycourse', 'ready', [
            'courseid' => (int) $course->id,
        ], MUST_EXIST));
        $this->assertSame($firstcmid, (int) $DB->get_field('local_rtcsync_deliverycourse', 'lastcmid', [
            'courseid' => (int) $course->id,
        ], MUST_EXIST));

        $originaldatabase = $DB;
        $DB = new delivery_activity_visibility_restore_fault_database(
            $originaldatabase,
            false,
            false,
            0,
            false,
            true
        );
        $failure = null;
        try {
            \local_rtcsync\local\delivery_activity_access::prepare_course((int) $course->id, 2);
        } catch (\coding_exception $exception) {
            $failure = $exception;
        } finally {
            $DB = $originaldatabase;
        }

        $this->assertInstanceOf(\coding_exception::class, $failure);
        $this->assertStringContainsString('Could not restore course visibility', $failure->getMessage());
        $this->assertSame(0, (int) $DB->get_field('course', 'visible', ['id' => (int) $course->id], MUST_EXIST));
        $this->assertSame(0, (int) get_course((int) $course->id)->visible);
        $this->assertSame(0, (int) $DB->get_field('local_rtcsync_deliverycourse', 'ready', [
            'courseid' => (int) $course->id,
        ], MUST_EXIST));
        $this->assertSame($firstcmid, (int) $DB->get_field('local_rtcsync_deliverycourse', 'lastcmid', [
            'courseid' => (int) $course->id,
        ], MUST_EXIST));
        $this->assertSame(1, (int) $DB->get_field('local_rtcsync_deliverycourse', 'coursevisible', [
            'courseid' => (int) $course->id,
        ], MUST_EXIST));
        $this->assertUnreviewedModuleGuard($firstcmid);
        $this->assertUnreviewedModuleGuard($lastcmid);

        // A subsequent preparation retries the final page and can publish
        // readiness only after the visibility write succeeds.
        $this->assertTrue(\local_rtcsync\local\delivery_activity_access::prepare_course((int) $course->id, 2));
        $this->assertSame(1, (int) $DB->get_field('course', 'visible', ['id' => (int) $course->id], MUST_EXIST));
        $this->assertSame(1, (int) get_course((int) $course->id)->visible);
        $this->assertSame(1, (int) $DB->get_field('local_rtcsync_deliverycourse', 'ready', [
            'courseid' => (int) $course->id,
        ], MUST_EXIST));
        $this->assertSame($lastcmid, (int) $DB->get_field('local_rtcsync_deliverycourse', 'lastcmid', [
            'courseid' => (int) $course->id,
        ], MUST_EXIST));
        $this->assertUnreviewedModuleGuard($firstcmid);
        $this->assertUnreviewedModuleGuard($lastcmid);
    }

    public function test_visibility_restore_noop_never_invokes_ready_access_callback(): void
    {
        global $DB;

        $this->resetAfterTest();
        set_config('enableavailability', 1);
        if (!\local_rtcsync\local\delivery_activity_access::supports_standard_grouping_restrictions()) {
            $this->markTestSkipped('Standard grouping restrictions are required for course preparation.');
        }

        $course = $this->getDataGenerator()->create_course([
            'idnumber' => 'unmanaged:visibility-restore-callback',
            'visible' => 1,
        ]);
        $module = $this->getDataGenerator()->create_module('page', [
            'course' => (int) $course->id,
            'name' => 'Visibility restore callback fixture',
        ]);
        $DB->set_field('course', 'idnumber', 'rtc-subject:visibility-restore-callback', ['id' => (int) $course->id]);
        $courseid = (int) $course->id;
        $cmid = (int) $module->cmid;

        // Persist the guard page while the course is locked and not ready.
        $this->assertFalse(\local_rtcsync\local\delivery_activity_access::prepare_course($courseid, 1));
        $this->assertSame(0, (int) $DB->get_field('course', 'visible', ['id' => $courseid], MUST_EXIST));

        $originaldatabase = $DB;
        $DB = new delivery_activity_visibility_restore_fault_database(
            $originaldatabase,
            false,
            false,
            0,
            false,
            true
        );
        $callbackinvoked = false;
        $failure = null;
        try {
            \local_rtcsync\local\delivery_activity_access::with_ready_course(
                $courseid,
                static function() use (&$callbackinvoked): void {
                    $callbackinvoked = true;
                }
            );
        } catch (\coding_exception $exception) {
            $failure = $exception;
        } finally {
            $DB = $originaldatabase;
        }

        $this->assertInstanceOf(\coding_exception::class, $failure);
        $this->assertStringContainsString('Could not restore course visibility', $failure->getMessage());
        $this->assertFalse($callbackinvoked, 'The ready-only callback must not run after a failed visibility restore.');
        $this->assertSame(0, (int) $DB->get_field('course', 'visible', ['id' => $courseid], MUST_EXIST));
        $state = $DB->get_record('local_rtcsync_deliverycourse', ['courseid' => $courseid], '*', MUST_EXIST);
        $this->assertSame(0, (int) $state->ready);
        $this->assertSame(1, (int) $state->coursevisible);
        $this->assertSame($cmid, (int) $state->lastcmid);

        $callbackinvoked = false;
        \local_rtcsync\local\delivery_activity_access::with_ready_course(
            $courseid,
            static function() use (&$callbackinvoked): void {
                $callbackinvoked = true;
            }
        );
        $this->assertTrue($callbackinvoked);
        $this->assertSame(1, (int) $DB->get_field('course', 'visible', ['id' => $courseid], MUST_EXIST));
        $this->assertSame(1, (int) $DB->get_field('local_rtcsync_deliverycourse', 'ready', [
            'courseid' => $courseid,
        ], MUST_EXIST));
    }

    public function test_ready_fast_path_retries_visibility_restore_after_silent_failure(): void
    {
        global $DB;

        $this->resetAfterTest();
        set_config('enableavailability', 1);
        if (!\local_rtcsync\local\delivery_activity_access::supports_standard_grouping_restrictions()) {
            $this->markTestSkipped('Standard grouping restrictions are required for course preparation.');
        }

        $course = $this->getDataGenerator()->create_course([
            'idnumber' => 'unmanaged:ready-visibility-restore',
            'visible' => 1,
        ]);
        $module = $this->getDataGenerator()->create_module('page', [
            'course' => (int) $course->id,
            'name' => 'Ready fast-path visibility fixture',
        ]);
        $DB->set_field('course', 'idnumber', 'rtc-subject:ready-visibility-restore', ['id' => (int) $course->id]);
        $this->assertTrue(\local_rtcsync\local\delivery_activity_access::prepare_course((int) $course->id, 2));

        $DB->set_field('course', 'visible', 0, ['id' => (int) $course->id]);
        $this->assertSame(1, (int) $DB->get_field('local_rtcsync_deliverycourse', 'ready', [
            'courseid' => (int) $course->id,
        ], MUST_EXIST));
        $this->assertSame(1, (int) $DB->get_field('local_rtcsync_deliverycourse', 'coursevisible', [
            'courseid' => (int) $course->id,
        ], MUST_EXIST));

        $originaldatabase = $DB;
        $faultdatabase = new delivery_activity_visibility_restore_fault_database(
            $originaldatabase,
            false,
            false,
            0,
            false,
            true
        );
        $DB = $faultdatabase;
        $failure = null;
        try {
            \local_rtcsync\local\delivery_activity_access::prepare_course((int) $course->id, 2);
        } catch (\coding_exception $exception) {
            $failure = $exception;
        } finally {
            $DB = $originaldatabase;
        }

        $this->assertTrue($faultdatabase->visibilityupdateattempted);
        $this->assertInstanceOf(\coding_exception::class, $failure);
        $this->assertStringContainsString('Could not restore course visibility', $failure->getMessage());
        $this->assertSame(0, (int) $DB->get_field('course', 'visible', ['id' => (int) $course->id], MUST_EXIST));
        $this->assertSame(0, (int) $DB->get_field('local_rtcsync_deliverycourse', 'ready', [
            'courseid' => (int) $course->id,
        ], MUST_EXIST));
        $this->assertSame((int) $module->cmid, (int) $DB->get_field('local_rtcsync_deliverycourse', 'lastcmid', [
            'courseid' => (int) $course->id,
        ], MUST_EXIST));

        $this->assertTrue(\local_rtcsync\local\delivery_activity_access::prepare_course((int) $course->id, 2));
        $this->assertSame(1, (int) $DB->get_field('course', 'visible', ['id' => (int) $course->id], MUST_EXIST));
        $this->assertSame(1, (int) $DB->get_field('local_rtcsync_deliverycourse', 'ready', [
            'courseid' => (int) $course->id,
        ], MUST_EXIST));
    }

    public function test_module_event_defers_first_visibility_restore_until_outer_transaction_commits(): void
    {
        global $DB;

        $this->resetAfterTest();
        set_config('enableavailability', 1);
        if (!\local_rtcsync\local\delivery_activity_access::supports_standard_grouping_restrictions()) {
            $this->markTestSkipped('Standard grouping restrictions are required for course preparation.');
        }

        // Create the module while the course is unmanaged so its normal create observer does no RTC work.
        $course = $this->getDataGenerator()->create_course([
            'idnumber' => 'unmanaged:nested-preparation',
            'visible' => 1,
        ]);
        $module = $this->getDataGenerator()->create_module('page', [
            'course' => (int) $course->id,
            'name' => 'Nested preparation visibility fixture',
        ]);
        $DB->set_field('course', 'idnumber', 'rtc-subject:nested-preparation', ['id' => (int) $course->id]);

        $originaldatabase = $DB;
        $outertransaction = $originaldatabase->start_delegated_transaction();
        $faultdatabase = new delivery_activity_visibility_restore_fault_database($originaldatabase, true);
        $DB = $faultdatabase;
        $failure = null;
        try {
            \local_rtcsync\local\delivery_activity_access::handle_module_event((int) $module->cmid);
        } catch (\Throwable $exception) {
            $failure = $exception;
        } finally {
            $DB = $originaldatabase;
        }

        if ($failure !== null) {
            try {
                $outertransaction->rollback($failure);
            } catch (\Throwable) {
                // rollback() rethrows the original exception after closing the transaction.
            }
            $this->fail('Module-event preparation failed inside the outer transaction: ' . $failure->getMessage());
        }
        $outertransaction->allow_commit();

        $cmid = (int) $module->cmid;
        $this->assertFalse($faultdatabase->visibilityupdateattempted);
        $this->assertSame(0, (int) $DB->get_field('course', 'visible', ['id' => (int) $course->id], MUST_EXIST));
        $this->assertSame(0, (int) $DB->get_field('local_rtcsync_deliverycourse', 'ready', [
            'courseid' => (int) $course->id,
        ], MUST_EXIST));
        $this->assertSame($cmid, (int) $DB->get_field('local_rtcsync_deliverycourse', 'lastcmid', [
            'courseid' => (int) $course->id,
        ], MUST_EXIST));
        $this->assertSame(1, (int) $DB->get_field('local_rtcsync_deliverycourse', 'coursevisible', [
            'courseid' => (int) $course->id,
        ], MUST_EXIST));
        $this->assertUnreviewedModuleGuard($cmid);

        // The post-commit pass can safely publish readiness and restore requested visibility.
        $this->assertTrue(\local_rtcsync\local\delivery_activity_access::prepare_course((int) $course->id));
        $this->assertSame(1, (int) $DB->get_field('course', 'visible', ['id' => (int) $course->id], MUST_EXIST));
        $this->assertSame(1, (int) $DB->get_field('local_rtcsync_deliverycourse', 'ready', [
            'courseid' => (int) $course->id,
        ], MUST_EXIST));
        $this->assertUnreviewedModuleGuard($cmid);
    }

    public function test_cache_invalidation_failure_returns_course_to_pending_for_backfill_retry(): void
    {
        global $DB;

        $this->resetAfterTest();
        set_config('enableavailability', 1);
        if (!\local_rtcsync\local\delivery_activity_access::supports_standard_grouping_restrictions()) {
            $this->markTestSkipped('Standard grouping restrictions are required for course preparation.');
        }

        $course = $this->getDataGenerator()->create_course([
            'idnumber' => 'unmanaged:cache-invalidation-retry',
            'visible' => 1,
        ]);
        $module = $this->getDataGenerator()->create_module('page', [
            'course' => (int) $course->id,
            'name' => 'Course cache retry fixture',
        ]);
        $DB->set_field('course', 'idnumber', 'rtc-subject:cache-invalidation-retry', ['id' => (int) $course->id]);
        $cmid = (int) $module->cmid;

        // Persist the module guard and cursor so the next pass is an empty final page.
        $this->assertFalse(\local_rtcsync\local\delivery_activity_access::prepare_course((int) $course->id, 1));
        $this->assertSame($cmid, (int) $DB->get_field('local_rtcsync_deliverycourse', 'lastcmid', [
            'courseid' => (int) $course->id,
        ], MUST_EXIST));

        $originaldatabase = $DB;
        $faultdatabase = new delivery_activity_visibility_restore_fault_database(
            $originaldatabase,
            false,
            true,
            (int) $course->id,
            true
        );
        $coursecatcache = \cache::make('core', 'coursecat');
        $coursecatcachekey = 'rtcsync-cache-failure-' . (int) $course->id;
        $coursecatcache->set($coursecatcachekey, 'stale visible course result');
        $DB = $faultdatabase;
        $failure = null;
        try {
            \local_rtcsync\local\delivery_activity_access::prepare_course((int) $course->id);
        } catch (\moodle_exception $exception) {
            $failure = $exception;
        } finally {
            $DB = $originaldatabase;
        }

        $this->assertInstanceOf(\moodle_exception::class, $failure);
        $this->assertSame(2, $faultdatabase->cacherevisionupdateattempts);
        $this->assertFalse($coursecatcache->get($coursecatcachekey));
        $this->assertSame(0, (int) $DB->get_field('course', 'visible', ['id' => (int) $course->id], MUST_EXIST));
        $this->assertSame(0, (int) $DB->get_field('local_rtcsync_deliverycourse', 'ready', [
            'courseid' => (int) $course->id,
        ], MUST_EXIST));
        $this->assertSame(0, (int) $DB->get_field('local_rtcsync_deliverycourse', 'lastcmid', [
            'courseid' => (int) $course->id,
        ], MUST_EXIST));
        $this->assertUnreviewedModuleGuard($cmid);

        // The scheduled backfill selects ready=0 rows and can complete after cache recovery.
        $this->assertSame(1, \local_rtcsync\local\delivery_activity_access::backfill_pending_courses(10, 100));
        $this->assertSame(1, (int) $DB->get_field('course', 'visible', ['id' => (int) $course->id], MUST_EXIST));
        $this->assertSame(1, (int) $DB->get_field('local_rtcsync_deliverycourse', 'ready', [
            'courseid' => (int) $course->id,
        ], MUST_EXIST));
        $this->assertSame($cmid, (int) $DB->get_field('local_rtcsync_deliverycourse', 'lastcmid', [
            'courseid' => (int) $course->id,
        ], MUST_EXIST));
        $this->assertUnreviewedModuleGuard($cmid);
    }

    public function test_backfill_continues_after_one_course_cache_failure(): void
    {
        global $DB;

        $this->resetAfterTest();
        set_config('enableavailability', 1);
        if (!\local_rtcsync\local\delivery_activity_access::supports_standard_grouping_restrictions()) {
            $this->markTestSkipped('Standard grouping restrictions are required for course preparation.');
        }

        $firstcourse = $this->getDataGenerator()->create_course([
            'idnumber' => 'unmanaged:backfill-isolation-first',
            'visible' => 1,
        ]);
        $firstmodule = $this->getDataGenerator()->create_module('page', [
            'course' => (int) $firstcourse->id,
            'name' => 'First backfill isolation fixture',
        ]);
        $DB->set_field('course', 'idnumber', 'rtc-subject:backfill-isolation-first', ['id' => (int) $firstcourse->id]);
        $firstcmid = (int) $firstmodule->cmid;
        $this->assertFalse(\local_rtcsync\local\delivery_activity_access::prepare_course((int) $firstcourse->id, 1));

        $secondcourse = $this->getDataGenerator()->create_course([
            'idnumber' => 'unmanaged:backfill-isolation-second',
            'visible' => 1,
        ]);
        $secondfirstmodule = $this->getDataGenerator()->create_module('page', [
            'course' => (int) $secondcourse->id,
            'name' => 'Second backfill first page fixture',
        ]);
        $secondlastmodule = $this->getDataGenerator()->create_module('page', [
            'course' => (int) $secondcourse->id,
            'name' => 'Second backfill final page fixture',
        ]);
        $DB->set_field('course', 'idnumber', 'rtc-subject:backfill-isolation-second', ['id' => (int) $secondcourse->id]);
        $secondfirstcmid = (int) $secondfirstmodule->cmid;
        $secondlastcmid = (int) $secondlastmodule->cmid;
        $this->assertGreaterThan($secondfirstcmid, $secondlastcmid);
        $this->assertFalse(\local_rtcsync\local\delivery_activity_access::prepare_course((int) $secondcourse->id, 1));

        $originaldatabase = $DB;
        $faultdatabase = new delivery_activity_visibility_restore_fault_database(
            $originaldatabase,
            false,
            true,
            (int) $firstcourse->id,
            true
        );
        $DB = $faultdatabase;
        $failure = null;
        // Backfill deliberately logs per-course failures; assert the diagnostic rather than leave it risky output.
        $this->expectOutputRegex('/^Delivery activity backfill failed for course \\d+:/m');
        try {
            \local_rtcsync\local\delivery_activity_access::backfill_pending_courses(10, 100);
        } catch (\moodle_exception $exception) {
            $failure = $exception;
        } finally {
            $DB = $originaldatabase;
        }

        $this->assertInstanceOf(\moodle_exception::class, $failure);
        $this->assertStringContainsString((string) $firstcourse->id, $failure->getMessage());
        $this->assertSame(3, $faultdatabase->cacherevisionupdateattempts);

        // The failed course stays hidden and pending, while the later course completed its page.
        $this->assertSame(0, (int) $DB->get_field('course', 'visible', ['id' => (int) $firstcourse->id], MUST_EXIST));
        $this->assertSame(0, (int) $DB->get_field('local_rtcsync_deliverycourse', 'ready', [
            'courseid' => (int) $firstcourse->id,
        ], MUST_EXIST));
        $this->assertSame(0, (int) $DB->get_field('local_rtcsync_deliverycourse', 'lastcmid', [
            'courseid' => (int) $firstcourse->id,
        ], MUST_EXIST));
        $this->assertSame(1, (int) $DB->get_field('course', 'visible', ['id' => (int) $secondcourse->id], MUST_EXIST));
        $this->assertSame(1, (int) $DB->get_field('local_rtcsync_deliverycourse', 'ready', [
            'courseid' => (int) $secondcourse->id,
        ], MUST_EXIST));
        $this->assertSame($secondlastcmid, (int) $DB->get_field('local_rtcsync_deliverycourse', 'lastcmid', [
            'courseid' => (int) $secondcourse->id,
        ], MUST_EXIST));
        $this->assertUnreviewedModuleGuard($firstcmid);
        $this->assertUnreviewedModuleGuard($secondfirstcmid);
        $this->assertUnreviewedModuleGuard($secondlastcmid);
    }

    public function test_tracked_module_keeps_grade_reports_restricted_without_state_or_grouping(): void
    {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['idnumber' => 'unmanaged:tracked-module']);
        $student = $this->getDataGenerator()->create_user();
        $studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $this->getDataGenerator()->enrol_user((int) $student->id, (int) $course->id, $studentroleid);
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => (int) $course->id,
            'name' => 'Tracked but not ready activity',
        ]);
        $DB->set_field('course', 'idnumber', 'rtc-subject:70031', ['id' => (int) $course->id]);
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
