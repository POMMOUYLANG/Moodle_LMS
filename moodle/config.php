<?php
unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->wwwroot   = 'http://rtc-bb.camai.kh/moodle';
$CFG->dataroot  = '/var/www/moodledata';

$CFG->dbtype    = 'mysqli';
$CFG->dblibrary = 'native';
$CFG->dbhost    = 'moodle-db';
$CFG->dbname    = 'moodle_lms';
$CFG->dbuser    = 'root';
$CFG->dbpass    = 'Root@123';
$CFG->prefix    = 'mdl_';
$CFG->directorypermissions = 0777;

require_once(__DIR__ . '/lib/setup.php');
