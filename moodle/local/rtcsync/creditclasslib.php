<?php

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/group/lib.php');

trait local_rtcsync_credit_class_external
{
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
        $parentcourse = get_course((int) $credit['courseid']);
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
        get_course((int) $credit['courseid']);
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
                'userids' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'Moodle user id.'),
                    'Desired cohort members.', VALUE_DEFAULT, []
                ),
            ]),
        ]);
    }

    public static function upsert_class(array $class): array
    {
        global $DB;

        $params = self::validate_parameters(self::upsert_class_parameters(), ['class' => $class]);
        $class = $params['class'];
        $systemcontext = context_system::instance();
        self::validate_context($systemcontext);
        require_capability('moodle/cohort:manage', $systemcontext);

        $idnumber = trim((string) $class['idnumber']);
        if ($idnumber === '' || !str_starts_with($idnumber, 'rtc-class:')) {
            throw new invalid_parameter_exception('Class idnumber must use the rtc-class: prefix.');
        }

        $cohort = $DB->get_record('cohort', ['idnumber' => $idnumber], '*', IGNORE_MISSING);
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
            $cohortid = (int) cohort_add_cohort($record);
        }

        $desired = self::valid_userids($class['userids'] ?? [], 'classuser');
        $existing = array_map('intval', array_keys($DB->get_records('cohort_members', ['cohortid' => $cohortid])));
        foreach (array_diff($desired, $existing) as $userid) {
            cohort_add_member($cohortid, $userid);
        }
        foreach (array_diff($existing, $desired) as $userid) {
            cohort_remove_member($cohortid, $userid);
        }

        return ['id' => $cohortid, 'idnumber' => $idnumber, 'member_count' => count($desired)];
    }

    public static function upsert_class_returns(): external_single_structure
    {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Moodle cohort id.'),
            'idnumber' => new external_value(PARAM_RAW, 'Class idnumber.'),
            'member_count' => new external_value(PARAM_INT, 'Managed cohort member count.'),
        ]);
    }

    private static function reconcile_credit_role(int $courseid, string $roleshortname, array $desired): void
    {
        global $DB;

        $role = self::role_by_shortname($roleshortname);
        $context = context_course::instance($courseid);
        $existing = array_map('intval', array_keys($DB->get_records('role_assignments', [
            'contextid' => $context->id,
            'roleid' => (int) $role->id,
        ], '', 'userid')));

        foreach (array_diff($desired, $existing) as $userid) {
            self::enrol_user([
                'courseid' => $courseid,
                'userid' => $userid,
                'role_shortname' => $roleshortname,
                'suspend' => 0,
            ]);
        }
        foreach (array_diff($existing, $desired) as $userid) {
            self::unenrol_user([
                'courseid' => $courseid,
                'userid' => $userid,
                'role_shortname' => $roleshortname,
            ]);
        }
    }

    private static function valid_userids(array $userids, string $prefix): array
    {
        global $DB;

        $desired = array_values(array_unique(array_map('intval', $userids)));
        $desired = array_values(array_filter($desired, static fn(int $userid): bool => $userid > 0));
        if (!$desired) {
            return [];
        }

        [$usersql, $userparams] = $DB->get_in_or_equal($desired, SQL_PARAMS_NAMED, $prefix);
        $validusers = array_map('intval', array_keys($DB->get_records_select(
            'user', "id {$usersql} AND deleted = 0", $userparams, '', 'id'
        )));

        return array_values(array_intersect($desired, $validusers));
    }
}