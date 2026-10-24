<?php

namespace local_rtcsync\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only released grade history for SMS Students with suspended enrolments.
 */
final class student_history
{
    public const PAGE_SIZE = 50;

    /** @var array<string> */
    private const SOURCE_TYPES = ['user_program', 'delivery_participation'];

    /**
     * Create or clear one source-scoped history marker.
     *
     * A history marker never changes course enrolment or role assignments.
     *
     * @param int $userid Moodle user ID.
     * @param int $courseid Moodle course ID.
     * @param int $historyaccess 1 to retain history; 0 to clear one source.
     * @param string $sourcetype Source record kind.
     * @param int $sourceid SMS source record ID.
     */
    public static function sync_marker(
        int $userid,
        int $courseid,
        int $historyaccess,
        string $sourcetype,
        int $sourceid
    ): void {
        global $DB;

        if (!in_array($historyaccess, [0, 1], true)) {
            throw new \invalid_parameter_exception('History access must be 0 or 1.');
        }

        $sourcetype = trim($sourcetype);
        if ($historyaccess === 1 || $sourcetype !== '' || $sourceid !== 0) {
            self::validate_source($sourcetype, $sourceid);
        }

        if ($historyaccess === 1) {
            self::require_suspended_student_enrolment($userid, $courseid);

            $conditions = [
                'userid' => $userid,
                'courseid' => $courseid,
                'sourcetype' => $sourcetype,
                'sourceid' => $sourceid,
            ];
            $existing = $DB->get_record('local_rtcsync_history', $conditions, '*', IGNORE_MISSING);
            if ($existing) {
                $existing->timemodified = time();
                $DB->update_record('local_rtcsync_history', $existing);
            } else {
                $now = time();
                $DB->insert_record('local_rtcsync_history', (object) ($conditions + [
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]));
            }

            return;
        }

        // Missing source metadata is a no-op. Never clear every history marker
        // for a Moodle user or course when an older sync caller omits it.
        if ($sourcetype !== '' && $sourceid > 0) {
            $DB->delete_records('local_rtcsync_history', [
                'userid' => $userid,
                'courseid' => $courseid,
                'sourcetype' => $sourcetype,
                'sourceid' => $sourceid,
            ]);
        }
    }

    /**
     * Validate the current Moodle user against a fresh RTC SSO response.
     *
     * @param array<string, mixed> $rtcuser Fresh user payload from RTC.
     */
    public function require_current_rtc_student(array $rtcuser): void
    {
        global $DB, $USER;

        if (empty($USER->id) || !empty($USER->deleted) || !empty($USER->suspended) || isguestuser()) {
            throw new \moodle_exception('studenthistorydenied', 'local_rtcsync');
        }

        if (($rtcuser['account_is_active'] ?? null) !== true) {
            throw new \moodle_exception('studenthistorydenied', 'local_rtcsync');
        }

        $smsuserid = filter_var($rtcuser['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $email = function_exists('rtc_sso_normalize_verified_email')
            ? rtc_sso_normalize_verified_email((string) ($rtcuser['email'] ?? ''))
            : core_text::strtolower(trim((string) ($rtcuser['email'] ?? '')));
        $detail = is_array($rtcuser['user_detail'] ?? null) ? $rtcuser['user_detail'] : [];
        $idcard = trim((string) ($detail['id_card'] ?? ''));
        $expectedidnumber = $idcard !== '' ? $idcard : 'rtc-user:' . (int) $smsuserid;

        $roles = $rtcuser['roles'] ?? $rtcuser['rtc_roles'] ?? [];
        if (!is_array($roles)) {
            $roles = [];
        }
        $normalizedroles = [];
        foreach ($roles as $role) {
            if (is_array($role)) {
                $role = $role['role_key'] ?? $role['name'] ?? '';
            } else if (is_object($role)) {
                $role = $role->role_key ?? $role->name ?? '';
            }
            $role = core_text::strtolower(trim((string) $role));
            $role = preg_replace('/\s+/', ' ', str_replace(['-', '_'], ' ', $role));
            if ($role !== '') {
                $normalizedroles[] = $role;
            }
        }

        $manageduser = $DB->get_record('local_rtcsync_user', ['userid' => (int) $USER->id], '*', IGNORE_MISSING);
        $moodleemail = core_text::strtolower(trim((string) ($USER->email ?? '')));
        $moodleidnumber = trim((string) ($USER->idnumber ?? ''));

        if (!$smsuserid
            || !validate_email($email)
            || $email !== $moodleemail
            || $expectedidnumber === 'rtc-user:0'
            || $expectedidnumber !== $moodleidnumber
            || !$manageduser
            || trim((string) $manageduser->idnumber) !== $expectedidnumber
            || !in_array('student', $normalizedroles, true)) {
            throw new \moodle_exception('studenthistorydenied', 'local_rtcsync');
        }

        if (!$this->has_valid_suspended_student_marker((int) $USER->id)) {
            throw new \moodle_exception('studenthistorydenied', 'local_rtcsync');
        }
    }

    /**
     * Return a bounded page of this Moodle user's visible, released grade rows.
     *
     * @return array{results: list<array{courseid: int, course: string, item: string, grade: float, max: float, formatted: string}>, hasnext: bool}
     */
    public function visible_results(int $page): array
    {
        global $CFG, $DB, $USER;

        require_once($CFG->libdir . '/gradelib.php');

        $page = max(0, min(1000, $page));
        $offset = $page * self::PAGE_SIZE;
        $now = time();
        // Apply Moodle's grade/category/item visibility rules before the bounded page window.
        // The path prefix catches hidden ancestors as well as the item's immediate category.
        $hiddenCategoryPathPrefix = $DB->sql_concat('hiddencategory.path', "'%'");
        $records = $DB->get_records_sql(
            "SELECT DISTINCT gi.id AS id,
                    gg.id AS gradeid,
                    c.id AS courseid,
                    c.fullname AS coursefullname,
                    gi.sortorder AS sortorder
               FROM {local_rtcsync_history} history
               JOIN {course} c ON c.id = history.courseid
               JOIN {enrol} enrol ON enrol.courseid = c.id AND enrol.enrol = :manual
               JOIN {user_enrolments} ue
                 ON ue.enrolid = enrol.id
                AND ue.userid = history.userid
                AND ue.status = :suspended
               JOIN {context} ctx
                 ON ctx.instanceid = c.id
                AND ctx.contextlevel = :coursecontext
               JOIN {role_assignments} ra
                 ON ra.contextid = ctx.id
                AND ra.userid = history.userid
               JOIN {role} r ON r.id = ra.roleid AND r.shortname = :student
               JOIN {grade_items} gi ON gi.courseid = c.id
               JOIN {grade_grades} gg
                 ON gg.itemid = gi.id
                AND gg.userid = history.userid
               JOIN {grade_categories} itemcategory
                 ON itemcategory.id = gi.categoryid
                AND itemcategory.courseid = c.id
              WHERE history.userid = :userid
                AND history.sourceid > 0
                AND history.sourcetype IN (:userprogram, :participation)
                AND (c.idnumber LIKE :subjectcourse OR c.idnumber LIKE :creditcourse)
                AND c.visible = 1
                AND c.showgrades = 1
                AND gg.finalgrade IS NOT NULL
                AND gi.itemtype NOT IN (:categoryitemtype, :courseitemtype)
                AND gi.gradetype IN (:valuegradetype, :scalegradetype)
                AND (gi.hidden = 0 OR (gi.hidden <> 1 AND gi.hidden <= :itemvisibleat))
                AND (gg.hidden = 0 OR (gg.hidden <> 1 AND gg.hidden <= :gradevisibleat))
                AND NOT EXISTS (
                    SELECT 1
                      FROM {grade_categories} hiddencategory
                     WHERE hiddencategory.courseid = c.id
                       AND itemcategory.path LIKE {$hiddenCategoryPathPrefix}
                       AND (hiddencategory.hidden = 1 OR hiddencategory.hidden > :categoryvisibleat)
                )
              ORDER BY c.id ASC, gi.sortorder ASC, gi.id ASC",
            [
                'manual' => 'manual',
                'suspended' => ENROL_USER_SUSPENDED,
                'coursecontext' => CONTEXT_COURSE,
                'student' => 'student',
                'userid' => (int) $USER->id,
                'userprogram' => 'user_program',
                'participation' => 'delivery_participation',
                'subjectcourse' => 'rtc-subject:%',
                'creditcourse' => 'rtc-credit-course:%',
                'categoryitemtype' => 'category',
                'courseitemtype' => 'course',
                'valuegradetype' => GRADE_TYPE_VALUE,
                'scalegradetype' => GRADE_TYPE_SCALE,
                'itemvisibleat' => $now,
                'gradevisibleat' => $now,
                'categoryvisibleat' => $now,
            ],
            $offset,
            self::PAGE_SIZE + 1
        );

        $hasnext = count($records) > self::PAGE_SIZE;
        if ($hasnext) {
            array_pop($records);
        }

        $gradeids = array_map(static fn($record): int => (int) $record->gradeid, $records);
        $itemids = array_map(static fn($record): int => (int) $record->id, $records);
        $grades = $gradeids === [] ? [] : $DB->get_records_list('grade_grades', 'id', $gradeids);
        $items = $itemids === [] ? [] : $DB->get_records_list('grade_items', 'id', $itemids);

        $results = [];
        foreach ($records as $record) {
            $graderecord = $grades[(int) $record->gradeid] ?? null;
            $itemrecord = $items[(int) $record->id] ?? null;
            if (!$graderecord || !$itemrecord || $graderecord->finalgrade === null) {
                continue;
            }

            $grade = new \grade_grade($graderecord, false);
            $grade->grade_item = new \grade_item($itemrecord, false);
            if (!$grade->grade_item
                || (int) $grade->grade_item->courseid !== (int) $record->courseid
                || $grade->is_hidden()) {
                continue;
            }

            $formattedgrade = grade_format_gradevalue((float) $grade->finalgrade, $grade->grade_item, true);
            if ($formattedgrade === '') {
                continue;
            }

            $results[] = [
                'courseid' => (int) $record->courseid,
                'course' => (string) $record->coursefullname,
                'item' => (string) $grade->grade_item->get_name(false, false),
                'grade' => (float) $grade->finalgrade,
                'max' => (float) $grade->grade_item->grademax,
                'formatted' => $formattedgrade,
            ];
        }

        return ['results' => $results, 'hasnext' => $hasnext];
    }

    private static function validate_source(string $sourcetype, int $sourceid): void
    {
        if (!in_array($sourcetype, self::SOURCE_TYPES, true) || $sourceid <= 0) {
            throw new \invalid_parameter_exception('A valid history source type and positive source ID are required.');
        }
    }

    private static function require_suspended_student_enrolment(int $userid, int $courseid): void
    {
        global $DB;

        $instance = $DB->get_record('enrol', [
            'courseid' => $courseid,
            'enrol' => 'manual',
        ], '*', IGNORE_MISSING);
        if (!$instance || !$DB->record_exists('user_enrolments', [
            'enrolid' => (int) $instance->id,
            'userid' => $userid,
            'status' => ENROL_USER_SUSPENDED,
        ])) {
            throw new \invalid_parameter_exception('History markers require a suspended manual student enrolment.');
        }

        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], IGNORE_MISSING);
        $context = \context_course::instance($courseid);
        if (!$studentroleid || !$DB->record_exists('role_assignments', [
            'userid' => $userid,
            'contextid' => (int) $context->id,
            'roleid' => (int) $studentroleid,
        ])) {
            throw new \invalid_parameter_exception('History markers require the Moodle student role in the suspended course.');
        }
    }

    private function has_valid_suspended_student_marker(int $userid): bool
    {
        global $DB;

        return (bool) $DB->get_field_sql(
            "SELECT 1
               FROM {local_rtcsync_history} history
               JOIN {course} c ON c.id = history.courseid
               JOIN {enrol} enrol ON enrol.courseid = c.id AND enrol.enrol = :manual
               JOIN {user_enrolments} ue
                 ON ue.enrolid = enrol.id
                AND ue.userid = history.userid
                AND ue.status = :suspended
               JOIN {context} ctx
                 ON ctx.instanceid = c.id
                AND ctx.contextlevel = :coursecontext
               JOIN {role_assignments} ra
                 ON ra.contextid = ctx.id
                AND ra.userid = history.userid
               JOIN {role} r ON r.id = ra.roleid AND r.shortname = :student
              WHERE history.userid = :userid
                AND history.sourceid > 0
                AND history.sourcetype IN (:userprogram, :participation)
                AND c.visible = 1
                AND (c.idnumber LIKE :subjectcourse OR c.idnumber LIKE :creditcourse)",
            [
                'manual' => 'manual',
                'suspended' => ENROL_USER_SUSPENDED,
                'coursecontext' => CONTEXT_COURSE,
                'student' => 'student',
                'userid' => $userid,
                'userprogram' => 'user_program',
                'participation' => 'delivery_participation',
                'subjectcourse' => 'rtc-subject:%',
                'creditcourse' => 'rtc-credit-course:%',
            ]
        );
    }
}
