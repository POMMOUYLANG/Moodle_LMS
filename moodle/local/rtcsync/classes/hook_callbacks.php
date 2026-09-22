<?php

namespace local_rtcsync;

use core\hook\output\before_footer_html_generation;

defined('MOODLE_INTERNAL') || die();

/**
 * Output hooks for contextual RTC Moodle guidance.
 */
final class hook_callbacks
{
    /** @var string[] Moodle pages where the group-management guide is useful. */
    private const GROUP_GUIDE_PATHS = [
        '/group/index.php',
        '/group/group.php',
        '/group/members.php',
        '/user/index.php',
    ];

    /**
     * Adds the guide only for signed-in users who can manage groups in this course.
     */
    public static function before_footer_html_generation(before_footer_html_generation $hook): void
    {
        global $COURSE, $PAGE;

        if (!self::should_show_group_guide($PAGE, $COURSE)) {
            return;
        }

        $PAGE->requires->js_call_amd('local_rtcsync/user_guide', 'init');

        $context = [
            'title' => get_string('userguidetitle', 'local_rtcsync'),
            'eyebrow' => get_string('userguideeyebrow', 'local_rtcsync', self::campus_label()),
            'intro' => get_string('userguideintro', 'local_rtcsync'),
            'openlabel' => get_string('userguideopen', 'local_rtcsync'),
            'closelabel' => get_string('userguideclose', 'local_rtcsync'),
            'tiptitle' => get_string('userguidetiptitle', 'local_rtcsync'),
            'tipbody' => get_string('userguidetipbody', 'local_rtcsync'),
            'steps' => array_map(
                static fn(int $step): array => [
                    'number' => $step,
                    'title' => get_string("userguidestep{$step}title", 'local_rtcsync'),
                    'body' => get_string("userguidestep{$step}body", 'local_rtcsync'),
                ],
                range(1, 5)
            ),
        ];

        $hook->add_html($hook->renderer->render_from_template('local_rtcsync/user_guide', $context));
    }

    /**
     * Pure page/capability gate kept public for focused Moodle tests.
     */
    public static function should_show_group_guide(\moodle_page $page, \stdClass $course): bool
    {
        if (!isloggedin() || isguestuser() || empty($course->id) || (int) $course->id === SITEID) {
            return false;
        }

        if (!in_array($page->url->get_path(), self::GROUP_GUIDE_PATHS, true)) {
            return false;
        }

        $context = \context_course::instance((int) $course->id);
        return has_capability('moodle/course:managegroups', $context);
    }

    /**
     * Resolves the user-facing campus abbreviation from Moodle's configured host.
     */
    public static function campus_label(): string
    {
        global $CFG;

        $host = strtolower((string) parse_url((string) $CFG->wwwroot, PHP_URL_HOST));
        if (str_contains($host, 'bb-rtc-edu.com')) {
            return 'RTC BB';
        }
        if (str_contains($host, 'kampot-rtc-edu.com')) {
            return 'RTC KP';
        }
        if (str_contains($host, 'kc-rtc-edu.com')) {
            return 'RTC KC';
        }

        return 'RTC';
    }
}
