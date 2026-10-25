<?php
// Isolated configuration mounted only into the opt-in PHPUnit runner.

unset($CFG);
global $CFG;
$CFG = new stdClass();

$phpunitdbhost = getenv('MOODLE_PHPUNIT_DB_HOST');
$phpunitdbname = getenv('MOODLE_PHPUNIT_DB_NAME');
$phpunitdbuser = getenv('MOODLE_PHPUNIT_DB_USER');
$phpunitdbpass = getenv('MOODLE_PHPUNIT_DB_PASSWORD');

if ($phpunitdbhost !== 'phpunit-db'
        || $phpunitdbname !== 'rtc_moodle_phpunit'
        || $phpunitdbuser !== 'rtc_moodle_phpunit'
        || !is_string($phpunitdbpass)
        || !preg_match('/\A[a-f0-9]{64}\z/i', $phpunitdbpass)) {
    throw new RuntimeException('Isolated PHPUnit DB settings are missing or do not identify the dedicated local test database.');
}

$CFG->dbtype = 'mysqli';
$CFG->dblibrary = 'native';
$CFG->dbhost = 'phpunit-db';
$CFG->dbname = 'rtc_moodle_phpunit';
$CFG->dbuser = 'rtc_moodle_phpunit';
$CFG->dbpass = $phpunitdbpass;
$CFG->prefix = 'mdl_';
$CFG->dboptions = [
    'dbpersist' => 0,
    'dbport' => 3306,
    'dbsocket' => '',
    'dbcollation' => 'utf8mb4_0900_ai_ci',
];

$CFG->wwwroot = 'http://moodle-phpunit.local';
$CFG->dataroot = '/var/moodledata';
$CFG->admin = 'admin';

$CFG->phpunit_dbtype = 'mysqli';
$CFG->phpunit_dblibrary = 'native';
$CFG->phpunit_dbhost = 'phpunit-db';
$CFG->phpunit_dbname = 'rtc_moodle_phpunit';
$CFG->phpunit_dbuser = 'rtc_moodle_phpunit';
$CFG->phpunit_dbpass = $phpunitdbpass;
$CFG->phpunit_dboptions = $CFG->dboptions;
$CFG->phpunit_prefix = 'phpu_';
$CFG->phpunit_dataroot = '/var/moodledata/phpunit';

$CFG->directorypermissions = 02777;

require_once(__DIR__ . '/lib/setup.php');
