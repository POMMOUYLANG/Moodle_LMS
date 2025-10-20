<?php
unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'mysqli';
$CFG->dblibrary = 'native';
$CFG->dbhost    = 'moodle-db';
$CFG->dbname    = 'moodle_lms';
$CFG->dbuser    = 'root';
$CFG->dbpass    = 'Root@123';
$CFG->prefix    = 'mdl_';
$CFG->dboptions = [
    'dbpersist' => 0,
    'dbport' => 3306,
    'dbsocket' => '',
    'dbcollation' => 'utf8mb4_unicode_ci',
];

$CFG->wwwroot   = 'http://rtc-bb.camai.kh/moodle';
$CFG->dataroot  = '/var/www/moodledata';
$CFG->admin     = 'admin';
$CFG->directorypermissions = 0777;

require_once(__DIR__ . '/lib/setup.php');
