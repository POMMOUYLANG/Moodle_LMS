<?php

declare(strict_types=1);

define('CLI_SCRIPT', true);
$moodleroot = dirname(__DIR__, 4);
require($moodleroot . '/config.php');

global $CFG, $DB;

require_once($CFG->dirroot . '/local/rtcsync/externallib.php');

$payload = json_decode(base64_decode($argv[1] ?? '', true) ?: '', true, 512, JSON_THROW_ON_ERROR);
$barrierdir = (string) ($argv[2] ?? '');
$workername = (string) ($argv[3] ?? '');
$idnumber = (string) ($payload['idnumber'] ?? '');
$lockroot = $barrierdir . '/locks-' . $workername;
if (!mkdir($lockroot, 0770, true) && !is_dir($lockroot)) {
    throw new RuntimeException('Could not create the worker-local Moodle lock directory.');
}

// Give each process an independent file-lock root to reproduce separate nodes.
$CFG->lock_factory = '\core\lock\file_lock_factory';
$CFG->file_lock_root = $lockroot;
$CFG->preventfilelocking = false;
\core\session\manager::set_user(get_admin());

/** Pause after reading the initial sync row so the parent can order the commits. */
final class local_rtcsync_course_revision_race_barrier_database
{
    /** @var moodle_database */
    private $database;

    /** @var string */
    private $barrierdir;

    /** @var string */
    private $workername;

    /** @var string */
    private $idnumber;

    /** @var bool */
    private $waited = false;

    public function __construct($database, string $barrierdir, string $workername, string $idnumber)
    {
        $this->database = $database;
        $this->barrierdir = $barrierdir;
        $this->workername = $workername;
        $this->idnumber = $idnumber;
    }

    public function __call(string $name, array $arguments)
    {
        $result = call_user_func_array([$this->database, $name], $arguments);
        if ($this->waited || $name !== 'get_record'
                || ($arguments[0] ?? null) !== 'local_rtcsync_coursesync'
                || (($arguments[1]['idnumber'] ?? null) !== $this->idnumber)) {
            return $result;
        }

        $this->waited = true;
        $readyfile = $this->barrierdir . '/' . $this->workername . '.ready';
        $releasefile = $this->barrierdir . '/' . $this->workername . '.release';
        file_put_contents($readyfile, 'ready', LOCK_EX);
        $deadline = microtime(true) + 60;
        while (!is_file($releasefile) && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (!is_file($releasefile)) {
            throw new RuntimeException('The parent did not release the course revision race barrier.');
        }

        return $result;
    }
}

$originaldatabase = $DB;
$DB = new local_rtcsync_course_revision_race_barrier_database(
    $originaldatabase,
    $barrierdir,
    $workername,
    $idnumber
);

$resultpath = $barrierdir . '/' . $workername . '.result.json';
try {
    $result = \local_rtcsync_external::upsert_course($payload);
    file_put_contents($resultpath, json_encode(['result' => $result], JSON_THROW_ON_ERROR), LOCK_EX);
    exit(0);
} catch (Throwable $exception) {
    file_put_contents($resultpath, json_encode([
        'error' => $exception->getMessage(),
        'type' => get_class($exception),
    ], JSON_THROW_ON_ERROR), LOCK_EX);
    exit(1);
}
