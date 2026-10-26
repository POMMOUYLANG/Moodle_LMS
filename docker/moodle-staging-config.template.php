<?php // RTC Moodle staging configuration; generated locally from stage-only settings.

unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype = 'mysqli';
$CFG->dblibrary = 'native';
$CFG->dbhost = 'db';
$CFG->dbname = @@DB_NAME@@;
$CFG->dbuser = @@DB_USER@@;
$CFG->dbpass = @@DB_PASSWORD@@;
$CFG->prefix = 'mdl_';
$CFG->dboptions = [
    'dbpersist' => 0,
    'dbport' => 3306,
    'dbsocket' => '',
    'dbcollation' => 'utf8mb4_unicode_ci',
];

$CFG->wwwroot = @@MOODLE_ORIGIN@@;
$CFG->sslproxy = true;
$CFG->reverseproxy = false;
$CFG->dataroot = '/var/moodledata';
$CFG->dirroot = '/var/www/html';
$CFG->admin = 'admin';
$CFG->directorypermissions = 02770;
$CFG->passwordsaltmain = @@PASSWORD_SALT@@;

// Pin all SSO requests to this exact staging API origin, including its port.
// Do not try legacy gateway or backend hostnames if the configured API fails.
putenv('RTC_API_BASE_URL=' . @@RTC_API_BASE_URL@@);
putenv('RTC_SSO_STRICT_API_BASE_URLS=true');
putenv('GATEWAY_API_DOMAIN=' . @@RTC_API_BASE_URL@@);
putenv('GATEWAY_MOODLE_DOMAIN=' . @@MOODLE_HOSTNAME@@);
putenv('RTC_SSO_SITE_REGISTRY=');
putenv('CONTAINER_PREFIX=');

require_once(__DIR__ . '/lib/setup.php');
