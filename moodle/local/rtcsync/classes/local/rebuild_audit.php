<?php

namespace local_rtcsync\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only evidence collection for a guarded Moodle rebuild.
 */
final class rebuild_audit
{
    /** @return array<string, mixed> */
    public static function inventory(): array
    {
        global $CFG, $DB;

        $managedcondition = self::managed_course_condition('c');
        $managedparams = self::managed_course_params();
        $baseparams = ['siteid' => (int) $CFG->siteid];
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
                'shortname' => (string) $DB->get_field('course', 'shortname', ['id' => $CFG->siteid]),
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
        $missingfunctions = array_values(array_diff($requiredfunctions, $installedfunctions));
        $duplicatecourses = self::duplicate_idnumbers('course');
        $duplicatecategories = self::duplicate_idnumbers('course_categories');
        $inventory = self::inventory();

        $checks = [
            'plugin_installed' => get_config('local_rtcsync', 'version') !== false,
            'web_services_enabled' => (bool) get_config('core', 'enablewebservices'),
            'rest_protocol_enabled' => $DB->record_exists('external_protocol', [
                'name' => 'rest',
                'enabled' => 1,
            ]),
            'rtc_service_enabled' => $service !== false && (bool) $service->enabled,
            'rtc_service_restricted' => $service !== false && (bool) $service->restrictedusers,
            'required_functions_present' => $missingfunctions === [],
            'duplicate_managed_course_idnumbers_absent' => $duplicatecourses === [],
            'duplicate_managed_category_idnumbers_absent' => $duplicatecategories === [],
        ];

        return [
            'generated_at' => gmdate('c'),
            'wwwroot' => (string) $CFG->wwwroot,
            'checks' => $checks,
            'missing_functions' => $missingfunctions,
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
     * @param array<string, mixed> $params
     * @return array<string, int>
     */
    private static function content_counts(string $coursecondition, array $params): array
    {
        return [
            'courses' => self::count_sql(
                "SELECT COUNT(1) FROM {course} c WHERE $coursecondition",
                $params
            ),
            'activities' => self::count_sql(
                "SELECT COUNT(1)
                   FROM {course_modules} cm
                   JOIN {course} c ON c.id = cm.course
                  WHERE cm.deletioninprogress = 0 AND $coursecondition",
                $params
            ),
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
            'activities',
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
        $params = self::managed_course_params() + ['siteid' => (int) $CFG->siteid];
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

    /** @param array<string, mixed> $params */
    private static function count_sql(string $sql, array $params): int
    {
        global $DB;

        return (int) $DB->get_field_sql($sql, $params);
    }
}