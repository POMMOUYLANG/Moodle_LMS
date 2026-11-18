<?php

declare(strict_types=1);

define('CLI_SCRIPT', true);

$payload = json_decode(base64_decode($argv[1] ?? '', true) ?: '', true, 512, JSON_THROW_ON_ERROR);
$workername = (string) ($argv[2] ?? '');
$barrierdir = (string) ($argv[3] ?? '');
$dataroot = (string) ($argv[5] ?? '');
if (!in_array($workername, ['writer', 'observer'], true) || !is_dir($barrierdir)) {
    throw new RuntimeException('Invalid marker lock race worker identity or barrier directory.');
}

$moodleroot = dirname(__DIR__, 4);
$configsource = is_file($moodleroot . '/config.php')
    ? file_get_contents($moodleroot . '/config.php')
    : false;
if (!is_string($configsource) || strpos($configsource, '$CFG->phpunit_prefix') === false) {
    // Moodle 4.5+ keeps config.php above its public web root.
    $moodleroot = dirname($moodleroot);
    $configsource = is_file($moodleroot . '/config.php')
        ? file_get_contents($moodleroot . '/config.php')
        : false;
}
if (!is_string($configsource)) {
    throw new RuntimeException('Could not read the isolated PHPUnit configuration for the marker lock worker.');
}

$runtimeconfig = [
    'prefix' => (string) ($argv[4] ?? ''),
    'dataroot' => $dataroot,
    'wwwroot' => (string) ($argv[6] ?? ''),
];
foreach ($runtimeconfig as $name => $value) {
    $pattern = '/\$CFG->' . preg_quote($name, '/') . '[ \t]*=[^\r\n]*;/';
    $replacement = '$CFG->' . $name . ' = ' . var_export($value, true) . ';';
    $configsource = preg_replace($pattern, $replacement, $configsource, 1, $replacements);
    if (!is_string($configsource) || $replacements !== 1) {
        throw new RuntimeException('Could not apply the PHPUnit ' . $name . ' setting to the marker lock worker.');
    }
}

$workerconfigid = preg_replace('/[^a-z0-9_-]/i', '', basename($barrierdir)) . '-' . $workername;
$workerconfig = $moodleroot . '/config-rtc-marker-race-' . $workerconfigid . '.php';
if (file_put_contents($workerconfig, $configsource, LOCK_EX) === false) {
    throw new RuntimeException('Could not create the marker lock worker PHPUnit configuration.');
}
try {
    require($workerconfig);
} finally {
    @unlink($workerconfig);
}

global $CFG, $DB;

require_once($CFG->dirroot . '/local/rtcsync/externallib.php');
\core\session\manager::set_user(get_admin());

/** Writer factory signals after it has acquired the production course lock. */
final class local_rtcsync_marker_race_writer_lock_factory extends \core\lock\mysql_lock_factory
{
    public static string $barrierdir;

    public static string $targetresource;

    public function get_lock($resource, $timeout, $maxlifetime = 86400)
    {
        $lock = parent::get_lock($resource, $timeout, $maxlifetime);
        if ((string) $resource === self::$targetresource && $lock) {
            file_put_contents(self::$barrierdir . '/writer.lock-acquired', 'locked', LOCK_EX);
        }
        return $lock;
    }
}

/** Observer probes once with a zero-second GET_LOCK timeout, then retries normally. */
final class local_rtcsync_marker_race_observer_lock_factory extends \core\lock\mysql_lock_factory
{
    public static string $barrierdir;

    public static string $targetresource;

    private bool $probed = false;

    public function get_lock($resource, $timeout, $maxlifetime = 86400)
    {
        if (!$this->probed && (string) $resource === self::$targetresource) {
            $this->probed = true;
            $lock = parent::get_lock($resource, 0, $maxlifetime);
            $event = $lock ? 'observer.acquired-before-commit' : 'observer.blocked';
            file_put_contents(self::$barrierdir . '/' . $event, 'probe', LOCK_EX);
            return $lock;
        }
        return parent::get_lock($resource, $timeout, $maxlifetime);
    }
}

local_rtcsync_marker_race_writer_lock_factory::$barrierdir = $barrierdir;
local_rtcsync_marker_race_writer_lock_factory::$targetresource = 'course:' . (int) $payload['courseid'];
local_rtcsync_marker_race_observer_lock_factory::$barrierdir = $barrierdir;
local_rtcsync_marker_race_observer_lock_factory::$targetresource = 'course:' . (int) $payload['courseid'];
$CFG->lock_factory = $workername === 'writer'
    ? local_rtcsync_marker_race_writer_lock_factory::class
    : local_rtcsync_marker_race_observer_lock_factory::class;

/** Pause the writer's real transaction immediately before the delegated commit. */
final class local_rtcsync_marker_race_transaction
{
    /** @var object */
    private $transaction;

    /** @var object */
    private $database;

    /** @var string */
    private $barrierdir;

    public function __construct($transaction, $database, string $barrierdir)
    {
        $this->transaction = $transaction;
        $this->database = $database;
        $this->barrierdir = $barrierdir;
    }

    public function allow_commit()
    {
        file_put_contents($this->barrierdir . '/writer.commit-ready', json_encode([
            'transaction_started' => $this->database->is_transaction_started(),
        ], JSON_THROW_ON_ERROR), LOCK_EX);

        $deadline = microtime(true) + 90;
        while (!is_file($this->barrierdir . '/writer.commit-release') && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (!is_file($this->barrierdir . '/writer.commit-release')) {
            $this->transaction->rollback(new RuntimeException('Parent did not release the marker writer commit barrier.'));
            throw new RuntimeException('Parent did not release the marker writer commit barrier.');
        }

        $result = $this->transaction->allow_commit();
        file_put_contents($this->barrierdir . '/writer.commit-done', 'committed', LOCK_EX);
        return $result;
    }

    public function rollback($exception = null)
    {
        return $this->transaction->rollback($exception);
    }

    public function __call(string $name, array $arguments)
    {
        return call_user_func_array([$this->transaction, $name], $arguments);
    }
}

/** Minimal database proxy that wraps only the transaction started under the writer lock. */
final class local_rtcsync_marker_race_database
{
    /** @var object */
    private $database;

    /** @var string */
    private $barrierdir;

    /** @var bool */
    private $wrapped = false;

    public function __construct($database, string $barrierdir)
    {
        $this->database = $database;
        $this->barrierdir = $barrierdir;
    }

    public function __call(string $name, array $arguments)
    {
        $result = call_user_func_array([$this->database, $name], $arguments);
        if (!$this->wrapped && $name === 'start_delegated_transaction'
                && is_file($this->barrierdir . '/writer.lock-acquired')) {
            $this->wrapped = true;
            return new local_rtcsync_marker_race_transaction($result, $this->database, $this->barrierdir);
        }
        return $result;
    }
}

/** Read the marker, enrolment and Student role under the same public course lock. */
function local_rtcsync_marker_race_observe(array $payload): array
{
    global $DB;

    $courseid = (int) $payload['courseid'];
    $userid = (int) $payload['userid'];
    $sourceid = (int) $payload['historysourceid'];
    $instanceid = (int) $DB->get_field('enrol', 'id', [
        'courseid' => $courseid,
        'enrol' => 'manual',
    ], MUST_EXIST);
    $contextid = \context_course::instance($courseid)->id;
    $studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);

    return [
        'marker_exists' => $DB->record_exists('local_rtcsync_history', [
            'userid' => $userid,
            'courseid' => $courseid,
            'sourcetype' => 'user_program',
            'sourceid' => $sourceid,
        ]),
        'enrolment_exists' => $DB->record_exists('user_enrolments', [
            'enrolid' => $instanceid,
            'userid' => $userid,
        ]),
        'student_role_exists' => $DB->record_exists('role_assignments', [
            'contextid' => $contextid,
            'userid' => $userid,
            'roleid' => $studentroleid,
        ]),
    ];
}

$resultpath = $barrierdir . '/' . $workername . '.result.json';
try {
    if ($workername === 'writer') {
        $originaldatabase = $DB;
        $DB = new local_rtcsync_marker_race_database($originaldatabase, $barrierdir);
        $result = \local_rtcsync_external::enrol_user([
            'courseid' => (int) $payload['courseid'],
            'userid' => (int) $payload['userid'],
            'role_shortname' => 'student',
            'suspend' => 1,
            'historyaccess' => 1,
            'historysourcetype' => 'user_program',
            'historysourceid' => (int) $payload['historysourceid'],
        ]);
        file_put_contents($resultpath, json_encode(['result' => $result], JSON_THROW_ON_ERROR), LOCK_EX);
        exit(0);
    }

    try {
        \local_rtcsync\local\delivery_activity_access::with_course_access_lock(
            (int) $payload['courseid'],
            static function() use ($payload, $resultpath): void {
                file_put_contents($resultpath, json_encode([
                    'stage' => 'before-commit',
                    'state' => local_rtcsync_marker_race_observe($payload),
                ], JSON_THROW_ON_ERROR), LOCK_EX);
            }
        );
        throw new RuntimeException('Observer unexpectedly acquired the course lock on its first probe.');
    } catch (moodle_exception $exception) {
        if ($exception->errorcode !== 'locktimeout') {
            throw $exception;
        }
    }

    $deadline = microtime(true) + 90;
    while (!is_file($barrierdir . '/observer.retry') && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (!is_file($barrierdir . '/observer.retry')) {
        throw new RuntimeException('Parent did not release the marker observer retry barrier.');
    }

    \local_rtcsync\local\delivery_activity_access::with_course_access_lock(
        (int) $payload['courseid'],
        static function() use ($payload, $resultpath): void {
            file_put_contents($resultpath, json_encode([
                'stage' => 'after-commit',
                'state' => local_rtcsync_marker_race_observe($payload),
            ], JSON_THROW_ON_ERROR), LOCK_EX);
        }
    );
    exit(0);
} catch (Throwable $exception) {
    file_put_contents($resultpath, json_encode([
        'error' => $exception->getMessage(),
        'type' => get_class($exception),
    ], JSON_THROW_ON_ERROR), LOCK_EX);
    exit(1);
}
