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
 * Recheck delivery-scoped activity access after Moodle's normal login checks.
 *
 * Moodle's activity availability can be edited before the course-module
 * observer restores the RTC grouping restriction. This request-time check
 * denies managed Students if that persisted restriction is temporarily
 * missing or out of sync.
 *
 * @param mixed $courseorid Course object or ID supplied by require_login().
 * @param mixed $autologinguest Whether guest autologin was requested.
 * @param mixed $cm Course-module info, when the request has a module context.
 * @param mixed $setwantsurltome Whether the current URL should be retained.
 * @param mixed $preventredirect Whether redirects are disabled.
 */
function local_rtcsync_after_require_login(
    $courseorid = null,
    $autologinguest = null,
    $cm = null,
    $setwantsurltome = null,
    $preventredirect = null
): void {
    global $PAGE, $USER;

    if (empty($USER->id)) {
        return;
    }

    \local_rtcsync\local\delivery_activity_access::reconcile_user_delivery_group_memberships(
        (int) $USER->id
    );

    // Keep self-only core grade reports from exposing unreleased gradebook
    // details outside the Released results surface. This also covers the
    // course profile's mode=grade route, which does not pass through a report
    // plugin index file.
    if (!empty($PAGE->url)
            && \local_rtcsync\local\grade_report_redirect::should_redirect(
                $PAGE->url->get_path(),
                $PAGE->url->params(),
                (int) $USER->id,
                static function (int $courseid) use ($USER): bool {
                    return \local_rtcsync\local\delivery_activity_access::should_restrict_grade_report(
                        (int) $USER->id,
                        $courseid
                    );
                }
            )) {
        redirect(new moodle_url('/local/rtcsync/student_history.php'));
        return;
    }

    if (is_object($cm) && !empty($cm->id) && !empty($cm->course)) {
        \local_rtcsync\local\delivery_activity_access::require_student_module_access(
            (int) $cm->course,
            (int) $cm->id,
            (int) $USER->id
        );
        return;
    }

    // Some content-bearing search routes authenticate only at course scope,
    // then rely on course modinfo when filtering activity results.
    $courseid = is_object($courseorid)
        ? (int) ($courseorid->id ?? 0)
        : (is_numeric($courseorid) ? (int) $courseorid : 0);
    if ($courseid <= 1
            && !empty($PAGE->url)
            && str_ends_with($PAGE->url->get_path(), '/mod/forum/user.php')) {
        $pageparams = $PAGE->url->params();
        if (!array_key_exists('course', $pageparams)) {
            \local_rtcsync\local\delivery_activity_access::require_student_all_managed_course_module_guards(
                (int) $USER->id
            );
        } else if ((int) $pageparams['course'] > 1) {
            \local_rtcsync\local\delivery_activity_access::require_student_course_module_guards(
                (int) $pageparams['course'],
                (int) $USER->id
            );
        }
        return;
    }

    if ($courseid > 1) {
        $coursecontext = context_course::instance($courseid);
        if (has_capability('moodle/grade:viewall', $coursecontext)
                && !\local_rtcsync\local\delivery_activity_access::is_active_manual_student(
                    $courseid,
                    (int) $USER->id
                )) {
            // Preserve course-only gradebook staff access. Active manual
            // Students still receive the delivery course guard below.
            return;
        }

        \local_rtcsync\local\delivery_activity_access::require_student_course_module_guards(
            $courseid,
            (int) $USER->id
        );
    }
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
