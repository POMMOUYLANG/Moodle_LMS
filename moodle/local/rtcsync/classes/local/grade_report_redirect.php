<?php

namespace local_rtcsync\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Identifies grade-report requests that should use the Released results page.
 *
 * The request classifier is kept independent of Moodle's database and login
 * state so the report-route policy can be checked by a standalone regression
 * script as well as by the require_login callback.
 */
final class grade_report_redirect {

    /**
     * Whether a request should be redirected, using the caller's learner policy.
     *
     * The callback is only invoked for a recognized, self-only report request.
     * Its argument is the course ID, or zero for the cross-course Overview.
     *
     * @param string $path Request path, including any Moodle subdirectory prefix.
     * @param array $params URL parameters.
     * @param int $viewerid Authenticated viewer ID.
     * @param callable $shouldrestrict Callback accepting a course ID and returning bool.
     * @return bool
     */
    public static function should_redirect(
        string $path,
        array $params,
        int $viewerid,
        callable $shouldrestrict
    ): bool {
        $courseid = self::managed_report_course_scope($path, $params, $viewerid);
        return $courseid !== null && $shouldrestrict($courseid);
    }

    /**
     * Get the managed-course scope for a self-only report URL.
     *
     * @param string $path Request path, including any Moodle subdirectory prefix.
     * @param array $params URL parameters.
     * @param int $viewerid Authenticated viewer ID.
     * @return int|null Zero means the cross-course Overview; null means this URL
     *     is not a self-only grade report request.
     */
    private static function managed_report_course_scope(string $path, array $params, int $viewerid): ?int {
        if ($viewerid <= 1) {
            return null;
        }

        if (str_ends_with($path, '/grade/report/overview/index.php')) {
            $userid = self::request_int($params, 'userid', $viewerid);
            return $userid === $viewerid ? 0 : null;
        }

        if (str_ends_with($path, '/grade/report/index.php')) {
            // This page only dispatches to the current course's selected report.
            return self::positive_course_id($params);
        }

        // Do not classify grade/report/user/index.php here. Moodle reads the
        // requested userid, then resets $PAGE->url to only the course id
        // before require_login() invokes local after_require_login callbacks.
        // Its core page has a direct self-report guard after authorization,
        // where the original request parameter is still available.

        if (str_ends_with($path, '/course/user.php')
                && !self::is_non_grade_course_profile_mode($params['mode'] ?? 'todaylogs')) {
            $userid = self::request_int($params, 'user', null);
            if ($userid !== $viewerid) {
                return null;
            }
            return self::positive_course_id($params);
        }

        return null;
    }

    /**
     * Whether course/user.php will route this exact mode away before choosing
     * its first allowed profile mode. Unknown and case-variant modes fall back
     * to the first allowed mode, which is grade for a self-viewing Student.
     *
     * @param mixed $mode Requested profile mode.
     * @return bool
     */
    private static function is_non_grade_course_profile_mode($mode): bool {
        return in_array($mode, [
            'outline',
            'complete',
            'todaylogs',
            'alllogs',
            'stats',
            'coursecompletions',
            'coursecompletion',
        ], true);
    }

    /**
     * Parse a positive course ID from the standard course parameter.
     *
     * @param array $params URL parameters.
     * @return int|null
     */
    private static function positive_course_id(array $params): ?int {
        $courseid = self::request_int($params, 'id', null);
        return $courseid !== null && $courseid > 0 ? $courseid : null;
    }

    /**
     * Parse an integer URL parameter without treating an empty string as absent.
     *
     * @param array $params URL parameters.
     * @param string $name Parameter name.
     * @param int|null $default Default when the parameter is absent.
     * @return int|null
     */
    private static function request_int(array $params, string $name, ?int $default): ?int {
        if (!array_key_exists($name, $params)) {
            return $default;
        }

        $value = $params[$name];
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/D', $value)) {
            return (int) $value;
        }
        return null;
    }
}
