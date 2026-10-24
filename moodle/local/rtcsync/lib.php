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

/**
 * Add a site-level history entry for RTC Student sessions.
 *
 * Fresh account status and identity are checked again by the target page.
 */
function local_rtcsync_extend_navigation(global_navigation $navigation): void
{
    global $SESSION;

    if (!isloggedin() || isguestuser()) {
        return;
    }

    $roles = $SESSION->rtc_roles ?? [];
    if (!is_array($roles)) {
        $roles = [$roles];
    }
    $isstudent = false;
    foreach ($roles as $role) {
        if (is_array($role)) {
            $role = $role['role_key'] ?? $role['name'] ?? '';
        } else if (is_object($role)) {
            $role = $role->role_key ?? $role->name ?? '';
        }
        $role = core_text::strtolower(trim((string) $role));
        $role = preg_replace('/\s+/', ' ', str_replace(['-', '_'], ' ', $role));
        if ($role === 'student') {
            $isstudent = true;
            break;
        }
    }

    if (!$isstudent) {
        return;
    }

    $navigation->add(
        get_string('studenthistorynav', 'local_rtcsync'),
        new moodle_url('/local/rtcsync/student_history.php'),
        navigation_node::TYPE_CUSTOM,
        null,
        'local_rtcsync_student_history',
        new pix_icon('i/grades', '')
    );
}
