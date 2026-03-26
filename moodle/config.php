<?php  // Moodle configuration file

unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'mysqli';
$CFG->dblibrary = 'native';
$CFG->dbhost    = getenv('DB_HOST') ?: 'db';
$CFG->dbname    = getenv('MYSQL_DATABASE') ?: 'moodle';
$CFG->dbuser    = getenv('MYSQL_USER') ?: 'moodleuser';
$CFG->dbpass    = getenv('MYSQL_PASSWORD') ?: 'moodlepass';
$CFG->prefix    = 'mdl_';
$CFG->dboptions = array(
  'dbpersist' => 0,
  'dbport' => 3306,
  'dbsocket' => '',
  'dbcollation' => 'utf8mb4_unicode_ci',
);

$domain = getenv('GATEWAY_MOODLE_DOMAIN') ?: 'lms.kc-rtc-edu.com';
$CFG->wwwroot = (strpos($domain, 'http') === 0) ? $domain : 'https://' . $domain;
$CFG->sslproxy  = true;
$CFG->reverseproxy = true;
$CFG->dataroot  = getenv('MOODLE_DATA_ROOT') ?: '/var/moodledata';
$CFG->dirroot   = '/var/www/html';
$CFG->admin     = 'admin';

$CFG->directorypermissions = 0777;

require_once(__DIR__ . '/lib/setup.php');
