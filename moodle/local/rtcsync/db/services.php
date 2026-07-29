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
    'local_rtcsync_sync_system_roles' => [
        'classname' => 'local_rtcsync_external',
        'methodname' => 'sync_system_roles',
        'classpath' => 'local/rtcsync/externallib.php',
        'description' => 'Reconcile approved RTC-managed Moodle system roles.',
        'type' => 'write',
        'capabilities' => 'moodle/role:assign',
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
    'local_rtcsync_upsert_credit' => [
        'classname' => 'local_rtcsync_external',
        'methodname' => 'upsert_credit',
        'classpath' => 'local/rtcsync/externallib.php',
        'description' => 'Create/update an isolated subject credit course and reconcile its teacher/student access.',
        'type' => 'write',
        'capabilities' => 'moodle/course:create,moodle/course:update,moodle/course:managegroups,enrol/manual:enrol,enrol/manual:unenrol',
    ],
    'local_rtcsync_delete_credit' => [
        'classname' => 'local_rtcsync_external',
        'methodname' => 'delete_credit',
        'classpath' => 'local/rtcsync/externallib.php',
        'description' => 'Archive one RTC-managed isolated credit course and revoke its managed access.',
        'type' => 'write',
        'capabilities' => 'moodle/course:create,moodle/course:update,moodle/course:managegroups,enrol/manual:enrol,enrol/manual:unenrol',
    ],
    'local_rtcsync_upsert_class' => [
        'classname' => 'local_rtcsync_external',
        'methodname' => 'upsert_class',
        'classpath' => 'local/rtcsync/externallib.php',
        'description' => 'Create/update a managed SMS class cohort and reconcile its members.',
        'type' => 'write',
        'capabilities' => 'moodle/cohort:manage',
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
