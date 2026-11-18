<?php

namespace local_rtcsync;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

defined('MOODLE_INTERNAL') || die();

/**
 * MySQL lock factory that records whether a course-access lock overlaps a DB transaction.
 *
 * @package    local_rtcsync
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrolment_lock_lifetime_mysql_factory extends \core\lock\mysql_lock_factory
{
    /** @var array<bool> Transaction state when a course-access lock is acquired. */
    public static array $courseaccessacquiretransactionstates = [];

    /** @var array<bool> Transaction state when a course-access lock is released. */
    public static array $courseaccessreleasetransactionstates = [];

    /** @var string */
    private string $locktype;

    public function __construct($type)
    {
        $this->locktype = (string) $type;
        parent::__construct($type);
    }

    public function get_lock($resource, $timeout, $maxlifetime = 86400)
    {
        global $DB;

        if ($this->locktype === 'local_rtcsync_delivery_access') {
            self::$courseaccessacquiretransactionstates[] = $DB->is_transaction_started();
        }

        return parent::get_lock($resource, $timeout, $maxlifetime);
    }

    public function release_lock(\core\lock\lock $lock)
    {
        global $DB;

        if ($this->locktype === 'local_rtcsync_delivery_access') {
            self::$courseaccessreleasetransactionstates[] = $DB->is_transaction_started();
        }

        return parent::release_lock($lock);
    }
}

/** Verify marker rejection and source-scoped clear lock lifetimes in enrolment APIs. */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class enrol_user_lock_lifetime_test extends \advanced_testcase
{
    private bool $originalfactorywasconfigured = false;

    /** @var class-string|null */
    private ?string $originalfactory = null;

    public function test_rejected_non_subject_marker_rolls_back_without_changing_enrolment_or_role(): void
    {
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->dirroot . '/local/rtcsync/externallib.php');

        [$course, $user] = $this->create_managed_course_and_user('rtc-credit-course:lock-lifetime');
        $manual = enrol_get_plugin('manual');
        $manual->add_default_instance($course);
        $instanceid = $this->manual_enrolment_instance_id((int) $course->id);
        $contextid = \context_course::instance((int) $course->id)->id;
        $enrolmentsbefore = $DB->get_records('user_enrolments', [
            'enrolid' => $instanceid,
            'userid' => (int) $user->id,
        ], 'id ASC');
        $rolesbefore = $DB->get_records('role_assignments', [
            'contextid' => $contextid,
            'userid' => (int) $user->id,
        ], 'id ASC');
        $this->install_lock_state_capture($CFG);

        try {
            try {
                \local_rtcsync_external::enrol_user([
                    'courseid' => (int) $course->id,
                    'userid' => (int) $user->id,
                    'role_shortname' => 'student',
                    'suspend' => 1,
                    'historyaccess' => 1,
                    'historysourcetype' => 'user_program',
                    'historysourceid' => 700001,
                ]);
                $this->fail('History marker creation on a non-subject course must be rejected.');
            } catch (\invalid_parameter_exception $exception) {
                $this->assertStringContainsString('restricted to RTC subject courses', $exception->getMessage());
            }

            $this->assertFalse($DB->is_transaction_started());
            $this->assertFalse($DB->record_exists('local_rtcsync_history', [
                'userid' => (int) $user->id,
                'courseid' => (int) $course->id,
                'sourcetype' => 'user_program',
                'sourceid' => 700001,
            ]));
            $this->assertEquals($enrolmentsbefore, $DB->get_records('user_enrolments', [
                'enrolid' => $instanceid,
                'userid' => (int) $user->id,
            ], 'id ASC'));
            $this->assertEquals($rolesbefore, $DB->get_records('role_assignments', [
                'contextid' => $contextid,
                'userid' => (int) $user->id,
            ], 'id ASC'));
            $this->assert_course_lock_released_outside_transaction();
        } finally {
            $this->restore_lock_factory($CFG);
        }
    }

    public function test_subject_course_source_clear_holds_lock_through_commit(): void
    {
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->dirroot . '/local/rtcsync/externallib.php');

        [$course, $user] = $this->create_managed_course_and_user('rtc-subject:lock-lifetime');
        [$instanceid, $studentroleid] = $this->enrol_suspended_student($course, $user);
        $sourceid = 700002;
        $this->create_user_program_marker($user, $course, $sourceid);
        $this->install_lock_state_capture($CFG);

        try {
            $result = \local_rtcsync_external::unenrol_user([
                'courseid' => (int) $course->id,
                'userid' => (int) $user->id,
                'role_shortname' => 'student',
                'historyaccess' => 0,
                'historysourcetype' => 'user_program',
                'historysourceid' => $sourceid,
            ]);

            $this->assertSame(1, $result['historyapplied']);
            $this->assertFalse($DB->record_exists('local_rtcsync_history', [
                'userid' => (int) $user->id,
                'courseid' => (int) $course->id,
                'sourcetype' => 'user_program',
                'sourceid' => $sourceid,
            ]));
            $this->assertFalse($DB->record_exists('user_enrolments', [
                'enrolid' => $instanceid,
                'userid' => (int) $user->id,
            ]));
            $this->assertFalse($DB->record_exists('role_assignments', [
                'contextid' => \context_course::instance((int) $course->id)->id,
                'userid' => (int) $user->id,
                'roleid' => $studentroleid,
            ]));
            $this->assert_course_lock_released_outside_transaction();
        } finally {
            $this->restore_lock_factory($CFG);
        }
    }

    public function test_non_subject_source_clear_holds_lock_through_commit_for_cleanup(): void
    {
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->dirroot . '/local/rtcsync/externallib.php');

        [$course, $user] = $this->create_managed_course_and_user('rtc-subject:lock-lifetime-cleanup');
        [$instanceid, $studentroleid] = $this->enrol_suspended_student($course, $user);
        $sourceid = 700003;
        $this->create_user_program_marker($user, $course, $sourceid);
        $DB->set_field('course', 'idnumber', 'rtc-credit-course:lock-lifetime-cleanup', ['id' => (int) $course->id]);
        $this->install_lock_state_capture($CFG);

        try {
            $result = \local_rtcsync_external::unenrol_user([
                'courseid' => (int) $course->id,
                'userid' => (int) $user->id,
                'role_shortname' => 'student',
                'historyaccess' => 0,
                'historysourcetype' => 'user_program',
                'historysourceid' => $sourceid,
            ]);

            $this->assertSame(1, $result['historyapplied']);
            $this->assertFalse($DB->record_exists('local_rtcsync_history', [
                'userid' => (int) $user->id,
                'courseid' => (int) $course->id,
                'sourcetype' => 'user_program',
                'sourceid' => $sourceid,
            ]));
            $this->assertFalse($DB->record_exists('user_enrolments', [
                'enrolid' => $instanceid,
                'userid' => (int) $user->id,
            ]));
            $this->assertFalse($DB->record_exists('role_assignments', [
                'contextid' => \context_course::instance((int) $course->id)->id,
                'userid' => (int) $user->id,
                'roleid' => $studentroleid,
            ]));
            $this->assert_course_lock_released_outside_transaction();
        } finally {
            $this->restore_lock_factory($CFG);
        }
    }

    public function test_mysql_enrol_marker_lock_blocks_competing_connection_until_commit(): void
    {
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        if ($DB->get_dbfamily() !== 'mysql'
                || (string) $CFG->dbhost !== 'phpunit-db'
                || (string) $CFG->dbname !== 'rtc_moodle_phpunit') {
            $this->markTestSkipped('This interleaving test requires the isolated MySQL PHPUnit database.');
        }
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('Independent PHP workers are required for the lock interleaving test.');
        }
        require_once($CFG->dirroot . '/local/rtcsync/externallib.php');

        $raceid = bin2hex(random_bytes(6));
        $course = $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-credit-course:marker-race-' . $raceid,
        ]);
        $manual = enrol_get_plugin('manual');
        $manual->add_default_instance($course);
        $user = $this->getDataGenerator()->create_user();
        $now = time();
        $DB->insert_record('local_rtcsync_user', (object) [
            'userid' => (int) $user->id,
            'idnumber' => (string) ($user->idnumber ?? ''),
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        // Enable history on this managed non-prefix course to exercise the
        // enrol_user() branch used by delivery-aware courses.
        $DB->insert_record('local_rtcsync_deliverycourse', (object) [
            'courseid' => (int) $course->id,
            'coursevisible' => 1,
            'ready' => 0,
            'lastcmid' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $sourceid = random_int(9000000, 9999999);
        $payload = [
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'historysourceid' => $sourceid,
        ];
        $barrierdir = make_temp_directory('local_rtcsync_marker_lock_race');
        $workers = ['writer' => null, 'observer' => null];

        try {
            // Avoid cache configuration initialization racing the independent Moodle bootstraps.
            \core_cache\config::instance();
            $workers['writer'] = $this->start_marker_lock_race_worker($barrierdir, 'writer', $payload);
            $this->wait_for_marker_lock_race_event(
                $barrierdir,
                ['writer.commit-ready'],
                $workers['writer'],
                'writer'
            );

            $commitstate = json_decode(
                (string) file_get_contents($barrierdir . '/writer.commit-ready'),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $this->assertTrue($commitstate['transaction_started']);

            $workers['observer'] = $this->start_marker_lock_race_worker($barrierdir, 'observer', $payload);
            $probeevent = $this->wait_for_marker_lock_race_event(
                $barrierdir,
                ['observer.blocked', 'observer.acquired-before-commit', 'observer.result.json'],
                $workers['observer'],
                'observer'
            );

            file_put_contents($barrierdir . '/writer.commit-release', 'commit', LOCK_EX);
            $writerresult = $this->finish_marker_lock_race_worker($workers['writer'], 'writer');
            if ($probeevent === 'observer.blocked') {
                file_put_contents($barrierdir . '/observer.retry', 'retry', LOCK_EX);
            }
            $observerresult = $this->finish_marker_lock_race_worker($workers['observer'], 'observer');

            $this->assertSame('observer.blocked', $probeevent);
            $this->assertFileExists($barrierdir . '/writer.commit-done');
            $this->assertSame(1, $writerresult['result']['historyapplied']);
            $this->assertSame('after-commit', $observerresult['stage']);
            $this->assertTrue($observerresult['state']['marker_exists']);
            $this->assertTrue($observerresult['state']['enrolment_exists']);
            $this->assertTrue($observerresult['state']['student_role_exists']);
        } finally {
            file_put_contents($barrierdir . '/writer.commit-release', 'cleanup', LOCK_EX);
            file_put_contents($barrierdir . '/observer.retry', 'cleanup', LOCK_EX);
            foreach ($workers as $name => &$worker) {
                if ($worker !== null) {
                    $this->stop_marker_lock_race_worker($worker);
                }
            }
            unset($worker);
            remove_dir($barrierdir);
        }
    }

    /** Create a managed course and user used by the API fixture. */
    private function create_managed_course_and_user(string $idnumber): array
    {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['idnumber' => $idnumber]);
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_rtcsync_user', (object) [
            'userid' => (int) $user->id,
            'idnumber' => (string) ($user->idnumber ?? ''),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        return [$course, $user];
    }

    /** Create a suspended manual Student enrolment and return its enrolment and role IDs. */
    private function enrol_suspended_student(\stdClass $course, \stdClass $user): array
    {
        global $DB;

        $manual = enrol_get_plugin('manual');
        $manual->add_default_instance($course);
        $instance = $DB->get_record('enrol', [
            'courseid' => (int) $course->id,
            'enrol' => 'manual',
        ], '*', MUST_EXIST);
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        $manual->enrol_user($instance, (int) $user->id, (int) $studentrole->id, 0, 0, ENROL_USER_SUSPENDED);

        return [(int) $instance->id, (int) $studentrole->id];
    }

    private function create_user_program_marker(\stdClass $user, \stdClass $course, int $sourceid): void
    {
        $result = \local_rtcsync\local\student_history::sync_marker(
            (int) $user->id,
            (int) $course->id,
            1,
            'user_program',
            $sourceid,
            0
        );
        $this->assertSame(1, $result['applied']);
    }

    private function manual_enrolment_instance_id(int $courseid): int
    {
        global $DB;

        return (int) $DB->get_field('enrol', 'id', [
            'courseid' => $courseid,
            'enrol' => 'manual',
        ], MUST_EXIST);
    }

    private function start_marker_lock_race_worker(string $barrierdir, string $name, array $payload): array
    {
        global $CFG;

        $command = [
            PHP_BINARY,
            __DIR__ . '/fixtures/course_marker_lock_race_worker.php',
            base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
            $name,
            $barrierdir,
            (string) $CFG->prefix,
            (string) $CFG->dataroot,
            (string) $CFG->wwwroot,
        ];
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start a marker lock race worker.');
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

    private function wait_for_marker_lock_race_event(
        string $barrierdir,
        array $events,
        array &$worker,
        string $name
    ): string {
        $deadline = microtime(true) + 60;
        do {
            foreach ($events as $event) {
                if (is_file($barrierdir . '/' . $event)) {
                    return $event;
                }
            }
            $status = proc_get_status($worker['process']);
            if (!$status['running']) {
                $stdout = stream_get_contents($worker['pipes'][1]);
                $stderr = stream_get_contents($worker['pipes'][2]);
                $this->fail('Marker lock race worker ' . $name . ' exited before its barrier (exit '
                    . $status['exitcode'] . '). Output: ' . $stdout . "\n" . $stderr);
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        $stdout = stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        $this->fail('Marker lock race worker ' . $name . ' did not reach the expected barrier. Output: '
            . $stdout . "\n" . $stderr);
    }

    private function finish_marker_lock_race_worker(array &$worker, string $name): array
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
            $this->fail('Marker lock race worker ' . $name . ' did not finish in time.');
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
        $this->assertFileExists($worker['resultpath']);
        $result = json_decode((string) file_get_contents($worker['resultpath']), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($result);
        $workerdiagnostics = 'Worker ' . $name . ' exited ' . $exitcode
            . '; result=' . json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . '; stdout=' . $stdout . '; stderr=' . $stderr;
        $this->assertSame(0, $exitcode, $workerdiagnostics);
        if ($name === 'writer') {
            $this->assertArrayHasKey('result', $result, $workerdiagnostics);
        } else {
            $this->assertArrayHasKey('stage', $result, $workerdiagnostics);
            $this->assertArrayHasKey('state', $result, $workerdiagnostics);
        }

        return $result;
    }

    private function stop_marker_lock_race_worker(array &$worker): void
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

    private function install_lock_state_capture(\stdClass $CFG): void
    {
        $this->originalfactorywasconfigured = property_exists($CFG, 'lock_factory');
        $this->originalfactory = $CFG->lock_factory ?? null;
        enrolment_lock_lifetime_mysql_factory::$courseaccessacquiretransactionstates = [];
        enrolment_lock_lifetime_mysql_factory::$courseaccessreleasetransactionstates = [];
        $CFG->lock_factory = enrolment_lock_lifetime_mysql_factory::class;
    }

    private function assert_course_lock_released_outside_transaction(): void
    {
        $this->assertSame([false], enrolment_lock_lifetime_mysql_factory::$courseaccessacquiretransactionstates);
        $this->assertSame([false], enrolment_lock_lifetime_mysql_factory::$courseaccessreleasetransactionstates);
    }

    private function restore_lock_factory(\stdClass $CFG): void
    {
        if ($this->originalfactorywasconfigured) {
            $CFG->lock_factory = $this->originalfactory;
        } else {
            unset($CFG->lock_factory);
        }
    }
}
