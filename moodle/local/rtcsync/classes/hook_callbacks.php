<?php

namespace local_rtcsync;

use core\hook\output\before_footer_html_generation;
use core\hook\output\before_standard_head_html_generation;
use core\hook\after_config;
use core_course\hook\before_course_data_purge;

defined('MOODLE_INTERNAL') || die();

/**
 * Moodle hook callbacks for RTC Sync protections and guidance.
 */
final class hook_callbacks
{
    /**
     * Prevent core from deleting or resetting course data while RTC lineage remains.
     */
    public static function before_course_data_purge(before_course_data_purge $hook): void
    {
        if ($hook->courseid <= 0) {
            $hook->stop_propagation();
            return;
        }

        $muststop = match ($hook->operation) {
            before_course_data_purge::OPERATION_ACTIVITY_DELETE => self::module_has_delivery_history_to_preserve(
                $hook->courseid,
                (int) ($hook->options['cmid'] ?? 0)
            ),
            before_course_data_purge::OPERATION_GRADE_ITEM_GRADES_DELETE => self::grade_item_has_delivery_history_to_preserve(
                $hook->courseid,
                (int) ($hook->options['gradeitemid'] ?? 0)
            ),
            before_course_data_purge::OPERATION_USER_UNENROL => self::user_unenrol_would_purge_delivery_grade_candidates(
                $hook->courseid,
                (int) ($hook->options['userid'] ?? 0),
                (int) ($hook->options['userenrolmentid'] ?? 0)
            ),
            default => self::course_has_delivery_history_to_preserve($hook->courseid),
        };

        if ($muststop) {
            if ($hook->operation === before_course_data_purge::OPERATION_ACTIVITY_DELETE) {
                self::clear_pending_module_deletion($hook);
            }
            $hook->stop_propagation();
        }
    }

    /** Restore a queued activity's normal visibility when its deletion is vetoed. */
    private static function clear_pending_module_deletion(before_course_data_purge $hook): void
    {
        global $DB;

        $cmid = (int) ($hook->options['cmid'] ?? 0);
        if ($cmid <= 0) {
            return;
        }

        $cm = $DB->get_record('course_modules', [
            'id' => $cmid,
            'course' => $hook->courseid,
        ], 'id,deletioninprogress', IGNORE_MISSING);
        if ($cm && !empty($cm->deletioninprogress)) {
            $DB->set_field('course_modules', 'deletioninprogress', 0, ['id' => $cmid]);
            rebuild_course_cache($hook->courseid, true);
        }
    }

    /**
     * Fail closed when any persisted RTC delivery state or delivery score remains.
     */
    private static function course_has_delivery_history_to_preserve(int $courseid): bool
    {
        global $DB;

        $manager = $DB->get_manager();
        foreach ([
            'local_rtcsync_deliverycourse',
            'local_rtcsync_deliverycm',
            'local_rtcsync_deliverycmhist',
            'local_rtcsync_deliverygrade',
            'local_rtcsync_history',
            'local_rtcsync_studentscope',
            'local_rtcsync_deliverymember',
            'local_rtcsync_linemanifest',
            'local_rtcsync_lineageaudit',
        ] as $table) {
            // A partial plugin upgrade must never turn off the purge guard.
            if (!$manager->table_exists($table)) {
                return true;
            }
            if ($DB->record_exists($table, ['courseid' => $courseid])) {
                return true;
            }
        }

        if ($DB->record_exists_sql(
            'SELECT 1 FROM {groupings} WHERE courseid = :courseid AND idnumber LIKE :deliverypattern',
            [
                'courseid' => $courseid,
                'deliverypattern' => 'rtc-delivery-grouping:%',
            ]
        )) {
            return true;
        }

        // An unresolved delivery grade may have no deliverygrade row yet, but
        // its RTC-owned stable identifier is still enough to protect the data.
        return $DB->record_exists_sql(
            'SELECT 1 FROM {grade_items} WHERE courseid = :courseid AND idnumber LIKE :scorepattern',
            [
                'courseid' => $courseid,
                'scorepattern' => 'rtc-subject-score:%:delivery:%',
            ]
        );
    }

    /** Check only lineage directly attached to an activity and the grades it owns. */
    private static function module_has_delivery_history_to_preserve(int $courseid, int $cmid): bool
    {
        global $DB;

        if ($cmid <= 0) {
            return true;
        }

        foreach ([
            'local_rtcsync_deliverycm',
            'local_rtcsync_deliverycmhist',
            'local_rtcsync_deliverygrade',
            'local_rtcsync_lineageaudit',
        ] as $table) {
            if (!$DB->get_manager()->table_exists($table)) {
                return true;
            }
        }

        $cm = $DB->get_record_sql(
            'SELECT cm.id, cm.course, cm.instance, cm.groupingid, m.name AS modulename, g.idnumber AS groupingidnumber
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
          LEFT JOIN {groupings} g ON g.id = cm.groupingid
              WHERE cm.id = :cmid AND cm.course = :courseid',
            ['cmid' => $cmid, 'courseid' => $courseid],
            IGNORE_MISSING
        );
        if (!$cm) {
            return true;
        }

        if ($DB->record_exists('local_rtcsync_deliverycm', ['cmid' => $cmid, 'courseid' => $courseid])
                || $DB->record_exists('local_rtcsync_deliverycmhist', ['cmid' => $cmid, 'courseid' => $courseid])
                || $DB->record_exists('local_rtcsync_lineageaudit', [
                    'courseid' => $courseid,
                    'recordtype' => 'module',
                    'targetid' => $cmid,
                ])) {
            return true;
        }

        if (str_starts_with((string) ($cm->groupingidnumber ?? ''), 'rtc-delivery-grouping:')) {
            return true;
        }

        $gradeparams = [
            'courseid' => $courseid,
            'itemmodule' => (string) $cm->modulename,
            'iteminstance' => (int) $cm->instance,
        ];
        if ($DB->record_exists_sql(
            'SELECT 1 FROM {grade_items} gi
               JOIN {local_rtcsync_deliverygrade} dg ON dg.gradeitemid = gi.id AND dg.courseid = gi.courseid
              WHERE gi.courseid = :courseid AND gi.itemmodule = :itemmodule AND gi.iteminstance = :iteminstance',
            $gradeparams
        ) || $DB->record_exists_sql(
            'SELECT 1 FROM {grade_items} gi
               JOIN {local_rtcsync_lineageaudit} la ON la.targetid = gi.id AND la.courseid = gi.courseid
              WHERE gi.courseid = :courseid AND gi.itemmodule = :itemmodule AND gi.iteminstance = :iteminstance
                AND la.recordtype = :recordtype',
            $gradeparams + ['recordtype' => 'gradeitem']
        ) || $DB->record_exists_sql(
            'SELECT 1 FROM {grade_items}
              WHERE courseid = :courseid AND itemmodule = :itemmodule AND iteminstance = :iteminstance
                AND idnumber LIKE :scorepattern',
            $gradeparams + ['scorepattern' => 'rtc-subject-score:%:delivery:%']
        )) {
            return true;
        }

        return false;
    }

    /** Public scope check for the legacy pre_course_module_delete fallback. */
    public static function module_deletion_requires_protection(int $courseid, int $cmid): bool
    {
        return self::module_has_delivery_history_to_preserve($courseid, $cmid);
    }

    /** Check only lineage directly attached to a grade item and its owning activity. */
    private static function grade_item_has_delivery_history_to_preserve(int $courseid, int $gradeitemid): bool
    {
        global $DB;

        if ($gradeitemid <= 0) {
            return true;
        }

        foreach ([
            'local_rtcsync_deliverycm',
            'local_rtcsync_deliverycmhist',
            'local_rtcsync_deliverygrade',
            'local_rtcsync_lineageaudit',
        ] as $table) {
            if (!$DB->get_manager()->table_exists($table)) {
                return true;
            }
        }

        $gradeitem = $DB->get_record('grade_items', [
            'id' => $gradeitemid,
            'courseid' => $courseid,
        ], 'id,itemmodule,iteminstance,idnumber', IGNORE_MISSING);
        if (!$gradeitem) {
            return true;
        }

        if ($DB->record_exists('local_rtcsync_deliverygrade', [
            'gradeitemid' => $gradeitemid,
            'courseid' => $courseid,
        ]) || $DB->record_exists('local_rtcsync_lineageaudit', [
            'courseid' => $courseid,
            'recordtype' => 'gradeitem',
            'targetid' => $gradeitemid,
        ]) || preg_match('/^rtc-subject-score:[^:]+:delivery:[^:]+$/', (string) $gradeitem->idnumber)) {
            return true;
        }

        if (!empty($gradeitem->itemmodule) && !empty($gradeitem->iteminstance)) {
            $cm = $DB->get_record_sql(
                'SELECT cm.id
                   FROM {course_modules} cm
                   JOIN {modules} m ON m.id = cm.module
                  WHERE cm.course = :courseid AND m.name = :modulename AND cm.instance = :instanceid',
                [
                    'courseid' => $courseid,
                    'modulename' => (string) $gradeitem->itemmodule,
                    'instanceid' => (int) $gradeitem->iteminstance,
                ],
                IGNORE_MISSING
            );
            if ($cm && self::module_has_delivery_history_to_preserve($courseid, (int) $cm->id)) {
                return true;
            }
        }

        return false;
    }

    /** Protect possible delivery grades when removing the user's final course enrolment. */
    private static function user_unenrol_would_purge_delivery_grade_candidates(
        int $courseid,
        int $userid,
        int $userenrolmentid
    ): bool {
        global $DB;

        if ($courseid <= 0 || $userid <= 0 || $userenrolmentid <= 0) {
            return true;
        }

        // Moodle only deletes grade rows when this is the last enrolment in the course.
        if ($DB->record_exists_sql(
            'SELECT 1
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE ue.userid = :userid AND e.courseid = :courseid AND ue.id <> :currentueid',
            [
                'userid' => $userid,
                'courseid' => $courseid,
                'currentueid' => $userenrolmentid,
            ]
        )) {
            return false;
        }

        foreach ([
            'local_rtcsync_history',
            'local_rtcsync_deliverygrade',
            'local_rtcsync_deliverycm',
            'local_rtcsync_deliverycmhist',
            'local_rtcsync_lineageaudit',
        ] as $table) {
            if (!$DB->get_manager()->table_exists($table)) {
                return true;
            }
        }

        // A participation marker may be absent or may already have been
        // cleared for the active delivery. Exact persisted delivery lineage
        // on a grade row is independently sufficient to protect that result.
        $hasdirectdeliverygrade = $DB->record_exists_sql(
            'SELECT 1
               FROM {grade_grades} gg
               JOIN {grade_items} gi ON gi.id = gg.itemid
              WHERE gg.userid = :userid AND gi.courseid = :courseid
                AND (
                    EXISTS (
                        SELECT 1 FROM {local_rtcsync_deliverygrade} dg
                         WHERE dg.gradeitemid = gi.id AND dg.courseid = gi.courseid
                    )
                    OR gi.idnumber LIKE :scorepattern
                    OR EXISTS (
                        SELECT 1 FROM {local_rtcsync_lineageaudit} ga
                         WHERE ga.courseid = gi.courseid AND ga.recordtype = :graderecordtype
                           AND ga.targetid = gi.id
                    )
                    OR EXISTS (
                        SELECT 1
                          FROM {course_modules} cm
                          JOIN {modules} m ON m.id = cm.module
                         WHERE cm.course = gi.courseid AND m.name = gi.itemmodule
                           AND cm.instance = gi.iteminstance AND gi.itemtype = :moditemtype
                           AND (
                               EXISTS (
                                   SELECT 1 FROM {local_rtcsync_deliverycm} dc
                                    WHERE dc.cmid = cm.id AND dc.courseid = cm.course
                               )
                               OR EXISTS (
                                   SELECT 1 FROM {local_rtcsync_deliverycmhist} dh
                                    WHERE dh.cmid = cm.id AND dh.courseid = cm.course
                               )
                               OR EXISTS (
                                   SELECT 1 FROM {local_rtcsync_lineageaudit} ma
                                    WHERE ma.courseid = cm.course AND ma.recordtype = :modulerecordtype
                                      AND ma.targetid = cm.id
                               )
                           )
                    )
                )',
            [
                'userid' => $userid,
                'courseid' => $courseid,
                'scorepattern' => 'rtc-subject-score:%:delivery:%',
                'graderecordtype' => 'gradeitem',
                'modulerecordtype' => 'module',
                'moditemtype' => 'mod',
            ]
        );
        if ($hasdirectdeliverygrade) {
            return true;
        }

        if (!$DB->record_exists_select(
            'local_rtcsync_history',
            'userid = :userid AND courseid = :courseid AND sourcetype = :sourcetype AND deliveryid > 0',
            [
                'userid' => $userid,
                'courseid' => $courseid,
                'sourcetype' => 'delivery_participation',
            ]
        )) {
            return false;
        }

        // A retained delivery marker cannot identify which of a student's grade
        // rows came from an older reused module. Preserve all candidate rows
        // until reviewed lineage or an explicit archive/disposal decision exists.
        return $DB->record_exists_sql(
            'SELECT 1
               FROM {grade_grades} gg
               JOIN {grade_items} gi ON gi.id = gg.itemid
              WHERE gg.userid = :userid AND gi.courseid = :courseid',
            ['userid' => $userid, 'courseid' => $courseid]
        );
    }

    /** Fail closed before any route runs if grouping availability is disabled. */
    public static function after_config(after_config $hook): void
    {
        \local_rtcsync\local\delivery_activity_access::enforce_availability_lockdown();
    }

    /**
     * Keeps standard Moodle multilingual spans language-specific even when a
     * theme or custom-header renderer bypasses the normal filter pipeline.
     */
    public static function before_standard_head_html_generation(
        before_standard_head_html_generation $hook
    ): void {
        $hook->add_html(<<<'HTML'
<style id="local-rtcsync-multilang-fallback">
.multilang { display: none !important; }
html[lang^="en"] .multilang[lang^="en"],
html[lang^="km"] .multilang[lang^="km"],
html[lang^="km"] .multilang[lang^="kh"],
html[lang^="kh"] .multilang[lang^="km"],
html[lang^="kh"] .multilang[lang^="kh"] { display: inline !important; }
</style>
HTML
        );
    }

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
        global $CFG;

        if (!isloggedin() || isguestuser() || empty($course->id) || (int) $course->id === SITEID) {
            return false;
        }

        $path = $page->url->get_path();
        $basepath = parse_url((string) $CFG->wwwroot, PHP_URL_PATH) ?: '';
        $basepath = rtrim('/' . trim($basepath, '/'), '/');
        if ($basepath !== '' && str_starts_with($path, $basepath . '/')) {
            $path = substr($path, strlen($basepath));
        }

        if (!in_array($path, self::GROUP_GUIDE_PATHS, true)) {
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
