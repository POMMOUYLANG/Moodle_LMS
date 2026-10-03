<?php

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/group/lib.php');

trait local_rtcsync_credit_class_external
{
    private const MOODLE_IDNUMBER_MAX_LENGTH = 100;
    private const MOODLE_NAME_MAX_LENGTH = 254;

    public static function upsert_credit_parameters(): external_function_parameters
    {
        return new external_function_parameters([
            'credit' => new external_single_structure([
                'courseid' => new external_value(PARAM_INT, 'Parent SMS subject Moodle course id.'),
                'subject_id' => new external_value(PARAM_INT, 'SMS subject id for reconciliation.', VALUE_DEFAULT, 0),
                'idnumber' => new external_value(PARAM_RAW, 'Stable isolated credit-course idnumber.'),
                'shortname' => new external_value(PARAM_TEXT, 'Unique isolated credit-course shortname.'),
                'name' => new external_value(PARAM_TEXT, 'Isolated credit-course display name.'),
                'description' => new external_value(PARAM_RAW, 'Credit-course summary.', VALUE_DEFAULT, ''),
                'category_path' => new external_multiple_structure(
                    new external_single_structure([
                        'idnumber' => new external_value(PARAM_RAW, 'Expected category idnumber.'),
                        'name' => new external_value(PARAM_RAW, 'Expected multilingual category name.'),
                    ]),
                    'Expected inherited Program, study-year, and semester path.',
                    VALUE_DEFAULT,
                    []
                ),
                'teacher_role_shortname' => new external_value(PARAM_ALPHANUMEXT, 'Teacher role.', VALUE_DEFAULT, 'editingteacher'),
                'student_role_shortname' => new external_value(PARAM_ALPHANUMEXT, 'Student role.', VALUE_DEFAULT, 'student'),
                'teacher_userids' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'Assigned Moodle teacher id.'),
                    'Teachers assigned to this isolated credit course.', VALUE_DEFAULT, []
                ),
                'student_userids' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'Assigned Moodle student id.'),
                    'Students assigned to this isolated credit course.', VALUE_DEFAULT, []
                ),
            ]),
        ]);
    }

    public static function upsert_credit(array $credit): array
    {
        global $DB;

        $params = self::validate_parameters(self::upsert_credit_parameters(), ['credit' => $credit]);
        $credit = $params['credit'];
        $parentcourse = local_rtcsync_require_managed_course(
            (int) $credit['courseid'],
            ['rtc-subject:']
        );
        $parentcontext = context_course::instance($parentcourse->id);
        self::validate_context($parentcontext);

        $idnumber = trim((string) $credit['idnumber']);
        if ($idnumber === '' || !str_starts_with($idnumber, 'rtc-credit-course:')) {
            throw new invalid_parameter_exception('Credit idnumber must use the rtc-credit-course: prefix.');
        }

        $categorycontext = context_coursecat::instance((int) $parentcourse->category);
        require_capability('moodle/course:create', $categorycontext);
        $existing = $DB->get_record('course', ['idnumber' => $idnumber], '*', IGNORE_MISSING);
        $record = (object) [
            'fullname' => trim((string) $credit['name']),
            'shortname' => trim((string) $credit['shortname']),
            'idnumber' => $idnumber,
            'category' => (int) $parentcourse->category,
            'summary' => (string) $credit['description'],
            'summaryformat' => FORMAT_HTML,
            'visible' => 1,
            'format' => 'topics',
            'numsections' => 1,
        ];

        if ($existing) {
            $context = context_course::instance((int) $existing->id);
            self::validate_context($context);
            require_capability('moodle/course:update', $context);
            $record->id = (int) $existing->id;
            update_course($record);
            $courseid = (int) $existing->id;
        } else {
            $saved = create_course($record);
            $courseid = (int) $saved->id;
        }

        $teachers = self::valid_userids($credit['teacher_userids'] ?? [], 'creditteacher');
        $students = self::valid_userids($credit['student_userids'] ?? [], 'creditstudent');
        self::reconcile_credit_role(
            $courseid,
            (string) $credit['teacher_role_shortname'],
            $teachers
        );
        self::reconcile_credit_role(
            $courseid,
            (string) $credit['student_role_shortname'],
            $students
        );

        return [
            'id' => $courseid,
            'courseid' => $courseid,
            'parent_courseid' => (int) $parentcourse->id,
            'idnumber' => $idnumber,
            'teacher_count' => count($teachers),
            'student_count' => count($students),
        ];
    }

    public static function upsert_credit_returns(): external_single_structure
    {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Isolated Moodle credit-course id.'),
            'courseid' => new external_value(PARAM_INT, 'Isolated Moodle credit-course id.'),
            'parent_courseid' => new external_value(PARAM_INT, 'Parent SMS subject Moodle course id.'),
            'idnumber' => new external_value(PARAM_RAW, 'Credit-course idnumber.'),
            'teacher_count' => new external_value(PARAM_INT, 'Managed teacher count.'),
            'student_count' => new external_value(PARAM_INT, 'Managed student count.'),
        ]);
    }

    public static function delete_credit_parameters(): external_function_parameters
    {
        return new external_function_parameters([
            'credit' => new external_single_structure([
                'courseid' => new external_value(PARAM_INT, 'Parent SMS subject Moodle course id.'),
                'idnumber' => new external_value(PARAM_RAW, 'Stable isolated credit-course idnumber.'),
                'teacher_role_shortname' => new external_value(PARAM_ALPHANUMEXT, 'Managed teacher role to revoke.', VALUE_DEFAULT, 'editingteacher'),
                'student_role_shortname' => new external_value(PARAM_ALPHANUMEXT, 'Managed student role to revoke.', VALUE_DEFAULT, 'student'),
            ]),
        ]);
    }

    public static function delete_credit(array $credit): array
    {
        global $DB;

        $params = self::validate_parameters(self::delete_credit_parameters(), ['credit' => $credit]);
        $credit = $params['credit'];
        local_rtcsync_require_managed_course(
            (int) $credit['courseid'],
            ['rtc-subject:']
        );
        $idnumber = trim((string) $credit['idnumber']);
        if (str_starts_with($idnumber, 'rtc-credit:')) {
            $legacygroup = $DB->get_record('groups', [
                'courseid' => (int) $credit['courseid'],
                'idnumber' => $idnumber,
            ], '*', IGNORE_MISSING);
            if ($legacygroup) {
                $context = context_course::instance((int) $credit['courseid']);
                self::validate_context($context);
                require_capability('moodle/course:managegroups', $context);
                groups_delete_group($legacygroup);
            }

            return [
                'deleted' => $legacygroup ? 1 : 0,
                'courseid' => (int) $credit['courseid'],
                'idnumber' => $idnumber,
            ];
        }
        if ($idnumber === '' || !str_starts_with($idnumber, 'rtc-credit-course:')) {
            throw new invalid_parameter_exception('Credit idnumber must use the rtc-credit-course: prefix.');
        }

        $course = $DB->get_record('course', ['idnumber' => $idnumber], '*', IGNORE_MISSING);
        if (!$course) {
            return ['deleted' => 0, 'courseid' => 0, 'idnumber' => $idnumber];
        }

        $context = context_course::instance((int) $course->id);
        self::validate_context($context);
        require_capability('moodle/course:update', $context);
        self::reconcile_credit_role((int) $course->id, (string) $credit['teacher_role_shortname'], []);
        self::reconcile_credit_role((int) $course->id, (string) $credit['student_role_shortname'], []);
        update_course((object) ['id' => (int) $course->id, 'visible' => 0]);

        return ['deleted' => 1, 'courseid' => (int) $course->id, 'idnumber' => $idnumber];
    }

    public static function delete_credit_returns(): external_single_structure
    {
        return new external_single_structure([
            'deleted' => new external_value(PARAM_INT, 'Whether a managed credit course was archived.'),
            'courseid' => new external_value(PARAM_INT, 'Archived Moodle credit-course id.'),
            'idnumber' => new external_value(PARAM_RAW, 'Credit-course idnumber.'),
        ]);
    }

    public static function upsert_class_parameters(): external_function_parameters
    {
        return new external_function_parameters([
            'class' => new external_single_structure([
                'idnumber' => new external_value(PARAM_RAW, 'Stable SMS class idnumber.'),
                'name' => new external_value(PARAM_TEXT, 'Class/cohort name.'),
                'description' => new external_value(PARAM_RAW, 'Class description.', VALUE_DEFAULT, ''),
                'visible' => new external_value(PARAM_INT, 'Cohort visibility.', VALUE_DEFAULT, 1),
                'delivery_id' => new external_value(
                    PARAM_RAW,
                    'Optional SMS delivery id carried by class synchronization.',
                    VALUE_DEFAULT,
                    ''
                ),
                'userids' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'Moodle user id.'),
                    'Desired cohort members.', VALUE_DEFAULT, []
                ),
                'courseids' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'Applicable Moodle course id.'),
                    'Courses that receive the class grouping.', VALUE_DEFAULT, []
                ),
                'teacher_role_shortname' => new external_value(
                    PARAM_ALPHANUMEXT,
                    'Moodle role for class teachers.',
                    VALUE_DEFAULT,
                    'editingteacher'
                ),
                'student_role_shortname' => new external_value(
                    PARAM_ALPHANUMEXT,
                    'Moodle role for class students.',
                    VALUE_DEFAULT,
                    'student'
                ),
                'teacher_userids' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'Assigned Moodle class-teacher id.'),
                    'Desired class-teacher users.', VALUE_DEFAULT, []
                ),
                'grouping_idnumber' => new external_value(
                    PARAM_RAW, 'Stable class grouping idnumber.', VALUE_DEFAULT, ''
                ),
                'grouping_name' => new external_value(
                    PARAM_TEXT, 'Class grouping display name.', VALUE_DEFAULT, ''
                ),
                'groups' => new external_multiple_structure(
                    new external_single_structure([
                        'idnumber' => new external_value(PARAM_RAW, 'Stable SMS child-group idnumber.'),
                        'name' => new external_value(PARAM_TEXT, 'Child-group display name.'),
                        'description' => new external_value(PARAM_RAW, 'Child-group description.', VALUE_DEFAULT, ''),
                        'userids' => new external_multiple_structure(
                            new external_value(PARAM_INT, 'Moodle group member id.'),
                            'Exact child-group members.', VALUE_DEFAULT, []
                        ),
                    ]),
                    'Child groups placed inside the class grouping.', VALUE_DEFAULT, []
                ),
            ]),
        ]);
    }

    public static function upsert_class(array $class): array
    {
        $idnumber = isset($class['idnumber']) && is_scalar($class['idnumber'])
            ? trim((string) $class['idnumber'])
            : 'missing';

        try {
            return self::upsert_class_impl($class);
        } catch (dml_exception $exception) {
            $groupcount = isset($class['groups']) && is_array($class['groups'])
                ? count($class['groups'])
                : 0;
            $coursecount = isset($class['courseids']) && is_array($class['courseids'])
                ? count($class['courseids'])
                : 0;
            $membercount = isset($class['userids']) && is_array($class['userids'])
                ? count($class['userids'])
                : 0;

            debugging(
                sprintf(
                    'local_rtcsync_upsert_class failed for idnumber=%s '
                        .'(courses=%d, groups=%d, members=%d): %s',
                    $idnumber,
                    $coursecount,
                    $groupcount,
                    $membercount,
                    $exception->getMessage(),
                ),
                DEBUG_DEVELOPER,
            );

            throw $exception;
        }
    }

    private static function upsert_class_impl(array $class): array
    {
        global $DB;

        $params = self::validate_parameters(self::upsert_class_parameters(), ['class' => $class]);
        $class = $params['class'];
        $class['idnumber'] = self::validated_class_identifier($class['idnumber'], 'Class');
        $class['name'] = self::validated_class_name($class['name'], 'Class');
        $class['grouping_idnumber'] = self::validated_class_identifier(
            $class['grouping_idnumber'],
            'Class grouping',
        );
        $class['grouping_name'] = self::validated_class_name(
            $class['grouping_name'],
            'Class grouping',
        );
        foreach ($class['groups'] as $index => &$group) {
            $group['idnumber'] = self::validated_class_identifier(
                $group['idnumber'],
                'Class group '.((int) $index + 1),
            );
            $group['name'] = self::validated_class_name(
                $group['name'],
                'Class group '.((int) $index + 1),
            );
        }
        unset($group);

        $systemcontext = context_system::instance();
        self::validate_context($systemcontext);
        require_capability('moodle/cohort:manage', $systemcontext);

        $idnumber = trim((string) $class['idnumber']);
        if ($idnumber === ''
                || (!str_starts_with($idnumber, 'rtc-class:')
                    && !str_starts_with($idnumber, 'rtc-delivery:'))) {
            throw new invalid_parameter_exception(
                'Class idnumber must use the rtc-class: or rtc-delivery: prefix.'
            );
        }

        $cohort = $DB->get_record('cohort', [
            'contextid' => $systemcontext->id,
            'idnumber' => $idnumber,
        ], '*', IGNORE_MISSING);
        $record = (object) [
            'contextid' => $systemcontext->id,
            'name' => trim((string) $class['name']),
            'idnumber' => $idnumber,
            'description' => (string) $class['description'],
            'descriptionformat' => FORMAT_HTML,
            'visible' => (int) $class['visible'],
        ];

        if ($cohort) {
            $record->id = $cohort->id;
            cohort_update_cohort($record);
            $cohortid = (int) $cohort->id;
        } else {
            try {
                $cohortid = (int) cohort_add_cohort($record);
            } catch (dml_exception $exception) {
                // Another sync worker may have created this idnumber between
                // the lookup and insert. Re-read it and finish as an update;
                // unrelated DML errors still reach the diagnostic wrapper.
                $cohort = $DB->get_record('cohort', [
                    'contextid' => $systemcontext->id,
                    'idnumber' => $idnumber,
                ], '*', IGNORE_MISSING);
                if (!$cohort) {
                    throw $exception;
                }

                $record->id = $cohort->id;
                cohort_update_cohort($record);
                $cohortid = (int) $cohort->id;
            }
        }

        $desired = self::valid_userids($class['userids'] ?? [], 'classuser');
        $members = $DB->get_records(
            'cohort_members',
            ['cohortid' => $cohortid],
            'id',
            'id,userid'
        );
        $existing = array_map(
            static fn(object $member): int => (int) $member->userid,
            array_values($members)
        );
        foreach (array_diff($desired, $existing) as $userid) {
            cohort_add_member($cohortid, $userid);
        }
        foreach (array_diff($existing, $desired) as $userid) {
            cohort_remove_member($cohortid, $userid);
        }

        $courseids = self::valid_courseids($class['courseids'] ?? []);
        // Moodle only permits a user who is enrolled in the course to join a
        // course group. Reconcile class students before creating memberships
        // so the child-group assignment is effective on the same sync pass.
        self::reconcile_class_course_roles(
            $courseids,
            $cohortid,
            trim((string) ($class['student_role_shortname'] ?? 'student')),
            $desired,
            (int) $class['visible'] === 1
        );
        $structure = self::reconcile_class_course_groups(
            $courseids,
            trim((string) ($class['grouping_idnumber'] ?? '')),
            trim((string) ($class['grouping_name'] ?? '')),
            $class['groups'] ?? [],
            (int) $class['visible'] === 1
        );
        $teacherids = self::valid_userids($class['teacher_userids'] ?? [], 'classteacher');
        $teacherCount = self::reconcile_class_course_roles(
            $courseids,
            $cohortid,
            trim((string) ($class['teacher_role_shortname'] ?? 'editingteacher')),
            $teacherids,
            (int) $class['visible'] === 1
        );
        self::cleanup_stale_class_role_assignments(
            $cohortid,
            [
                trim((string) ($class['student_role_shortname'] ?? 'student')),
                trim((string) ($class['teacher_role_shortname'] ?? 'editingteacher')),
            ],
        );

        return [
            'id' => $cohortid,
            'idnumber' => $idnumber,
            'member_count' => count($desired),
            'course_count' => $structure['course_count'],
            'group_count' => $structure['group_count'],
            'teacher_count' => $teacherCount,
        ];
    }

    private static function validated_class_identifier(mixed $value, string $label): string
    {
        $identifier = trim((string) $value);
        if ($identifier === '') {
            throw new invalid_parameter_exception($label.' idnumber must not be empty.');
        }
        if (core_text::strlen($identifier) > self::MOODLE_IDNUMBER_MAX_LENGTH) {
            throw new invalid_parameter_exception(
                $label.' idnumber must be at most '
                    .self::MOODLE_IDNUMBER_MAX_LENGTH.' characters.'
            );
        }

        return $identifier;
    }

    private static function validated_class_name(mixed $value, string $label): string
    {
        $name = trim((string) $value);
        if ($name === '') {
            throw new invalid_parameter_exception($label.' name must not be empty.');
        }
        if (core_text::strlen($name) > self::MOODLE_NAME_MAX_LENGTH) {
            throw new invalid_parameter_exception(
                $label.' name must be at most '.self::MOODLE_NAME_MAX_LENGTH.' characters.'
            );
        }

        return $name;
    }

    public static function upsert_class_returns(): external_single_structure
    {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Moodle cohort id.'),
            'idnumber' => new external_value(PARAM_RAW, 'Class idnumber.'),
            'member_count' => new external_value(PARAM_INT, 'Managed cohort member count.'),
            'course_count' => new external_value(PARAM_INT, 'Courses receiving the class grouping.'),
            'group_count' => new external_value(PARAM_INT, 'Managed child groups per course in total.'),
            'teacher_count' => new external_value(PARAM_INT, 'Managed class-teacher users.'),
        ]);
    }

    private static function valid_courseids(array $courseids): array
    {
        global $DB;

        $desired = array_values(array_unique(array_filter(
            array_map('intval', $courseids),
            static fn(int $courseid): bool => $courseid > 1
        )));
        if (!$desired) {
            return [];
        }

        [$sql, $params] = $DB->get_in_or_equal($desired, SQL_PARAMS_NAMED, 'classcourse');
        $existing = $DB->get_records_select('course', "id {$sql}", $params, '', 'id,idnumber');
        $valid = [];
        foreach ($existing as $course) {
            $idnumber = (string) ($course->idnumber ?? '');
            if (str_starts_with($idnumber, 'rtc-subject:')
                    || str_starts_with($idnumber, 'rtc-credit-course:')) {
                $valid[] = (int) $course->id;
            }
        }

        if (count($valid) !== count($desired)) {
            $missing = array_values(array_diff($desired, $valid));
            throw new invalid_parameter_exception(
                'RTC-Sync class courses contain unmanaged or missing Moodle course IDs: '
                .implode(', ', $missing)
            );
        }

        return $desired;
    }

    private static function reconcile_class_course_groups(
        array $courseids,
        string $groupingidnumber,
        string $groupingname,
        array $groups,
        bool $visible
    ): array {
        global $DB;

        if ($groupingidnumber === ''
                || (!str_starts_with($groupingidnumber, 'rtc-class-grouping:')
                    && !str_starts_with($groupingidnumber, 'rtc-delivery-grouping:'))) {
            throw new invalid_parameter_exception(
                'Class grouping idnumber must use the rtc-class-grouping: or rtc-delivery-grouping: prefix.'
            );
        }

        $desiredcourseids = $visible && $groups ? $courseids : [];
        $existinggroupings = $DB->get_records('groupings', ['idnumber' => $groupingidnumber]);
        foreach ($existinggroupings as $existinggrouping) {
            if (!in_array((int) $existinggrouping->courseid, $desiredcourseids, true)) {
                self::delete_class_grouping((int) $existinggrouping->id);
            }
        }

        $groupcount = 0;
        foreach ($desiredcourseids as $courseid) {
            $groupcount += self::reconcile_class_grouping_course(
                $courseid,
                $groupingidnumber,
                $groupingname,
                $groups
            );
        }

        return ['course_count' => count($desiredcourseids), 'group_count' => $groupcount];
    }

    private static function reconcile_class_grouping_course(
        int $courseid,
        string $groupingidnumber,
        string $groupingname,
        array $groups
    ): int {
        global $DB;

        $context = context_course::instance($courseid);
        self::validate_context($context);
        require_capability('moodle/course:managegroups', $context);

        $grouping = $DB->get_record('groupings', [
            'courseid' => $courseid,
            'idnumber' => $groupingidnumber,
        ], '*', IGNORE_MISSING);
        $record = (object) [
            'courseid' => $courseid,
            'name' => $groupingname,
            'idnumber' => $groupingidnumber,
            'description' => '',
            'descriptionformat' => FORMAT_HTML,
        ];
        if ($grouping) {
            $record->id = (int) $grouping->id;
            groups_update_grouping($record);
            $groupingid = (int) $grouping->id;
        } else {
            $groupingid = (int) groups_create_grouping($record);
        }

        $desiredids = [];
        foreach ($groups as $group) {
            $desiredids[] = self::upsert_class_group($courseid, $groupingid, $group);
        }

        $assigned = $DB->get_records_sql(
            'SELECT g.*
               FROM {groupings_groups} gg
               JOIN {groups} g ON g.id = gg.groupid
              WHERE gg.groupingid = :groupingid',
            ['groupingid' => $groupingid]
        );
        foreach ($assigned as $existing) {
            if (str_starts_with((string) $existing->idnumber, 'rtc-class-group:')
                    && !in_array((int) $existing->id, $desiredids, true)) {
                groups_delete_group($existing);
            }
        }

        return count($desiredids);
    }

    /**
     * Reconcile class-scoped teacher role assignments without touching
     * subject-teacher or other unrelated course roles.
     *
     * The managed assignment uses the class cohort id as itemid. This leaves
     * normal manual/subject assignments intact when a class teacher changes.
     *
     * @param array<int, int> $courseids
     * @param array<int, int> $teacherids
     */
    private static function reconcile_class_course_roles(
        array $courseids,
        int $cohortid,
        string $roleshortname,
        array $teacherids,
        bool $visible
    ): int {
        global $DB;

        $role = self::role_by_shortname($roleshortname);
        $desiredcourseids = $visible ? $courseids : [];
        $desiredteacherids = self::valid_userids($teacherids, 'classteacher');
        $desired = [];
        foreach ($desiredcourseids as $courseid) {
            foreach ($desiredteacherids as $userid) {
                $desired[$courseid.':'.$userid] = [$courseid, $userid];
            }
        }

        $existing = $DB->get_records_sql(
            'SELECT ra.id, ra.userid, ra.contextid, ctx.instanceid AS courseid
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid
              WHERE ra.component = :component
                AND ra.itemid = :itemid
                AND ra.roleid = :roleid
                AND ctx.contextlevel = :contextlevel',
            [
                'component' => 'local_rtcsync',
                'itemid' => $cohortid,
                'roleid' => (int) $role->id,
                'contextlevel' => CONTEXT_COURSE,
            ]
        );

        foreach ($existing as $assignment) {
            $key = ((int) $assignment->courseid).':'.((int) $assignment->userid);
            if (!isset($desired[$key])) {
                role_unassign(
                    (int) $role->id,
                    (int) $assignment->userid,
                    (int) $assignment->contextid,
                    'local_rtcsync',
                    $cohortid
                );
                self::unenrol_if_course_has_no_roles(
                    (int) $assignment->courseid,
                    (int) $assignment->userid
                );
            }
        }

        foreach ($desired as [$courseid, $userid]) {
            $context = context_course::instance((int) $courseid);
            self::validate_context($context);
            require_capability('moodle/role:assign', $context);

            $markerExists = $DB->record_exists('role_assignments', [
                'roleid' => (int) $role->id,
                'userid' => (int) $userid,
                'contextid' => $context->id,
                'component' => 'local_rtcsync',
                'itemid' => $cohortid,
            ]);
            if ($markerExists) {
                continue;
            }

            $hasRole = $DB->record_exists('role_assignments', [
                'roleid' => (int) $role->id,
                'userid' => (int) $userid,
                'contextid' => $context->id,
            ]);
            if (!$hasRole) {
                self::enrol_user([
                    'courseid' => (int) $courseid,
                    'userid' => (int) $userid,
                    'role_shortname' => $roleshortname,
                    'suspend' => 0,
                ]);

                // Take ownership of the role assignment created by manual
                // enrolment so it can be removed safely with this class.
                $baseassignment = $DB->get_record('role_assignments', [
                    'roleid' => (int) $role->id,
                    'userid' => (int) $userid,
                    'contextid' => $context->id,
                    'component' => '',
                    'itemid' => 0,
                ], '*', IGNORE_MISSING);
                if ($baseassignment) {
                    $baseassignment->component = 'local_rtcsync';
                    $baseassignment->itemid = $cohortid;
                    $DB->update_record('role_assignments', $baseassignment);
                    continue;
                }
            }

            role_assign(
                (int) $role->id,
                (int) $userid,
                $context->id,
                'local_rtcsync',
                $cohortid
            );
        }

        return count($desiredteacherids);
    }

    /**
     * Remove managed class-role assignments left behind when the configured
     * student or class-teacher role changes. Current-role reconciliation only
     * sees the role currently configured, so a previous role would otherwise
     * retain class-wide access indefinitely.
     *
     * @param array<int, string> $protectedroles
     */
    private static function cleanup_stale_class_role_assignments(
        int $cohortid,
        array $protectedroles
    ): void {
        global $DB;

        $protectedroleids = [];
        foreach (array_values(array_unique(array_filter($protectedroles))) as $shortname) {
            $protectedroleids[] = (int) self::role_by_shortname($shortname)->id;
        }

        $existing = $DB->get_records_sql(
            'SELECT ra.id, ra.roleid, ra.userid, ra.contextid, ctx.instanceid AS courseid
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid
              WHERE ra.component = :component
                AND ra.itemid = :itemid
                AND ctx.contextlevel = :contextlevel',
            [
                'component' => 'local_rtcsync',
                'itemid' => $cohortid,
                'contextlevel' => CONTEXT_COURSE,
            ]
        );

        foreach ($existing as $assignment) {
            if (in_array((int) $assignment->roleid, $protectedroleids, true)) {
                continue;
            }

            role_unassign(
                (int) $assignment->roleid,
                (int) $assignment->userid,
                (int) $assignment->contextid,
                'local_rtcsync',
                $cohortid
            );
            self::unenrol_if_course_has_no_roles(
                (int) $assignment->courseid,
                (int) $assignment->userid
            );
        }
    }

    private static function unenrol_if_course_has_no_roles(int $courseid, int $userid): void
    {
        global $DB;

        $context = context_course::instance($courseid);
        if ($DB->record_exists('role_assignments', [
            'contextid' => $context->id,
            'userid' => $userid,
        ])) {
            return;
        }

        $instance = $DB->get_record('enrol', [
            'courseid' => $courseid,
            'enrol' => 'manual',
        ], '*', IGNORE_MISSING);
        if ($instance) {
            enrol_get_plugin('manual')->unenrol_user($instance, $userid);
        }
    }

    private static function upsert_class_group(int $courseid, int $groupingid, array $group): int
    {
        global $DB;

        $idnumber = trim((string) ($group['idnumber'] ?? ''));
        if ($idnumber === '' || !str_starts_with($idnumber, 'rtc-class-group:')) {
            throw new invalid_parameter_exception(
                'Class group idnumber must use the rtc-class-group: prefix.'
            );
        }

        $existing = $DB->get_record('groups', [
            'courseid' => $courseid,
            'idnumber' => $idnumber,
        ], '*', IGNORE_MISSING);
        $record = (object) [
            'courseid' => $courseid,
            'name' => trim((string) ($group['name'] ?? '')),
            'idnumber' => $idnumber,
            'description' => (string) ($group['description'] ?? ''),
            'descriptionformat' => FORMAT_HTML,
        ];
        if ($existing) {
            $record->id = (int) $existing->id;
            groups_update_group($record);
            $groupid = (int) $existing->id;
        } else {
            $groupid = (int) groups_create_group($record);
        }

        if (!$DB->record_exists('groupings_groups', [
            'groupingid' => $groupingid,
            'groupid' => $groupid,
        ])) {
            groups_assign_grouping($groupingid, $groupid);
        }

        $desired = self::valid_userids($group['userids'] ?? [], 'classgroupuser');
        $existingmembers = array_map('intval', array_keys($DB->get_records(
            'groups_members', ['groupid' => $groupid], '', 'userid'
        )));
        foreach (array_diff($desired, $existingmembers) as $userid) {
            groups_add_member($groupid, $userid);
        }
        foreach (array_diff($existingmembers, $desired) as $userid) {
            groups_remove_member($groupid, $userid);
        }

        return $groupid;
    }

    private static function delete_class_grouping(int $groupingid): void
    {
        global $DB;

        $groups = $DB->get_records_sql(
            'SELECT g.*
               FROM {groupings_groups} gg
               JOIN {groups} g ON g.id = gg.groupid
              WHERE gg.groupingid = :groupingid',
            ['groupingid' => $groupingid]
        );
        foreach ($groups as $group) {
            if (str_starts_with((string) $group->idnumber, 'rtc-class-group:')) {
                groups_delete_group($group);
            }
        }
        groups_delete_grouping($groupingid);
    }

    private static function reconcile_credit_role(int $courseid, string $roleshortname, array $desired): void
    {
        global $DB;

        $role = self::role_by_shortname($roleshortname);
        $context = context_course::instance($courseid);
        $managedassignments = $DB->get_records('role_assignments', [
            'contextid' => $context->id,
            'roleid' => (int) $role->id,
            'component' => 'local_rtcsync',
            'itemid' => $courseid,
        ], '', 'userid');
        $existing = array_map('intval', array_keys($managedassignments));

        foreach (array_diff($desired, $existing) as $userid) {
            $hasRole = $DB->record_exists('role_assignments', [
                'contextid' => $context->id,
                'roleid' => (int) $role->id,
                'userid' => $userid,
            ]);
            if ($hasRole) {
                // Preserve an unmanaged Moodle assignment. It may be a
                // deliberate operator permission rather than SMS-owned access.
                continue;
            }

            self::enrol_user([
                'courseid' => $courseid,
                'userid' => $userid,
                'role_shortname' => $roleshortname,
                'suspend' => 0,
            ]);

            // Manual enrolment creates an unowned base role assignment. Take
            // ownership of the new assignment so later SMS removal cannot
            // revoke an unrelated Moodle role assignment.
            $baseassignment = $DB->get_record('role_assignments', [
                'contextid' => $context->id,
                'roleid' => (int) $role->id,
                'userid' => $userid,
                'component' => '',
                'itemid' => 0,
            ], '*', IGNORE_MISSING);
            if ($baseassignment) {
                $baseassignment->component = 'local_rtcsync';
                $baseassignment->itemid = $courseid;
                $DB->update_record('role_assignments', $baseassignment);
            } else {
                role_assign(
                    (int) $role->id,
                    $userid,
                    $context->id,
                    'local_rtcsync',
                    $courseid
                );
            }
        }
        foreach (array_diff($existing, $desired) as $userid) {
            role_unassign(
                (int) $role->id,
                $userid,
                $context->id,
                'local_rtcsync',
                $courseid
            );
            self::unenrol_if_course_has_no_roles($courseid, $userid);
        }
    }

    private static function valid_userids(array $userids, string $prefix): array
    {
        return local_rtcsync_validate_managed_userids($userids, $prefix);
    }
}
