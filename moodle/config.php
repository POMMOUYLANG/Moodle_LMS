<?php  // Moodle configuration file

unset($CFG);
global $CFG;
$CFG = new stdClass();

// === Database settings ===
$CFG->dbtype    = 'mysqli';
$CFG->dblibrary = 'native';
$CFG->dbhost    = 'moodle_db';               // Docker service name
$CFG->dbname    = 'moodle_lms';
$CFG->dbuser    = 'root';
$CFG->dbpass    = 'Root@123';
$CFG->prefix    = 'mdl_db_lms';
$CFG->dboptions = array(
  'dbpersist' => 0,
  'dbport' => 3306,
  'dbsocket' => '',
  'dbcollation' => 'utf8mb4_unicode_ci',
);

// === Web and data paths ===
$CFG->wwwroot   = 'https://rtc-bb.camai.kh/moodle';
$CFG->dataroot  = '/var/moodledata';
$CFG->admin     = 'admin';

$CFG->directorypermissions = 0777;

require_once(__DIR__ . '/lib/setup.php');
