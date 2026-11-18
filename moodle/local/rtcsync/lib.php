<?php

defined('MOODLE_INTERNAL') || die();

/**
 * Keep Moodle from deleting a course that contains RTC delivery state.
 *
 * Stock Moodle calls this legacy pre-delete callback before its newer
 * before_course_deleted hook. Prefer the latter when it exists, and retain this
 * callback for older core versions without either cancellable hook.
 *
 * @param stdClass $course Course being deleted.
 */
function local_rtcsync_pre_course_delete(stdClass $course): void {
    if (class_exists(\core_course\hook\before_course_data_purge::class)
            || class_exists(\core_course\hook\before_course_deleted::class)) {
        return;
    }

    if (\local_rtcsync\hook_callbacks::course_deletion_requires_protection((int) ($course->id ?? 0))) {
        throw new moodle_exception('coursepurgestopped', 'core');
    }
}

/**
 * Keep Moodle from deleting activity data that may back delivery history.
 *
 * Moodle removes module attempts and grade items after this callback. A
 * delivery-lineage archive or disposal decision intentionally retains that
 * content, so removal stays blocked while the course is delivery-aware.
 *
 * @param stdClass $cm Course-module record about to be deleted.
 */
function local_rtcsync_pre_course_module_delete(stdClass $cm): void {
    global $DB;

    if (class_exists(\core_course\hook\before_course_data_purge::class)) {
        return;
    }

    if (empty($cm->course) || empty($cm->id)
            || !\local_rtcsync\hook_callbacks::module_deletion_requires_protection(
                (int) $cm->course,
                (int) $cm->id
            )) {
        return;
    }

    // Moodle may queue this callback after marking a module for background
    // deletion. Restore its normal visibility before rejecting that removal.
    if (!empty($cm->deletioninprogress) && !empty($cm->id)) {
        $DB->set_field('course_modules', 'deletioninprogress', 0, [
            'id' => (int) $cm->id,
            'course' => (int) $cm->course,
        ]);
        rebuild_course_cache((int) $cm->course, true);
    }

    throw new moodle_exception('deliverymoduledeletionblocked', 'local_rtcsync');
}

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
 * @param callable|null $identityfetch Optional RTC identity fetcher for focused tests.
 */
function local_rtcsync_after_require_login(
    $courseorid = null,
    $autologinguest = null,
    $cm = null,
    $setwantsurltome = null,
    $preventredirect = null,
    ?callable $identityfetch = null
): void {
    global $PAGE, $USER;

    if (empty($USER->id)) {
        return;
    }

    \local_rtcsync\local\delivery_activity_access::reconcile_user_delivery_group_memberships(
        (int) $USER->id
    );

    $courseid = is_object($cm) && !empty($cm->course)
        ? (int) $cm->course
        : (is_object($courseorid)
            ? (int) ($courseorid->id ?? 0)
            : (is_numeric($courseorid) ? (int) $courseorid : 0));

    $isrestrictedmanagedstudent = static function(int $targetcourseid) use ($USER): bool {
        $userid = (int) $USER->id;
        if (!\local_rtcsync\local\delivery_activity_access::is_managed_student_course_member(
                $targetcourseid,
                $userid
            )
                || !\local_rtcsync\local\delivery_activity_access::is_active_manual_student(
                    $targetcourseid,
                    $userid
                )) {
            return false;
        }

        $context = context_course::instance($targetcourseid);
        if (has_capability('moodle/grade:viewall', $context, $userid)
                || has_capability('moodle/grade:viewhidden', $context, $userid)) {
            return false;
        }

        return true;
    };
    $revalidatelearneridentity = static function(int $targetcourseid) use (
        $isrestrictedmanagedstudent,
        $identityfetch
    ): void {
        if (!$isrestrictedmanagedstudent($targetcourseid)) {
            return;
        }
        (new \local_rtcsync\local\student_history())->require_current_rtc_student_session($identityfetch);
    };

    // Keep self-only grade surfaces from exposing unreleased gradebook details
    // outside Released results. This includes course activities overview and
    // the course profile's mode=grade route, which bypass report plugin indexes.
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
        if (plugin_supports('mod', (string) ($cm->modname ?? ''), FEATURE_GRADE_HAS_GRADE, false)
                || plugin_supports('mod', (string) ($cm->modname ?? ''), FEATURE_GRADE_OUTCOMES, false)) {
            if ($isrestrictedmanagedstudent((int) $cm->course)) {
                \local_rtcsync\local\delivery_activity_access::quarantine_nonmanual_delivery_grade_items(
                    (int) $cm->course
                );
            }
            $revalidatelearneridentity((int) $cm->course);
        }
        return;
    }

    // Some content-bearing search routes authenticate only at course scope,
    // then rely on course modinfo when filtering activity results.
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

        // Moodle's activity-overview page renders individual module grades
        // outside the standard grade-report endpoints.
        if (!empty($PAGE->url) && str_ends_with($PAGE->url->get_path(), '/course/overview.php')) {
            if ($isrestrictedmanagedstudent($courseid)) {
                \local_rtcsync\local\delivery_activity_access::quarantine_nonmanual_delivery_grade_items($courseid);
            }
            $revalidatelearneridentity($courseid);
        }
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
