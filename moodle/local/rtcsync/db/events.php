<?php

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\\core\\event\\config_log_created',
        'callback' => '\\local_rtcsync\\observer::config_log_created',
        'internal' => true,
    ],
    [
        'eventname' => '\\core\\event\\course_module_created',
        'callback' => '\\local_rtcsync\\observer::course_module_created',
        'internal' => true,
    ],
    [
        'eventname' => '\\core\\event\\course_module_updated',
        'callback' => '\\local_rtcsync\\observer::course_module_updated',
        'internal' => true,
    ],
];
