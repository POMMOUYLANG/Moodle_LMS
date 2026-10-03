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

    /**
     * Build a Moodle user with the explicit RTC-Sync ownership marker.
     *
     * External-service tests should not accidentally exercise unmanaged
     * records now that the write boundary is enforced.
     *
     * @param array<string, mixed> $options
     */
    private function createManagedUser(array $options = []): \stdClass
    {
        global $DB;

        $user = $this->getDataGenerator()->create_user($options);
        if (!$DB->record_exists('local_rtcsync_user', ['userid' => (int) $user->id])) {
            $DB->insert_record('local_rtcsync_user', (object) [
                'userid' => (int) $user->id,
                'idnumber' => (string) ($user->idnumber ?? ''),
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        return $user;
    }

    /**
     * Build an RTC subject course for write-path tests.
     *
     * @param array<string, mixed> $options
     */
    private function createManagedCourse(array $options = []): \stdClass
    {
        $options['idnumber'] ??= 'rtc-subject:test-'.uniqid('', true);

        return $this->getDataGenerator()->create_course($options);
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

    public function test_managed_user_inventory_uses_marker_for_sms_id_card_idnumbers(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();

        $managed = \local_rtcsync_external::upsert_user([
            'moodleid' => 0,
            'username' => 'rtc-managed-card',
            'email' => 'rtc-managed-card@example.test',
            'firstname' => 'Managed',
            'lastname' => 'Card',
            'idnumber' => 'CARD-001',
            'phone1' => '',
            'suspended' => 0,
            'profile_fields' => [],
        ]);
        $this->getDataGenerator()->create_user([
            'idnumber' => 'CARD-999',
        ]);

        $result = \local_rtcsync_external::get_managed_state(
            'managedusers',
            [],
            0,
            100
        );

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['records']);
        $this->assertSame((int) $managed['id'], $result['records'][0]['moodle_id']);
        $this->assertSame('CARD-001', $result['records'][0]['idnumber']);
    }

    public function test_bilingual_labels_render_one_language_for_text_and_strings(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();
        filter_set_global_state('multilang', TEXTFILTER_ON);
        filter_set_applies_to_strings('multilang', true);

        global $SESSION;
        $bilingual = '<span class="multilang" lang="en">English label</span>'
            .'<span class="multilang" lang="km">ស្លាកខ្មែរ</span>';
        $legacy = '<span class="multilang" lang="en">English label</span>'
            .'<span class="multilang" lang="kh">ស្លាកខ្មែរ</span>';
        $options = ['context' => \context_system::instance()];

        $SESSION->forcelang = 'en';
        $this->assertSame('English label', format_text($bilingual, FORMAT_HTML, $options));
        $this->assertSame('English label', format_string($bilingual, true, $options));

        $SESSION->forcelang = 'km';
        $this->assertSame('ស្លាកខ្មែរ', format_text($bilingual, FORMAT_HTML, $options));
        $this->assertSame('ស្លាកខ្មែរ', format_string($bilingual, true, $options));
        $this->assertSame('ស្លាកខ្មែរ', format_text($legacy, FORMAT_HTML, $options));
    }

    public function test_user_profile_contract_persists_batch_fields_and_rejects_unknown_fields(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = \local_rtcsync_external::upsert_user([
            'moodleid' => 0,
            'username' => 'rtc-batch-contract',
            'email' => 'rtc-batch-contract@example.test',
            'firstname' => 'Batch',
            'lastname' => 'Contract',
            'idnumber' => 'rtc-user:batch-contract',
            'phone1' => '',
            'suspended' => 0,
            'profile_fields' => [
                ['shortname' => 'rtc_program_batch_id', 'value' => '41'],
                ['shortname' => 'rtc_batch_code', 'value' => 'BATCH-41'],
                ['shortname' => 'rtc_batch_number', 'value' => '3'],
            ],
        ]);

        $this->assertSame(3, $result['profile_fields_saved']);

        $this->expectException(\invalid_parameter_exception::class);
        \local_rtcsync_external::upsert_user([
            'moodleid' => (int) $result['id'],
            'username' => 'rtc-batch-contract',
            'email' => 'rtc-batch-contract@example.test',
            'firstname' => 'Batch',
            'lastname' => 'Contract',
            'idnumber' => 'rtc-user:batch-contract',
            'phone1' => '',
            'suspended' => 0,
            'profile_fields' => [
                ['shortname' => 'rtc_unknown_contract_field', 'value' => 'should fail'],
            ],
        ]);
    }

    public function test_category_roles_are_scoped_and_managed_without_removing_manual_roles(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        if (!$DB->record_exists('role', ['shortname' => 'manager'])) {
            create_role(
                'RTC category manager',
                'manager',
                'RTC category manager role for synchronization tests.',
                'manager'
            );
        }
        $user = $this->createManagedUser([
            'idnumber' => 'rtc-user:hod-category',
        ]);

        $result = \local_rtcsync_external::sync_category_roles([
            'userid' => (int) $user->id,
            'category_roles' => [[
                'category_idnumber' => 'DEPT-TEST',
                'category_name' => 'Department Test',
                'role_shortname' => 'manager',
            ]],
        ]);

        $this->assertSame(1, $result['category_count']);
        $category = $DB->get_record('course_categories', ['idnumber' => 'DEPT-TEST'], '*', MUST_EXIST);
        $manager = $DB->get_record('role', ['shortname' => 'manager'], '*', MUST_EXIST);
        $context = \context_coursecat::instance((int) $category->id);
        $this->assertTrue($DB->record_exists('role_assignments', [
            'userid' => (int) $user->id,
            'roleid' => (int) $manager->id,
            'contextid' => $context->id,
            'component' => 'local_rtcsync',
            'itemid' => 0,
        ]));

        role_assign((int) $manager->id, (int) $user->id, $context->id);
        \local_rtcsync_external::sync_category_roles([
            'userid' => (int) $user->id,
            'category_roles' => [],
        ]);

        $this->assertFalse($DB->record_exists('role_assignments', [
            'userid' => (int) $user->id,
            'roleid' => (int) $manager->id,
            'contextid' => $context->id,
            'component' => 'local_rtcsync',
            'itemid' => 0,
        ]));
        $this->assertTrue($DB->record_exists('role_assignments', [
            'userid' => (int) $user->id,
            'roleid' => (int) $manager->id,
            'contextid' => $context->id,
            'component' => '',
            'itemid' => 0,
        ]));
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

    public function test_managed_course_inventory_lists_subject_and_credit_courses_only(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();

        $subject = $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-subject:11',
        ]);
        $credit = $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-credit-course:22',
        ]);
        $this->getDataGenerator()->create_course([
            'idnumber' => 'manual-course:33',
        ]);

        $result = \local_rtcsync_external::get_managed_state(
            'managedcourses',
            [],
            0,
            100
        );

        $this->assertSame(2, $result['total']);
        $this->assertSame(
            [(int) $subject->id, (int) $credit->id],
            array_map(
                static fn (array $record): int => $record['moodle_id'],
                $result['records']
            )
        );
    }

    public function test_managed_class_inventory_lists_class_and_delivery_cohorts_only(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->createManagedCourse(['idnumber' => 'rtc-subject:inventory']);
        $student = $this->createManagedUser(['idnumber' => 'rtc-user:inventory']);

        \local_rtcsync_external::upsert_class([
            'idnumber' => 'rtc-class:inventory',
            'name' => '[Class] Inventory',
            'visible' => 1,
            'userids' => [$student->id],
            'courseids' => [$course->id],
            'grouping_idnumber' => 'rtc-class-grouping:inventory',
            'grouping_name' => '[Class] Inventory',
            'groups' => [],
        ]);
        \local_rtcsync_external::upsert_class([
            'idnumber' => 'rtc-delivery:inventory',
            'name' => '[Delivery] Inventory',
            'visible' => 1,
            'userids' => [$student->id],
            'courseids' => [$course->id],
            'grouping_idnumber' => 'rtc-delivery-grouping:inventory',
            'grouping_name' => '[Delivery] Inventory',
            'groups' => [],
        ]);

        $result = \local_rtcsync_external::get_managed_state(
            'managedclasses',
            [],
            0,
            100
        );

        $this->assertSame(2, $result['total']);
        $this->assertSame(
            ['rtc-class:inventory', 'rtc-delivery:inventory'],
            array_map(
                static fn (array $record): string => $record['idnumber'],
                $result['records']
            )
        );
    }

    public function test_activity_grade_read_returns_only_course_items_enabled_for_sms_formative(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['idnumber' => 'rtc-subject:11']);
        $student = $this->getDataGenerator()->create_user(['idnumber' => 'rtc-user:22']);
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'name' => 'Quiz 1',
        ]);
        $quizitem = \grade_item::fetch([
            'courseid' => $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $quiz->id,
            'itemnumber' => 0,
        ]);
        $this->assertNotFalse($quizitem);
        $item = $quizitem;
        $item->itemname = 'Quiz 1';
        $item->grademax = 10;
        $item->update();
        $item->update_final_grade($student->id, 8, 'test');

        $configid = $DB->insert_record('local_rtcsync_formcfg', (object) [
            'courseid' => $course->id,
            'enabled' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('local_rtcsync_formitem', (object) [
            'courseid' => $course->id,
            'itemid' => $item->id,
            'included' => 1,
            'label' => 'Knowledge check',
            'weight' => 100,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $result = \local_rtcsync_external::get_managed_state(
            'activitygrades',
            ['rtc-subject:11'],
            0,
            100
        );

        $this->assertSame(1, $result['total']);
        $this->assertSame('Knowledge check', $result['records'][0]['sync_label']);
        $this->assertSame(100.0, $result['records'][0]['sync_weight']);
        $this->assertSame(1, $result['records'][0]['sync_selected_count']);

        $DB->set_field('local_rtcsync_formcfg', 'enabled', 0, ['id' => $configid]);
        $disabled = \local_rtcsync_external::get_managed_state(
            'activitygrades',
            ['rtc-subject:11'],
            0,
            100
        );
        $this->assertSame(0, $disabled['total']);
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

        $teacher = $this->createManagedUser();
        $student = $this->createManagedUser();
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

        $user = $this->createManagedUser();
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

        $DB->set_field('user', 'confirmed', 0, ['id' => $created['id']]);
        $payload['suspended'] = 0;
        $activated = \local_rtcsync_external::upsert_user($payload);

        $this->assertSame(1, $activated['confirmed']);
        $this->assertSame(0, $activated['suspended']);
        $this->assertSame(
            0,
            (int) $DB->get_field('user', 'suspended', ['id' => $created['id']])
        );
        $this->assertSame(
            1,
            (int) $DB->get_field('user', 'confirmed', ['id' => $created['id']])
        );
    }

    public function test_user_upsert_refuses_to_mutate_an_unmanaged_existing_account(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();

        $existing = $this->getDataGenerator()->create_user([
            'username' => 'manual-owned-boundary',
            'email' => 'manual-owned-boundary@example.test',
        ]);

        $this->expectException(\invalid_parameter_exception::class);

        \local_rtcsync_external::upsert_user([
            'moodleid' => (int) $existing->id,
            'username' => $existing->username,
            'email' => $existing->email,
            'firstname' => 'Must Not Change',
            'lastname' => $existing->lastname,
            'idnumber' => 'CARD-MANUAL',
            'phone1' => '',
            'suspended' => 1,
            'profile_fields' => [],
        ]);
    }

    public function test_course_upsert_requires_a_reserved_subject_identifier(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\invalid_parameter_exception::class);

        \local_rtcsync_external::upsert_course([
            'fullname' => 'Manual course must remain untouched',
            'shortname' => 'MANUAL-COURSE',
            'idnumber' => 'manual-course:1',
            'summary' => '',
            'category_idnumber' => 'rtc-academic',
            'category_name' => 'RTC Academic Courses',
            'category_path' => [],
            'visible' => 1,
        ]);
    }

    public function test_enrolment_refuses_unmanaged_course_even_for_a_managed_user(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('sendcoursewelcomemessage', 0, 'enrol_manual');

        $course = $this->getDataGenerator()->create_course([
            'idnumber' => 'manual-course:2',
        ]);
        $user = $this->createManagedUser();

        $this->expectException(\invalid_parameter_exception::class);

        \local_rtcsync_external::enrol_user([
            'courseid' => (int) $course->id,
            'userid' => (int) $user->id,
            'role_shortname' => 'student',
            'suspend' => 0,
        ]);
    }

    public function test_system_role_sync_manages_only_approved_component_assignments(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $user = $this->createManagedUser();
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

        $course = $this->createManagedCourse();
        $firstteacher = $this->createManagedUser();
        $secondteacher = $this->createManagedUser();

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

        $course = $this->createManagedCourse();
        $user = $this->createManagedUser();
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

        $user = $this->createManagedUser([
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
        $teacherone = $this->createManagedUser();
        $teachertwo = $this->createManagedUser();
        $student = $this->createManagedUser();

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

        // A Moodle operator may add an unmanaged permission to an RTC-owned
        // credit course. SMS must remove only the role assignment it created.
        role_assign((int) $teacherrole->id, (int) $teacherone->id, $firstcontext->id);

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
            'component' => 'local_rtcsync',
            'itemid' => (int) $first['courseid'],
        ]));
        $this->assertTrue($DB->record_exists('role_assignments', [
            'contextid' => $firstcontext->id,
            'userid' => (int) $teacherone->id,
            'component' => '',
            'itemid' => 0,
        ]));
        $this->assertFalse($DB->record_exists('role_assignments', [
            'contextid' => $firstcontext->id,
            'userid' => (int) $student->id,
        ]));
    }

    public function test_class_cohort_reconciles_child_groups_inside_course_grouping(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $courseone = $this->createManagedCourse();
        $coursetwo = $this->createManagedCourse();
        $studentone = $this->createManagedUser();
        $studenttwo = $this->createManagedUser();
        $studentthree = $this->createManagedUser();

        $result = \local_rtcsync_external::upsert_class([
            'idnumber' => 'rtc-class:100',
            'name' => '[Class] ADN Year 1 A',
            'description' => 'Managed class.',
            'visible' => 1,
            'userids' => [$studentone->id, $studenttwo->id, $studentthree->id],
            'courseids' => [$courseone->id, $coursetwo->id],
            'grouping_idnumber' => 'rtc-class-grouping:100',
            'grouping_name' => '[Class] ADN Year 1 A',
            'groups' => [
                [
                    'idnumber' => 'rtc-class-group:101',
                    'name' => '[Group] Group 1',
                    'userids' => [$studentone->id, $studenttwo->id],
                ],
                [
                    'idnumber' => 'rtc-class-group:102',
                    'name' => '[Group] Group 2',
                    'userids' => [$studentthree->id],
                ],
            ],
        ]);

        $this->assertSame(3, $result['member_count']);
        $this->assertSame(2, $result['course_count']);
        $this->assertSame(4, $result['group_count']);
        foreach ([$courseone, $coursetwo] as $course) {
            $grouping = $DB->get_record('groupings', [
                'courseid' => $course->id,
                'idnumber' => 'rtc-class-grouping:100',
            ], '*', MUST_EXIST);
            $groups = $DB->get_records('groups', ['courseid' => $course->id], 'id');
            $this->assertCount(2, $groups);
            $this->assertSame(2, $DB->count_records('groupings_groups', [
                'groupingid' => $grouping->id,
            ]));
            $first = $DB->get_record('groups', [
                'courseid' => $course->id,
                'idnumber' => 'rtc-class-group:101',
            ], '*', MUST_EXIST);
            $this->assertSame(2, $DB->count_records('groups_members', ['groupid' => $first->id]));
        }
        $state = \local_rtcsync_external::get_managed_state(
            'classes',
            ['rtc-class:100'],
            0,
            100
        );
        $this->assertSame(1, $state['total']);
        $structure = json_decode($state['records'][0]['course_structure'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(2, $structure);
        $this->assertSame('rtc-class-grouping:100', $structure[0]['grouping_idnumber']);
        $this->assertCount(2, $structure[0]['groups']);

        \local_rtcsync_external::upsert_class([
            'idnumber' => 'rtc-class:100',
            'name' => '[Class] ADN Year 1 A',
            'visible' => 1,
            'userids' => [$studentone->id],
            'courseids' => [$courseone->id],
            'grouping_idnumber' => 'rtc-class-grouping:100',
            'grouping_name' => '[Class] ADN Year 1 A',
            'groups' => [[
                'idnumber' => 'rtc-class-group:101',
                'name' => '[Group] Group 1',
                'userids' => [$studentone->id],
            ]],
        ]);

        $this->assertFalse($DB->record_exists('groupings', [
            'courseid' => $coursetwo->id,
            'idnumber' => 'rtc-class-grouping:100',
        ]));
        $this->assertFalse($DB->record_exists('groups', [
            'courseid' => $courseone->id,
            'idnumber' => 'rtc-class-group:102',
        ]));
        $state = \local_rtcsync_external::get_managed_state(
            'classes',
            ['rtc-class:100'],
            0,
            100
        );
        $structure = json_decode($state['records'][0]['course_structure'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $structure);
        $this->assertSame((int) $courseone->id, $structure[0]['courseid']);
        $this->assertCount(1, $structure[0]['groups']);

        \local_rtcsync_external::upsert_class([
            'idnumber' => 'rtc-class:100',
            'name' => '[Class] ADN Year 1 A',
            'visible' => 0,
            'userids' => [],
            'courseids' => [$courseone->id, $coursetwo->id],
            'grouping_idnumber' => 'rtc-class-grouping:100',
            'grouping_name' => '[Class] ADN Year 1 A',
            'groups' => [],
        ]);

        $cohort = $DB->get_record('cohort', ['idnumber' => 'rtc-class:100'], '*', MUST_EXIST);
        $this->assertSame(0, (int) $cohort->visible);
        $this->assertSame(0, $DB->count_records('cohort_members', ['cohortid' => $cohort->id]));
        $this->assertFalse($DB->record_exists('groupings', ['idnumber' => 'rtc-class-grouping:100']));
        $this->assertSame(0, $DB->count_records_select(
            'role_assignments',
            'component = :component AND itemid = :itemid',
            ['component' => 'local_rtcsync', 'itemid' => $cohort->id]
        ));
    }

    public function test_class_payload_rejects_values_that_exceed_moodle_limits(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\invalid_parameter_exception::class);
        $this->expectExceptionMessage('Class idnumber must be at most 100 characters.');

        \local_rtcsync_external::upsert_class([
            'idnumber' => 'rtc-class:'.str_repeat('x', 100),
            'name' => '[Class] Valid name',
            'grouping_idnumber' => 'rtc-class-grouping:limit-check',
            'grouping_name' => '[Class] Valid grouping',
            'groups' => [],
        ]);
    }

    public function test_class_payload_accepts_delivery_identity_metadata(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse();

        $result = \local_rtcsync_external::upsert_class([
            'idnumber' => 'rtc-delivery:metadata:class:1',
            'name' => '[Class] Delivery Metadata',
            'visible' => 1,
            'delivery_id' => 17,
            'userids' => [],
            'courseids' => [(int) $course->id],
            'grouping_idnumber' => 'rtc-delivery-grouping:metadata:class:1',
            'grouping_name' => '[Class] Delivery Metadata',
            'groups' => [],
        ]);

        $this->assertSame('rtc-delivery:metadata:class:1', $result['idnumber']);
        $this->assertSame(0, $result['member_count']);
    }

    public function test_class_payload_accepts_managed_isolated_credit_courses(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse(['idnumber' => 'rtc-credit-course:class-credit']);

        $result = \local_rtcsync_external::upsert_class([
            'idnumber' => 'rtc-class:credit-course',
            'name' => '[Class] Credit Course',
            'visible' => 1,
            'userids' => [],
            'courseids' => [(int) $course->id],
            'grouping_idnumber' => 'rtc-class-grouping:credit-course',
            'grouping_name' => '[Class] Credit Course',
            'groups' => [[
                'idnumber' => 'rtc-class-group:credit-course',
                'name' => '[Group] Credit Course',
                'userids' => [],
            ]],
        ]);

        $this->assertSame(1, $result['course_count']);
    }

    public function test_class_teacher_role_change_removes_stale_managed_role_assignment(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse();
        $teacher = $this->createManagedUser();
        $payload = [
            'idnumber' => 'rtc-class:role-change',
            'name' => '[Class] Role Change',
            'description' => 'Managed class role transition.',
            'visible' => 1,
            'userids' => [],
            'courseids' => [(int) $course->id],
            'grouping_idnumber' => 'rtc-class-grouping:role-change',
            'grouping_name' => '[Class] Role Change',
            'groups' => [],
            'teacher_userids' => [(int) $teacher->id],
            'student_role_shortname' => 'student',
        ];

        \local_rtcsync_external::upsert_class($payload + [
            'teacher_role_shortname' => 'editingteacher',
        ]);

        $cohort = $DB->get_record('cohort', [
            'idnumber' => 'rtc-class:role-change',
        ], '*', MUST_EXIST);
        $context = \context_course::instance((int) $course->id);
        $editingteacher = $DB->get_record('role', ['shortname' => 'editingteacher'], '*', MUST_EXIST);
        $teacherrole = $DB->get_record('role', ['shortname' => 'teacher'], '*', MUST_EXIST);
        $this->assertTrue($DB->record_exists('role_assignments', [
            'roleid' => (int) $editingteacher->id,
            'userid' => (int) $teacher->id,
            'contextid' => $context->id,
            'component' => 'local_rtcsync',
            'itemid' => $cohort->id,
        ]));

        \local_rtcsync_external::upsert_class($payload + [
            'teacher_role_shortname' => 'teacher',
        ]);

        $this->assertFalse($DB->record_exists('role_assignments', [
            'roleid' => (int) $editingteacher->id,
            'userid' => (int) $teacher->id,
            'contextid' => $context->id,
            'component' => 'local_rtcsync',
            'itemid' => $cohort->id,
        ]));
        $this->assertTrue($DB->record_exists('role_assignments', [
            'roleid' => (int) $teacherrole->id,
            'userid' => (int) $teacher->id,
            'contextid' => $context->id,
            'component' => 'local_rtcsync',
            'itemid' => $cohort->id,
        ]));
    }

    public function test_class_managed_state_exposes_course_role_assignments(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->createManagedCourse();
        $teacher = $this->createManagedUser();
        $student = $this->createManagedUser();

        \local_rtcsync_external::upsert_class([
            'idnumber' => 'rtc-class:managed-state-roles',
            'name' => '[Class] Managed State Roles',
            'description' => 'Managed class role read-back.',
            'visible' => 1,
            'userids' => [(int) $student->id],
            'courseids' => [(int) $course->id],
            'grouping_idnumber' => 'rtc-class-grouping:managed-state-roles',
            'grouping_name' => '[Class] Managed State Roles',
            'groups' => [],
            'teacher_userids' => [(int) $teacher->id],
            'teacher_role_shortname' => 'editingteacher',
            'student_role_shortname' => 'student',
        ]);

        $state = \local_rtcsync_external::get_managed_state(
            'classes',
            ['rtc-class:managed-state-roles'],
            0,
            100
        );
        $roles = json_decode(
            $state['records'][0]['class_role_assignments'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        sort($roles, SORT_STRING);
        $expected = [
            $course->id . ':' . $student->id . ':student',
            $course->id . ':' . $teacher->id . ':editingteacher',
        ];
        sort($expected, SORT_STRING);

        $this->assertSame($expected, $roles);
    }
}
