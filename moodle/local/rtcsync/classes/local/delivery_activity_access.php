<?php

namespace local_rtcsync\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Delivery-aware Moodle activity and course access guard.
 *
 * A managed delivery course stays hidden while its existing modules are
 * processed in bounded pages. Each module gets a standard availability
 * grouping condition. Modules without a valid RTC delivery grouping get an
 * impossible grouping condition, so they remain inaccessible to students.
 */
final class delivery_activity_access
{
    public const MODULE_PAGE_SIZE = 100;
    public const LINEAGE_UNREVIEWED = 0;
    public const LINEAGE_CURRENT = 1;
    public const LINEAGE_HISTORICAL = 2;
    public const LINEAGE_QUARANTINED = 3;
    public const LINEAGE_ARCHIVED = 4;
    public const LINEAGE_DISPOSED = 5;
    /** Minimum versioned contract revision accepted from SMS. */
    private const MIN_STUDENT_SCOPE_REVISION = 2026102505;
    private const LOCK_TYPE = 'local_rtcsync_delivery_access';
    private const LOCK_WAIT_SECONDS = 1;

    /** Whether Moodle's standard availability grouping condition can enforce the guard. */
    public static function supports_standard_grouping_restrictions(): bool
    {
        global $CFG;

        if (empty($CFG->enableavailability)) {
            return false;
        }
        $enabled = \core_plugin_manager::instance()->get_enabled_plugins('availability');
        return isset($enabled['grouping']);
    }

    /**
     * Whether this managed subject course has entered delivery-aware access.
     *
     * @param int $courseid Moodle course ID.
     * @return bool True for a previously prepared course or a synced delivery grouping.
     */
    public static function is_enabled(int $courseid): bool
    {
        global $DB;

        return $DB->record_exists('local_rtcsync_deliverycourse', ['courseid' => $courseid])
            || $DB->record_exists_sql(
                "SELECT 1
                   FROM {groupings} g
                  WHERE g.courseid = :courseid
                    AND g.idnumber LIKE :deliverypattern",
                [
                    'courseid' => $courseid,
                    'deliverypattern' => 'rtc-delivery-grouping:%',
                ]
            );
    }

    /**
     * Whether the user is both assigned the Student role and actively enrolled
     * through Moodle's manual enrolment method in this course.
     */
    public static function is_active_manual_student(int $courseid, int $userid): bool
    {
        global $DB;

        return $DB->record_exists_sql(
            'SELECT 1
               FROM {role_assignments} ra
               JOIN {role} r ON r.id = ra.roleid AND r.shortname = :student
               JOIN {context} ctx ON ctx.id = ra.contextid
               JOIN {enrol} e ON e.courseid = ctx.instanceid AND e.enrol = :manual
               JOIN {user_enrolments} ue
                 ON ue.enrolid = e.id
                AND ue.userid = ra.userid
                AND ue.status = :active
              WHERE ctx.contextlevel = :coursecontext
                AND ctx.instanceid = :courseid
                AND ra.userid = :userid',
            [
                'student' => 'student',
                'manual' => 'manual',
                'active' => ENROL_USER_ACTIVE,
                'coursecontext' => CONTEXT_COURSE,
                'courseid' => $courseid,
                'userid' => $userid,
            ]
        );
    }

    /**
     * Fail closed for Student activity requests while a delivery guard is not current.
     *
     * Moodle stores edited availability before the course-module observer
     * reapplies RTC grouping restrictions. The after_require_login callback
     * uses this method to prevent a managed Student from entering a module in
     * that interval, including newly-created modules that are not mapped yet.
     *
     * @param int $courseid Moodle course ID.
     * @param int $cmid Moodle course-module ID.
     * @param int $userid Moodle user ID.
     */
    public static function require_student_module_access(int $courseid, int $cmid, int $userid): void
    {
        global $DB;

        if ($courseid <= 1 || $cmid <= 0 || $userid <= 1 || is_siteadmin($userid)) {
            return;
        }

        if (!$DB->get_manager()->table_exists('local_rtcsync_deliverycourse')
                || !$DB->get_manager()->table_exists('local_rtcsync_deliverycm')) {
            return;
        }

        if (!$DB->record_exists('course', ['id' => $courseid])) {
            return;
        }

        $state = $DB->get_record(
            'local_rtcsync_deliverycourse',
            ['courseid' => $courseid],
            'ready',
            IGNORE_MISSING
        );
        $hasdeliverygrouping = $DB->record_exists_select(
            'groupings',
            'courseid = :courseid AND idnumber LIKE :deliverypattern',
            ['courseid' => $courseid, 'deliverypattern' => 'rtc-delivery-grouping:%']
        );
        if (!$state && !$hasdeliverygrouping) {
            return;
        }

        $context = \context_course::instance($courseid);
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], IGNORE_MISSING);
        if (!$studentroleid || !$DB->record_exists('role_assignments', [
            'contextid' => (int) $context->id,
            'userid' => $userid,
            'roleid' => (int) $studentroleid,
        ])) {
            return;
        }

        self::require_active_managed_user($userid);

        // Moodle teaching and administration roles need to manage or review
        // activities across delivery groupings; keep those existing privileges.
        foreach ([
            'moodle/course:manageactivities',
            'moodle/course:ignoreavailabilityrestrictions',
            'moodle/course:viewhiddenactivities',
            'moodle/course:update',
            'moodle/site:accessallgroups',
        ] as $capability) {
            if (has_capability($capability, $context, $userid)) {
                return;
            }
        }

        $deny = static function(): void {
            throw new \moodle_exception('activityiscurrentlyhidden', 'moodle');
        };

        $hasactiveManualEnrolment = $DB->record_exists_sql(
            'SELECT 1
               FROM {enrol} e
               JOIN {user_enrolments} ue ON ue.enrolid = e.id
              WHERE e.courseid = :courseid
                AND e.enrol = :manual
                AND ue.userid = :userid
                AND ue.status = :active',
            [
                'courseid' => $courseid,
                'manual' => 'manual',
                'userid' => $userid,
                'active' => ENROL_USER_ACTIVE,
            ]
        );
        if (!$hasactiveManualEnrolment) {
            $deny();
        }

        if (!$state
                || (int) $state->ready !== 1
                || !self::supports_standard_grouping_restrictions()) {
            $deny();
        }

        $module = $DB->get_record_sql(
            'SELECT cm.id,
                    cm.course AS courseid,
                    cm.availability AS liveavailability,
                    cm.groupingid AS livegroupingid,
                    delivery.deliveryid,
                    delivery.groupingid,
                    delivery.lineagestatus,
                    delivery.managedavailability,
                    deliverygrouping.idnumber AS groupingidnumber
               FROM {course_modules} cm
          LEFT JOIN {local_rtcsync_deliverycm} delivery
                 ON delivery.cmid = cm.id
                AND delivery.courseid = cm.course
          LEFT JOIN {groupings} deliverygrouping
                 ON deliverygrouping.id = delivery.groupingid
                AND deliverygrouping.courseid = cm.course
              WHERE cm.id = :cmid
                AND cm.course = :courseid',
            ['cmid' => $cmid, 'courseid' => $courseid],
            IGNORE_MISSING
        );
        if (!$module || !self::has_current_module_guard($module, false)) {
            $deny();
        }
        if (!self::student_delivery_scope_allows(
            $courseid,
            $userid,
            (int) $module->deliveryid
        )) {
            $deny();
        }

        // The direct membership query avoids any group-visibility filtering:
        // Moodle's grouping availability condition also checks actual membership.
        $ismember = $DB->record_exists_sql(
            'SELECT 1
               FROM {groupings_groups} gg
               JOIN {groups} g ON g.id = gg.groupid
               JOIN {groups_members} gm ON gm.groupid = g.id
              WHERE gg.groupingid = :groupingid
                AND g.courseid = :courseid
                AND gm.userid = :userid',
            [
                'groupingid' => (int) $module->groupingid,
                'courseid' => $courseid,
                'userid' => $userid,
            ]
        );
        if (!$ismember) {
            $deny();
        }
    }

    /**
     * Validate every live and cached module guard before course-scoped content search.
     *
     * Some Moodle search endpoints authenticate a course without passing a
     * course-module object, then rely on course modinfo to filter content. A
     * stale or temporarily unguarded module must deny that whole request.
     *
     * @param int $courseid Moodle course ID.
     * @param int $userid Moodle user ID.
     */
    public static function require_student_course_module_guards(int $courseid, int $userid): void
    {
        global $DB;

        if ($courseid <= 1 || $userid <= 1 || is_siteadmin($userid)) {
            return;
        }

        if (!$DB->get_manager()->table_exists('local_rtcsync_deliverycourse')
                || !$DB->get_manager()->table_exists('local_rtcsync_deliverycm')) {
            return;
        }

        if (!$DB->record_exists('course', ['id' => $courseid])) {
            return;
        }

        $state = $DB->get_record(
            'local_rtcsync_deliverycourse',
            ['courseid' => $courseid],
            'ready',
            IGNORE_MISSING
        );
        $hasdeliverygrouping = $DB->record_exists_select(
            'groupings',
            'courseid = :courseid AND idnumber LIKE :deliverypattern',
            ['courseid' => $courseid, 'deliverypattern' => 'rtc-delivery-grouping:%']
        );
        if (!$state && !$hasdeliverygrouping) {
            return;
        }

        $context = \context_course::instance($courseid);
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], IGNORE_MISSING);
        if (!$studentroleid || !$DB->record_exists('role_assignments', [
            'contextid' => (int) $context->id,
            'userid' => $userid,
            'roleid' => (int) $studentroleid,
        ])) {
            return;
        }

        self::require_active_managed_user($userid);

        // Moodle teaching and administration roles need to manage or review
        // activities across delivery groupings; keep those existing privileges.
        foreach ([
            'moodle/course:manageactivities',
            'moodle/course:ignoreavailabilityrestrictions',
            'moodle/course:viewhiddenactivities',
            'moodle/course:update',
            'moodle/site:accessallgroups',
        ] as $capability) {
            if (has_capability($capability, $context, $userid)) {
                return;
            }
        }

        $deny = static function(): void {
            throw new \moodle_exception('activityiscurrentlyhidden', 'moodle');
        };

        $hasactiveManualEnrolment = $DB->record_exists_sql(
            'SELECT 1
               FROM {enrol} e
               JOIN {user_enrolments} ue ON ue.enrolid = e.id
              WHERE e.courseid = :courseid
                AND e.enrol = :manual
                AND ue.userid = :userid
                AND ue.status = :active',
            [
                'courseid' => $courseid,
                'manual' => 'manual',
                'userid' => $userid,
                'active' => ENROL_USER_ACTIVE,
            ]
        );
        if (!$hasactiveManualEnrolment) {
            $deny();
        }

        if (!$state
                || (int) $state->ready !== 1
                || !self::supports_standard_grouping_restrictions()) {
            $deny();
        }

        $modules = $DB->get_records_sql(
            'SELECT cm.id,
                    cm.course AS courseid,
                    cm.availability AS liveavailability,
                    cm.groupingid AS livegroupingid,
                    delivery.deliveryid,
                    delivery.groupingid,
                    delivery.lineagestatus,
                    delivery.managedavailability,
                    deliverygrouping.idnumber AS groupingidnumber
               FROM {course_modules} cm
          LEFT JOIN {local_rtcsync_deliverycm} delivery
                 ON delivery.cmid = cm.id
                AND delivery.courseid = cm.course
          LEFT JOIN {groupings} deliverygrouping
                 ON deliverygrouping.id = delivery.groupingid
                AND deliverygrouping.courseid = cm.course
              WHERE cm.course = :courseid
           ORDER BY cm.id',
            ['courseid' => $courseid]
        );

        $modinfo = get_fast_modinfo($courseid, $userid);
        $cachedmodules = $modinfo->get_cms();
        if (count($modules) !== count($cachedmodules)) {
            $deny();
        }
        $currentdeliveryids = self::student_current_delivery_scope($courseid, $userid);

        foreach ($modules as $module) {
            $cmid = (int) $module->id;
            $cachedmodule = $cachedmodules[$cmid] ?? null;
            if (!self::has_current_module_guard($module, true)
                    || !$cachedmodule
                    || $cachedmodule->availability !== $module->liveavailability
                    || (int) $cachedmodule->groupingid !== (int) $module->livegroupingid) {
                $deny();
            }

            $deliveryid = (int) $module->deliveryid;
            if ($deliveryid > 0
                    && ($currentdeliveryids === null || !in_array($deliveryid, $currentdeliveryids, true))
                    && $cachedmodule->uservisible) {
                // A stale Moodle grouping can still make the module visible in
                // modinfo. Fail closed for course-wide search until roster sync
                // removes that stale membership.
                $deny();
            }

            $guardgroupingid = (int) $module->groupingid;
            if ($guardgroupingid <= 0) {
                if ($cachedmodule->uservisible) {
                    $deny();
                }
                continue;
            }

            $ismember = $DB->record_exists_sql(
                'SELECT 1
                   FROM {groupings_groups} gg
                   JOIN {groups} g ON g.id = gg.groupid
                   JOIN {groups_members} gm ON gm.groupid = g.id
                  WHERE gg.groupingid = :groupingid
                    AND g.courseid = :courseid
                    AND gm.userid = :userid',
                [
                    'groupingid' => $guardgroupingid,
                    'courseid' => $courseid,
                    'userid' => $userid,
                ]
            );
            if (!$ismember && $cachedmodule->uservisible) {
                $deny();
            }
        }
    }

    /**
     * Guard every delivery-managed course where the current user has a manual
     * Student enrolment. Suspended courses must have no visible modules, while
     * active courses must have current guards. Used by global content reports
     * that filter results across courses after a site-level login.
     *
     * @param int $userid Moodle user ID.
     */
    public static function require_student_all_managed_course_module_guards(int $userid): void
    {
        global $DB;

        if ($userid <= 1 || is_siteadmin($userid)
                || !$DB->get_manager()->table_exists('local_rtcsync_deliverycourse')
                || !$DB->get_manager()->table_exists('local_rtcsync_deliverycm')) {
            return;
        }

        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], IGNORE_MISSING);
        if (!$studentroleid) {
            return;
        }

        $courses = $DB->get_records_sql(
            "SELECT DISTINCT c.id AS courseid
               FROM {course} c
               JOIN {context} ctx
                 ON ctx.instanceid = c.id
                AND ctx.contextlevel = :coursecontext
               JOIN {role_assignments} ra
                 ON ra.contextid = ctx.id
                AND ra.userid = :roleuserid
                AND ra.roleid = :studentroleid
               JOIN {enrol} e
                 ON e.courseid = c.id
                AND e.enrol = :manual
               JOIN {user_enrolments} ue
                 ON ue.enrolid = e.id
                AND ue.userid = :enroluserid
                AND ue.status IN (:active, :suspended)
              WHERE c.id <> :siteid
                AND (
                    EXISTS (
                        SELECT 1
                          FROM {local_rtcsync_deliverycourse} state
                         WHERE state.courseid = c.id
                    )
                    OR EXISTS (
                        SELECT 1
                          FROM {groupings} g
                         WHERE g.courseid = c.id
                           AND g.idnumber LIKE :deliverypattern
                    )
                )
           ORDER BY c.id",
            [
                'coursecontext' => CONTEXT_COURSE,
                'roleuserid' => $userid,
                'enroluserid' => $userid,
                'studentroleid' => (int) $studentroleid,
                'manual' => 'manual',
                'active' => ENROL_USER_ACTIVE,
                'suspended' => ENROL_USER_SUSPENDED,
                'siteid' => SITEID,
                'deliverypattern' => 'rtc-delivery-grouping:%',
            ]
        );

        foreach ($courses as $course) {
            $courseid = (int) $course->courseid;
            if (self::is_active_manual_student($courseid, $userid)) {
                self::require_student_course_module_guards($courseid, $userid);
                continue;
            }

            $modinfo = get_fast_modinfo($courseid, $userid);
            foreach ($modinfo->get_cms() as $cachedmodule) {
                if ($cachedmodule->uservisible) {
                    throw new \moodle_exception('activityiscurrentlyhidden', 'moodle');
                }
            }
        }
    }

    /** Whether course contents need per-module delivery filtering for this user. */
    public static function should_filter_student_course_contents(int $courseid, int $userid): bool
    {
        global $DB;

        if ($courseid <= 1 || $userid <= 1 || is_siteadmin($userid)
                || !$DB->get_manager()->table_exists('local_rtcsync_deliverycourse')
                || !$DB->get_manager()->table_exists('local_rtcsync_deliverycm')
                || !self::is_active_manual_student($courseid, $userid)) {
            return false;
        }

        $stateexists = $DB->record_exists('local_rtcsync_deliverycourse', ['courseid' => $courseid]);
        $hasdeliverygrouping = $DB->record_exists_select(
            'groupings',
            'courseid = :courseid AND idnumber LIKE :deliverypattern',
            ['courseid' => $courseid, 'deliverypattern' => 'rtc-delivery-grouping:%']
        );
        if (!$stateexists && !$hasdeliverygrouping) {
            return false;
        }

        $context = \context_course::instance($courseid);
        foreach ([
            'moodle/course:manageactivities',
            'moodle/course:ignoreavailabilityrestrictions',
            'moodle/course:viewhiddenactivities',
            'moodle/course:update',
            'moodle/site:accessallgroups',
        ] as $capability) {
            if (has_capability($capability, $context, $userid)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Filter calendar metadata to the current delivery for RTC Students.
     * Personal, site, unmanaged-course, and staff-visible events are preserved.
     */
    public static function calendar_event_is_in_current_delivery(object $event, int $userid): bool
    {
        global $DB;

        if (self::is_inactive_managed_user($userid)) {
            return false;
        }

        $courseid = (int) ($event->courseid ?? 0);
        $modulename = trim((string) ($event->modulename ?? ''));
        $instanceid = (int) ($event->instance ?? 0);
        $cmid = 0;

        if ((string) ($event->eventtype ?? '') === 'user'
                && (int) ($event->userid ?? 0) === $userid) {
            return true;
        }

        if ($modulename !== '' && $instanceid > 0) {
            $params = ['modulename' => $modulename, 'instanceid' => $instanceid];
            $coursesql = '';
            if ($courseid > 1) {
                $coursesql = ' AND cm.course = :courseid';
                $params['courseid'] = $courseid;
            }
            $module = $DB->get_record_sql(
                'SELECT cm.id, cm.course
                   FROM {modules} m
                   JOIN {course_modules} cm ON cm.module = m.id
                  WHERE m.name = :modulename
                    AND cm.instance = :instanceid' . $coursesql,
                $params,
                IGNORE_MULTIPLE
            );
            if ($module) {
                $courseid = (int) $module->course;
                $cmid = (int) $module->id;
            }
        }

        if ($courseid <= 1 || !self::is_enabled($courseid)) {
            return true;
        }

        if (self::has_delivery_calendar_bypass($courseid, $userid)) {
            return true;
        }

        if (self::is_active_manual_student($courseid, $userid)) {
            if ($cmid > 0) {
                return self::student_module_content_is_in_current_delivery(
                    $courseid,
                    $cmid,
                    $userid
                );
            }

            // An unresolved module reference is not a module-less event.
            // Only allow a genuine group event when the synced delivery roster
            // proves this Student belongs to its delivery-scoped child group.
            if ($modulename !== '' || $instanceid > 0
                    || (string) ($event->eventtype ?? '') !== 'group') {
                return false;
            }

            return self::student_group_event_is_in_current_delivery(
                $courseid,
                (int) ($event->groupid ?? 0),
                $userid
            );
        }

        if (self::is_suspended_manual_student($courseid, $userid)) {
            return false;
        }

        // This restriction only applies to RTC Student enrolments.
        return true;
    }

    /**
     * Whether a module-less group event belongs to the active Student's
     * explicitly synced delivery child group in this course.
     */
    private static function student_group_event_is_in_current_delivery(
        int $courseid,
        int $groupid,
        int $userid
    ): bool {
        global $DB;

        if ($groupid <= 0) {
            return false;
        }

        $state = $DB->get_record(
            'local_rtcsync_deliverycourse',
            ['courseid' => $courseid],
            'ready',
            IGNORE_MISSING
        );
        if (!$state || (int) $state->ready !== 1) {
            return false;
        }

        $memberships = $DB->get_records_sql(
            'SELECT gg.id AS membershipid,
                    g.idnumber AS groupidnumber,
                    deliverygrouping.idnumber AS groupingidnumber
               FROM {groups} g
               JOIN {groups_members} gm ON gm.groupid = g.id
               JOIN {groupings_groups} gg ON gg.groupid = g.id
               JOIN {groupings} deliverygrouping ON deliverygrouping.id = gg.groupingid
              WHERE g.id = :groupid
                AND g.courseid = :groupcourseid
                AND gm.userid = :userid
                AND deliverygrouping.courseid = :groupingcourseid',
            [
                'groupid' => $groupid,
                'groupcourseid' => $courseid,
                'userid' => $userid,
                'groupingcourseid' => $courseid,
            ]
        );

        $matched = false;
        foreach ($memberships as $membership) {
            $groupingidnumber = (string) $membership->groupingidnumber;
            if (!str_starts_with($groupingidnumber, 'rtc-delivery-grouping:')) {
                continue;
            }

            $groupingmatch = [];
            $groupmatch = [];
            if (preg_match('/^rtc-delivery-grouping:([1-9][0-9]*)$/', $groupingidnumber, $groupingmatch) !== 1
                    || preg_match(
                        '/^rtc-delivery-class-group:([1-9][0-9]*):([^:]+)$/',
                        (string) $membership->groupidnumber,
                        $groupmatch
                    ) !== 1
                    || $groupmatch[1] !== $groupingmatch[1]
                    || $matched) {
                // Conflicting or duplicate delivery associations are ambiguous.
                return false;
            }

            if (!self::student_delivery_scope_allows($courseid, $userid, (int) $groupingmatch[1])) {
                return false;
            }

            $matched = true;
        }

        return $matched;
    }

    /** Whether the user has a suspended manual Student enrolment. */
    private static function is_suspended_manual_student(int $courseid, int $userid): bool
    {
        global $DB;

        return $DB->record_exists_sql(
            'SELECT 1
               FROM {role_assignments} ra
               JOIN {role} r ON r.id = ra.roleid AND r.shortname = :student
               JOIN {context} ctx ON ctx.id = ra.contextid
               JOIN {enrol} e ON e.courseid = ctx.instanceid AND e.enrol = :manual
               JOIN {user_enrolments} ue
                 ON ue.enrolid = e.id
                AND ue.userid = ra.userid
                AND ue.status = :suspended
              WHERE ctx.contextlevel = :coursecontext
                AND ctx.instanceid = :courseid
                AND ra.userid = :userid',
            [
                'student' => 'student',
                'manual' => 'manual',
                'suspended' => ENROL_USER_SUSPENDED,
                'coursecontext' => CONTEXT_COURSE,
                'courseid' => $courseid,
                'userid' => $userid,
            ]
        );
    }

    /** Whether the user has a course or site capability that bypasses Student delivery scoping. */
    private static function has_delivery_calendar_bypass(int $courseid, int $userid): bool
    {
        if (is_siteadmin($userid)) {
            return true;
        }

        $context = \context_course::instance($courseid);
        foreach ([
            'moodle/course:manageactivities',
            'moodle/course:ignoreavailabilityrestrictions',
            'moodle/course:viewhiddenactivities',
            'moodle/course:update',
            'moodle/site:accessallgroups',
            'moodle/calendar:manageentries',
            'moodle/calendar:managegroupentries',
        ] as $capability) {
            if (has_capability($capability, $context, $userid)) {
                return true;
            }
        }

        return false;
    }

    /** Whether an active or suspended Student is restricted in this managed course. */
    public static function is_managed_student_course_member(int $courseid, int $userid): bool
    {
        return $courseid > 1
            && $userid > 1
            && self::is_enabled($courseid)
            && !self::has_delivery_calendar_bypass($courseid, $userid)
            && (self::is_active_manual_student($courseid, $userid)
                || self::is_suspended_manual_student($courseid, $userid));
    }

    /** Whether the user has a restricted Student enrolment in any managed course. */
    public static function has_managed_student_course_membership(int $userid): bool
    {
        global $DB;

        if ($userid <= 1 || is_siteadmin($userid)
                || !$DB->get_manager()->table_exists('local_rtcsync_deliverycourse')) {
            return false;
        }

        $courses = $DB->get_records_sql(
            'SELECT DISTINCT c.id
               FROM {course} c
               JOIN {context} ctx
                 ON ctx.instanceid = c.id
                AND ctx.contextlevel = :coursecontext
               JOIN {role_assignments} ra
                 ON ra.contextid = ctx.id
                AND ra.userid = :roleuserid
               JOIN {role} r
                 ON r.id = ra.roleid
                AND r.shortname = :student
               JOIN {enrol} e
                 ON e.courseid = c.id
                AND e.enrol = :manual
               JOIN {user_enrolments} ue
                 ON ue.enrolid = e.id
                AND ue.userid = ra.userid
                AND ue.status IN (:active, :suspended)
              WHERE c.id <> :siteid
                AND (
                    EXISTS (
                        SELECT 1
                          FROM {local_rtcsync_deliverycourse} state
                         WHERE state.courseid = c.id
                    )
                    OR EXISTS (
                        SELECT 1
                          FROM {groupings} deliverygrouping
                         WHERE deliverygrouping.courseid = c.id
                           AND deliverygrouping.idnumber LIKE :deliverypattern
                    )
                )',
            [
                'coursecontext' => CONTEXT_COURSE,
                'roleuserid' => $userid,
                'student' => 'student',
                'manual' => 'manual',
                'active' => ENROL_USER_ACTIVE,
                'suspended' => ENROL_USER_SUSPENDED,
                'siteid' => SITEID,
                'deliverypattern' => 'rtc-delivery-grouping:%',
            ]
        );

        if ($courses && self::is_inactive_managed_user($userid)) {
            throw new \moodle_exception('activityiscurrentlyhidden', 'moodle');
        }

        foreach ($courses as $course) {
            if (self::is_managed_student_course_member((int) $course->id, $userid)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a grade report should be replaced by Released results for this
     * user. Gradebook staff keep access when they have grade:viewall in the
     * relevant managed course; this is deliberately separate from content
     * and calendar bypass rules.
     *
     * Pass a course ID for a course-level report, or 0 to inspect all managed
     * courses included by the site-wide grade overview.
     */
    public static function should_restrict_grade_report(int $userid, int $courseid = 0): bool
    {
        global $DB;

        if ($userid <= 1 || is_siteadmin($userid)
                || !$DB->get_manager()->table_exists('local_rtcsync_deliverycourse')) {
            return false;
        }

        if ($courseid > 1) {
            if (!self::is_managed_student_course_member($courseid, $userid)) {
                return false;
            }

            return !has_capability(
                'moodle/grade:viewall',
                \context_course::instance($courseid),
                $userid
            );
        }

        $courses = $DB->get_records_sql(
            'SELECT DISTINCT c.id
               FROM {course} c
               JOIN {context} ctx
                 ON ctx.instanceid = c.id
                AND ctx.contextlevel = :coursecontext
               JOIN {role_assignments} ra
                 ON ra.contextid = ctx.id
                AND ra.userid = :roleuserid
               JOIN {role} r
                 ON r.id = ra.roleid
                AND r.shortname = :student
               JOIN {enrol} e
                 ON e.courseid = c.id
                AND e.enrol = :manual
               JOIN {user_enrolments} ue
                 ON ue.enrolid = e.id
                AND ue.userid = ra.userid
                AND ue.status IN (:active, :suspended)
              WHERE c.id <> :siteid
                AND (
                    EXISTS (
                        SELECT 1
                          FROM {local_rtcsync_deliverycourse} state
                         WHERE state.courseid = c.id
                    )
                    OR EXISTS (
                        SELECT 1
                          FROM {groupings} deliverygrouping
                         WHERE deliverygrouping.courseid = c.id
                           AND deliverygrouping.idnumber LIKE :deliverypattern
                    )
                )',
            [
                'coursecontext' => CONTEXT_COURSE,
                'roleuserid' => $userid,
                'student' => 'student',
                'manual' => 'manual',
                'active' => ENROL_USER_ACTIVE,
                'suspended' => ENROL_USER_SUSPENDED,
                'siteid' => SITEID,
                'deliverypattern' => 'rtc-delivery-grouping:%',
            ]
        );

        foreach ($courses as $course) {
            $managedcourseid = (int) $course->id;
            if (!self::is_managed_student_course_member($managedcourseid, $userid)) {
                continue;
            }
            if (has_capability(
                    'moodle/grade:viewall',
                    \context_course::instance($managedcourseid),
                    $userid
            )) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Whether the requested module belongs to the current Student delivery
     * and has a live, persisted delivery guard.
     */
    public static function student_module_content_is_in_current_delivery(
        int $courseid,
        int $cmid,
        int $userid
    ): bool {
        global $DB;

        $state = $DB->get_record(
            'local_rtcsync_deliverycourse',
            ['courseid' => $courseid],
            'ready',
            IGNORE_MISSING
        );
        if (!$state || (int) $state->ready !== 1 || !self::supports_standard_grouping_restrictions()) {
            return false;
        }

        $module = $DB->get_record_sql(
            'SELECT cm.id,
                    cm.course AS courseid,
                    cm.availability AS liveavailability,
                    cm.groupingid AS livegroupingid,
                    delivery.deliveryid,
                    delivery.groupingid,
                    delivery.lineagestatus,
                    delivery.managedavailability,
                    deliverygrouping.idnumber AS groupingidnumber
               FROM {course_modules} cm
          LEFT JOIN {local_rtcsync_deliverycm} delivery
                 ON delivery.cmid = cm.id
                AND delivery.courseid = cm.course
          LEFT JOIN {groupings} deliverygrouping
                 ON deliverygrouping.id = delivery.groupingid
                AND deliverygrouping.courseid = cm.course
              WHERE cm.id = :cmid
                AND cm.course = :courseid',
            ['cmid' => $cmid, 'courseid' => $courseid],
            IGNORE_MISSING
        );
        if (!$module
                || (int) $module->deliveryid <= 0
                || !self::has_current_module_guard($module, false)
                || !self::student_delivery_scope_allows($courseid, $userid, (int) $module->deliveryid)) {
            return false;
        }

        return $DB->record_exists_sql(
            'SELECT 1
               FROM {groupings_groups} gg
               JOIN {groups} g ON g.id = gg.groupid
               JOIN {groups_members} gm ON gm.groupid = g.id
              WHERE gg.groupingid = :groupingid
                AND g.courseid = :courseid
                AND gm.userid = :userid',
            [
                'groupingid' => (int) $module->groupingid,
                'courseid' => $courseid,
                'userid' => $userid,
            ]
        );
    }

    /**
     * Check a live course-module row against its persisted, fail-closed guard.
     *
     * @param \stdClass $module Row with id, courseid, liveavailability,
     *     livegroupingid, deliveryid, groupingid, managedavailability, and groupingidnumber.
     * @param bool $allowsentinel Whether an unmapped module's impossible grouping is acceptable.
     * @return bool True when the mapping and live restriction agree.
     */
    private static function has_current_module_guard(\stdClass $module, bool $allowsentinel): bool
    {
        if (!is_string($module->managedavailability)
                || $module->managedavailability === ''
                || $module->liveavailability !== $module->managedavailability) {
            return false;
        }

        $deliveryid = (int) $module->deliveryid;
        $groupingid = (int) $module->groupingid;
        $lineagestatus = (int) ($module->lineagestatus ?? self::LINEAGE_UNREVIEWED);
        if (!self::availability_requires_grouping($module->managedavailability, $groupingid)) {
            return false;
        }

        if ($deliveryid > 0) {
            if ($lineagestatus !== self::LINEAGE_CURRENT) {
                return false;
            }
            if (self::has_conflicting_delivery_history_mapping(
                (int) $module->id,
                (int) $module->courseid,
                $deliveryid
            )) {
                return false;
            }

            return $groupingid > 0
                && (string) $module->groupingidnumber === 'rtc-delivery-grouping:' . $deliveryid
                && ((int) $module->livegroupingid <= 0 || (int) $module->livegroupingid === $groupingid);
        }

        return $allowsentinel
            && $deliveryid === 0
            && $groupingid < 0
            && $lineagestatus !== self::LINEAGE_CURRENT
            && $module->groupingidnumber === null;
    }

    /**
     * Apply one complete SMS-authoritative delivery scope for a managed Student.
     * The caller must hold the course access lock and a delegated transaction.
     *
     * @param array<int, int> $deliveryids Complete set of active delivery IDs.
     * @return array{courseid:int,userid:int,revision:int,applied:int,deliveryids:array<int,int>}
     */
    public static function sync_student_delivery_scope_locked(
        int $courseid,
        int $userid,
        array $deliveryids,
        int $revision
    ): array {
        global $DB;

        if ($courseid <= 1 || $userid <= 1 || $revision < self::MIN_STUDENT_SCOPE_REVISION) {
            throw new \invalid_parameter_exception('A valid course, user, and supported scope revision are required.');
        }
        if (!$DB->get_manager()->table_exists('local_rtcsync_studentscope')) {
            throw new \moodle_exception('Delivery access scope storage is not ready.');
        }

        $state = $DB->get_record(
            'local_rtcsync_deliverycourse',
            ['courseid' => $courseid],
            'ready',
            IGNORE_MISSING
        );
        if (!$state || (int) $state->ready !== 1) {
            throw new \invalid_parameter_exception('The delivery-managed course is not ready for access scopes.');
        }

        $normalizedids = [];
        foreach ($deliveryids as $deliveryid) {
            $deliveryid = (int) $deliveryid;
            if ($deliveryid <= 0) {
                throw new \invalid_parameter_exception('Delivery IDs must be positive integers.');
            }
            $normalizedids[$deliveryid] = $deliveryid;
        }
        $normalizedids = array_values($normalizedids);
        sort($normalizedids, SORT_NUMERIC);

        $existing = $DB->get_record('local_rtcsync_studentscope', [
            'courseid' => $courseid,
            'userid' => $userid,
        ], '*', IGNORE_MISSING);
        if ($existing && $revision < (int) $existing->revision) {
            $currentids = self::decode_student_delivery_scope((string) $existing->deliveryids);
            if ($currentids === null) {
                throw new \moodle_exception('The stored student delivery scope is invalid.');
            }

            self::reconcile_staged_delivery_group_memberships($courseid, $userid, $currentids);

            return [
                'courseid' => $courseid,
                'userid' => $userid,
                'revision' => (int) $existing->revision,
                'applied' => 0,
                'deliveryids' => $currentids,
            ];
        }

        if ($existing && $revision === (int) $existing->revision) {
            $currentids = self::decode_student_delivery_scope((string) $existing->deliveryids);
            if ($currentids === null || $currentids !== $normalizedids) {
                throw new \invalid_parameter_exception('A scope revision cannot be reused for a different delivery set.');
            }

            self::reconcile_staged_delivery_group_memberships($courseid, $userid, $currentids);

            return [
                'courseid' => $courseid,
                'userid' => $userid,
                'revision' => $revision,
                'applied' => 0,
                'deliveryids' => $currentids,
            ];
        }

        foreach ($normalizedids as $deliveryid) {
            $groupingidnumber = 'rtc-delivery-grouping:' . $deliveryid;
            if (!$DB->record_exists('groupings', [
                'courseid' => $courseid,
                'idnumber' => $groupingidnumber,
            ])) {
                throw new \invalid_parameter_exception('Every active delivery must have a grouping in this course.');
            }
        }

        $now = time();
        $values = (object) [
            'courseid' => $courseid,
            'userid' => $userid,
            'deliveryids' => json_encode($normalizedids, JSON_THROW_ON_ERROR),
            'revision' => $revision,
            'timemodified' => $now,
        ];
        if ($existing) {
            $values->id = (int) $existing->id;
            $DB->update_record('local_rtcsync_studentscope', $values);
        } else {
            $values->timecreated = $now;
            $DB->insert_record('local_rtcsync_studentscope', $values);
        }

        self::reconcile_staged_delivery_group_memberships($courseid, $userid, $normalizedids);

        return [
            'courseid' => $courseid,
            'userid' => $userid,
            'revision' => $revision,
            'applied' => 1,
            'deliveryids' => $normalizedids,
        ];
    }

    /**
     * Reconcile stale delivery-scoped group memberships for an active RTC
     * Student before user-facing pages render. Moodle uses those memberships
     * to select group calendar events, so activity guards alone cannot prevent
     * a previous delivery's event metadata from appearing after a transfer.
     */
    public static function reconcile_user_delivery_group_memberships(int $userid): void
    {
        global $DB;

        if ($userid <= 1 || !$DB->get_manager()->table_exists('local_rtcsync_studentscope')) {
            return;
        }

        $courseids = $DB->get_fieldset('local_rtcsync_studentscope', 'courseid', ['userid' => $userid]);
        foreach ($courseids as $courseid) {
            $courseid = (int) $courseid;
            self::with_course_access_lock(
                $courseid,
                static function() use ($courseid, $userid): void {
                    self::reconcile_course_delivery_group_memberships_locked($courseid, $userid);
                }
            );
        }
    }

    /**
     * Reconcile one course's staged roster against the authoritative scope.
     * The caller must hold this course's access lock so scope reads cannot go
     * stale while applying or pruning real group membership.
     */
    public static function reconcile_course_delivery_group_memberships_locked(int $courseid, int $userid): void
    {
        global $DB;

        if ($courseid <= 1 || $userid <= 1
                || !$DB->get_manager()->table_exists('local_rtcsync_studentscope')) {
            return;
        }

        $scope = $DB->get_record('local_rtcsync_studentscope', [
            'courseid' => $courseid,
            'userid' => $userid,
        ], 'deliveryids', IGNORE_MISSING);
        if (!$scope) {
            return;
        }

        self::reconcile_delivery_group_memberships_for_scope(
            $courseid,
            $userid,
            (string) $scope->deliveryids
        );
    }

    /** Reconcile one already-read complete scope without an extra database lookup. */
    private static function reconcile_delivery_group_memberships_for_scope(
        int $courseid,
        int $userid,
        string $encodedscope
    ): void {

        $inactiveaccount = self::is_inactive_managed_user($userid);
        if (!$inactiveaccount && self::has_delivery_calendar_bypass($courseid, $userid)) {
            return;
        }

        $deliveryids = self::decode_student_delivery_scope($encodedscope);
        if ($inactiveaccount) {
            $deliveryids = [];
        }

        // The scope write can precede re-enrolment, so groups_add_member()
        // may have been unable to apply the staged roster at that time.
        self::reconcile_staged_delivery_group_memberships(
            $courseid,
            $userid,
            $deliveryids ?? []
        );
    }

    /** Deny a stale authenticated session for an inactive RTC-managed account. */
    private static function require_active_managed_user(int $userid): void
    {
        if (self::is_inactive_managed_user($userid)) {
            throw new \moodle_exception('activityiscurrentlyhidden', 'moodle');
        }
    }

    /** Whether the locally synchronized Moodle account is deleted or suspended. */
    private static function is_inactive_managed_user(int $userid): bool
    {
        global $DB;

        if ($userid <= 1
                || !$DB->get_manager()->table_exists('local_rtcsync_user')
                || !$DB->record_exists('local_rtcsync_user', ['userid' => $userid])) {
            return false;
        }

        $user = $DB->get_record('user', ['id' => $userid], 'deleted,suspended', IGNORE_MISSING);
        return !$user || !empty($user->deleted) || !empty($user->suspended);
    }

    /**
     * Remove stale memberships from delivery-scoped child groups so Moodle's
     * native group calendar filtering cannot expose previous-delivery events.
     * Grade history is keyed by retained delivery markers and does not need
     * these memberships.
     *
     * @param array<int, int> $deliveryids Current active delivery IDs.
     */
    private static function prune_out_of_scope_delivery_group_memberships(
        int $courseid,
        int $userid,
        array $deliveryids
    ): void {
        global $DB;

        $memberships = $DB->get_records_sql(
            "SELECT DISTINCT g.id, g.idnumber AS groupidnumber,
                    deliverygrouping.idnumber AS groupingidnumber
               FROM {groups_members} gm
               JOIN {groups} g ON g.id = gm.groupid AND g.courseid = :groupcourseid
               JOIN {groupings_groups} gg ON gg.groupid = g.id
               JOIN {groupings} deliverygrouping
                 ON deliverygrouping.id = gg.groupingid
                AND deliverygrouping.courseid = g.courseid
              WHERE gm.userid = :userid
                AND deliverygrouping.idnumber LIKE :deliverygrouping",
            [
                'groupcourseid' => $courseid,
                'userid' => $userid,
                'deliverygrouping' => 'rtc-delivery-grouping:%',
            ]
        );

        foreach ($memberships as $membership) {
            $groupingmatch = [];
            $groupmatch = [];
            if (preg_match('/^rtc-delivery-grouping:([1-9][0-9]*)$/', (string) $membership->groupingidnumber, $groupingmatch) !== 1
                    || preg_match(
                        '/^rtc-delivery-class-group:([1-9][0-9]*):([^:]+)$/',
                        (string) $membership->groupidnumber,
                        $groupmatch
                    ) !== 1
                    || $groupmatch[1] !== $groupingmatch[1]) {
                continue;
            }

            if (!in_array((int) $groupingmatch[1], $deliveryids, true)) {
                groups_remove_member((int) $membership->id, $userid);
            }
        }
    }

    /** Whether one delivery is in the active scope for a non-suspended managed learner. */
    public static function student_delivery_scope_allows(int $courseid, int $userid, int $deliveryid): bool
    {
        if ($deliveryid <= 0 || self::is_inactive_managed_user($userid)) {
            return false;
        }

        $deliveryids = self::student_current_delivery_scope($courseid, $userid);

        return $deliveryids !== null && in_array($deliveryid, $deliveryids, true);
    }

    /**
     * Persist a desired delivery roster, returning only currently enrolled
     * Student members authorized by the effective delivery scope.
     *
     * @param array<int, int> $userids Desired members from SMS delivery sync.
     * @return array<int, int> Active scoped Students that may be added now.
     */
    public static function stage_delivery_group_roster(
        int $courseid,
        int $groupid,
        int $deliveryid,
        array $userids
    ): array {
        global $DB;

        if ($courseid <= 1 || $groupid <= 0 || $deliveryid <= 0) {
            throw new \invalid_parameter_exception('A valid course, group, and delivery are required.');
        }
        if (!$DB->get_manager()->table_exists('local_rtcsync_deliverymember')) {
            throw new \moodle_exception('Delivery roster staging storage is not ready.');
        }

        $userids = array_values(array_unique(array_map('intval', $userids)));
        foreach ($userids as $userid) {
            if ($userid <= 1) {
                throw new \invalid_parameter_exception('Delivery roster user IDs must be positive Moodle users.');
            }
        }

        $DB->delete_records('local_rtcsync_deliverymember', [
            'courseid' => $courseid,
            'groupid' => $groupid,
        ]);
        $now = time();
        foreach ($userids as $userid) {
            $DB->insert_record('local_rtcsync_deliverymember', (object) [
                'courseid' => $courseid,
                'groupid' => $groupid,
                'userid' => $userid,
                'timecreated' => $now,
            ]);
        }

        return array_values(array_filter(
            $userids,
            static fn(int $userid): bool => self::is_active_manual_student($courseid, $userid)
                && self::student_delivery_scope_allows($courseid, $userid, $deliveryid)
        ));
    }

    /** Apply staged memberships only while scope and active Student enrolment authorize them. */
    private static function apply_staged_delivery_group_memberships(
        int $courseid,
        int $userid,
        array $deliveryids
    ): void {
        global $DB;

        if ($deliveryids === []
                || self::is_inactive_managed_user($userid)
                || !self::is_active_manual_student($courseid, $userid)
                || !$DB->get_manager()->table_exists('local_rtcsync_deliverymember')) {
            return;
        }

        $memberships = $DB->get_records_sql(
            "SELECT desired.groupid,
                    deliverygrouping.idnumber AS groupingidnumber,
                    g.idnumber AS groupidnumber
               FROM {local_rtcsync_deliverymember} desired
               JOIN {groups} g
                 ON g.id = desired.groupid
                AND g.courseid = desired.courseid
               JOIN {groupings_groups} gg ON gg.groupid = g.id
               JOIN {groupings} deliverygrouping
                 ON deliverygrouping.id = gg.groupingid
                AND deliverygrouping.courseid = g.courseid
              WHERE desired.courseid = :courseid
                AND desired.userid = :userid
                AND deliverygrouping.idnumber LIKE :deliverypattern",
            [
                'courseid' => $courseid,
                'userid' => $userid,
                'deliverypattern' => 'rtc-delivery-grouping:%',
            ]
        );

        foreach ($memberships as $membership) {
            $groupingmatch = [];
            $groupmatch = [];
            if (preg_match('/^rtc-delivery-grouping:([1-9][0-9]*)$/', (string) $membership->groupingidnumber, $groupingmatch) !== 1
                    || preg_match(
                        '/^rtc-delivery-class-group:([1-9][0-9]*):([^:]+)$/',
                        (string) $membership->groupidnumber,
                        $groupmatch
                    ) !== 1
                    || $groupmatch[1] !== $groupingmatch[1]
                    || !in_array((int) $groupingmatch[1], $deliveryids, true)) {
                continue;
            }

            groups_add_member((int) $membership->groupid, $userid);
        }
    }

    /** Reconcile staged memberships and real Moodle groups after each scope write/replay. */
    private static function reconcile_staged_delivery_group_memberships(
        int $courseid,
        int $userid,
        array $deliveryids
    ): void {
        $canapply = !self::is_inactive_managed_user($userid)
            && self::is_active_manual_student($courseid, $userid);
        if (!$canapply) {
            // Keep the desired roster in the staging table for later
            // reactivation, but remove all real delivery memberships while
            // this user cannot participate in the course.
            $deliveryids = [];
        } else {
            self::apply_staged_delivery_group_memberships($courseid, $userid, $deliveryids);
        }

        self::prune_out_of_scope_delivery_group_memberships($courseid, $userid, $deliveryids);
    }

    /**
     * Read the current complete scope, or null when no trustworthy scope exists.
     *
     * @return array<int, int>|null
     */
    private static function student_current_delivery_scope(int $courseid, int $userid): ?array
    {
        global $DB;

        if ($courseid <= 1 || $userid <= 1
                || !$DB->get_manager()->table_exists('local_rtcsync_studentscope')) {
            return null;
        }

        $scope = $DB->get_record('local_rtcsync_studentscope', [
            'courseid' => $courseid,
            'userid' => $userid,
        ], 'deliveryids', IGNORE_MISSING);
        if (!$scope) {
            return null;
        }

        return self::decode_student_delivery_scope((string) $scope->deliveryids);
    }

    /** @return array<int, int>|null */
    private static function decode_student_delivery_scope(string $encoded): ?array
    {
        $deliveryids = json_decode($encoded, true);
        if (!is_array($deliveryids) || !array_is_list($deliveryids)) {
            return null;
        }

        $normalizedids = [];
        foreach ($deliveryids as $deliveryid) {
            if (!is_int($deliveryid) || $deliveryid <= 0) {
                return null;
            }
            $normalizedids[$deliveryid] = $deliveryid;
        }
        $normalizedids = array_values($normalizedids);
        sort($normalizedids, SORT_NUMERIC);

        return $normalizedids;
    }

    /** Ensure the availability tree's top-level AND requires this exact grouping. */
    private static function availability_requires_grouping(string $availability, int $groupingid): bool
    {
        $root = json_decode($availability);
        if (!is_object($root)
                || ($root->op ?? null) !== \core_availability\tree::OP_AND
                || !isset($root->c)
                || !is_array($root->c)) {
            return false;
        }

        foreach ($root->c as $condition) {
            if (is_object($condition)
                    && ($condition->type ?? null) === 'grouping'
                    && isset($condition->id)
                    && is_int($condition->id)
                    && $condition->id === $groupingid) {
                return true;
            }
        }

        return false;
    }

    /** Whether the delivery guard has been initialized for any course. */
    public static function has_guarded_courses(): bool
    {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_rtcsync_deliverycourse')) {
            return false;
        }
        return $DB->record_exists('local_rtcsync_deliverycourse', [])
            || $DB->record_exists_sql(
                "SELECT 1
                   FROM {groupings} g
                  WHERE g.idnumber LIKE :deliverypattern",
                ['deliverypattern' => 'rtc-delivery-grouping:%']
            );
    }

    /** Enable the grouping selector for a managed subject course. */
    public static function ensure_grouping_ui_enabled(int $courseid): void
    {
        self::with_course_lock($courseid, static function() use ($courseid): void {
            global $DB;

            self::get_or_create_course_state($courseid);
            self::ensure_course_group_mode($courseid);
            $state = $DB->get_record('local_rtcsync_deliverycourse', ['courseid' => $courseid], '*', MUST_EXIST);
            if ((int) $state->ready !== 1) {
                // Course sync can run before delivery-group sync. Keep every
                // pre-existing activity closed until a complete guard pass.
                self::lock_course($courseid);
                self::suspend_active_managed_students($courseid);
            }
        });
    }

    /** Update a subject course and its retained visibility under the access lock. */
    public static function synchronize_course_metadata(
        int $courseid,
        callable $update,
        int $requestedvisibility
    ): \stdClass {
        global $DB;

        return self::with_course_lock($courseid, static function() use (
            $courseid,
            $update,
            $requestedvisibility
        ): \stdClass {
            global $DB;

            self::get_or_create_course_state($courseid);
            self::ensure_course_group_mode($courseid);
            $state = $DB->get_record('local_rtcsync_deliverycourse', ['courseid' => $courseid], '*', MUST_EXIST);
            if ((int) $state->ready !== 1) {
                self::lock_course($courseid);
                self::suspend_active_managed_students($courseid);
            }

            $update();

            $state = $DB->get_record('local_rtcsync_deliverycourse', ['courseid' => $courseid], '*', MUST_EXIST);
            $state->coursevisible = $requestedvisibility ? 1 : 0;
            $state->timemodified = self::next_state_modified($state);
            $DB->update_record('local_rtcsync_deliverycourse', $state);
            if ((int) $state->ready !== 1) {
                // update_course applies its requested visibility; restore the
                // lock while the bounded module guard is still pending.
                self::lock_course($courseid);
                self::suspend_active_managed_students($courseid);
            }

            return $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        });
    }

    /**
     * Start or continue preparing one managed subject course for delivery access.
     *
     * @param int $courseid Moodle course ID.
     * @param int $limit Maximum course modules processed in this call.
     * @return bool True when all existing modules have a guard.
     */
    public static function prepare_course(int $courseid, int $limit = self::MODULE_PAGE_SIZE): bool
    {
        $limit = max(1, min(self::MODULE_PAGE_SIZE, $limit));
        return self::with_course_lock(
            $courseid,
            static fn(): bool => self::prepare_course_locked($courseid, $limit)
        );
    }

    /** Keep readiness valid until the supplied active-student write completes. */
    public static function with_ready_course(int $courseid, callable $callback)
    {
        return self::with_course_lock($courseid, static function() use ($courseid, $callback) {
            if (!self::prepare_course_locked($courseid, self::MODULE_PAGE_SIZE)) {
                throw new \invalid_parameter_exception(
                    'Delivery activity access preparation is incomplete; active enrolment was not granted.'
                );
            }
            return $callback();
        });
    }

    /** Perform preparation while the caller holds the per-course lock. */
    private static function prepare_course_locked(int $courseid, int $limit): bool
    {
        global $DB;

        $limit = max(1, min(self::MODULE_PAGE_SIZE, $limit));
        $state = self::get_or_create_course_state($courseid);
        self::ensure_course_group_mode($courseid);

        if (!self::supports_standard_grouping_restrictions()) {
            $state->ready = 0;
            $state->lastcmid = 0;
            $state->timemodified = self::next_state_modified($state);
            $DB->update_record('local_rtcsync_deliverycourse', $state);
            self::lock_course($courseid);
            self::suspend_active_managed_students($courseid);
            return false;
        }

        if ((int) $state->ready === 1) {
            $maxcmid = (int) $DB->get_field_sql(
                'SELECT COALESCE(MAX(id), 0) FROM {course_modules} WHERE course = :courseid',
                ['courseid' => $courseid]
            );
            if ($maxcmid <= (int) $state->lastcmid) {
                return true;
            }
            $state->ready = 0;
            $state->timemodified = self::next_state_modified($state);
            $DB->update_record('local_rtcsync_deliverycourse', $state);
        }

        self::lock_course($courseid);
        self::suspend_active_managed_students($courseid);

        self::process_course_page($courseid, $limit);
        return (int) $DB->get_field('local_rtcsync_deliverycourse', 'ready', ['courseid' => $courseid]) === 1;
    }

    /**
     * Reconcile one course's delivery grouping while preparation/backfill is
     * excluded by the same per-course Moodle lock.
     *
     * @param int $courseid Moodle course ID.
     * @param callable $reconcile Grouping mutation callback.
     * @return bool True if the bounded guard pass completed.
     */
    public static function synchronize_grouping(int $courseid, callable $reconcile): bool
    {
        global $DB;

        return self::with_course_lock($courseid, static function() use ($courseid, $reconcile): bool {
            global $DB;

            $state = self::get_or_create_course_state($courseid);
            $state->ready = 0;
            $state->lastcmid = 0;
            $state->timemodified = self::next_state_modified($state);
            $DB->update_record('local_rtcsync_deliverycourse', $state);
            self::ensure_course_group_mode($courseid);
            self::lock_course($courseid);
            self::suspend_active_managed_students($courseid);
            try {
                $reconcile();
            } catch (\Throwable $exception) {
                self::fail_closed_course($courseid);
                throw $exception;
            }
            if (!self::supports_standard_grouping_restrictions()) {
                self::fail_closed_course($courseid);
                return false;
            }

            self::process_course_page($courseid, self::MODULE_PAGE_SIZE);
            return (int) $DB->get_field('local_rtcsync_deliverycourse', 'ready', ['courseid' => $courseid]) === 1;
        });
    }

    /**
     * Process bounded work for every pending delivery course.
     *
     * @param int $courselimit Maximum number of courses to inspect.
     * @param int $modulelimit Maximum modules per course in this run.
     * @return int Number of module rows guarded.
     */
    public static function backfill_pending_courses(int $courselimit = 10, int $modulelimit = self::MODULE_PAGE_SIZE): int
    {
        global $DB;

        $courselimit = max(1, min(50, $courselimit));
        $modulelimit = max(1, min(self::MODULE_PAGE_SIZE, $modulelimit));
        if (!self::supports_standard_grouping_restrictions()) {
            self::enforce_availability_lockdown();
            return 0;
        }

        $states = $DB->get_records('local_rtcsync_deliverycourse', ['ready' => 0], 'id ASC', '*', 0, $courselimit);
        $processed = 0;
        foreach ($states as $state) {
            $courseid = (int) $state->courseid;
            $processed += self::with_course_lock($courseid, static function() use ($courseid, $modulelimit): int {
                global $DB;

                $current = $DB->get_record('local_rtcsync_deliverycourse', ['courseid' => $courseid], '*', IGNORE_MISSING);
                if (!$current || (int) $current->ready === 1) {
                    return 0;
                }
                if (!self::supports_standard_grouping_restrictions()) {
                    self::fail_closed_course($courseid);
                    return 0;
                }
                self::lock_course($courseid);
                self::suspend_active_managed_students($courseid);
                return self::process_course_page($courseid, $modulelimit);
            });
        }

        return $processed;
    }

    /**
     * When availability has been disabled, revoke readiness from ready courses
     * in bounded batches so a scheduled run cannot leave their access active.
     */
    /** Hide and pend all guarded courses when availability cannot enforce guards. */
    public static function enforce_availability_lockdown(): void
    {
        global $DB;

        if (self::supports_standard_grouping_restrictions()
                || !$DB->get_manager()->table_exists('local_rtcsync_deliverycourse')) {
            return;
        }
        $courses = $DB->get_records_sql(
            "SELECT DISTINCT c.id AS courseid
               FROM {course} c
              WHERE c.id <> :siteid
                AND (
                    EXISTS (
                        SELECT 1
                          FROM {local_rtcsync_deliverycourse} state
                         WHERE state.courseid = c.id
                    )
                    OR EXISTS (
                        SELECT 1
                          FROM {groupings} g
                         WHERE g.courseid = c.id
                           AND g.idnumber LIKE :deliverypattern
                    )
                )
              ORDER BY c.id ASC",
            [
                'siteid' => SITEID,
                'deliverypattern' => 'rtc-delivery-grouping:%',
            ]
        );
        foreach ($courses as $course) {
            $courseid = (int) $course->courseid;
            try {
                self::with_course_lock($courseid, static function() use ($courseid): void {
                    global $DB;

                    $current = self::get_or_create_course_state($courseid);
                    if ((int) $current->ready === 1 || (int) $current->lastcmid !== 0) {
                        $current->ready = 0;
                        $current->lastcmid = 0;
                        $current->timemodified = self::next_state_modified($current);
                        $DB->update_record('local_rtcsync_deliverycourse', $current);
                    }
                    self::lock_course($courseid);
                    self::suspend_active_managed_students($courseid);
                });
            } catch (\Throwable $exception) {
                // Availability is already disabled. If a concurrent sync owns
                // the lock, still revoke ready state and visibility now; its
                // page-level compare-and-update cannot publish stale readiness.
                self::fail_closed_course($courseid);
            }
        }
    }

    /** Run per-course access mutations under Moodle's shared locking API. */
    private static function with_course_lock(int $courseid, callable $callback)
    {
        $factory = \core\lock\lock_config::get_lock_factory(self::LOCK_TYPE);
        $lock = $factory->get_lock('course:' . $courseid, self::LOCK_WAIT_SECONDS);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }
        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /** Return a per-course state timestamp that also changes within one second. */
    private static function next_state_modified(\stdClass $state): int
    {
        return max(time(), (int) $state->timemodified + 1);
    }

    /** Public lock entry point for related marker/score writes. */
    public static function with_course_access_lock(int $courseid, callable $callback)
    {
        return self::with_course_lock($courseid, $callback);
    }

    /** Ensure teachers can assign activities to a grouping in this subject course. */
    private static function ensure_course_group_mode(int $courseid): void
    {
        global $CFG, $DB;

        require_once($CFG->libdir . '/grouplib.php');
        $groupmode = $DB->get_field('course', 'groupmode', ['id' => $courseid], MUST_EXIST);
        if ((int) $groupmode === NOGROUPS) {
            $DB->set_field('course', 'groupmode', SEPARATEGROUPS, ['id' => $courseid]);
            rebuild_course_cache($courseid, true);
        }
    }

    /**
     * Apply a delivery grouping condition to one module, or an impossible
     * grouping condition when the module is untagged.
     *
     * @param int $cmid Moodle course-module ID.
     * @return bool Whether this module belongs to a delivery-managed course.
     */
    public static function protect_course_module(int $cmid, bool $rebuildcache = true): bool
    {
        global $DB;

        $cm = $DB->get_record_sql(
            'SELECT cm.id, cm.course, cm.groupingid, cm.availability
               FROM {course_modules} cm
              WHERE cm.id = :cmid',
            ['cmid' => $cmid],
            IGNORE_MISSING
        );
        if (!$cm) {
            return false;
        }

        if (!self::is_enabled((int) $cm->course)) {
            return false;
        }

        $existing = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => $cmid], '*', IGNORE_MISSING);
        $now = time();
        $editedavailability = $cm->availability;
        if ($existing && $cm->availability !== $existing->managedavailability) {
            $editedavailability = self::remove_previous_managed_guard(
                $cm->availability,
                $existing->managedavailability
            );
        }
        $trustedcurrent = $existing
            && (int) $existing->lineagestatus === self::LINEAGE_CURRENT;
        if ($trustedcurrent) {
            [$deliveryid, $groupingid] = self::delivery_grouping_for_module(
                (int) $cm->course,
                (int) $cm->groupingid,
                $cmid,
                $editedavailability
            );
            if ((int) $existing->deliveryid > 0
                    && ((int) $existing->deliveryid !== $deliveryid
                        || (int) $existing->courseid !== (int) $cm->course)) {
                self::retain_previous_delivery_history_mapping(
                    $cmid,
                    (int) $existing->courseid,
                    (int) $existing->deliveryid,
                    (string) ($existing->lineagemanifestid ?? '')
                );
                $existing->lineagestatus = self::LINEAGE_HISTORICAL;
            }
            if ($deliveryid > 0 && self::has_conflicting_delivery_history_mapping(
                $cmid,
                (int) $cm->course,
                $deliveryid
            )) {
                // Moodle submissions and attempts belong to the activity instance,
                // not to an RTC delivery. Keep a previously used module bound to
                // its original delivery; a new delivery needs a new course module.
                $deliveryid = 0;
                $groupingid = -max(1, $cmid);
                $existing->lineagestatus = self::LINEAGE_HISTORICAL;
            }
        } else {
            // A current grouping identifies only today's delivery. It cannot
            // establish where a legacy attempt or grade belongs. Keep every
            // unreviewed/restored module inaccessible until an approved
            // reconciliation manifest assigns its lineage or disposition.
            $deliveryid = 0;
            $groupingid = -max(1, $cmid);
        }
        if (!$existing) {
            $originalavailability = $cm->availability;
            $existing = (object) [
                'cmid' => $cmid,
                'courseid' => (int) $cm->course,
                'deliveryid' => $deliveryid,
                'groupingid' => $groupingid,
                'lineagestatus' => self::LINEAGE_UNREVIEWED,
                'lineagemanifestid' => null,
                'originalavailability' => $originalavailability,
                'managedavailability' => null,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $existing->id = $DB->insert_record('local_rtcsync_deliverycm', $existing);
        } else {
            // Preserve Moodle edits made after the previous guard pass.
            if ($cm->availability !== $existing->managedavailability) {
                $existing->originalavailability = $editedavailability;
            }
            $existing->courseid = (int) $cm->course;
            $existing->deliveryid = $deliveryid;
            $existing->groupingid = $groupingid;
            $existing->timemodified = $now;
        }

        $condition = \availability_grouping\condition::get_json($groupingid);
        $availability = self::availability_with_required_condition(
            $existing->originalavailability,
            $condition
        );
        $existing->managedavailability = $availability;
        $DB->update_record('local_rtcsync_deliverycm', $existing);
        $DB->set_field('course_modules', 'availability', $availability, ['id' => $cmid]);

        // Keep the cursor current when an event handles a newly-created module.
        $state = $DB->get_record('local_rtcsync_deliverycourse', ['courseid' => (int) $cm->course], '*', IGNORE_MISSING);
        if ($state && (int) $state->ready === 1 && $cmid > (int) $state->lastcmid) {
            $state->lastcmid = $cmid;
            $state->timemodified = self::next_state_modified($state);
            $DB->update_record('local_rtcsync_deliverycourse', $state);
        }

        if ($rebuildcache) {
            rebuild_course_cache((int) $cm->course, true);
        }
        return true;
    }

    /** Preserve old result scope without changing the module's current access restriction. */
    private static function retain_previous_delivery_history_mapping(
        int $cmid,
        int $courseid,
        int $deliveryid,
        string $manifestid = ''
    ): void
    {
        global $DB;

        if ($deliveryid <= 0 || !$DB->get_manager()->table_exists('local_rtcsync_deliverycmhist')
                || $DB->record_exists('local_rtcsync_deliverycmhist', [
            'cmid' => $cmid,
            'courseid' => $courseid,
            'deliveryid' => $deliveryid,
        ])) {
            return;
        }

        $DB->insert_record('local_rtcsync_deliverycmhist', (object) [
            'cmid' => $cmid,
            'courseid' => $courseid,
            'deliveryid' => $deliveryid,
            'timecreated' => time(),
            'lineageverified' => 1,
            'lineagemanifestid' => $manifestid !== '' ? $manifestid : null,
        ]);
    }

    /** Whether a module has already belonged to a different delivery. */
    private static function has_conflicting_delivery_history_mapping(
        int $cmid,
        int $courseid,
        int $deliveryid
    ): bool {
        global $DB;

        if ($deliveryid <= 0) {
            return false;
        }
        if (!$DB->get_manager()->table_exists('local_rtcsync_deliverycmhist')) {
            return true;
        }

        return $DB->record_exists_select(
            'local_rtcsync_deliverycmhist',
            'cmid = :cmid AND courseid = :courseid AND deliveryid <> :deliveryid',
            ['cmid' => $cmid, 'courseid' => $courseid, 'deliveryid' => $deliveryid]
        );
    }

    /**
     * Enforce the guard when Moodle creates or edits a course module.
     *
     * @param int $cmid Moodle course-module ID.
     */
    public static function handle_module_event(int $cmid): void
    {
        global $DB;

        $courseid = (int) $DB->get_field('course_modules', 'course', ['id' => $cmid], IGNORE_MISSING);
        if ($courseid <= 1) {
            return;
        }
        try {
            self::with_course_lock($courseid, static function() use ($DB, $cmid, $courseid): void {
                if (!self::is_enabled($courseid)) {
                    return;
                }
                if (!self::supports_standard_grouping_restrictions()
                        || !$DB->record_exists('local_rtcsync_deliverycourse', ['courseid' => $courseid])) {
                    // Register, enable the grouping UI, and hide the course
                    // before backfilling any existing modules.
                    self::prepare_course_locked($courseid, self::MODULE_PAGE_SIZE);
                    return;
                }

                self::protect_course_module($cmid);
            });
        } catch (\Throwable $exception) {
            // The course-module write has already happened when Moodle emits
            // this event. If a lock timeout or guard error escapes, close the
            // course before returning so an event exception cannot expose it.
            try {
                if (self::is_enabled($courseid)) {
                    self::fail_closed_course($courseid);
                }
            } catch (\Throwable $failclosedexception) {
                // Keep the original failure as the main cause while surfacing
                // that the emergency lockdown itself could not complete.
                throw new \moodle_exception(
                    'error',
                    'local_rtcsync',
                    '',
                    null,
                    $exception->getMessage() . '; lockdown failed: ' . $failclosedexception->getMessage()
                );
            }
            throw $exception;
        }
    }

    /**
     * Find an exact RTC delivery grouping for this module. Unknown groupings
     * are deliberately treated as untagged.
     *
     * @return array{0:int,1:int} Delivery ID and grouping ID (negative CM ID on failure).
     */
    private static function delivery_grouping_for_module(
        int $courseid,
        int $groupingid,
        int $cmid,
        ?string $availability
    ): array
    {
        global $DB;

        if ($groupingid > 0) {
            $grouping = $DB->get_record('groupings', [
                'id' => $groupingid,
                'courseid' => $courseid,
            ], 'id,idnumber', IGNORE_MISSING);
            if ($grouping && preg_match('/^rtc-delivery-grouping:([1-9][0-9]*)$/', (string) $grouping->idnumber, $matches)) {
                return [(int) $matches[1], $groupingid];
            }
            return [0, -max(1, $cmid)];
        }

        // Page/resource activities have no grouping selector. Their standard
        // Restrict access JSON can still carry one explicit delivery grouping.
        $matches = [];
        if ($availability !== null && trim($availability) !== '') {
            $tree = json_decode($availability);
            if (is_object($tree)) {
                self::collect_delivery_groupings($tree, $courseid, $matches);
            }
        }
        if (count($matches) === 1) {
            $match = reset($matches);
            return [(int) $match['deliveryid'], (int) $match['groupingid']];
        }

        // Moodle's grouping condition treats a missing grouping as inaccessible.
        // A missing or ambiguous restriction stays inaccessible.
        return [0, -max(1, $cmid)];
    }

    /**
     * Return the exact grouping already present on a target module for manifest
     * consistency validation. Callers must compare this with an explicit
     * reviewed delivery ID; this method never authorizes or persists a mapping.
     *
     * @return array{0:int,1:int} Observed delivery ID and grouping ID.
     */
    public static function manifest_grouping_reference(
        int $courseid,
        int $groupingid,
        int $cmid,
        ?string $availability
    ): array {
        global $DB;

        [$deliveryid, $resolvedgroupingid] = self::delivery_grouping_for_module(
            $courseid,
            $groupingid,
            $cmid,
            $availability
        );
        if ($deliveryid <= 0 || count($DB->get_records('groupings', [
            'courseid' => $courseid,
            'idnumber' => 'rtc-delivery-grouping:' . $deliveryid,
        ], 'id ASC', 'id')) !== 1) {
            return [0, -max(1, $cmid)];
        }
        return [$deliveryid, $resolvedgroupingid];
    }

    /** Collect exact RTC delivery grouping conditions from availability JSON. */
    private static function collect_delivery_groupings(\stdClass $node, int $courseid, array &$matches): void
    {
        global $DB;

        if (($node->type ?? null) === 'grouping' && isset($node->id) && is_int($node->id) && $node->id > 0) {
            $grouping = $DB->get_record('groupings', [
                'id' => $node->id,
                'courseid' => $courseid,
            ], 'id,idnumber', IGNORE_MISSING);
            if ($grouping && preg_match('/^rtc-delivery-grouping:([1-9][0-9]*)$/', (string) $grouping->idnumber, $idmatches)) {
                $matches[(int) $grouping->id] = [
                    'deliveryid' => (int) $idmatches[1],
                    'groupingid' => (int) $grouping->id,
                ];
            }
        }

        if (isset($node->c) && is_array($node->c)) {
            foreach ($node->c as $child) {
                if (is_object($child)) {
                    self::collect_delivery_groupings($child, $courseid, $matches);
                }
            }
        }
    }

    /**
     * Remove one previous hidden RTC grouping guard from a teacher-edited
     * availability tree before preserving the teacher's updated conditions.
     */
    private static function remove_previous_managed_guard(?string $current, ?string $previousmanaged): ?string
    {
        if ($current === null || trim($current) === '' || $previousmanaged === null || trim($previousmanaged) === '') {
            return $current;
        }
        $currenttree = json_decode($current);
        $managedtree = json_decode($previousmanaged);
        if (!is_object($currenttree) || !is_object($managedtree)
                || ($managedtree->op ?? null) !== \core_availability\tree::OP_AND
                || !isset($managedtree->c, $managedtree->showc)
                || !is_array($managedtree->c) || !is_array($managedtree->showc)
                || count($managedtree->c) !== count($managedtree->showc)) {
            return $current;
        }

        $managedcondition = null;
        foreach ($managedtree->showc as $index => $showcondition) {
            if ($showcondition === false && isset($managedtree->c[$index])
                    && is_object($managedtree->c[$index])
                    && (($managedtree->c[$index]->type ?? null) === 'grouping')) {
                $managedcondition = $managedtree->c[$index];
                break;
            }
        }
        if (!$managedcondition || !isset($currenttree->c) || !is_array($currenttree->c)) {
            return $current;
        }

        $conditionjson = json_encode($managedcondition);
        $removed = false;
        $children = [];
        $showchildren = isset($currenttree->showc) && is_array($currenttree->showc)
            ? $currenttree->showc
            : [];
        $newshowchildren = [];
        foreach ($currenttree->c as $index => $child) {
            if (!$removed && json_encode($child) === $conditionjson) {
                $removed = true;
                continue;
            }
            $children[] = $child;
            if (array_key_exists($index, $showchildren)) {
                $newshowchildren[] = $showchildren[$index];
            }
        }
        if (!$removed) {
            return $current;
        }

        $currenttree->c = $children;
        if (isset($currenttree->showc)) {
            $currenttree->showc = $newshowchildren;
        }
        $encoded = json_encode($currenttree);
        return $encoded === false ? $current : $encoded;
    }

    /**
     * Add an AND grouping condition while retaining teacher-defined restrictions.
     *
     * @param string|null $base Original availability JSON.
     * @param \stdClass $condition Grouping condition JSON.
     * @return string Encoded availability JSON.
     */
    private static function availability_with_required_condition(?string $base, \stdClass $condition): string
    {
        $original = null;
        if ($base !== null && trim($base) !== '') {
            $original = json_decode($base);
            if (!$original || !is_object($original)) {
                throw new \coding_exception('Cannot protect an activity with malformed availability JSON.');
            }
            // Validate the tree with Moodle's standard availability parser.
            new \core_availability\tree($original, true);
        } else {
            $original = \core_availability\tree::get_root_json([]);
        }

        $root = \core_availability\tree::get_root_json(
            [$original, $condition],
            \core_availability\tree::OP_AND,
            [true, false]
        );
        $encoded = json_encode($root);
        if ($encoded === false) {
            throw new \coding_exception('Could not encode delivery availability restriction.');
        }
        return $encoded;
    }

    /** Lock a course while a bounded guard pass is incomplete. */
    private static function lock_course(int $courseid): void
    {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid], 'id,visible', IGNORE_MISSING);
        if ($course && (int) $course->visible !== 0) {
            $DB->set_field('course', 'visible', 0, ['id' => $courseid]);
            rebuild_course_cache($courseid, true);
        }
    }

    /** Suspend managed Student manual enrolments without deleting markers or roles. */
    private static function suspend_active_managed_students(int $courseid): void
    {
        global $DB;

        $instance = $DB->get_record('enrol', [
            'courseid' => $courseid,
            'enrol' => 'manual',
        ], '*', IGNORE_MISSING);
        if (!$instance) {
            return;
        }

        $users = $DB->get_records_sql(
            "SELECT DISTINCT ue.userid
               FROM {enrol} e
               JOIN {user_enrolments} ue ON ue.enrolid = e.id
               JOIN {local_rtcsync_user} managed ON managed.userid = ue.userid
               JOIN {context} ctx ON ctx.instanceid = e.courseid AND ctx.contextlevel = :coursecontext
               JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = ue.userid
               JOIN {role} r ON r.id = ra.roleid AND r.shortname = :student
              WHERE e.courseid = :courseid
                AND e.enrol = :manual
                AND ue.status = :active",
            [
                'coursecontext' => CONTEXT_COURSE,
                'student' => 'student',
                'courseid' => $courseid,
                'manual' => 'manual',
                'active' => ENROL_USER_ACTIVE,
            ]
        );
        $plugin = enrol_get_plugin('manual');
        foreach ($users as $record) {
            $plugin->update_user_enrol($instance, (int) $record->userid, ENROL_USER_SUSPENDED);
        }
    }

    /**
     * Hide a course and suspend its managed RTC student enrollments after a
     * synchronous module guard failure.
     */
    private static function fail_closed_course(int $courseid): void
    {
        global $DB;

        $state = self::get_or_create_course_state($courseid);
        $state->ready = 0;
        $state->lastcmid = 0;
        $state->timemodified = self::next_state_modified($state);
        $DB->update_record('local_rtcsync_deliverycourse', $state);
        $DB->set_field('course', 'visible', 0, ['id' => $courseid]);
        rebuild_course_cache($courseid, true);
        self::suspend_active_managed_students($courseid);
    }

    /** Get or initialize guarded-access state for one managed subject course. */
    private static function get_or_create_course_state(int $courseid): \stdClass
    {
        global $DB;

        $state = $DB->get_record('local_rtcsync_deliverycourse', ['courseid' => $courseid], '*', IGNORE_MISSING);
        if ($state) {
            return $state;
        }

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $hasdeliverygrouping = $DB->record_exists_select(
            'groupings',
            'courseid = :courseid AND idnumber LIKE :deliverypattern',
            ['courseid' => $courseid, 'deliverypattern' => 'rtc-delivery-grouping:%']
        );
        if (!str_starts_with((string) $course->idnumber, 'rtc-subject:') && !$hasdeliverygrouping) {
            throw new \invalid_parameter_exception('Delivery access can only be prepared for a managed subject course.');
        }

        $now = time();
        $state = (object) [
            'courseid' => $courseid,
            'coursevisible' => (int) $course->visible,
            'ready' => 0,
            'lastcmid' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $state->id = $DB->insert_record('local_rtcsync_deliverycourse', $state);
        return $state;
    }

    /**
     * Guard one bounded page of course modules, restoring course visibility only
     * after the final page has succeeded.
     *
     * @param int $courseid Moodle course ID.
     * @param int $limit Maximum modules to process.
     * @return int Number of modules processed.
     */
    private static function process_course_page(int $courseid, int $limit): int
    {
        global $DB;

        $limit = max(1, min(self::MODULE_PAGE_SIZE, $limit));
        $state = $DB->get_record('local_rtcsync_deliverycourse', ['courseid' => $courseid], '*', MUST_EXIST);
        $expectedstate = clone $state;
        $modules = $DB->get_records_select(
            'course_modules',
            'course = :courseid AND id > :lastcmid',
            ['courseid' => $courseid, 'lastcmid' => (int) $state->lastcmid],
            'id ASC',
            '*',
            0,
            $limit
        );
        foreach ($modules as $module) {
            self::protect_course_module((int) $module->id, false);
        }
        if ($modules) {
            rebuild_course_cache($courseid, true);
        }

        $lastcmid = $modules ? (int) end($modules)->id : (int) $state->lastcmid;
        if (!self::supports_standard_grouping_restrictions()) {
            self::fail_closed_course($courseid);
            return count($modules);
        }
        $ready = count($modules) < $limit;
        $statemodified = self::next_state_modified($expectedstate);
        if (!self::compare_and_update_course_state(
                $expectedstate,
                (int) $ready,
                $lastcmid,
                $statemodified
        )) {
            // An out-of-lock fail-closed signal changed the state while this
            // page was running. Do not publish stale readiness or visibility.
            return count($modules);
        }
        if ($ready) {
            $restorevisible = (int) $DB->get_field(
                'local_rtcsync_deliverycourse',
                'coursevisible',
                ['courseid' => $courseid],
                MUST_EXIST
            );
            $DB->execute(
                'UPDATE {course}
                    SET visible = :visible
                  WHERE id = :courseid
                    AND EXISTS (
                        SELECT 1
                          FROM {local_rtcsync_deliverycourse} accessstate
                         WHERE accessstate.courseid = :statecourseid
                           AND accessstate.ready = 1
                           AND accessstate.timemodified = :statetime
                           AND accessstate.coursevisible = :restorevisible
                    )',
                [
                    'visible' => $restorevisible,
                    'courseid' => $courseid,
                    'statecourseid' => $courseid,
                    'statetime' => $statemodified,
                    'restorevisible' => $restorevisible,
                ]
            );
            rebuild_course_cache($courseid, true);
        }

        return count($modules);
    }

    /** Update a page cursor only if no fail-closed operation changed its snapshot. */
    private static function compare_and_update_course_state(
        \stdClass $expected,
        int $ready,
        int $lastcmid,
        int $timemodified
    ): bool {
        global $DB;

        $DB->execute(
            'UPDATE {local_rtcsync_deliverycourse}
                SET ready = :newready,
                    lastcmid = :newlastcmid,
                    timemodified = :newtimemodified
              WHERE id = :id
                AND ready = :expectedready
                AND lastcmid = :expectedlastcmid
                AND timemodified = :expectedtimemodified
                AND coursevisible = :expectedcoursevisible',
            [
                'newready' => $ready,
                'newlastcmid' => $lastcmid,
                'newtimemodified' => $timemodified,
                'id' => (int) $expected->id,
                'expectedready' => (int) $expected->ready,
                'expectedlastcmid' => (int) $expected->lastcmid,
                'expectedtimemodified' => (int) $expected->timemodified,
                'expectedcoursevisible' => (int) $expected->coursevisible,
            ]
        );
        $current = $DB->get_record('local_rtcsync_deliverycourse', ['id' => (int) $expected->id], '*', MUST_EXIST);
        return (int) $current->ready === $ready
            && (int) $current->lastcmid === $lastcmid
            && (int) $current->timemodified === $timemodified;
    }
}
