<?php

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_rtcsync_upsert_course' => [
        'classname' => 'local_rtcsync_external',
        'methodname' => 'upsert_course',
        'classpath' => 'local/rtcsync/externallib.php',
        'description' => 'Create or update a Moodle course managed by RTC.',
        'type' => 'write',
        'capabilities' => 'moodle/course:create,moodle/course:update,moodle/category:manage',
    ],
    'local_rtcsync_upsert_user' => [
        'classname' => 'local_rtcsync_external',
        'methodname' => 'upsert_user',
        'classpath' => 'local/rtcsync/externallib.php',
        'description' => 'Create or update a Moodle user managed by RTC.',
        'type' => 'write',
        'capabilities' => 'moodle/user:create,moodle/user:update',
    ],
    'local_rtcsync_enrol_user' => [
        'classname' => 'local_rtcsync_external',
        'methodname' => 'enrol_user',
        'classpath' => 'local/rtcsync/externallib.php',
        'description' => 'Enrol an RTC-managed user in an RTC-managed Moodle course.',
        'type' => 'write',
        'capabilities' => 'enrol/manual:enrol',
    ],
    'local_rtcsync_unenrol_user' => [
        'classname' => 'local_rtcsync_external',
        'methodname' => 'unenrol_user',
        'classpath' => 'local/rtcsync/externallib.php',
        'description' => 'Remove an RTC-managed course role enrolment.',
        'type' => 'write',
        'capabilities' => 'enrol/manual:unenrol',
    ],
    'local_rtcsync_upsert_grade' => [
        'classname' => 'local_rtcsync_external',
        'methodname' => 'upsert_grade',
        'classpath' => 'local/rtcsync/externallib.php',
        'description' => 'Create/update an RTC grade item and student grade.',
        'type' => 'write',
        'capabilities' => 'moodle/grade:manage,moodle/grade:edit',
    ],
    'local_rtcsync_get_managed_state' => [
        'classname' => 'local_rtcsync_external',
        'methodname' => 'get_managed_state',
        'classpath' => 'local/rtcsync/externallib.php',
        'description' => 'Read bounded state for explicitly identified RTC-managed records.',
        'type' => 'read',
        'capabilities' => 'local/rtcsync:readmanagedstate',
    ],
];

$services = [
    'RTC Sync Service' => [
        'functions' => array_keys($functions),
        'restrictedusers' => 1,
        'enabled' => 1,
    ],
];
