<?php

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/local/rtcsync/locallib.php');
require_once($CFG->dirroot . '/local/rtcsync/creditclasslib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/grade/grade_item.php');

class local_rtcsync_external extends external_api
{
    use local_rtcsync_credit_class_external;

    public static function upsert_course_parameters(): external_function_parameters
    {
        return new external_function_parameters([
            'course' => new external_single_structure([
                'fullname' => new external_value(PARAM_TEXT, 'Course full name.'),
                'shortname' => new external_value(PARAM_TEXT, 'Course short name.'),
                'idnumber' => new external_value(PARAM_RAW, 'Stable RTC course idnumber.'),
                'summary' => new external_value(PARAM_RAW, 'Course summary.', VALUE_DEFAULT, ''),
                'category_idnumber' => new external_value(PARAM_RAW, 'RTC category idnumber.', VALUE_DEFAULT, 'rtc-academic'),
                'category_name' => new external_value(PARAM_RAW, 'RTC category name.', VALUE_DEFAULT, 'RTC Academic Courses'),
                'category_path' => new external_multiple_structure(
                    new external_single_structure([
                        'idnumber' => new external_value(PARAM_RAW, 'Stable category idnumber.'),
                        'name' => new external_value(PARAM_RAW, 'Multilingual category name.'),
                    ]),
                    'Program, study-year, and semester category path.',
                    VALUE_DEFAULT,
                    []
                ),
                'visible' => new external_value(PARAM_INT, 'Course visibility.', VALUE_DEFAULT, 1),
                'startdate' => new external_value(PARAM_INT, 'Course start timestamp.', VALUE_DEFAULT, 0),
                'enddate' => new external_value(PARAM_INT, 'Course end timestamp.', VALUE_DEFAULT, 0),
            ]),
        ]);
    }

    public static function upsert_course(array $course): array
    {
        global $DB;

        $params = self::validate_parameters(self::upsert_course_parameters(), ['course' => $course]);
        $course = $params['course'];

        $systemcontext = context_system::instance();
        self::validate_context($systemcontext);
        require_capability('moodle/category:manage', $systemcontext);

        $categoryid = self::ensure_category_path(
            $course['category_path'] ?? [],
            $course['category_idnumber'],
            $course['category_name']
        );
        $categorycontext = context_coursecat::instance($categoryid);
        require_capability('moodle/course:create', $categorycontext);

        $existing = $DB->get_record('course', ['idnumber' => $course['idnumber']], '*', IGNORE_MISSING);
        if (!$existing) {
            $existing = $DB->get_record('course', ['shortname' => $course['shortname']], '*', IGNORE_MISSING);
        }

        $data = (object) [
            'fullname' => trim($course['fullname']),
            'shortname' => trim($course['shortname']),
            'idnumber' => trim($course['idnumber']),
            'category' => $categoryid,
            'summary' => $course['summary'],
            'summaryformat' => FORMAT_HTML,
            'visible' => (int) $course['visible'],
            'format' => 'topics',
            'numsections' => 1,
        ];

        if (!empty($course['startdate'])) {
            $data->startdate = (int) $course['startdate'];
        }
        if (!empty($course['enddate'])) {
            $data->enddate = (int) $course['enddate'];
        }

        if ($existing) {
            $coursecontext = context_course::instance($existing->id);
            self::validate_context($coursecontext);
            require_capability('moodle/course:update', $coursecontext);

            $data->id = $existing->id;
            update_course($data);
            $saved = $DB->get_record('course', ['id' => $existing->id], '*', MUST_EXIST);
        } else {
            $saved = create_course($data);
        }

        return [
            'id' => (int) $saved->id,
            'shortname' => $saved->shortname,
            'idnumber' => $saved->idnumber,
            'categoryid' => (int) $saved->category,
        ];
    }

    public static function upsert_course_returns(): external_single_structure
    {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Moodle course id.'),
            'shortname' => new external_value(PARAM_TEXT, 'Course shortname.'),
            'idnumber' => new external_value(PARAM_RAW, 'Course idnumber.'),
            'categoryid' => new external_value(PARAM_INT, 'Course category id.'),
        ]);
    }

    public static function upsert_user_parameters(): external_function_parameters
    {
        return new external_function_parameters([
            'user' => new external_single_structure([
                'moodleid' => new external_value(PARAM_INT, 'Existing Moodle user id.', VALUE_DEFAULT, 0),
                'username' => new external_value(PARAM_USERNAME, 'Username.'),
                'email' => new external_value(PARAM_EMAIL, 'Email address.'),
                'firstname' => new external_value(PARAM_TEXT, 'First name.'),
                'lastname' => new external_value(PARAM_TEXT, 'Last name.'),
                'idnumber' => new external_value(PARAM_RAW, 'Stable RTC user idnumber.', VALUE_DEFAULT, ''),
                'phone1' => new external_value(PARAM_TEXT, 'Phone number.', VALUE_DEFAULT, ''),
                'suspended' => new external_value(PARAM_INT, 'Suspended flag.', VALUE_DEFAULT, 0),
                'profile_fields' => new external_multiple_structure(
                    new external_single_structure([
                        'shortname' => new external_value(PARAM_ALPHANUMEXT, 'RTC profile field shortname.'),
                        'value' => new external_value(PARAM_RAW, 'RTC profile field value.', VALUE_DEFAULT, ''),
                    ]),
                    'RTC profile fields.',
                    VALUE_DEFAULT,
                    []
                ),
            ]),
        ]);
    }

    public static function upsert_user(array $user): array
    {
        global $CFG, $DB;

        $params = self::validate_parameters(self::upsert_user_parameters(), ['user' => $user]);
        $user = $params['user'];

        $systemcontext = context_system::instance();
        self::validate_context($systemcontext);

        $existing = false;
        if (!empty($user['moodleid'])) {
            $existing = $DB->get_record('user', [
                'id' => (int) $user['moodleid'],
                'deleted' => 0,
                'mnethostid' => $CFG->mnet_localhost_id,
            ], '*', IGNORE_MISSING);
        }
        if (!$existing && trim($user['idnumber']) !== '') {
            $existing = $DB->get_record('user', [
                'idnumber' => trim($user['idnumber']),
                'deleted' => 0,
                'mnethostid' => $CFG->mnet_localhost_id,
            ], '*', IGNORE_MISSING);
        }
        if (!$existing) {
            $existing = core_user::get_user_by_username($user['username'], '*', null, IGNORE_MISSING);
            if ($existing && !empty($existing->deleted)) {
                $existing = false;
            }
        }
        if (!$existing) {
            $existing = $DB->get_record('user', [
                'email' => $user['email'],
                'deleted' => 0,
                'mnethostid' => $CFG->mnet_localhost_id,
            ], '*', IGNORE_MISSING);
        }

        $record = (object) [
            'username' => core_text::strtolower($user['username']),
            'email' => $user['email'],
            'firstname' => trim($user['firstname']) ?: 'RTC',
            'lastname' => trim($user['lastname']) ?: 'User',
            'idnumber' => trim($user['idnumber']),
            'phone1' => trim($user['phone1']),
            'suspended' => (int) $user['suspended'],
        ];

        if ($existing) {
            require_capability('moodle/user:update', $systemcontext);
            $record->id = $existing->id;
            $record->timemodified = time();
            user_update_user($record, false);
            $saved = $DB->get_record('user', ['id' => $existing->id], '*', MUST_EXIST);
        } else {
            require_capability('moodle/user:create', $systemcontext);
            $record->auth = 'manual';
            $record->confirmed = 1;
            $record->mnethostid = $CFG->mnet_localhost_id;
            $record->password = hash_internal_user_password(random_string(32));
            $record->city = '';
            $record->country = '';
            $record->timezone = !empty($CFG->timezone) ? $CFG->timezone : '99';
            $record->lang = !empty($CFG->lang) ? $CFG->lang : 'en';
            $record->id = user_create_user($record, false);
            $saved = $DB->get_record('user', ['id' => $record->id], '*', MUST_EXIST);
        }

        $profilesaved = local_rtcsync_save_profile_fields((int) $saved->id, $user['profile_fields'] ?? []);

        return [
            'id' => (int) $saved->id,
            'username' => $saved->username,
            'email' => $saved->email,
            'idnumber' => $saved->idnumber,
            'profile_fields_saved' => $profilesaved,
        ];
    }

    public static function upsert_user_returns(): external_single_structure
    {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Moodle user id.'),
            'username' => new external_value(PARAM_USERNAME, 'Username.'),
            'email' => new external_value(PARAM_EMAIL, 'Email address.'),
            'idnumber' => new external_value(PARAM_RAW, 'User idnumber.'),
            'profile_fields_saved' => new external_value(PARAM_INT, 'Number of profile fields updated.'),
        ]);
    }

    public static function sync_system_roles_parameters(): external_function_parameters
    {
        return new external_function_parameters([
            'access' => new external_single_structure([
                'userid' => new external_value(PARAM_INT, 'Moodle user id.'),
                'role_shortnames' => new external_multiple_structure(
                    new external_value(PARAM_ALPHANUMEXT, 'Approved Moodle system role shortname.'),
                    'Desired RTC-managed system roles.',
                    VALUE_DEFAULT,
                    []
                ),
            ]),
        ]);
    }

    public static function sync_system_roles(array $access): array
    {
        global $DB;

        $params = self::validate_parameters(self::sync_system_roles_parameters(), ['access' => $access]);
        $access = $params['access'];
        $systemcontext = context_system::instance();
        self::validate_context($systemcontext);
        require_capability('moodle/role:assign', $systemcontext);

        $userid = (int) $access['userid'];
        $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id', MUST_EXIST);

        // V2 permits only manager at system scope. Student and editingteacher
        // roles are always derived from individual course relationships.
        $allowedshortnames = ['manager'];
        $desiredshortnames = array_values(array_unique(array_intersect(
            array_map('trim', $access['role_shortnames'] ?? []),
            $allowedshortnames
        )));
        $managedroles = $DB->get_records_list('role', 'shortname', $allowedshortnames);
        $managedroleids = array_map('intval', array_keys($managedroles));
        $desiredroleids = [];

        foreach ($managedroles as $role) {
            if (in_array($role->shortname, $desiredshortnames, true)) {
                $desiredroleids[] = (int) $role->id;
            }
        }

        $component = 'local_rtcsync';
        foreach ($DB->get_records('role_assignments', [
            'userid' => $userid,
            'contextid' => $systemcontext->id,
            'component' => $component,
        ]) as $assignment) {
            if (
                in_array((int) $assignment->roleid, $managedroleids, true)
                && !in_array((int) $assignment->roleid, $desiredroleids, true)
            ) {
                role_unassign((int) $assignment->roleid, $userid, $systemcontext->id, $component);
            }
        }

        foreach ($desiredroleids as $roleid) {
            if (!$DB->record_exists('role_assignments', [
                'roleid' => $roleid,
                'userid' => $userid,
                'contextid' => $systemcontext->id,
                'component' => $component,
            ])) {
                role_assign($roleid, $userid, $systemcontext->id, $component);
            }
        }

        return [
            'userid' => $userid,
            'role_shortnames' => $desiredshortnames,
            'managed_count' => count($desiredshortnames),
        ];
    }

    public static function sync_system_roles_returns(): external_single_structure
    {
        return new external_single_structure([
            'userid' => new external_value(PARAM_INT, 'Moodle user id.'),
            'role_shortnames' => new external_multiple_structure(
                new external_value(PARAM_ALPHANUMEXT, 'Managed role shortname.')
            ),
            'managed_count' => new external_value(PARAM_INT, 'Number of managed system roles.'),
        ]);
    }

    public static function enrol_user_parameters(): external_function_parameters
    {
        return new external_function_parameters([
            'enrolment' => new external_single_structure([
                'courseid' => new external_value(PARAM_INT, 'Moodle course id.'),
                'userid' => new external_value(PARAM_INT, 'Moodle user id.'),
                'role_shortname' => new external_value(PARAM_ALPHANUMEXT, 'Moodle role shortname.'),
                'suspend' => new external_value(PARAM_INT, 'Suspend enrolment flag.', VALUE_DEFAULT, 0),
            ]),
        ]);
    }

    public static function enrol_user(array $enrolment): array
    {
        $params = self::validate_parameters(self::enrol_user_parameters(), ['enrolment' => $enrolment]);
        $enrolment = $params['enrolment'];

        $course = get_course((int) $enrolment['courseid']);
        $context = context_course::instance($course->id);
        self::validate_context($context);
        require_capability('enrol/manual:enrol', $context);

        $role = self::role_by_shortname($enrolment['role_shortname']);
        $instance = self::manual_enrol_instance($course);
        $plugin = enrol_get_plugin('manual');
        $status = (int) $enrolment['suspend'] === 1 ? ENROL_USER_SUSPENDED : ENROL_USER_ACTIVE;

        $plugin->enrol_user($instance, (int) $enrolment['userid'], (int) $role->id, 0, 0, $status);

        return [
            'courseid' => (int) $course->id,
            'userid' => (int) $enrolment['userid'],
            'roleid' => (int) $role->id,
            'role_shortname' => $role->shortname,
        ];
    }

    public static function enrol_user_returns(): external_single_structure
    {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Moodle course id.'),
            'userid' => new external_value(PARAM_INT, 'Moodle user id.'),
            'roleid' => new external_value(PARAM_INT, 'Moodle role id.'),
            'role_shortname' => new external_value(PARAM_ALPHANUMEXT, 'Role shortname.'),
        ]);
    }

    public static function unenrol_user_parameters(): external_function_parameters
    {
        return new external_function_parameters([
            'enrolment' => new external_single_structure([
                'courseid' => new external_value(PARAM_INT, 'Moodle course id.'),
                'userid' => new external_value(PARAM_INT, 'Moodle user id.'),
                'role_shortname' => new external_value(PARAM_ALPHANUMEXT, 'Moodle role shortname.'),
            ]),
        ]);
    }

    public static function unenrol_user(array $enrolment): array
    {
        global $DB;

        $params = self::validate_parameters(self::unenrol_user_parameters(), ['enrolment' => $enrolment]);
        $enrolment = $params['enrolment'];

        $course = get_course((int) $enrolment['courseid']);
        $context = context_course::instance($course->id);
        self::validate_context($context);
        require_capability('enrol/manual:unenrol', $context);

        $role = self::role_by_shortname($enrolment['role_shortname']);
        role_unassign((int) $role->id, (int) $enrolment['userid'], $context->id);

        $remainingroles = $DB->record_exists('role_assignments', [
            'userid' => (int) $enrolment['userid'],
            'contextid' => $context->id,
        ]);
        if (!$remainingroles) {
            $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', IGNORE_MISSING);
            if ($instance) {
                enrol_get_plugin('manual')->unenrol_user($instance, (int) $enrolment['userid']);
            }
        }

        return [
            'courseid' => (int) $course->id,
            'userid' => (int) $enrolment['userid'],
            'roleid' => (int) $role->id,
            'removed' => 1,
        ];
    }

    public static function unenrol_user_returns(): external_single_structure
    {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Moodle course id.'),
            'userid' => new external_value(PARAM_INT, 'Moodle user id.'),
            'roleid' => new external_value(PARAM_INT, 'Moodle role id.'),
            'removed' => new external_value(PARAM_INT, 'Removal flag.'),
        ]);
    }

    public static function upsert_grade_parameters(): external_function_parameters
    {
        return new external_function_parameters([
            'grade' => new external_single_structure([
                'courseid' => new external_value(PARAM_INT, 'Moodle course id.'),
                'userid' => new external_value(PARAM_INT, 'Moodle user id.'),
                'itemname' => new external_value(PARAM_TEXT, 'RTC grade item name.'),
                'idnumber' => new external_value(PARAM_RAW, 'Stable RTC grade item idnumber.'),
                'grade' => new external_value(PARAM_FLOAT, 'Grade value.', VALUE_DEFAULT, null, NULL_ALLOWED),
                'grademax' => new external_value(PARAM_FLOAT, 'Maximum grade.', VALUE_DEFAULT, 100),
                'feedback' => new external_value(PARAM_RAW, 'Feedback text.', VALUE_DEFAULT, ''),
                'clear' => new external_value(PARAM_INT, 'Clear grade flag.', VALUE_DEFAULT, 0),
                'hidden' => new external_value(PARAM_INT, 'Hide grade item flag.', VALUE_DEFAULT, 0),
            ]),
        ]);
    }

    public static function upsert_grade(array $grade): array
    {
        $params = self::validate_parameters(self::upsert_grade_parameters(), ['grade' => $grade]);
        $grade = $params['grade'];

        $course = get_course((int) $grade['courseid']);
        $context = context_course::instance($course->id);
        self::validate_context($context);
        require_capability('moodle/grade:manage', $context);
        require_capability('moodle/grade:edit', $context);

        $item = self::ensure_grade_item(
            (int) $course->id,
            trim($grade['idnumber']),
            trim($grade['itemname']) ?: 'RTC Final Score',
            (float) $grade['grademax'],
            (int) $grade['hidden'] === 1
        );

        $value = (int) $grade['clear'] === 1 ? null : $grade['grade'];
        $item->update_final_grade(
            (int) $grade['userid'],
            $value,
            'local_rtcsync',
            $grade['feedback'],
            FORMAT_PLAIN
        );

        return [
            'itemid' => (int) $item->id,
            'courseid' => (int) $course->id,
            'userid' => (int) $grade['userid'],
            'grade' => $value,
            'cleared' => (int) $grade['clear'],
        ];
    }

    public static function upsert_grade_returns(): external_single_structure
    {
        return new external_single_structure([
            'itemid' => new external_value(PARAM_INT, 'Moodle grade item id.'),
            'courseid' => new external_value(PARAM_INT, 'Moodle course id.'),
            'userid' => new external_value(PARAM_INT, 'Moodle user id.'),
            'grade' => new external_value(PARAM_FLOAT, 'Grade value.', VALUE_DEFAULT, null, NULL_ALLOWED),
            'cleared' => new external_value(PARAM_INT, 'Clear flag.'),
        ]);
    }

    public static function get_managed_state_parameters(): external_function_parameters
    {
        return new external_function_parameters([
            'scope' => new external_value(
                PARAM_ALPHA,
                'State scope: users, systemroles, courses, credits, classes, enrolments, or grades.'
            ),
            'idnumbers' => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Explicit RTC-managed user or course idnumber.'),
                'Identifiers to read. Identifiers use the stable idnumber for the selected managed scope.'
            ),
            'offset' => new external_value(PARAM_INT, 'Page offset.', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT, 'Page size, capped at 100.', VALUE_DEFAULT, 100),
        ]);
    }

    public static function get_managed_state(
        string $scope,
        array $idnumbers,
        int $offset = 0,
        int $limit = 100
    ): array {
        global $CFG, $DB;

        $params = self::validate_parameters(self::get_managed_state_parameters(), [
            'scope' => $scope,
            'idnumbers' => $idnumbers,
            'offset' => $offset,
            'limit' => $limit,
        ]);

        $scope = strtolower(trim($params['scope']));
        if (!in_array($scope, ['users', 'systemroles', 'courses', 'credits', 'classes', 'enrolments', 'grades'], true)) {
            throw new invalid_parameter_exception('Unsupported RTC managed-state scope.');
        }

        $idnumbers = array_values(array_unique(array_filter(array_map(
            static fn($idnumber): string => trim((string) $idnumber),
            $params['idnumbers']
        ), static fn(string $idnumber): bool => $idnumber !== '')));
        if (count($idnumbers) > 100) {
            throw new invalid_parameter_exception('Managed-state reads accept at most 100 identifiers.');
        }

        $offset = max(0, (int) $params['offset']);
        $limit = min(100, max(1, (int) $params['limit']));
        $systemcontext = context_system::instance();
        self::validate_context($systemcontext);
        require_capability('local/rtcsync:readmanagedstate', $systemcontext);

        if (!$idnumbers) {
            return [
                'scope' => $scope,
                'offset' => $offset,
                'limit' => $limit,
                'total' => 0,
                'records' => [],
            ];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($idnumbers, SQL_PARAMS_NAMED, 'rtcid');
        $records = [];
        $total = 0;

        if ($scope === 'users') {
            $where = "u.idnumber {$insql}
                      AND u.deleted = 0
                      AND u.mnethostid = :mnethostid";
            $queryparams = $inparams + ['mnethostid' => $CFG->mnet_localhost_id];
            $total = $DB->count_records_sql(
                "SELECT COUNT(1) FROM {user} u WHERE {$where}",
                $queryparams
            );
            $records = $DB->get_records_sql(
                "SELECT u.id AS record_id,
                        u.id AS moodle_id,
                        u.idnumber,
                        u.username,
                        u.email,
                        u.firstname,
                        u.lastname,
                        u.suspended
                   FROM {user} u
                  WHERE {$where}
               ORDER BY u.id",
                $queryparams,
                $offset,
                $limit
            );
        } else if ($scope === 'systemroles') {
            $where = "u.idnumber {$insql}
                      AND u.deleted = 0
                      AND ctx.contextlevel = :contextlevel
                      AND ctx.instanceid = 0
                      AND ra.component IN (:synccomponent, :ssocomponent)";
            $queryparams = $inparams + [
                'contextlevel' => CONTEXT_SYSTEM,
                'synccomponent' => 'local_rtcsync',
                'ssocomponent' => 'local_rtc_sso',
            ];
            $from = "FROM {user} u
                     JOIN {role_assignments} ra ON ra.userid = u.id
                     JOIN {context} ctx ON ctx.id = ra.contextid
                     JOIN {role} r ON r.id = ra.roleid";
            $total = $DB->count_records_sql(
                "SELECT COUNT(1) {$from} WHERE {$where}",
                $queryparams
            );
            $records = $DB->get_records_sql(
                "SELECT ra.id AS record_id,
                        u.id AS moodle_id,
                        u.idnumber,
                        u.id AS user_id,
                        u.idnumber AS user_idnumber,
                        r.shortname AS role_shortname
                   {$from}
                  WHERE {$where}
               ORDER BY u.id, r.id",
                $queryparams,
                $offset,
                $limit
            );
        } else if ($scope === 'courses') {
            $where = "c.idnumber {$insql}";
            $total = $DB->count_records_sql(
                "SELECT COUNT(1) FROM {course} c WHERE {$where}",
                $inparams
            );
            $records = $DB->get_records_sql(
                "SELECT c.id AS record_id,
                        c.id AS moodle_id,
                        c.idnumber,
                        c.shortname,
                        c.fullname,
                        c.visible,
                        cc.id AS category_id,
                        cc.idnumber AS category_idnumber
                   FROM {course} c
                   JOIN {course_categories} cc ON cc.id = c.category
                  WHERE {$where}
               ORDER BY c.id",
                $inparams,
                $offset,
                $limit
            );
        } else if ($scope === 'credits') {
            $where = "c.idnumber {$insql}";
            $total = $DB->count_records_sql(
                "SELECT COUNT(1) FROM {course} c WHERE {$where}",
                $inparams
            );
            $records = $DB->get_records_sql(
                "SELECT c.id AS record_id,
                        c.id AS moodle_id,
                        c.idnumber,
                        c.shortname,
                        c.fullname,
                        c.visible,
                        cc.id AS category_id,
                        cc.idnumber AS category_idnumber,
                        c.id AS course_id
                   FROM {course} c
                   JOIN {course_categories} cc ON cc.id = c.category
                  WHERE {$where}
               ORDER BY c.id",
                $inparams,
                $offset,
                $limit
            );
            foreach ($records as $record) {
                $context = context_course::instance((int) $record->record_id);
                $assignments = $DB->get_records_sql(
                    "SELECT ra.id, ra.userid, r.shortname
                       FROM {role_assignments} ra
                       JOIN {role} r ON r.id = ra.roleid
                       JOIN {user} u ON u.id = ra.userid
                      WHERE ra.contextid = :contextid
                        AND u.deleted = 0
                   ORDER BY ra.userid, r.shortname",
                    ['contextid' => $context->id]
                );
                $members = [];
                $memberroles = [];
                foreach ($assignments as $assignment) {
                    $members[] = (int) $assignment->userid;
                    $memberroles[] = (int) $assignment->userid . ':' . $assignment->shortname;
                }
                $members = array_values(array_unique($members));
                sort($members, SORT_NUMERIC);
                sort($memberroles, SORT_STRING);
                $record->member_count = count($members);
                $record->member_userids = json_encode($members);
                $record->member_roles = json_encode($memberroles);
            }        } else if ($scope === 'classes') {
            $where = "ch.idnumber {$insql}";
            $total = $DB->count_records_sql(
                "SELECT COUNT(1) FROM {cohort} ch WHERE {$where}",
                $inparams
            );
            $records = $DB->get_records_sql(
                "SELECT ch.id AS record_id,
                        ch.id AS moodle_id,
                        ch.idnumber,
                        ch.name AS fullname,
                        ch.visible
                   FROM {cohort} ch
                  WHERE {$where}
               ORDER BY ch.id",
                $inparams,
                $offset,
                $limit
            );
            foreach ($records as $record) {
                $members = array_map('intval', array_keys($DB->get_records(
                    'cohort_members', ['cohortid' => $record->record_id], '', 'userid'
                )));
                sort($members, SORT_NUMERIC);
                $record->member_count = count($members);
                $record->member_userids = json_encode($members);
            }
        } else if ($scope === 'enrolments') {
            $where = "c.idnumber {$insql}
                      AND e.enrol = :enrolmethod
                      AND u.deleted = 0";
            $queryparams = $inparams + ['enrolmethod' => 'manual'];
            $from = "FROM {course} c
                     JOIN {context} ctx
                       ON ctx.contextlevel = :contextlevel
                      AND ctx.instanceid = c.id
                     JOIN {enrol} e
                       ON e.courseid = c.id
                     JOIN {user_enrolments} ue
                       ON ue.enrolid = e.id
                     JOIN {user} u
                       ON u.id = ue.userid
                     JOIN {role_assignments} ra
                       ON ra.contextid = ctx.id
                      AND ra.userid = u.id
                     JOIN {role} r
                       ON r.id = ra.roleid";
            $queryparams += ['contextlevel' => CONTEXT_COURSE];
            $total = $DB->count_records_sql(
                "SELECT COUNT(1) {$from} WHERE {$where}",
                $queryparams
            );
            $records = $DB->get_records_sql(
                "SELECT ra.id AS record_id,
                        c.id AS course_id,
                        c.idnumber AS course_idnumber,
                        u.id AS user_id,
                        u.idnumber AS user_idnumber,
                        r.shortname AS role_shortname,
                        ue.status AS enrolment_status
                   {$from}
                  WHERE {$where}
               ORDER BY c.id, u.id, r.id",
                $queryparams,
                $offset,
                $limit
            );
        } else {
            $where = "c.idnumber {$insql}
                      AND gi.idnumber LIKE :gradeprefix
                      AND u.deleted = 0
                      AND gg.finalgrade IS NOT NULL";
            $queryparams = $inparams + ['gradeprefix' => 'rtc-subject-score:%'];
            $from = "FROM {course} c
                     JOIN {grade_items} gi
                       ON gi.courseid = c.id
                     JOIN {grade_grades} gg
                       ON gg.itemid = gi.id
                     JOIN {user} u
                       ON u.id = gg.userid";
            $total = $DB->count_records_sql(
                "SELECT COUNT(1) {$from} WHERE {$where}",
                $queryparams
            );
            $records = $DB->get_records_sql(
                "SELECT gg.id AS record_id,
                        c.id AS course_id,
                        c.idnumber AS course_idnumber,
                        u.id AS user_id,
                        u.idnumber AS user_idnumber,
                        gi.id AS grade_item_id,
                        gi.idnumber AS grade_item_idnumber,
                        gg.finalgrade AS grade,
                        gi.grademax AS grade_max,
                        gi.hidden
                   {$from}
                  WHERE {$where}
               ORDER BY c.id, gi.id, u.id",
                $queryparams,
                $offset,
                $limit
            );
        }

        return [
            'scope' => $scope,
            'offset' => $offset,
            'limit' => $limit,
            'total' => (int) $total,
            'records' => array_values(array_map(
                [self::class, 'normalise_managed_state_record'],
                $records
            )),
        ];
    }

    public static function get_managed_state_returns(): external_single_structure
    {
        return new external_single_structure([
            'scope' => new external_value(PARAM_ALPHA, 'Returned state scope.'),
            'offset' => new external_value(PARAM_INT, 'Page offset.'),
            'limit' => new external_value(PARAM_INT, 'Page size.'),
            'total' => new external_value(PARAM_INT, 'Total matching records.'),
            'records' => new external_multiple_structure(
                new external_single_structure([
                    'record_id' => new external_value(PARAM_INT, 'Stable row id.'),
                    'moodle_id' => new external_value(PARAM_INT, 'Moodle entity id.'),
                    'idnumber' => new external_value(PARAM_RAW, 'Entity idnumber.'),
                    'username' => new external_value(PARAM_USERNAME, 'Moodle username.'),
                    'email' => new external_value(PARAM_EMAIL, 'Moodle email.', VALUE_DEFAULT, ''),
                    'firstname' => new external_value(PARAM_TEXT, 'First name.'),
                    'lastname' => new external_value(PARAM_TEXT, 'Last name.'),
                    'suspended' => new external_value(PARAM_INT, 'User suspension status.'),
                    'shortname' => new external_value(PARAM_TEXT, 'Course shortname.'),
                    'fullname' => new external_value(PARAM_TEXT, 'Course fullname.'),
                    'visible' => new external_value(PARAM_INT, 'Course visibility.'),
                    'category_idnumber' => new external_value(PARAM_RAW, 'Leaf course category idnumber.'),
                    'category_path' => new external_value(PARAM_RAW, 'JSON category idnumber path.'),
                    'course_id' => new external_value(PARAM_INT, 'Moodle course id.'),
                    'course_idnumber' => new external_value(PARAM_RAW, 'Course idnumber.'),
                    'user_id' => new external_value(PARAM_INT, 'Moodle user id.'),
                    'user_idnumber' => new external_value(PARAM_RAW, 'User idnumber.'),
                    'role_shortname' => new external_value(PARAM_ALPHANUMEXT, 'Role shortname.'),
                    'enrolment_status' => new external_value(PARAM_INT, 'Moodle enrolment status.'),
                    'grade_item_id' => new external_value(PARAM_INT, 'Moodle grade item id.'),
                    'grade_item_idnumber' => new external_value(PARAM_RAW, 'RTC grade item idnumber.'),
                    'grade' => new external_value(
                        PARAM_FLOAT,
                        'Final grade.',
                        VALUE_DEFAULT,
                        null,
                        NULL_ALLOWED
                    ),
                    'grade_max' => new external_value(PARAM_FLOAT, 'Maximum grade.'),
                    'hidden' => new external_value(PARAM_INT, 'Grade item hidden flag.'),
                    'member_count' => new external_value(PARAM_INT, 'Managed member count.'),
                    'member_userids' => new external_value(PARAM_RAW, 'JSON array of managed Moodle user ids.'),
                    'member_roles' => new external_value(PARAM_RAW, 'JSON array of Moodle userid:role assignments.'),
                ])
            ),
        ]);
    }

    private static function normalise_managed_state_record(stdClass $record): array
    {
        return [
            'record_id' => (int) ($record->record_id ?? 0),
            'moodle_id' => (int) ($record->moodle_id ?? 0),
            'idnumber' => (string) ($record->idnumber ?? ''),
            'username' => (string) ($record->username ?? ''),
            'email' => (string) ($record->email ?? ''),
            'firstname' => (string) ($record->firstname ?? ''),
            'lastname' => (string) ($record->lastname ?? ''),
            'suspended' => (int) ($record->suspended ?? 0),
            'shortname' => (string) ($record->shortname ?? ''),
            'fullname' => (string) ($record->fullname ?? ''),
            'visible' => (int) ($record->visible ?? 0),
            'category_idnumber' => (string) ($record->category_idnumber ?? ''),
            'category_path' => isset($record->category_id)
                ? self::category_path_idnumbers((int) $record->category_id)
                : '[]',
            'course_id' => (int) ($record->course_id ?? 0),
            'course_idnumber' => (string) ($record->course_idnumber ?? ''),
            'user_id' => (int) ($record->user_id ?? 0),
            'user_idnumber' => (string) ($record->user_idnumber ?? ''),
            'role_shortname' => (string) ($record->role_shortname ?? ''),
            'enrolment_status' => (int) ($record->enrolment_status ?? 0),
            'grade_item_id' => (int) ($record->grade_item_id ?? 0),
            'grade_item_idnumber' => (string) ($record->grade_item_idnumber ?? ''),
            'grade' => isset($record->grade) ? (float) $record->grade : null,
            'grade_max' => (float) ($record->grade_max ?? 0),
            'hidden' => (int) ($record->hidden ?? 0),
            'member_count' => (int) ($record->member_count ?? 0),
            'member_userids' => (string) ($record->member_userids ?? '[]'),
            'member_roles' => (string) ($record->member_roles ?? '[]'),
        ];
    }

    private static function ensure_category_path(
        array $path,
        string $fallbackidnumber,
        string $fallbackname
    ): int {
        if (!$path) {
            $path = [[
                'idnumber' => $fallbackidnumber,
                'name' => $fallbackname,
            ]];
        }

        $parentid = 0;
        foreach ($path as $node) {
            $parentid = self::ensure_category(
                (string) ($node['idnumber'] ?? ''),
                (string) ($node['name'] ?? ''),
                $parentid
            );
        }

        return $parentid;
    }

    private static function ensure_category(string $idnumber, string $name, int $parentid = 0): int
    {
        global $DB;

        $idnumber = trim($idnumber) ?: 'rtc-academic';
        $name = trim($name) ?: 'RTC Academic Courses';

        $category = $DB->get_record('course_categories', ['idnumber' => $idnumber], '*', IGNORE_MISSING);
        if (!$category) {
            // Adopt an existing manually-created node instead of duplicating
            // the legacy Program -> Year -> Semester tree.
            $category = $DB->get_record('course_categories', [
                'name' => $name,
                'parent' => $parentid,
            ], '*', IGNORE_MISSING);
            if ($category && (string) $category->idnumber !== $idnumber) {
                $category->idnumber = $idnumber;
                $DB->update_record('course_categories', $category);
            }
        }
        if ($category) {
            if ((int) $category->parent !== $parentid) {
                core_course_category::get((int) $category->id)->change_parent($parentid);
            }

            return (int) $category->id;
        }

        $created = core_course_category::create([
            'name' => $name,
            'idnumber' => $idnumber,
            'parent' => $parentid,
            'visible' => 1,
        ]);

        return (int) $created->id;
    }

    private static function category_path_idnumbers(int $categoryid): string
    {
        global $DB;

        $category = $DB->get_record('course_categories', ['id' => $categoryid], 'id,path', IGNORE_MISSING);
        if (!$category) {
            return '[]';
        }

        $ids = array_values(array_filter(array_map(
            'intval',
            explode('/', trim((string) $category->path, '/'))
        )));
        if (!$ids) {
            return '[]';
        }

        $categories = $DB->get_records_list('course_categories', 'id', $ids, '', 'id,idnumber');
        $path = [];
        foreach ($ids as $id) {
            if (isset($categories[$id])) {
                $path[] = (string) $categories[$id]->idnumber;
            }
        }

        return json_encode($path);
    }
    private static function role_by_shortname(string $shortname): stdClass
    {
        global $DB;

        $role = $DB->get_record('role', ['shortname' => trim($shortname)], '*', IGNORE_MISSING);
        if (!$role) {
            throw new moodle_exception('invalidrole', 'error', '', $shortname);
        }

        return $role;
    }

    private static function manual_enrol_instance(stdClass $course): stdClass
    {
        global $DB;

        $plugin = enrol_get_plugin('manual');
        if (!$plugin) {
            throw new moodle_exception('manualpluginnotinstalled', 'enrol_manual');
        }

        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', IGNORE_MISSING);
        if (!$instance) {
            $plugin->add_default_instance($course);
            $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        }

        return $instance;
    }

    private static function ensure_grade_item(
        int $courseid,
        string $idnumber,
        string $itemname,
        float $grademax,
        bool $hidden
    ): grade_item {
        $idnumber = $idnumber ?: 'rtc-final-score';
        $item = grade_item::fetch(['courseid' => $courseid, 'idnumber' => $idnumber]);

        if (!$item) {
            $item = new grade_item([
                'courseid' => $courseid,
                'itemname' => $itemname,
                'itemtype' => 'manual',
                'itemmodule' => null,
                'iteminstance' => null,
                'itemnumber' => 0,
                'idnumber' => $idnumber,
                'gradetype' => GRADE_TYPE_VALUE,
                'grademax' => $grademax > 0 ? $grademax : 100,
                'grademin' => 0,
            ], false);
            $item->insert('local_rtcsync');
        } else {
            $item->itemname = $itemname;
            $item->gradetype = GRADE_TYPE_VALUE;
            $item->grademax = $grademax > 0 ? $grademax : 100;
            $item->grademin = 0;
            $item->update('local_rtcsync');
        }

        $item->set_hidden($hidden ? 1 : 0, false);

        return $item;
    }
}
