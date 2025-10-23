<?php
unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'mysqli';
$CFG->dblibrary = 'native';
$CFG->dbhost    = 'moodle-db';      // Docker service name
$CFG->dbname    = 'moodle_lms';
$CFG->dbuser    = 'moodle';
$CFG->dbpass    = 'Moodle@123';     // whatever you set in docker-compose.yml
$CFG->wwwroot   = 'https://rtc-bb.camai.kh/moodle';
$CFG->dataroot  = '/var/moodledata';
$CFG->admin     = 'admin';

$CFG->prefix    = 'mdl_';
$CFG->directorypermissions = 0777;

require_once(__DIR__ . '/lib/setup.php');
