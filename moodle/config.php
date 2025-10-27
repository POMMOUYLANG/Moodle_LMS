<?php
unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'mysqli';
$CFG->dblibrary = 'native';
$CFG->dbhost    = 'moodle-db';     
$CFG->dbname    = 'moodle_lms';
$CFG->dbuser    = 'moodle';
$CFG->dbpass    = 'Moodle@123';      
// $CFG->wwwroot   = 'http://rtc-bb.camai.kh/moodle';
$CFG->wwwroot   = 'https://moodle.rtc-bb.camai.kh';
$CFG->dataroot  = '/var/www/moodledata';

$CFG->admin     = 'admin';

$CFG->prefix    = 'mdl_';

$CFG->directorypermissions = 0777;

require_once(__DIR__ . '/lib/setup.php');
