<?php

namespace local_rtcsync;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the RTC synchronization external service.
 */
#[CoversClass(\local_rtcsync_external::class)]
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class externallib_test extends \advanced_testcase
{
    protected function setUp(): void
    {
        parent::setUp();

        global $CFG;
        require_once($CFG->dirroot . '/local/rtcsync/externallib.php');
    }

    /**
     * Build a Moodle user with the explicit RTC-Sync ownership marker.
     *
     * External-service tests should not accidentally exercise unmanaged
     * records now that the write boundary is enforced.
     *
     * @param array<string, mixed> $options
     */
    private function createManagedUser(array $options = []): \stdClass
    {
        global $DB;

        $user = $this->getDataGenerator()->create_user($options);
        if (!$DB->record_exists('local_rtcsync_user', ['userid' => (int) $user->id])) {
            $DB->insert_record('local_rtcsync_user', (object) [
                'userid' => (int) $user->id,
                'idnumber' => (string) ($user->idnumber ?? ''),
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        return $user;
    }

    /** Create a managed account using the legacy pre-stable-ID convention. */
    private function createLegacyManagedUser(string $username, string $email, string $idcard): \stdClass
    {
        global $DB;

        $user = $this->getDataGenerator()->create_user([
            'username' => $username,
            'email' => $email,
            'idnumber' => $idcard,
        ]);
        $DB->insert_record('local_rtcsync_user', (object) [
            'userid' => (int) $user->id,
            'idnumber' => $idcard,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        return $user;
    }

    /**
     * Build an RTC subject course for write-path tests.
     *
     * @param array<string, mixed> $options
     */
    private function createManagedCourse(array $options = []): \stdClass
    {
        $options['idnumber'] ??= 'rtc-subject:' . random_int(100000000, 999999999);

        return $this->getDataGenerator()->create_course($options);
    }

    /** Build an RTC subject upsert with the agreed deterministic revision key. */
    private function versionedCoursePayload(int $subjectid, int $revision, array $overrides = []): array
    {
        return array_replace([
            'fullname' => 'Versioned RTC subject ' . $subjectid,
            'shortname' => 'RTC-V-' . $subjectid,
            'idnumber' => 'rtc-subject:' . $subjectid,
            'summary' => 'Versioned subject fixture.',
            'category_idnumber' => 'rtc-versioned-test:' . $subjectid,
            'category_name' => 'RTC Versioned Test ' . $subjectid,
            'category_path' => [],
            'visible' => 1,
            'startdate' => 0,
            'enddate' => 0,
            'source_revision' => $revision,
            'idempotency_key' => 'rtc-course:' . $subjectid . ':' . $revision,
        ], $overrides);
    }

    public function test_versioned_course_upsert_replays_exact_payload_without_reapplying(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $payload = $this->versionedCoursePayload(73101, 17);

        $first = \local_rtcsync_external::upsert_course($payload);
        $this->assertSame(1, $first['applied']);
        $this->assertSame(0, $first['replayed']);
        $this->assertSame(17, $first['source_revision']);
        $state = $DB->get_record('local_rtcsync_coursesync', ['idnumber' => $payload['idnumber']], '*', MUST_EXIST);

        // Simulate a local drift after the original source mutation. A replay
        // acknowledges the already applied revision and does not overwrite it.
        $DB->set_field('course', 'fullname', 'Manual Moodle drift', ['id' => (int) $first['id']]);
        $replay = \local_rtcsync_external::upsert_course($payload);

        $this->assertSame(0, $replay['applied']);
        $this->assertSame(1, $replay['replayed']);
        $this->assertSame(17, $replay['source_revision']);
        $this->assertSame((int) $first['id'], (int) $replay['id']);
        $this->assertSame('Manual Moodle drift', (string) $DB->get_field(
            'course',
            'fullname',
            ['id' => (int) $first['id']],
            MUST_EXIST
        ));
        $this->assertEquals($state, $DB->get_record(
            'local_rtcsync_coursesync',
            ['idnumber' => $payload['idnumber']],
            '*',
            MUST_EXIST
        ));
    }

    public function test_versioned_course_replay_requires_course_update_capability(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $payload = $this->versionedCoursePayload(73106, 2);
        $created = \local_rtcsync_external::upsert_course($payload);
        // This case targets course:update, so model a fully prepared visible
        // course and leave the guard-lock behavior to its dedicated tests.
        $DB->set_field('local_rtcsync_deliverycourse', 'ready', 1, ['courseid' => (int) $created['id']]);
        $DB->set_field('local_rtcsync_deliverycourse', 'coursevisible', 1, ['courseid' => (int) $created['id']]);
        $DB->set_field('course', 'visible', 1, ['id' => (int) $created['id']]);
        rebuild_course_cache((int) $created['id'], true);

        $operator = $this->getDataGenerator()->create_user();
        $systemcontext = \context_system::instance();
        $coursecontext = \context_course::instance((int) $created['id']);
        $categorycontext = \context_coursecat::instance((int) $created['categoryid']);
        $roleid = create_role('RTC category-only sync operator', 'rtc_cat_only_sync', '');
        assign_capability('moodle/category:manage', CAP_ALLOW, $roleid, $systemcontext->id);
        // Let the participant pass the delivery-guard check; this test isolates
        // the separate course:update authorization boundary.
        assign_capability('moodle/course:ignoreavailabilityrestrictions', CAP_ALLOW, $roleid, $systemcontext->id);
        assign_capability('moodle/course:create', CAP_ALLOW, $roleid, $categorycontext->id);
        role_assign($roleid, (int) $operator->id, $systemcontext->id);
        // A real participant record is required to pass Moodle 5.3's hidden
        // course login check before testing the narrower update capability.
        $this->getDataGenerator()->enrol_user((int) $operator->id, (int) $created['id'], 'student');
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertTrue(has_capability('moodle/category:manage', $systemcontext, $operator));
        $this->assertTrue(has_capability('moodle/course:create', $categorycontext, $operator));
        $this->assertFalse(has_capability('moodle/course:update', $coursecontext, $operator));
        $this->setUser($operator);
        $this->expectException(\required_capability_exception::class);

        // The exact-replay branch returns the existing course mapping but must
        // not bypass the capability required by the original upsert path.
        \local_rtcsync_external::upsert_course($payload);
    }

    public function test_versioned_course_same_revision_with_changed_payload_is_rejected(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $payload = $this->versionedCoursePayload(73102, 8);
        $applied = \local_rtcsync_external::upsert_course($payload);
        $conflictingkey = $payload;
        $conflictingkey['idempotency_key'] = 'rtc-course:73102:7';
        try {
            \local_rtcsync_external::upsert_course($conflictingkey);
            $this->fail('A same-revision request with a different idempotency key must be rejected.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('deterministic', $exception->getMessage());
        }

        $conflicting = $payload;
        $conflicting['fullname'] = 'Conflicting payload at the same source revision';

        try {
            \local_rtcsync_external::upsert_course($conflicting);
            $this->fail('A source revision cannot be reused for a different course payload.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('already used', $exception->getMessage());
        }

        $this->assertSame($payload['fullname'], (string) $DB->get_field(
            'course',
            'fullname',
            ['id' => (int) $applied['id']],
            MUST_EXIST
        ));
        $this->assertSame(8, (int) $DB->get_field(
            'local_rtcsync_coursesync',
            'sourcerevision',
            ['idnumber' => $payload['idnumber']],
            MUST_EXIST
        ));
    }

    public function test_stale_course_revision_cannot_unarchive_course_or_create_categories(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $archived = $this->versionedCoursePayload(73103, 11, ['visible' => 0]);
        $applied = \local_rtcsync_external::upsert_course($archived);
        $stale = $this->versionedCoursePayload(73103, 10, [
            'fullname' => 'Stale attempt to restore archived course',
            'category_path' => [[
                'idnumber' => 'rtc-stale-side-effect:73103',
                'name' => 'Must not be created for a stale course revision',
            ]],
        ]);

        $result = \local_rtcsync_external::upsert_course($stale);

        $this->assertSame(0, $result['applied']);
        $this->assertSame(0, $result['replayed']);
        $this->assertSame(11, $result['source_revision']);
        $this->assertSame((int) $applied['id'], (int) $result['id']);
        $this->assertSame(0, (int) $DB->get_field(
            'course',
            'visible',
            ['id' => (int) $applied['id']],
            MUST_EXIST
        ));
        $this->assertFalse($DB->record_exists('course_categories', ['idnumber' => 'rtc-stale-side-effect:73103']));
        $this->assertSame(11, (int) $DB->get_field(
            'local_rtcsync_coursesync',
            'sourcerevision',
            ['idnumber' => $archived['idnumber']],
            MUST_EXIST
        ));

        $restored = $this->versionedCoursePayload(73103, 12, ['visible' => 1]);
        $higher = \local_rtcsync_external::upsert_course($restored);
        $this->assertSame(1, $higher['applied']);
        // The new requested visibility is retained, but an incomplete delivery
        // guard keeps the course hidden until a later committed backfill.
        $this->assertSame(0, (int) $DB->get_field(
            'course',
            'visible',
            ['id' => (int) $applied['id']],
            MUST_EXIST
        ));
        $guardstate = $DB->get_record('local_rtcsync_deliverycourse', [
            'courseid' => (int) $applied['id'],
        ], '*', MUST_EXIST);
        $this->assertSame(1, (int) $guardstate->coursevisible);
        $this->assertSame(0, (int) $guardstate->ready);
    }

    public function test_mysql_course_revision_claim_serializes_competing_writes_and_first_row_races(): void
    {
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        if ($DB->get_dbfamily() !== 'mysql'
                || (string) $CFG->dbhost !== 'phpunit-db'
                || (string) $CFG->dbname !== 'rtc_moodle_phpunit') {
            $this->markTestSkipped('This concurrency test requires the isolated MySQL PHPUnit database.');
        }
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('Independent PHP workers are required to test the database revision fence.');
        }

        $subjectid = random_int(7900000, 7999999);
        $seed = \local_rtcsync_external::upsert_course($this->versionedCoursePayload($subjectid, 30));
        $raceroot = make_temp_directory('local_rtcsync_course_revision_race');
        try {
            [$higherresult, $lowerresult] = $this->run_course_revision_race_pair(
                $raceroot . '/existing-state',
                $this->versionedCoursePayload($subjectid, 32, ['fullname' => 'Revision 32 wins']),
                $this->versionedCoursePayload($subjectid, 31, [
                    'fullname' => 'Stale revision 31',
                    'category_path' => [[
                        'idnumber' => 'rtc-race-stale:' . $subjectid,
                        'name' => 'Must not be created by the losing revision',
                    ]],
                ]),
            );

            $this->assertSame(1, $higherresult['applied']);
            $this->assertSame(32, $higherresult['source_revision']);
            $this->assertSame(0, $lowerresult['applied']);
            $this->assertSame(0, $lowerresult['replayed']);
            $this->assertSame(32, $lowerresult['source_revision']);
            $this->assertSame('Revision 32 wins', (string) $DB->get_field(
                'course',
                'fullname',
                ['id' => (int) $seed['id']],
                MUST_EXIST
            ));
            $this->assertSame(32, (int) $DB->get_field(
                'local_rtcsync_coursesync',
                'sourcerevision',
                ['idnumber' => 'rtc-subject:' . $subjectid],
                MUST_EXIST
            ));
            $this->assertFalse($DB->record_exists('course_categories', [
                'idnumber' => 'rtc-race-stale:' . $subjectid,
            ]));

            $firstsubjectid = random_int(8000000, 8099999);
            [$firsthigherresult, $firstlowerresult] = $this->run_course_revision_race_pair(
                $raceroot . '/first-state',
                $this->versionedCoursePayload($firstsubjectid, 42, ['fullname' => 'First claim revision 42']),
                $this->versionedCoursePayload($firstsubjectid, 41, [
                    'fullname' => 'Losing first claim revision 41',
                    'category_path' => [[
                        'idnumber' => 'rtc-first-claim-stale:' . $firstsubjectid,
                        'name' => 'Must not be created by the losing first claim',
                    ]],
                ]),
            );

            $this->assertSame(1, $firsthigherresult['applied']);
            $this->assertSame(42, $firsthigherresult['source_revision']);
            $this->assertSame(0, $firstlowerresult['applied']);
            $this->assertSame(0, $firstlowerresult['replayed']);
            $this->assertSame(42, $firstlowerresult['source_revision']);
            $this->assertSame(1, $DB->count_records('course', ['idnumber' => 'rtc-subject:' . $firstsubjectid]));
            $this->assertSame(1, $DB->count_records(
                'local_rtcsync_coursesync',
                ['idnumber' => 'rtc-subject:' . $firstsubjectid]
            ));
            $this->assertFalse($DB->record_exists('course_categories', [
                'idnumber' => 'rtc-first-claim-stale:' . $firstsubjectid,
            ]));
            $this->assertSame('First claim revision 42', (string) $DB->get_field(
                'course',
                'fullname',
                ['idnumber' => 'rtc-subject:' . $firstsubjectid],
                MUST_EXIST
            ));
        } finally {
            remove_dir($raceroot);
        }
    }

    /** Run independent workers that both read the same initial course state, then order the higher write first. */
    private function run_course_revision_race_pair(string $barrierdir, array $higher, array $lower): array
    {
        if (!mkdir($barrierdir, 0770, true) && !is_dir($barrierdir)) {
            throw new \RuntimeException('Could not create the course revision race barrier directory.');
        }

        $workers = [];
        try {
            // Initialise Moodle's cache config before workers bootstrap concurrently.
            // The cache config writer uses the shared cachelock_file directory.
            \core_cache\config::instance();

            $workers['higher'] = $this->start_course_revision_race_worker($barrierdir, 'higher', $higher);
            $workers['lower'] = $this->start_course_revision_race_worker($barrierdir, 'lower', $lower);
            $this->wait_for_course_revision_race_file($barrierdir . '/higher.ready', $workers['higher'], 'higher');
            $this->wait_for_course_revision_race_file($barrierdir . '/lower.ready', $workers['lower'], 'lower');

            file_put_contents($barrierdir . '/higher.release', 'go', LOCK_EX);
            $higherresult = $this->finish_course_revision_race_worker($workers['higher']);
            file_put_contents($barrierdir . '/lower.release', 'go', LOCK_EX);
            $lowerresult = $this->finish_course_revision_race_worker($workers['lower']);

            return [$higherresult, $lowerresult];
        } finally {
            file_put_contents($barrierdir . '/higher.release', 'cleanup', LOCK_EX);
            file_put_contents($barrierdir . '/lower.release', 'cleanup', LOCK_EX);
            foreach ($workers as &$worker) {
                $this->stop_course_revision_race_worker($worker);
            }
            unset($worker);
            foreach (['higher', 'lower'] as $name) {
                $lockroot = $this->course_revision_race_worker_lock_root($barrierdir, $name);
                if (is_dir($lockroot)) {
                    remove_dir($lockroot);
                }
            }
        }
    }

    /** Return a per-worker lock root outside the PHPUnit dataroot. */
    private function course_revision_race_worker_lock_root(string $barrierdir, string $name): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . 'rtc-moodle-course-race-lock-' . hash('sha256', $barrierdir . "\0" . $name);
    }

    /** Start one isolated PHP worker with its own local file-lock directory. */
    private function start_course_revision_race_worker(string $barrierdir, string $name, array $payload): array
    {
        global $CFG;

        $lockroot = $this->course_revision_race_worker_lock_root($barrierdir, $name);
        if (!mkdir($lockroot, 0770, true)) {
            throw new \RuntimeException('Could not create the worker-local Moodle lock directory.');
        }

        $workerpath = __DIR__ . '/fixtures/course_revision_race_worker.php';
        $payloadjson = json_encode($payload, JSON_THROW_ON_ERROR);
        $command = [
            PHP_BINARY,
            $workerpath,
            base64_encode($payloadjson),
            $barrierdir,
            $name,
            (string) $CFG->prefix,
            (string) $CFG->dataroot,
            (string) $CFG->wwwroot,
            $lockroot,
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start a course revision race worker.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [
            'process' => $process,
            'pipes' => $pipes,
            'resultpath' => $barrierdir . '/' . $name . '.result.json',
        ];
    }

    /** Wait for one worker's barrier or result file without touching the database. */
    private function wait_for_course_revision_race_file(string $path, array &$worker, string $name): void
    {
        $deadline = microtime(true) + 60;
        while (!is_file($path) && microtime(true) < $deadline) {
            $status = proc_get_status($worker['process']);
            if (!$status['running']) {
                $stdout = stream_get_contents($worker['pipes'][1]);
                $stderr = stream_get_contents($worker['pipes'][2]);
                $this->fail('Course revision race worker ' . $name . ' exited before its barrier '
                    . '(exit ' . $status['exitcode'] . '). Output: ' . $stdout . "\n" . $stderr);
            }
            usleep(10000);
        }
        if (!is_file($path)) {
            $status = proc_get_status($worker['process']);
            $stdout = stream_get_contents($worker['pipes'][1]);
            $stderr = stream_get_contents($worker['pipes'][2]);
            $this->fail('A course revision race worker did not reach its barrier in time. '
                . 'Worker ' . $name . ' running=' . ($status['running'] ? 'yes' : 'no')
                . ', exit=' . $status['exitcode'] . '. Output: ' . $stdout . "\n" . $stderr);
        }
    }

    /** Collect a completed worker result and close its process resources. */
    private function finish_course_revision_race_worker(array &$worker): array
    {
        $process = $worker['process'];
        $deadline = microtime(true) + 60;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        if (!empty($status['running'])) {
            proc_terminate($process);
            $this->fail('A course revision race worker did not finish in time.');
        }

        $stdout = stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $closecode = proc_close($process);
        $worker['process'] = null;
        $worker['pipes'] = [];

        $exitcode = (int) $status['exitcode'];
        if ($exitcode < 0) {
            $exitcode = $closecode;
        }
        $this->assertSame(0, $exitcode, 'Worker output: ' . $stdout . "\n" . $stderr);
        $this->assertFileExists($worker['resultpath']);
        $result = json_decode((string) file_get_contents($worker['resultpath']), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('result', $result, (string) ($result['error'] ?? 'Worker returned no result.'));

        return $result['result'];
    }

    /** Release or terminate a worker if an assertion interrupted the race test. */
    private function stop_course_revision_race_worker(array &$worker): void
    {
        if (empty($worker['process']) || !is_resource($worker['process'])) {
            return;
        }
        $status = proc_get_status($worker['process']);
        if ($status['running']) {
            proc_terminate($worker['process']);
        }
        foreach ($worker['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($worker['process']);
        $worker['process'] = null;
        $worker['pipes'] = [];
    }

    public function test_legacy_course_upsert_is_allowed_until_versioned_fence_is_established(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $legacy = [
            'fullname' => 'Legacy RTC subject',
            'shortname' => 'RTC-LEGACY-73104',
            'idnumber' => 'rtc-subject:73104',
            'summary' => '',
            'category_idnumber' => 'rtc-legacy-test:73104',
            'category_name' => 'RTC Legacy Test 73104',
            'visible' => 1,
        ];
        $first = \local_rtcsync_external::upsert_course($legacy);
        $versioned = $this->versionedCoursePayload(73104, 3);
        \local_rtcsync_external::upsert_course($versioned);
        $legacy['fullname'] = 'Legacy write after revision opt-in';

        try {
            \local_rtcsync_external::upsert_course($legacy);
            $this->fail('An unversioned mutation must not bypass an established revision fence.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('opted into source revisions', $exception->getMessage());
        }

        $this->assertSame($versioned['fullname'], (string) $DB->get_field(
            'course',
            'fullname',
            ['id' => (int) $first['id']],
            MUST_EXIST
        ));
    }

    public function test_first_versioned_course_upsert_rejects_duplicate_stable_idnumber(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $idnumber = 'rtc-subject:73107';
        $first = $this->getDataGenerator()->create_course([
            'idnumber' => $idnumber,
            'shortname' => 'RTC-DUPLICATE-73107-A',
            'fullname' => 'First duplicate stable identity',
        ]);
        // Core create_course rejects duplicates, but restored/imported data can
        // contain them because course.idnumber has only a non-unique index.
        $second = $DB->get_record('course', ['id' => (int) $first->id], '*', MUST_EXIST);
        unset($second->id);
        $second->shortname = 'RTC-DUPLICATE-73107-B';
        $second->fullname = 'Second duplicate stable identity';
        $secondid = (int) $DB->insert_record('course', $second);

        try {
            \local_rtcsync_external::upsert_course($this->versionedCoursePayload(73107, 1));
            $this->fail('A version fence must not choose one of multiple Moodle courses with the same stable idnumber.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('matches multiple Moodle courses', $exception->getMessage());
        }

        $this->assertSame(2, $DB->count_records('course', ['idnumber' => $idnumber]));
        $this->assertFalse($DB->record_exists('local_rtcsync_coursesync', ['idnumber' => $idnumber]));
        $this->assertFalse($DB->record_exists('course_categories', [
            'idnumber' => 'rtc-versioned-test:73107',
        ]));
        $this->assertSame('First duplicate stable identity', (string) $DB->get_field(
            'course',
            'fullname',
            ['id' => (int) $first->id],
            MUST_EXIST
        ));
        $this->assertSame('Second duplicate stable identity', (string) $DB->get_field(
            'course',
            'fullname',
            ['id' => $secondid],
            MUST_EXIST
        ));
    }

    public function test_failed_higher_course_revision_rolls_back_course_category_and_revision_state(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $payload = $this->versionedCoursePayload(73105, 4);
        $first = \local_rtcsync_external::upsert_course($payload);
        $competitor = $this->getDataGenerator()->create_course(['shortname' => 'RTC-COLLISION-73105']);
        $failed = $this->versionedCoursePayload(73105, 5, [
            'fullname' => 'This higher-revision fullname must roll back',
            'shortname' => (string) $competitor->shortname,
            'category_idnumber' => 'rtc-rollback-only:73105',
            'category_name' => 'Must be rolled back with failed course update',
        ]);

        $failure = null;
        try {
            \local_rtcsync_external::upsert_course($failed);
        } catch (\Throwable $exception) {
            $failure = $exception;
        }
        $this->assertNotNull($failure, 'The conflicting shortname must fail the higher revision mutation.');
        $this->assertNotSame($failed['fullname'], (string) $DB->get_field(
            'course',
            'fullname',
            ['id' => (int) $first['id']],
            MUST_EXIST
        ));

        $this->assertFalse($DB->record_exists('course_categories', ['idnumber' => 'rtc-rollback-only:73105']));
        $this->assertSame(4, (int) $DB->get_field(
            'local_rtcsync_coursesync',
            'sourcerevision',
            ['idnumber' => $payload['idnumber']],
            MUST_EXIST
        ));
    }

    /** Apply an explicitly reviewed module identity fixture. */
    private function reconcileDeliveryModule(\stdClass $course, int $cmid, string $decision, int $deliveryid): void
    {
        global $DB;

        $cm = $DB->get_record_sql(
            'SELECT cm.id, cm.instance, cm.idnumber, m.name AS modname
               FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = :cmid',
            ['cmid' => $cmid],
            MUST_EXIST
        );
        $identity = [
            'course_idnumber' => (string) $course->idnumber,
            'cmid' => $cmid,
            'modname' => (string) $cm->modname,
            'instanceid' => (int) $cm->instance,
            'idnumber' => (string) $cm->idnumber,
        ];
        $json = json_encode([
            'schema_version' => 1,
            'manifest_id' => 'externallib-' . bin2hex(random_bytes(8)),
            'course_idnumber' => (string) $course->idnumber,
            'source_snapshot_sha256' => hash_file('sha256', __DIR__ . '/fixtures/lineage-source-backup.txt'),
            'source_plugin_version' => 2026102700,
            'review' => [
                'approved_by' => 'RTC Data Owner Test Fixture',
                'approved_at' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 1),
                'approval_reference' => 'TEST-FIXTURE',
            ],
            'entries' => [[
                'source' => $identity,
                'target' => $identity,
                'decision' => $decision,
                'delivery_ids' => [$deliveryid],
                'reason' => 'Explicit test fixture mapping.',
            ]],
            'grade_items' => [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $preview = \local_rtcsync\local\delivery_lineage_reconciliation::preview($json);
        $this->assertTrue($preview['valid'], implode('; ', $preview['errors']));
        $manifest = json_decode($json, true);
        \local_rtcsync\local\delivery_lineage_reconciliation::apply(
            $json,
            $preview['plan_sha256'],
            'externallib-test',
            [
                'approved_by' => $manifest['review']['approved_by'],
                'approved_at' => $manifest['review']['approved_at'],
                'approval_reference' => $manifest['review']['approval_reference'],
                'source_snapshot_sha256' => $manifest['source_snapshot_sha256'],
                'source_plugin_version' => $manifest['source_plugin_version'],
            ],
            __DIR__ . '/fixtures/lineage-source-backup.txt'
        );
    }

    /** Create an explicitly named RTC delivery grouping in one subject course. */
    private function createDeliveryGrouping(\stdClass $course, int $deliveryid): \stdClass
    {
        return $this->getDataGenerator()->create_grouping([
            'courseid' => (int) $course->id,
            'name' => 'Delivery ' . $deliveryid,
            'idnumber' => 'rtc-delivery-grouping:' . $deliveryid,
        ]);
    }

    /** Insert a corrupt duplicate identity that the Moodle grouping API correctly rejects. */
    private function createDuplicateDeliveryGrouping(\stdClass $course, int $deliveryid): \stdClass
    {
        global $DB;

        $original = $DB->get_record('groupings', [
            'courseid' => (int) $course->id,
            'idnumber' => 'rtc-delivery-grouping:' . $deliveryid,
        ], '*', MUST_EXIST);
        $duplicate = clone $original;
        unset($duplicate->id);
        $duplicate->name = 'Duplicate delivery ' . $deliveryid;
        $duplicate->timecreated = time();
        $duplicate->timemodified = time();
        $id = $DB->insert_record('groupings', $duplicate);

        return $DB->get_record('groupings', ['id' => $id], '*', MUST_EXIST);
    }

    public function test_user_read_returns_only_explicit_idnumbers(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();

        $included = $this->getDataGenerator()->create_user([
            'idnumber' => 'rtc-user:73001',
        ]);
        $this->getDataGenerator()->create_user([
            'idnumber' => 'rtc-user:73002',
        ]);

        $result = \local_rtcsync_external::get_managed_state(
            'users',
            ['rtc-user:73001'],
            0,
            100
        );

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['records']);
        $this->assertSame((int) $included->id, $result['records'][0]['moodle_id']);
        $this->assertSame('rtc-user:73001', $result['records'][0]['idnumber']);
    }

    public function test_user_read_omits_duplicate_stable_idnumbers(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->getDataGenerator()->create_user(['idnumber' => 'rtc-user:73003']);
        $this->getDataGenerator()->create_user(['idnumber' => 'rtc-user:73003']);

        $result = \local_rtcsync_external::get_managed_state(
            'users',
            ['rtc-user:73003'],
            0,
            100
        );

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['records']);
    }

    public function test_user_read_omits_a_stable_idnumber_with_a_conflicting_managed_marker(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $target = $this->getDataGenerator()->create_user(['idnumber' => 'rtc-user:73004']);
        $markerowner = $this->createManagedUser(['idnumber' => 'rtc-user:73005']);
        $DB->set_field('local_rtcsync_user', 'idnumber', 'rtc-user:73004', [
            'userid' => (int) $markerowner->id,
        ]);
        $DB->set_field('user', 'deleted', 1, ['id' => (int) $markerowner->id]);

        $result = \local_rtcsync_external::get_managed_state(
            'users',
            ['rtc-user:73004'],
            0,
            100
        );

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['records']);
        $this->assertSame(1, (int) $DB->get_field(
            'user',
            'deleted',
            ['id' => (int) $markerowner->id],
            MUST_EXIST
        ));
        $this->assertSame((int) $target->id, (int) $DB->get_field(
            'user',
            'id',
            ['idnumber' => 'rtc-user:73004'],
            MUST_EXIST
        ));
    }

    public function test_managed_user_inventory_uses_marker_for_sms_id_card_idnumbers(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();

        $managed = $this->createLegacyManagedUser(
            'rtc-managed-card',
            'rtc-managed-card@example.test',
            'CARD-001'
        );
        $this->getDataGenerator()->create_user([
            'idnumber' => 'CARD-999',
        ]);

        $result = \local_rtcsync_external::get_managed_state(
            'managedusers',
            [],
            0,
            100
        );

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['records']);
        $this->assertSame((int) $managed->id, $result['records'][0]['moodle_id']);
        $this->assertSame('CARD-001', $result['records'][0]['idnumber']);
    }

    public function test_bilingual_labels_render_one_language_for_text_and_strings(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();
        filter_set_global_state('multilang', TEXTFILTER_ON);
        filter_set_applies_to_strings('multilang', true);

        global $SESSION;
        $bilingual = '<span class="multilang" lang="en">English label</span>'
            .'<span class="multilang" lang="km">ស្លាកខ្មែរ</span>';
        $legacy = '<span class="multilang" lang="en">English label</span>'
            .'<span class="multilang" lang="kh">ស្លាកខ្មែរ</span>';
        $options = ['context' => \context_system::instance()];

        $SESSION->forcelang = 'en';
        $this->assertSame('English label', format_text($bilingual, FORMAT_HTML, $options));
        $this->assertSame('English label', format_string($bilingual, true, $options));

        $SESSION->forcelang = 'km';
        $this->assertSame('ស្លាកខ្មែរ', format_text($bilingual, FORMAT_HTML, $options));
        $this->assertSame('ស្លាកខ្មែរ', format_string($bilingual, true, $options));
        $this->assertSame('ស្លាកខ្មែរ', format_text($legacy, FORMAT_HTML, $options));
    }

    public function test_user_profile_contract_persists_batch_fields_and_rejects_unknown_fields(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = \local_rtcsync_external::upsert_user([
            'moodleid' => 0,
            'username' => 'rtc-batch-contract',
            'email' => 'rtc-batch-contract@example.test',
            'firstname' => 'Batch',
            'lastname' => 'Contract',
            'idnumber' => 'rtc-user:73010',
            'phone1' => '',
            'suspended' => 0,
            'profile_fields' => [
                ['shortname' => 'rtc_program_batch_id', 'value' => '41'],
                ['shortname' => 'rtc_batch_code', 'value' => 'BATCH-41'],
                ['shortname' => 'rtc_batch_number', 'value' => '3'],
            ],
        ]);

        $this->assertSame(3, $result['profile_fields_saved']);

        $this->expectException(\invalid_parameter_exception::class);
        \local_rtcsync_external::upsert_user([
            'moodleid' => (int) $result['id'],
            'username' => 'rtc-batch-contract',
            'email' => 'rtc-batch-contract@example.test',
            'firstname' => 'Batch',
            'lastname' => 'Contract',
            'idnumber' => 'rtc-user:73010',
            'phone1' => '',
            'suspended' => 0,
            'profile_fields' => [
                ['shortname' => 'rtc_unknown_contract_field', 'value' => 'should fail'],
            ],
        ]);
    }

    public function test_category_roles_are_scoped_and_managed_without_removing_manual_roles(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        if (!$DB->record_exists('role', ['shortname' => 'manager'])) {
            create_role(
                'RTC category manager',
                'manager',
                'RTC category manager role for synchronization tests.',
                'manager'
            );
        }
        $user = $this->createManagedUser([
            'idnumber' => 'rtc-user:73011',
        ]);

        $result = \local_rtcsync_external::sync_category_roles([
            'userid' => (int) $user->id,
            'category_roles' => [[
                'category_idnumber' => 'DEPT-TEST',
                'category_name' => 'Department Test',
                'role_shortname' => 'manager',
            ]],
        ]);

        $this->assertSame(1, $result['category_count']);
        $category = $DB->get_record('course_categories', ['idnumber' => 'DEPT-TEST'], '*', MUST_EXIST);
        $manager = $DB->get_record('role', ['shortname' => 'manager'], '*', MUST_EXIST);
        $context = \context_coursecat::instance((int) $category->id);
        $this->assertTrue($DB->record_exists('role_assignments', [
            'userid' => (int) $user->id,
            'roleid' => (int) $manager->id,
            'contextid' => $context->id,
            'component' => 'local_rtcsync',
            'itemid' => 0,
        ]));

        role_assign((int) $manager->id, (int) $user->id, $context->id);
        \local_rtcsync_external::sync_category_roles([
            'userid' => (int) $user->id,
            'category_roles' => [],
        ]);

        $this->assertFalse($DB->record_exists('role_assignments', [
            'userid' => (int) $user->id,
            'roleid' => (int) $manager->id,
            'contextid' => $context->id,
            'component' => 'local_rtcsync',
            'itemid' => 0,
        ]));
        $this->assertTrue($DB->record_exists('role_assignments', [
            'userid' => (int) $user->id,
            'roleid' => (int) $manager->id,
            'contextid' => $context->id,
            'component' => '',
            'itemid' => 0,
        ]));
    }

    public function test_course_read_is_explicit_and_paginated(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();

        $included = $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-subject:11',
        ]);
        $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-subject:unrelated',
        ]);

        $result = \local_rtcsync_external::get_managed_state(
            'courses',
            ['rtc-subject:11'],
            0,
            1
        );

        $this->assertSame(1, $result['total']);
        $this->assertSame(1, $result['limit']);
        $this->assertCount(1, $result['records']);
        $this->assertSame((int) $included->id, $result['records'][0]['moodle_id']);
    }

    public function test_managed_course_inventory_lists_subject_and_credit_courses_only(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();

        $subject = $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-subject:11',
        ]);
        $credit = $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-credit-course:22',
        ]);
        $this->getDataGenerator()->create_course([
            'idnumber' => 'manual-course:33',
        ]);

        $result = \local_rtcsync_external::get_managed_state(
            'managedcourses',
            [],
            0,
            100
        );

        $this->assertSame(2, $result['total']);
        $this->assertSame(
            [(int) $subject->id, (int) $credit->id],
            array_map(
                static fn (array $record): int => $record['moodle_id'],
                $result['records']
            )
        );
    }

    public function test_managed_class_inventory_lists_class_and_delivery_cohorts_only(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->createManagedCourse(['idnumber' => 'rtc-subject:inventory']);
        $student = $this->createManagedUser(['idnumber' => 'rtc-user:73012']);

        \local_rtcsync_external::upsert_class([
            'idnumber' => 'rtc-class:inventory',
            'name' => '[Class] Inventory',
            'visible' => 1,
            'userids' => [$student->id],
            'courseids' => [$course->id],
            'grouping_idnumber' => 'rtc-class-grouping:inventory',
            'grouping_name' => '[Class] Inventory',
            'groups' => [],
        ]);
        \local_rtcsync_external::upsert_class([
            'idnumber' => 'rtc-delivery:inventory',
            'name' => '[Delivery] Inventory',
            'visible' => 1,
            'delivery_id' => 73013,
            'userids' => [$student->id],
            'courseids' => [$course->id],
            'grouping_idnumber' => 'rtc-delivery-grouping:73013',
            'grouping_name' => '[Delivery] Inventory',
            'groups' => [],
        ]);

        $result = \local_rtcsync_external::get_managed_state(
            'managedclasses',
            [],
            0,
            100
        );

        $this->assertSame(2, $result['total']);
        $this->assertSame(
            ['rtc-class:inventory', 'rtc-delivery:inventory'],
            array_map(
                static fn (array $record): string => $record['idnumber'],
                $result['records']
            )
        );
    }

    public function test_activity_grade_read_returns_only_course_items_enabled_for_sms_formative(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['idnumber' => 'rtc-fixture:activity-grade-read']);
        $student = $this->createManagedUser(['idnumber' => 'rtc-user:22']);
        $unmanagedstudent = $this->getDataGenerator()->create_user(['idnumber' => 'rtc-user:23']);
        $mismarkedstudent = $this->getDataGenerator()->create_user(['idnumber' => 'rtc-user:24']);
        $DB->insert_record('local_rtcsync_user', (object) [
            'userid' => (int) $mismarkedstudent->id,
            'idnumber' => 'rtc-user:25',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'name' => 'Quiz 1',
        ]);
        $quizitem = \grade_item::fetch([
            'courseid' => $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $quiz->id,
            'itemnumber' => 0,
        ]);
        $this->assertNotFalse($quizitem);
        $item = $quizitem;
        $item->itemname = 'Quiz 1';
        $item->grademax = 10;
        $item->update();
        $item->update_final_grade($student->id, 8, 'test');
        $item->update_final_grade($unmanagedstudent->id, 9, 'test');
        $item->update_final_grade($mismarkedstudent->id, 7, 'test');

        $configid = $DB->insert_record('local_rtcsync_formcfg', (object) [
            'courseid' => $course->id,
            'enabled' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('local_rtcsync_formitem', (object) [
            'courseid' => $course->id,
            'itemid' => $item->id,
            'included' => 1,
            'label' => 'Knowledge check',
            'weight' => 100,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        // Keep module-created observers out of this legacy-course read fixture.
        $DB->set_field('course', 'idnumber', 'rtc-subject:11', ['id' => (int) $course->id]);

        $result = \local_rtcsync_external::get_managed_state(
            'activitygrades',
            ['rtc-subject:11'],
            0,
            100
        );

        $this->assertSame(1, $result['total']);
        $this->assertSame('Knowledge check', $result['records'][0]['sync_label']);
        $this->assertSame('rtc-user:22', $result['records'][0]['user_idnumber']);
        $this->assertSame((int) $student->id, $result['records'][0]['user_id']);
        $this->assertSame(100.0, $result['records'][0]['sync_weight']);
        $this->assertSame(1, $result['records'][0]['sync_selected_count']);

        $DB->set_field('local_rtcsync_formcfg', 'enabled', 0, ['id' => $configid]);
        $disabled = \local_rtcsync_external::get_managed_state(
            'activitygrades',
            ['rtc-subject:11'],
            0,
            100
        );
        $this->assertSame(0, $disabled['total']);
    }

    public function test_activity_grade_read_omits_duplicate_stable_user_idnumbers(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['idnumber' => 'rtc-subject:12']);
        $student = $this->createManagedUser(['idnumber' => 'rtc-user:70012']);
        $duplicate = $this->getDataGenerator()->create_user(['idnumber' => 'rtc-user:70012']);
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'name' => 'Duplicate identity quiz',
        ]);
        $item = \grade_item::fetch([
            'courseid' => $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $quiz->id,
            'itemnumber' => 0,
        ]);
        $this->assertNotFalse($item);
        $item->update_final_grade($student->id, 8, 'test');
        $item->update_final_grade($duplicate->id, 7, 'test');
        $DB->insert_record('local_rtcsync_formcfg', (object) [
            'courseid' => $course->id,
            'enabled' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('local_rtcsync_formitem', (object) [
            'courseid' => $course->id,
            'itemid' => $item->id,
            'included' => 1,
            'label' => 'Duplicate identity quiz',
            'weight' => 100,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $result = \local_rtcsync_external::get_managed_state(
            'activitygrades',
            ['rtc-subject:12'],
            0,
            100
        );

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['records']);
    }

    public function test_activity_grade_read_omits_duplicate_stable_user_markers(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['idnumber' => 'rtc-subject:13']);
        $student = $this->createManagedUser(['idnumber' => 'rtc-user:70013']);
        $duplicate = $this->getDataGenerator()->create_user(['idnumber' => 'rtc-user:70014']);
        $DB->insert_record('local_rtcsync_user', (object) [
            'userid' => (int) $duplicate->id,
            'idnumber' => 'rtc-user:70013',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'name' => 'Duplicate marker quiz',
        ]);
        $item = \grade_item::fetch([
            'courseid' => $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $quiz->id,
            'itemnumber' => 0,
        ]);
        $this->assertNotFalse($item);
        $item->update_final_grade($student->id, 8, 'test');
        $DB->insert_record('local_rtcsync_formcfg', (object) [
            'courseid' => $course->id,
            'enabled' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('local_rtcsync_formitem', (object) [
            'courseid' => $course->id,
            'itemid' => $item->id,
            'included' => 1,
            'label' => 'Duplicate marker quiz',
            'weight' => 100,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $result = \local_rtcsync_external::get_managed_state(
            'activitygrades',
            ['rtc-subject:13'],
            0,
            100
        );

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['records']);

        // Deleting the conflicting account leaves its stable identity marker as
        // a tombstone; that must not make the remaining account exportable.
        $DB->set_field('user', 'deleted', 1, ['id' => (int) $duplicate->id]);
        $afterdelete = \local_rtcsync_external::get_managed_state(
            'activitygrades',
            ['rtc-subject:13'],
            0,
            100
        );
        $this->assertSame(0, $afterdelete['total']);
        $this->assertSame([], $afterdelete['records']);
    }

    public function test_synced_grade_read_requires_unique_current_user_identity(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['idnumber' => 'rtc-subject:14']);
        $this->createDeliveryGrouping($course, 801);
        $valid = $this->createManagedUser(['idnumber' => 'rtc-user:70015']);
        $emptyidentity = $this->createManagedUser();
        $unmanaged = $this->getDataGenerator()->create_user(['idnumber' => 'rtc-user:70016']);
        $mismarked = $this->getDataGenerator()->create_user(['idnumber' => 'rtc-user:70017']);
        $DB->insert_record('local_rtcsync_user', (object) [
            'userid' => (int) $mismarked->id,
            'idnumber' => 'rtc-user:70017-other',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $duplicateaccount = $this->createManagedUser(['idnumber' => 'rtc-user:70018']);
        $this->getDataGenerator()->create_user(['idnumber' => 'rtc-user:70018']);
        $duplicatemarker = $this->createManagedUser(['idnumber' => 'rtc-user:70019']);
        $markerowner = $this->getDataGenerator()->create_user(['idnumber' => 'rtc-user:70020']);
        $DB->insert_record('local_rtcsync_user', (object) [
            'userid' => (int) $markerowner->id,
            'idnumber' => 'rtc-user:70019',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $item = new \grade_item([
            'courseid' => (int) $course->id,
            'itemname' => 'RTC delivery score',
            'itemtype' => 'manual',
            'itemmodule' => null,
            'iteminstance' => null,
            'itemnumber' => 0,
            'idnumber' => 'rtc-subject-score:14:delivery:801',
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
        ], false);
        $item->insert('grade identity test');
        $DB->insert_record('local_rtcsync_deliverygrade', (object) [
            'gradeitemid' => (int) $item->id,
            'courseid' => (int) $course->id,
            'deliveryid' => 801,
            'timecreated' => time(),
            'timemodified' => time(),
            'lineageverified' => 1,
            // New items created by the guarded grade upsert are verified directly without a manifest.
            'lineagemanifestid' => null,
        ]);
        foreach ([
            $valid,
            $emptyidentity,
            $unmanaged,
            $mismarked,
            $duplicateaccount,
            $duplicatemarker,
        ] as $user) {
            $item->update_final_grade((int) $user->id, 80.0, 'grade identity test');
        }
        $item->update_final_grade((int) $markerowner->id, 70.0, 'grade identity test');

        $result = \local_rtcsync_external::get_managed_state(
            'grades',
            ['rtc-subject:14'],
            0,
            100
        );

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['records']);
        $this->assertSame((int) $valid->id, $result['records'][0]['user_id']);
        $this->assertSame('rtc-user:70015', $result['records'][0]['user_idnumber']);
    }

    public function test_delivery_grade_read_requires_verified_exact_mapping_and_unique_grouping(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['idnumber' => 'rtc-subject:18']);
        $user = $this->createManagedUser(['idnumber' => 'rtc-user:70022']);
        foreach ([801, 802, 803, 804, 805, 806] as $deliveryid) {
            $this->createDeliveryGrouping($course, $deliveryid);
        }
        $this->createDuplicateDeliveryGrouping($course, 806);
        // A non-ready course must not make an unreviewed mapping readable. A verified
        // manual result remains available after activities are no longer current.
        $DB->insert_record('local_rtcsync_deliverycourse', (object) [
            'courseid' => (int) $course->id,
            'coursevisible' => 1,
            'ready' => 0,
            'lastcmid' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $createitem = function(
            int $itemdeliveryid,
            ?int $mappeddeliveryid,
            int $verified
        ) use ($DB, $course, $user): \grade_item {
            $item = new \grade_item([
                'courseid' => (int) $course->id,
                'itemname' => 'Delivery ' . $itemdeliveryid . ' score',
                'itemtype' => 'manual',
                'itemmodule' => null,
                'iteminstance' => null,
                'itemnumber' => 0,
                'idnumber' => 'rtc-subject-score:18:delivery:' . $itemdeliveryid,
                'gradetype' => GRADE_TYPE_VALUE,
                'grademax' => 100,
                'grademin' => 0,
            ], false);
            $item->insert('delivery grade readback test');
            $item->update_final_grade((int) $user->id, 80.0, 'delivery grade readback test');
            if ($mappeddeliveryid !== null) {
                $DB->insert_record('local_rtcsync_deliverygrade', (object) [
                    'gradeitemid' => (int) $item->id,
                    'courseid' => (int) $course->id,
                    'deliveryid' => $mappeddeliveryid,
                    'timecreated' => time(),
                    'timemodified' => time(),
                    'lineageverified' => $verified,
                    'lineagemanifestid' => null,
                ]);
            }
            return $item;
        };

        $valid = $createitem(801, 801, 1);
        $unreviewedwithoutmanifest = $createitem(802, 802, 0);
        $unmapped = $createitem(803, null, 0);
        $wrongdeliveryidentity = $createitem(804, 805, 1);
        $ambiguousgrouping = $createitem(806, 806, 1);
        $missinggrouping = $createitem(807, 807, 1);
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => (int) $course->id,
            'name' => 'Activity grade with delivery score idnumber',
            'grade' => 100,
        ]);
        $activityitem = \grade_item::fetch([
            'courseid' => (int) $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => (int) $quiz->id,
            'itemnumber' => 0,
        ]);
        $this->assertNotFalse($activityitem);
        $activityitem->idnumber = 'rtc-subject-score:18:delivery:809';
        $activityitem->update();
        $activityitem->update_final_grade((int) $user->id, 77.0, 'delivery grade readback test');

        $result = \local_rtcsync_external::get_managed_state(
            'grades',
            ['rtc-subject:18'],
            0,
            100
        );

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['records']);
        $this->assertSame((int) $valid->id, $result['records'][0]['grade_item_id']);
        $resultitemids = array_column($result['records'], 'grade_item_id');
        $this->assertNotContains((int) $unreviewedwithoutmanifest->id, $resultitemids);
        $this->assertNotContains((int) $unmapped->id, $resultitemids);
        $this->assertNotContains((int) $wrongdeliveryidentity->id, $resultitemids);
        $this->assertNotContains((int) $ambiguousgrouping->id, $resultitemids);
        $this->assertNotContains((int) $missinggrouping->id, $resultitemids);
        $this->assertNotContains((int) $activityitem->id, $resultitemids);
    }

    public function test_delivery_grade_read_requires_exact_lineage_audit_for_manifest_mapping(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['idnumber' => 'rtc-subject:19']);
        $user = $this->createManagedUser(['idnumber' => 'rtc-user:70023']);
        foreach ([901, 902, 903] as $deliveryid) {
            $this->createDeliveryGrouping($course, $deliveryid);
        }

        $items = [];
        foreach ([901, 902, 903] as $deliveryid) {
            $item = new \grade_item([
                'courseid' => (int) $course->id,
                'itemname' => 'Reviewed delivery ' . $deliveryid,
                'itemtype' => 'manual',
                'itemmodule' => null,
                'iteminstance' => null,
                'itemnumber' => 0,
                'idnumber' => 'rtc-subject-score:19:delivery:' . $deliveryid,
                'gradetype' => GRADE_TYPE_VALUE,
                'grademax' => 100,
                'grademin' => 0,
            ], false);
            $item->insert('manifest grade readback test');
            $item->update_final_grade((int) $user->id, 80.0, 'manifest grade readback test');
            $items[$deliveryid] = $item;
            $manifestid = 'grade-readback-' . $deliveryid;
            $DB->insert_record('local_rtcsync_deliverygrade', (object) [
                'gradeitemid' => (int) $item->id,
                'courseid' => (int) $course->id,
                'deliveryid' => $deliveryid,
                'timecreated' => time(),
                'timemodified' => time(),
                'lineageverified' => 1,
                'lineagemanifestid' => $manifestid,
            ]);
        }

        $insertaudit = function(int $deliveryid, string $deliveryids) use ($DB, $course, $items): int {
            $manifestid = 'grade-readback-' . $deliveryid;
            return (int) $DB->insert_record('local_rtcsync_lineageaudit', (object) [
                'manifestid' => $manifestid,
                'manifesthash' => hash('sha256', $manifestid),
                'courseid' => (int) $course->id,
                'recordtype' => 'gradeitem',
                'targetid' => (int) $items[$deliveryid]->id,
                'decision' => 'map',
                'deliveryids' => $deliveryids,
                'sourceidentity' => '{}',
                'targetidentity' => '{}',
                'reason' => 'Explicit external grade readback test fixture.',
                'entryhash' => hash('sha256', $manifestid . ':' . $deliveryids),
                'resultstate' => '{}',
                'parententryhash' => null,
                'parentresultstate' => null,
                'timecreated' => time(),
            ]);
        };
        $insertaudit(902, '[999]');
        $validauditid = $insertaudit(903, '[903]');

        $result = \local_rtcsync_external::get_managed_state(
            'grades',
            ['rtc-subject:19'],
            0,
            100
        );
        $this->assertSame(1, $result['total']);
        $this->assertSame([(int) $items[903]->id], array_column($result['records'], 'grade_item_id'));

        $DB->delete_records('local_rtcsync_lineageaudit', ['id' => $validauditid]);
        $resultwithoutaudit = \local_rtcsync_external::get_managed_state(
            'grades',
            ['rtc-subject:19'],
            0,
            100
        );
        $this->assertSame(0, $resultwithoutaudit['total']);
        $this->assertSame([], $resultwithoutaudit['records']);
    }

    public function test_activity_grade_read_fails_closed_for_unreviewed_unready_and_incomplete_delivery_guards(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $readycourse = $this->getDataGenerator()->create_course(['idnumber' => 'rtc-subject:15']);
        $pendingcourse = $this->getDataGenerator()->create_course(['idnumber' => 'rtc-subject:16']);
        $stalestatecourse = $this->getDataGenerator()->create_course(['idnumber' => 'rtc-subject:17']);
        $student = $this->createManagedUser(['idnumber' => 'rtc-user:70021']);
        $now = time();

        foreach ([
            [$readycourse, 1],
            [$pendingcourse, 0],
        ] as [$course, $ready]) {
            $DB->insert_record('local_rtcsync_deliverycourse', (object) [
                'courseid' => (int) $course->id,
                'coursevisible' => 1,
                'ready' => $ready,
                'lastcmid' => 0,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            $DB->insert_record('local_rtcsync_formcfg', (object) [
                'courseid' => (int) $course->id,
                'enabled' => 1,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
        $DB->insert_record('local_rtcsync_formcfg', (object) [
            'courseid' => (int) $stalestatecourse->id,
            'enabled' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $currentgrouping = $this->createDeliveryGrouping($readycourse, 820);
        $this->createDeliveryGrouping($readycourse, 821);
        $pendinggrouping = $this->createDeliveryGrouping($pendingcourse, 822);
        $missingmanifestgrouping = $this->createDeliveryGrouping($readycourse, 824);
        $ambiguousgrouping = $this->createDeliveryGrouping($readycourse, 825);
        $this->createDuplicateDeliveryGrouping($readycourse, 825);
        $livegroupingmismatch = $this->createDeliveryGrouping($readycourse, 827);
        $activities = [
            [
                'course' => $readycourse,
                'name' => 'Current delivery activity',
                'deliveryid' => 820,
                'groupingid' => (int) $currentgrouping->id,
                'lineagestatus' => \local_rtcsync\local\delivery_activity_access::LINEAGE_CURRENT,
            ],
            [
                'course' => $readycourse,
                'name' => 'Historical delivery activity',
                'deliveryid' => 0,
                'groupingid' => 0,
                'lineagestatus' => \local_rtcsync\local\delivery_activity_access::LINEAGE_HISTORICAL,
            ],
            [
                'course' => $readycourse,
                'name' => 'Unreviewed legacy activity',
                'deliveryid' => 0,
                'groupingid' => 0,
                'lineagestatus' => \local_rtcsync\local\delivery_activity_access::LINEAGE_UNREVIEWED,
            ],
            [
                'course' => $pendingcourse,
                'name' => 'Pending delivery activity',
                'deliveryid' => 822,
                'groupingid' => (int) $pendinggrouping->id,
                'lineagestatus' => \local_rtcsync\local\delivery_activity_access::LINEAGE_CURRENT,
            ],
            [
                'course' => $stalestatecourse,
                'name' => 'Delivery activity with missing course state',
                'deliveryid' => 823,
                'groupingid' => 0,
                'lineagestatus' => \local_rtcsync\local\delivery_activity_access::LINEAGE_CURRENT,
            ],
            [
                'course' => $readycourse,
                'name' => 'Current delivery activity with missing manifest marker',
                'deliveryid' => 824,
                'groupingid' => (int) $missingmanifestgrouping->id,
                'lineagestatus' => \local_rtcsync\local\delivery_activity_access::LINEAGE_CURRENT,
                'lineagemanifestid' => null,
            ],
            [
                'course' => $readycourse,
                'name' => 'Current delivery activity with missing grouping marker',
                'deliveryid' => 826,
                'groupingid' => 0,
                'lineagestatus' => \local_rtcsync\local\delivery_activity_access::LINEAGE_CURRENT,
            ],
            [
                'course' => $readycourse,
                'name' => 'Current delivery activity with ambiguous grouping marker',
                'deliveryid' => 825,
                'groupingid' => (int) $ambiguousgrouping->id,
                'lineagestatus' => \local_rtcsync\local\delivery_activity_access::LINEAGE_CURRENT,
            ],
            [
                'course' => $readycourse,
                'name' => 'Current delivery activity with mismatched live grouping marker',
                'deliveryid' => 827,
                'groupingid' => (int) $livegroupingmismatch->id,
                'livegroupingid' => (int) $currentgrouping->id,
                'lineagestatus' => \local_rtcsync\local\delivery_activity_access::LINEAGE_CURRENT,
            ],
        ];
        $expecteditemid = 0;
        foreach ($activities as $index => $activity) {
            $quiz = $this->getDataGenerator()->create_module('quiz', [
                'course' => (int) $activity['course']->id,
                'name' => $activity['name'],
            ]);
            $item = \grade_item::fetch([
                'courseid' => (int) $activity['course']->id,
                'itemtype' => 'mod',
                'itemmodule' => 'quiz',
                'iteminstance' => (int) $quiz->id,
                'itemnumber' => 0,
            ]);
            $this->assertNotFalse($item);
            $item->update_final_grade((int) $student->id, 80.0 - $index, 'delivery grade visibility test');
            $DB->insert_record('local_rtcsync_formitem', (object) [
                'courseid' => (int) $activity['course']->id,
                'itemid' => (int) $item->id,
                'included' => 1,
                'label' => $activity['name'],
                'weight' => 25,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            $cmid = (int) $quiz->cmid;
            $deliverymapping = (object) [
                'cmid' => $cmid,
                'courseid' => (int) $activity['course']->id,
                'deliveryid' => (int) $activity['deliveryid'],
                'groupingid' => (int) $activity['groupingid'] ?: -$cmid,
                'originalavailability' => null,
                'managedavailability' => null,
                'lineagestatus' => (int) $activity['lineagestatus'],
                'lineagemanifestid' => array_key_exists('lineagemanifestid', $activity)
                    ? $activity['lineagemanifestid']
                    : ($activity['lineagestatus'] === \local_rtcsync\local\delivery_activity_access::LINEAGE_CURRENT
                        ? hash('sha256', 'reviewed activity ' . $cmid)
                        : null),
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            // Delivery-managed courses may already have a row from Moodle's module-created observer.
            $existingmapping = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => $cmid], '*', IGNORE_MISSING);
            if ($existingmapping) {
                $deliverymapping->id = (int) $existingmapping->id;
                $DB->update_record('local_rtcsync_deliverycm', $deliverymapping);
            } else {
                $DB->insert_record('local_rtcsync_deliverycm', $deliverymapping);
            }
            if ($index === 0) {
                $currentdeliveryjson = json_encode([(int) $activity['deliveryid']], JSON_THROW_ON_ERROR);
                $DB->insert_record('local_rtcsync_lineageaudit', (object) [
                    'manifestid' => (string) $deliverymapping->lineagemanifestid,
                    'manifesthash' => hash('sha256', (string) $deliverymapping->lineagemanifestid),
                    'courseid' => (int) $activity['course']->id,
                    'recordtype' => 'module',
                    'targetid' => $cmid,
                    'decision' => 'current',
                    'deliveryids' => $currentdeliveryjson,
                    'sourceidentity' => '{}',
                    'targetidentity' => '{}',
                    'reason' => 'Explicit current activity readback test fixture.',
                    'entryhash' => hash('sha256', $cmid . ':' . $currentdeliveryjson),
                    'resultstate' => '{}',
                    'parententryhash' => null,
                    'parentresultstate' => null,
                    'timecreated' => $now,
                ]);
            }
            if (!empty($activity['livegroupingid'])) {
                $DB->set_field('course_modules', 'groupingid', (int) $activity['livegroupingid'], ['id' => $cmid]);
            }
            if ($index === 0) {
                $expecteditemid = (int) $item->id;
            }
        }

        $result = \local_rtcsync_external::get_managed_state(
            'activitygrades',
            ['rtc-subject:15', 'rtc-subject:16', 'rtc-subject:17'],
            0,
            100
        );

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['records']);
        $this->assertSame($expecteditemid, $result['records'][0]['grade_item_id']);
    }

    public function test_activity_grade_read_requires_exact_current_module_lineage_audit(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['idnumber' => 'rtc-subject:20']);
        $student = $this->createManagedUser(['idnumber' => 'rtc-user:70024']);
        $now = time();
        $DB->insert_record('local_rtcsync_deliverycourse', (object) [
            'courseid' => (int) $course->id,
            'coursevisible' => 1,
            'ready' => 1,
            'lastcmid' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('local_rtcsync_formcfg', (object) [
            'courseid' => (int) $course->id,
            'enabled' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $items = [];
        $moduleids = [];
        foreach ([911, 912, 913] as $deliveryid) {
            $grouping = $this->createDeliveryGrouping($course, $deliveryid);
            $quiz = $this->getDataGenerator()->create_module('quiz', [
                'course' => (int) $course->id,
                'name' => 'Current delivery activity ' . $deliveryid,
            ]);
            $item = \grade_item::fetch([
                'courseid' => (int) $course->id,
                'itemtype' => 'mod',
                'itemmodule' => 'quiz',
                'iteminstance' => (int) $quiz->id,
                'itemnumber' => 0,
            ]);
            $this->assertNotFalse($item);
            $item->update_final_grade((int) $student->id, 80.0, 'activity lineage audit test');
            $DB->insert_record('local_rtcsync_formitem', (object) [
                'courseid' => (int) $course->id,
                'itemid' => (int) $item->id,
                'included' => 1,
                'label' => 'Delivery ' . $deliveryid,
                'weight' => 100,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);

            $cmid = (int) $quiz->cmid;
            $manifestid = 'activity-grade-audit-' . $deliveryid;
            $mapping = (object) [
                'cmid' => $cmid,
                'courseid' => (int) $course->id,
                'deliveryid' => $deliveryid,
                'groupingid' => (int) $grouping->id,
                'originalavailability' => null,
                'managedavailability' => null,
                'lineagestatus' => \local_rtcsync\local\delivery_activity_access::LINEAGE_CURRENT,
                'lineagemanifestid' => $manifestid,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $existingmapping = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => $cmid], '*', IGNORE_MISSING);
            if ($existingmapping) {
                $mapping->id = (int) $existingmapping->id;
                $DB->update_record('local_rtcsync_deliverycm', $mapping);
            } else {
                $DB->insert_record('local_rtcsync_deliverycm', $mapping);
            }
            $items[$deliveryid] = $item;
            $moduleids[$deliveryid] = $cmid;
        }

        $insertaudit = function(int $deliveryid, string $deliveryids) use ($DB, $course, $moduleids): int {
            $manifestid = 'activity-grade-audit-' . $deliveryid;
            return (int) $DB->insert_record('local_rtcsync_lineageaudit', (object) [
                'manifestid' => $manifestid,
                'manifesthash' => hash('sha256', $manifestid),
                'courseid' => (int) $course->id,
                'recordtype' => 'module',
                'targetid' => (int) $moduleids[$deliveryid],
                'decision' => 'current',
                'deliveryids' => $deliveryids,
                'sourceidentity' => '{}',
                'targetidentity' => '{}',
                'reason' => 'Explicit activity grade readback test fixture.',
                'entryhash' => hash('sha256', $manifestid . ':' . $deliveryids),
                'resultstate' => '{}',
                'parententryhash' => null,
                'parentresultstate' => null,
                'timecreated' => time(),
            ]);
        };
        $insertaudit(912, '[999]');
        $validauditid = $insertaudit(913, '[913]');

        $result = \local_rtcsync_external::get_managed_state(
            'activitygrades',
            ['rtc-subject:20'],
            0,
            100
        );
        $this->assertSame(1, $result['total']);
        $this->assertSame([(int) $items[913]->id], array_column($result['records'], 'grade_item_id'));

        $historyid = (int) $DB->insert_record('local_rtcsync_deliverycmhist', (object) [
            'cmid' => $moduleids[913],
            'courseid' => (int) $course->id,
            'deliveryid' => 912,
            'timecreated' => $now,
            'lineageverified' => 0,
            'lineagemanifestid' => null,
        ]);
        $resultwithconflictinghistory = \local_rtcsync_external::get_managed_state(
            'activitygrades',
            ['rtc-subject:20'],
            0,
            100
        );
        $this->assertSame(0, $resultwithconflictinghistory['total']);
        $this->assertSame([], $resultwithconflictinghistory['records']);
        $DB->delete_records('local_rtcsync_deliverycmhist', ['id' => $historyid]);

        $historyid = (int) $DB->insert_record('local_rtcsync_deliverycmhist', (object) [
            'cmid' => $moduleids[913],
            'courseid' => (int) $course->id,
            'deliveryid' => 913,
            'timecreated' => $now,
            'lineageverified' => 0,
            'lineagemanifestid' => null,
        ]);
        $resultwithunverifiedcurrenthistory = \local_rtcsync_external::get_managed_state(
            'activitygrades',
            ['rtc-subject:20'],
            0,
            100
        );
        $this->assertSame(0, $resultwithunverifiedcurrenthistory['total']);
        $this->assertSame([], $resultwithunverifiedcurrenthistory['records']);
        $DB->delete_records('local_rtcsync_deliverycmhist', ['id' => $historyid]);

        $historyid = (int) $DB->insert_record('local_rtcsync_deliverycmhist', (object) [
            'cmid' => $moduleids[913],
            'courseid' => (int) $course->id,
            'deliveryid' => 913,
            'timecreated' => $now,
            'lineageverified' => 1,
            'lineagemanifestid' => 'missing-historical-audit',
        ]);
        $resultwithstalehistory = \local_rtcsync_external::get_managed_state(
            'activitygrades',
            ['rtc-subject:20'],
            0,
            100
        );
        $this->assertSame(0, $resultwithstalehistory['total']);
        $this->assertSame([], $resultwithstalehistory['records']);
        $DB->delete_records('local_rtcsync_deliverycmhist', ['id' => $historyid]);

        $resultafterhistorycleanup = \local_rtcsync_external::get_managed_state(
            'activitygrades',
            ['rtc-subject:20'],
            0,
            100
        );
        $this->assertSame(1, $resultafterhistorycleanup['total']);
        $this->assertSame([(int) $items[913]->id], array_column($resultafterhistorycleanup['records'], 'grade_item_id'));

        $DB->delete_records('local_rtcsync_lineageaudit', ['id' => $validauditid]);
        $resultwithoutaudit = \local_rtcsync_external::get_managed_state(
            'activitygrades',
            ['rtc-subject:20'],
            0,
            100
        );
        $this->assertSame(0, $resultwithoutaudit['total']);
        $this->assertSame([], $resultwithoutaudit['records']);
    }

    public function test_course_upsert_reuses_nested_legacy_categories_and_moves_existing_course(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('sendcoursewelcomemessage', 0, 'enrol_manual');

        $program = \core_course_category::create([
            'name' => 'Associate Degree in Nursing',
            'idnumber' => 'ADN',
            'parent' => 0,
        ]);
        $year = \core_course_category::create([
            'name' => 'YEAR 1',
            'idnumber' => 'ADN_Y001',
            'parent' => (int) $program->id,
        ]);
        $semester = \core_course_category::create([
            'name' => 'Semester 1',
            'idnumber' => 'ADNY1S1',
            'parent' => (int) $year->id,
        ]);
        $flat = \core_course_category::create([
            'name' => 'Duplicate flat category',
            'idnumber' => 'rtc-program-4',
            'parent' => 0,
        ]);
        $existing = $this->getDataGenerator()->create_course([
            'fullname' => 'Human Ethic',
            'shortname' => 'RTC-BB-SUBJ-255',
            'idnumber' => 'rtc-subject:255',
            'category' => (int) $flat->id,
        ]);
        $path = [
            ['idnumber' => 'ADN', 'name' => 'Associate Degree in Nursing'],
            ['idnumber' => 'ADN_Y001', 'name' => 'YEAR 1'],
            ['idnumber' => 'ADNY1S1', 'name' => 'Semester 1'],
        ];

        $saved = \local_rtcsync_external::upsert_course([
            'fullname' => 'N-15-HE - Human Ethic',
            'shortname' => 'RTC-BB-SUBJ-255',
            'idnumber' => 'rtc-subject:255',
            'category_idnumber' => 'ADNY1S1',
            'category_name' => 'Semester 1',
            'category_path' => $path,
            'visible' => 1,
        ]);

        $this->assertSame((int) $existing->id, $saved['id']);
        $this->assertSame((int) $semester->id, $saved['categoryid']);
        foreach (['ADN', 'ADN_Y001', 'ADNY1S1'] as $idnumber) {
            $this->assertSame(1, $DB->count_records('course_categories', ['idnumber' => $idnumber]));
        }

        $state = \local_rtcsync_external::get_managed_state(
            'courses',
            ['rtc-subject:255'],
            0,
            100
        );
        $this->assertSame('ADNY1S1', $state['records'][0]['category_idnumber']);
        $this->assertSame(
            ['ADN', 'ADN_Y001', 'ADNY1S1'],
            json_decode($state['records'][0]['category_path'], true, 512, JSON_THROW_ON_ERROR),
        );

        $teacher = $this->createManagedUser();
        $student = $this->createManagedUser();
        $credit = \local_rtcsync_external::upsert_credit([
            'courseid' => $saved['id'],
            'subject_id' => 255,
            'idnumber' => 'rtc-credit-course:17',
            'shortname' => 'RTC-BB-CREDIT-17',
            'name' => 'Human Ethic - Test Teacher',
            'category_path' => $path,
            'teacher_role_shortname' => 'editingteacher',
            'student_role_shortname' => 'student',
            'teacher_userids' => [(int) $teacher->id],
            'student_userids' => [(int) $student->id],
        ]);
        $this->assertSame(
            (int) $semester->id,
            (int) $DB->get_field('course', 'category', ['id' => $credit['courseid']], MUST_EXIST),
        );
    }

    public function test_course_upsert_waits_for_stable_identity_lock_before_first_create(): void
    {
        global $CFG, $DB;

        $this->resetAfterTest();
        $CFG->lock_factory = '\\core\\lock\\file_lock_factory';
        $this->setAdminUser();
        $idnumber = 'rtc-subject:73016';
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_rtcsync_course_identity');
        $lock = $lockfactory->get_lock($idnumber, 0);
        $this->assertNotFalse($lock);

        $timedout = false;
        try {
            \local_rtcsync_external::upsert_course([
                'fullname' => 'Course Identity Lock',
                'shortname' => 'RTC-COURSE-IDENTITY-LOCK-73016',
                'idnumber' => $idnumber,
            ]);
        } catch (\moodle_exception $exception) {
            $timedout = $exception->errorcode === 'locktimeout';
        } finally {
            $lock->release();
        }

        $this->assertTrue($timedout, 'A competing first upsert must serialize before lookup and create.');
        $this->assertSame(0, $DB->count_records('course', ['idnumber' => $idnumber]));
    }

    public function test_read_rejects_more_than_one_hundred_identifiers(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\invalid_parameter_exception::class);

        \local_rtcsync_external::get_managed_state(
            'users',
            array_map(
                static fn(int $id): string => "rtc-user:{$id}",
                range(1, 101)
            )
        );
    }

    public function test_read_requires_dedicated_capability(): void
    {
        $this->resetAfterTest();

        $user = $this->createManagedUser();
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);

        \local_rtcsync_external::get_managed_state(
            'users',
            ['rtc-user:1']
        );
    }

    public function test_user_upsert_is_idempotent_and_supports_suspension_lifecycle(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $payload = [
            'moodleid' => 0,
            'username' => 'rtc.lifecycle@example.test',
            'email' => 'rtc.lifecycle@example.test',
            'firstname' => 'RTC',
            'lastname' => 'Lifecycle',
            'idnumber' => 'rtc-user:73013',
            'phone1' => '012345678',
            'suspended' => 0,
            'profile_fields' => [],
        ];

        $created = \local_rtcsync_external::upsert_user($payload);
        $payload['moodleid'] = $created['id'];
        $payload['firstname'] = 'Updated';
        $payload['suspended'] = 1;
        $suspended = \local_rtcsync_external::upsert_user($payload);

        $this->assertSame($created['id'], $suspended['id']);
        $this->assertSame(1, $DB->count_records('user', [
            'idnumber' => 'rtc-user:73013',
            'deleted' => 0,
        ]));

        $saved = $DB->get_record('user', ['id' => $created['id']], '*', MUST_EXIST);
        $this->assertSame('Updated', $saved->firstname);
        $this->assertSame(1, (int) $saved->suspended);

        $DB->set_field('user', 'confirmed', 0, ['id' => $created['id']]);
        $payload['suspended'] = 0;
        $activated = \local_rtcsync_external::upsert_user($payload);

        $this->assertSame(1, $activated['confirmed']);
        $this->assertSame(0, $activated['suspended']);
        $this->assertSame(
            0,
            (int) $DB->get_field('user', 'suspended', ['id' => $created['id']])
        );
        $this->assertSame(
            1,
            (int) $DB->get_field('user', 'confirmed', ['id' => $created['id']])
        );
    }

    public function test_user_upsert_rejects_invalid_suspension_before_mutating_existing_user(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $existing = $this->createManagedUser([
            'username' => 'rtc-invalid-suspension-existing@example.test',
            'email' => 'rtc-invalid-suspension-existing@example.test',
            'idnumber' => 'rtc-user:73019',
        ]);
        $profilefieldid = local_rtcsync_ensure_profile_fields()['rtc_batch_code'];
        local_rtcsync_save_profile_fields((int) $existing->id, [[
            'shortname' => 'rtc_batch_code',
            'value' => 'BEFORE-INVALID-SUSPENSION',
        ]]);
        $userbefore = $DB->get_record('user', ['id' => (int) $existing->id], '*', MUST_EXIST);
        $markerbefore = $DB->get_record('local_rtcsync_user', ['userid' => (int) $existing->id], '*', MUST_EXIST);
        $profilebefore = $DB->get_record('user_info_data', [
            'userid' => (int) $existing->id,
            'fieldid' => (int) $profilefieldid,
        ], '*', MUST_EXIST);

        foreach ([-1, 2] as $suspended) {
            try {
                \local_rtcsync_external::upsert_user([
                    'moodleid' => (int) $existing->id,
                    'username' => 'rtc-invalid-suspension-existing@example.test',
                    'email' => 'rtc-invalid-suspension-existing@example.test',
                    'firstname' => 'Must Not Change',
                    'lastname' => 'Existing Account',
                    'idnumber' => 'rtc-user:73019',
                    'phone1' => '999999',
                    'suspended' => $suspended,
                    'profile_fields' => [[
                        'shortname' => 'rtc_batch_code',
                        'value' => 'AFTER-INVALID-SUSPENSION',
                    ]],
                ]);
                $this->fail('Suspension value ' . $suspended . ' must be rejected before user mutation.');
            } catch (\invalid_parameter_exception $exception) {
                $this->assertStringContainsString('Suspended flag must be 0 or 1.', $exception->getMessage());
            }

            $this->assertEquals($userbefore, $DB->get_record('user', [
                'id' => (int) $existing->id,
            ], '*', MUST_EXIST));
            $this->assertEquals($markerbefore, $DB->get_record('local_rtcsync_user', [
                'userid' => (int) $existing->id,
            ], '*', MUST_EXIST));
            $this->assertEquals($profilebefore, $DB->get_record('user_info_data', [
                'userid' => (int) $existing->id,
                'fieldid' => (int) $profilefieldid,
            ], '*', MUST_EXIST));
        }
    }

    public function test_user_upsert_rejects_invalid_suspension_without_creating_account_or_marker(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        foreach ([-1, 2] as $index => $suspended) {
            $suffix = $index === 0 ? 'negative' : 'above-one';
            $username = 'rtc-invalid-suspension-' . $suffix . '@example.test';
            $idnumber = 'rtc-user:' . (73020 + $index);
            try {
                \local_rtcsync_external::upsert_user([
                    'moodleid' => 0,
                    'username' => $username,
                    'email' => $username,
                    'firstname' => 'Must Not Create',
                    'lastname' => 'Invalid Suspension',
                    'idnumber' => $idnumber,
                    'phone1' => '',
                    'suspended' => $suspended,
                    'profile_fields' => [],
                ]);
                $this->fail('Suspension value ' . $suspended . ' must be rejected before account creation.');
            } catch (\invalid_parameter_exception $exception) {
                $this->assertStringContainsString('Suspended flag must be 0 or 1.', $exception->getMessage());
            }

            $this->assertSame(0, $DB->count_records('user', [
                'username' => $username,
                'deleted' => 0,
            ]));
            $this->assertSame(0, $DB->count_records('user', [
                'idnumber' => $idnumber,
                'deleted' => 0,
            ]));
            $this->assertSame(0, $DB->count_records('local_rtcsync_user', ['idnumber' => $idnumber]));
        }
    }

    public function test_user_upsert_waits_for_stable_identity_lock_before_creating_account(): void
    {
        global $CFG, $DB;

        $this->resetAfterTest();
        $CFG->lock_factory = '\\core\\lock\\file_lock_factory';
        $this->setAdminUser();
        $idnumber = 'rtc-user:73014';
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_rtcsync_user_identity');
        $lock = $lockfactory->get_lock($idnumber, 0);
        $this->assertNotFalse($lock);

        $timedout = false;
        try {
            \local_rtcsync_external::upsert_user([
                'moodleid' => 0,
                'username' => 'rtc.identity-lock@example.test',
                'email' => 'rtc.identity-lock@example.test',
                'firstname' => 'RTC',
                'lastname' => 'Identity Lock',
                'idnumber' => $idnumber,
                'phone1' => '',
                'suspended' => 0,
                'profile_fields' => [],
            ]);
        } catch (\moodle_exception $exception) {
            $timedout = $exception->errorcode === 'locktimeout';
        } finally {
            $lock->release();
        }

        $this->assertTrue($timedout, 'A competing upsert must not pass the stable-identity lookup and create path.');
        $this->assertSame(0, $DB->count_records('user', [
            'idnumber' => $idnumber,
            'deleted' => 0,
        ]));
        $this->assertSame(0, $DB->count_records('local_rtcsync_user', ['idnumber' => $idnumber]));
    }

    public function test_user_upsert_waits_for_explicit_moodle_mapping_lock_before_migration(): void
    {
        global $CFG, $DB;

        $this->resetAfterTest();
        $CFG->lock_factory = '\\core\\lock\\file_lock_factory';
        $this->setAdminUser();
        $legacy = $this->createLegacyManagedUser(
            'rtc.identity-migration-lock@example.test',
            'rtc.identity-migration-lock@example.test',
            'LEGACY-CARD-73015'
        );
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_rtcsync_user_identity');
        $lock = $lockfactory->get_lock('moodle:' . (int) $legacy->id, 0);
        $this->assertNotFalse($lock);

        $timedout = false;
        try {
            \local_rtcsync_external::upsert_user([
                'moodleid' => (int) $legacy->id,
                'username' => 'rtc.identity-migration-lock@example.test',
                'email' => 'rtc.identity-migration-lock@example.test',
                'firstname' => 'RTC',
                'lastname' => 'Identity Lock',
                'idnumber' => 'rtc-user:73015',
                'phone1' => '',
                'suspended' => 0,
                'profile_fields' => [],
            ]);
        } catch (\moodle_exception $exception) {
            $timedout = $exception->errorcode === 'locktimeout';
        } finally {
            $lock->release();
        }

        $this->assertTrue($timedout, 'A competing legacy migration must not pass the Moodle mapping lock.');
        $this->assertSame('LEGACY-CARD-73015', (string) $DB->get_field('user', 'idnumber', ['id' => (int) $legacy->id]));
        $this->assertSame('LEGACY-CARD-73015', (string) $DB->get_field(
            'local_rtcsync_user',
            'idnumber',
            ['userid' => (int) $legacy->id]
        ));
    }

    public function test_user_upsert_requires_a_canonical_numeric_rtc_user_idnumber(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\invalid_parameter_exception::class);
        \local_rtcsync_external::upsert_user([
            'moodleid' => 0,
            'username' => 'rtc-idnumber-contract@example.test',
            'email' => 'rtc-idnumber-contract@example.test',
            'firstname' => 'RTC',
            'lastname' => 'Student',
            'idnumber' => 'ID-CARD-IS-NOT-STABLE',
            'phone1' => '',
            'suspended' => 0,
            'profile_fields' => [],
        ]);
    }

    public function test_user_upsert_rejects_duplicate_stable_idnumber_matches(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->getDataGenerator()->create_user(['idnumber' => 'rtc-user:70001']);
        $this->getDataGenerator()->create_user(['idnumber' => 'rtc-user:70001']);

        $this->expectException(\invalid_parameter_exception::class);
        \local_rtcsync_external::upsert_user([
            'moodleid' => 0,
            'username' => 'rtc-duplicate-idnumber@example.test',
            'email' => 'rtc-duplicate-idnumber@example.test',
            'firstname' => 'RTC',
            'lastname' => 'Student',
            'idnumber' => 'rtc-user:70001',
            'phone1' => '',
            'suspended' => 0,
            'profile_fields' => [],
        ]);
    }

    public function test_user_upsert_does_not_reuse_a_stable_id_with_a_deleted_account_marker(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $deleted = $this->createManagedUser(['idnumber' => 'rtc-user:70002']);
        $DB->set_field('user', 'deleted', 1, ['id' => (int) $deleted->id]);

        try {
            \local_rtcsync_external::upsert_user([
                'moodleid' => 0,
                'username' => 'rtc-reused-stable-id@example.test',
                'email' => 'rtc-reused-stable-id@example.test',
                'firstname' => 'Must',
                'lastname' => 'Not Reuse',
                'idnumber' => 'rtc-user:70002',
                'phone1' => '',
                'suspended' => 0,
                'profile_fields' => [],
            ]);
            $this->fail('A deleted account marker must reserve its stable RTC identity.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString(
                'The stable RTC user idnumber is already reserved by an unavailable Moodle ownership marker.',
                $exception->getMessage()
            );
        }

        $this->assertSame(0, $DB->count_records('user', [
            'username' => 'rtc-reused-stable-id@example.test',
            'deleted' => 0,
        ]));
        $this->assertSame(1, $DB->count_records('local_rtcsync_user', [
            'idnumber' => 'rtc-user:70002',
        ]));
    }

    public function test_user_upsert_rejects_a_stale_mapping_to_a_different_stable_rtc_user(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $created = \local_rtcsync_external::upsert_user([
            'moodleid' => 0,
            'username' => 'rtc-stable-map@example.test',
            'email' => 'rtc-stable-map@example.test',
            'firstname' => 'Stable',
            'lastname' => 'Mapping',
            'idnumber' => 'rtc-user:7101',
            'phone1' => '',
            'suspended' => 0,
            'profile_fields' => [],
        ]);

        try {
            \local_rtcsync_external::upsert_user([
                'moodleid' => (int) $created['id'],
                'username' => 'rtc-stable-map@example.test',
                'email' => 'rtc-stable-map@example.test',
                'firstname' => 'Must',
                'lastname' => 'Not Reassign',
                'idnumber' => 'rtc-user:7102',
                'phone1' => '',
                'suspended' => 0,
                'profile_fields' => [],
            ]);
            $this->fail('A Moodle mapping must not be reassigned from one stable RTC user to another.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('stable RTC user identity', $exception->getMessage());
        }

        $this->assertSame('rtc-user:7101', $DB->get_field('user', 'idnumber', ['id' => (int) $created['id']]));
        $this->assertSame('rtc-user:7101', $DB->get_field(
            'local_rtcsync_user',
            'idnumber',
            ['userid' => (int) $created['id']]
        ));

        try {
            \local_rtcsync_external::upsert_user([
                'moodleid' => (int) $created['id'],
                'username' => 'rtc-stable-map@example.test',
                'email' => 'rtc-stable-map@example.test',
                'firstname' => 'Must',
                'lastname' => 'Not Downgrade',
                'idnumber' => 'LEGACY-CARD-7101',
                'phone1' => '',
                'suspended' => 0,
                'profile_fields' => [],
            ]);
            $this->fail('A stable RTC user idnumber must not be downgraded to an ID card.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('canonical rtc-user:', $exception->getMessage());
        }
        $this->assertSame('rtc-user:7101', $DB->get_field('user', 'idnumber', ['id' => (int) $created['id']]));
    }

    public function test_user_upsert_rejects_login_fields_owned_by_another_stable_account(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $target = $this->createManagedUser([
            'username' => 'rtc-login-target@example.test',
            'email' => 'rtc-login-target@example.test',
            'idnumber' => 'rtc-user:7103',
        ]);
        $other = $this->createManagedUser([
            'username' => 'rtc-login-other@example.test',
            'email' => 'rtc-login-other@example.test',
            'idnumber' => 'rtc-user:7104',
        ]);

        try {
            \local_rtcsync_external::upsert_user([
                'moodleid' => 0,
                'username' => (string) $other->username,
                'email' => (string) $other->email,
                'firstname' => 'Must',
                'lastname' => 'Not Cross-Link',
                'idnumber' => 'rtc-user:7103',
                'phone1' => '',
                'suspended' => 1,
                'profile_fields' => [],
            ]);
            $this->fail('Login fields belonging to another Moodle user must not be applied to a stable identity.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('username belongs to a different Moodle account', $exception->getMessage());
        }

        $this->assertSame('rtc-login-target@example.test', $DB->get_field('user', 'username', ['id' => (int) $target->id]));
        $this->assertSame('rtc-login-target@example.test', $DB->get_field('user', 'email', ['id' => (int) $target->id]));
        $this->assertSame(0, (int) $DB->get_field('user', 'suspended', ['id' => (int) $target->id]));
        $this->assertSame('rtc-user:7104', $DB->get_field('user', 'idnumber', ['id' => (int) $other->id]));
    }

    public function test_user_upsert_does_not_infer_a_legacy_id_card_link_from_email(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $legacy = $this->createLegacyManagedUser(
            'rtc-legacy-card@example.test',
            'rtc-legacy-card@example.test',
            'LEGACY-CARD-7201'
        );

        try {
            \local_rtcsync_external::upsert_user([
                'moodleid' => 0,
                'username' => 'rtc-legacy-card@example.test',
                'email' => 'rtc-legacy-card@example.test',
                'firstname' => 'RTC',
                'lastname' => 'Student',
                'idnumber' => 'rtc-user:7201',
                'phone1' => '',
                'suspended' => 0,
                'profile_fields' => [],
            ]);
            $this->fail('A legacy card account needs a stable mapping before it can be linked.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('explicit Moodle mapping', $exception->getMessage());
        }

        $this->assertSame('LEGACY-CARD-7201', $DB->get_field('user', 'idnumber', ['id' => (int) $legacy->id]));
        $this->assertSame('LEGACY-CARD-7201', $DB->get_field(
            'local_rtcsync_user',
            'idnumber',
            ['userid' => (int) $legacy->id]
        ));
    }

    public function test_user_upsert_migrates_a_legacy_id_card_only_with_an_explicit_moodle_mapping(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $legacy = $this->createLegacyManagedUser(
            'rtc-legacy-mapped@example.test',
            'rtc-legacy-mapped@example.test',
            'LEGACY-CARD-7202'
        );

        $migrated = \local_rtcsync_external::upsert_user([
            'moodleid' => (int) $legacy->id,
            'username' => 'rtc-legacy-mapped@example.test',
            'email' => 'rtc-legacy-mapped@example.test',
            'firstname' => 'RTC',
            'lastname' => 'Student',
            'idnumber' => 'rtc-user:7202',
            'phone1' => '',
            'suspended' => 0,
            'profile_fields' => [],
        ]);

        $this->assertSame((int) $legacy->id, $migrated['id']);
        $this->assertSame('rtc-user:7202', $DB->get_field('user', 'idnumber', ['id' => (int) $legacy->id]));
        $this->assertSame('rtc-user:7202', $DB->get_field(
            'local_rtcsync_user',
            'idnumber',
            ['userid' => (int) $legacy->id]
        ));
    }

    public function test_user_upsert_rolls_back_identity_and_marker_when_profile_sync_fails(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $legacy = $this->createLegacyManagedUser(
            'rtc-atomic-legacy@example.test',
            'rtc-atomic-legacy@example.test',
            'LEGACY-CARD-ATOMIC'
        );
        $userbefore = $DB->get_record('user', ['id' => (int) $legacy->id], '*', MUST_EXIST);
        $markerbefore = $DB->get_record('local_rtcsync_user', ['userid' => (int) $legacy->id], '*', MUST_EXIST);

        try {
            \local_rtcsync_external::upsert_user([
                'moodleid' => (int) $legacy->id,
                'username' => 'rtc-atomic-legacy-renamed@example.test',
                'email' => 'rtc-atomic-legacy-renamed@example.test',
                'firstname' => 'Changed',
                'lastname' => 'Before Failure',
                'idnumber' => 'rtc-user:73016',
                'phone1' => '12345',
                'suspended' => 1,
                'profile_fields' => [[
                    'shortname' => 'rtc_unknown_atomic_test_field',
                    'value' => 'invalid',
                ]],
            ]);
            $this->fail('An invalid profile field must abort the complete identity migration.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('Unknown RTC profile field', $exception->getMessage());
        }

        $this->assertEquals(
            $userbefore,
            $DB->get_record('user', ['id' => (int) $legacy->id], '*', MUST_EXIST),
            'A failed migration must preserve all Moodle account fields.'
        );
        $this->assertEquals(
            $markerbefore,
            $DB->get_record('local_rtcsync_user', ['userid' => (int) $legacy->id], '*', MUST_EXIST),
            'A failed migration must preserve the stable-identity ownership marker.'
        );

        try {
            \local_rtcsync_external::upsert_user([
                'moodleid' => 0,
                'username' => 'rtc-atomic-new@example.test',
                'email' => 'rtc-atomic-new@example.test',
                'firstname' => 'New',
                'lastname' => 'Before Failure',
                'idnumber' => 'rtc-user:73017',
                'phone1' => '',
                'suspended' => 0,
                'profile_fields' => [[
                    'shortname' => 'rtc_unknown_atomic_test_field',
                    'value' => 'invalid',
                ]],
            ]);
            $this->fail('A profile failure must not leave a partially created RTC account.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('Unknown RTC profile field', $exception->getMessage());
        }

        $this->assertSame(0, $DB->count_records('user', [
            'idnumber' => 'rtc-user:73017',
            'deleted' => 0,
        ]));
        $this->assertSame(0, $DB->count_records('local_rtcsync_user', [
            'idnumber' => 'rtc-user:73017',
        ]));
    }

    public function test_user_upsert_rolls_back_user_and_profile_when_marker_update_fails(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $legacy = $this->createLegacyManagedUser(
            'rtc-marker-write-failure@example.test',
            'rtc-marker-write-failure@example.test',
            'LEGACY-CARD-MARKER-FAILURE'
        );
        local_rtcsync_save_profile_fields((int) $legacy->id, [[
            'shortname' => 'rtc_batch_code',
            'value' => 'BEFORE-MIGRATION',
        ]]);

        $userbefore = $DB->get_record('user', ['id' => (int) $legacy->id], '*', MUST_EXIST);
        $markerbefore = $DB->get_record('local_rtcsync_user', ['userid' => (int) $legacy->id], '*', MUST_EXIST);
        $profilefieldid = local_rtcsync_ensure_profile_fields()['rtc_batch_code'];
        $profilebefore = $DB->get_record('user_info_data', [
            'userid' => (int) $legacy->id,
            'fieldid' => $profilefieldid,
        ], '*', MUST_EXIST);

        $realdb = $DB;
        $faultdb = new class($realdb) {
            /** @var moodle_database */
            private $database;

            /** @var bool */
            public $markerupdatefailed = false;

            public function __construct($database) {
                $this->database = $database;
            }

            public function __call(string $name, array $arguments) {
                if ($name === 'update_record' && ($arguments[0] ?? null) === 'local_rtcsync_user') {
                    $this->markerupdatefailed = true;
                    throw new \dml_write_exception(
                        'injected-marker-write-failure',
                        'UPDATE {local_rtcsync_user}',
                        []
                    );
                }

                return $this->database->{$name}(...$arguments);
            }

            public function __get(string $name) {
                return $this->database->{$name};
            }

            public function __isset(string $name): bool {
                return isset($this->database->{$name});
            }
        };

        $DB = $faultdb;
        try {
            \local_rtcsync_external::upsert_user([
                'moodleid' => (int) $legacy->id,
                'username' => 'rtc-marker-write-failure-renamed@example.test',
                'email' => 'rtc-marker-write-failure-renamed@example.test',
                'firstname' => 'Changed',
                'lastname' => 'Before Marker Failure',
                'idnumber' => 'rtc-user:73018',
                'phone1' => '54321',
                'suspended' => 1,
                'profile_fields' => [[
                    'shortname' => 'rtc_batch_code',
                    'value' => 'AFTER-MIGRATION',
                ]],
            ]);
            $this->fail('A database failure while updating the ownership marker must abort user migration.');
        } catch (\dml_write_exception $exception) {
            $this->assertSame('dmlwriteexception', $exception->errorcode);
        } finally {
            $DB = $realdb;
        }

        $this->assertTrue($faultdb->markerupdatefailed, 'The injected failure must occur at the marker write boundary.');
        $this->assertEquals(
            $userbefore,
            $DB->get_record('user', ['id' => (int) $legacy->id], '*', MUST_EXIST),
            'The Moodle identity/profile fields must be rolled back after marker persistence fails.'
        );
        $this->assertEquals(
            $markerbefore,
            $DB->get_record('local_rtcsync_user', ['userid' => (int) $legacy->id], '*', MUST_EXIST),
            'The ownership marker must retain its pre-migration identity.'
        );
        $this->assertEquals(
            $profilebefore,
            $DB->get_record('user_info_data', [
                'userid' => (int) $legacy->id,
                'fieldid' => $profilefieldid,
            ], '*', MUST_EXIST),
            'RTC profile changes must roll back with the failed identity migration.'
        );
    }

    public function test_user_upsert_resolves_legacy_account_from_exact_stable_marker_after_email_change(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $legacy = $this->createLegacyManagedUser(
            'rtc-legacy-marker@example.test',
            'rtc-legacy-marker@example.test',
            'LEGACY-CARD-7204'
        );
        $DB->set_field('local_rtcsync_user', 'idnumber', 'rtc-user:7204', ['userid' => (int) $legacy->id]);

        $migrated = \local_rtcsync_external::upsert_user([
            'moodleid' => 0,
            'username' => 'rtc-renamed-marker@example.test',
            'email' => 'rtc-renamed-marker@example.test',
            'firstname' => 'RTC',
            'lastname' => 'Student',
            'idnumber' => 'rtc-user:7204',
            'phone1' => '',
            'suspended' => 0,
            'profile_fields' => [],
        ]);

        $this->assertSame((int) $legacy->id, $migrated['id']);
        $this->assertSame('rtc-user:7204', $migrated['idnumber']);
        $this->assertSame(1, $DB->count_records('user', ['idnumber' => 'rtc-user:7204', 'deleted' => 0]));
        $this->assertSame(1, $DB->count_records('local_rtcsync_user', ['idnumber' => 'rtc-user:7204']));
    }

    public function test_user_upsert_rejects_duplicate_stable_markers_without_mutating_either_account(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $first = $this->createLegacyManagedUser(
            'rtc-duplicate-marker-one@example.test',
            'rtc-duplicate-marker-one@example.test',
            'LEGACY-CARD-7205A'
        );
        $second = $this->createLegacyManagedUser(
            'rtc-duplicate-marker-two@example.test',
            'rtc-duplicate-marker-two@example.test',
            'LEGACY-CARD-7205B'
        );
        $DB->set_field('local_rtcsync_user', 'idnumber', 'rtc-user:7205', ['userid' => (int) $first->id]);
        $DB->set_field('local_rtcsync_user', 'idnumber', 'rtc-user:7205', ['userid' => (int) $second->id]);

        try {
            \local_rtcsync_external::upsert_user([
                'moodleid' => 0,
                'username' => (string) $first->username,
                'email' => (string) $first->email,
                'firstname' => 'Must',
                'lastname' => 'Not Adopt',
                'idnumber' => 'rtc-user:7205',
                'phone1' => '',
                'suspended' => 1,
                'profile_fields' => [],
            ]);
            $this->fail('Duplicate stable markers must be rejected as ambiguous.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('marked on multiple', $exception->getMessage());
        }

        $this->assertSame('LEGACY-CARD-7205A', $DB->get_field('user', 'idnumber', ['id' => (int) $first->id]));
        $this->assertSame('LEGACY-CARD-7205B', $DB->get_field('user', 'idnumber', ['id' => (int) $second->id]));
        $this->assertSame(0, (int) $DB->get_field('user', 'suspended', ['id' => (int) $first->id]));
    }

    public function test_user_upsert_rejects_stable_idnumber_and_marker_for_different_accounts(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $byidnumber = $this->getDataGenerator()->create_user([
            'username' => 'rtc-conflicting-idnumber@example.test',
            'email' => 'rtc-conflicting-idnumber@example.test',
            'idnumber' => 'rtc-user:7206',
        ]);
        $bymarker = $this->createLegacyManagedUser(
            'rtc-conflicting-marker@example.test',
            'rtc-conflicting-marker@example.test',
            'LEGACY-CARD-7206'
        );
        $DB->set_field('local_rtcsync_user', 'idnumber', 'rtc-user:7206', ['userid' => (int) $bymarker->id]);

        try {
            \local_rtcsync_external::upsert_user([
                'moodleid' => 0,
                'username' => (string) $bymarker->username,
                'email' => (string) $bymarker->email,
                'firstname' => 'Must',
                'lastname' => 'Not Reassign',
                'idnumber' => 'rtc-user:7206',
                'phone1' => '',
                'suspended' => 1,
                'profile_fields' => [],
            ]);
            $this->fail('Stable user idnumber and marker references to different Moodle accounts must conflict.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('identify different Moodle accounts', $exception->getMessage());
        }

        $this->assertSame('rtc-user:7206', $DB->get_field('user', 'idnumber', ['id' => (int) $byidnumber->id]));
        $this->assertSame('LEGACY-CARD-7206', $DB->get_field('user', 'idnumber', ['id' => (int) $bymarker->id]));
        $this->assertSame(0, (int) $DB->get_field('user', 'suspended', ['id' => (int) $bymarker->id]));
    }

    public function test_user_upsert_can_adopt_an_unmarked_legacy_user_only_with_an_explicit_mapping(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $legacy = $this->getDataGenerator()->create_user([
            'username' => 'rtc-legacy-unmarked@example.test',
            'email' => 'rtc-legacy-unmarked@example.test',
            'idnumber' => 'LEGACY-CARD-7203',
        ]);

        $migrated = \local_rtcsync_external::upsert_user([
            'moodleid' => (int) $legacy->id,
            'username' => 'rtc-legacy-unmarked@example.test',
            'email' => 'rtc-legacy-unmarked@example.test',
            'firstname' => 'RTC',
            'lastname' => 'Student',
            'idnumber' => 'rtc-user:7203',
            'phone1' => '',
            'suspended' => 0,
            'profile_fields' => [],
        ]);

        $this->assertSame((int) $legacy->id, $migrated['id']);
        $this->assertSame('rtc-user:7203', $DB->get_field('user', 'idnumber', ['id' => (int) $legacy->id]));
        $this->assertSame('rtc-user:7203', $DB->get_field(
            'local_rtcsync_user',
            'idnumber',
            ['userid' => (int) $legacy->id]
        ));
    }

    public function test_user_upsert_refuses_to_mutate_an_unmanaged_existing_account(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();

        $existing = $this->getDataGenerator()->create_user([
            'username' => 'manual-owned-boundary',
            'email' => 'manual-owned-boundary@example.test',
        ]);

        $this->expectException(\invalid_parameter_exception::class);

        \local_rtcsync_external::upsert_user([
            'moodleid' => 0,
            'username' => $existing->username,
            'email' => $existing->email,
            'firstname' => 'Must Not Change',
            'lastname' => $existing->lastname,
            'idnumber' => 'rtc-user:74001',
            'phone1' => '',
            'suspended' => 1,
            'profile_fields' => [],
        ]);
    }

    public function test_course_upsert_requires_a_reserved_subject_identifier(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\invalid_parameter_exception::class);

        \local_rtcsync_external::upsert_course([
            'fullname' => 'Manual course must remain untouched',
            'shortname' => 'MANUAL-COURSE',
            'idnumber' => 'manual-course:1',
            'summary' => '',
            'category_idnumber' => 'rtc-academic',
            'category_name' => 'RTC Academic Courses',
            'category_path' => [],
            'visible' => 1,
        ]);
    }

    public function test_enrolment_refuses_unmanaged_course_even_for_a_managed_user(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('sendcoursewelcomemessage', 0, 'enrol_manual');

        $course = $this->getDataGenerator()->create_course([
            'idnumber' => 'manual-course:2',
        ]);
        $user = $this->createManagedUser();

        $this->expectException(\invalid_parameter_exception::class);

        \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'student',
            'suspend' => 0,
        ]);
    }

    public function test_standalone_history_marker_writes_are_source_scoped_and_do_not_change_enrolment(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('sendcoursewelcomemessage', 0, 'enrol_manual');
        set_config('enableavailability', 1);
        $course = $this->createManagedCourse();
        $this->createDeliveryGrouping($course, 77);
        $user = $this->createManagedUser();
        \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'student',
            'suspend' => 1,
        ]);
        $this->assertTrue(\local_rtcsync_external::prepare_delivery_course((int) $course->id)['ready']);
        $revision = 20261031000000000;
        \local_rtcsync_external::sync_student_delivery_scope([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'deliveryids' => [77],
            'revision' => $revision,
        ]);
        $instance = $DB->get_record('enrol', [
            'courseid' => (int) $course->id,
            'enrol' => 'manual',
        ], '*', MUST_EXIST);
        $context = \context_course::instance((int) $course->id);
        $enrolmentbefore = $DB->get_record('user_enrolments', [
            'enrolid' => (int) $instance->id,
            'userid' => (int) $user->id,
        ], '*', MUST_EXIST);
        $rolesbefore = $DB->get_records('role_assignments', [
            'contextid' => (int) $context->id,
            'userid' => (int) $user->id,
        ], 'id ASC');

        $first = \local_rtcsync_external::sync_history_marker([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'historyaccess' => 1,
            'historysourcetype' => 'user_program',
            'historysourceid' => 101,
        ]);
        \local_rtcsync_external::sync_history_marker([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'historyaccess' => 1,
            'historysourcetype' => 'delivery_participation',
            'historysourceid' => 202,
            'deliveryid' => 77,
        ]);
        $clear = \local_rtcsync_external::sync_history_marker([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'historyaccess' => 0,
            'historysourcetype' => 'delivery_participation',
            'historysourceid' => 202,
            'deliveryid' => 77,
            'expectedscoperevision' => $revision,
        ]);
        \local_rtcsync_external::sync_history_marker([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'historyaccess' => 1,
            'historysourcetype' => 'user_program',
            'historysourceid' => 102,
        ]);
        $userprogramclear = \local_rtcsync_external::sync_history_marker([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'historyaccess' => 0,
            'historysourcetype' => 'user_program',
            'historysourceid' => 102,
        ]);

        $enrolmentafter = $DB->get_record('user_enrolments', [
            'enrolid' => (int) $instance->id,
            'userid' => (int) $user->id,
        ], '*', MUST_EXIST);
        $rolesafter = $DB->get_records('role_assignments', [
            'contextid' => (int) $context->id,
            'userid' => (int) $user->id,
        ], 'id ASC');

        $this->assertSame(1, $first['historyaccess']);
        $this->assertSame(1, $clear['applied']);
        $this->assertSame($revision, $clear['scoperevision']);
        $this->assertSame(1, $userprogramclear['applied']);
        $this->assertSame(0, $userprogramclear['scoperevision']);
        $this->assertSame(ENROL_USER_SUSPENDED, (int) $enrolmentafter->status);
        $this->assertEquals($enrolmentbefore, $enrolmentafter);
        $this->assertEquals($rolesbefore, $rolesafter);
        $this->assertTrue($DB->record_exists('local_rtcsync_history', [
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'sourcetype' => 'user_program',
            'sourceid' => 101,
        ]));
        $this->assertFalse($DB->record_exists('local_rtcsync_history', [
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'sourcetype' => 'user_program',
            'sourceid' => 102,
        ]));
        $this->assertFalse($DB->record_exists('local_rtcsync_history', [
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'sourcetype' => 'delivery_participation',
            'sourceid' => 202,
        ]));
    }

    public function test_history_marker_rejects_invalid_source_type(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse();
        $user = $this->createManagedUser();
        \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'student',
            'suspend' => 1,
        ]);

        $this->expectException(\invalid_parameter_exception::class);
        \local_rtcsync_external::sync_history_marker([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'historyaccess' => 1,
            'historysourcetype' => 'unsupported_source',
            'historysourceid' => 303,
        ]);
    }

    public function test_history_marker_rejects_invalid_access_flag(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse();
        $user = $this->createManagedUser();
        \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'student',
            'suspend' => 1,
        ]);

        $this->expectException(\invalid_parameter_exception::class);
        \local_rtcsync_external::sync_history_marker([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'historyaccess' => 2,
            'historysourcetype' => 'user_program',
            'historysourceid' => 304,
        ]);
    }

    public function test_history_marker_cannot_be_set_for_an_active_student_enrolment(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse();
        $user = $this->createManagedUser();
        \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'student',
            'suspend' => 0,
        ]);

        $this->expectException(\invalid_parameter_exception::class);
        \local_rtcsync_external::sync_history_marker([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'historyaccess' => 1,
            'historysourcetype' => 'user_program',
            'historysourceid' => 305,
        ]);
    }

    public function test_delivery_history_marker_is_allowed_for_active_student_enrolment(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enableavailability', 1);
        $course = $this->createManagedCourse();
        $this->createDeliveryGrouping($course, 77);
        $user = $this->createManagedUser();
        $this->assertTrue(\local_rtcsync_external::prepare_delivery_course((int) $course->id)['ready']);
        \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'student',
            'suspend' => 0,
        ]);

        $result = \local_rtcsync_external::sync_history_marker([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'historyaccess' => 1,
            'historysourcetype' => 'delivery_participation',
            'historysourceid' => 9201,
            'deliveryid' => 77,
        ]);

        $marker = $DB->get_record('local_rtcsync_history', [
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'sourcetype' => 'delivery_participation',
            'sourceid' => 9201,
        ], '*', MUST_EXIST);
        $this->assertSame(77, (int) $marker->deliveryid);
        $this->assertSame(77, (int) $result['deliveryid']);
    }

    public function test_delivery_history_marker_grant_retry_updates_the_same_source_row(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enableavailability', 1);
        $course = $this->createManagedCourse();
        $this->createDeliveryGrouping($course, 77);
        $user = $this->createManagedUser();
        $this->assertTrue(\local_rtcsync_external::prepare_delivery_course((int) $course->id)['ready']);
        \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'student',
            'suspend' => 1,
        ]);

        $source = [
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'historyaccess' => 1,
            'historysourcetype' => 'delivery_participation',
            'historysourceid' => 9208,
            'deliveryid' => 77,
        ];
        $first = \local_rtcsync_external::sync_history_marker($source);
        $initial = $DB->get_record('local_rtcsync_history', [
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'sourcetype' => 'delivery_participation',
            'sourceid' => 9208,
        ], '*', MUST_EXIST);
        $DB->set_field('local_rtcsync_history', 'timemodified', time() - 60, ['id' => (int) $initial->id]);
        $before = $DB->get_record('local_rtcsync_history', ['id' => (int) $initial->id], '*', MUST_EXIST);

        $replay = \local_rtcsync_external::sync_history_marker($source);

        $after = $DB->get_record('local_rtcsync_history', [
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'sourcetype' => 'delivery_participation',
            'sourceid' => 9208,
        ], '*', MUST_EXIST);
        $this->assertSame(1, (int) $first['applied']);
        $this->assertSame(1, (int) $replay['applied']);
        $this->assertSame((int) $initial->id, (int) $after->id);
        $this->assertSame((int) $initial->timecreated, (int) $after->timecreated);
        $this->assertGreaterThan((int) $before->timemodified, (int) $after->timemodified);
        $this->assertSame(77, (int) $after->deliveryid);
        $this->assertSame(1, $DB->count_records('local_rtcsync_history', [
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'sourcetype' => 'delivery_participation',
            'sourceid' => 9208,
        ]));
    }

    public function test_delivery_history_source_cannot_be_reassigned_to_another_delivery(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enableavailability', 1);
        $course = $this->createManagedCourse();
        $this->createDeliveryGrouping($course, 77);
        $this->createDeliveryGrouping($course, 88);
        $user = $this->createManagedUser();
        $this->assertTrue(\local_rtcsync_external::prepare_delivery_course((int) $course->id)['ready']);
        \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'student',
            'suspend' => 0,
        ]);

        \local_rtcsync_external::sync_history_marker([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'historyaccess' => 1,
            'historysourcetype' => 'delivery_participation',
            'historysourceid' => 9202,
            'deliveryid' => 77,
        ]);

        try {
            \local_rtcsync_external::sync_history_marker([
                'courseid' => (int) $course->id,
                'userid' => (int) $user->id,
                'historyaccess' => 1,
                'historysourcetype' => 'delivery_participation',
                'historysourceid' => 9202,
                'deliveryid' => 88,
            ]);
            $this->fail('An existing participation source must not be silently remapped to another delivery.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('cannot be reassigned', $exception->getMessage());
        }

        $marker = $DB->get_record('local_rtcsync_history', [
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'sourcetype' => 'delivery_participation',
            'sourceid' => 9202,
        ], '*', MUST_EXIST);
        $this->assertSame(77, (int) $marker->deliveryid);
    }

    public function test_stale_out_of_scope_delivery_history_clear_preserves_released_marker(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enableavailability', 1);
        $course = $this->createManagedCourse();
        $this->createDeliveryGrouping($course, 77);
        $this->createDeliveryGrouping($course, 88);
        $user = $this->createManagedUser();
        $this->assertTrue(\local_rtcsync_external::prepare_delivery_course((int) $course->id)['ready']);
        \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'student',
            'suspend' => 0,
        ]);

        \local_rtcsync_external::sync_history_marker([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'historyaccess' => 1,
            'historysourcetype' => 'delivery_participation',
            'historysourceid' => 9203,
            'deliveryid' => 77,
        ]);
        \local_rtcsync_external::sync_student_delivery_scope([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'deliveryids' => [88],
            'revision' => 20261025000000000,
        ]);

        $staleClear = \local_rtcsync_external::sync_history_marker([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'historyaccess' => 0,
            'historysourcetype' => 'delivery_participation',
            'historysourceid' => 9203,
            'deliveryid' => 77,
            'expectedscoperevision' => 20261025000000000,
        ]);

        $this->assertSame(0, $staleClear['applied']);
        $this->assertSame(20261025000000000, $staleClear['scoperevision']);
        $this->assertTrue($DB->record_exists('local_rtcsync_history', [
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'sourcetype' => 'delivery_participation',
            'sourceid' => 9203,
            'deliveryid' => 77,
        ]));
    }

    public function test_delivery_history_clear_requires_matching_revision_and_in_scope_delivery(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enableavailability', 1);
        $course = $this->createManagedCourse();
        $this->createDeliveryGrouping($course, 77);
        $this->createDeliveryGrouping($course, 88);
        $user = $this->createManagedUser();
        $this->assertTrue(\local_rtcsync_external::prepare_delivery_course((int) $course->id)['ready']);
        \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'student',
            'suspend' => 1,
        ]);

        $revision = 20261031000000000;
        \local_rtcsync_external::sync_student_delivery_scope([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'deliveryids' => [77],
            'revision' => $revision,
        ]);
        \local_rtcsync_external::sync_history_marker([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'historyaccess' => 1,
            'historysourcetype' => 'delivery_participation',
            'historysourceid' => 9204,
            'deliveryid' => 77,
        ]);

        $missingrevision = \local_rtcsync_external::sync_history_marker([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'historyaccess' => 0,
            'historysourcetype' => 'delivery_participation',
            'historysourceid' => 9204,
            'deliveryid' => 77,
        ]);
        $this->assertSame(0, $missingrevision['applied']);
        $this->assertSame($revision, $missingrevision['scoperevision']);
        $this->assertTrue($DB->record_exists('local_rtcsync_history', [
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'sourcetype' => 'delivery_participation',
            'sourceid' => 9204,
            'deliveryid' => 77,
        ]));

        $rejected = \local_rtcsync_external::sync_history_marker([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'historyaccess' => 0,
            'historysourcetype' => 'delivery_participation',
            'historysourceid' => 9204,
            'deliveryid' => 77,
            'expectedscoperevision' => $revision - 1,
        ]);
        $this->assertSame(0, $rejected['applied']);
        $this->assertSame($revision, $rejected['scoperevision']);
        $this->assertTrue($DB->record_exists('local_rtcsync_history', [
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'sourcetype' => 'delivery_participation',
            'sourceid' => 9204,
            'deliveryid' => 77,
        ]));

        $accepted = \local_rtcsync_external::sync_history_marker([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'historyaccess' => 0,
            'historysourcetype' => 'delivery_participation',
            'historysourceid' => 9204,
            'deliveryid' => 77,
            'expectedscoperevision' => $revision,
        ]);
        $this->assertSame(1, $accepted['applied']);
        $this->assertSame($revision, $accepted['scoperevision']);
        $this->assertFalse($DB->record_exists('local_rtcsync_history', [
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'sourcetype' => 'delivery_participation',
            'sourceid' => 9204,
        ]));

        $replay = \local_rtcsync_external::sync_history_marker([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'historyaccess' => 0,
            'historysourcetype' => 'delivery_participation',
            'historysourceid' => 9204,
            'deliveryid' => 77,
            'expectedscoperevision' => $revision,
        ]);
        $this->assertSame(0, $replay['applied']);
        $this->assertSame($revision, $replay['scoperevision']);
    }

    public function test_delivery_history_clear_rejects_a_conflicting_stored_delivery(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enableavailability', 1);
        $course = $this->createManagedCourse();
        $this->createDeliveryGrouping($course, 77);
        $this->createDeliveryGrouping($course, 88);
        $user = $this->createManagedUser();
        $this->assertTrue(\local_rtcsync_external::prepare_delivery_course((int) $course->id)['ready']);
        \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'student',
            'suspend' => 1,
        ]);

        $revision = 20261031000000000;
        \local_rtcsync_external::sync_student_delivery_scope([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'deliveryids' => [77],
            'revision' => $revision,
        ]);
        $originalmarker = (object) [
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'sourcetype' => 'delivery_participation',
            'sourceid' => 9205,
            'deliveryid' => 88,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $originalmarker->id = $DB->insert_record('local_rtcsync_history', $originalmarker);

        try {
            \local_rtcsync_external::sync_history_marker([
                'courseid' => (int) $course->id,
                'userid' => (int) $user->id,
                'historyaccess' => 0,
                'historysourcetype' => 'delivery_participation',
                'historysourceid' => 9205,
                'deliveryid' => 77,
                'expectedscoperevision' => $revision,
            ]);
            $this->fail('A clear request must not silently accept a source marker bound to another delivery.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('deliveryhistorymarkermismatch', $exception->errorcode);
        }

        $this->assertEquals($originalmarker, $DB->get_record('local_rtcsync_history', [
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'sourcetype' => 'delivery_participation',
            'sourceid' => 9205,
        ], '*', MUST_EXIST));

        $absentclear = \local_rtcsync_external::sync_history_marker([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'historyaccess' => 0,
            'historysourcetype' => 'delivery_participation',
            'historysourceid' => 9206,
            'deliveryid' => 77,
            'expectedscoperevision' => $revision,
        ]);
        $this->assertSame(0, $absentclear['applied']);
        $this->assertSame($revision, $absentclear['scoperevision']);
    }

    public function test_delivery_history_grants_remain_independent_of_current_scope_revision(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enableavailability', 1);
        $course = $this->createManagedCourse();
        $this->createDeliveryGrouping($course, 77);
        $this->createDeliveryGrouping($course, 88);
        $user = $this->createManagedUser();
        $this->assertTrue(\local_rtcsync_external::prepare_delivery_course((int) $course->id)['ready']);
        \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'student',
            'suspend' => 1,
        ]);
        $revision = 20261031000000000;
        \local_rtcsync_external::sync_student_delivery_scope([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'deliveryids' => [88],
            'revision' => $revision,
        ]);

        $grant = \local_rtcsync_external::sync_history_marker([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'historyaccess' => 1,
            'historysourcetype' => 'delivery_participation',
            'historysourceid' => 9205,
            'deliveryid' => 77,
            'expectedscoperevision' => $revision - 1,
        ]);
        $this->assertSame(1, $grant['applied']);
        $this->assertSame($revision, $grant['scoperevision']);
        $this->assertTrue($DB->record_exists('local_rtcsync_history', [
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'sourcetype' => 'delivery_participation',
            'sourceid' => 9205,
            'deliveryid' => 77,
        ]));
    }

    public function test_minimum_delivery_scope_revision_requires_explicit_expected_revision(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enableavailability', 1);
        $course = $this->createManagedCourse();
        $this->createDeliveryGrouping($course, 77);
        $user = $this->createManagedUser();
        $this->assertTrue(\local_rtcsync_external::prepare_delivery_course((int) $course->id)['ready']);
        \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'student',
            'suspend' => 1,
        ]);
        $revision = 2026102505;
        \local_rtcsync_external::sync_student_delivery_scope([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'deliveryids' => [77],
            'revision' => $revision,
        ]);
        \local_rtcsync_external::sync_history_marker([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'historyaccess' => 1,
            'historysourcetype' => 'delivery_participation',
            'historysourceid' => 9206,
            'deliveryid' => 77,
        ]);

        $omitted = \local_rtcsync_external::sync_history_marker([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'historyaccess' => 0,
            'historysourcetype' => 'delivery_participation',
            'historysourceid' => 9206,
            'deliveryid' => 77,
        ]);
        $this->assertSame(0, $omitted['applied']);
        $this->assertSame($revision, $omitted['scoperevision']);
        $this->assertTrue($DB->record_exists('local_rtcsync_history', [
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'sourcetype' => 'delivery_participation',
            'sourceid' => 9206,
        ]));

        $explicit = \local_rtcsync_external::sync_history_marker([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'historyaccess' => 0,
            'historysourcetype' => 'delivery_participation',
            'historysourceid' => 9206,
            'deliveryid' => 77,
            'expectedscoperevision' => $revision,
        ]);
        $this->assertSame(1, $explicit['applied']);
        $this->assertSame($revision, $explicit['scoperevision']);
        $this->assertFalse($DB->record_exists('local_rtcsync_history', [
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'sourcetype' => 'delivery_participation',
            'sourceid' => 9206,
        ]));
    }

    public function test_unsupported_stored_scope_revision_denies_activity_and_preserves_history_marker(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enableavailability', 1);
        $course = $this->createManagedCourse(['showgrades' => 1]);
        $grouping = $this->createDeliveryGrouping($course, 77);
        $user = $this->createManagedUser();
        $this->assertTrue(\local_rtcsync_external::prepare_delivery_course((int) $course->id)['ready']);
        \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'student',
            'suspend' => 0,
        ]);
        \local_rtcsync_external::sync_history_marker([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'historyaccess' => 1,
            'historysourcetype' => 'delivery_participation',
            'historysourceid' => 9207,
            'deliveryid' => 77,
        ]);

        $group = $this->getDataGenerator()->create_group([
            'courseid' => (int) $course->id,
            'idnumber' => 'rtc-delivery-class-group:77:1',
        ]);
        groups_assign_grouping((int) $grouping->id, (int) $group->id);
        groups_add_member((int) $group->id, (int) $user->id);
        $DB->insert_record('local_rtcsync_studentscope', (object) [
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'deliveryids' => '[77]',
            'revision' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $subjectid = substr((string) $course->idnumber, strlen('rtc-subject:'));
        \local_rtcsync_external::upsert_grade([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'itemname' => 'Retained delivery result',
            'idnumber' => 'rtc-subject-score:' . $subjectid . ':delivery:77',
            'deliveryid' => 77,
            'grade' => 74.0,
        ]);

        $this->assertFalse(\local_rtcsync\local\delivery_activity_access::student_delivery_scope_allows(
            (int) $course->id,
            (int) $user->id,
            77
        ));
        \local_rtcsync\local\delivery_activity_access::with_course_access_lock(
            (int) $course->id,
            static function() use ($course, $user): void {
                \local_rtcsync\local\delivery_activity_access::reconcile_course_delivery_group_memberships_locked(
                    (int) $course->id,
                    (int) $user->id
                );
            }
        );
        $this->assertFalse($DB->record_exists('groups_members', [
            'groupid' => (int) $group->id,
            'userid' => (int) $user->id,
        ]));

        $history = new \local_rtcsync\local\student_history();
        $this->setUser($user);
        $resultsbeforeclear = $history->visible_results();
        $this->assertSame(['Retained delivery result'], array_column($resultsbeforeclear['results'], 'item'));
        $this->assertSame([74.0], array_column($resultsbeforeclear['results'], 'grade'));

        $this->setAdminUser();
        $clear = \local_rtcsync_external::sync_history_marker([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'historyaccess' => 0,
            'historysourcetype' => 'delivery_participation',
            'historysourceid' => 9207,
            'deliveryid' => 77,
            'expectedscoperevision' => 1,
        ]);
        $this->assertSame(0, $clear['applied']);
        $this->assertTrue($DB->record_exists('local_rtcsync_history', [
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'sourcetype' => 'delivery_participation',
            'sourceid' => 9207,
            'deliveryid' => 77,
        ]));
        $this->setUser($user);
        $resultsafterclear = $history->visible_results();
        $this->assertSame(['Retained delivery result'], array_column($resultsafterclear['results'], 'item'));
    }

    public function test_student_delivery_scope_is_complete_versioned_and_order_independent(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enableavailability', 1);
        $course = $this->createManagedCourse();
        $this->createDeliveryGrouping($course, 77);
        $this->createDeliveryGrouping($course, 78);
        $user = $this->createManagedUser();
        $this->assertTrue(\local_rtcsync_external::prepare_delivery_course((int) $course->id)['ready']);
        \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'student',
            'suspend' => 0,
        ]);

        $groupids = [];
        foreach ([77, 78] as $deliveryid) {
            $group = $this->getDataGenerator()->create_group([
                'courseid' => (int) $course->id,
                'idnumber' => 'rtc-delivery-class-group:' . $deliveryid . ':37',
            ]);
            $grouping = $DB->get_record('groupings', [
                'courseid' => (int) $course->id,
                'idnumber' => 'rtc-delivery-grouping:' . $deliveryid,
            ], '*', MUST_EXIST);
            groups_assign_grouping((int) $grouping->id, (int) $group->id);
            $DB->insert_record('local_rtcsync_deliverymember', (object) [
                'courseid' => (int) $course->id,
                'groupid' => (int) $group->id,
                'userid' => (int) $user->id,
                'timecreated' => time(),
            ]);
            $groupids[$deliveryid] = (int) $group->id;
        }

        $dayonefloor = 20261025000000000;
        $nextdayfloor = 20261026000000000;
        $access = [
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'deliveryids' => [78, 77, 77],
            'revision' => $dayonefloor,
        ];
        $first = \local_rtcsync_external::sync_student_delivery_scope($access);
        $this->assertSame([77, 78], $first['deliveryids']);
        $this->assertSame(1, $first['applied']);
        $this->assertSame('[77,78]', $DB->get_field('local_rtcsync_studentscope', 'deliveryids', [
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
        ]));
        foreach ([77, 78] as $deliveryid) {
            $this->assertTrue($DB->record_exists('groups_members', [
                'groupid' => $groupids[$deliveryid],
                'userid' => (int) $user->id,
            ]));
        }

        $replay = \local_rtcsync_external::sync_student_delivery_scope($access);
        $this->assertSame(0, $replay['applied']);
        $this->assertSame([77, 78], $replay['deliveryids']);
        foreach ([77, 78] as $deliveryid) {
            $this->assertTrue($DB->record_exists('groups_members', [
                'groupid' => $groupids[$deliveryid],
                'userid' => (int) $user->id,
            ]));
        }

        $stale = \local_rtcsync_external::sync_student_delivery_scope([
            ...$access,
            'deliveryids' => [77],
            'revision' => $dayonefloor - 1,
        ]);
        $this->assertSame(0, $stale['applied']);
        $this->assertSame($dayonefloor, $stale['revision']);
        $this->assertSame([77, 78], $stale['deliveryids']);
        foreach ([77, 78] as $deliveryid) {
            $this->assertTrue($DB->record_exists('groups_members', [
                'groupid' => $groupids[$deliveryid],
                'userid' => (int) $user->id,
            ]));
        }

        try {
            \local_rtcsync_external::sync_student_delivery_scope([
                ...$access,
                'deliveryids' => [77],
            ]);
            $this->fail('A scope revision cannot be reused for a different delivery set.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('revision', $exception->getMessage());
        }

        $replacement = \local_rtcsync_external::sync_student_delivery_scope([
            ...$access,
            'deliveryids' => [78],
            'revision' => $dayonefloor + 1,
        ]);
        $this->assertSame(1, $replacement['applied']);
        $this->assertSame([78], $replacement['deliveryids']);
        $this->assertFalse($DB->record_exists('groups_members', [
            'groupid' => $groupids[77],
            'userid' => (int) $user->id,
        ]));
        $this->assertTrue($DB->record_exists('groups_members', [
            'groupid' => $groupids[78],
            'userid' => (int) $user->id,
        ]));

        $boundaryreplay = \local_rtcsync_external::sync_student_delivery_scope([
            ...$access,
            'deliveryids' => [78],
            'revision' => $nextdayfloor,
        ]);
        $this->assertSame(1, $boundaryreplay['applied']);
        $this->assertSame([78], $boundaryreplay['deliveryids']);

        $cleared = \local_rtcsync_external::sync_student_delivery_scope([
            ...$access,
            'deliveryids' => [],
            'revision' => $nextdayfloor + 1,
        ]);
        $this->assertSame(1, $cleared['applied']);
        $this->assertSame([], $cleared['deliveryids']);
        $this->assertSame($nextdayfloor + 1, (int) $DB->get_field('local_rtcsync_studentscope', 'revision', [
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
        ]));
    }

    public function test_subject_role_enrol_and_unenrol_marker_updates_do_not_nest_the_course_access_lock(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enableavailability', 1);
        $course = $this->createManagedCourse();
        $this->createDeliveryGrouping($course, 801);
        $user = $this->createManagedUser();
        $this->assertTrue(\local_rtcsync_external::prepare_delivery_course((int) $course->id)['ready']);
        $revision = 20261031000000000;
        \local_rtcsync_external::sync_student_delivery_scope([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'deliveryids' => [801],
            'revision' => $revision,
        ]);
        \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'student',
            'suspend' => 1,
            'historyaccess' => 1,
            'historysourcetype' => 'delivery_participation',
            'historysourceid' => 9801,
            'deliveryid' => 801,
        ]);

        $clearedenrolment = \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'student',
            'suspend' => 0,
            'historyaccess' => 0,
            'historysourcetype' => 'delivery_participation',
            'historysourceid' => 9801,
            'deliveryid' => 801,
            'expectedscoperevision' => $revision,
        ]);

        $this->assertSame(1, $clearedenrolment['historyapplied']);
        $this->assertSame($revision, $clearedenrolment['scoperevision']);
        $this->assertSame(ENROL_USER_ACTIVE, (int) $DB->get_field('user_enrolments', 'status', [
            'userid' => (int) $user->id,
        ]));
        $this->assertFalse($DB->record_exists('local_rtcsync_history', [
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'sourcetype' => 'delivery_participation',
            'sourceid' => 9801,
        ]));

        // Teacher unassigns share the same manual enrolment row. Removing a
        // teacher role must not remove the Student's active course enrolment.
        \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'editingteacher',
            'suspend' => 0,
        ]);
        \local_rtcsync_external::unenrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'editingteacher',
        ]);
        $this->assertTrue($DB->record_exists('user_enrolments', [
            'userid' => (int) $user->id,
            'enrolid' => (int) $DB->get_field('enrol', 'id', [
                'courseid' => (int) $course->id,
                'enrol' => 'manual',
            ], MUST_EXIST),
        ]));

        \local_rtcsync\local\student_history::sync_marker(
            (int) $user->id,
            (int) $course->id,
            1,
            'delivery_participation',
            9802,
            801
        );
        $clearedunenrolment = \local_rtcsync_external::unenrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'student',
            'historysourcetype' => 'delivery_participation',
            'historysourceid' => 9802,
            'deliveryid' => 801,
            'expectedscoperevision' => $revision,
        ]);
        $this->assertSame(1, $clearedunenrolment['historyapplied']);
        $this->assertSame($revision, $clearedunenrolment['scoperevision']);
        $this->assertFalse($DB->record_exists('user_enrolments', [
            'userid' => (int) $user->id,
            'enrolid' => (int) $DB->get_field('enrol', 'id', [
                'courseid' => (int) $course->id,
                'enrol' => 'manual',
            ], MUST_EXIST),
        ]));
        $this->assertFalse($DB->record_exists('local_rtcsync_history', [
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'sourcetype' => 'delivery_participation',
            'sourceid' => 9802,
        ]));
    }

    public function test_enrolment_clear_wrappers_report_rejected_scope_revisions_and_preserve_markers(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enableavailability', 1);
        $course = $this->createManagedCourse();
        $this->createDeliveryGrouping($course, 802);
        $user = $this->createManagedUser();
        $this->assertTrue(\local_rtcsync_external::prepare_delivery_course((int) $course->id)['ready']);
        \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'student',
            'suspend' => 1,
        ]);

        $revision = 20261031000000000;
        \local_rtcsync_external::sync_student_delivery_scope([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'deliveryids' => [802],
            'revision' => $revision,
        ]);
        foreach ([9811, 9812] as $sourceid) {
            \local_rtcsync_external::sync_history_marker([
                'courseid' => (int) $course->id,
                'userid' => (int) $user->id,
                'historyaccess' => 1,
                'historysourcetype' => 'delivery_participation',
                'historysourceid' => $sourceid,
                'deliveryid' => 802,
            ]);
        }

        $staleenrolment = \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'student',
            'suspend' => 0,
            'historysourcetype' => 'delivery_participation',
            'historysourceid' => 9811,
            'deliveryid' => 802,
            'expectedscoperevision' => $revision - 1,
        ]);
        $this->assertSame(0, $staleenrolment['historyapplied']);
        $this->assertSame($revision, $staleenrolment['scoperevision']);
        $this->assertTrue($DB->record_exists('local_rtcsync_history', [
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'sourcetype' => 'delivery_participation',
            'sourceid' => 9811,
            'deliveryid' => 802,
        ]));

        $omittedunenrolment = \local_rtcsync_external::unenrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'student',
            'historysourcetype' => 'delivery_participation',
            'historysourceid' => 9812,
            'deliveryid' => 802,
        ]);
        $this->assertSame(1, $omittedunenrolment['removed']);
        $this->assertSame(0, $omittedunenrolment['historyapplied']);
        $this->assertSame($revision, $omittedunenrolment['scoperevision']);
        $this->assertTrue($DB->record_exists('local_rtcsync_history', [
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'sourcetype' => 'delivery_participation',
            'sourceid' => 9812,
            'deliveryid' => 802,
        ]));
    }

    public function test_teacher_unenrolment_waits_for_the_subject_course_access_lock(): void
    {
        global $CFG, $DB;

        $this->resetAfterTest();
        // MySQL GET_LOCK stacks within one DB session, so a competing in-process lock
        // only blocks with the file factory.
        $CFG->lock_factory = '\core\lock\file_lock_factory';
        $this->setAdminUser();
        set_config('enableavailability', 1);
        $course = $this->createManagedCourse(['idnumber' => 'rtc-subject:cross-role-unenrol']);
        $user = $this->createManagedUser();
        foreach (['student', 'editingteacher'] as $role) {
            \local_rtcsync_external::enrol_user([
                'courseid' => (int) $course->id,
                'userid' => (int) $user->id,
                'role_shortname' => $role,
                'suspend' => 0,
            ]);
        }

        $lockfactory = \core\lock\lock_config::get_lock_factory('local_rtcsync_delivery_access');
        $lock = $lockfactory->get_lock('course:' . (int) $course->id, 0);
        $this->assertNotFalse($lock);
        $timedout = false;
        try {
            \local_rtcsync_external::unenrol_user([
                'courseid' => (int) $course->id,
                'userid' => (int) $user->id,
                'role_shortname' => 'editingteacher',
            ]);
        } catch (\moodle_exception $exception) {
            $timedout = $exception->errorcode === 'locktimeout';
        } finally {
            $lock->release();
        }

        $this->assertTrue($timedout, 'Teacher unassign must wait for the same course lock as active Student writes.');
        $context = \context_course::instance((int) $course->id);
        $teacherrole = $DB->get_record('role', ['shortname' => 'editingteacher'], '*', MUST_EXIST);
        $this->assertTrue($DB->record_exists('role_assignments', [
            'contextid' => (int) $context->id,
            'roleid' => (int) $teacherrole->id,
            'userid' => (int) $user->id,
        ]));
        $this->assertTrue($DB->record_exists('user_enrolments', [
            'userid' => (int) $user->id,
            'status' => ENROL_USER_ACTIVE,
        ]));
    }

    public function test_delivery_score_items_are_idempotent_and_scoped_to_subject_and_delivery(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse(['idnumber' => 'rtc-subject:314']);
        $this->createDeliveryGrouping($course, 710);
        $this->createDeliveryGrouping($course, 711);
        $user = $this->createManagedUser();

        $first = \local_rtcsync_external::upsert_grade([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'itemname' => 'Delivery score',
            'idnumber' => 'rtc-subject-score:314:delivery:710',
            'deliveryid' => 710,
            'grade' => 75.0,
            'hidden' => 0,
        ]);
        $updated = \local_rtcsync_external::upsert_grade([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'itemname' => 'Delivery score',
            'idnumber' => 'rtc-subject-score:314:delivery:710',
            'deliveryid' => 710,
            'grade' => 81.0,
            'hidden' => 1,
        ]);
        $second = \local_rtcsync_external::upsert_grade([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'itemname' => 'Delivery score',
            'idnumber' => 'rtc-subject-score:314:delivery:711',
            'deliveryid' => 711,
            'grade' => 83.0,
            'hidden' => 0,
        ]);
        $cleared = \local_rtcsync_external::upsert_grade([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'itemname' => 'Delivery score',
            'idnumber' => 'rtc-subject-score:314:delivery:710',
            'deliveryid' => 710,
            'grade' => null,
            'clear' => 1,
            'hidden' => 1,
        ]);

        $firstitem = \grade_item::fetch([
            'courseid' => (int) $course->id,
            'idnumber' => 'rtc-subject-score:314:delivery:710',
        ]);
        $seconditem = \grade_item::fetch([
            'courseid' => (int) $course->id,
            'idnumber' => 'rtc-subject-score:314:delivery:711',
        ]);
        $this->assertNotFalse($firstitem);
        $this->assertNotFalse($seconditem);
        $this->assertSame(1, (int) $firstitem->hidden, 'A valid hidden=1 update stays hidden through a clear.');
        $this->assertSame(0, (int) $seconditem->hidden, 'A valid hidden=0 update stays visible.');
        $this->assertSame([
            'itemid' => (int) $firstitem->id,
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'grade' => 75.0,
            'cleared' => 0,
            'deliveryid' => 710,
        ], $first);
        $this->assertSame([
            'itemid' => (int) $firstitem->id,
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'grade' => 81.0,
            'cleared' => 0,
            'deliveryid' => 710,
        ], $updated);
        $this->assertSame([
            'itemid' => (int) $firstitem->id,
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'grade' => null,
            'cleared' => 1,
            'deliveryid' => 710,
        ], $cleared);
        $this->assertSame([
            'itemid' => (int) $seconditem->id,
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'grade' => 83.0,
            'cleared' => 0,
            'deliveryid' => 711,
        ], $second);
        $this->assertNull($DB->get_field('grade_grades', 'finalgrade', [
            'itemid' => (int) $firstitem->id,
            'userid' => (int) $user->id,
        ]));
        $this->assertNotSame($first['itemid'], $second['itemid']);
        $this->assertSame(1, $DB->count_records('local_rtcsync_deliverygrade', [
            'gradeitemid' => (int) $first['itemid'],
            'courseid' => (int) $course->id,
            'deliveryid' => 710,
        ]));
        $this->assertSame(1, $DB->count_records('local_rtcsync_deliverygrade', [
            'gradeitemid' => (int) $second['itemid'],
            'courseid' => (int) $course->id,
            'deliveryid' => 711,
        ]));
        $this->assertSame(1, (int) $DB->get_field('local_rtcsync_deliverygrade', 'lineageverified', [
            'gradeitemid' => (int) $first['itemid'],
        ]));
        $this->assertSame(1, (int) $DB->get_field('local_rtcsync_deliverygrade', 'lineageverified', [
            'gradeitemid' => (int) $second['itemid'],
        ]));
    }

    public function test_delivery_grade_rejects_invalid_hidden_flags_without_mutating_item_grade_or_mapping(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse(['idnumber' => 'rtc-subject:316']);
        $this->createDeliveryGrouping($course, 723);
        $user = $this->createManagedUser();
        $payload = [
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'itemname' => 'Reviewed delivery score',
            'idnumber' => 'rtc-subject-score:316:delivery:723',
            'deliveryid' => 723,
        ];
        $created = \local_rtcsync_external::upsert_grade(array_merge($payload, [
            'grade' => 74.0,
            'grademax' => 100.0,
            'hidden' => 0,
        ]));
        $itembefore = $DB->get_record('grade_items', ['id' => (int) $created['itemid']], '*', MUST_EXIST);
        $gradebefore = $DB->get_record('grade_grades', [
            'itemid' => (int) $itembefore->id,
            'userid' => (int) $user->id,
        ], '*', MUST_EXIST);
        $mappingbefore = $DB->get_record('local_rtcsync_deliverygrade', [
            'gradeitemid' => (int) $itembefore->id,
        ], '*', MUST_EXIST);

        foreach ([-1, 2] as $hidden) {
            try {
                \local_rtcsync_external::upsert_grade(array_merge($payload, [
                    'itemname' => 'Must not rename existing score',
                    'grade' => 88.0,
                    'grademax' => 200.0,
                    'hidden' => $hidden,
                ]));
                $this->fail('Hidden flag ' . $hidden . ' must be rejected before grade mutation.');
            } catch (\invalid_parameter_exception $exception) {
                $this->assertStringContainsString('Hidden flag must be 0 or 1.', $exception->getMessage());
            }

            $this->assertEquals($itembefore, $DB->get_record('grade_items', [
                'id' => (int) $itembefore->id,
            ], '*', MUST_EXIST));
            $this->assertEquals($gradebefore, $DB->get_record('grade_grades', [
                'itemid' => (int) $itembefore->id,
                'userid' => (int) $user->id,
            ], '*', MUST_EXIST));
            $this->assertEquals($mappingbefore, $DB->get_record('local_rtcsync_deliverygrade', [
                'gradeitemid' => (int) $itembefore->id,
            ], '*', MUST_EXIST));
            $this->assertSame(1, $DB->count_records('grade_items', [
                'courseid' => (int) $course->id,
                'idnumber' => 'rtc-subject-score:316:delivery:723',
            ]));
            $this->assertSame(1, $DB->count_records('local_rtcsync_deliverygrade', [
                'gradeitemid' => (int) $itembefore->id,
                'courseid' => (int) $course->id,
                'deliveryid' => 723,
            ]));
        }
    }

    public function test_grade_upsert_rejects_conflicting_clear_payloads_without_mutation(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse(['idnumber' => 'rtc-subject:321']);
        $this->createDeliveryGrouping($course, 720);
        $user = $this->createManagedUser();
        $base = [
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'itemname' => 'Explicit delivery clear contract',
            'idnumber' => 'rtc-subject-score:321:delivery:720',
            'deliveryid' => 720,
        ];
        $created = \local_rtcsync_external::upsert_grade($base + [
            'grade' => 74.0,
            'clear' => 0,
        ]);
        $item = \grade_item::fetch(['id' => (int) $created['itemid']]);
        $this->assertNotFalse($item);
        $mappingbefore = $DB->get_record('local_rtcsync_deliverygrade', [
            'gradeitemid' => (int) $item->id,
        ], '*', MUST_EXIST);

        foreach ([
            [
                'payload' => ['grade' => null, 'clear' => 0],
                'message' => 'grade update requires a non-null grade',
            ],
            [
                'payload' => ['grade' => 61.0, 'clear' => 1],
                'message' => 'clear request requires a null grade',
            ],
            [
                'payload' => ['grade' => 62.0, 'clear' => 2],
                'message' => 'Clear flag must be 0 or 1',
            ],
        ] as $invalidrequest) {
            try {
                \local_rtcsync_external::upsert_grade($base + $invalidrequest['payload']);
                $this->fail('A conflicting grade/clear request must be rejected before updating the grade.');
            } catch (\invalid_parameter_exception $exception) {
                $this->assertStringContainsString($invalidrequest['message'], $exception->getMessage());
            }
            $this->assertSame(74.0, (float) $DB->get_field('grade_grades', 'finalgrade', [
                'itemid' => (int) $item->id,
                'userid' => (int) $user->id,
            ]));
        }

        $this->assertEquals($mappingbefore, $DB->get_record('local_rtcsync_deliverygrade', [
            'gradeitemid' => (int) $item->id,
        ], '*', MUST_EXIST));
    }

    public function test_grade_upsert_rejects_values_moodle_would_clamp_without_mutation(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse(['idnumber' => 'rtc-subject:322']);
        $this->createDeliveryGrouping($course, 721);
        $user = $this->createManagedUser();
        $payload = [
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'itemname' => 'Grade range contract',
            'idnumber' => 'rtc-subject-score:322:delivery:721',
            'deliveryid' => 721,
            'grademax' => 100.0,
            'grade' => 74.0,
        ];
        $created = \local_rtcsync_external::upsert_grade($payload);
        $itembefore = $DB->get_record('grade_items', ['id' => (int) $created['itemid']], '*', MUST_EXIST);
        $gradebefore = $DB->get_record('grade_grades', [
            'itemid' => (int) $created['itemid'],
            'userid' => (int) $user->id,
        ], '*', MUST_EXIST);
        $mappingbefore = $DB->get_record('local_rtcsync_deliverygrade', [
            'gradeitemid' => (int) $created['itemid'],
        ], '*', MUST_EXIST);

        foreach ([-1.0, 101.0] as $invalidgrade) {
            try {
                \local_rtcsync_external::upsert_grade(array_merge($payload, [
                    'itemname' => 'Must not rename existing score',
                    'grade' => $invalidgrade,
                    'hidden' => 1,
                ]));
                $this->fail('A grade outside Moodle’s item range must be rejected, not silently clamped.');
            } catch (\invalid_parameter_exception $exception) {
                $this->assertStringContainsString(
                    'Grade value falls outside the Moodle grade item’s allowed range.',
                    $exception->getMessage()
                );
            }

            $this->assertEquals($itembefore, $DB->get_record('grade_items', [
                'id' => (int) $created['itemid'],
            ], '*', MUST_EXIST));
            $this->assertEquals($gradebefore, $DB->get_record('grade_grades', [
                'itemid' => (int) $created['itemid'],
                'userid' => (int) $user->id,
            ], '*', MUST_EXIST));
            $this->assertEquals($mappingbefore, $DB->get_record('local_rtcsync_deliverygrade', [
                'gradeitemid' => (int) $created['itemid'],
            ], '*', MUST_EXIST));
            $this->assertSame(1, $DB->count_records('grade_items', [
                'courseid' => (int) $course->id,
                'idnumber' => 'rtc-subject-score:322:delivery:721',
            ]));
        }

        foreach ([0.0, -1.0] as $invalidmaximum) {
            try {
                \local_rtcsync_external::upsert_grade(array_merge($payload, [
                    'itemname' => 'Must not rename existing score',
                    'grademax' => $invalidmaximum,
                    'grade' => 88.0,
                    'hidden' => 1,
                ]));
                $this->fail('A non-positive grade maximum must be rejected before mutation.');
            } catch (\invalid_parameter_exception $exception) {
                $this->assertStringContainsString('Grade maximum must be greater than zero.', $exception->getMessage());
            }

            $this->assertEquals($itembefore, $DB->get_record('grade_items', [
                'id' => (int) $created['itemid'],
            ], '*', MUST_EXIST));
            $this->assertEquals($gradebefore, $DB->get_record('grade_grades', [
                'itemid' => (int) $created['itemid'],
                'userid' => (int) $user->id,
            ], '*', MUST_EXIST));
            $this->assertEquals($mappingbefore, $DB->get_record('local_rtcsync_deliverygrade', [
                'gradeitemid' => (int) $created['itemid'],
            ], '*', MUST_EXIST));
            $this->assertSame(1, $DB->count_records('grade_items', [
                'courseid' => (int) $course->id,
                'idnumber' => 'rtc-subject-score:322:delivery:721',
            ]));
        }
    }

    public function test_delivery_score_clear_fails_when_grade_item_is_locked(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse(['idnumber' => 'rtc-subject:315']);
        $this->createDeliveryGrouping($course, 712);
        $user = $this->createManagedUser();
        $created = \local_rtcsync_external::upsert_grade([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'itemname' => 'Locked delivery score',
            'idnumber' => 'rtc-subject-score:315:delivery:712',
            'deliveryid' => 712,
            'grade' => 64.0,
        ]);

        $mappingbefore = $DB->get_record('local_rtcsync_deliverygrade', [
            'gradeitemid' => (int) $created['itemid'],
            'courseid' => (int) $course->id,
            'deliveryid' => 712,
        ], '*', MUST_EXIST);
        $mappingbefore->timemodified = time() - 60;
        $DB->update_record('local_rtcsync_deliverygrade', $mappingbefore);
        $mappingbefore = $DB->get_record('local_rtcsync_deliverygrade', [
            'gradeitemid' => (int) $created['itemid'],
        ], '*', MUST_EXIST);

        $DB->set_field('grade_items', 'locked', 1, ['id' => (int) $created['itemid']]);

        try {
            \local_rtcsync_external::upsert_grade([
                'courseid' => (int) $course->id,
                'userid' => (int) $user->id,
                'itemname' => 'Attempted locked item rewrite',
                'idnumber' => 'rtc-subject-score:315:delivery:712',
                'deliveryid' => 712,
                'grade' => null,
                'grademax' => 200,
                'hidden' => 1,
                'clear' => 1,
            ]);
            $this->fail('A locked Moodle grade must not be reported as successfully cleared.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('gradeupdatefailed', $exception->errorcode);
        }

        $this->assertSame(64.0, (float) $DB->get_field('grade_grades', 'finalgrade', [
            'itemid' => (int) $created['itemid'],
            'userid' => (int) $user->id,
        ]));
        $unchangeditem = \grade_item::fetch(['id' => (int) $created['itemid']]);
        $this->assertNotFalse($unchangeditem);
        $this->assertSame('Locked delivery score', (string) $unchangeditem->itemname);
        $this->assertSame(100.0, (float) $unchangeditem->grademax);
        $this->assertSame(0, (int) $unchangeditem->hidden);
        $this->assertSame(1, $DB->count_records('local_rtcsync_deliverygrade', [
            'gradeitemid' => (int) $created['itemid'],
            'courseid' => (int) $course->id,
            'deliveryid' => 712,
        ]));
        $this->assertSame(1, (int) $DB->get_field('local_rtcsync_deliverygrade', 'lineageverified', [
            'gradeitemid' => (int) $created['itemid'],
        ]));
        $mappingafter = $DB->get_record('local_rtcsync_deliverygrade', [
            'gradeitemid' => (int) $created['itemid'],
        ], '*', MUST_EXIST);
        $this->assertEquals($mappingbefore, $mappingafter, 'A failed grade write must leave all mapping fields unchanged.');
    }

    public function test_delivery_score_failure_does_not_create_delivery_grade_mapping(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse(['idnumber' => 'rtc-subject:319']);
        $this->createDeliveryGrouping($course, 717);
        $user = $this->createManagedUser();
        $idnumber = 'rtc-subject-score:319:delivery:717';
        $item = new \grade_item([
            'courseid' => (int) $course->id,
            'itemname' => 'Locked unmapped delivery score',
            'itemtype' => 'manual',
            'itemmodule' => null,
            'iteminstance' => null,
            'itemnumber' => 0,
            'idnumber' => $idnumber,
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
        ], false);
        $item->insert('grade-failure-test');
        $DB->set_field('grade_items', 'locked', 1, ['id' => (int) $item->id]);

        try {
            \local_rtcsync_external::upsert_grade([
                'courseid' => (int) $course->id,
                'userid' => (int) $user->id,
                'itemname' => 'Attempted locked item rewrite',
                'idnumber' => $idnumber,
                'deliveryid' => 717,
                'grade' => 64.0,
                'grademax' => 150,
                'hidden' => 1,
            ]);
            $this->fail('A locked grade item must not be reported as a successful sync.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('gradeupdatefailed', $exception->errorcode);
        }

        $this->assertFalse($DB->record_exists('local_rtcsync_deliverygrade', [
            'gradeitemid' => (int) $item->id,
        ]));
        $this->assertFalse($DB->record_exists('grade_grades', [
            'itemid' => (int) $item->id,
            'userid' => (int) $user->id,
        ]));
        $unchangeditem = \grade_item::fetch(['id' => (int) $item->id]);
        $this->assertNotFalse($unchangeditem);
        $this->assertSame('Locked unmapped delivery score', (string) $unchangeditem->itemname);
        $this->assertSame(100.0, (float) $unchangeditem->grademax);
        $this->assertSame(0, (int) $unchangeditem->hidden);
    }

    public function test_delivery_score_replays_same_payload_after_transient_moodle_grade_lock_failure(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse(['idnumber' => 'rtc-subject:320']);
        $this->createDeliveryGrouping($course, 718);
        $user = $this->createManagedUser();
        $idnumber = 'rtc-subject-score:320:delivery:718';
        $item = new \grade_item([
            'courseid' => (int) $course->id,
            'itemname' => 'Temporarily locked delivery score',
            'itemtype' => 'manual',
            'itemmodule' => null,
            'iteminstance' => null,
            'itemnumber' => 0,
            'idnumber' => $idnumber,
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
        ], false);
        $item->insert('grade-replay-test');
        $DB->set_field('grade_items', 'locked', 1, ['id' => (int) $item->id]);

        $payload = [
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'itemname' => 'Delivery score replay',
            'idnumber' => $idnumber,
            'deliveryid' => 718,
            'grade' => 64.0,
            'grademax' => 100.0,
        ];

        try {
            \local_rtcsync_external::upsert_grade($payload);
            $this->fail('A temporarily locked Moodle grade item must fail before the sync is committed.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('gradeupdatefailed', $exception->errorcode);
        }

        $this->assertSame('Temporarily locked delivery score', (string) $DB->get_field(
            'grade_items',
            'itemname',
            ['id' => (int) $item->id]
        ));
        $this->assertFalse($DB->record_exists('grade_grades', [
            'itemid' => (int) $item->id,
            'userid' => (int) $user->id,
        ]));
        $this->assertFalse($DB->record_exists('local_rtcsync_deliverygrade', [
            'gradeitemid' => (int) $item->id,
        ]));

        // Moodle has released the temporary lock; replay the identical request.
        $DB->set_field('grade_items', 'locked', 0, ['id' => (int) $item->id]);
        $firstreplay = \local_rtcsync_external::upsert_grade($payload);
        $duplicateReplay = \local_rtcsync_external::upsert_grade($payload);

        $this->assertSame((int) $item->id, (int) $firstreplay['itemid']);
        $this->assertSame($firstreplay['itemid'], $duplicateReplay['itemid']);
        $this->assertSame(64.0, (float) $DB->get_field('grade_grades', 'finalgrade', [
            'itemid' => (int) $item->id,
            'userid' => (int) $user->id,
        ]));
        $this->assertSame(1, $DB->count_records('grade_items', [
            'courseid' => (int) $course->id,
            'idnumber' => $idnumber,
        ]));
        $this->assertSame(1, $DB->count_records('grade_grades', [
            'itemid' => (int) $item->id,
            'userid' => (int) $user->id,
        ]));
        $this->assertSame(1, $DB->count_records('local_rtcsync_deliverygrade', [
            'gradeitemid' => (int) $item->id,
            'courseid' => (int) $course->id,
            'deliveryid' => 718,
        ]));
    }

    public function test_sync_does_not_verify_a_legacy_delivery_grade_mapping(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse(['idnumber' => 'rtc-subject:316']);
        $this->createDeliveryGrouping($course, 714);
        $user = $this->createManagedUser();
        $idnumber = 'rtc-subject-score:316:delivery:714';
        $item = new \grade_item([
            'courseid' => (int) $course->id,
            'itemname' => 'Legacy delivery score',
            'itemtype' => 'manual',
            'itemmodule' => null,
            'iteminstance' => null,
            'itemnumber' => 0,
            'idnumber' => $idnumber,
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
        ], false);
        $item->insert('lineage-test');
        $item->update_final_grade((int) $user->id, 54.0, 'lineage-test');
        $DB->insert_record('local_rtcsync_deliverygrade', (object) [
            'gradeitemid' => (int) $item->id,
            'courseid' => (int) $course->id,
            'deliveryid' => 714,
            'timecreated' => time(),
            'timemodified' => time(),
            'lineageverified' => 0,
            'lineagemanifestid' => null,
        ]);

        \local_rtcsync_external::upsert_grade([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'itemname' => 'Legacy delivery score',
            'idnumber' => $idnumber,
            'deliveryid' => 714,
            'grade' => 88.0,
        ]);

        $mapping = $DB->get_record('local_rtcsync_deliverygrade', ['gradeitemid' => (int) $item->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $mapping->lineageverified);
        $this->assertNull($mapping->lineagemanifestid);
    }

    public function test_grade_item_disposition_added_after_preflight_blocks_sync_write(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse(['idnumber' => 'rtc-subject:325']);
        $this->createDeliveryGrouping($course, 725);
        $user = $this->createManagedUser();
        $idnumber = 'rtc-subject-score:325:delivery:725';

        $realdb = $DB;
        $faultdb = new class($realdb, (int) $course->id, (int) $user->id, $idnumber) {
            /** @var moodle_database */
            private $database;

            /** @var int */
            private $courseid;

            /** @var int */
            private $userid;

            /** @var string */
            private $idnumber;

            /** @var bool */
            private $injectiontriggered = false;

            /** @var int */
            public $injectedgradeitemid = 0;

            public function __construct($database, int $courseid, int $userid, string $idnumber) {
                $this->database = $database;
                $this->courseid = $courseid;
                $this->userid = $userid;
                $this->idnumber = $idnumber;
            }

            public function __call(string $name, array $arguments) {
                $result = $this->database->{$name}(...$arguments);
                if ($name === 'get_recordset_select'
                        && ($arguments[0] ?? null) === 'grade_items'
                        && !$this->injectiontriggered
                        && in_array($this->idnumber, $arguments[2] ?? [], true)
                        && !$result->valid()) {
                    // Simulate a reviewed quarantine appearing after the API's
                    // first lookup but before its final write-time lookup.
                    $this->injectiontriggered = true;
                    $item = new \grade_item([
                        'courseid' => $this->courseid,
                        'itemname' => 'Concurrent quarantined delivery score',
                        'itemtype' => 'manual',
                        'itemmodule' => null,
                        'iteminstance' => null,
                        'itemnumber' => 0,
                        'idnumber' => $this->idnumber,
                        'gradetype' => GRADE_TYPE_VALUE,
                        'grademax' => 100,
                        'grademin' => 0,
                    ], false);
                    $item->insert('concurrent-admin-test');
                    $item->update_final_grade($this->userid, 54.0, 'concurrent-admin-test');
                    $this->injectedgradeitemid = (int) $item->id;
                    $manifestid = 'concurrent-quarantined-delivery-score';
                    $this->database->insert_record('local_rtcsync_lineageaudit', (object) [
                        'manifestid' => $manifestid,
                        'manifesthash' => hash('sha256', $manifestid),
                        'courseid' => $this->courseid,
                        'recordtype' => 'gradeitem',
                        'targetid' => $this->injectedgradeitemid,
                        'decision' => 'quarantine',
                        'deliveryids' => '[]',
                        'sourceidentity' => '{}',
                        'targetidentity' => '{}',
                        'reason' => 'Keep the concurrent legacy grade quarantined.',
                        'entryhash' => hash('sha256', $manifestid . ':quarantine:' . $item->id),
                        'resultstate' => '{}',
                        'parententryhash' => null,
                        'parentresultstate' => null,
                        'timecreated' => time(),
                    ]);
                }

                return $result;
            }

            public function __get(string $name) {
                return $this->database->{$name};
            }

            public function __isset(string $name): bool {
                return isset($this->database->{$name});
            }
        };

        $DB = $faultdb;
        $failure = null;
        try {
            \local_rtcsync_external::upsert_grade([
                'courseid' => (int) $course->id,
                'userid' => (int) $user->id,
                'itemname' => 'Attempted quarantined score rewrite',
                'idnumber' => $idnumber,
                'deliveryid' => 725,
                'grade' => 88.0,
                'grademax' => 200,
                'hidden' => 1,
            ]);
        } catch (\invalid_parameter_exception $exception) {
            $failure = $exception;
        } finally {
            $DB = $realdb;
        }

        $this->assertInstanceOf(\invalid_parameter_exception::class, $failure);
        $this->assertStringContainsString('matching audited map', $failure->getMessage());
        $this->assertGreaterThan(0, $faultdb->injectedgradeitemid);
        $this->assertFalse($DB->record_exists('grade_items', ['id' => $faultdb->injectedgradeitemid]));
        $this->assertFalse($DB->record_exists('local_rtcsync_deliverygrade', [
            'gradeitemid' => $faultdb->injectedgradeitemid,
        ]));
    }

    public function test_delivery_score_does_not_mutate_a_reviewed_dispositioned_grade_item(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse(['idnumber' => 'rtc-subject:321']);
        $deliveryid = 720;
        $this->createDeliveryGrouping($course, $deliveryid);
        $user = $this->createManagedUser();
        $idnumber = 'rtc-subject-score:321:delivery:' . $deliveryid;
        $item = new \grade_item([
            'courseid' => (int) $course->id,
            'itemname' => 'Archived delivery score',
            'itemtype' => 'manual',
            'itemmodule' => null,
            'iteminstance' => null,
            'itemnumber' => 0,
            'idnumber' => $idnumber,
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
        ], false);
        $item->insert('lineage-disposition-test');
        $item->update_final_grade((int) $user->id, 54.0, 'lineage-disposition-test');
        $manifestid = 'archived-delivery-score';
        $DB->insert_record('local_rtcsync_deliverygrade', (object) [
            'gradeitemid' => (int) $item->id,
            'courseid' => (int) $course->id,
            'deliveryid' => $deliveryid,
            'timecreated' => time(),
            'timemodified' => time(),
            'lineageverified' => 0,
            'lineagemanifestid' => $manifestid,
        ]);
        $DB->insert_record('local_rtcsync_lineageaudit', (object) [
            'manifestid' => $manifestid,
            'manifesthash' => hash('sha256', $manifestid),
            'courseid' => (int) $course->id,
            'recordtype' => 'gradeitem',
            'targetid' => (int) $item->id,
            'decision' => 'archive',
            'deliveryids' => '[]',
            'sourceidentity' => '{}',
            'targetidentity' => '{}',
            'reason' => 'The legacy score is retained without a delivery assignment.',
            'entryhash' => hash('sha256', $manifestid . ':archive:' . $item->id),
            'resultstate' => '{}',
            'parententryhash' => null,
            'parentresultstate' => null,
            'timecreated' => time(),
        ]);
        $mappingbefore = $DB->get_record('local_rtcsync_deliverygrade', [
            'gradeitemid' => (int) $item->id,
        ], '*', MUST_EXIST);

        try {
            \local_rtcsync_external::upsert_grade([
                'courseid' => (int) $course->id,
                'userid' => (int) $user->id,
                'itemname' => 'Attempted archived score rewrite',
                'idnumber' => $idnumber,
                'deliveryid' => $deliveryid,
                'grade' => 88.0,
                'grademax' => 200,
                'hidden' => 1,
            ]);
            $this->fail('An archived grade item must not accept a delivery score write.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('matching audited map', $exception->getMessage());
        }

        $unchangeditem = \grade_item::fetch(['id' => (int) $item->id]);
        $this->assertNotFalse($unchangeditem);
        $this->assertSame('Archived delivery score', (string) $unchangeditem->itemname);
        $this->assertSame(100.0, (float) $unchangeditem->grademax);
        $this->assertSame(0, (int) $unchangeditem->hidden);
        $this->assertSame(54.0, (float) $DB->get_field('grade_grades', 'finalgrade', [
            'itemid' => (int) $item->id,
            'userid' => (int) $user->id,
        ]));
        $this->assertEquals($mappingbefore, $DB->get_record('local_rtcsync_deliverygrade', [
            'gradeitemid' => (int) $item->id,
        ], '*', MUST_EXIST));
    }

    public function test_delivery_score_does_not_recreate_mapping_for_a_dispositioned_unmapped_item(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse(['idnumber' => 'rtc-subject:322']);
        $deliveryid = 721;
        $this->createDeliveryGrouping($course, $deliveryid);
        $user = $this->createManagedUser();
        $idnumber = 'rtc-subject-score:322:delivery:' . $deliveryid;
        $item = new \grade_item([
            'courseid' => (int) $course->id,
            'itemname' => 'Quarantined delivery score',
            'itemtype' => 'manual',
            'itemmodule' => null,
            'iteminstance' => null,
            'itemnumber' => 0,
            'idnumber' => $idnumber,
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
        ], false);
        $item->insert('lineage-disposition-test');
        $item->update_final_grade((int) $user->id, 61.0, 'lineage-disposition-test');
        $manifestid = 'quarantined-unmapped-delivery-score';
        $DB->insert_record('local_rtcsync_lineageaudit', (object) [
            'manifestid' => $manifestid,
            'manifesthash' => hash('sha256', $manifestid),
            'courseid' => (int) $course->id,
            'recordtype' => 'gradeitem',
            'targetid' => (int) $item->id,
            'decision' => 'quarantine',
            'deliveryids' => '[]',
            'sourceidentity' => '{}',
            'targetidentity' => '{}',
            'reason' => 'The legacy score is retained without a delivery assignment.',
            'entryhash' => hash('sha256', $manifestid . ':quarantine:' . $item->id),
            'resultstate' => '{}',
            'parententryhash' => null,
            'parentresultstate' => null,
            'timecreated' => time(),
        ]);

        try {
            \local_rtcsync_external::upsert_grade([
                'courseid' => (int) $course->id,
                'userid' => (int) $user->id,
                'itemname' => 'Attempted quarantined score rewrite',
                'idnumber' => $idnumber,
                'deliveryid' => $deliveryid,
                'grade' => 92.0,
                'grademax' => 200,
                'hidden' => 1,
            ]);
            $this->fail('A quarantined unmapped grade item must not accept a delivery score write.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('matching audited map', $exception->getMessage());
        }

        $unchangeditem = \grade_item::fetch(['id' => (int) $item->id]);
        $this->assertNotFalse($unchangeditem);
        $this->assertSame('Quarantined delivery score', (string) $unchangeditem->itemname);
        $this->assertSame(100.0, (float) $unchangeditem->grademax);
        $this->assertSame(0, (int) $unchangeditem->hidden);
        $this->assertSame(61.0, (float) $DB->get_field('grade_grades', 'finalgrade', [
            'itemid' => (int) $item->id,
            'userid' => (int) $user->id,
        ]));
        $this->assertFalse($DB->record_exists('local_rtcsync_deliverygrade', [
            'gradeitemid' => (int) $item->id,
        ]));
    }

    public function test_legacy_score_request_cannot_rewrite_a_delivery_mapped_grade_item(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse(['idnumber' => 'rtc-subject:317']);
        $this->createDeliveryGrouping($course, 715);
        $user = $this->createManagedUser();
        $item = new \grade_item([
            'courseid' => (int) $course->id,
            'itemname' => 'Reviewed historical score',
            'itemtype' => 'manual',
            'itemmodule' => null,
            'iteminstance' => null,
            'itemnumber' => 0,
            'idnumber' => 'reviewed-historical-score',
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
        ], false);
        $item->insert('lineage-test');
        $item->update_final_grade((int) $user->id, 54.0, 'lineage-test');
        $DB->insert_record('local_rtcsync_deliverygrade', (object) [
            'gradeitemid' => (int) $item->id,
            'courseid' => (int) $course->id,
            'deliveryid' => 715,
            'timecreated' => time(),
            'timemodified' => time(),
            'lineageverified' => 1,
            'lineagemanifestid' => 'reviewed-grade-manifest',
        ]);
        $mappingbefore = $DB->get_record('local_rtcsync_deliverygrade', [
            'gradeitemid' => (int) $item->id,
        ], '*', MUST_EXIST);

        try {
            \local_rtcsync_external::upsert_grade([
                'courseid' => (int) $course->id,
                'userid' => (int) $user->id,
                'itemname' => 'Overwrite as legacy score',
                'idnumber' => 'reviewed-historical-score',
                'deliveryid' => 0,
                'grade' => 88.0,
            ]);
            $this->fail('A delivery-mapped item must not accept a delivery-free grade update.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('matching positive delivery ID', $exception->getMessage());
        }

        $unchangeditem = \grade_item::fetch(['id' => (int) $item->id]);
        $this->assertNotFalse($unchangeditem);
        $this->assertSame('Reviewed historical score', (string) $unchangeditem->itemname);
        $this->assertSame(100.0, (float) $unchangeditem->grademax);
        $this->assertSame(54.0, (float) $DB->get_field('grade_grades', 'finalgrade', [
            'itemid' => (int) $item->id,
            'userid' => (int) $user->id,
        ]));
        $this->assertEquals($mappingbefore, $DB->get_record('local_rtcsync_deliverygrade', [
            'gradeitemid' => (int) $item->id,
        ], '*', MUST_EXIST));
    }

    public function test_delivery_score_rejects_stale_grade_mapping_before_any_write(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse(['idnumber' => 'rtc-subject:320']);
        $this->createDeliveryGrouping($course, 718);
        $this->createDeliveryGrouping($course, 719);
        $user = $this->createManagedUser();
        $item = new \grade_item([
            'courseid' => (int) $course->id,
            'itemname' => 'Existing delivery 719 score',
            'itemtype' => 'manual',
            'itemmodule' => null,
            'iteminstance' => null,
            'itemnumber' => 0,
            'idnumber' => 'rtc-subject-score:320:delivery:719',
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
        ], false);
        $item->insert('lineage-test');
        $item->update_final_grade((int) $user->id, 54.0, 'lineage-test');
        $DB->insert_record('local_rtcsync_deliverygrade', (object) [
            'gradeitemid' => (int) $item->id,
            'courseid' => (int) $course->id,
            'deliveryid' => 718,
            'timecreated' => time(),
            'timemodified' => time(),
            'lineageverified' => 1,
            'lineagemanifestid' => 'reviewed-grade-manifest',
        ]);
        $mappingbefore = $DB->get_record('local_rtcsync_deliverygrade', [
            'gradeitemid' => (int) $item->id,
        ], '*', MUST_EXIST);

        foreach ([
            'write' => ['grade' => 88.0, 'clear' => 0],
            'clear' => ['grade' => null, 'clear' => 1],
        ] as $operation => $change) {
            try {
                \local_rtcsync_external::upsert_grade([
                    'courseid' => (int) $course->id,
                    'userid' => (int) $user->id,
                    'itemname' => 'Stale mapping ' . $operation . ' attempt',
                    'idnumber' => 'rtc-subject-score:320:delivery:719',
                    'deliveryid' => 719,
                    'grade' => $change['grade'],
                    'grademax' => 200,
                    'clear' => $change['clear'],
                ]);
                $this->fail('A stale grade-item delivery mapping must reject a ' . $operation . '.');
            } catch (\invalid_parameter_exception $exception) {
                $this->assertStringContainsString(
                    'does not match the grade item’s stored delivery association',
                    $exception->getMessage()
                );
            }

            $unchangeditem = \grade_item::fetch(['id' => (int) $item->id]);
            $this->assertNotFalse($unchangeditem);
            $this->assertSame('Existing delivery 719 score', (string) $unchangeditem->itemname);
            $this->assertSame(100.0, (float) $unchangeditem->grademax);
            $this->assertSame(54.0, (float) $DB->get_field('grade_grades', 'finalgrade', [
                'itemid' => (int) $item->id,
                'userid' => (int) $user->id,
            ]));
            $this->assertEquals($mappingbefore, $DB->get_record('local_rtcsync_deliverygrade', [
                'gradeitemid' => (int) $item->id,
            ], '*', MUST_EXIST));
        }
    }

    public function test_delivery_score_sync_rejects_an_existing_activity_grade_item(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse(['idnumber' => 'rtc-subject:318']);
        $this->createDeliveryGrouping($course, 716);
        $user = $this->createManagedUser();
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => (int) $course->id,
            'name' => 'Existing activity grade',
            'grade' => 100,
        ]);
        $item = \grade_item::fetch([
            'courseid' => (int) $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => (int) $quiz->id,
            'itemnumber' => 0,
        ]);
        $this->assertNotFalse($item);
        $item->idnumber = 'rtc-subject-score:318:delivery:716';
        $item->itemname = 'Existing activity grade';
        $item->update();
        $item->update_final_grade((int) $user->id, 47.0, 'activity-grade-fixture');

        try {
            \local_rtcsync_external::upsert_grade([
                'courseid' => (int) $course->id,
                'userid' => (int) $user->id,
                'itemname' => 'RTC delivery result',
                'idnumber' => 'rtc-subject-score:318:delivery:716',
                'deliveryid' => 716,
                'grade' => 91.0,
            ]);
            $this->fail('RTC sync must not reuse or rewrite a Moodle activity grade item.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('must be manual grade items', $exception->getMessage());
        }

        $unchanged = \grade_item::fetch(['id' => (int) $item->id]);
        $grade = \grade_grade::fetch([
            'itemid' => (int) $item->id,
            'userid' => (int) $user->id,
        ]);
        $this->assertNotFalse($unchanged);
        $this->assertNotFalse($grade);
        $this->assertSame('mod', (string) $unchanged->itemtype);
        $this->assertSame('Existing activity grade', (string) $unchanged->itemname);
        $this->assertSame(47.0, (float) $grade->finalgrade);
        $this->assertFalse($DB->record_exists('local_rtcsync_deliverygrade', [
            'gradeitemid' => (int) $item->id,
        ]));
    }

    public function test_delivery_score_rejects_an_idnumber_for_another_delivery(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse(['idnumber' => 'rtc-subject:315']);
        $user = $this->createManagedUser();

        $this->expectException(\invalid_parameter_exception::class);
        \local_rtcsync_external::upsert_grade([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'itemname' => 'Delivery score',
            'idnumber' => 'rtc-subject-score:315:delivery:712',
            'deliveryid' => 713,
            'grade' => 75.0,
        ]);
    }

    public function test_delivery_score_rejects_missing_or_negative_delivery_grouping(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse(['idnumber' => 'rtc-subject:316']);
        $user = $this->createManagedUser();

        try {
            \local_rtcsync_external::upsert_grade([
                'courseid' => (int) $course->id,
                'userid' => (int) $user->id,
                'itemname' => 'Missing delivery mapping',
                'idnumber' => 'rtc-subject-score:316:delivery:714',
                'deliveryid' => 714,
                'grade' => 75.0,
            ]);
            $this->fail('A delivery score without a matching course grouping must fail closed.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('matching RTC delivery grouping', $exception->getMessage());
        }

        $this->createDeliveryGrouping($course, 714);
        $this->expectException(\invalid_parameter_exception::class);
        \local_rtcsync_external::upsert_grade([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'itemname' => 'Negative delivery mapping',
            'idnumber' => 'rtc-subject-score:316',
            'deliveryid' => -1,
            'grade' => 75.0,
        ]);
    }

    public function test_duplicate_delivery_groupings_block_scope_score_and_history_writes(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enableavailability', 1);
        set_config('version', '2026110200', 'local_rtcsync');
        $course = $this->createManagedCourse(['idnumber' => 'rtc-subject:318']);
        $user = $this->createManagedUser();
        $this->createDeliveryGrouping($course, 714);
        $this->createDuplicateDeliveryGrouping($course, 714);
        // This course has no activities to backfill. Seed it as already ready
        // so the test's outer PHPUnit transaction does not defer final readiness.
        $now = time();
        $DB->insert_record('local_rtcsync_deliverycourse', (object) [
            'courseid' => (int) $course->id,
            'coursevisible' => (int) $course->visible,
            'ready' => 1,
            'lastcmid' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        try {
            \local_rtcsync_external::upsert_grade([
                'courseid' => (int) $course->id,
                'userid' => (int) $user->id,
                'itemname' => 'Ambiguous delivery score',
                'idnumber' => 'rtc-subject-score:318:delivery:714',
                'deliveryid' => 714,
                'grade' => 75.0,
            ]);
            $this->fail('A score cannot be associated through duplicate RTC delivery groupings.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('exactly one matching RTC delivery grouping', $exception->getMessage());
        }
        $this->assertFalse($DB->record_exists('grade_items', [
            'courseid' => (int) $course->id,
            'idnumber' => 'rtc-subject-score:318:delivery:714',
        ]));

        \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'student',
            'suspend' => 1,
        ]);
        try {
            \local_rtcsync_external::sync_history_marker([
                'courseid' => (int) $course->id,
                'userid' => (int) $user->id,
                'historyaccess' => 1,
                'historysourcetype' => 'delivery_participation',
                'historysourceid' => 9302,
                'deliveryid' => 714,
            ]);
            $this->fail('A history marker cannot be granted through duplicate RTC delivery groupings.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('exactly one matching RTC delivery grouping', $exception->getMessage());
        }
        $this->assertFalse($DB->record_exists('local_rtcsync_history', [
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'sourceid' => 9302,
        ]));

        $preflight = \local_rtcsync_external::prepare_delivery_course((int) $course->id);
        $this->assertTrue($preflight['ready']);
        try {
            \local_rtcsync_external::sync_student_delivery_scope([
                'courseid' => (int) $course->id,
                'userid' => (int) $user->id,
                'deliveryids' => [714],
                'revision' => 20261028000000001,
            ]);
            $this->fail('A scope cannot authorize delivery access through duplicate RTC groupings.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('exactly one RTC grouping', $exception->getMessage());
        }
        $this->assertFalse($DB->record_exists('local_rtcsync_studentscope', [
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
        ]));
    }

    public function test_delivery_history_marker_requires_matching_course_grouping(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse();
        $user = $this->createManagedUser();
        \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'student',
            'suspend' => 1,
        ]);

        $this->expectException(\invalid_parameter_exception::class);
        \local_rtcsync_external::sync_history_marker([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'historyaccess' => 1,
            'historysourcetype' => 'delivery_participation',
            'historysourceid' => 9301,
            'deliveryid' => 799,
        ]);
    }

    public function test_delivery_preflight_returns_ready_only_after_course_guard(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('version', '2026103101', 'local_rtcsync');
        set_config('enableavailability', 1);
        $course = $this->createManagedCourse(['idnumber' => 'rtc-subject:preflight']);

        $result = \local_rtcsync_external::prepare_delivery_course((int) $course->id);

        $this->assertSame('2026103101', $result['plugin_version']);
        $this->assertTrue($result['ready']);
        $this->assertTrue($DB->record_exists('local_rtcsync_deliverycourse', [
            'courseid' => (int) $course->id,
            'ready' => 1,
        ]));
    }

    public function test_delivery_preflight_stays_closed_below_revisioned_history_contract_version(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('version', '2026103100', 'local_rtcsync');
        set_config('enableavailability', 1);
        $course = $this->createManagedCourse(['idnumber' => 'rtc-subject:preflight-old-contract']);

        $result = \local_rtcsync_external::prepare_delivery_course((int) $course->id);

        $this->assertSame('2026103100', $result['plugin_version']);
        $this->assertFalse($result['ready']);
        $this->assertFalse($DB->record_exists('local_rtcsync_deliverycourse', [
            'courseid' => (int) $course->id,
        ]));
    }

    public function test_archived_delivery_grouping_keeps_history_scope_but_removes_group_membership(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enableavailability', 1);
        $course = $this->createManagedCourse(['idnumber' => 'rtc-subject:archive-delivery']);
        $student = $this->createManagedUser();
        $payload = [
            'idnumber' => 'rtc-delivery:901',
            'name' => '[Delivery] Archive Guard',
            'delivery_id' => 901,
            'userids' => [(int) $student->id],
            'courseids' => [(int) $course->id],
            'grouping_idnumber' => 'rtc-delivery-grouping:901',
            'grouping_name' => '[Delivery] Archive Guard',
            'groups' => [[
                'idnumber' => 'rtc-class-group:901',
                'name' => '[Group] Archive Guard',
                'userids' => [(int) $student->id],
            ]],
        ];
        \local_rtcsync_external::upsert_class($payload + ['visible' => 1]);
        $grouping = $DB->get_record('groupings', [
            'courseid' => (int) $course->id,
            'idnumber' => 'rtc-delivery-grouping:901',
        ], '*', MUST_EXIST);
        $this->assertSame(1, $DB->count_records('groupings_groups', ['groupingid' => (int) $grouping->id]));

        \local_rtcsync_external::upsert_class($payload + ['visible' => 0, 'groups' => []]);

        $archivedgrouping = $DB->get_record('groupings', [
            'courseid' => (int) $course->id,
            'idnumber' => 'rtc-delivery-grouping:901',
        ], '*', MUST_EXIST);
        $this->assertSame((int) $grouping->id, (int) $archivedgrouping->id);
        $this->assertSame(0, $DB->count_records('groupings_groups', ['groupingid' => (int) $archivedgrouping->id]));
    }

    public function test_dropped_course_keeps_grouping_shell_for_retained_module_grade_history(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enableavailability', 1);
        $course = $this->createManagedCourse([
            'idnumber' => 'rtc-subject:9100991',
            'showgrades' => 1,
        ]);
        $student = $this->createManagedUser();
        \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $student->id,
            'role_shortname' => 'student',
            'suspend' => 1,
        ]);
        $payload = [
            'idnumber' => 'rtc-delivery:991',
            'name' => '[Delivery] Retained module history',
            'delivery_id' => 991,
            'userids' => [(int) $student->id],
            'courseids' => [(int) $course->id],
            'grouping_idnumber' => 'rtc-delivery-grouping:991',
            'grouping_name' => '[Delivery] Retained module history',
            'groups' => [[
                'idnumber' => 'rtc-delivery-class-group:991:991',
                'name' => '[Group] Retained module history',
                'userids' => [(int) $student->id],
            ]],
        ];
        \local_rtcsync_external::upsert_class($payload + ['visible' => 1]);
        $grouping = $DB->get_record('groupings', [
            'courseid' => (int) $course->id,
            'idnumber' => 'rtc-delivery-grouping:991',
        ], '*', MUST_EXIST);
        $managedgroup = $DB->get_record('groups', [
            'courseid' => (int) $course->id,
            'idnumber' => 'rtc-delivery-class-group:991:991',
        ], '*', MUST_EXIST);
        \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $student->id,
            'role_shortname' => 'student',
            'suspend' => 0,
        ]);
        $this->assertTrue(groups_add_member((int) $managedgroup->id, (int) $student->id));
        $manualinstance = $DB->get_record('enrol', [
            'courseid' => (int) $course->id,
            'enrol' => 'manual',
        ], '*', MUST_EXIST);
        enrol_get_plugin('manual')->update_user_enrol(
            $manualinstance,
            (int) $student->id,
            ENROL_USER_SUSPENDED
        );
        $groupavailability = json_encode(\core_availability\tree::get_root_json([
            \availability_group\condition::get_json((int) $managedgroup->id),
        ]));
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => (int) $course->id,
            'name' => 'Retained delivery result',
            'grade' => 100,
        ], ['availability' => $groupavailability]);
        $DB->set_field('course_modules', 'groupingid', (int) $grouping->id, ['id' => (int) $quiz->cmid]);
        \local_rtcsync\local\delivery_activity_access::protect_course_module((int) $quiz->cmid);
        $mapping = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => (int) $quiz->cmid], '*', MUST_EXIST);
        $this->assertSame(0, (int) $mapping->deliveryid);
        $this->assertSame(\local_rtcsync\local\delivery_activity_access::LINEAGE_UNREVIEWED, (int) $mapping->lineagestatus);
        $this->reconcileDeliveryModule($course, (int) $quiz->cmid, 'historical', 991);
        $mapping = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => (int) $quiz->cmid], '*', MUST_EXIST);
        $this->assertSame(\local_rtcsync\local\delivery_activity_access::LINEAGE_HISTORICAL, (int) $mapping->lineagestatus);
        $this->assertLessThan(0, (int) $mapping->groupingid);

        $gradeitem = \grade_item::fetch([
            'courseid' => (int) $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => (int) $quiz->id,
            'itemnumber' => 0,
        ]);
        $this->assertNotFalse($gradeitem);
        $gradeitem->itemname = 'Retained delivery result';
        $gradeitem->grademax = 100;
        $gradeitem->update();
        $gradeitem->update_final_grade((int) $student->id, 88.0, 'retained-delivery-test');
        \local_rtcsync\local\student_history::sync_marker(
            (int) $student->id,
            (int) $course->id,
            1,
            'delivery_participation',
            9802,
            991
        );

        \local_rtcsync_external::upsert_class(array_merge($payload, [
            'visible' => 1,
            'courseids' => [],
            'groups' => [],
        ]));

        $retainedgrouping = $DB->get_record('groupings', [
            'courseid' => (int) $course->id,
            'idnumber' => 'rtc-delivery-grouping:991',
        ], '*', MUST_EXIST);
        $this->assertSame((int) $grouping->id, (int) $retainedgrouping->id);
        $this->assertSame(0, $DB->count_records('groupings_groups', ['groupingid' => (int) $retainedgrouping->id]));
        $this->assertTrue($DB->record_exists('groups', ['id' => (int) $managedgroup->id]));
        $this->assertStringContainsString('"id":' . (int) $managedgroup->id, (string) $DB->get_field(
            'course_modules',
            'availability',
            ['id' => (int) $quiz->cmid]
        ));
        // This delivery-owned child group is emptied but kept because the
        // teacher's availability condition still references its ID.
        $this->assertFalse($DB->record_exists('groups_members', ['groupid' => (int) $managedgroup->id]));
        $this->assertSame(0, (int) $DB->get_field('local_rtcsync_deliverycm', 'deliveryid', ['cmid' => (int) $quiz->cmid]));

        $this->setUser($student);
        $history = new \local_rtcsync\local\student_history();
        $results = $history->visible_results();
        $this->assertSame(['Retained delivery result'], array_column($results['results'], 'item'));
        $this->assertSame(88.0, $results['results'][0]['grade']);
    }

    public function test_delivery_history_requires_positive_delivery_scope(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse();
        $user = $this->createManagedUser();
        \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'student',
            'suspend' => 1,
        ]);

        $this->expectException(\invalid_parameter_exception::class);
        \local_rtcsync_external::sync_history_marker([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'historyaccess' => 1,
            'historysourcetype' => 'delivery_participation',
            'historysourceid' => 9202,
        ]);
    }

    public function test_system_role_sync_manages_only_approved_component_assignments(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $user = $this->createManagedUser();
        $systemcontext = \context_system::instance();
        $manager = $DB->get_record('role', ['shortname' => 'manager'], '*', MUST_EXIST);
        $student = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);

        // This manual assignment must never be removed by RTC reconciliation.
        role_assign((int) $manager->id, (int) $user->id, $systemcontext->id);

        $result = \local_rtcsync_external::sync_system_roles([
            'userid' => (int) $user->id,
            'role_shortnames' => ['manager', 'student'],
        ]);

        $this->assertSame(['manager'], $result['role_shortnames']);
        $this->assertSame(1, $result['managed_count']);
        $this->assertTrue($DB->record_exists('role_assignments', [
            'roleid' => (int) $manager->id,
            'userid' => (int) $user->id,
            'contextid' => $systemcontext->id,
            'component' => 'local_rtcsync',
        ]));
        $this->assertFalse($DB->record_exists('role_assignments', [
            'roleid' => (int) $student->id,
            'userid' => (int) $user->id,
            'contextid' => $systemcontext->id,
            'component' => 'local_rtcsync',
        ]));

        \local_rtcsync_external::sync_system_roles([
            'userid' => (int) $user->id,
            'role_shortnames' => [],
        ]);

        $this->assertFalse($DB->record_exists('role_assignments', [
            'roleid' => (int) $manager->id,
            'userid' => (int) $user->id,
            'contextid' => $systemcontext->id,
            'component' => 'local_rtcsync',
        ]));
        $this->assertTrue($DB->record_exists('role_assignments', [
            'roleid' => (int) $manager->id,
            'userid' => (int) $user->id,
            'contextid' => $systemcontext->id,
            'component' => '',
        ]));
    }

    public function test_unenrolling_one_teacher_preserves_other_teacher_enrolments(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('sendcoursewelcomemessage', 0, 'enrol_manual');

        $course = $this->createManagedCourse();
        $firstteacher = $this->createManagedUser();
        $secondteacher = $this->createManagedUser();

        foreach ([$firstteacher, $secondteacher] as $teacher) {
            \local_rtcsync_external::enrol_user([
                'courseid' => (int) $course->id,
                'userid' => (int) $teacher->id,
                'role_shortname' => 'editingteacher',
                'suspend' => 0,
            ]);
        }

        \local_rtcsync_external::unenrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $firstteacher->id,
            'role_shortname' => 'editingteacher',
        ]);

        $manual = $DB->get_record('enrol', [
            'courseid' => (int) $course->id,
            'enrol' => 'manual',
        ], '*', MUST_EXIST);

        $this->assertFalse($DB->record_exists('user_enrolments', [
            'enrolid' => (int) $manual->id,
            'userid' => (int) $firstteacher->id,
        ]));
        $this->assertTrue($DB->record_exists('user_enrolments', [
            'enrolid' => (int) $manual->id,
            'userid' => (int) $secondteacher->id,
        ]));
    }

    public function test_unenrolling_one_course_role_preserves_another_role_and_enrolment(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('sendcoursewelcomemessage', 0, 'enrol_manual');

        $course = $this->createManagedCourse();
        $user = $this->createManagedUser();
        $context = \context_course::instance((int) $course->id);
        $student = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);

        \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'editingteacher',
            'suspend' => 0,
        ]);
        role_assign((int) $student->id, (int) $user->id, $context->id);

        \local_rtcsync_external::unenrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'editingteacher',
        ]);

        $manual = $DB->get_record('enrol', [
            'courseid' => (int) $course->id,
            'enrol' => 'manual',
        ], '*', MUST_EXIST);

        $this->assertTrue($DB->record_exists('user_enrolments', [
            'enrolid' => (int) $manual->id,
            'userid' => (int) $user->id,
        ]));
        $this->assertTrue($DB->record_exists('role_assignments', [
            'roleid' => (int) $student->id,
            'userid' => (int) $user->id,
            'contextid' => $context->id,
        ]));
    }

    public function test_managed_state_returns_system_roles_scope(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();

        $user = $this->createManagedUser([
            'idnumber' => 'rtc-user:73014',
        ]);

        \local_rtcsync_external::sync_system_roles([
            'userid' => (int) $user->id,
            'role_shortnames' => ['manager'],
        ]);

        $result = \local_rtcsync_external::get_managed_state(
            'systemroles',
            ['rtc-user:73014'],
            0,
            100
        );

        $this->assertSame('systemroles', $result['scope']);
        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['records']);
        $this->assertSame((int) $user->id, $result['records'][0]['moodle_id']);
        $this->assertSame('manager', $result['records'][0]['role_shortname']);
    }

    public function test_sso_elevation_check_prevents_broad_system_roles(): void
    {
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        require_once($CFG->dirroot . '/local/rtc_sso.php');

        $this->assertNull(rtc_sso_moodle_role_shortname(['teacher']));
        $this->assertNull(rtc_sso_moodle_role_shortname(['student']));
        $this->assertNull(rtc_sso_moodle_role_shortname(['staff', 'teacher']));
        $this->assertNull(rtc_sso_moodle_role_shortname(['director', 'head department']));
        $this->assertSame('manager', rtc_sso_moodle_role_shortname(['super admin']));
        $this->assertSame('manager', rtc_sso_moodle_role_shortname(['admin']));

        $user = $this->getDataGenerator()->create_user();
        $systemcontext = \context_system::instance();
        $manager = $DB->get_record('role', ['shortname' => 'manager'], '*', MUST_EXIST);

        role_assign((int) $manager->id, (int) $user->id, $systemcontext->id, 'local_rtc_sso');

        $this->assertTrue($DB->record_exists('role_assignments', [
            'roleid' => (int) $manager->id,
            'userid' => (int) $user->id,
            'contextid' => $systemcontext->id,
            'component' => 'local_rtc_sso',
        ]));

        rtc_sso_sync_role_access($user, ['teacher', 'staff']);

        $this->assertFalse($DB->record_exists('role_assignments', [
            'userid' => (int) $user->id,
            'contextid' => $systemcontext->id,
            'component' => 'local_rtc_sso',
        ]));
    }

    public function test_credit_courses_strictly_isolate_teacher_access_and_share_students(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('sendcoursewelcomemessage', 0, 'enrol_manual');

        $parent = $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-subject:strict',
            'shortname' => 'RTC-STRICT-PARENT',
        ]);
        $teacherone = $this->createManagedUser();
        $teachertwo = $this->createManagedUser();
        $student = $this->createManagedUser();

        $base = [
            'courseid' => (int) $parent->id,
            'subject_id' => 44,
            'description' => 'Strictly isolated teaching credit.',
            'teacher_role_shortname' => 'editingteacher',
            'student_role_shortname' => 'student',
            'student_userids' => [(int) $student->id],
        ];
        $first = \local_rtcsync_external::upsert_credit($base + [
            'idnumber' => 'rtc-credit-course:101',
            'shortname' => 'RTC-CREDIT-101',
            'name' => 'Credit 1 - Teacher One',
            'teacher_userids' => [(int) $teacherone->id],
        ]);
        $second = \local_rtcsync_external::upsert_credit($base + [
            'idnumber' => 'rtc-credit-course:102',
            'shortname' => 'RTC-CREDIT-102',
            'name' => 'Credit 2 - Teacher Two',
            'teacher_userids' => [(int) $teachertwo->id],
        ]);

        $this->assertNotSame($first['courseid'], $second['courseid']);
        $firstowner = $DB->get_record('local_rtcsync_creditowner', [
            'creditcourseid' => (int) $first['courseid'],
        ], '*', MUST_EXIST);
        $secondowner = $DB->get_record('local_rtcsync_creditowner', [
            'creditcourseid' => (int) $second['courseid'],
        ], '*', MUST_EXIST);
        $this->assertSame((int) $parent->id, (int) $firstowner->parentcourseid);
        $this->assertSame((int) $parent->id, (int) $secondowner->parentcourseid);
        $this->assertSame('rtc-credit-course:101', (string) $firstowner->idnumber);
        $repeatedfirst = \local_rtcsync_external::upsert_credit($base + [
            'idnumber' => 'rtc-credit-course:101',
            'shortname' => 'RTC-CREDIT-101',
            'name' => 'Credit 1 - Teacher One',
            'teacher_userids' => [(int) $teacherone->id],
        ]);
        $this->assertSame((int) $first['courseid'], (int) $repeatedfirst['courseid']);
        $this->assertSame(1, $DB->count_records('local_rtcsync_creditowner', [
            'creditcourseid' => (int) $first['courseid'],
        ]));

        $teacherrole = $DB->get_record('role', ['shortname' => 'editingteacher'], '*', MUST_EXIST);
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        $firstcontext = \context_course::instance((int) $first['courseid']);
        $secondcontext = \context_course::instance((int) $second['courseid']);

        $this->assertTrue($DB->record_exists('role_assignments', [
            'contextid' => $firstcontext->id,
            'roleid' => (int) $teacherrole->id,
            'userid' => (int) $teacherone->id,
        ]));
        $this->assertFalse($DB->record_exists('role_assignments', [
            'contextid' => $secondcontext->id,
            'roleid' => (int) $teacherrole->id,
            'userid' => (int) $teacherone->id,
        ]));
        $this->assertTrue($DB->record_exists('role_assignments', [
            'contextid' => $secondcontext->id,
            'roleid' => (int) $teacherrole->id,
            'userid' => (int) $teachertwo->id,
        ]));
        $this->assertFalse($DB->record_exists('role_assignments', [
            'contextid' => $firstcontext->id,
            'roleid' => (int) $teacherrole->id,
            'userid' => (int) $teachertwo->id,
        ]));
        $this->assertTrue(has_capability('moodle/course:update', $firstcontext, $teacherone));
        $this->assertFalse(has_capability('moodle/course:update', $secondcontext, $teacherone));
        $this->assertTrue(has_capability('moodle/course:update', $secondcontext, $teachertwo));
        $this->assertFalse(has_capability('moodle/course:update', $firstcontext, $teachertwo));
        foreach ([$firstcontext, $secondcontext] as $context) {
            $this->assertTrue($DB->record_exists('role_assignments', [
                'contextid' => $context->id,
                'roleid' => (int) $studentrole->id,
                'userid' => (int) $student->id,
            ]));
        }

        $state = \local_rtcsync_external::get_managed_state(
            'credits',
            ['rtc-credit-course:101', 'rtc-credit-course:102'],
            0,
            100
        );
        $this->assertSame(2, $state['total']);
        $this->assertCount(2, $state['records']);
        $this->assertStringContainsString(
            $teacherone->id . ':editingteacher',
            $state['records'][0]['member_roles']
        );

        // A Moodle operator may add an unmanaged permission to an RTC-owned
        // credit course. SMS must remove only the role assignment it created.
        role_assign((int) $teacherrole->id, (int) $teacherone->id, $firstcontext->id);

        \local_rtcsync_external::delete_credit([
            'courseid' => (int) $parent->id,
            'idnumber' => 'rtc-credit-course:101',
            'teacher_role_shortname' => 'editingteacher',
            'student_role_shortname' => 'student',
        ]);
        $this->assertSame(0, (int) $DB->get_field('course', 'visible', [
            'id' => (int) $first['courseid'],
        ]));
        $this->assertFalse($DB->record_exists('role_assignments', [
            'contextid' => $firstcontext->id,
            'userid' => (int) $teacherone->id,
            'component' => 'local_rtcsync',
            'itemid' => (int) $first['courseid'],
        ]));
        $this->assertTrue($DB->record_exists('role_assignments', [
            'contextid' => $firstcontext->id,
            'userid' => (int) $teacherone->id,
            'component' => '',
            'itemid' => 0,
        ]));
        $this->assertFalse($DB->record_exists('role_assignments', [
            'contextid' => $firstcontext->id,
            'userid' => (int) $student->id,
        ]));
        $this->assertSame((int) $parent->id, (int) $DB->get_field(
            'local_rtcsync_creditowner',
            'parentcourseid',
            ['creditcourseid' => (int) $first['courseid']],
            MUST_EXIST
        ));
    }

    public function test_same_category_wrong_parent_cannot_sync_or_cleanup_credit_course(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('sendcoursewelcomemessage', 0, 'enrol_manual');

        $parent = $this->createManagedCourse(['idnumber' => 'rtc-subject:credit-owner']);
        $otherparent = $this->createManagedCourse([
            'idnumber' => 'rtc-subject:credit-wrong-owner',
            'category' => (int) $parent->category,
        ]);
        $this->assertSame((int) $parent->category, (int) $otherparent->category);
        $teacher = $this->createManagedUser();
        $credit = \local_rtcsync_external::upsert_credit([
            'courseid' => (int) $parent->id,
            'subject_id' => 7001,
            'idnumber' => 'rtc-credit-course:7001',
            'shortname' => 'RTC-CREDIT-7001',
            'name' => 'Credit owned by subject one',
            'teacher_userids' => [(int) $teacher->id],
            'student_userids' => [],
        ]);
        $creditbefore = $DB->get_record('course', [
            'id' => (int) $credit['courseid'],
        ], '*', MUST_EXIST);
        $context = \context_course::instance((int) $credit['courseid']);
        $rolesbefore = $DB->get_records('role_assignments', [
            'contextid' => (int) $context->id,
        ], 'id', 'id,userid,roleid,component,itemid');
        $ownerbefore = $DB->get_record('local_rtcsync_creditowner', [
            'creditcourseid' => (int) $credit['courseid'],
        ], '*', MUST_EXIST);

        try {
            \local_rtcsync_external::upsert_credit([
                'courseid' => (int) $otherparent->id,
                'subject_id' => 7001,
                'idnumber' => 'rtc-credit-course:7001',
                'shortname' => 'RTC-CREDIT-7001-WRONG-PARENT',
                'name' => 'Must not update wrong owner',
                'teacher_userids' => [],
                'student_userids' => [],
            ]);
            $this->fail('A same-category wrong parent must not sync this credit course.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString(
                'The RTC credit course belongs to a different parent subject course; no changes were made.',
                $exception->getMessage()
            );
        }

        $this->assertEquals($creditbefore, $DB->get_record('course', [
            'id' => (int) $credit['courseid'],
        ], '*', MUST_EXIST));
        $this->assertEquals($rolesbefore, $DB->get_records('role_assignments', [
            'contextid' => (int) $context->id,
        ], 'id', 'id,userid,roleid,component,itemid'));
        $this->assertEquals($ownerbefore, $DB->get_record('local_rtcsync_creditowner', [
            'creditcourseid' => (int) $credit['courseid'],
        ], '*', MUST_EXIST));

        try {
            \local_rtcsync_external::delete_credit([
                'courseid' => (int) $otherparent->id,
                'idnumber' => 'rtc-credit-course:7001',
                'teacher_role_shortname' => 'editingteacher',
                'student_role_shortname' => 'student',
            ]);
            $this->fail('A same-category wrong parent must not archive this credit course.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString(
                'The RTC credit course belongs to a different parent subject course; no changes were made.',
                $exception->getMessage()
            );
        }

        $this->assertEquals($creditbefore, $DB->get_record('course', [
            'id' => (int) $credit['courseid'],
        ], '*', MUST_EXIST));
        $this->assertEquals($rolesbefore, $DB->get_records('role_assignments', [
            'contextid' => (int) $context->id,
        ], 'id', 'id,userid,roleid,component,itemid'));
        $this->assertEquals($ownerbefore, $DB->get_record('local_rtcsync_creditowner', [
            'creditcourseid' => (int) $credit['courseid'],
        ], '*', MUST_EXIST));
    }

    public function test_legacy_credit_course_without_parent_owner_fails_closed(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $parent = $this->createManagedCourse(['idnumber' => 'rtc-subject:legacy-credit-owner']);
        $legacy = $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-credit-course:legacy-unowned',
            'category' => (int) $parent->category,
            'fullname' => 'Legacy credit with no verified owner',
            'visible' => 1,
        ]);
        $coursebefore = $DB->get_record('course', ['id' => (int) $legacy->id], '*', MUST_EXIST);
        $context = \context_course::instance((int) $legacy->id);
        $rolesbefore = $DB->get_records('role_assignments', [
            'contextid' => (int) $context->id,
        ], 'id', 'id,userid,roleid,component,itemid');
        $message = 'This legacy RTC credit course has no verified parent ownership record. '
            . 'Do not sync or archive it by category; have the LMS data owner verify its exact parent '
            . 'from authoritative RTC records before retrying.';

        try {
            \local_rtcsync_external::upsert_credit([
                'courseid' => (int) $parent->id,
                'idnumber' => 'rtc-credit-course:legacy-unowned',
                'shortname' => 'LEGACY-CREDIT-UNOWNED',
                'name' => 'Must not guess the owner',
                'teacher_userids' => [],
                'student_userids' => [],
            ]);
            $this->fail('A legacy credit course without an ownership record must not be updated.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }

        try {
            \local_rtcsync_external::delete_credit([
                'courseid' => (int) $parent->id,
                'idnumber' => 'rtc-credit-course:legacy-unowned',
            ]);
            $this->fail('A legacy credit course without an ownership record must not be archived.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }

        $this->assertEquals($coursebefore, $DB->get_record('course', ['id' => (int) $legacy->id], '*', MUST_EXIST));
        $this->assertEquals($rolesbefore, $DB->get_records('role_assignments', [
            'contextid' => (int) $context->id,
        ], 'id', 'id,userid,roleid,component,itemid'));
        $this->assertFalse($DB->record_exists('local_rtcsync_creditowner', [
            'creditcourseid' => (int) $legacy->id,
        ]));
    }

    public function test_credit_sync_and_cleanup_reject_duplicate_idnumber_without_mutation(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('sendcoursewelcomemessage', 0, 'enrol_manual');

        $parent = $this->createManagedCourse(['idnumber' => 'rtc-subject:credit-duplicate-owner']);
        $teacher = $this->createManagedUser();
        $credit = \local_rtcsync_external::upsert_credit([
            'courseid' => (int) $parent->id,
            'subject_id' => 7002,
            'idnumber' => 'rtc-credit-course:7002',
            'shortname' => 'RTC-CREDIT-7002',
            'name' => 'Credit with ambiguous idnumber',
            'teacher_userids' => [(int) $teacher->id],
            'student_userids' => [],
        ]);
        $duplicate = $this->getDataGenerator()->create_course([
            'idnumber' => 'manual-course:credit-duplicate',
            'category' => (int) $parent->category,
        ]);
        $DB->set_field('course', 'idnumber', 'rtc-credit-course:7002', ['id' => (int) $duplicate->id]);
        $creditbefore = $DB->get_record('course', [
            'id' => (int) $credit['courseid'],
        ], '*', MUST_EXIST);
        $duplicatebefore = $DB->get_record('course', [
            'id' => (int) $duplicate->id,
        ], '*', MUST_EXIST);
        $context = \context_course::instance((int) $credit['courseid']);
        $rolesbefore = $DB->get_records('role_assignments', [
            'contextid' => (int) $context->id,
        ], 'id', 'id,userid,roleid,component,itemid');

        try {
            \local_rtcsync_external::upsert_credit([
                'courseid' => (int) $parent->id,
                'subject_id' => 7002,
                'idnumber' => 'rtc-credit-course:7002',
                'shortname' => 'RTC-CREDIT-7002-CHANGED',
                'name' => 'Must not update ambiguous credit',
                'teacher_userids' => [],
                'student_userids' => [],
            ]);
            $this->fail('An ambiguous credit idnumber must not update an arbitrary Moodle course.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString(
                'Credit course idnumber matches multiple Moodle courses.',
                $exception->getMessage()
            );
        }
        $this->assertEquals($creditbefore, $DB->get_record('course', [
            'id' => (int) $credit['courseid'],
        ], '*', MUST_EXIST));
        $this->assertEquals($duplicatebefore, $DB->get_record('course', [
            'id' => (int) $duplicate->id,
        ], '*', MUST_EXIST));
        $this->assertEquals($rolesbefore, $DB->get_records('role_assignments', [
            'contextid' => (int) $context->id,
        ], 'id', 'id,userid,roleid,component,itemid'));

        try {
            \local_rtcsync_external::delete_credit([
                'courseid' => (int) $parent->id,
                'idnumber' => 'rtc-credit-course:7002',
                'teacher_role_shortname' => 'editingteacher',
                'student_role_shortname' => 'student',
            ]);
            $this->fail('An ambiguous credit idnumber must not archive an arbitrary Moodle course.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString(
                'Credit course idnumber matches multiple Moodle courses.',
                $exception->getMessage()
            );
        }

        $this->assertEquals($creditbefore, $DB->get_record('course', [
            'id' => (int) $credit['courseid'],
        ], '*', MUST_EXIST));
        $this->assertEquals($duplicatebefore, $DB->get_record('course', [
            'id' => (int) $duplicate->id,
        ], '*', MUST_EXIST));
        $this->assertEquals($rolesbefore, $DB->get_records('role_assignments', [
            'contextid' => (int) $context->id,
        ], 'id', 'id,userid,roleid,component,itemid'));
    }

    public function test_class_cohort_reconciles_child_groups_inside_course_grouping(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $courseone = $this->createManagedCourse();
        $coursetwo = $this->createManagedCourse();
        $studentone = $this->createManagedUser();
        $studenttwo = $this->createManagedUser();
        $studentthree = $this->createManagedUser();

        $result = \local_rtcsync_external::upsert_class([
            'idnumber' => 'rtc-class:100',
            'name' => '[Class] ADN Year 1 A',
            'description' => 'Managed class.',
            'visible' => 1,
            'userids' => [$studentone->id, $studenttwo->id, $studentthree->id],
            'courseids' => [$courseone->id, $coursetwo->id],
            'grouping_idnumber' => 'rtc-class-grouping:100',
            'grouping_name' => '[Class] ADN Year 1 A',
            'groups' => [
                [
                    'idnumber' => 'rtc-class-group:101',
                    'name' => '[Group] Group 1',
                    'userids' => [$studentone->id, $studenttwo->id],
                ],
                [
                    'idnumber' => 'rtc-class-group:102',
                    'name' => '[Group] Group 2',
                    'userids' => [$studentthree->id],
                ],
            ],
        ]);

        $this->assertSame(3, $result['member_count']);
        $this->assertSame(2, $result['course_count']);
        $this->assertSame(4, $result['group_count']);
        foreach ([$courseone, $coursetwo] as $course) {
            $grouping = $DB->get_record('groupings', [
                'courseid' => $course->id,
                'idnumber' => 'rtc-class-grouping:100',
            ], '*', MUST_EXIST);
            $groups = $DB->get_records('groups', ['courseid' => $course->id], 'id');
            $this->assertCount(2, $groups);
            $this->assertSame(2, $DB->count_records('groupings_groups', [
                'groupingid' => $grouping->id,
            ]));
            $first = $DB->get_record('groups', [
                'courseid' => $course->id,
                'idnumber' => 'rtc-class-group:101',
            ], '*', MUST_EXIST);
            $this->assertSame(2, $DB->count_records('groups_members', ['groupid' => $first->id]));
        }
        $state = \local_rtcsync_external::get_managed_state(
            'classes',
            ['rtc-class:100'],
            0,
            100
        );
        $this->assertSame(1, $state['total']);
        $structure = json_decode($state['records'][0]['course_structure'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(2, $structure);
        $this->assertSame('rtc-class-grouping:100', $structure[0]['grouping_idnumber']);
        $this->assertCount(2, $structure[0]['groups']);

        \local_rtcsync_external::upsert_class([
            'idnumber' => 'rtc-class:100',
            'name' => '[Class] ADN Year 1 A',
            'visible' => 1,
            'userids' => [$studentone->id],
            'courseids' => [$courseone->id],
            'grouping_idnumber' => 'rtc-class-grouping:100',
            'grouping_name' => '[Class] ADN Year 1 A',
            'groups' => [[
                'idnumber' => 'rtc-class-group:101',
                'name' => '[Group] Group 1',
                'userids' => [$studentone->id],
            ]],
        ]);

        $this->assertFalse($DB->record_exists('groupings', [
            'courseid' => $coursetwo->id,
            'idnumber' => 'rtc-class-grouping:100',
        ]));
        $this->assertFalse($DB->record_exists('groups', [
            'courseid' => $courseone->id,
            'idnumber' => 'rtc-class-group:102',
        ]));
        $state = \local_rtcsync_external::get_managed_state(
            'classes',
            ['rtc-class:100'],
            0,
            100
        );
        $structure = json_decode($state['records'][0]['course_structure'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $structure);
        $this->assertSame((int) $courseone->id, $structure[0]['courseid']);
        $this->assertCount(1, $structure[0]['groups']);

        \local_rtcsync_external::upsert_class([
            'idnumber' => 'rtc-class:100',
            'name' => '[Class] ADN Year 1 A',
            'visible' => 0,
            'userids' => [],
            'courseids' => [$courseone->id, $coursetwo->id],
            'grouping_idnumber' => 'rtc-class-grouping:100',
            'grouping_name' => '[Class] ADN Year 1 A',
            'groups' => [],
        ]);

        $cohort = $DB->get_record('cohort', ['idnumber' => 'rtc-class:100'], '*', MUST_EXIST);
        $this->assertSame(0, (int) $cohort->visible);
        $this->assertSame(0, $DB->count_records('cohort_members', ['cohortid' => $cohort->id]));
        $this->assertFalse($DB->record_exists('groupings', ['idnumber' => 'rtc-class-grouping:100']));
        $this->assertSame(0, $DB->count_records_select(
            'role_assignments',
            'component = :component AND itemid = :itemid',
            ['component' => 'local_rtcsync', 'itemid' => $cohort->id]
        ));
    }

    public function test_class_payload_rejects_values_that_exceed_moodle_limits(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\invalid_parameter_exception::class);
        $this->expectExceptionMessage('Class idnumber must be at most 100 characters.');

        \local_rtcsync_external::upsert_class([
            'idnumber' => 'rtc-class:'.str_repeat('x', 100),
            'name' => '[Class] Valid name',
            'grouping_idnumber' => 'rtc-class-grouping:limit-check',
            'grouping_name' => '[Class] Valid grouping',
            'groups' => [],
        ]);
    }

    public function test_class_delivery_roster_rejects_bad_snapshots_without_partial_mutation_and_replays_idempotently(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enableavailability', 1);
        $course = $this->createManagedCourse(['idnumber' => 'rtc-subject:roster-preflight']);
        $studentone = $this->createManagedUser();
        $studenttwo = $this->createManagedUser();
        $teacher = $this->createManagedUser();
        $unmanaged = $this->getDataGenerator()->create_user();

        $payload = [
            'idnumber' => 'rtc-delivery:roster-preflight',
            'name' => '[Delivery] Roster Preflight',
            'visible' => 1,
            'delivery_id' => 9901,
            'userids' => [(int) $studentone->id],
            'courseids' => [(int) $course->id],
            'teacher_userids' => [(int) $teacher->id],
            'grouping_idnumber' => 'rtc-delivery-grouping:9901',
            'grouping_name' => '[Delivery] Roster Preflight',
            'groups' => [
                [
                    'idnumber' => 'rtc-delivery-class-group:9901:37',
                    'name' => '[Delivery Group] 37',
                    'userids' => [(int) $studentone->id],
                ],
                [
                    'idnumber' => 'rtc-class-group:legacy37',
                    'name' => '[Legacy Delivery Group] 37',
                    'userids' => [(int) $studentone->id],
                ],
            ],
        ];
        \local_rtcsync_external::upsert_class($payload);
        $revision = 20261031000000000;
        \local_rtcsync_external::sync_student_delivery_scope([
            'courseid' => (int) $course->id,
            'userid' => (int) $studentone->id,
            'deliveryids' => [9901],
            'revision' => $revision,
        ]);
        \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $studentone->id,
            'role_shortname' => 'student',
            'suspend' => 0,
        ]);

        $snapshot = static function() use ($DB, $course): array {
            $cohorts = $DB->get_records_select(
                'cohort',
                'idnumber LIKE :classpattern OR idnumber LIKE :deliverypattern',
                ['classpattern' => 'rtc-class:%', 'deliverypattern' => 'rtc-delivery:%'],
                'idnumber',
                'id,idnumber,name,visible'
            );
            $cohortrows = array_map(static fn(\stdClass $row): array => [
                (int) $row->id,
                (string) $row->idnumber,
                (string) $row->name,
                (int) $row->visible,
            ], array_values($cohorts));
            $cohortmembers = $DB->get_records_sql(
                'SELECT cm.id, cm.cohortid, cm.userid
                   FROM {cohort_members} cm
                   JOIN {cohort} c ON c.id = cm.cohortid
                  WHERE c.idnumber LIKE :classpattern OR c.idnumber LIKE :deliverypattern
               ORDER BY cm.cohortid, cm.userid',
                ['classpattern' => 'rtc-class:%', 'deliverypattern' => 'rtc-delivery:%']
            );
            $enrolinstance = $DB->get_record('enrol', [
                'courseid' => (int) $course->id,
                'enrol' => 'manual',
            ], 'id', IGNORE_MISSING);
            $enrolments = $enrolinstance ? $DB->get_records(
                'user_enrolments',
                ['enrolid' => (int) $enrolinstance->id],
                'userid,id',
                'userid,status'
            ) : [];
            $coursecontext = \context_course::instance((int) $course->id);
            $roles = $DB->get_records_sql(
                'SELECT id, userid, roleid, component, itemid
                   FROM {role_assignments}
                  WHERE contextid = :contextid
               ORDER BY userid, roleid, component, itemid',
                ['contextid' => (int) $coursecontext->id]
            );
            $groupings = $DB->get_records('groupings', ['courseid' => (int) $course->id], 'idnumber,id');
            $groups = $DB->get_records('groups', ['courseid' => (int) $course->id], 'idnumber,id');
            $groupinglinks = $DB->get_records_sql(
                'SELECT gg.id, gg.groupingid, gg.groupid
                   FROM {groupings_groups} gg
                   JOIN {groupings} g ON g.id = gg.groupingid
                  WHERE g.courseid = :courseid
               ORDER BY gg.groupingid, gg.groupid',
                ['courseid' => (int) $course->id]
            );
            $groupmembers = $DB->get_records_sql(
                'SELECT gm.id, gm.groupid, gm.userid
                   FROM {groups_members} gm
                   JOIN {groups} g ON g.id = gm.groupid
                  WHERE g.courseid = :courseid
               ORDER BY gm.groupid, gm.userid',
                ['courseid' => (int) $course->id]
            );
            $stagedmembers = $DB->get_records_sql(
                'SELECT id, groupid, userid
                   FROM {local_rtcsync_deliverymember}
                  WHERE courseid = :courseid
               ORDER BY groupid, userid',
                ['courseid' => (int) $course->id]
            );
            $scopes = $DB->get_records('local_rtcsync_studentscope', [
                'courseid' => (int) $course->id,
            ], 'userid', 'userid,deliveryids,revision');
            $coursestate = $DB->get_record('local_rtcsync_deliverycourse', [
                'courseid' => (int) $course->id,
            ], 'ready,lastcmid', IGNORE_MISSING);

            $normaliserows = static function(array $rows, array $fields): array {
                return array_map(static function(\stdClass $row) use ($fields): array {
                    return array_map(static fn(string $field): int|string => is_numeric($row->{$field})
                        ? (int) $row->{$field}
                        : (string) $row->{$field}, $fields);
                }, array_values($rows));
            };

            return [
                'cohorts' => $cohortrows,
                'cohortmembers' => $normaliserows($cohortmembers, ['cohortid', 'userid']),
                'enrolments' => $normaliserows($enrolments, ['userid', 'status']),
                'roles' => $normaliserows($roles, ['userid', 'roleid', 'component', 'itemid']),
                'groupings' => array_map(static fn(\stdClass $row): array => [
                    (int) $row->id,
                    (string) $row->idnumber,
                    (string) $row->name,
                ], array_values($groupings)),
                'groups' => array_map(static fn(\stdClass $row): array => [
                    (int) $row->id,
                    (string) $row->idnumber,
                    (string) $row->name,
                ], array_values($groups)),
                'groupinglinks' => $normaliserows($groupinglinks, ['groupingid', 'groupid']),
                'groupmembers' => $normaliserows($groupmembers, ['groupid', 'userid']),
                'stagedmembers' => array_map(static fn(\stdClass $row): array => [
                    (int) $row->groupid,
                    (int) $row->userid,
                ], array_values($stagedmembers)),
                'scopes' => $normaliserows($scopes, ['userid', 'deliveryids', 'revision']),
                'coursestate' => $coursestate ? [
                    (int) $coursestate->ready,
                    (int) $coursestate->lastcmid,
                ] : null,
            ];
        };

        $before = $snapshot();
        $invalidpayloads = [
            'non-positive cohort member' => array_merge($payload, [
                'name' => '[Delivery] Must Not Change',
                'userids' => [(int) $studenttwo->id, -7],
            ]),
            'non-positive course id' => array_merge($payload, [
                'name' => '[Delivery] Must Not Change',
                'userids' => [(int) $studenttwo->id],
                'courseids' => [(int) $course->id, 1],
            ]),
            'non-positive teacher id' => array_merge($payload, [
                'name' => '[Delivery] Must Not Change',
                'userids' => [(int) $studenttwo->id],
                'teacher_userids' => [(int) $teacher->id, 0],
            ]),
            'unmanaged delivery group member' => array_merge($payload, [
                'name' => '[Delivery] Must Not Change',
                'userids' => [(int) $studenttwo->id],
                'groups' => [
                    array_merge($payload['groups'][0], [
                        'name' => '[Delivery Group] Must Not Change',
                        'userids' => [(int) $unmanaged->id],
                    ]),
                    $payload['groups'][1],
                ],
            ]),
            'invalid grouping identity' => array_merge($payload, [
                'name' => '[Delivery] Must Not Change',
                'userids' => [(int) $studenttwo->id],
                'grouping_idnumber' => 'rtc-invalid-grouping',
                'groups' => [],
            ]),
            'duplicate child identity' => array_merge($payload, [
                'name' => '[Delivery] Must Not Change',
                'userids' => [(int) $studenttwo->id],
                'groups' => [$payload['groups'][0], $payload['groups'][0]],
            ]),
            'child group owned by another delivery' => array_merge($payload, [
                'idnumber' => 'rtc-delivery:9902',
                'name' => '[Delivery] Must Not Change',
                'delivery_id' => 9902,
                'userids' => [(int) $studenttwo->id],
                'grouping_idnumber' => 'rtc-delivery-grouping:9902',
                'grouping_name' => '[Delivery] Must Not Change',
                'groups' => [array_merge($payload['groups'][1], [
                    'name' => '[Legacy Delivery Group] Must Not Change',
                    'userids' => [(int) $studenttwo->id],
                ])],
            ]),
        ];
        foreach ($invalidpayloads as $label => $invalidpayload) {
            try {
                \local_rtcsync_external::upsert_class($invalidpayload);
                $this->fail('Expected invalid roster case to be rejected: ' . $label);
            } catch (\invalid_parameter_exception $exception) {
                $this->assertNotSame('', $exception->getMessage(), $label);
            }
            $this->assertSame($before, $snapshot(), $label . ' must not partially mutate sync state');
        }

        $stale = \local_rtcsync_external::sync_student_delivery_scope([
            'courseid' => (int) $course->id,
            'userid' => (int) $studentone->id,
            'deliveryids' => [],
            'revision' => $revision - 1,
        ]);
        $this->assertSame(0, $stale['applied']);
        $this->assertSame([9901], $stale['deliveryids']);
        $this->assertSame($before, $snapshot(), 'A stale delivery snapshot must retain the roster and scope.');

        \local_rtcsync_external::upsert_class($payload);
        $replay = \local_rtcsync_external::sync_student_delivery_scope([
            'courseid' => (int) $course->id,
            'userid' => (int) $studentone->id,
            'deliveryids' => [9901],
            'revision' => $revision,
        ]);
        $this->assertSame(0, $replay['applied']);
        \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $studentone->id,
            'role_shortname' => 'student',
            'suspend' => 0,
        ]);
        $this->assertSame($before, $snapshot(), 'Replaying the complete roster and scope must be idempotent.');
    }

    public function test_delivery_owned_child_group_has_scoped_identity_and_survives_class_cleanup(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enableavailability', 1);
        $course = $this->createManagedCourse(['idnumber' => 'rtc-subject:delivery-roster-ownership']);
        $classuser = $this->createManagedUser();
        $deliveryuser = $this->createManagedUser();
        $staleclassuser = $this->createManagedUser();
        foreach ([$classuser, $deliveryuser, $staleclassuser] as $user) {
            \local_rtcsync_external::enrol_user([
                'courseid' => (int) $course->id,
                'userid' => (int) $user->id,
                'role_shortname' => 'student',
                'suspend' => 0,
            ]);
        }

        $classpayload = [
            'idnumber' => 'rtc-class:roster-owner',
            'name' => '[Class] Roster Owner',
            'visible' => 1,
            'userids' => [(int) $classuser->id, (int) $deliveryuser->id, (int) $staleclassuser->id],
            'courseids' => [(int) $course->id],
            'grouping_idnumber' => 'rtc-class-grouping:roster-owner',
            'grouping_name' => '[Class] Roster Owner',
            'groups' => [[
                'idnumber' => 'rtc-class-group:37',
                'name' => '[Group] 37',
                'userids' => [(int) $classuser->id],
            ]],
        ];
        \local_rtcsync_external::upsert_class($classpayload);

        $deliverypayload = [
            'idnumber' => 'rtc-delivery:7037',
            'name' => '[Delivery] Roster Owner',
            'delivery_id' => 7037,
            'visible' => 1,
            'userids' => [(int) $classuser->id, (int) $deliveryuser->id, (int) $staleclassuser->id],
            'courseids' => [(int) $course->id],
            'grouping_idnumber' => 'rtc-delivery-grouping:7037',
            'grouping_name' => '[Delivery] Roster Owner',
            'groups' => [[
                'idnumber' => 'rtc-delivery-class-group:7037:37',
                'name' => '[Delivery Group] 37',
                'userids' => [(int) $deliveryuser->id],
            ]],
        ];
        \local_rtcsync_external::upsert_class($deliverypayload);

        // A later class sync with an old/stale cohort roster must not rewrite
        // the delivery-owned child roster. The shared SMS child ID maps to a
        // separate Moodle group for each owner.
        \local_rtcsync_external::upsert_class(array_merge($classpayload, [
            'groups' => [[
                'idnumber' => 'rtc-class-group:37',
                'name' => '[Group] 37',
                'userids' => [(int) $staleclassuser->id],
            ]],
        ]));

        // Delivery rosters are staged and only applied for an active Student whose effective
        // scope includes the delivery. Grouping syncs fail closed and suspend managed Students,
        // so authorize the delivery scope and reactivate the delivery learner like the backend does.
        \local_rtcsync_external::sync_student_delivery_scope([
            'courseid' => (int) $course->id,
            'userid' => (int) $deliveryuser->id,
            'deliveryids' => [7037],
            'revision' => 20261025000000000,
        ]);
        \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $deliveryuser->id,
            'role_shortname' => 'student',
            'suspend' => 0,
        ]);

        $classgroup = $DB->get_record('groups', [
            'courseid' => (int) $course->id,
            'idnumber' => 'rtc-class-group:37',
        ], '*', MUST_EXIST);
        $deliverygroup = $DB->get_record('groups', [
            'courseid' => (int) $course->id,
            'idnumber' => 'rtc-delivery-class-group:7037:37',
        ], '*', MUST_EXIST);
        $this->assertSame([(int) $staleclassuser->id], array_map('intval', array_keys($DB->get_records(
            'groups_members', ['groupid' => (int) $classgroup->id], '', 'userid'
        ))));
        $this->assertSame([(int) $deliveryuser->id], array_map('intval', array_keys($DB->get_records(
            'groups_members', ['groupid' => (int) $deliverygroup->id], '', 'userid'
        ))));

        // Exercise a legacy shared class-group ID during migration. Delivery
        // sync owns its roster while linked; a subsequent class payload with
        // a stale roster must leave that delivery roster untouched.
        $legacypayload = array_merge($deliverypayload, [
            'idnumber' => 'rtc-delivery:7038',
            'delivery_id' => 7038,
            'grouping_idnumber' => 'rtc-delivery-grouping:7038',
            'grouping_name' => '[Delivery] Legacy roster owner',
            'groups' => [[
                'idnumber' => 'rtc-class-group:37',
                'name' => '[Group] 37',
                'userids' => [(int) $deliveryuser->id],
            ]],
        ]);
        \local_rtcsync_external::upsert_class($legacypayload);
        \local_rtcsync_external::upsert_class(array_merge($classpayload, [
            'groups' => [[
                'idnumber' => 'rtc-class-group:37',
                'name' => '[Group] 37',
                'userids' => [(int) $staleclassuser->id],
            ]],
        ]));
        $this->assertSame([(int) $deliveryuser->id], array_map('intval', array_keys($DB->get_records(
            'groups_members', ['groupid' => (int) $classgroup->id], '', 'userid'
        ))));
        $sharedlegacygrouprejected = false;
        try {
            \local_rtcsync_external::upsert_class(array_merge($legacypayload, [
                'idnumber' => 'rtc-delivery:7039',
                'delivery_id' => 7039,
                'grouping_idnumber' => 'rtc-delivery-grouping:7039',
                'grouping_name' => '[Delivery] Must use a scoped group',
            ]));
        } catch (\invalid_parameter_exception $exception) {
            $sharedlegacygrouprejected = str_contains($exception->getMessage(), 'cannot be shared by multiple delivery groupings');
        }
        $this->assertTrue($sharedlegacygrouprejected);

        // Retiring the legacy delivery detaches its link without deleting or
        // clearing the class-owned child group and its preserved roster.
        \local_rtcsync_external::upsert_class(array_merge($legacypayload, [
            'courseids' => [],
            'groups' => [],
        ]));
        $classgrouping = $DB->get_record('groupings', [
            'courseid' => (int) $course->id,
            'idnumber' => 'rtc-class-grouping:roster-owner',
        ], '*', MUST_EXIST);
        $this->assertTrue($DB->record_exists('groupings_groups', [
            'groupingid' => (int) $classgrouping->id,
            'groupid' => (int) $classgroup->id,
        ]));
        $this->assertTrue($DB->record_exists('groups_members', [
            'groupid' => (int) $classgroup->id,
            'userid' => (int) $deliveryuser->id,
        ]));

        // Even a stale cross-link from a legacy class grouping must not let
        // class cleanup delete a delivery-owned group or its roster.
        $deliverygrouping = $DB->get_record('groupings', [
            'courseid' => (int) $course->id,
            'idnumber' => 'rtc-delivery-grouping:7037',
        ], '*', MUST_EXIST);
        groups_assign_grouping((int) $classgrouping->id, (int) $deliverygroup->id);
        \local_rtcsync_external::upsert_class(array_merge($classpayload, [
            'courseids' => [],
            'groups' => [],
        ]));

        $this->assertTrue($DB->record_exists('groups', ['id' => (int) $deliverygroup->id]));
        $this->assertTrue($DB->record_exists('groupings_groups', [
            'groupingid' => (int) $deliverygrouping->id,
            'groupid' => (int) $deliverygroup->id,
        ]));
        $this->assertTrue($DB->record_exists('groups_members', [
            'groupid' => (int) $deliverygroup->id,
            'userid' => (int) $deliveryuser->id,
        ]));

        // Archiving the delivery now empties its scoped roster but keeps the
        // delivery grouping shell; the group object is deleted because no
        // module availability condition references it.
        \local_rtcsync_external::upsert_class(array_merge($deliverypayload, ['visible' => 0]));
        $this->assertFalse($DB->record_exists('groups', ['id' => (int) $deliverygroup->id]));
        $this->assertTrue($DB->record_exists('groupings', [
            'courseid' => (int) $course->id,
            'idnumber' => 'rtc-delivery-grouping:7037',
        ]));
    }

    public function test_class_payload_accepts_delivery_identity_metadata(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse();

        $result = \local_rtcsync_external::upsert_class([
            'idnumber' => 'rtc-delivery:metadata:class:1',
            'name' => '[Class] Delivery Metadata',
            'visible' => 1,
            'delivery_id' => 17,
            'userids' => [],
            'courseids' => [(int) $course->id],
            'grouping_idnumber' => 'rtc-delivery-grouping:17',
            'grouping_name' => '[Class] Delivery Metadata',
            'groups' => [],
        ]);

        $this->assertSame('rtc-delivery:metadata:class:1', $result['idnumber']);
        $this->assertSame(0, $result['member_count']);
    }

    public function test_delivery_class_sync_rejects_ambiguous_grouping_before_cohort_mutation(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse(['idnumber' => 'rtc-subject:ambiguous-delivery-class']);
        $this->createDeliveryGrouping($course, 718);
        $this->createDuplicateDeliveryGrouping($course, 718);

        try {
            \local_rtcsync_external::upsert_class([
                'idnumber' => 'rtc-delivery:718',
                'name' => '[Delivery] Ambiguous Grouping',
                'visible' => 1,
                'delivery_id' => 718,
                'userids' => [],
                'courseids' => [(int) $course->id],
                'grouping_idnumber' => 'rtc-delivery-grouping:718',
                'grouping_name' => '[Delivery] 718',
                'groups' => [],
            ]);
            $this->fail('Delivery roster reconciliation must reject an ambiguous target before writing its cohort.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString(
                'The RTC delivery grouping idnumber is ambiguous in this course; no changes were made.',
                $exception->getMessage()
            );
        }

        $this->assertFalse($DB->record_exists('cohort', [
            'contextid' => \context_system::instance()->id,
            'idnumber' => 'rtc-delivery:718',
        ]));
    }

    public function test_class_payload_accepts_managed_isolated_credit_courses(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse(['idnumber' => 'rtc-credit-course:class-credit']);

        $result = \local_rtcsync_external::upsert_class([
            'idnumber' => 'rtc-class:credit-course',
            'name' => '[Class] Credit Course',
            'visible' => 1,
            'userids' => [],
            'courseids' => [(int) $course->id],
            'grouping_idnumber' => 'rtc-class-grouping:credit-course',
            'grouping_name' => '[Class] Credit Course',
            'groups' => [[
                'idnumber' => 'rtc-class-group:credit-course',
                'name' => '[Group] Credit Course',
                'userids' => [],
            ]],
        ]);

        $this->assertSame(1, $result['course_count']);
    }

    public function test_class_teacher_role_change_removes_stale_managed_role_assignment(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse();
        $teacher = $this->createManagedUser();
        $payload = [
            'idnumber' => 'rtc-class:role-change',
            'name' => '[Class] Role Change',
            'description' => 'Managed class role transition.',
            'visible' => 1,
            'userids' => [],
            'courseids' => [(int) $course->id],
            'grouping_idnumber' => 'rtc-class-grouping:role-change',
            'grouping_name' => '[Class] Role Change',
            'groups' => [],
            'teacher_userids' => [(int) $teacher->id],
            'student_role_shortname' => 'student',
        ];

        \local_rtcsync_external::upsert_class($payload + [
            'teacher_role_shortname' => 'editingteacher',
        ]);

        $cohort = $DB->get_record('cohort', [
            'idnumber' => 'rtc-class:role-change',
        ], '*', MUST_EXIST);
        $context = \context_course::instance((int) $course->id);
        $editingteacher = $DB->get_record('role', ['shortname' => 'editingteacher'], '*', MUST_EXIST);
        $teacherrole = $DB->get_record('role', ['shortname' => 'teacher'], '*', MUST_EXIST);
        $this->assertTrue($DB->record_exists('role_assignments', [
            'roleid' => (int) $editingteacher->id,
            'userid' => (int) $teacher->id,
            'contextid' => $context->id,
            'component' => 'local_rtcsync',
            'itemid' => $cohort->id,
        ]));

        \local_rtcsync_external::upsert_class($payload + [
            'teacher_role_shortname' => 'teacher',
        ]);

        $this->assertFalse($DB->record_exists('role_assignments', [
            'roleid' => (int) $editingteacher->id,
            'userid' => (int) $teacher->id,
            'contextid' => $context->id,
            'component' => 'local_rtcsync',
            'itemid' => $cohort->id,
        ]));
        $this->assertTrue($DB->record_exists('role_assignments', [
            'roleid' => (int) $teacherrole->id,
            'userid' => (int) $teacher->id,
            'contextid' => $context->id,
            'component' => 'local_rtcsync',
            'itemid' => $cohort->id,
        ]));
    }

    public function test_class_managed_state_exposes_course_role_assignments(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse();
        $teacher = $this->createManagedUser();
        $student = $this->createManagedUser();

        \local_rtcsync_external::upsert_class([
            'idnumber' => 'rtc-class:managed-state-roles',
            'name' => '[Class] Managed State Roles',
            'description' => 'Managed class role read-back.',
            'visible' => 1,
            'userids' => [(int) $student->id],
            'courseids' => [(int) $course->id],
            'grouping_idnumber' => 'rtc-class-grouping:managed-state-roles',
            'grouping_name' => '[Class] Managed State Roles',
            'groups' => [],
            'teacher_userids' => [(int) $teacher->id],
            'teacher_role_shortname' => 'editingteacher',
            'student_role_shortname' => 'student',
        ]);

        $state = \local_rtcsync_external::get_managed_state(
            'classes',
            ['rtc-class:managed-state-roles'],
            0,
            100
        );
        $roles = json_decode(
            $state['records'][0]['class_role_assignments'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        sort($roles, SORT_STRING);
        $expected = [
            $course->id . ':' . $student->id . ':student',
            $course->id . ':' . $teacher->id . ':editingteacher',
        ];
        sort($expected, SORT_STRING);

        $this->assertSame($expected, $roles);
    }
}
