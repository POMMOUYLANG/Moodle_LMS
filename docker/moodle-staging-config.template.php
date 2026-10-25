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

$CFG->wwwroot = 'https://' . @@MOODLE_HOSTNAME@@;
$CFG->sslproxy = true;
$CFG->reverseproxy = false;
$CFG->dataroot = '/var/moodledata';
$CFG->dirroot = '/var/www/html';
$CFG->admin = 'admin';
$CFG->directorypermissions = 02770;
$CFG->passwordsaltmain = @@PASSWORD_SALT@@;

// Pin SSO requests to the explicitly configured staging API. The ordinary
// RTC SSO fallback hostname is not attached to this isolated Compose network.
putenv('RTC_API_BASE_URL=' . @@RTC_API_BASE_URL@@);
putenv('GATEWAY_API_DOMAIN=' . @@RTC_API_HOSTNAME@@);
putenv('GATEWAY_MOODLE_DOMAIN=' . @@MOODLE_HOSTNAME@@);
putenv('RTC_SSO_SITE_REGISTRY=');
putenv('CONTAINER_PREFIX=');

require_once(__DIR__ . '/lib/setup.php');
