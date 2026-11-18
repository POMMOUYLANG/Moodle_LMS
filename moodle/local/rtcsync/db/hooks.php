<?php

defined('MOODLE_INTERNAL') || die();

$callbacks = [];

if (class_exists(\core_course\hook\before_course_data_purge::class)) {
    // Use the generic cancellable hook when the Moodle distribution provides it.
    $callbacks[] = [
        'hook' => \core_course\hook\before_course_data_purge::class,
        'callback' => \local_rtcsync\hook_callbacks::class . '::before_course_data_purge',
        'priority' => 0,
    ];
} else {
    // Stock Moodle 5.3 exposes narrower pre-operation hooks instead.
    if (class_exists(\core_course\hook\before_course_deleted::class)) {
        $callbacks[] = [
            'hook' => \core_course\hook\before_course_deleted::class,
            'callback' => \local_rtcsync\hook_callbacks::class . '::before_course_deleted',
            'priority' => 0,
        ];
    }

    if (class_exists(\core_enrol\hook\before_user_enrolment_removed::class)) {
        $callbacks[] = [
            'hook' => \core_enrol\hook\before_user_enrolment_removed::class,
            'callback' => \local_rtcsync\hook_callbacks::class . '::before_user_enrolment_removed',
            'priority' => 0,
        ];
    }
}

$callbacks = array_merge($callbacks, [
    [
        'hook' => \core\hook\after_config::class,
        'callback' => \local_rtcsync\hook_callbacks::class . '::after_config',
        'priority' => 0,
    ],
    [
        'hook' => \core\hook\output\before_standard_head_html_generation::class,
        'callback' => \local_rtcsync\hook_callbacks::class . '::before_standard_head_html_generation',
        'priority' => 0,
    ],
    [
        'hook' => \core\hook\output\before_footer_html_generation::class,
        'callback' => \local_rtcsync\hook_callbacks::class . '::before_footer_html_generation',
        'priority' => 0,
    ],
]);

