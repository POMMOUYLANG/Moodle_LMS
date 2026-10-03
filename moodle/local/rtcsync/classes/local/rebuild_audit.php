<?php

namespace local_rtcsync\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only evidence collection for a guarded Moodle rebuild.
 */
final class rebuild_audit
{
    /** Minimum plugin DB version required by the current backend contract. */
    private const MINIMUM_SUPPORTED_PLUGIN_VERSION = 2026100302;

    /**
     * Capabilities that must remain attached to each RTC external function.
     *
     * The service restriction alone is not enough: a stale or broadened
     * capability declaration can turn a deployment account into an
     * unintended privilege-escalation path.
     *
     * @var array<string, string>
     */
    private const REQUIRED_FUNCTION_CAPABILITIES = [
        'local_rtcsync_upsert_course' => 'moodle/course:create,moodle/course:update,moodle/category:manage',
        'local_rtcsync_upsert_user' => 'moodle/user:create,moodle/user:update',
        'local_rtcsync_sync_system_roles' => 'moodle/role:assign',
        'local_rtcsync_sync_category_roles' => 'moodle/role:assign,moodle/category:manage',
        'local_rtcsync_enrol_user' => 'enrol/manual:enrol',
        'local_rtcsync_unenrol_user' => 'enrol/manual:unenrol',
        'local_rtcsync_upsert_credit' =>
            'moodle/course:create,moodle/course:update,moodle/course:managegroups,enrol/manual:enrol,enrol/manual:unenrol',
        'local_rtcsync_delete_credit' =>
            'moodle/course:create,moodle/course:update,moodle/course:managegroups,enrol/manual:enrol,enrol/manual:unenrol',
        'local_rtcsync_upsert_class' => 'moodle/cohort:manage',
        'local_rtcsync_upsert_grade' => 'moodle/grade:manage,moodle/grade:edit',
        'local_rtcsync_get_managed_state' => 'local/rtcsync:readmanagedstate',
    ];

    /** @return array<string, mixed> */
    public static function inventory(): array
    {
        global $CFG, $DB;

        $managedcondition = self::managed_course_condition('c');
        $managedparams = self::managed_course_params();
        $baseparams = ['siteid' => SITEID];
        $basecondition = 'c.id <> :siteid';

        $managed = self::content_counts(
            "$basecondition AND $managedcondition",
            $baseparams + $managedparams
        );
        $unmanaged = self::content_counts(
            "$basecondition AND NOT ($managedcondition)",
            $baseparams + $managedparams
        );

        $blockers = [];
        self::append_content_blockers($blockers, 'managed', $managed);
        self::append_content_blockers($blockers, 'unmanaged', $unmanaged);

        return [
            'generated_at' => gmdate('c'),
            'site' => [
                'shortname' => (string) $DB->get_field('course', 'shortname', ['id' => SITEID]),
                'wwwroot' => (string) $CFG->wwwroot,
                'plugin_version' => (string) get_config('local_rtcsync', 'version'),
            ],
            'users' => [
                'active' => $DB->count_records('user', ['deleted' => 0, 'suspended' => 0]),
                'suspended' => $DB->count_records('user', ['deleted' => 0, 'suspended' => 1]),
                'deleted' => $DB->count_records('user', ['deleted' => 1]),
            ],
            'managed' => $managed,
            'unmanaged' => $unmanaged,
            'unmanaged_course_samples' => self::unmanaged_course_samples(),
            'blockers' => $blockers,
            'safe_to_discard_without_content_migration' => $blockers === [],
        ];
    }

    /** @return array<string, mixed> */
    public static function acceptance(): array
    {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/webservice/lib.php');
        require_once($CFG->dirroot . '/local/rtcsync/locallib.php');

        $service = $DB->get_record(
            'external_services',
            ['name' => 'RTC Sync Service'],
            'id,name,enabled,restrictedusers',
            IGNORE_MISSING
        );
        $requiredfunctions = [
            'local_rtcsync_upsert_course',
            'local_rtcsync_upsert_user',
            'local_rtcsync_sync_system_roles',
            'local_rtcsync_sync_category_roles',
            'local_rtcsync_enrol_user',
            'local_rtcsync_unenrol_user',
            'local_rtcsync_upsert_credit',
            'local_rtcsync_delete_credit',
            'local_rtcsync_upsert_class',
            'local_rtcsync_upsert_grade',
            'local_rtcsync_get_managed_state',
        ];
        $installedfunctions = $DB->get_fieldset_select(
            'external_functions',
            'name',
            'component = :component',
            ['component' => 'local_rtcsync']
        );
        $installedfunctionrecords = $DB->get_records_select(
            'external_functions',
            'component = :component',
            ['component' => 'local_rtcsync'],
            'name ASC',
            'id,name,capabilities'
        );
        $missingfunctions = array_values(array_diff($requiredfunctions, $installedfunctions));
        $functioncapabilitymismatches = [];
        foreach ($installedfunctionrecords as $functionrecord) {
            $name = (string) $functionrecord->name;
            if (!array_key_exists($name, self::REQUIRED_FUNCTION_CAPABILITIES)) {
                continue;
            }

            $expected = self::normalize_capabilities(self::REQUIRED_FUNCTION_CAPABILITIES[$name]);
            $actual = self::normalize_capabilities((string) $functionrecord->capabilities);
            if ($actual !== $expected) {
                $functioncapabilitymismatches[$name] = [
                    'expected' => $expected,
                    'actual' => $actual,
                ];
            }
        }
        $duplicatecourses = self::duplicate_idnumbers('course');
        $duplicatecategories = self::duplicate_idnumbers('course_categories');
        $inventory = self::inventory();
        $unmanagedcreditroles = self::unmanaged_credit_role_assignments();
        $unmanagedcreditrolesfingerprint = self::unmanaged_credit_role_fingerprint($unmanagedcreditroles);
        $acknowledgedfingerprint = (string) get_config(
            'local_rtcsync',
            'unmanaged_credit_role_assignments_acknowledged'
        );
        $creditrolesreviewed = $unmanagedcreditroles === []
            || ($acknowledgedfingerprint !== ''
                && hash_equals($unmanagedcreditrolesfingerprint, $acknowledgedfingerprint));
        $pluginversion = get_config('local_rtcsync', 'version');
        $plugininstalled = $pluginversion !== false;
        $pluginsupported = $plugininstalled
            && (int) $pluginversion >= self::MINIMUM_SUPPORTED_PLUGIN_VERSION;
        $requiredprofilefields = array_keys(local_rtcsync_profile_field_definitions());
        $installedprofilefields = $DB->get_fieldset_select(
            'user_info_field',
            'shortname',
            'shortname IN (' . implode(',', array_fill(0, count($requiredprofilefields), '?')) . ')',
            $requiredprofilefields
        );
        $missingprofilefields = array_values(array_diff($requiredprofilefields, $installedprofilefields));
        $profilefieldrecords = $DB->get_records_select(
            'user_info_field',
            'shortname IN (' . implode(',', array_fill(0, count($requiredprofilefields), '?')) . ')',
            $requiredprofilefields,
            'shortname ASC',
            'id,shortname,name,datatype,categoryid'
        );
        $profilefieldrecordsbyshortname = [];
        foreach ($profilefieldrecords as $field) {
            $profilefieldrecordsbyshortname[(string) $field->shortname] = $field;
        }
        $profilecategories = $DB->get_records('user_info_category', [], '', 'id,name');
        $profilefieldmismatches = [];
        foreach (local_rtcsync_profile_field_definitions() as $shortname => $expectedname) {
            if (!isset($profilefieldrecordsbyshortname[$shortname])) {
                continue;
            }

            $field = $profilefieldrecordsbyshortname[$shortname];
            $issues = [];
            if ((string) $field->name !== $expectedname) {
                $issues[] = 'name';
            }
            if ((string) $field->datatype !== 'text') {
                $issues[] = 'datatype';
            }
            $categoryname = $profilecategories[(int) $field->categoryid]->name ?? null;
            if ((string) $categoryname !== 'RTC Academic Data') {
                $issues[] = 'category';
            }
            if ($issues !== []) {
                $profilefieldmismatches[$shortname] = $issues;
            }
        }

        $checks = [
            'plugin_installed' => $plugininstalled,
            'plugin_version_supported' => $pluginsupported,
            'required_profile_fields_present' => $missingprofilefields === [],
            'profile_fields_match_contract' => $profilefieldmismatches === [],
            'unmanaged_credit_role_assignments_reviewed' => $creditrolesreviewed,
            'web_services_enabled' => (bool) get_config('core', 'enablewebservices'),
            'rest_protocol_enabled' => webservice_protocol_is_enabled('rest'),
            'rtc_service_enabled' => $service !== false && (bool) $service->enabled,
            'rtc_service_restricted' => $service !== false && (bool) $service->restrictedusers,
            'required_functions_present' => $missingfunctions === [],
            'function_capabilities_match_contract' => $functioncapabilitymismatches === [],
            'duplicate_managed_course_idnumbers_absent' => $duplicatecourses === [],
            'duplicate_managed_category_idnumbers_absent' => $duplicatecategories === [],
        ];

        return [
            'generated_at' => gmdate('c'),
            'wwwroot' => (string) $CFG->wwwroot,
            'plugin_version' => $pluginversion === false ? null : (string) $pluginversion,
            'required_plugin_version' => (string) self::MINIMUM_SUPPORTED_PLUGIN_VERSION,
            'required_profile_fields' => $requiredprofilefields,
            'missing_profile_fields' => $missingprofilefields,
            'profile_field_mismatches' => $profilefieldmismatches,
            'unmanaged_credit_role_assignments' => $unmanagedcreditroles,
            'unmanaged_credit_role_assignments_fingerprint' => $unmanagedcreditroles === []
                ? null
                : $unmanagedcreditrolesfingerprint,
            'unmanaged_credit_role_assignments_acknowledged' => $acknowledgedfingerprint !== ''
                ? $acknowledgedfingerprint
                : null,
            'checks' => $checks,
            'missing_functions' => $missingfunctions,
            'function_capability_mismatches' => $functioncapabilitymismatches,
            'duplicate_course_idnumbers' => $duplicatecourses,
            'duplicate_category_idnumbers' => $duplicatecategories,
            'inventory' => [
                'managed' => $inventory['managed'],
                'unmanaged' => $inventory['unmanaged'],
            ],
            'passed' => !in_array(false, $checks, true),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function normalize_capabilities(string $capabilities): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn(string $capability): string => trim($capability),
            explode(',', $capabilities)
        ))));
        sort($normalized);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, int>
     */
    private static function content_counts(string $coursecondition, array $params): array
    {
        $activities = self::count_sql(
            "SELECT COUNT(1)
               FROM {course_modules} cm
               JOIN {course} c ON c.id = cm.course
              WHERE cm.deletioninprogress = 0 AND $coursecondition",
            $params
        );
        $emptyannouncements = self::count_sql(
            "SELECT COUNT(1)
               FROM {course_modules} cm
               JOIN {modules} module ON module.id = cm.module
               JOIN {forum} forum ON forum.id = cm.instance
               JOIN {course} c ON c.id = cm.course
              WHERE cm.deletioninprogress = 0
                AND module.name = :forummodule
                AND forum.type = :newstype
                AND NOT EXISTS (
                    SELECT 1
                      FROM {forum_discussions} discussion
                     WHERE discussion.forum = forum.id
                )
                AND $coursecondition",
            $params + [
                'forummodule' => 'forum',
                'newstype' => 'news',
            ]
        );

        return [
            'courses' => self::count_sql(
                "SELECT COUNT(1) FROM {course} c WHERE $coursecondition",
                $params
            ),
            'activities_total' => $activities,
            'empty_announcement_forums' => $emptyannouncements,
            'meaningful_activities' => $activities - $emptyannouncements,
            'assignment_submissions' => self::count_sql(
                "SELECT COUNT(1)
                   FROM {assign_submission} submission
                   JOIN {assign} assignment ON assignment.id = submission.assignment
                   JOIN {course} c ON c.id = assignment.course
                  WHERE submission.status = :submitted AND $coursecondition",
                $params + ['submitted' => 'submitted']
            ),
            'quiz_attempts' => self::count_sql(
                "SELECT COUNT(1)
                   FROM {quiz_attempts} attempt
                   JOIN {quiz} quiz ON quiz.id = attempt.quiz
                   JOIN {course} c ON c.id = quiz.course
                  WHERE $coursecondition",
                $params
            ),
            'final_grades' => self::count_sql(
                "SELECT COUNT(1)
                   FROM {grade_grades} grade
                   JOIN {grade_items} item ON item.id = grade.itemid
                   JOIN {course} c ON c.id = item.courseid
                  WHERE grade.finalgrade IS NOT NULL AND $coursecondition",
                $params
            ),
            'completed_courses' => self::count_sql(
                "SELECT COUNT(1)
                   FROM {course_completions} completion
                   JOIN {course} c ON c.id = completion.course
                  WHERE completion.timecompleted IS NOT NULL AND $coursecondition",
                $params
            ),
        ];
    }

    private static function managed_course_condition(string $alias): string
    {
        global $DB;

        return '('
            . $DB->sql_like("{$alias}.idnumber", ':subjectpattern', false)
            . ' OR '
            . $DB->sql_like("{$alias}.idnumber", ':creditpattern', false)
            . ')';
    }

    /** @return array<string, string> */
    private static function managed_course_params(): array
    {
        return [
            'subjectpattern' => 'rtc-subject:%',
            'creditpattern' => 'rtc-credit-course:%',
        ];
    }

    /**
     * @param string[] $blockers
     * @param array<string, int> $counts
     */
    private static function append_content_blockers(array &$blockers, string $scope, array $counts): void
    {
        foreach ([
            'meaningful_activities',
            'assignment_submissions',
            'quiz_attempts',
            'final_grades',
            'completed_courses',
        ] as $metric) {
            if (($counts[$metric] ?? 0) > 0) {
                $blockers[] = "{$scope}.{$metric}";
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    private static function unmanaged_course_samples(): array
    {
        global $CFG, $DB;

        $managedcondition = self::managed_course_condition('c');
        $params = self::managed_course_params() + ['siteid' => SITEID];
        $records = $DB->get_records_sql(
            "SELECT c.id, c.fullname, c.shortname, c.idnumber, COUNT(cm.id) AS activities
               FROM {course} c
          LEFT JOIN {course_modules} cm
                 ON cm.course = c.id
                AND cm.deletioninprogress = 0
              WHERE c.id <> :siteid
                AND NOT ($managedcondition)
           GROUP BY c.id, c.fullname, c.shortname, c.idnumber
           ORDER BY activities DESC, c.id ASC",
            $params,
            0,
            25
        );

        return array_values(array_map(static fn($record): array => [
            'id' => (int) $record->id,
            'fullname' => (string) $record->fullname,
            'shortname' => (string) $record->shortname,
            'idnumber' => (string) $record->idnumber,
            'activities' => (int) $record->activities,
        ], $records));
    }

    /** @return array<int, array{idnumber: string, total: int}> */
    private static function duplicate_idnumbers(string $table): array
    {
        global $DB;

        $subjectlike = $DB->sql_like('idnumber', ':subjectpattern', false);
        $creditlike = $DB->sql_like('idnumber', ':creditpattern', false);
        $classlike = $DB->sql_like('idnumber', ':classpattern', false);
        $programlike = $DB->sql_like('idnumber', ':programpattern', false);
        $records = $DB->get_records_sql(
            "SELECT MIN(id) AS id, idnumber, COUNT(1) AS total
               FROM {{$table}}
              WHERE idnumber <> ''
                AND ($subjectlike OR $creditlike OR $classlike OR $programlike)
           GROUP BY idnumber
             HAVING COUNT(1) > 1
           ORDER BY idnumber",
            [
                'subjectpattern' => 'rtc-subject:%',
                'creditpattern' => 'rtc-credit-course:%',
                'classpattern' => 'rtc-class:%',
                'programpattern' => 'rtc-program-%',
            ]
        );

        return array_values(array_map(static fn($record): array => [
            'idnumber' => (string) $record->idnumber,
            'total' => (int) $record->total,
        ], $records));
    }

    /** @return array<int, array<string, mixed>> */
    public static function unmanaged_credit_role_assignments(): array
    {
        global $DB;

        $creditpattern = $DB->sql_like('course.idnumber', ':creditpattern', false);
        $records = $DB->get_records_sql(
            "SELECT assignment.id AS assignmentid,
                    assignment.userid,
                    assignment.component,
                    assignment.itemid,
                    course.id AS courseid,
                    course.idnumber,
                    role.shortname AS roleshortname
               FROM {role_assignments} assignment
               JOIN {context} context ON context.id = assignment.contextid
               JOIN {course} course ON course.id = context.instanceid
               JOIN {role} role ON role.id = assignment.roleid
              WHERE context.contextlevel = :contextlevel
                AND $creditpattern
                AND NOT (
                    assignment.component = :managedcomponent
                    AND assignment.itemid = course.id
                )
           ORDER BY course.id, assignment.id",
            [
                'contextlevel' => CONTEXT_COURSE,
                'creditpattern' => 'rtc-credit-course:%',
                'managedcomponent' => 'local_rtcsync',
            ],
            0,
            100
        );

        return array_values(array_map(static fn($record): array => [
            'assignment_id' => (int) $record->assignmentid,
            'course_id' => (int) $record->courseid,
            'course_idnumber' => (string) $record->idnumber,
            'userid' => (int) $record->userid,
            'role_shortname' => (string) $record->roleshortname,
            'component' => (string) $record->component,
            'itemid' => (int) $record->itemid,
        ], $records));
    }

    /** @param array<int, array<string, mixed>> $assignments */
    public static function unmanaged_credit_role_fingerprint(array $assignments): string
    {
        return hash(
            'sha256',
            (string) json_encode($assignments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    /** @param array<string, mixed> $params */
    private static function count_sql(string $sql, array $params): int
    {
        global $DB;

        return (int) $DB->get_field_sql($sql, $params);
    }
}
