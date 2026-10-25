<?php

namespace local_rtcsync\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only released grade history for active or suspended SMS Student enrolments.
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
        int $sourceid,
        int $deliveryid
    ): void {
        delivery_activity_access::with_course_access_lock($courseid, static function() use (
            $userid,
            $courseid,
            $historyaccess,
            $sourcetype,
            $sourceid,
            $deliveryid
        ): void {
            self::sync_marker_locked($userid, $courseid, $historyaccess, $sourcetype, $sourceid, $deliveryid);
        });
    }

    /**
     * Persist one marker for a caller that already holds the course access lock.
     *
     * This method intentionally does not acquire a lock; use sync_marker()
     * unless the caller is performing a larger atomic course operation.
     */
    public static function sync_marker_locked(
        int $userid,
        int $courseid,
        int $historyaccess,
        string $sourcetype,
        int $sourceid,
        int $deliveryid
    ): void {
        global $DB;

        if (!in_array($historyaccess, [0, 1], true)) {
            throw new \invalid_parameter_exception('History access must be 0 or 1.');
        }

        $sourcetype = trim($sourcetype);
        if ($historyaccess === 1 || $sourcetype !== '' || $sourceid !== 0) {
            self::validate_source($sourcetype, $sourceid, $deliveryid);
        } else if ($deliveryid !== 0) {
            throw new \invalid_parameter_exception('A delivery ID requires a history source.');
        }

        if ($historyaccess === 1) {
            self::require_student_enrolment($userid, $courseid, $sourcetype, $deliveryid);

            $conditions = [
                'userid' => $userid,
                'courseid' => $courseid,
                'sourcetype' => $sourcetype,
                'sourceid' => $sourceid,
            ];
            $existing = $DB->get_record('local_rtcsync_history', $conditions, '*', IGNORE_MISSING);
            if ($existing) {
                $existing->deliveryid = $deliveryid;
                $existing->timemodified = time();
                $DB->update_record('local_rtcsync_history', $existing);
            } else {
                $now = time();
                $DB->insert_record('local_rtcsync_history', (object) ($conditions + [
                    'deliveryid' => $deliveryid,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]));
            }

            return;
        }

        // Missing source metadata is a no-op. Never clear every history marker
        // for a Moodle user or course when an older sync caller omits it.
        if ($sourcetype !== '' && $sourceid > 0) {
            if ($sourcetype === 'delivery_participation' && $deliveryid > 0) {
                // Before delivery IDs were stored, upgraded delivery markers
                // retain a NULL value and cannot grant history access. A clear
                // request is still exact by its user/course/source identity,
                // so remove that legacy row as well as the current keyed form.
                $DB->delete_records_select(
                    'local_rtcsync_history',
                    'userid = :userid AND courseid = :courseid AND sourcetype = :sourcetype'
                        . ' AND sourceid = :sourceid AND (deliveryid = :deliveryid OR deliveryid IS NULL)',
                    [
                        'userid' => $userid,
                        'courseid' => $courseid,
                        'sourcetype' => $sourcetype,
                        'sourceid' => $sourceid,
                        'deliveryid' => $deliveryid,
                    ]
                );
            } else {
                $DB->delete_records('local_rtcsync_history', [
                    'userid' => $userid,
                    'courseid' => $courseid,
                    'sourcetype' => $sourcetype,
                    'sourceid' => $sourceid,
                    'deliveryid' => $deliveryid,
                ]);
            }
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

        if (!$this->has_valid_student_history_marker((int) $USER->id)) {
            throw new \moodle_exception('studenthistorydenied', 'local_rtcsync');
        }
    }

    /**
     * Return a bounded page of this Moodle user's visible, released grade rows.
     *
     * @return array{
     *     results: list<array{courseid: int, course: string, item: string, grade: float, max: float, formatted: string}>,
     *     hasnext: bool,
     *     hasprevious: bool,
     *     nextcursor: ?string,
     *     previouscursor: ?string
     * }
     */
    public function visible_results(string $cursor = ''): array
    {
        global $CFG, $DB, $USER;

        require_once($CFG->libdir . '/gradelib.php');

        $position = self::decode_results_cursor($cursor);
        $previous = $position !== null && $position['direction'] === 'previous';
        $cursorcondition = '';
        $cursorparams = [];
        if ($position !== null) {
            $comparison = $previous ? '<' : '>';
            $cursorcondition = " AND (
                    c.id $comparison :cursorcourseid
                    OR (c.id = :cursorcourseequal AND gi.sortorder $comparison :cursorsortorder)
                    OR (c.id = :cursorcourseitem AND gi.sortorder = :cursorsortorderequal
                        AND gi.id $comparison :cursoritemid)
                )";
            $cursorparams = [
                'cursorcourseid' => $position['courseid'],
                'cursorcourseequal' => $position['courseid'],
                'cursorsortorder' => $position['sortorder'],
                'cursorcourseitem' => $position['courseid'],
                'cursorsortorderequal' => $position['sortorder'],
                'cursoritemid' => $position['itemid'],
            ];
        }
        $orderdirection = $previous ? 'DESC' : 'ASC';
        $now = time();
        // Apply Moodle's grade/category/item visibility rules before the bounded page window.
        // The path prefix catches hidden ancestors as well as the item's immediate category.
        $hiddenCategoryPathPrefix = $DB->sql_concat('hiddencategory.path', "'%'");
        $deliveryGroupingIdnumber = $DB->sql_concat(
            "'rtc-delivery-grouping:'",
            $DB->sql_cast_to_char('history.deliveryid')
        );
        // Course visibility and enrolment status continue to block activities;
        // this global page exposes only Moodle-visible released grade items.
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
                AND ue.status IN (:active, :suspended)
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
          LEFT JOIN {local_rtcsync_deliverygrade} deliverygrade
                 ON deliverygrade.gradeitemid = gi.id
                AND deliverygrade.courseid = c.id
          LEFT JOIN {modules} activitymodule
                 ON activitymodule.name = gi.itemmodule
          LEFT JOIN {course_modules} cm
                 ON cm.course = c.id
                AND cm.module = activitymodule.id
                AND cm.instance = gi.iteminstance
          LEFT JOIN {local_rtcsync_deliverycm} deliverymodule
                 ON deliverymodule.cmid = cm.id
                AND deliverymodule.courseid = c.id
          LEFT JOIN {local_rtcsync_deliverycmhist} deliverymodulehistory
                 ON deliverymodulehistory.cmid = cm.id
                AND deliverymodulehistory.courseid = c.id
                AND deliverymodulehistory.deliveryid = history.deliveryid
          LEFT JOIN {groupings} deliverygrouping
                 ON deliverygrouping.courseid = c.id
                AND deliverygrouping.idnumber = {$deliveryGroupingIdnumber}
              WHERE history.userid = :userid
                {$cursorcondition}
                AND history.sourceid > 0
                AND history.sourcetype IN (:userprogram, :participation)
                AND (
                    (history.sourcetype = :userprogramscope
                     AND history.deliveryid = 0
                     AND ue.status = :programsuspended)
                    OR
                    (history.sourcetype = :participationscope
                     AND history.deliveryid > 0
                     AND (
                        (gi.itemtype = :manualitemtype
                         AND deliverygrade.deliveryid = history.deliveryid)
                        OR
                        (gi.itemtype = :moduleitemtype
                         AND (deliverymodule.deliveryid = history.deliveryid
                              OR deliverymodulehistory.id IS NOT NULL)
                         AND deliverygrouping.id IS NOT NULL)
                     ))
                )
                AND (
                    c.idnumber LIKE :subjectcourse
                    OR EXISTS (
                        SELECT 1
                          FROM {local_rtcsync_deliverycourse} deliverycourse
                         WHERE deliverycourse.courseid = c.id
                    )
                    OR EXISTS (
                        SELECT 1
                          FROM {groupings} managedgrouping
                         WHERE managedgrouping.courseid = c.id
                           AND managedgrouping.idnumber LIKE :deliverygrouping
                    )
                )
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
              ORDER BY c.id {$orderdirection}, gi.sortorder {$orderdirection}, gi.id {$orderdirection}",
            [
                'manual' => 'manual',
                'active' => ENROL_USER_ACTIVE,
                'suspended' => ENROL_USER_SUSPENDED,
                'coursecontext' => CONTEXT_COURSE,
                'student' => 'student',
                'userid' => (int) $USER->id,
                'userprogram' => 'user_program',
                'participation' => 'delivery_participation',
                'userprogramscope' => 'user_program',
                'programsuspended' => ENROL_USER_SUSPENDED,
                'participationscope' => 'delivery_participation',
                'manualitemtype' => 'manual',
                'moduleitemtype' => 'mod',
                'subjectcourse' => 'rtc-subject:%',
                'deliverygrouping' => 'rtc-delivery-grouping:%',
                'categoryitemtype' => 'category',
                'courseitemtype' => 'course',
                'valuegradetype' => GRADE_TYPE_VALUE,
                'scalegradetype' => GRADE_TYPE_SCALE,
                'itemvisibleat' => $now,
                'gradevisibleat' => $now,
                'categoryvisibleat' => $now,
            ] + $cursorparams,
            0,
            self::PAGE_SIZE + 1
        );

        $hasmore = count($records) > self::PAGE_SIZE;
        if ($hasmore) {
            array_pop($records);
        }
        if ($previous) {
            $records = array_reverse($records, true);
        }

        $recordvalues = array_values($records);
        $firstrecord = $recordvalues[0] ?? null;
        $lastrecord = $recordvalues === [] ? null : $recordvalues[count($recordvalues) - 1];
        $hasprevious = $previous ? $hasmore : ($position !== null && $recordvalues !== []);
        $hasnext = $previous ? ($position !== null && $recordvalues !== []) : $hasmore;
        $nextcursor = $lastrecord ? self::encode_results_cursor($lastrecord, 'next') : null;
        $previouscursor = $hasprevious && $firstrecord
            ? self::encode_results_cursor($firstrecord, 'previous')
            : null;

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

        return [
            'results' => $results,
            'hasnext' => $hasnext,
            'hasprevious' => $hasprevious,
            'nextcursor' => $nextcursor,
            'previouscursor' => $previouscursor,
        ];
    }

    /**
     * Encode a stable result position. The query still scopes every page to the current Moodle user.
     *
     * @param \stdClass $record Result row containing courseid, sortorder, and id.
     */
    private static function encode_results_cursor(\stdClass $record, string $direction): string
    {
        $encoded = json_encode([
            'version' => 1,
            'direction' => $direction,
            'courseid' => (int) $record->courseid,
            'sortorder' => (int) $record->sortorder,
            'itemid' => (int) $record->id,
        ], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($encoded), '+/', '-_'), '=');
    }

    /**
     * Decode and validate a result cursor; cursor values only affect ordering, never authorization.
     *
     * @return array{direction: string, courseid: int, sortorder: int, itemid: int}|null
     */
    private static function decode_results_cursor(string $cursor): ?array
    {
        if ($cursor === '') {
            return null;
        }
        if (strlen($cursor) > 128 || preg_match('/\A[A-Za-z0-9_-]+\z/', $cursor) !== 1) {
            throw new \invalid_parameter_exception('Invalid student history cursor.');
        }

        $base64 = strtr($cursor, '-_', '+/');
        $padding = strlen($base64) % 4;
        if ($padding > 0) {
            $base64 .= str_repeat('=', 4 - $padding);
        }
        $json = base64_decode($base64, true);
        if ($json === false) {
            throw new \invalid_parameter_exception('Invalid student history cursor.');
        }

        try {
            $position = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \invalid_parameter_exception('Invalid student history cursor.');
        }
        if (!is_array($position)
            || ($position['version'] ?? null) !== 1
            || !in_array($position['direction'] ?? null, ['next', 'previous'], true)
            || !is_int($position['courseid'] ?? null)
            || $position['courseid'] <= 0
            || !is_int($position['sortorder'] ?? null)
            || $position['sortorder'] < 0
            || !is_int($position['itemid'] ?? null)
            || $position['itemid'] <= 0) {
            throw new \invalid_parameter_exception('Invalid student history cursor.');
        }

        return [
            'direction' => $position['direction'],
            'courseid' => $position['courseid'],
            'sortorder' => $position['sortorder'],
            'itemid' => $position['itemid'],
        ];
    }

    private static function validate_source(string $sourcetype, int $sourceid, int $deliveryid): void
    {
        if (!in_array($sourcetype, self::SOURCE_TYPES, true) || $sourceid <= 0) {
            throw new \invalid_parameter_exception('A valid history source type and positive source ID are required.');
        }
        if (($sourcetype === 'user_program' && $deliveryid !== 0)
                || ($sourcetype === 'delivery_participation' && $deliveryid <= 0)) {
            throw new \invalid_parameter_exception(
                'Delivery participation history requires a positive delivery ID; user-program history uses 0.'
            );
        }
    }

    private static function require_student_enrolment(
        int $userid,
        int $courseid,
        string $sourcetype,
        int $deliveryid
    ): void
    {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid], 'idnumber', IGNORE_MISSING);
        if (!$course
                || (!str_starts_with((string) $course->idnumber, 'rtc-subject:')
                    && !delivery_activity_access::is_enabled($courseid))) {
            throw new \invalid_parameter_exception('History markers are restricted to RTC subject courses.');
        }

        $instance = $DB->get_record('enrol', [
            'courseid' => $courseid,
            'enrol' => 'manual',
        ], '*', IGNORE_MISSING);
        $enrolment = $instance ? $DB->get_record('user_enrolments', [
            'enrolid' => (int) $instance->id,
            'userid' => $userid,
        ], 'id,status', IGNORE_MISSING) : false;
        if (!$enrolment
                || !in_array((int) $enrolment->status, [ENROL_USER_ACTIVE, ENROL_USER_SUSPENDED], true)
                || ($sourcetype === 'user_program' && (int) $enrolment->status !== ENROL_USER_SUSPENDED)
                || ($sourcetype === 'delivery_participation' && $deliveryid <= 0)) {
            throw new \invalid_parameter_exception(
                'History markers require an active or suspended manual student enrolment with matching source metadata.'
            );
        }
        if ($sourcetype === 'delivery_participation'
                && !$DB->record_exists('groupings', [
                    'courseid' => $courseid,
                    'idnumber' => 'rtc-delivery-grouping:' . $deliveryid,
                ])) {
            throw new \invalid_parameter_exception('Delivery history requires a matching RTC delivery grouping in the subject course.');
        }
        if ($sourcetype === 'delivery_participation'
                && (int) $enrolment->status === ENROL_USER_ACTIVE) {
            if (!delivery_activity_access::supports_standard_grouping_restrictions()) {
                throw new \invalid_parameter_exception('Active delivery history requires Moodle grouping availability to be enabled.');
            }
            $deliverycoursestate = $DB->get_record(
                'local_rtcsync_deliverycourse',
                ['courseid' => $courseid],
                'ready',
                IGNORE_MISSING
            );
            if (!$deliverycoursestate || (int) $deliverycoursestate->ready !== 1) {
                throw new \invalid_parameter_exception('Active delivery history requires a fully guarded course.');
            }
        }

        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], IGNORE_MISSING);
        $context = \context_course::instance($courseid);
        if (!$studentroleid || !$DB->record_exists('role_assignments', [
            'userid' => $userid,
            'contextid' => (int) $context->id,
            'roleid' => (int) $studentroleid,
        ])) {
            throw new \invalid_parameter_exception('History markers require the Moodle student role in the course.');
        }
    }

    private function has_valid_student_history_marker(int $userid): bool
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
                AND ue.status IN (:active, :suspended)
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
                AND (
                    (history.sourcetype = :userprogramscope
                     AND history.deliveryid = 0
                     AND ue.status = :programsuspended)
                    OR
                    (history.sourcetype = :participationscope
                     AND history.deliveryid > 0)
                )
                AND (
                    c.idnumber LIKE :subjectcourse
                    OR EXISTS (
                        SELECT 1
                          FROM {local_rtcsync_deliverycourse} deliverycourse
                         WHERE deliverycourse.courseid = c.id
                    )
                    OR EXISTS (
                        SELECT 1
                          FROM {groupings} managedgrouping
                         WHERE managedgrouping.courseid = c.id
                           AND managedgrouping.idnumber LIKE :deliverygrouping
                    )
                )",
            [
                'manual' => 'manual',
                'active' => ENROL_USER_ACTIVE,
                'suspended' => ENROL_USER_SUSPENDED,
                'coursecontext' => CONTEXT_COURSE,
                'student' => 'student',
                'userid' => $userid,
                'userprogram' => 'user_program',
                'participation' => 'delivery_participation',
                'userprogramscope' => 'user_program',
                'programsuspended' => ENROL_USER_SUSPENDED,
                'participationscope' => 'delivery_participation',
                'subjectcourse' => 'rtc-subject:%',
                'deliverygrouping' => 'rtc-delivery-grouping:%',
            ]
        );
    }
}
