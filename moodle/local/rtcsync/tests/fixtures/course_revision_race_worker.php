<?php

declare(strict_types=1);

define('CLI_SCRIPT', true);
$barrierdir = (string) ($argv[2] ?? '');
$workername = (string) ($argv[3] ?? '');
$dataroot = (string) ($argv[5] ?? '');
$lockroot = (string) ($argv[7] ?? '');
if (!in_array($workername, ['higher', 'lower'], true) || !is_dir($barrierdir)) {
    throw new RuntimeException('Invalid course revision race worker identity or barrier directory.');
}
$resolvedlockroot = realpath($lockroot);
$resolveddataroot = realpath($dataroot);
if (!is_string($resolvedlockroot) || !is_string($resolveddataroot) || !is_writable($resolvedlockroot)) {
    throw new RuntimeException('The worker-local Moodle lock directory is unavailable.');
}
$datarootprefix = rtrim($resolveddataroot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
if ($resolvedlockroot === $resolveddataroot || strpos($resolvedlockroot, $datarootprefix) === 0) {
    throw new RuntimeException('The worker-local Moodle lock directory must be outside the PHPUnit dataroot.');
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
    throw new RuntimeException('Could not read the Moodle configuration for the race worker.');
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
        throw new RuntimeException('Could not apply the PHPUnit ' . $name . ' setting to the race worker.');
    }
}
$setupstatement = "require_once(__DIR__ . '/lib/setup.php');";
$lockconfiguration = '$CFG->lock_factory = ' . var_export('\\core\\lock\\file_lock_factory', true) . ";\n"
    . '$CFG->file_lock_root = ' . var_export($resolvedlockroot, true) . ";\n";
$configsource = str_replace($setupstatement, $lockconfiguration . $setupstatement, $configsource, $setupreplacements);
if ($setupreplacements !== 1) {
    throw new RuntimeException('Could not set the worker-local file-lock root before Moodle setup.');
}
$workerconfig = $moodleroot . '/config-rtc-race-' . preg_replace('/[^a-z0-9_-]/i', '', (string) ($argv[3] ?? '')) . '.php';
if (file_put_contents($workerconfig, $configsource, LOCK_EX) === false) {
    throw new RuntimeException('Could not create the race worker PHPUnit configuration.');
}
try {
    require($workerconfig);
} finally {
    @unlink($workerconfig);
}

global $CFG, $DB;

require_once($CFG->dirroot . '/local/rtcsync/externallib.php');

$payload = json_decode(base64_decode($argv[1] ?? '', true) ?: '', true, 512, JSON_THROW_ON_ERROR);
$idnumber = (string) ($payload['idnumber'] ?? '');

// Give each process an independent file-lock root to reproduce separate nodes.
$CFG->lock_factory = '\core\lock\file_lock_factory';
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
