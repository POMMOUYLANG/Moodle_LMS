<?php

namespace local_rtcsync;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the RTC synchronization external service.
 */
#[CoversClass(\local_rtcsync_external::class)]
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class externallib_test extends \advanced_testcase
{
    protected function setUp(): void
    {
        parent::setUp();

        global $CFG;
        require_once($CFG->dirroot . '/local/rtcsync/externallib.php');
    }

    public function test_user_read_returns_only_explicit_idnumbers(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();

        $included = $this->getDataGenerator()->create_user([
            'idnumber' => 'rtc-user:included',
        ]);
        $this->getDataGenerator()->create_user([
            'idnumber' => 'rtc-user:unrelated',
        ]);

        $result = \local_rtcsync_external::get_managed_state(
            'users',
            ['rtc-user:included'],
            0,
            100
        );

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['records']);
        $this->assertSame((int) $included->id, $result['records'][0]['moodle_id']);
        $this->assertSame('rtc-user:included', $result['records'][0]['idnumber']);
    }

    public function test_course_read_is_explicit_and_paginated(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();

        $included = $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-subject:11',
        ]);
        $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-subject:unrelated',
        ]);

        $result = \local_rtcsync_external::get_managed_state(
            'courses',
            ['rtc-subject:11'],
            0,
            1
        );

        $this->assertSame(1, $result['total']);
        $this->assertSame(1, $result['limit']);
        $this->assertCount(1, $result['records']);
        $this->assertSame((int) $included->id, $result['records'][0]['moodle_id']);
    }

    public function test_course_upsert_reuses_nested_legacy_categories_and_moves_existing_course(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('sendcoursewelcomemessage', 0, 'enrol_manual');

        $program = \core_course_category::create([
            'name' => 'Associate Degree in Nursing',
            'idnumber' => 'ADN',
            'parent' => 0,
        ]);
        $year = \core_course_category::create([
            'name' => 'YEAR 1',
            'idnumber' => 'ADN_Y001',
            'parent' => (int) $program->id,
        ]);
        $semester = \core_course_category::create([
            'name' => 'Semester 1',
            'idnumber' => 'ADNY1S1',
            'parent' => (int) $year->id,
        ]);
        $flat = \core_course_category::create([
            'name' => 'Duplicate flat category',
            'idnumber' => 'rtc-program-4',
            'parent' => 0,
        ]);
        $existing = $this->getDataGenerator()->create_course([
            'fullname' => 'Human Ethic',
            'shortname' => 'RTC-BB-SUBJ-255',
            'idnumber' => 'rtc-subject:255',
            'category' => (int) $flat->id,
        ]);
        $path = [
            ['idnumber' => 'ADN', 'name' => 'Associate Degree in Nursing'],
            ['idnumber' => 'ADN_Y001', 'name' => 'YEAR 1'],
            ['idnumber' => 'ADNY1S1', 'name' => 'Semester 1'],
        ];

        $saved = \local_rtcsync_external::upsert_course([
            'fullname' => 'N-15-HE - Human Ethic',
            'shortname' => 'RTC-BB-SUBJ-255',
            'idnumber' => 'rtc-subject:255',
            'category_idnumber' => 'ADNY1S1',
            'category_name' => 'Semester 1',
            'category_path' => $path,
            'visible' => 1,
        ]);

        $this->assertSame((int) $existing->id, $saved['id']);
        $this->assertSame((int) $semester->id, $saved['categoryid']);
        foreach (['ADN', 'ADN_Y001', 'ADNY1S1'] as $idnumber) {
            $this->assertSame(1, $DB->count_records('course_categories', ['idnumber' => $idnumber]));
        }

        $state = \local_rtcsync_external::get_managed_state(
            'courses',
            ['rtc-subject:255'],
            0,
            100
        );
        $this->assertSame('ADNY1S1', $state['records'][0]['category_idnumber']);
        $this->assertSame(
            ['ADN', 'ADN_Y001', 'ADNY1S1'],
            json_decode($state['records'][0]['category_path'], true, 512, JSON_THROW_ON_ERROR),
        );

        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $credit = \local_rtcsync_external::upsert_credit([
            'courseid' => $saved['id'],
            'subject_id' => 255,
            'idnumber' => 'rtc-credit-course:17',
            'shortname' => 'RTC-BB-CREDIT-17',
            'name' => 'Human Ethic - Test Teacher',
            'category_path' => $path,
            'teacher_role_shortname' => 'editingteacher',
            'student_role_shortname' => 'student',
            'teacher_userids' => [(int) $teacher->id],
            'student_userids' => [(int) $student->id],
        ]);
        $this->assertSame(
            (int) $semester->id,
            (int) $DB->get_field('course', 'category', ['id' => $credit['courseid']], MUST_EXIST),
        );
    }
    public function test_read_rejects_more_than_one_hundred_identifiers(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\invalid_parameter_exception::class);

        \local_rtcsync_external::get_managed_state(
            'users',
            array_map(
                static fn(int $id): string => "rtc-user:{$id}",
                range(1, 101)
            )
        );
    }

    public function test_read_requires_dedicated_capability(): void
    {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);

        \local_rtcsync_external::get_managed_state(
            'users',
            ['rtc-user:1']
        );
    }

    public function test_user_upsert_is_idempotent_and_supports_suspension_lifecycle(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $payload = [
            'moodleid' => 0,
            'username' => 'rtc.lifecycle@example.test',
            'email' => 'rtc.lifecycle@example.test',
            'firstname' => 'RTC',
            'lastname' => 'Lifecycle',
            'idnumber' => 'rtc-user:lifecycle',
            'phone1' => '012345678',
            'suspended' => 0,
            'profile_fields' => [],
        ];

        $created = \local_rtcsync_external::upsert_user($payload);
        $payload['moodleid'] = $created['id'];
        $payload['firstname'] = 'Updated';
        $payload['suspended'] = 1;
        $suspended = \local_rtcsync_external::upsert_user($payload);

        $this->assertSame($created['id'], $suspended['id']);
        $this->assertSame(1, $DB->count_records('user', [
            'idnumber' => 'rtc-user:lifecycle',
            'deleted' => 0,
        ]));

        $saved = $DB->get_record('user', ['id' => $created['id']], '*', MUST_EXIST);
        $this->assertSame('Updated', $saved->firstname);
        $this->assertSame(1, (int) $saved->suspended);

        $payload['suspended'] = 0;
        \local_rtcsync_external::upsert_user($payload);

        $this->assertSame(
            0,
            (int) $DB->get_field('user', 'suspended', ['id' => $created['id']])
        );
    }

    public function test_system_role_sync_manages_only_approved_component_assignments(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $user = $this->getDataGenerator()->create_user();
        $systemcontext = \context_system::instance();
        $manager = $DB->get_record('role', ['shortname' => 'manager'], '*', MUST_EXIST);
        $student = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);

        // This manual assignment must never be removed by RTC reconciliation.
        role_assign((int) $manager->id, (int) $user->id, $systemcontext->id);

        $result = \local_rtcsync_external::sync_system_roles([
            'userid' => (int) $user->id,
            'role_shortnames' => ['manager', 'student'],
        ]);

        $this->assertSame(['manager'], $result['role_shortnames']);
        $this->assertSame(1, $result['managed_count']);
        $this->assertTrue($DB->record_exists('role_assignments', [
            'roleid' => (int) $manager->id,
            'userid' => (int) $user->id,
            'contextid' => $systemcontext->id,
            'component' => 'local_rtcsync',
        ]));
        $this->assertFalse($DB->record_exists('role_assignments', [
            'roleid' => (int) $student->id,
            'userid' => (int) $user->id,
            'contextid' => $systemcontext->id,
            'component' => 'local_rtcsync',
        ]));

        \local_rtcsync_external::sync_system_roles([
            'userid' => (int) $user->id,
            'role_shortnames' => [],
        ]);

        $this->assertFalse($DB->record_exists('role_assignments', [
            'roleid' => (int) $manager->id,
            'userid' => (int) $user->id,
            'contextid' => $systemcontext->id,
            'component' => 'local_rtcsync',
        ]));
        $this->assertTrue($DB->record_exists('role_assignments', [
            'roleid' => (int) $manager->id,
            'userid' => (int) $user->id,
            'contextid' => $systemcontext->id,
            'component' => '',
        ]));
    }

    public function test_unenrolling_one_teacher_preserves_other_teacher_enrolments(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('sendcoursewelcomemessage', 0, 'enrol_manual');

        $course = $this->getDataGenerator()->create_course();
        $firstteacher = $this->getDataGenerator()->create_user();
        $secondteacher = $this->getDataGenerator()->create_user();

        foreach ([$firstteacher, $secondteacher] as $teacher) {
            \local_rtcsync_external::enrol_user([
                'courseid' => (int) $course->id,
                'userid' => (int) $teacher->id,
                'role_shortname' => 'editingteacher',
                'suspend' => 0,
            ]);
        }

        \local_rtcsync_external::unenrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $firstteacher->id,
            'role_shortname' => 'editingteacher',
        ]);

        $manual = $DB->get_record('enrol', [
            'courseid' => (int) $course->id,
            'enrol' => 'manual',
        ], '*', MUST_EXIST);

        $this->assertFalse($DB->record_exists('user_enrolments', [
            'enrolid' => (int) $manual->id,
            'userid' => (int) $firstteacher->id,
        ]));
        $this->assertTrue($DB->record_exists('user_enrolments', [
            'enrolid' => (int) $manual->id,
            'userid' => (int) $secondteacher->id,
        ]));
    }

    public function test_unenrolling_one_course_role_preserves_another_role_and_enrolment(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('sendcoursewelcomemessage', 0, 'enrol_manual');

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $context = \context_course::instance((int) $course->id);
        $student = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);

        \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'editingteacher',
            'suspend' => 0,
        ]);
        role_assign((int) $student->id, (int) $user->id, $context->id);

        \local_rtcsync_external::unenrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'editingteacher',
        ]);

        $manual = $DB->get_record('enrol', [
            'courseid' => (int) $course->id,
            'enrol' => 'manual',
        ], '*', MUST_EXIST);

        $this->assertTrue($DB->record_exists('user_enrolments', [
            'enrolid' => (int) $manual->id,
            'userid' => (int) $user->id,
        ]));
        $this->assertTrue($DB->record_exists('role_assignments', [
            'roleid' => (int) $student->id,
            'userid' => (int) $user->id,
            'contextid' => $context->id,
        ]));
    }

    public function test_managed_state_returns_system_roles_scope(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();

        $user = $this->getDataGenerator()->create_user([
            'idnumber' => 'rtc-user:sysrole',
        ]);

        \local_rtcsync_external::sync_system_roles([
            'userid' => (int) $user->id,
            'role_shortnames' => ['manager'],
        ]);

        $result = \local_rtcsync_external::get_managed_state(
            'systemroles',
            ['rtc-user:sysrole'],
            0,
            100
        );

        $this->assertSame('systemroles', $result['scope']);
        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['records']);
        $this->assertSame((int) $user->id, $result['records'][0]['moodle_id']);
        $this->assertSame('manager', $result['records'][0]['role_shortname']);
    }

    public function test_sso_elevation_check_prevents_broad_system_roles(): void
    {
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        require_once($CFG->dirroot . '/local/rtc_sso.php');

        $this->assertNull(rtc_sso_moodle_role_shortname(['teacher']));
        $this->assertNull(rtc_sso_moodle_role_shortname(['student']));
        $this->assertNull(rtc_sso_moodle_role_shortname(['staff', 'teacher']));
        $this->assertNull(rtc_sso_moodle_role_shortname(['director', 'head department']));
        $this->assertSame('manager', rtc_sso_moodle_role_shortname(['super admin']));
        $this->assertSame('manager', rtc_sso_moodle_role_shortname(['admin']));

        $user = $this->getDataGenerator()->create_user();
        $systemcontext = \context_system::instance();
        $manager = $DB->get_record('role', ['shortname' => 'manager'], '*', MUST_EXIST);

        role_assign((int) $manager->id, (int) $user->id, $systemcontext->id, 'local_rtc_sso');

        $this->assertTrue($DB->record_exists('role_assignments', [
            'roleid' => (int) $manager->id,
            'userid' => (int) $user->id,
            'contextid' => $systemcontext->id,
            'component' => 'local_rtc_sso',
        ]));

        rtc_sso_sync_role_access($user, ['teacher', 'staff']);

        $this->assertFalse($DB->record_exists('role_assignments', [
            'userid' => (int) $user->id,
            'contextid' => $systemcontext->id,
            'component' => 'local_rtc_sso',
        ]));
    }

    public function test_credit_courses_strictly_isolate_teacher_access_and_share_students(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('sendcoursewelcomemessage', 0, 'enrol_manual');

        $parent = $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-subject:strict',
            'shortname' => 'RTC-STRICT-PARENT',
        ]);
        $teacherone = $this->getDataGenerator()->create_user();
        $teachertwo = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();

        $base = [
            'courseid' => (int) $parent->id,
            'subject_id' => 44,
            'description' => 'Strictly isolated teaching credit.',
            'teacher_role_shortname' => 'editingteacher',
            'student_role_shortname' => 'student',
            'student_userids' => [(int) $student->id],
        ];
        $first = \local_rtcsync_external::upsert_credit($base + [
            'idnumber' => 'rtc-credit-course:101',
            'shortname' => 'RTC-CREDIT-101',
            'name' => 'Credit 1 - Teacher One',
            'teacher_userids' => [(int) $teacherone->id],
        ]);
        $second = \local_rtcsync_external::upsert_credit($base + [
            'idnumber' => 'rtc-credit-course:102',
            'shortname' => 'RTC-CREDIT-102',
            'name' => 'Credit 2 - Teacher Two',
            'teacher_userids' => [(int) $teachertwo->id],
        ]);

        $this->assertNotSame($first['courseid'], $second['courseid']);
        $teacherrole = $DB->get_record('role', ['shortname' => 'editingteacher'], '*', MUST_EXIST);
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        $firstcontext = \context_course::instance((int) $first['courseid']);
        $secondcontext = \context_course::instance((int) $second['courseid']);

        $this->assertTrue($DB->record_exists('role_assignments', [
            'contextid' => $firstcontext->id,
            'roleid' => (int) $teacherrole->id,
            'userid' => (int) $teacherone->id,
        ]));
        $this->assertFalse($DB->record_exists('role_assignments', [
            'contextid' => $secondcontext->id,
            'roleid' => (int) $teacherrole->id,
            'userid' => (int) $teacherone->id,
        ]));
        $this->assertTrue($DB->record_exists('role_assignments', [
            'contextid' => $secondcontext->id,
            'roleid' => (int) $teacherrole->id,
            'userid' => (int) $teachertwo->id,
        ]));
        $this->assertFalse($DB->record_exists('role_assignments', [
            'contextid' => $firstcontext->id,
            'roleid' => (int) $teacherrole->id,
            'userid' => (int) $teachertwo->id,
        ]));
        $this->assertTrue(has_capability('moodle/course:update', $firstcontext, $teacherone));
        $this->assertFalse(has_capability('moodle/course:update', $secondcontext, $teacherone));
        $this->assertTrue(has_capability('moodle/course:update', $secondcontext, $teachertwo));
        $this->assertFalse(has_capability('moodle/course:update', $firstcontext, $teachertwo));
        foreach ([$firstcontext, $secondcontext] as $context) {
            $this->assertTrue($DB->record_exists('role_assignments', [
                'contextid' => $context->id,
                'roleid' => (int) $studentrole->id,
                'userid' => (int) $student->id,
            ]));
        }

        $state = \local_rtcsync_external::get_managed_state(
            'credits',
            ['rtc-credit-course:101', 'rtc-credit-course:102'],
            0,
            100
        );
        $this->assertSame(2, $state['total']);
        $this->assertCount(2, $state['records']);
        $this->assertStringContainsString(
            $teacherone->id . ':editingteacher',
            $state['records'][0]['member_roles']
        );

        \local_rtcsync_external::delete_credit([
            'courseid' => (int) $parent->id,
            'idnumber' => 'rtc-credit-course:101',
            'teacher_role_shortname' => 'editingteacher',
            'student_role_shortname' => 'student',
        ]);
        $this->assertSame(0, (int) $DB->get_field('course', 'visible', [
            'id' => (int) $first['courseid'],
        ]));
        $this->assertFalse($DB->record_exists('role_assignments', [
            'contextid' => $firstcontext->id,
            'userid' => (int) $teacherone->id,
        ]));
        $this->assertFalse($DB->record_exists('role_assignments', [
            'contextid' => $firstcontext->id,
            'userid' => (int) $student->id,
        ]));
    }}
