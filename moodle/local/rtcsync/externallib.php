<?php

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/local/rtcsync/locallib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/grade/grade_item.php');

class local_rtcsync_external extends external_api
{
    public static function upsert_course_parameters(): external_function_parameters
    {
        return new external_function_parameters([
            'course' => new external_single_structure([
                'fullname' => new external_value(PARAM_TEXT, 'Course full name.'),
                'shortname' => new external_value(PARAM_TEXT, 'Course short name.'),
                'idnumber' => new external_value(PARAM_RAW, 'Stable RTC course idnumber.'),
                'summary' => new external_value(PARAM_RAW, 'Course summary.', VALUE_DEFAULT, ''),
                'category_idnumber' => new external_value(PARAM_RAW, 'RTC category idnumber.', VALUE_DEFAULT, 'rtc-academic'),
                'category_name' => new external_value(PARAM_TEXT, 'RTC category name.', VALUE_DEFAULT, 'RTC Academic Courses'),
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

        $categoryid = self::ensure_category($course['category_idnumber'], $course['category_name']);
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

    private static function ensure_category(string $idnumber, string $name): int
    {
        global $DB;

        $idnumber = trim($idnumber) ?: 'rtc-academic';
        $name = trim($name) ?: 'RTC Academic Courses';

        $category = $DB->get_record('course_categories', ['idnumber' => $idnumber], '*', IGNORE_MISSING);
        if ($category) {
            return (int) $category->id;
        }

        $created = core_course_category::create([
            'name' => $name,
            'idnumber' => $idnumber,
            'parent' => 0,
            'visible' => 1,
        ]);

        return (int) $created->id;
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
