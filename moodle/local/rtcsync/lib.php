<?php

defined('MOODLE_INTERNAL') || die();

/**
 * Adds the formative synchronization control to the course navigation.
 */
function local_rtcsync_extend_navigation_course(
    navigation_node $navigation,
    stdClass $course,
    context_course $context
): void {
    if (!has_capability('local/rtcsync:manageformative', $context)) {
        return;
    }

    $navigation->add(
        get_string('formativenav', 'local_rtcsync'),
        new moodle_url('/local/rtcsync/formative.php', ['courseid' => $course->id]),
        navigation_node::TYPE_SETTING,
        null,
        'local_rtcsync_formative',
        new pix_icon('i/grades', '')
    );
}
