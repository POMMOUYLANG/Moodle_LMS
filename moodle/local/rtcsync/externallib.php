<?php

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

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

/** Internal signal that a concurrent course-fence claim requires a fresh transaction. */
final class local_rtcsync_course_sync_retry_exception extends \RuntimeException
{
}

class local_rtcsync_external extends external_api
{
    private const MIN_REVISIONED_HISTORY_SCOPE_VERSION = 2026103101;

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
                'source_revision' => new external_value(
                    PARAM_RAW,
                    'Optional positive RTC source revision.',
                    VALUE_DEFAULT,
                    ''
                ),
                'idempotency_key' => new external_value(
                    PARAM_RAW,
                    'Optional deterministic RTC course mutation key.',
                    VALUE_DEFAULT,
                    ''
                ),
            ]),
        ]);
    }

    public static function upsert_course(array $course): array
    {
        global $DB;

        $params = self::validate_parameters(self::upsert_course_parameters(), ['course' => $course]);
        $course = $params['course'];
        $course['idnumber'] = trim((string) $course['idnumber']);
        if ($course['idnumber'] === '' || !str_starts_with($course['idnumber'], 'rtc-subject:')) {
            throw new invalid_parameter_exception(
                'Subject course idnumber must use the rtc-subject: prefix.'
            );
        }

        $revision = self::parse_course_source_revision($course['source_revision'] ?? '');
        $idempotencykey = trim((string) ($course['idempotency_key'] ?? ''));
        if (($revision === null) !== ($idempotencykey === '')) {
            throw new invalid_parameter_exception(
                'Course source_revision and idempotency_key must be supplied together.'
            );
        }
        if ($revision !== null) {
            if (!preg_match('/\Artc-subject:([1-9][0-9]*)\z/', $course['idnumber'], $matches)
                    || $idempotencykey !== 'rtc-course:' . $matches[1] . ':' . $revision) {
                throw new invalid_parameter_exception(
                    'Versioned course requests require the deterministic rtc-course:<subject id>:<source revision> idempotency key.'
                );
            }
        }
        if (!in_array((int) $course['visible'], [0, 1], true)) {
            throw new invalid_parameter_exception('Course visibility must be 0 or 1.');
        }

        $canonicalpayload = self::canonical_course_source_payload($course);
        $payloadhash = null;
        if ($revision !== null) {
            $encodedpayload = json_encode($canonicalpayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encodedpayload === false) {
                throw new invalid_parameter_exception('Course payload cannot be canonicalized.');
            }
            $payloadhash = hash('sha256', $encodedpayload);
        }

        $systemcontext = context_system::instance();
        self::validate_context($systemcontext);
        require_capability('moodle/category:manage', $systemcontext);

        return self::with_course_identity_lock(
            (string) $course['idnumber'],
            static function() use (
                $DB,
                $course,
                $canonicalpayload,
                $revision,
                $idempotencykey,
                $payloadhash
            ): array {
                // Never fall back to shortname matching: a reused display
                // identifier must not let SMS overwrite a manual course. Use
                // the unique sync-state row as the database revision fence;
                // bounded retries resolve a concurrent first-row insert or a
                // lost conditional claim using a fresh transaction snapshot.
                for ($attempt = 0; $attempt < 3; $attempt++) {
                    try {
                        $transaction = $DB->start_delegated_transaction();
                        try {
                            $state = $DB->get_record(
                                'local_rtcsync_coursesync',
                                ['idnumber' => $course['idnumber']],
                                '*',
                                IGNORE_MISSING
                            );
                            $hadstate = (bool) $state;

                            if ($state && $revision === null && (int) $state->sourcerevision > 0) {
                                throw new invalid_parameter_exception(
                                    'This RTC subject has opted into source revisions; unversioned course mutations are no longer accepted.'
                                );
                            }

                            $existing = false;
                            if ($state) {
                                $storedrevision = (int) $state->sourcerevision;
                                if ($storedrevision > 0) {
                                    $existing = $DB->get_record(
                                        'course',
                                        ['id' => (int) $state->courseid],
                                        '*',
                                        IGNORE_MISSING
                                    );
                                    if (!$existing || (string) $existing->idnumber !== (string) $course['idnumber']) {
                                        throw new invalid_parameter_exception(
                                            'The versioned RTC course target is missing or no longer has its recorded stable identity.'
                                        );
                                    }
                                } else if ($revision !== null) {
                                    // Revision-zero rows are provisional legacy
                                    // identity locks, not a positive source fence.
                                    $matchingcourses = $DB->get_records(
                                        'course',
                                        ['idnumber' => $course['idnumber']],
                                        'id ASC',
                                        '*',
                                        0,
                                        2
                                    );
                                    if (count($matchingcourses) > 1) {
                                        throw new invalid_parameter_exception(
                                            'The versioned RTC course identity matches multiple Moodle courses.'
                                        );
                                    }
                                    $existing = $matchingcourses ? reset($matchingcourses) : false;
                                } else {
                                    $existing = $DB->get_record('course', [
                                        'idnumber' => $course['idnumber'],
                                    ], '*', IGNORE_MISSING);
                                }

                                if ($existing) {
                                    // Replays and stale no-ops still return this
                                    // existing mapping under the ordinary update
                                    // authorization boundary.
                                    $coursecontext = context_course::instance((int) $existing->id);
                                    self::validate_context($coursecontext);
                                    require_capability('moodle/course:update', $coursecontext);
                                }

                                if ($revision !== null && $revision < $storedrevision) {
                                    $result = self::course_sync_result($existing, $storedrevision, false, false);
                                    $transaction->allow_commit();
                                    return $result;
                                }
                                if ($revision !== null && $revision === $storedrevision) {
                                    if ((string) $state->idempotencykey !== $idempotencykey
                                            || !hash_equals((string) $state->payloadhash, (string) $payloadhash)) {
                                        throw new invalid_parameter_exception(
                                            'The course source revision was already used with a different key or payload.'
                                        );
                                    }
                                    $result = self::course_sync_result($existing, $storedrevision, false, true);
                                    $transaction->allow_commit();
                                    return $result;
                                }

                                if ($revision === null) {
                                    self::claim_legacy_course_identity($state, (string) $course['idnumber']);
                                } else {
                                    self::claim_course_source_revision(
                                        $state,
                                        (string) $course['idnumber'],
                                        $revision,
                                        $idempotencykey,
                                        (string) $payloadhash
                                    );
                                }
                            } else {
                                if ($revision !== null) {
                                    // Moodle's course idnumber index is deliberately
                                    // non-unique. Do not establish a stable revision
                                    // fence against an arbitrary duplicate target.
                                    $matchingcourses = $DB->get_records(
                                        'course',
                                        ['idnumber' => $course['idnumber']],
                                        'id ASC',
                                        '*',
                                        0,
                                        2
                                    );
                                    if (count($matchingcourses) > 1) {
                                        throw new invalid_parameter_exception(
                                            'The versioned RTC course identity matches multiple Moodle courses.'
                                        );
                                    }
                                    $existing = $matchingcourses ? reset($matchingcourses) : false;
                                } else {
                                    $existing = $DB->get_record('course', [
                                        'idnumber' => $course['idnumber'],
                                    ], '*', IGNORE_MISSING);
                                }

                                // Revision zero records identity serialization for
                                // legacy writers. A versioned first call claims its
                                // positive revision in this unique insert.
                                $now = time();
                                $claimtoken = bin2hex(random_bytes(16));
                                $state = (object) [
                                    'idnumber' => (string) $course['idnumber'],
                                    'courseid' => $existing ? (int) $existing->id : 0,
                                    'sourcerevision' => $revision ?? 0,
                                    'idempotencykey' => $revision === null ? '' : $idempotencykey,
                                    'payloadhash' => $revision === null ? '' : (string) $payloadhash,
                                    'fencetoken' => $claimtoken,
                                    'coursevisible' => (int) $canonicalpayload['visible'],
                                    'timecreated' => $now,
                                    'timemodified' => $now,
                                ];
                                try {
                                    $state->id = $DB->insert_record('local_rtcsync_coursesync', $state);
                                } catch (\dml_write_exception $exception) {
                                    throw new local_rtcsync_course_sync_retry_exception(
                                        'A concurrent first course-sync row claim must be retried.',
                                        0,
                                        $exception
                                    );
                                }
                            }

                            // The revision claim is held in this transaction
                            // before category or course mutations begin.
                            $categoryid = self::ensure_category_path(
                                $course['category_path'] ?? [],
                                $course['category_idnumber'],
                                $course['category_name']
                            );
                            $categorycontext = context_coursecat::instance($categoryid);
                            require_capability('moodle/course:create', $categorycontext);

                            $data = (object) [
                                'fullname' => $canonicalpayload['fullname'],
                                'shortname' => $canonicalpayload['shortname'],
                                'idnumber' => $canonicalpayload['idnumber'],
                                'category' => $categoryid,
                                'summary' => $canonicalpayload['summary'],
                                'summaryformat' => FORMAT_HTML,
                                'visible' => $canonicalpayload['visible'],
                                'format' => 'topics',
                                'numsections' => 1,
                            ];

                            if (!empty($canonicalpayload['startdate'])) {
                                $data->startdate = $canonicalpayload['startdate'];
                            }
                            if (!empty($canonicalpayload['enddate'])) {
                                $data->enddate = $canonicalpayload['enddate'];
                            }

                            if ($existing) {
                                if (!$hadstate) {
                                    $coursecontext = context_course::instance($existing->id);
                                    self::validate_context($coursecontext);
                                    require_capability('moodle/course:update', $coursecontext);
                                }

                                $data->id = $existing->id;
                                $saved = \local_rtcsync\local\delivery_activity_access::synchronize_course_metadata(
                                    (int) $existing->id,
                                    static function() use ($data): void {
                                        update_course($data);
                                    },
                                    (int) $data->visible
                                );
                            } else {
                                $saved = create_course($data);
                                \local_rtcsync\local\delivery_activity_access::ensure_grouping_ui_enabled((int) $saved->id);
                                $saved = $DB->get_record('course', ['id' => (int) $saved->id], '*', MUST_EXIST);
                            }

                            $state->courseid = (int) $saved->id;
                            $state->coursevisible = (int) $saved->visible;
                            $state->timemodified = time();
                            $DB->update_record('local_rtcsync_coursesync', $state);

                            $result = [
                                'id' => (int) $saved->id,
                                'shortname' => $saved->shortname,
                                'idnumber' => $saved->idnumber,
                                'categoryid' => (int) $saved->category,
                            ];
                            if ($revision !== null) {
                                $result += [
                                    'source_revision' => $revision,
                                    'applied' => 1,
                                    'replayed' => 0,
                                ];
                            }
                            $transaction->allow_commit();
                            return $result;
                        } catch (\Throwable $exception) {
                            $transaction->rollback($exception);
                        }
                    } catch (local_rtcsync_course_sync_retry_exception $exception) {
                        if ($attempt >= 2) {
                            if ($exception->getPrevious()) {
                                \local_rtcsync\local\retryable_webservice_error::mark_service_unavailable();
                                throw $exception->getPrevious();
                            }
                            throw \local_rtcsync\local\retryable_webservice_error::lock_timeout();
                        }
                    }
                }

                throw \local_rtcsync\local\retryable_webservice_error::lock_timeout();
            }
        );
    }

    public static function upsert_course_returns(): external_single_structure
    {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Moodle course id.'),
            'shortname' => new external_value(PARAM_TEXT, 'Course shortname.'),
            'idnumber' => new external_value(PARAM_RAW, 'Course idnumber.'),
            'categoryid' => new external_value(PARAM_INT, 'Course category id.'),
            'source_revision' => new external_value(PARAM_INT, 'Latest RTC source revision.', VALUE_OPTIONAL),
            'applied' => new external_value(PARAM_INT, 'Whether the submitted versioned mutation was applied.', VALUE_OPTIONAL),
            'replayed' => new external_value(PARAM_INT, 'Whether the submitted mutation was an idempotent replay.', VALUE_OPTIONAL),
        ]);
    }

    /** Parse a positive source revision without allowing PARAM_INT coercion to change its value. */
    private static function parse_course_source_revision($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_int($value) && !is_string($value)) {
            throw new invalid_parameter_exception('Course source_revision must be a positive integer.');
        }

        $revision = (string) $value;
        $maximum = (string) PHP_INT_MAX;
        if (!preg_match('/\A[1-9][0-9]{0,18}\z/', $revision)
                || strlen($revision) > strlen($maximum)
                || (strlen($revision) === strlen($maximum) && strcmp($revision, $maximum) > 0)) {
            throw new invalid_parameter_exception('Course source_revision must be a positive PHP integer.');
        }

        return (int) $revision;
    }

    /** Canonical source-controlled course fields used to detect same-revision payload conflicts. */
    private static function canonical_course_source_payload(array $course): array
    {
        $categorypath = [];
        foreach ($course['category_path'] ?? [] as $node) {
            $categorypath[] = [
                'idnumber' => trim((string) $node['idnumber']),
                'name' => trim((string) $node['name']),
            ];
        }

        return [
            'fullname' => trim((string) $course['fullname']),
            'shortname' => trim((string) $course['shortname']),
            'idnumber' => trim((string) $course['idnumber']),
            'summary' => (string) $course['summary'],
            'category_idnumber' => trim((string) $course['category_idnumber']),
            'category_name' => trim((string) $course['category_name']),
            'category_path' => $categorypath,
            'visible' => (int) $course['visible'],
            'startdate' => (int) $course['startdate'],
            'enddate' => (int) $course['enddate'],
        ];
    }

    /** Claim a strictly higher source revision before any course-side effects. */
    private static function claim_course_source_revision(
        \stdClass $state,
        string $idnumber,
        int $revision,
        string $idempotencykey,
        string $payloadhash
    ): void {
        global $DB;

        $expectedrevision = (int) $state->sourcerevision;
        if ($revision <= $expectedrevision) {
            throw new \coding_exception('Course revision claims must be strictly increasing.');
        }

        $claimtoken = bin2hex(random_bytes(16));
        $now = time();
        $DB->execute(
            'UPDATE {local_rtcsync_coursesync}
                SET sourcerevision = :newrevision,
                    idempotencykey = :idempotencykey,
                    payloadhash = :payloadhash,
                    fencetoken = :fencetoken,
                    timemodified = :timemodified
              WHERE id = :id
                AND idnumber = :idnumber
                AND sourcerevision = :expectedrevision
                AND sourcerevision < :minimumrevision',
            [
                'newrevision' => $revision,
                'idempotencykey' => $idempotencykey,
                'payloadhash' => $payloadhash,
                'fencetoken' => $claimtoken,
                'timemodified' => $now,
                'id' => (int) $state->id,
                'idnumber' => $idnumber,
                'expectedrevision' => $expectedrevision,
                'minimumrevision' => $revision,
            ]
        );

        // DML execute() does not expose a portable affected-row count. A
        // per-attempt token distinguishes our conditional write from a newer
        // transaction that won while this request was waiting.
        $storedtoken = (string) $DB->get_field(
            'local_rtcsync_coursesync',
            'fencetoken',
            ['id' => (int) $state->id],
            MUST_EXIST
        );
        if (!hash_equals($claimtoken, $storedtoken)) {
            throw new local_rtcsync_course_sync_retry_exception('A concurrent course revision claim won first.');
        }

        $state->sourcerevision = $revision;
        $state->idempotencykey = $idempotencykey;
        $state->payloadhash = $payloadhash;
        $state->fencetoken = $claimtoken;
        $state->timemodified = $now;
    }

    /** Fence legacy writes at revision zero until the first versioned claim. */
    private static function claim_legacy_course_identity(\stdClass $state, string $idnumber): void
    {
        global $DB;

        if ((int) $state->sourcerevision !== 0) {
            throw new \coding_exception('Legacy course writes cannot claim a versioned course identity.');
        }

        $claimtoken = bin2hex(random_bytes(16));
        $DB->execute(
            'UPDATE {local_rtcsync_coursesync}
                SET fencetoken = :fencetoken
              WHERE id = :id
                AND idnumber = :idnumber
                AND sourcerevision = :sourcerevision',
            [
                'fencetoken' => $claimtoken,
                'id' => (int) $state->id,
                'idnumber' => $idnumber,
                'sourcerevision' => 0,
            ]
        );

        $storedtoken = (string) $DB->get_field(
            'local_rtcsync_coursesync',
            'fencetoken',
            ['id' => (int) $state->id],
            MUST_EXIST
        );
        if (!hash_equals($claimtoken, $storedtoken)) {
            throw new local_rtcsync_course_sync_retry_exception('A versioned course claim won before the legacy write.');
        }

        $state->fencetoken = $claimtoken;
    }

    /** Return the stable Moodle course mapping with version outcome fields. */
    private static function course_sync_result(\stdClass $course, int $revision, bool $applied, bool $replayed): array
    {
        return [
            'id' => (int) $course->id,
            'shortname' => (string) $course->shortname,
            'idnumber' => (string) $course->idnumber,
            'categoryid' => (int) $course->category,
            'source_revision' => $revision,
            'applied' => $applied ? 1 : 0,
            'replayed' => $replayed ? 1 : 0,
        ];
    }

    /** Create a Moodle user with the non-deprecated API when the installed core provides it. */
    private static function create_moodle_user(\stdClass $user, bool $updatepassword): int
    {
        if (method_exists(\core\user::class, 'create_user')) {
            return \core\user::create_user($user, $updatepassword);
        }

        return user_create_user($user, $updatepassword);
    }

    /** Update a Moodle user with the non-deprecated API when the installed core provides it. */
    private static function update_moodle_user(\stdClass $user, bool $updatepassword): void
    {
        if (method_exists(\core\user::class, 'update_user')) {
            \core\user::update_user($user, $updatepassword);
            return;
        }

        user_update_user($user, $updatepassword);
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
                'idnumber' => new external_value(
                    PARAM_RAW,
                    'Stable RTC user idnumber in rtc-user:<users.id> format.',
                    VALUE_DEFAULT,
                    ''
                ),
                'phone1' => new external_value(PARAM_TEXT, 'Phone number.', VALUE_DEFAULT, ''),
                'suspended' => new external_value(PARAM_INT, 'Suspended flag (0 or 1).', VALUE_DEFAULT, 0),
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
        $params = self::validate_parameters(self::upsert_user_parameters(), ['user' => $user]);
        $user = $params['user'];
        if (!in_array($user['suspended'], [0, 1], true)) {
            throw new invalid_parameter_exception('Suspended flag must be 0 or 1.');
        }
        if (preg_match('/\Artc-user:[1-9][0-9]*\z/', trim((string) $user['idnumber'])) !== 1) {
            throw new invalid_parameter_exception(
                'RTC user idnumber must use the canonical rtc-user:<positive users.id> format.'
            );
        }

        $requestedidnumber = trim((string) $user['idnumber']);
        return self::with_user_identity_lock(
            $requestedidnumber,
            (int) $user['moodleid'],
            static function() use ($user): array {
                return self::upsert_user_locked($user);
            }
        );
    }

    /** Resolve or mutate a user while holding the lock for its canonical RTC identity. */
    private static function upsert_user_locked(array $user): array
    {
        global $CFG, $DB;

        $systemcontext = context_system::instance();
        self::validate_context($systemcontext);

        $requestedidnumber = trim((string) $user['idnumber']);
        $stableusermatches = $DB->get_records('user', [
            'idnumber' => $requestedidnumber,
            'deleted' => 0,
            'mnethostid' => $CFG->mnet_localhost_id,
        ], '', '*', 0, 2);
        if (count($stableusermatches) > 1) {
            throw new invalid_parameter_exception(
                'The stable RTC user idnumber matches multiple local Moodle accounts.'
            );
        }
        // A marker remains an ownership claim even if its Moodle account was
        // deleted or moved off the local host. Never recycle that RTC identity.
        $stablemarkermatches = $DB->get_records(
            'local_rtcsync_user',
            ['idnumber' => $requestedidnumber],
            '',
            'userid',
            0,
            2
        );
        if (count($stablemarkermatches) > 1) {
            throw new invalid_parameter_exception(
                'The stable RTC user idnumber is marked on multiple local Moodle accounts.'
            );
        }
        $userbystableid = $stableusermatches ? reset($stableusermatches) : false;
        $userbymarker = false;
        if ($stablemarkermatches) {
            $marker = reset($stablemarkermatches);
            $userbymarker = $DB->get_record('user', [
                'id' => (int) $marker->userid,
                'deleted' => 0,
                'mnethostid' => $CFG->mnet_localhost_id,
            ], '*', IGNORE_MISSING);
        }
        if ($userbystableid && $userbymarker
                && (int) $userbystableid->id !== (int) $userbymarker->id) {
            throw new invalid_parameter_exception(
                'The stable RTC user idnumber and managed-user marker identify different Moodle accounts.'
            );
        }
        $stablemapping = $userbystableid ?: $userbymarker;

        $existing = false;
        $resolution = '';
        if (!empty($user['moodleid'])) {
            $existing = $DB->get_record('user', [
                'id' => (int) $user['moodleid'],
                'deleted' => 0,
                'mnethostid' => $CFG->mnet_localhost_id,
            ], '*', IGNORE_MISSING);
            if (!$existing) {
                throw new invalid_parameter_exception(
                    'The supplied Moodle user mapping no longer resolves to an active local account.'
                );
            }
            $resolution = 'moodleid';
            if ($stablemapping && (int) $stablemapping->id !== (int) $existing->id) {
                throw new invalid_parameter_exception(
                    'The supplied Moodle user mapping conflicts with the stable RTC user mapping.'
                );
            }
        }
        if (!$existing && $stablemapping) {
            $existing = $stablemapping;
            if ($existing) {
                $resolution = $userbystableid ? 'idnumber' : 'marker';
            }
        }
        if (!$existing) {
            $existing = core_user::get_user_by_username($user['username'], '*', null, IGNORE_MISSING);
            if ($existing && !empty($existing->deleted)) {
                $existing = false;
            }
            if ($existing) {
                $resolution = 'username';
            }
        }
        if (!$existing) {
            $emailmatches = $DB->get_records_select(
                'user',
                'LOWER(email) = :email AND deleted = 0 AND mnethostid = :mnethostid',
                [
                    'email' => core_text::strtolower(trim($user['email'])),
                    'mnethostid' => (int) $CFG->mnet_localhost_id,
                ],
                '',
                '*',
                0,
                2
            );
            if (count($emailmatches) > 1) {
                throw new invalid_parameter_exception(
                    'The RTC user email matches multiple local Moodle accounts.'
                );
            }
            $existing = $emailmatches ? reset($emailmatches) : false;
            if ($existing) {
                $resolution = 'email';
            }
        }

        if (!$existing && $stablemarkermatches) {
            throw new invalid_parameter_exception(
                'The stable RTC user idnumber is already reserved by an unavailable Moodle ownership marker.'
            );
        }

        if ($existing) {
            $managedmarker = $DB->get_record(
                'local_rtcsync_user',
                ['userid' => (int) $existing->id],
                '*',
                IGNORE_MISSING
            );
            $storedidnumber = trim((string) $existing->idnumber);
            $markeridnumber = trim((string) ($managedmarker->idnumber ?? ''));
            $requestedidnumber = trim((string) $user['idnumber']);
            $incomingstableid = str_starts_with($requestedidnumber, 'rtc-user:');
            $storedstableid = str_starts_with($storedidnumber, 'rtc-user:');
            $markerprovesincoming = $markeridnumber === $requestedidnumber;

            if ($managedmarker) {
                local_rtcsync_require_managed_user((int) $existing->id);
            } else if ($resolution !== 'moodleid' || !$incomingstableid) {
                throw new invalid_parameter_exception(
                    'An unmanaged Moodle account requires an explicit stable RTC user mapping before adoption.'
                );
            }

            if ($incomingstableid) {
                $otherstablematches = $DB->get_records('user', [
                    'idnumber' => $requestedidnumber,
                    'deleted' => 0,
                    'mnethostid' => $CFG->mnet_localhost_id,
                ], '', 'id', 0, 2);
                foreach (array_keys($otherstablematches) as $matcheduserid) {
                    if ((int) $matcheduserid !== (int) $existing->id) {
                        throw new invalid_parameter_exception(
                            'The stable RTC user idnumber is already assigned to another Moodle account.'
                        );
                    }
                }
                $othermarkermatches = $DB->get_records('local_rtcsync_user', [
                    'idnumber' => $requestedidnumber,
                ], '', 'userid', 0, 2);
                foreach (array_keys($othermarkermatches) as $matcheduserid) {
                    if ((int) $matcheduserid !== (int) $existing->id) {
                        throw new invalid_parameter_exception(
                            'The stable RTC user idnumber is already marked on another Moodle account.'
                        );
                    }
                }
            }

            if (($storedstableid && $storedidnumber !== $requestedidnumber)
                    || (str_starts_with($markeridnumber, 'rtc-user:')
                        && $markeridnumber !== $requestedidnumber)) {
                throw new invalid_parameter_exception(
                    'The supplied Moodle mapping conflicts with the account’s stable RTC user identity.'
                );
            }
            if ($managedmarker && $markeridnumber !== $storedidnumber
                    && !($markerprovesincoming && !$storedstableid)) {
                throw new invalid_parameter_exception(
                    'The managed-user marker conflicts with the Moodle account identity.'
                );
            }
            if ($incomingstableid && !$storedstableid
                    && $resolution !== 'moodleid'
                    && !$markerprovesincoming) {
                throw new invalid_parameter_exception(
                    'A legacy ID-card account requires an explicit Moodle mapping or an existing stable RTC identity marker.'
                );
            }

            $usernamecandidate = core_user::get_user_by_username(
                core_text::strtolower(trim((string) $user['username'])),
                '*',
                null,
                IGNORE_MISSING
            );
            if ($usernamecandidate && empty($usernamecandidate->deleted)
                    && (int) $usernamecandidate->id !== (int) $existing->id) {
                throw new invalid_parameter_exception(
                    'The supplied username belongs to a different Moodle account than the stable RTC mapping.'
                );
            }
            $emailcandidates = $DB->get_records_select(
                'user',
                'LOWER(email) = :email AND deleted = 0 AND mnethostid = :mnethostid',
                [
                    'email' => core_text::strtolower(trim((string) $user['email'])),
                    'mnethostid' => (int) $CFG->mnet_localhost_id,
                ],
                '',
                'id',
                0,
                2
            );
            foreach (array_keys($emailcandidates) as $matcheduserid) {
                if ((int) $matcheduserid !== (int) $existing->id) {
                    throw new invalid_parameter_exception(
                        'The supplied email belongs to a different Moodle account than the stable RTC mapping.'
                    );
                }
            }
        }

        $record = (object) [
            'username' => core_text::strtolower($user['username']),
            'email' => $user['email'],
            'firstname' => trim($user['firstname']) ?: 'RTC',
            'lastname' => trim($user['lastname']) ?: 'User',
            'idnumber' => trim($user['idnumber']),
            'phone1' => trim($user['phone1']),
            'suspended' => (int) $user['suspended'],
            'confirmed' => 1,
        ];

        // Keep the Moodle identity, RTC profile, and ownership marker in one
        // commit. A failed profile or marker write must not strand a partial
        // migration where the stable idnumber and marker disagree.
        $transaction = $DB->start_delegated_transaction();
        try {
            if ($existing) {
                require_capability('moodle/user:update', $systemcontext);
                $record->id = $existing->id;
                $record->timemodified = time();
                self::update_moodle_user($record, false);
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
                $record->id = self::create_moodle_user($record, false);
                $saved = $DB->get_record('user', ['id' => $record->id], '*', MUST_EXIST);
            }

            $profilesaved = local_rtcsync_save_profile_fields((int) $saved->id, $user['profile_fields'] ?? []);
            $manageduser = $DB->get_record(
                'local_rtcsync_user',
                ['userid' => (int) $saved->id],
                '*',
                IGNORE_MISSING
            );
            $marker = (object) [
                'userid' => (int) $saved->id,
                'idnumber' => (string) $saved->idnumber,
                'timemodified' => time(),
            ];
            if ($manageduser) {
                $marker->id = (int) $manageduser->id;
                $DB->update_record('local_rtcsync_user', $marker);
            } else {
                $marker->timecreated = time();
                $DB->insert_record('local_rtcsync_user', $marker);
            }

            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }

        return [
            'id' => (int) $saved->id,
            'username' => $saved->username,
            'email' => $saved->email,
            'idnumber' => $saved->idnumber,
            'confirmed' => (int) $saved->confirmed,
            'suspended' => (int) $saved->suspended,
            'profile_fields_saved' => $profilesaved,
        ];
    }

    /** Serialize retries that create or migrate the same stable or mapped Moodle identity. */
    private static function with_user_identity_lock(string $idnumber, int $moodleid, callable $callback)
    {
        // Neither the core nor plugin idnumber index is unique, so serialize the lookup with its writes.
        $factory = \core\lock\lock_config::get_lock_factory('local_rtcsync_user_identity');
        $lockkeys = [$idnumber];
        if ($moodleid > 0) {
            $lockkeys[] = 'moodle:' . $moodleid;
        }
        sort($lockkeys, SORT_STRING);
        $locks = [];
        try {
            foreach ($lockkeys as $lockkey) {
                $lock = $factory->get_lock($lockkey, 1);
                if (!$lock) {
                    throw \local_rtcsync\local\retryable_webservice_error::lock_timeout();
                }
                $locks[] = $lock;
            }
            return $callback();
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }

    /** Serialize course lookup/create retries for the same stable RTC idnumber. */
    private static function with_course_identity_lock(string $idnumber, callable $callback)
    {
        $factory = \core\lock\lock_config::get_lock_factory('local_rtcsync_course_identity');
        $lock = $factory->get_lock($idnumber, 1);
        if (!$lock) {
            throw \local_rtcsync\local\retryable_webservice_error::lock_timeout();
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    public static function upsert_user_returns(): external_single_structure
    {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Moodle user id.'),
            'username' => new external_value(PARAM_USERNAME, 'Username.'),
            'email' => new external_value(PARAM_EMAIL, 'Email address.'),
            'idnumber' => new external_value(PARAM_RAW, 'User idnumber.'),
            'confirmed' => new external_value(PARAM_INT, 'Confirmed account flag.'),
            'suspended' => new external_value(PARAM_INT, 'Suspended account flag.'),
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
        local_rtcsync_require_managed_user($userid);

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

    public static function sync_category_roles_parameters(): external_function_parameters
    {
        return new external_function_parameters([
            'access' => new external_single_structure([
                'userid' => new external_value(PARAM_INT, 'Moodle user id.'),
                'category_roles' => new external_multiple_structure(
                    new external_single_structure([
                        'category_idnumber' => new external_value(PARAM_RAW, 'Stable RTC category idnumber.'),
                        'category_name' => new external_value(PARAM_RAW, 'Multilingual category name.', VALUE_DEFAULT, ''),
                        'role_shortname' => new external_value(PARAM_ALPHANUMEXT, 'Approved category role shortname.'),
                    ]),
                    'Desired RTC-managed category roles.',
                    VALUE_DEFAULT,
                    []
                ),
            ]),
        ]);
    }

    public static function sync_category_roles(array $access): array
    {
        global $DB;

        $params = self::validate_parameters(self::sync_category_roles_parameters(), ['access' => $access]);
        $access = $params['access'];
        $systemcontext = context_system::instance();
        self::validate_context($systemcontext);
        require_capability('moodle/role:assign', $systemcontext);

        $userid = (int) $access['userid'];
        local_rtcsync_require_managed_user($userid);
        $allowedshortnames = ['manager'];
        $managedroles = $DB->get_records_list('role', 'shortname', $allowedshortnames);
        $managedroleids = array_map('intval', array_keys($managedroles));
        $managedrolesbyshortname = [];
        foreach ($managedroles as $managedrole) {
            $managedrolesbyshortname[(string) $managedrole->shortname] = $managedrole;
        }
        $desired = [];

        foreach ($access['category_roles'] ?? [] as $requested) {
            $idnumber = trim((string) ($requested['category_idnumber'] ?? ''));
            $roleshortname = trim((string) ($requested['role_shortname'] ?? ''));
            if ($idnumber === '' || !in_array($roleshortname, $allowedshortnames, true)) {
                throw new invalid_parameter_exception(
                    'RTC category roles may only use a non-empty category idnumber and the approved manager role.'
                );
            }

            $categoryid = self::ensure_category(
                $idnumber,
                trim((string) ($requested['category_name'] ?? '')) ?: $idnumber,
                0
            );
            $role = $managedrolesbyshortname[$roleshortname] ?? null;
            if (!$role) {
                throw new invalid_parameter_exception(
                    'The approved Moodle category role is not installed: ' . $roleshortname
                );
            }
            $context = context_coursecat::instance($categoryid);
            $desired[$context->id . ':' . $role->id] = [
                'categoryid' => $categoryid,
                'idnumber' => $idnumber,
                'contextid' => (int) $context->id,
                'roleid' => (int) $role->id,
            ];
        }

        $component = 'local_rtcsync';
        foreach ($DB->get_records_sql(
            'SELECT ra.id, ra.roleid, ra.contextid
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid
              WHERE ra.userid = :userid
                AND ra.component = :component
                AND ctx.contextlevel = :contextlevel',
            [
                'userid' => $userid,
                'component' => $component,
                'contextlevel' => CONTEXT_COURSECAT,
            ]
        ) as $assignment) {
            $key = (string) $assignment->contextid . ':' . (string) $assignment->roleid;
            if (
                in_array((int) $assignment->roleid, $managedroleids, true)
                && !isset($desired[$key])
            ) {
                role_unassign(
                    (int) $assignment->roleid,
                    $userid,
                    (int) $assignment->contextid,
                    $component,
                    0
                );
            }
        }

        foreach ($desired as $wanted) {
            if (!$DB->record_exists('role_assignments', [
                'roleid' => $wanted['roleid'],
                'userid' => $userid,
                'contextid' => $wanted['contextid'],
                'component' => $component,
                'itemid' => 0,
            ])) {
                role_assign(
                    $wanted['roleid'],
                    $userid,
                    $wanted['contextid'],
                    $component,
                    0
                );
            }
        }

        return [
            'userid' => $userid,
            'category_count' => count($desired),
            'category_idnumbers' => array_values(array_map(
                static fn (array $role): string => (string) $role['idnumber'],
                $desired
            )),
        ];
    }

    public static function sync_category_roles_returns(): external_single_structure
    {
        return new external_single_structure([
            'userid' => new external_value(PARAM_INT, 'Moodle user id.'),
            'category_count' => new external_value(PARAM_INT, 'Number of managed category role assignments.'),
            'category_idnumbers' => new external_multiple_structure(
                new external_value(PARAM_ALPHANUMEXT, 'Managed category id.')
            ),
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
                'historyaccess' => new external_value(PARAM_INT, 'Retain read-only history marker.', VALUE_DEFAULT, 0),
                'historysourcetype' => new external_value(PARAM_ALPHANUMEXT, 'History source type.', VALUE_DEFAULT, ''),
                'historysourceid' => new external_value(PARAM_INT, 'History source ID.', VALUE_DEFAULT, 0),
                'deliveryid' => new external_value(PARAM_INT, 'Delivery scope for retained history; 0 for user-program history.', VALUE_DEFAULT, 0),
                'expectedscoperevision' => new external_value(PARAM_INT, 'Expected current delivery-scope revision for delivery-participation clears.', VALUE_DEFAULT, 0),
            ]),
        ]);
    }

    public static function enrol_user(array $enrolment): array
    {
        global $DB;

        $params = self::validate_parameters(self::enrol_user_parameters(), ['enrolment' => $enrolment]);
        $enrolment = $params['enrolment'];

        $course = local_rtcsync_require_managed_course((int) $enrolment['courseid']);
        local_rtcsync_require_managed_user((int) $enrolment['userid']);
        $context = context_course::instance($course->id);
        self::validate_context($context);
        require_capability('enrol/manual:enrol', $context);

        $role = self::role_by_shortname($enrolment['role_shortname']);
        $instance = self::manual_enrol_instance($course);
        $plugin = enrol_get_plugin('manual');
        $status = (int) $enrolment['suspend'] === 1 ? ENROL_USER_SUSPENDED : ENROL_USER_ACTIVE;
        $historyaccess = (int) $enrolment['historyaccess'];
        $historysourcetype = trim((string) $enrolment['historysourcetype']);
        $historysourceid = (int) $enrolment['historysourceid'];
        $deliveryid = (int) $enrolment['deliveryid'];
        $expectedscoperevision = (int) $enrolment['expectedscoperevision'];

        self::validate_history_source($historyaccess, $historysourcetype, $historysourceid, $deliveryid);
        $hashistorysource = $historyaccess === 1 || $historysourcetype !== '' || $historysourceid !== 0;
        if ($hashistorysource && $role->shortname !== 'student') {
            throw new invalid_parameter_exception('Read-only history markers are only valid for student enrolments.');
        }
        if ($historyaccess === 1
                && (($historysourcetype === 'user_program' && $status !== ENROL_USER_SUSPENDED)
                    || ($historysourcetype === 'delivery_participation' && $deliveryid <= 0))) {
            throw new invalid_parameter_exception('User-program history requires a suspended enrolment; delivery history requires a positive delivery ID.');
        }

        $subjectCourseEnrolment = str_starts_with((string) $course->idnumber, 'rtc-subject:');
        $activeSubjectEnrolment = $subjectCourseEnrolment && $status === ENROL_USER_ACTIVE;
        $hasHistoryMarker = $historyaccess === 1 || $historysourcetype !== '' || $historysourceid > 0;
        $historyresult = ['applied' => 0, 'scoperevision' => 0];

        $writeenrolment = static function(bool $accesslockheld) use (
            $DB,
            $plugin,
            $instance,
            $enrolment,
            $role,
            $status,
            $hasHistoryMarker,
            $course,
            $historyaccess,
            $historysourcetype,
            $historysourceid,
            $deliveryid,
            $expectedscoperevision,
            &$historyresult
        ): void {
            // Open the transaction only after acquiring the course lock, so a
            // grouping sync cannot hold the lock while waiting on this write.
            $transaction = $DB->start_delegated_transaction();
            try {
                $plugin->enrol_user(
                    $instance,
                    (int) $enrolment['userid'],
                    (int) $role->id,
                    0,
                    0,
                    $status
                );
                if ($hasHistoryMarker) {
                    if ($accesslockheld) {
                        $historyresult = \local_rtcsync\local\student_history::sync_marker_locked(
                            (int) $enrolment['userid'],
                            (int) $course->id,
                            $historyaccess,
                            $historysourcetype,
                            $historysourceid,
                            $deliveryid,
                            $expectedscoperevision
                        );
                    } else {
                        $historyresult = \local_rtcsync\local\student_history::sync_marker(
                            (int) $enrolment['userid'],
                            (int) $course->id,
                            $historyaccess,
                            $historysourcetype,
                            $historysourceid,
                            $deliveryid,
                            $expectedscoperevision
                        );
                    }
                }
                $transaction->allow_commit();
            } catch (\Throwable $exception) {
                $transaction->rollback($exception);
            }
        };

        if ($activeSubjectEnrolment) {
            // Readiness and the active enrolment write share one lock. A
            // concurrent delivery regrouping or availability lockdown cannot
            // suspend/reconcile the course between these two operations and
            // then be undone by this enrolment. Commit before releasing that
            // lock so a concurrent sync also sees and can suspend the row.
            // This applies to every active subject-course enrolment because
            // manual enrolment status is shared across the user's course roles.
            \local_rtcsync\local\delivery_activity_access::with_ready_course(
                (int) $course->id,
                static function() use ($writeenrolment, $course, $enrolment): void {
                    $writeenrolment(true);
                    // Scope is synchronized before active enrolment. Reconcile
                    // staged delivery groups now, before the learner's first
                    // module request evaluates Moodle grouping availability.
                    \local_rtcsync\local\delivery_activity_access::reconcile_course_delivery_group_memberships_locked(
                        (int) $course->id,
                        (int) $enrolment['userid']
                    );
                }
            );
        } else if ($subjectCourseEnrolment) {
            // Suspended subject-course writes also serialize with regrouping
            // and lockdown, but do not require a ready course.
            \local_rtcsync\local\delivery_activity_access::with_course_access_lock(
                (int) $course->id,
                static fn() => $writeenrolment(true)
            );
        } else if ($hasHistoryMarker) {
            // Marker writes and exact-source clears stay locked through commit; rejected writes roll back first.
            \local_rtcsync\local\delivery_activity_access::with_course_access_lock(
                (int) $course->id,
                static fn() => $writeenrolment(true)
            );
        } else {
            $writeenrolment(false);
        }

        return [
            'courseid' => (int) $course->id,
            'userid' => (int) $enrolment['userid'],
            'roleid' => (int) $role->id,
            'role_shortname' => $role->shortname,
            'historyapplied' => (int) $historyresult['applied'],
            'scoperevision' => (int) $historyresult['scoperevision'],
        ];
    }

    public static function enrol_user_returns(): external_single_structure
    {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Moodle course id.'),
            'userid' => new external_value(PARAM_INT, 'Moodle user id.'),
            'roleid' => new external_value(PARAM_INT, 'Moodle role id.'),
            'role_shortname' => new external_value(PARAM_ALPHANUMEXT, 'Role shortname.'),
            'historyapplied' => new external_value(PARAM_INT, 'Whether a history marker row was changed.'),
            'scoperevision' => new external_value(PARAM_INT, 'Effective delivery-scope revision; 0 when no delivery scope exists or applies.'),
        ]);
    }

    public static function unenrol_user_parameters(): external_function_parameters
    {
        return new external_function_parameters([
            'enrolment' => new external_single_structure([
                'courseid' => new external_value(PARAM_INT, 'Moodle course id.'),
                'userid' => new external_value(PARAM_INT, 'Moodle user id.'),
                'role_shortname' => new external_value(PARAM_ALPHANUMEXT, 'Moodle role shortname.'),
                'historyaccess' => new external_value(PARAM_INT, 'History access flag; unenrolment cannot retain access.', VALUE_DEFAULT, 0),
                'historysourcetype' => new external_value(PARAM_ALPHANUMEXT, 'History source type to clear.', VALUE_DEFAULT, ''),
                'historysourceid' => new external_value(PARAM_INT, 'History source ID to clear.', VALUE_DEFAULT, 0),
                'deliveryid' => new external_value(PARAM_INT, 'Delivery scope for delivery history; 0 for user-program history.', VALUE_DEFAULT, 0),
                'expectedscoperevision' => new external_value(PARAM_INT, 'Expected current delivery-scope revision for delivery-participation clears.', VALUE_DEFAULT, 0),
            ]),
        ]);
    }

    public static function unenrol_user(array $enrolment): array
    {
        global $DB;

        $params = self::validate_parameters(self::unenrol_user_parameters(), ['enrolment' => $enrolment]);
        $enrolment = $params['enrolment'];

        $course = local_rtcsync_require_managed_course((int) $enrolment['courseid']);
        local_rtcsync_require_managed_user((int) $enrolment['userid']);
        $context = context_course::instance($course->id);
        self::validate_context($context);
        require_capability('enrol/manual:unenrol', $context);

        $role = self::role_by_shortname($enrolment['role_shortname']);
        $historyaccess = (int) $enrolment['historyaccess'];
        $historysourcetype = trim((string) $enrolment['historysourcetype']);
        $historysourceid = (int) $enrolment['historysourceid'];
        $deliveryid = (int) $enrolment['deliveryid'];
        $expectedscoperevision = (int) $enrolment['expectedscoperevision'];
        self::validate_history_source($historyaccess, $historysourcetype, $historysourceid, $deliveryid);
        if ($historyaccess === 1) {
            throw new invalid_parameter_exception('Unenrolment cannot retain read-only history access.');
        }
        if (($historysourcetype !== '' || $historysourceid !== 0) && $role->shortname !== 'student') {
            throw new invalid_parameter_exception('Only student enrolments can clear a history marker.');
        }

        // Manual enrolment status is shared across a user's course roles. A
        // teacher-role unassign can therefore remove the shared enrolment if
        // it races an active Student write, so serialize every subject-course
        // role unassign with the same per-course lock.
        $subjectCourse = str_starts_with((string) $course->idnumber, 'rtc-subject:');
        $hasHistorySource = $historysourcetype !== '' || $historysourceid > 0;
        $historyresult = ['applied' => 0, 'scoperevision' => 0];
        $writeunenrolment = static function(bool $accesslockheld) use (
            $DB,
            $role,
            $enrolment,
            $context,
            $course,
            $hasHistorySource,
            $historysourcetype,
            $historysourceid,
            $deliveryid,
            $expectedscoperevision,
            &$historyresult
        ): void {
            // Acquire the course lock before opening the transaction, matching
            // active/suspended enrolment writes and preventing lock inversion.
            $transaction = $DB->start_delegated_transaction();
            if ($hasHistorySource) {
                $syncmarker = $accesslockheld
                    ? [\local_rtcsync\local\student_history::class, 'sync_marker_locked']
                    : [\local_rtcsync\local\student_history::class, 'sync_marker'];
                $historyresult = $syncmarker(
                    (int) $enrolment['userid'],
                    (int) $course->id,
                    0,
                    $historysourcetype,
                    $historysourceid,
                    $deliveryid,
                    $expectedscoperevision
                );
            }

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
            $transaction->allow_commit();
        };

        if ($subjectCourse || $hasHistorySource) {
            // Keep source-scoped clears under the course lock through the outer transaction commit.
            \local_rtcsync\local\delivery_activity_access::with_course_access_lock(
                (int) $course->id,
                static fn() => $writeunenrolment(true)
            );
        } else {
            $writeunenrolment(false);
        }

        return [
            'courseid' => (int) $course->id,
            'userid' => (int) $enrolment['userid'],
            'roleid' => (int) $role->id,
            'removed' => 1,
            'historyapplied' => (int) $historyresult['applied'],
            'scoperevision' => (int) $historyresult['scoperevision'],
        ];
    }

    public static function unenrol_user_returns(): external_single_structure
    {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Moodle course id.'),
            'userid' => new external_value(PARAM_INT, 'Moodle user id.'),
            'roleid' => new external_value(PARAM_INT, 'Moodle role id.'),
            'removed' => new external_value(PARAM_INT, 'Removal flag.'),
            'historyapplied' => new external_value(PARAM_INT, 'Whether a history marker row was changed.'),
            'scoperevision' => new external_value(PARAM_INT, 'Effective delivery-scope revision; 0 when no delivery scope exists or applies.'),
        ]);
    }

    public static function sync_history_marker_parameters(): external_function_parameters
    {
        return new external_function_parameters([
            'history' => new external_single_structure([
                'courseid' => new external_value(PARAM_INT, 'RTC-managed Moodle course ID.'),
                'userid' => new external_value(PARAM_INT, 'RTC-managed Moodle user ID.'),
                'historyaccess' => new external_value(PARAM_INT, '1 to retain history; 0 to clear this source marker.'),
                'historysourcetype' => new external_value(PARAM_ALPHANUMEXT, 'History source type.'),
                'historysourceid' => new external_value(PARAM_INT, 'Positive history source ID.'),
                'deliveryid' => new external_value(PARAM_INT, 'Positive delivery ID for delivery participation; 0 for user-program history.', VALUE_DEFAULT, 0),
                'expectedscoperevision' => new external_value(PARAM_INT, 'Expected current delivery-scope revision for delivery-participation clears.', VALUE_DEFAULT, 0),
            ]),
        ]);
    }

    public static function sync_history_marker(array $history): array
    {
        global $DB;

        $params = self::validate_parameters(self::sync_history_marker_parameters(), ['history' => $history]);
        $history = $params['history'];
        $historyaccess = (int) $history['historyaccess'];
        $historysourcetype = trim((string) $history['historysourcetype']);
        $historysourceid = (int) $history['historysourceid'];
        $deliveryid = (int) $history['deliveryid'];
        $expectedscoperevision = (int) $history['expectedscoperevision'];
        self::validate_history_source($historyaccess, $historysourcetype, $historysourceid, $deliveryid);
        if ($historysourcetype === '' || $historysourceid <= 0) {
            throw new invalid_parameter_exception('A valid history source type and positive source ID are required.');
        }
        $course = local_rtcsync_require_managed_course((int) $history['courseid']);
        local_rtcsync_require_managed_user((int) $history['userid']);
        $context = context_course::instance($course->id);
        self::validate_context($context);
        require_capability('enrol/manual:enrol', $context);

        $result = \local_rtcsync\local\delivery_activity_access::with_course_access_lock(
            (int) $course->id,
            static function() use (
                $DB,
                $history,
                $course,
                $historyaccess,
                $historysourcetype,
                $historysourceid,
                $deliveryid,
                $expectedscoperevision
            ): array {
                $transaction = $DB->start_delegated_transaction();
                $result = \local_rtcsync\local\student_history::sync_marker_locked(
                    (int) $history['userid'],
                    (int) $course->id,
                    $historyaccess,
                    $historysourcetype,
                    $historysourceid,
                    $deliveryid,
                    $expectedscoperevision
                );
                $transaction->allow_commit();

                return $result;
            }
        );

        return [
            'applied' => (int) $result['applied'],
            'scoperevision' => (int) $result['scoperevision'],
            'courseid' => (int) $course->id,
            'userid' => (int) $history['userid'],
            'historyaccess' => $historyaccess,
            'historysourcetype' => $historysourcetype,
            'historysourceid' => $historysourceid,
            'deliveryid' => $deliveryid,
        ];
    }

    public static function sync_history_marker_returns(): external_single_structure
    {
        return new external_single_structure([
            'applied' => new external_value(PARAM_INT, 'Whether a history marker row was changed.'),
            'scoperevision' => new external_value(PARAM_INT, 'Effective delivery-scope revision; 0 when no delivery scope exists or applies.'),
            'courseid' => new external_value(PARAM_INT, 'RTC-managed Moodle course ID.'),
            'userid' => new external_value(PARAM_INT, 'RTC-managed Moodle user ID.'),
            'historyaccess' => new external_value(PARAM_INT, 'History access flag.'),
            'historysourcetype' => new external_value(PARAM_ALPHANUMEXT, 'History source type.'),
            'historysourceid' => new external_value(PARAM_INT, 'History source ID.'),
            'deliveryid' => new external_value(PARAM_INT, 'Delivery scope for history.'),
        ]);
    }

    public static function sync_student_delivery_scope_parameters(): external_function_parameters
    {
        return new external_function_parameters([
            'access' => new external_single_structure([
                'courseid' => new external_value(PARAM_INT, 'RTC-managed subject course ID.'),
                'userid' => new external_value(PARAM_INT, 'RTC-managed Moodle user ID.'),
                'deliveryids' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'Currently eligible SMS delivery ID.'),
                    'Complete set of currently eligible deliveries for this learner and subject.'
                ),
                'revision' => new external_value(PARAM_INT, 'Monotonic SMS delivery-scope revision.'),
            ]),
        ]);
    }

    public static function sync_student_delivery_scope(array $access): array
    {
        global $DB;

        $params = self::validate_parameters(
            self::sync_student_delivery_scope_parameters(),
            ['access' => $access]
        );
        $access = $params['access'];
        $course = local_rtcsync_require_managed_course((int) $access['courseid'], ['rtc-subject:']);
        local_rtcsync_require_managed_user((int) $access['userid']);
        $context = context_course::instance((int) $course->id);
        self::validate_context($context);
        require_capability('enrol/manual:enrol', $context);

        return \local_rtcsync\local\delivery_activity_access::with_course_access_lock(
            (int) $course->id,
            static function() use ($DB, $access, $course): array {
                $transaction = $DB->start_delegated_transaction();
                $result = \local_rtcsync\local\delivery_activity_access::sync_student_delivery_scope_locked(
                    (int) $course->id,
                    (int) $access['userid'],
                    $access['deliveryids'],
                    (int) $access['revision']
                );
                $transaction->allow_commit();

                return $result;
            }
        );
    }

    public static function sync_student_delivery_scope_returns(): external_single_structure
    {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'RTC-managed subject course ID.'),
            'userid' => new external_value(PARAM_INT, 'RTC-managed Moodle user ID.'),
            'revision' => new external_value(PARAM_INT, 'Effective Moodle delivery-scope revision.'),
            'applied' => new external_value(PARAM_INT, 'Whether this request replaced the stored scope.'),
            'deliveryids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Effective active delivery ID.'),
                'Effective sorted active delivery IDs.'
            ),
        ]);
    }

    /**
     * Mark a subject course as delivery-managed and run one bounded access pass.
     *
     * Laravel must require `ready === true` before syncing active student
     * enrolments. Incomplete courses remain hidden and active RTC Student
     * enrolments remain suspended until Moodle's scheduled backfill completes.
     */
    public static function prepare_delivery_course_parameters(): external_function_parameters
    {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'RTC-managed subject Moodle course ID.'),
        ]);
    }

    public static function prepare_delivery_course(int $courseid): array
    {
        $params = self::validate_parameters(
            self::prepare_delivery_course_parameters(),
            ['courseid' => $courseid]
        );
        $course = local_rtcsync_require_managed_course((int) $params['courseid'], ['rtc-subject:']);
        $context = context_course::instance((int) $course->id);
        self::validate_context($context);
        require_capability('moodle/course:update', $context);
        require_capability('moodle/course:managegroups', $context);
        require_capability('enrol/manual:enrol', $context);

        $pluginversion = (string) get_config('local_rtcsync', 'version');
        return [
            'courseid' => (int) $course->id,
            'plugin_version' => $pluginversion,
            'ready' => (int) $pluginversion >= self::MIN_REVISIONED_HISTORY_SCOPE_VERSION
                && \local_rtcsync\local\delivery_activity_access::prepare_course((int) $course->id),
        ];
    }

    public static function prepare_delivery_course_returns(): external_single_structure
    {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'RTC-managed subject Moodle course ID.'),
            'plugin_version' => new external_value(PARAM_TEXT, 'Installed RTC-Sync plugin database version.'),
            'ready' => new external_value(PARAM_BOOL, 'True only when all current course modules are delivery-guarded.'),
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
                'deliveryid' => new external_value(PARAM_INT, 'Delivery scope for this synced score; 0 for legacy/non-delivery scores.', VALUE_DEFAULT, 0),
                'grade' => new external_value(
                    PARAM_FLOAT,
                    'Grade value; required when clear is 0 and null when clear is 1.',
                    VALUE_DEFAULT,
                    null,
                    NULL_ALLOWED
                ),
                'grademax' => new external_value(PARAM_FLOAT, 'Maximum grade.', VALUE_DEFAULT, 100),
                'feedback' => new external_value(PARAM_RAW, 'Feedback text.', VALUE_DEFAULT, ''),
                'clear' => new external_value(
                    PARAM_INT,
                    'Use 1 with a null grade to clear; otherwise use 0 with a grade value.',
                    VALUE_DEFAULT,
                    0
                ),
                'hidden' => new external_value(PARAM_INT, 'Hide grade item flag (0 or 1).', VALUE_DEFAULT, 0),
            ]),
        ]);
    }

    public static function upsert_grade(array $grade): array
    {
        global $DB;

        $params = self::validate_parameters(self::upsert_grade_parameters(), ['grade' => $grade]);
        $grade = $params['grade'];
        if (!in_array($grade['hidden'], [0, 1], true)) {
            throw new invalid_parameter_exception('Hidden flag must be 0 or 1.');
        }
        $clear = (int) $grade['clear'];
        if (!in_array($clear, [0, 1], true)) {
            throw new invalid_parameter_exception('Clear flag must be 0 or 1.');
        }
        if (($clear === 1) !== ($grade['grade'] === null)) {
            throw new invalid_parameter_exception(
                'A clear request requires a null grade; a grade update requires a non-null grade.'
            );
        }
        if (!is_finite((float) $grade['grademax'])) {
            throw new invalid_parameter_exception('Grade maximum must be a finite number.');
        }
        if ((float) $grade['grademax'] <= 0) {
            throw new invalid_parameter_exception('Grade maximum must be greater than zero.');
        }
        if ($grade['grade'] !== null && !is_finite((float) $grade['grade'])) {
            throw new invalid_parameter_exception('Grade value must be a finite number.');
        }

        $course = local_rtcsync_require_managed_course(
            (int) $grade['courseid'],
            ['rtc-subject:']
        );
        local_rtcsync_require_managed_user((int) $grade['userid']);
        $context = context_course::instance($course->id);
        self::validate_context($context);
        require_capability('moodle/grade:manage', $context);
        require_capability('moodle/grade:edit', $context);

        return \local_rtcsync\local\delivery_activity_access::with_course_access_lock(
            (int) $course->id,
            static function() use ($course, $grade, $clear): array {
        global $DB;

        $deliveryid = (int) $grade['deliveryid'];
        $idnumber = trim($grade['idnumber']);
        if ($deliveryid < 0) {
            throw new invalid_parameter_exception('Delivery ID must be 0 for legacy scores or a positive delivery ID.');
        }
        if ($deliveryid > 0) {
            if (!preg_match('/^rtc-subject:([1-9][0-9]*)$/', (string) $course->idnumber, $subjectmatches)) {
                throw new invalid_parameter_exception('Delivery-scoped scores require a numeric RTC subject course idnumber.');
            }
            $expectedidnumber = 'rtc-subject-score:'.$subjectmatches[1].':delivery:'.$deliveryid;
            if ($idnumber !== $expectedidnumber) {
                throw new invalid_parameter_exception('Delivery score idnumber must include the matching subject and delivery IDs.');
            }
            if (!\local_rtcsync\local\delivery_activity_access::has_unique_delivery_grouping(
                (int) $course->id,
                $deliveryid
            )) {
                throw new invalid_parameter_exception(
                    'Delivery-scoped scores require exactly one matching RTC delivery grouping in the subject course.'
                );
            }
        } else if (preg_match('/^rtc-subject-score:[1-9][0-9]*:delivery:[1-9][0-9]*$/', $idnumber)) {
            throw new invalid_parameter_exception('A delivery-scoped score requires a positive delivery ID.');
        }

        $transaction = $DB->start_delegated_transaction();
        try {
        $existingitem = grade_item::fetch([
            'courseid' => (int) $course->id,
            'idnumber' => $idnumber !== '' ? $idnumber : 'rtc-final-score',
        ]);
        if ($existingitem) {
            self::validate_existing_delivery_grade_item(
                $existingitem,
                (int) $course->id,
                (int) $grade['userid'],
                $deliveryid
            );
        }

        $value = $clear === 1 ? null : $grade['grade'];
        if ($value !== null) {
            // Validate against the requested manual-item range before ensure_grade_item() persists metadata.
            $validationitem = $existingitem ? clone $existingitem : new grade_item([
                'courseid' => (int) $course->id,
                'itemtype' => 'manual',
                'gradetype' => GRADE_TYPE_VALUE,
                'grademax' => (float) $grade['grademax'],
                'grademin' => 0,
            ], false);
            $validationitem->gradetype = GRADE_TYPE_VALUE;
            $validationitem->grademax = (float) $grade['grademax'];
            $validationitem->grademin = 0;
            if ((float) $validationitem->bounded_grade((float) $value) !== (float) $value) {
                throw new invalid_parameter_exception(
                    'Grade value falls outside the Moodle grade item’s allowed range.'
                );
            }
        }

        $createdgradeitem = false;
        $item = self::ensure_grade_item(
            (int) $course->id,
            $idnumber,
            trim($grade['itemname']) ?: 'RTC Final Score',
            (float) $grade['grademax'],
            (int) $grade['hidden'] === 1,
            (int) $grade['userid'],
            $deliveryid,
            $createdgradeitem
        );

        $gradeupdated = $item->update_final_grade(
            (int) $grade['userid'],
            $value,
            'local_rtcsync',
            $grade['feedback'],
            FORMAT_PLAIN
        );
        if (!$gradeupdated) {
            throw new \moodle_exception('gradeupdatefailed', 'local_rtcsync');
        }

        if ($deliveryid > 0) {
            self::upsert_delivery_grade_mapping(
                (int) $item->id,
                (int) $course->id,
                $deliveryid,
                $createdgradeitem
            );
        }

        $transaction->allow_commit();

        return [
            'itemid' => (int) $item->id,
            'courseid' => (int) $course->id,
            'userid' => (int) $grade['userid'],
            'grade' => $value,
            'cleared' => $clear,
            'deliveryid' => $deliveryid,
        ];
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
            }
        );
    }

    public static function upsert_grade_returns(): external_single_structure
    {
        return new external_single_structure([
            'itemid' => new external_value(PARAM_INT, 'Moodle grade item id.'),
            'courseid' => new external_value(PARAM_INT, 'Moodle course id.'),
            'userid' => new external_value(PARAM_INT, 'Moodle user id.'),
            'grade' => new external_value(PARAM_FLOAT, 'Grade value.', VALUE_DEFAULT, null, NULL_ALLOWED),
            'cleared' => new external_value(PARAM_INT, 'Clear flag.'),
            'deliveryid' => new external_value(PARAM_INT, 'Delivery scope for the score; 0 when unmapped.'),
        ]);
    }

    public static function get_managed_state_parameters(): external_function_parameters
    {
        return new external_function_parameters([
            'scope' => new external_value(
                PARAM_ALPHA,
                'State scope: users, systemroles, categoryroles, courses, credits, classes, enrolments, grades, activitygrades, managedusers, managedcourses, or managedclasses.'
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
        if (!in_array($scope, ['users', 'systemroles', 'categoryroles', 'courses', 'credits', 'classes', 'enrolments', 'grades', 'activitygrades', 'managedusers', 'managedcourses', 'managedclasses'], true)) {
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

        if (!$idnumbers && !in_array($scope, ['managedusers', 'managedcourses', 'managedclasses'], true)) {
            return [
                'scope' => $scope,
                'offset' => $offset,
                'limit' => $limit,
                'total' => 0,
                'records' => [],
            ];
        }

        $records = [];
        $total = 0;

        if ($scope === 'managedusers') {
            $where = "mu.userid = u.id
                      AND u.deleted = 0
                      AND u.mnethostid = :mnethostid";
            $queryparams = ['mnethostid' => $CFG->mnet_localhost_id];
            $from = "FROM {local_rtcsync_user} mu
                     JOIN {user} u ON u.id = mu.userid";
            $total = $DB->count_records_sql(
                "SELECT COUNT(1) {$from} WHERE {$where}",
                $queryparams
            );
            $records = $DB->get_records_sql(
                "SELECT mu.id AS record_id,
                        u.id AS moodle_id,
                        u.idnumber,
                        u.username,
                        u.email,
                        u.firstname,
                        u.lastname,
                        u.confirmed,
                        u.suspended
                   {$from}
                  WHERE {$where}
               ORDER BY u.id",
                $queryparams,
                $offset,
                $limit
            );
        } else if ($scope === 'managedcourses') {
            $where = "c.id <> :siteid
                      AND (c.idnumber LIKE :subjectpattern OR c.idnumber LIKE :creditpattern)";
            $queryparams = [
                'siteid' => SITEID,
                'subjectpattern' => 'rtc-subject:%',
                'creditpattern' => 'rtc-credit-course:%',
            ];
            $total = $DB->count_records_sql(
                "SELECT COUNT(1) FROM {course} c WHERE {$where}",
                $queryparams
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
                $queryparams,
                $offset,
                $limit
            );
        } else if ($scope === 'managedclasses') {
            // The managed class inventory is enumerated by its stable
            // idnumber prefixes below, so it must not call get_in_or_equal()
            // with the intentionally empty identifier list.
            $insql = '1=1';
            $inparams = [];
        } else {
            [$insql, $inparams] = $DB->get_in_or_equal($idnumbers, SQL_PARAMS_NAMED, 'rtcid');
        }

        if ($scope !== 'managedcourses' && $scope === 'users') {
            $where = "u.idnumber {$insql}
                      AND u.deleted = 0
                      AND u.mnethostid = :mnethostid
                      AND NOT EXISTS (
                          SELECT 1
                            FROM {user} duplicateuser
                           WHERE duplicateuser.idnumber = u.idnumber
                             AND duplicateuser.deleted = 0
                             AND duplicateuser.mnethostid = u.mnethostid
                             AND duplicateuser.id <> u.id
                      )
                      AND NOT EXISTS (
                          SELECT 1
                            FROM {local_rtcsync_user} conflictingmarker
                           WHERE conflictingmarker.idnumber = u.idnumber
                             AND conflictingmarker.userid <> u.id
                      )";
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
                        u.confirmed,
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
        } else if ($scope === 'categoryroles') {
            $where = "u.idnumber {$insql}
                      AND u.deleted = 0
                      AND ctx.contextlevel = :contextlevel
                      AND ra.component = :synccomponent";
            $queryparams = $inparams + [
                'contextlevel' => CONTEXT_COURSECAT,
                'synccomponent' => 'local_rtcsync',
            ];
            $from = "FROM {user} u
                     JOIN {role_assignments} ra ON ra.userid = u.id
                     JOIN {context} ctx ON ctx.id = ra.contextid
                     JOIN {course_categories} cc ON cc.id = ctx.instanceid
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
                        cc.id AS category_id,
                        cc.idnumber AS category_idnumber,
                        r.shortname AS role_shortname
                   {$from}
                  WHERE {$where}
               ORDER BY u.id, cc.id, r.id",
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
            }
        } else if (in_array($scope, ['classes', 'managedclasses'], true)) {
            if ($scope === 'managedclasses') {
                $where = "ch.idnumber LIKE :classpattern OR ch.idnumber LIKE :deliverypattern";
                $queryparams = [
                    'classpattern' => 'rtc-class:%',
                    'deliverypattern' => 'rtc-delivery:%',
                ];
            } else {
                $where = "ch.idnumber {$insql}";
                $queryparams = $inparams;
            }
            $total = $DB->count_records_sql(
                "SELECT COUNT(1) FROM {cohort} ch WHERE {$where}",
                $queryparams
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
                $queryparams,
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
                $assignments = $DB->get_records_sql(
                    "SELECT ra.id AS assignmentid, ra.userid, r.shortname, ctx.instanceid AS courseid
                       FROM {role_assignments} ra
                       JOIN {role} r ON r.id = ra.roleid
                       JOIN {context} ctx ON ctx.id = ra.contextid
                      WHERE ra.component = :component
                        AND ra.itemid = :itemid
                        AND ctx.contextlevel = :contextlevel
                   ORDER BY ctx.instanceid, ra.userid, r.shortname",
                    [
                        'component' => 'local_rtcsync',
                        'itemid' => (int) $record->record_id,
                        'contextlevel' => CONTEXT_COURSE,
                    ]
                );
                $classroles = [];
                foreach ($assignments as $assignment) {
                    $classroles[] = (int) $assignment->courseid . ':'
                        . (int) $assignment->userid . ':'
                        . (string) $assignment->shortname;
                }
                $classroles = array_values(array_unique($classroles));
                sort($classroles, SORT_STRING);
                $record->class_role_assignments = json_encode($classroles);
                $record->course_structure = self::class_course_structure(
                    (string) $record->idnumber
                );
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
        } else if ($scope === 'grades') {
            // Backend grade reconciliation keys by Moodle user ID. Require the
            // current, unique RTC identity and reviewed delivery mapping before read-back.
            $deliveryScoreIdnumber = $DB->sql_concat(
                "'rtc-subject-score:'",
                $DB->sql_substr('c.idnumber', strlen('rtc-subject:') + 1),
                "':'",
                "'delivery:'",
                $DB->sql_cast_to_char('deliverygrade.deliveryid')
            );
            $deliveryGroupingIdnumber = $DB->sql_concat(
                "'rtc-delivery-grouping:'",
                $DB->sql_cast_to_char('deliverygrade.deliveryid')
            );
            $deliveryGradeDeliveryIdsJson = $DB->sql_concat(
                "'['",
                $DB->sql_cast_to_char('deliverygrade.deliveryid'),
                "']'"
            );
            $where = "c.idnumber {$insql}
                      AND gi.idnumber LIKE :gradeprefix
                      AND gi.itemtype = 'manual'
                      AND deliverygrade.deliveryid > 0
                      AND deliverygrade.lineageverified = 1
                      AND (
                          deliverygrade.lineagemanifestid IS NULL
                          OR (
                              TRIM(deliverygrade.lineagemanifestid) <> ''
                              AND EXISTS (
                                  SELECT 1
                                    FROM {local_rtcsync_lineageaudit} reviewedgradeaudit
                                   WHERE reviewedgradeaudit.manifestid = deliverygrade.lineagemanifestid
                                     AND reviewedgradeaudit.courseid = c.id
                                     AND reviewedgradeaudit.recordtype = 'gradeitem'
                                     AND reviewedgradeaudit.targetid = gi.id
                                     AND reviewedgradeaudit.decision = 'map'
                                     AND reviewedgradeaudit.deliveryids = {$deliveryGradeDeliveryIdsJson}
                              )
                          )
                      )
                      AND gi.idnumber = {$deliveryScoreIdnumber}
                      AND (
                          SELECT COUNT(1)
                            FROM {groupings} verifieddeliverygrouping
                           WHERE verifieddeliverygrouping.courseid = c.id
                             AND verifieddeliverygrouping.idnumber = {$deliveryGroupingIdnumber}
                      ) = 1
                      AND u.deleted = 0
                      AND u.mnethostid = :mnethostid
                      AND managed.idnumber = u.idnumber
                      AND TRIM(managed.idnumber) <> ''
                      AND NOT EXISTS (
                          SELECT 1
                            FROM {user} duplicateuser
                           WHERE duplicateuser.idnumber = managed.idnumber
                             AND duplicateuser.deleted = 0
                             AND duplicateuser.mnethostid = u.mnethostid
                             AND duplicateuser.id <> u.id
                      )
                      AND NOT EXISTS (
                          SELECT 1
                            FROM {local_rtcsync_user} duplicatemarker
                           WHERE duplicatemarker.idnumber = managed.idnumber
                             AND duplicatemarker.userid <> u.id
                      )
                      AND gg.finalgrade IS NOT NULL";
            $queryparams = $inparams + [
                'gradeprefix' => 'rtc-subject-score:%',
                'mnethostid' => (int) $CFG->mnet_localhost_id,
            ];
            $from = "FROM {course} c
                     JOIN {grade_items} gi
                       ON gi.courseid = c.id
                     JOIN {local_rtcsync_deliverygrade} deliverygrade
                       ON deliverygrade.gradeitemid = gi.id
                      AND deliverygrade.courseid = c.id
                     JOIN {grade_grades} gg
                       ON gg.itemid = gi.id
                     JOIN {user} u
                       ON u.id = gg.userid
                     JOIN {local_rtcsync_user} managed
                       ON managed.userid = u.id";
            $total = $DB->count_records_sql(
                "SELECT COUNT(1) {$from} WHERE {$where}",
                $queryparams
            );
            $records = $DB->get_records_sql(
                "SELECT gg.id AS record_id,
                        c.id AS course_id,
                        c.idnumber AS course_idnumber,
                        u.id AS user_id,
                        managed.idnumber AS user_idnumber,
                        gi.id AS grade_item_id,
                        gi.idnumber AS grade_item_idnumber,
                        gi.itemname AS grade_item_name,
                        gi.itemmodule AS grade_item_module,
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
        } else if ($scope === 'activitygrades') {
            // Formative sync must not turn a quarantined legacy attempt into a
            // current SMS assessment. Delivery courses require a ready guard
            // and an explicit current module mapping before read-back.
            $deliveryGroupingIdnumber = $DB->sql_concat(
                "'rtc-delivery-grouping:'",
                $DB->sql_cast_to_char('activitydelivery.deliveryid')
            );
            $currentDeliveryIdsJson = $DB->sql_concat(
                "'['",
                $DB->sql_cast_to_char('activitydelivery.deliveryid'),
                "']'"
            );
            $historicalDeliveryIdsJson = $DB->sql_concat(
                "'['",
                $DB->sql_cast_to_char('activityunresolvedhistory.deliveryid'),
                "']'"
            );
            $where = "c.idnumber {$insql}
                      AND cfg.enabled = 1
                      AND syncitem.included = 1
                      AND gi.itemtype = 'mod'
                      AND gi.itemmodule IS NOT NULL
                      AND u.deleted = 0
                      AND u.mnethostid = :mnethostid
                      AND managed.idnumber = u.idnumber
                      AND managed.idnumber LIKE :stableuserprefix
                      AND NOT EXISTS (
                          SELECT 1
                            FROM {user} duplicateuser
                           WHERE duplicateuser.idnumber = managed.idnumber
                             AND duplicateuser.deleted = 0
                             AND duplicateuser.mnethostid = u.mnethostid
                             AND duplicateuser.id <> u.id
                      )
                      AND NOT EXISTS (
                          SELECT 1
                            FROM {local_rtcsync_user} duplicatemarker
                           WHERE duplicatemarker.idnumber = managed.idnumber
                             AND duplicatemarker.userid <> u.id
                      )
                      AND (
                          (
                              NOT EXISTS (
                                  SELECT 1
                                    FROM {local_rtcsync_deliverycourse} deliverycoursestate
                                   WHERE deliverycoursestate.courseid = c.id
                              )
                              AND NOT EXISTS (
                                  SELECT 1
                                    FROM {groupings} deliverygroupingcourse
                                   WHERE deliverygroupingcourse.courseid = c.id
                                     AND deliverygroupingcourse.idnumber LIKE :deliverycoursepattern
                              )
                               AND NOT EXISTS (
                                   SELECT 1
                                     FROM {local_rtcsync_deliverycm} deliverytrackedmodule
                                    WHERE deliverytrackedmodule.courseid = c.id
                               )
                          )
                          OR (
                              EXISTS (
                                  SELECT 1
                                    FROM {local_rtcsync_deliverycourse} readycourse
                                   WHERE readycourse.courseid = c.id
                                     AND readycourse.ready = 1
                              )
                              AND activitydelivery.lineagestatus = 1
                              AND activitydelivery.deliveryid > 0
                              AND activitydelivery.lineagemanifestid IS NOT NULL
                              AND TRIM(activitydelivery.lineagemanifestid) <> ''
                              AND EXISTS (
                                  SELECT 1
                                    FROM {local_rtcsync_lineageaudit} currentactivityaudit
                                   WHERE currentactivityaudit.manifestid = activitydelivery.lineagemanifestid
                                     AND currentactivityaudit.courseid = c.id
                                     AND currentactivityaudit.recordtype = 'module'
                                     AND currentactivityaudit.targetid = activitycm.id
                                     AND currentactivityaudit.decision = 'current'
                                     AND currentactivityaudit.deliveryids = {$currentDeliveryIdsJson}
                              )
                              AND NOT EXISTS (
                                  SELECT 1
                                    FROM {local_rtcsync_deliverycmhist} activityotherhistory
                                   WHERE activityotherhistory.cmid = activitycm.id
                                     AND activityotherhistory.courseid = c.id
                                     AND activityotherhistory.deliveryid <> activitydelivery.deliveryid
                              )
                              AND NOT EXISTS (
                                  SELECT 1
                                    FROM {local_rtcsync_deliverycmhist} activityunresolvedhistory
                                   WHERE activityunresolvedhistory.cmid = activitycm.id
                                     AND activityunresolvedhistory.courseid = c.id
                                     AND activityunresolvedhistory.deliveryid = activitydelivery.deliveryid
                                     AND (
                                         activityunresolvedhistory.lineageverified <> 1
                                         OR activityunresolvedhistory.lineagemanifestid IS NULL
                                         OR TRIM(activityunresolvedhistory.lineagemanifestid) = ''
                                         OR NOT EXISTS (
                                             SELECT 1
                                               FROM {local_rtcsync_lineageaudit} activityhistoryaudit
                                              WHERE activityhistoryaudit.manifestid =
                                                  activityunresolvedhistory.lineagemanifestid
                                                AND activityhistoryaudit.courseid = c.id
                                                AND activityhistoryaudit.recordtype = 'module'
                                                AND activityhistoryaudit.targetid = activitycm.id
                                                AND activityhistoryaudit.decision = 'historical'
                                                AND activityhistoryaudit.deliveryids = {$historicalDeliveryIdsJson}
                                         )
                                     )
                              )
                              AND activitydelivery.groupingid > 0
                              AND (activitycm.groupingid <= 0
                                   OR activitycm.groupingid = activitydelivery.groupingid)
                              AND EXISTS (
                                  SELECT 1
                                    FROM {groupings} activityguardgrouping
                                   WHERE activityguardgrouping.id = activitydelivery.groupingid
                                     AND activityguardgrouping.courseid = c.id
                                     AND activityguardgrouping.idnumber = {$deliveryGroupingIdnumber}
                              )
                              AND (
                                  SELECT COUNT(1)
                                    FROM {groupings} uniqueactivitygrouping
                                   WHERE uniqueactivitygrouping.courseid = c.id
                                     AND uniqueactivitygrouping.idnumber = {$deliveryGroupingIdnumber}
                              ) = 1
                          )
                      )
                      AND gg.finalgrade IS NOT NULL";
            $queryparams = $inparams + [
                'mnethostid' => (int) $CFG->mnet_localhost_id,
                'stableuserprefix' => 'rtc-user:%',
                'deliverycoursepattern' => 'rtc-delivery-grouping:%',
            ];
            $from = "FROM {course} c
                     JOIN {local_rtcsync_formcfg} cfg
                       ON cfg.courseid = c.id
                     JOIN {local_rtcsync_formitem} syncitem
                       ON syncitem.courseid = c.id
                      AND syncitem.included = 1
                     JOIN {grade_items} gi
                       ON gi.id = syncitem.itemid
                      AND gi.courseid = c.id
                     JOIN {grade_grades} gg
                       ON gg.itemid = gi.id
                     JOIN {user} u
                       ON u.id = gg.userid";
            $from .= "\n                     JOIN {local_rtcsync_user} managed
                       ON managed.userid = u.id";
            $from .= "\n                LEFT JOIN {modules} activitymodule
                       ON activitymodule.name = gi.itemmodule
                LEFT JOIN {course_modules} activitycm
                       ON activitycm.course = c.id
                      AND activitycm.module = activitymodule.id
                      AND activitycm.instance = gi.iteminstance
                LEFT JOIN {local_rtcsync_deliverycm} activitydelivery
                       ON activitydelivery.cmid = activitycm.id
                      AND activitydelivery.courseid = c.id";
            $total = $DB->count_records_sql(
                "SELECT COUNT(1) {$from} WHERE {$where}",
                $queryparams
            );
            $records = $DB->get_records_sql(
                "SELECT gg.id AS record_id,
                        c.id AS course_id,
                        c.idnumber AS course_idnumber,
                        u.id AS user_id,
                        managed.idnumber AS user_idnumber,
                        gi.id AS grade_item_id,
                        gi.idnumber AS grade_item_idnumber,
                        gi.itemname AS grade_item_name,
                        gi.itemmodule AS grade_item_module,
                        gg.finalgrade AS grade,
                        gi.grademax AS grade_max,
                        gi.hidden,
                        syncitem.label AS sync_label,
                        syncitem.weight AS sync_weight,
                        (SELECT COUNT(1)
                           FROM {local_rtcsync_formitem} allselected
                           JOIN {grade_items} selectedgradeitem
                             ON selectedgradeitem.id = allselected.itemid
                            AND selectedgradeitem.courseid = c.id
                          WHERE allselected.courseid = c.id
                            AND allselected.included = 1) AS sync_selected_count
                   {$from}
                  WHERE {$where}
               ORDER BY c.id, gi.sortorder, u.id",
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
                    'confirmed' => new external_value(PARAM_INT, 'User confirmation status.'),
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
                    'grade_item_name' => new external_value(PARAM_TEXT, 'Grade item name.'),
                    'grade_item_module' => new external_value(PARAM_ALPHANUMEXT, 'Activity module type.'),
                    'sync_label' => new external_value(PARAM_TEXT, 'Teacher-defined SMS formative label.'),
                    'sync_weight' => new external_value(PARAM_FLOAT, 'Teacher-defined formative weight percentage.'),
                    'sync_selected_count' => new external_value(PARAM_INT, 'Number of activities required for a complete formative result.'),
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
                    'class_role_assignments' => new external_value(
                        PARAM_RAW,
                        'JSON array of managed class courseid:userid:role assignments.'
                    ),
                    'course_structure' => new external_value(
                        PARAM_RAW,
                        'JSON course grouping and child-group structure for a managed SMS class.'
                    ),
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
            'confirmed' => (int) ($record->confirmed ?? 0),
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
            'grade_item_name' => (string) ($record->grade_item_name ?? ''),
            'grade_item_module' => (string) ($record->grade_item_module ?? ''),
            'sync_label' => (string) ($record->sync_label ?? ''),
            'sync_weight' => (float) ($record->sync_weight ?? 0),
            'sync_selected_count' => (int) ($record->sync_selected_count ?? 0),
            'grade' => isset($record->grade) ? (float) $record->grade : null,
            'grade_max' => (float) ($record->grade_max ?? 0),
            'hidden' => (int) ($record->hidden ?? 0),
            'member_count' => (int) ($record->member_count ?? 0),
            'member_userids' => (string) ($record->member_userids ?? '[]'),
            'member_roles' => (string) ($record->member_roles ?? '[]'),
            'class_role_assignments' => (string) ($record->class_role_assignments ?? '[]'),
            'course_structure' => (string) ($record->course_structure ?? '[]'),
        ];
    }

    private static function class_course_structure(string $classidnumber): string
    {
        global $DB;

        if (!str_starts_with($classidnumber, 'rtc-class:')) {
            return '[]';
        }

        $groupingidnumber = 'rtc-class-grouping:' . substr($classidnumber, strlen('rtc-class:'));
        $groupings = $DB->get_records('groupings', ['idnumber' => $groupingidnumber], 'courseid, id');
        $structure = [];
        foreach ($groupings as $grouping) {
            $groups = $DB->get_records_sql(
                "SELECT g.id, g.idnumber, g.name
                   FROM {groups} g
                   JOIN {groupings_groups} gg ON gg.groupid = g.id
                  WHERE gg.groupingid = :groupingid
                    AND g.idnumber LIKE :managedprefix
               ORDER BY g.idnumber",
                [
                    'groupingid' => $grouping->id,
                    'managedprefix' => 'rtc-class-group:%',
                ]
            );
            $children = [];
            foreach ($groups as $group) {
                $userids = array_map('intval', array_keys($DB->get_records(
                    'groups_members',
                    ['groupid' => $group->id],
                    '',
                    'userid'
                )));
                sort($userids, SORT_NUMERIC);
                $children[] = [
                    'idnumber' => (string) $group->idnumber,
                    'name' => (string) $group->name,
                    'userids' => $userids,
                ];
            }
            $structure[] = [
                'courseid' => (int) $grouping->courseid,
                'grouping_idnumber' => (string) $grouping->idnumber,
                'grouping_name' => (string) $grouping->name,
                'groups' => $children,
            ];
        }

        usort($structure, static fn (array $a, array $b): int => $a['courseid'] <=> $b['courseid']);

        return json_encode($structure);
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
            // the legacy Program -> Year -> Semester tree. Only a node whose
            // idnumber is still empty is legacy: a same-named sibling that
            // already carries any idnumber (for example another RTC program
            // with the same name) is a different identity and must never be
            // adopted or rewritten, otherwise two programs steal one node
            // from each other on every sync. Moodle allows duplicate names;
            // the idnumber stays unique, so a new node is created instead.
            $legacy = $DB->get_records_select(
                'course_categories',
                "name = :name AND parent = :parent AND (idnumber IS NULL OR idnumber = '')",
                ['name' => $name, 'parent' => $parentid],
                'id ASC',
                '*',
                0,
                1
            );
            $category = $legacy ? reset($legacy) : false;
            if ($category) {
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

    /**
     * Validate the optional source-scoped history payload before any enrolment write.
     */
    private static function validate_history_source(
        int $historyaccess,
        string $sourcetype,
        int $sourceid,
        int $deliveryid
    ): void
    {
        if (!in_array($historyaccess, [0, 1], true)) {
            throw new invalid_parameter_exception('History access must be 0 or 1.');
        }

        $hasmarker = $historyaccess === 1 || $sourcetype !== '' || $sourceid !== 0;
        if ($hasmarker && (!in_array($sourcetype, ['user_program', 'delivery_participation'], true)
                || $sourceid <= 0
                || ($sourcetype === 'user_program' && $deliveryid !== 0)
                || ($sourcetype === 'delivery_participation' && $deliveryid <= 0))) {
            throw new invalid_parameter_exception('A valid history source type and positive source ID are required.');
        }
        if (!$hasmarker && $deliveryid !== 0) {
            throw new invalid_parameter_exception('A delivery ID requires a history source.');
        }
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

    private static function validate_existing_delivery_grade_item(
        grade_item $item,
        int $courseid,
        int $userid,
        int $deliveryid
    ): void {
        global $DB;

        if ((string) $item->itemtype !== 'manual') {
            throw new invalid_parameter_exception(
                'RTC score items must be manual grade items; activity grades require their own reviewed delivery lineage.'
            );
        }

        $existingdeliverymapping = $DB->get_record(
            'local_rtcsync_deliverygrade',
            ['gradeitemid' => (int) $item->id],
            '*',
            IGNORE_MISSING
        );
        if ($existingdeliverymapping) {
            if ($deliveryid <= 0) {
                throw new invalid_parameter_exception(
                    'A grade item with delivery lineage cannot be updated without its matching positive delivery ID.'
                );
            }
            if ((int) $existingdeliverymapping->courseid !== $courseid
                    || (int) $existingdeliverymapping->deliveryid !== $deliveryid) {
                throw new invalid_parameter_exception(
                    'The requested delivery does not match the grade item’s stored delivery association.'
                );
            }
        }

        $gradeaudits = $DB->get_records('local_rtcsync_lineageaudit', [
            'courseid' => $courseid,
            'recordtype' => 'gradeitem',
            'targetid' => (int) $item->id,
        ], 'id DESC', 'id,manifestid,decision,deliveryids', 0, 1);
        $latestgradeaudit = $gradeaudits ? reset($gradeaudits) : false;
        $mappingmanifestid = $existingdeliverymapping
            ? (string) ($existingdeliverymapping->lineagemanifestid ?? '')
            : '';
        $hasmappingmanifest = $existingdeliverymapping
            && $existingdeliverymapping->lineagemanifestid !== null;
        // A null-manifest legacy row may receive current values but stays unverified.
        // Once review recorded a decision, only its exact audited map remains writable.
        if ($latestgradeaudit || $hasmappingmanifest) {
            if (!$existingdeliverymapping
                    || !$latestgradeaudit
                    || trim($mappingmanifestid) === ''
                    || $mappingmanifestid !== (string) $latestgradeaudit->manifestid
                    || (int) $existingdeliverymapping->lineageverified !== 1
                    || $deliveryid <= 0
                    || (string) $latestgradeaudit->decision !== 'map'
                    || (string) $latestgradeaudit->deliveryids !== json_encode([$deliveryid])) {
                throw new invalid_parameter_exception(
                    'A grade item with a reviewed lineage decision requires a matching audited map before it can be updated.'
                );
            }
        }

        if ($item->is_locked($userid)) {
            // Avoid changing grade item metadata before Moodle rejects a locked grade.
            throw new \moodle_exception('gradeupdatefailed', 'local_rtcsync');
        }
    }

    private static function ensure_grade_item(
        int $courseid,
        string $idnumber,
        string $itemname,
        float $grademax,
        bool $hidden,
        int $userid,
        int $deliveryid,
        bool &$created
    ): grade_item {
        $idnumber = $idnumber ?: 'rtc-final-score';
        $item = grade_item::fetch(['courseid' => $courseid, 'idnumber' => $idnumber]);
        $created = false;

        // Recheck type, lineage decisions, and lock state on the final lookup:
        // an administrator may create or review an item after the optimistic preflight.
        if ($item) {
            self::validate_existing_delivery_grade_item($item, $courseid, $userid, $deliveryid);
        }

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
            $created = true;
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

    /**
     * Persist an explicit delivery association for one RTC-synced manual grade item.
     *
     * @param int $gradeitemid Moodle grade item ID.
     * @param int $courseid Moodle course ID.
     * @param int $deliveryid SMS delivery ID.
     */
    private static function upsert_delivery_grade_mapping(
        int $gradeitemid,
        int $courseid,
        int $deliveryid,
        bool $newgradeitem
    ): void
    {
        global $DB;

        $existing = $DB->get_record('local_rtcsync_deliverygrade', ['gradeitemid' => $gradeitemid], '*', IGNORE_MISSING);
        if ($existing) {
            if ((int) $existing->courseid !== $courseid || (int) $existing->deliveryid !== $deliveryid) {
                throw new invalid_parameter_exception('A synced grade item cannot be reassigned to another delivery.');
            }
            // A current sync can update a grade item, but it cannot establish
            // where values already stored on a legacy item belong. Only a new
            // item created by this request or a reviewed manifest can verify it.
            $existing->timemodified = time();
            $DB->update_record('local_rtcsync_deliverygrade', $existing);
            return;
        }

        $now = time();
        $DB->insert_record('local_rtcsync_deliverygrade', (object) [
            'gradeitemid' => $gradeitemid,
            'courseid' => $courseid,
            'deliveryid' => $deliveryid,
            'timecreated' => $now,
            'timemodified' => $now,
            'lineageverified' => $newgradeitem ? 1 : 0,
            'lineagemanifestid' => null,
        ]);
    }
}
