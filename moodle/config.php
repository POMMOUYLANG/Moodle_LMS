<?php  // Moodle configuration file

unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'mysqli';
$CFG->dblibrary = 'native';
$CFG->dbhost    = 'moodle-db';     
$CFG->dbname    = 'moodle_lms';
$CFG->dbuser    = 'moodle';
$CFG->dbpass    = 'Moodle@123';  
$CFG->prefix    = 'mdl_';
$CFG->dboptions = array (
  'dbpersist' => 0,
  'dbport' => 3306,
  'dbsocket' => '',
  'dbcollation' => 'utf8mb4_unicode_ci',
);

$CFG->wwwroot   = 'https://moodle.rtc-bb.camai.kh';
$CFG->dataroot  = '/var/www/moodledata';
$CFG->dirroot   = '/var/www/moodle';
$CFG->admin     = 'admin';

$CFG->directorypermissions = 0777;

require_once(__DIR__ . '/lib/setup.php');


if (isloggedin() && !isguestuser()) {
    redirect($CFG->wwwroot . '/my/');
}

// There is no php closing tag in this file,
// it is intentional because it prevents trailing whitespace problems!
