<?php

namespace local_rtcsync;

use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the read-only student released-history page and activity boundary.
 */
#[CoversClass(\local_rtcsync\local\student_history::class)]
final class student_history_test extends \advanced_testcase
{
    /**
     * Create an RTC-managed Moodle student with a suspended manual course enrolment.
     *
     * @return array{user: \stdClass, course: \stdClass}
     */
    private function createHistoryStudent(string $suffix = 'one', string $courseidnumber = ''): array
    {
        global $DB;

        $email = 'history-' . $suffix . '@example.test';
        $idnumber = 'HISTORY-CARD-' . strtoupper($suffix);
        $user = $this->getDataGenerator()->create_user([
            'username' => $email,
            'email' => $email,
            'idnumber' => $idnumber,
        ]);
        $DB->insert_record('local_rtcsync_user', (object) [
            'userid' => (int) $user->id,
            'idnumber' => $idnumber,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $course = $this->getDataGenerator()->create_course([
            'idnumber' => $courseidnumber !== '' ? $courseidnumber : 'rtc-subject:history-' . $suffix,
            'showgrades' => 1,
        ]);
        $this->enrolHistoryStudent($user, $course);

        return ['user' => $user, 'course' => $course];
    }

    /** Enrol an RTC student in a history course while keeping access suspended. */
    private function enrolHistoryStudent(\stdClass $user, \stdClass $course): void
    {
        global $DB;

        $plugin = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', [
            'courseid' => (int) $course->id,
            'enrol' => 'manual',
        ], '*', IGNORE_MISSING);
        if (!$instance) {
            $plugin->add_default_instance($course);
            $instance = $DB->get_record('enrol', [
                'courseid' => (int) $course->id,
                'enrol' => 'manual',
            ], '*', MUST_EXIST);
        }
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        $plugin->enrol_user(
            $instance,
            (int) $user->id,
            (int) $studentrole->id,
            0,
            0,
            ENROL_USER_SUSPENDED
        );
        \local_rtcsync\local\student_history::sync_marker(
            (int) $user->id,
            (int) $course->id,
            1,
            'user_program',
            1000 + (int) $user->id,
            0
        );
    }

    /**
     * Create a quiz grade item, optionally assigning a released or hidden grade.
     */
    private function createQuizGrade(\stdClass $course, \stdClass $user, string $name, ?float $value, bool $hidden = false): \grade_item
    {
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => (int) $course->id,
            'name' => $name,
            'grade' => 100,
        ]);
        $item = \grade_item::fetch([
            'courseid' => (int) $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => (int) $quiz->id,
            'itemnumber' => 0,
        ]);
        $this->assertNotFalse($item);
        $item->itemname = $name;
        $item->grademax = 100;
        $item->update();
        if ($value !== null) {
            $item->update_final_grade((int) $user->id, $value, 'student-history-test');
        }
        if ($hidden) {
            $grade = \grade_grade::fetch([
                'itemid' => (int) $item->id,
                'userid' => (int) $user->id,
            ]);
            $this->assertNotFalse($grade);
            $grade->set_hidden(1);
            $grade->update();
        }

        return $item;
    }

    /** Build Moodle availability JSON containing the requested grouping conditions. */
    private function groupingAvailability(array $groupingids): string
    {
        $conditions = array_map(
            static fn(int $groupingid): \stdClass => \availability_grouping\condition::get_json($groupingid),
            $groupingids
        );
        return json_encode(\core_availability\tree::get_root_json($conditions));
    }

    /** @return array<string, mixed> */
    private function rtcStudentIdentity(\stdClass $user, bool $active = true): array
    {
        return [
            'id' => 42,
            'email' => (string) $user->email,
            'account_is_active' => $active,
            'user_detail' => ['id_card' => (string) $user->idnumber],
            'roles' => ['Student'],
        ];
    }

    public function test_results_are_scoped_to_the_current_moodle_user(): void
    {
        global $DB;

        $this->resetAfterTest();
        $first = $this->createHistoryStudent('scope-first');
        $seconduser = $this->getDataGenerator()->create_user([
            'username' => 'history-scope-second@example.test',
            'email' => 'history-scope-second@example.test',
            'idnumber' => 'HISTORY-CARD-SCOPE-SECOND',
        ]);
        $DB->insert_record('local_rtcsync_user', (object) [
            'userid' => (int) $seconduser->id,
            'idnumber' => (string) $seconduser->idnumber,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $this->enrolHistoryStudent($seconduser, $first['course']);
        $item = $this->createQuizGrade($first['course'], $first['user'], 'Shared item', 41.0);
        $item->update_final_grade((int) $seconduser->id, 99.0, 'student-history-test');

        $this->setUser($first['user']);
        $history = new \local_rtcsync\local\student_history();
        $result = $history->visible_results();

        $this->assertCount(1, $result['results']);
        $this->assertSame(41.0, $result['results'][0]['grade']);
        $this->assertSame('Shared item', $result['results'][0]['item']);
    }

    public function test_hidden_and_null_grade_rows_are_not_returned(): void
    {
        global $DB;

        $this->resetAfterTest();
        $student = $this->createHistoryStudent('visibility');
        $this->createQuizGrade($student['course'], $student['user'], 'Visible result', 72.0);
        $this->createQuizGrade($student['course'], $student['user'], 'Hidden result', 91.0, true);
        $this->createQuizGrade($student['course'], $student['user'], 'Unreleased result', null);
        $hiddenfromgrades = $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-subject:history-grade-display-off',
        ]);
        $DB->set_field('course', 'showgrades', 0, ['id' => (int) $hiddenfromgrades->id]);
        $this->enrolHistoryStudent($student['user'], $hiddenfromgrades);
        $this->createQuizGrade($hiddenfromgrades, $student['user'], 'Course grade display off', 88.0);

        $this->setUser($student['user']);
        $history = new \local_rtcsync\local\student_history();
        $result = $history->visible_results();
        $names = array_column($result['results'], 'item');

        $this->assertContains('Visible result', $names);
        $this->assertNotContains('Hidden result', $names);
        $this->assertNotContains('Unreleased result', $names);
        $this->assertNotContains('Course grade display off', $names);
    }

    public function test_hidden_category_descendants_and_raw_aggregates_are_not_exposed(): void
    {
        $this->resetAfterTest();
        $student = $this->createHistoryStudent('hidden-category');
        $this->createQuizGrade($student['course'], $student['user'], 'Visible activity result', 73.0);
        $hiddenCategoryRecord = $this->getDataGenerator()->create_grade_category([
            'courseid' => (int) $student['course']->id,
            'fullname' => 'Hidden category',
        ]);
        $hiddenCategory = \grade_category::fetch(['id' => (int) $hiddenCategoryRecord->id]);
        $this->assertNotFalse($hiddenCategory);
        $hiddenCategory->set_hidden(1);
        $hiddenCategory->update();

        $hiddenItem = $this->createQuizGrade($student['course'], $student['user'], 'Hidden category result', 91.0);
        $hiddenItem->set_parent((int) $hiddenCategory->id);

        $categoryItem = \grade_item::fetch([
            'courseid' => (int) $student['course']->id,
            'itemtype' => 'category',
            'iteminstance' => (int) $hiddenCategory->id,
        ]);
        $this->assertNotFalse($categoryItem);
        $categoryItem->itemname = 'Hidden category total';
        $categoryItem->update();
        $categoryItem->update_final_grade((int) $student['user']->id, 91.0, 'student-history-test');

        $courseItem = \grade_item::fetch_course_item((int) $student['course']->id);
        $courseItem->itemname = 'Course total';
        $courseItem->update();
        $courseItem->update_final_grade((int) $student['user']->id, 91.0, 'student-history-test');

        $this->setUser($student['user']);
        $history = new \local_rtcsync\local\student_history();
        $page = $history->visible_results();

        $this->assertSame(['Visible activity result'], array_column($page['results'], 'item'));
        $this->assertSame(73.0, $page['results'][0]['grade']);
    }

    public function test_ineligible_grade_rows_do_not_consume_history_page_slots(): void
    {
        $this->resetAfterTest();
        $student = $this->createHistoryStudent('pagination-hidden');
        $hiddenCategoryRecord = $this->getDataGenerator()->create_grade_category([
            'courseid' => (int) $student['course']->id,
            'fullname' => 'Hidden pagination category',
        ]);
        $hiddenCategory = \grade_category::fetch(['id' => (int) $hiddenCategoryRecord->id]);
        $this->assertNotFalse($hiddenCategory);
        $hiddenCategory->set_hidden(1);
        $hiddenCategory->update();

        for ($index = 0; $index < \local_rtcsync\local\student_history::PAGE_SIZE + 1; $index++) {
            $visibility = $index % 4;
            $itemRecord = $this->getDataGenerator()->create_grade_item([
                'courseid' => (int) $student['course']->id,
                'itemname' => 'Ineligible result ' . $index,
                'itemtype' => 'manual',
                'gradetype' => $visibility === 3 ? GRADE_TYPE_TEXT : GRADE_TYPE_VALUE,
            ]);
            $item = \grade_item::fetch(['id' => (int) $itemRecord->id]);
            $this->assertNotFalse($item);

            if ($visibility === 1) {
                $item->set_hidden(time() + 86400);
                $item->update();
            } else if ($visibility === 2) {
                $item->set_parent((int) $hiddenCategory->id);
            }

            $grade = $this->getDataGenerator()->create_grade_grade([
                'itemid' => (int) $item->id,
                'userid' => (int) $student['user']->id,
                'finalgrade' => 50.0,
            ]);
            if ($visibility === 0) {
                $grade->set_hidden(1);
                $grade->update();
            }
        }

        $visibleRecord = $this->getDataGenerator()->create_grade_item([
            'courseid' => (int) $student['course']->id,
            'itemname' => 'Visible after hidden rows',
            'itemtype' => 'manual',
        ]);
        $visible = \grade_item::fetch(['id' => (int) $visibleRecord->id]);
        $this->assertNotFalse($visible);
        $this->getDataGenerator()->create_grade_grade([
            'itemid' => (int) $visible->id,
            'userid' => (int) $student['user']->id,
            'finalgrade' => 82.0,
        ]);
        $this->setUser($student['user']);

        $history = new \local_rtcsync\local\student_history();
        $page = $history->visible_results();

        $this->assertSame(['Visible after hidden rows'], array_column($page['results'], 'item'));
        $this->assertFalse($page['hasnext']);
    }

    public function test_history_cursor_pages_through_all_results_and_returns_to_the_previous_page(): void
    {
        $this->resetAfterTest();
        $student = $this->createHistoryStudent('cursor-pagination');

        for ($index = 1; $index <= \local_rtcsync\local\student_history::PAGE_SIZE + 1; $index++) {
            $itemrecord = $this->getDataGenerator()->create_grade_item([
                'courseid' => (int) $student['course']->id,
                'itemname' => sprintf('Released result %03d', $index),
                'itemtype' => 'manual',
                'gradetype' => GRADE_TYPE_VALUE,
                'grademax' => 100,
                'grademin' => 0,
            ]);
            $item = \grade_item::fetch(['id' => (int) $itemrecord->id]);
            $this->assertNotFalse($item);
            $this->getDataGenerator()->create_grade_grade([
                'itemid' => (int) $item->id,
                'userid' => (int) $student['user']->id,
                'finalgrade' => (float) $index,
            ]);
        }

        $this->setUser($student['user']);
        $history = new \local_rtcsync\local\student_history();
        $firstpage = $history->visible_results();
        $this->assertNotNull($firstpage['nextcursor']);
        $secondpage = $history->visible_results((string) $firstpage['nextcursor']);
        $this->assertNotNull($secondpage['previouscursor']);
        $previouspage = $history->visible_results((string) $secondpage['previouscursor']);

        $this->assertCount(\local_rtcsync\local\student_history::PAGE_SIZE, $firstpage['results']);
        $this->assertTrue($firstpage['hasnext']);
        $this->assertFalse($firstpage['hasprevious']);
        $this->assertCount(1, $secondpage['results']);
        $this->assertNotContains(
            $secondpage['results'][0]['item'],
            array_column($firstpage['results'], 'item')
        );
        $this->assertFalse($secondpage['hasnext']);
        $this->assertTrue($secondpage['hasprevious']);
        $this->assertSame(
            array_column($firstpage['results'], 'item'),
            array_column($previouspage['results'], 'item')
        );
    }

    public function test_student_identity_requires_a_history_marker_and_active_sms_account(): void
    {
        $this->resetAfterTest();
        $student = $this->createHistoryStudent('identity');
        $history = new \local_rtcsync\local\student_history();
        $this->setUser($student['user']);

        $this->expectException(\moodle_exception::class);
        $history->require_current_rtc_student($this->rtcStudentIdentity($student['user'], false));
    }

    public function test_student_without_a_history_marker_is_denied(): void
    {
        global $DB;

        $this->resetAfterTest();
        $student = $this->createHistoryStudent('nomarker');
        $DB->delete_records('local_rtcsync_history', ['userid' => (int) $student['user']->id]);
        $history = new \local_rtcsync\local\student_history();
        $this->setUser($student['user']);

        $this->expectException(\moodle_exception::class);
        $history->require_current_rtc_student($this->rtcStudentIdentity($student['user'], false));
    }

    public function test_mismatched_sms_identity_is_denied_even_when_history_exists(): void
    {
        $this->resetAfterTest();
        $student = $this->createHistoryStudent('mismatched');
        $identity = $this->rtcStudentIdentity($student['user']);
        $identity['email'] = 'different@example.test';
        $history = new \local_rtcsync\local\student_history();
        $this->setUser($student['user']);

        $this->expectException(\moodle_exception::class);
        $history->require_current_rtc_student($identity);
    }

    public function test_hidden_course_keeps_read_only_history_without_restoring_activity_access(): void
    {
        global $DB;

        $this->resetAfterTest();
        $student = $this->createHistoryStudent('hidden-course');
        $this->createQuizGrade($student['course'], $student['user'], 'Released while course is hidden', 76.0);
        $DB->set_field('course', 'visible', 0, ['id' => (int) $student['course']->id]);
        $history = new \local_rtcsync\local\student_history();
        $this->setUser($student['user']);

        $history->require_current_rtc_student($this->rtcStudentIdentity($student['user']));
        $result = $history->visible_results();

        $this->assertSame(['Released while course is hidden'], array_column($result['results'], 'item'));
        $this->assertSame(76.0, $result['results'][0]['grade']);
        $this->assertSame(0, (int) $DB->get_field('course', 'visible', ['id' => (int) $student['course']->id]));
        $this->assertFalse(is_enrolled(\context_course::instance((int) $student['course']->id), $student['user'], '', true));
    }

    public function test_hidden_course_still_requires_an_active_sms_student_identity(): void
    {
        global $DB;

        $this->resetAfterTest();
        $student = $this->createHistoryStudent('hidden-course-inactive-sms');
        $this->createQuizGrade($student['course'], $student['user'], 'Released result', 76.0);
        $DB->set_field('course', 'visible', 0, ['id' => (int) $student['course']->id]);
        $history = new \local_rtcsync\local\student_history();
        $this->setUser($student['user']);

        $this->expectException(\moodle_exception::class);
        $history->require_current_rtc_student($this->rtcStudentIdentity($student['user'], false));
    }

    public function test_visible_history_never_reactivates_suspended_course_enrolment(): void
    {
        global $DB;

        $this->resetAfterTest();
        $student = $this->createHistoryStudent('suspended');
        $this->createQuizGrade($student['course'], $student['user'], 'Released result', 80.0);
        $this->setUser($student['user']);

        $history = new \local_rtcsync\local\student_history();
        $result = $history->visible_results();
        $enrolment = $DB->get_record('user_enrolments', [
            'userid' => (int) $student['user']->id,
            'status' => ENROL_USER_SUSPENDED,
        ], '*', MUST_EXIST);

        $this->assertNotEmpty($result['results']);
        $this->assertSame(ENROL_USER_SUSPENDED, (int) $enrolment->status);
        $this->assertFalse(is_enrolled(\context_course::instance((int) $student['course']->id), $student['user'], '', true));
    }

    public function test_active_delivery_history_is_scoped_to_mapped_items_for_that_delivery(): void
    {
        global $DB;

        $this->resetAfterTest();
        $student = $this->createHistoryStudent('delivery-scoped');
        $DB->delete_records('local_rtcsync_history', [
            'userid' => (int) $student['user']->id,
            'courseid' => (int) $student['course']->id,
        ]);

        $deliverygrouping = $this->getDataGenerator()->create_grouping([
            'courseid' => (int) $student['course']->id,
            'name' => 'Previous delivery',
            'idnumber' => 'rtc-delivery-grouping:77',
        ]);
        $othergrouping = $this->getDataGenerator()->create_grouping([
            'courseid' => (int) $student['course']->id,
            'name' => 'Another delivery',
            'idnumber' => 'rtc-delivery-grouping:88',
        ]);
        $previousitem = $this->createQuizGrade($student['course'], $student['user'], 'Previous delivery result', 74.0);
        $otheritem = $this->createQuizGrade($student['course'], $student['user'], 'Other delivery result', 89.0);
        $quizmoduleid = (int) $DB->get_field('modules', 'id', ['name' => 'quiz'], MUST_EXIST);
        $DB->set_field('course_modules', 'groupingid', (int) $deliverygrouping->id, [
            'course' => (int) $student['course']->id,
            'module' => $quizmoduleid,
            'instance' => (int) $previousitem->iteminstance,
        ]);
        $DB->set_field('course_modules', 'groupingid', (int) $othergrouping->id, [
            'course' => (int) $student['course']->id,
            'module' => $quizmoduleid,
            'instance' => (int) $otheritem->iteminstance,
        ]);

        $this->assertTrue(\local_rtcsync\local\delivery_activity_access::prepare_course((int) $student['course']->id));
        $previouscmid = (int) $DB->get_field('course_modules', 'id', [
            'course' => (int) $student['course']->id,
            'module' => $quizmoduleid,
            'instance' => (int) $previousitem->iteminstance,
        ], MUST_EXIST);
        $previousmapping = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => $previouscmid], '*', MUST_EXIST);
        $this->assertSame(77, (int) $previousmapping->deliveryid);
        $this->assertStringContainsString('"id":' . (int) $deliverygrouping->id, (string) $previousmapping->managedavailability);
        $DB->set_field('user_enrolments', 'status', ENROL_USER_ACTIVE, [
            'userid' => (int) $student['user']->id,
        ]);
        \local_rtcsync\local\student_history::sync_marker(
            (int) $student['user']->id,
            (int) $student['course']->id,
            1,
            'delivery_participation',
            9001,
            77
        );

        $manual = new \grade_item([
            'courseid' => (int) $student['course']->id,
            'itemname' => 'Unmapped manual result',
            'itemtype' => 'manual',
            'itemmodule' => null,
            'iteminstance' => null,
            'itemnumber' => 0,
            'idnumber' => 'teacher-manual-result',
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
        ], false);
        $manual->insert('student-history-test');
        $manual->update_final_grade((int) $student['user']->id, 96.0, 'student-history-test');

        $this->setUser($student['user']);
        $history = new \local_rtcsync\local\student_history();
        $history->require_current_rtc_student($this->rtcStudentIdentity($student['user']));
        $result = $history->visible_results();

        $this->assertSame(['Previous delivery result'], array_column($result['results'], 'item'));
        $this->assertSame(74.0, $result['results'][0]['grade']);
    }

    public function test_reassigning_a_module_keeps_history_read_only_and_requires_a_new_activity_instance(): void
    {
        global $DB;

        $this->resetAfterTest();
        set_config('enableavailability', 1);
        $student = $this->createHistoryStudent('module-remap-history');
        $course = $student['course'];
        $DB->delete_records('local_rtcsync_history', [
            'userid' => (int) $student['user']->id,
            'courseid' => (int) $course->id,
        ]);
        $previousgrouping = $this->getDataGenerator()->create_grouping([
            'courseid' => (int) $course->id,
            'name' => 'Previous delivery',
            'idnumber' => 'rtc-delivery-grouping:771',
        ]);
        $currentgrouping = $this->getDataGenerator()->create_grouping([
            'courseid' => (int) $course->id,
            'name' => 'Current delivery',
            'idnumber' => 'rtc-delivery-grouping:772',
        ]);
        $previousgroup = $this->getDataGenerator()->create_group([
            'courseid' => (int) $course->id,
            'name' => 'Previous delivery group',
            'idnumber' => 'rtc-delivery-class-group:771:1',
        ]);
        $currentgroup = $this->getDataGenerator()->create_group([
            'courseid' => (int) $course->id,
            'name' => 'Current delivery group',
            'idnumber' => 'rtc-delivery-class-group:772:1',
        ]);
        groups_assign_grouping((int) $previousgrouping->id, (int) $previousgroup->id);
        groups_assign_grouping((int) $currentgrouping->id, (int) $currentgroup->id);
        $this->assertTrue(groups_add_member((int) $previousgroup->id, (int) $student['user']->id));
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => (int) $course->id,
            'name' => 'Reassigned delivery activity',
            'grade' => 100,
        ], ['availability' => $this->groupingAvailability([(int) $previousgrouping->id])]);
        $this->assertTrue(\local_rtcsync\local\delivery_activity_access::prepare_course((int) $course->id));
        $manualinstance = $DB->get_record('enrol', [
            'courseid' => (int) $course->id,
            'enrol' => 'manual',
        ], '*', MUST_EXIST);
        enrol_get_plugin('manual')->update_user_enrol(
            $manualinstance,
            (int) $student['user']->id,
            ENROL_USER_ACTIVE
        );
        $gradeitem = \grade_item::fetch([
            'courseid' => (int) $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => (int) $quiz->id,
            'itemnumber' => 0,
        ]);
        $this->assertNotFalse($gradeitem);
        $gradeitem->itemname = 'Previous delivery released result';
        $gradeitem->update();
        $gradeitem->update_final_grade((int) $student['user']->id, 81.0, 'student-history-test');
        \local_rtcsync\local\student_history::sync_marker(
            (int) $student['user']->id,
            (int) $course->id,
            1,
            'delivery_participation',
            9711,
            771
        );

        $cmid = (int) $quiz->cmid;
        $this->setUser($student['user']);
        $beforeaccess = get_fast_modinfo($course, $student['user'])->get_cm($cmid);
        $this->assertTrue($beforeaccess->uservisible);
        $before = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => $cmid], '*', MUST_EXIST);
        $this->assertSame(771, (int) $before->deliveryid);
        $teacheravailability = $this->groupingAvailability([(int) $currentgrouping->id]);
        $editedroot = \core_availability\tree::get_root_json(
            [
                json_decode($teacheravailability),
                \availability_grouping\condition::get_json((int) $previousgrouping->id),
            ],
            \core_availability\tree::OP_AND,
            [true, false]
        );
        $DB->set_field('course_modules', 'availability', json_encode($editedroot), ['id' => $cmid]);
        $this->assertTrue(\local_rtcsync\local\delivery_activity_access::protect_course_module($cmid));

        $current = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => $cmid], '*', MUST_EXIST);
        $this->assertSame(0, (int) $current->deliveryid);
        $this->assertSame(-$cmid, (int) $current->groupingid);
        $this->assertTrue($DB->record_exists('local_rtcsync_deliverycmhist', [
            'cmid' => $cmid,
            'courseid' => (int) $course->id,
            'deliveryid' => 771,
        ]));

        // A transfer removes the old delivery membership and adds the learner
        // to B. The old activity stays closed because its attempt state is
        // shared by Moodle's activity instance; a fresh B instance is usable.
        $this->assertTrue(groups_remove_member((int) $previousgroup->id, (int) $student['user']->id));
        $this->assertTrue(groups_add_member((int) $currentgroup->id, (int) $student['user']->id));
        $newquiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => (int) $course->id,
            'name' => 'Current delivery activity',
            'grade' => 100,
        ], ['availability' => $this->groupingAvailability([(int) $currentgrouping->id])]);
        $newmapping = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => (int) $newquiz->cmid], '*', MUST_EXIST);
        $this->assertSame(772, (int) $newmapping->deliveryid);

        $this->setUser($student['user']);
        $history = new \local_rtcsync\local\student_history();
        $results = $history->visible_results();
        $this->assertSame(['Previous delivery released result'], array_column($results['results'], 'item'));
        $this->assertSame(81.0, $results['results'][0]['grade']);
        $moduleinfo = get_fast_modinfo($course, $student['user']);
        $this->assertFalse($moduleinfo->get_cm($cmid)->uservisible);
        $this->assertTrue($moduleinfo->get_cm((int) $newquiz->cmid)->uservisible);
        $this->assertTrue(is_enrolled(\context_course::instance((int) $course->id), $student['user'], '', true));
    }

    public function test_delivery_scope_denies_stale_activity_but_keeps_old_released_result(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enableavailability', 1);
        $student = $this->createHistoryStudent('delivery-scope-switch');
        $course = $student['course'];
        $DB->delete_records('local_rtcsync_history', [
            'userid' => (int) $student['user']->id,
            'courseid' => (int) $course->id,
        ]);
        $previousgrouping = $this->getDataGenerator()->create_grouping([
            'courseid' => (int) $course->id,
            'name' => 'Previous delivery',
            'idnumber' => 'rtc-delivery-grouping:771',
        ]);
        $currentgrouping = $this->getDataGenerator()->create_grouping([
            'courseid' => (int) $course->id,
            'name' => 'Current delivery',
            'idnumber' => 'rtc-delivery-grouping:772',
        ]);
        $previousgroup = $this->getDataGenerator()->create_group([
            'courseid' => (int) $course->id,
            'name' => 'Previous delivery group',
            'idnumber' => 'rtc-delivery-class-group:771:1',
        ]);
        $currentgroup = $this->getDataGenerator()->create_group([
            'courseid' => (int) $course->id,
            'name' => 'Current delivery group',
            'idnumber' => 'rtc-delivery-class-group:772:1',
        ]);
        groups_assign_grouping((int) $previousgrouping->id, (int) $previousgroup->id);
        groups_assign_grouping((int) $currentgrouping->id, (int) $currentgroup->id);
        $this->assertTrue(groups_add_member((int) $previousgroup->id, (int) $student['user']->id));
        $this->assertTrue(groups_add_member((int) $currentgroup->id, (int) $student['user']->id));

        $previousquiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => (int) $course->id,
            'name' => 'Previous delivery activity',
            'grade' => 100,
        ], ['availability' => $this->groupingAvailability([(int) $previousgrouping->id])]);
        $currentquiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => (int) $course->id,
            'name' => 'Current delivery activity',
            'grade' => 100,
        ], ['availability' => $this->groupingAvailability([(int) $currentgrouping->id])]);
        $this->assertTrue(\local_rtcsync\local\delivery_activity_access::prepare_course((int) $course->id));

        $manualinstance = $DB->get_record('enrol', [
            'courseid' => (int) $course->id,
            'enrol' => 'manual',
        ], '*', MUST_EXIST);
        enrol_get_plugin('manual')->update_user_enrol(
            $manualinstance,
            (int) $student['user']->id,
            ENROL_USER_ACTIVE
        );

        $previousgrade = \grade_item::fetch([
            'courseid' => (int) $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => (int) $previousquiz->id,
            'itemnumber' => 0,
        ]);
        $this->assertNotFalse($previousgrade);
        $previousgrade->itemname = 'Previous delivery released result';
        $previousgrade->update();
        $previousgrade->update_final_grade((int) $student['user']->id, 81.0, 'student-history-test');
        \local_rtcsync\local\student_history::sync_marker(
            (int) $student['user']->id,
            (int) $course->id,
            1,
            'delivery_participation',
            9711,
            771
        );

        $initial = \local_rtcsync_external::sync_student_delivery_scope([
            'courseid' => (int) $course->id,
            'userid' => (int) $student['user']->id,
            'deliveryids' => [771],
            'revision' => 20261025000000000,
        ]);
        $this->assertSame([771], $initial['deliveryids']);
        $this->assertSame(1, $initial['applied']);
        $this->assertSame(771, (int) $DB->get_field('local_rtcsync_deliverycm', 'deliveryid', ['cmid' => (int) $previousquiz->cmid]));
        $this->assertSame(772, (int) $DB->get_field('local_rtcsync_deliverycm', 'deliveryid', ['cmid' => (int) $currentquiz->cmid]));
        $this->assertTrue($DB->record_exists('groups_members', [
            'groupid' => (int) $previousgroup->id,
            'userid' => (int) $student['user']->id,
        ]));
        $this->assertTrue($DB->record_exists('groups_members', [
            'groupid' => (int) $currentgroup->id,
            'userid' => (int) $student['user']->id,
        ]));

        \local_rtcsync\local\delivery_activity_access::require_student_module_access(
            (int) $course->id,
            (int) $previousquiz->cmid,
            (int) $student['user']->id
        );
        \local_rtcsync_external::sync_student_delivery_scope([
            'courseid' => (int) $course->id,
            'userid' => (int) $student['user']->id,
            'deliveryids' => [772],
            'revision' => 20261025000000001,
        ]);

        try {
            \local_rtcsync\local\delivery_activity_access::require_student_module_access(
                (int) $course->id,
                (int) $previousquiz->cmid,
                (int) $student['user']->id
            );
            $this->fail('A stale grouping membership must not keep the previous delivery activity open.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('activityiscurrentlyhidden', $exception->errorcode);
        }
        \local_rtcsync\local\delivery_activity_access::require_student_module_access(
            (int) $course->id,
            (int) $currentquiz->cmid,
            (int) $student['user']->id
        );
        $this->assertFalse(\local_rtcsync\local\delivery_activity_access::student_module_content_is_in_current_delivery(
            (int) $course->id,
            (int) $previousquiz->cmid,
            (int) $student['user']->id
        ));
        $this->assertTrue(\local_rtcsync\local\delivery_activity_access::student_module_content_is_in_current_delivery(
            (int) $course->id,
            (int) $currentquiz->cmid,
            (int) $student['user']->id
        ));
        $this->assertFalse(\local_rtcsync\local\delivery_activity_access::calendar_event_is_in_current_delivery((object) [
            'courseid' => (int) $course->id,
            'eventtype' => 'group',
            'groupid' => (int) $previousgroup->id,
        ], (int) $student['user']->id));
        $this->assertTrue(\local_rtcsync\local\delivery_activity_access::calendar_event_is_in_current_delivery((object) [
            'courseid' => (int) $course->id,
            'eventtype' => 'group',
            'groupid' => (int) $currentgroup->id,
        ], (int) $student['user']->id));
        try {
            \local_rtcsync\local\delivery_activity_access::require_student_course_module_guards(
                (int) $course->id,
                (int) $student['user']->id
            );
            $this->fail('Course-wide search must fail closed while a stale previous-delivery module remains visible.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('activityiscurrentlyhidden', $exception->errorcode);
        }

        $this->setUser($student['user']);
        $history = new \local_rtcsync\local\student_history();
        $results = $history->visible_results();
        $this->assertSame(['Previous delivery released result'], array_column($results['results'], 'item'));
        $this->assertSame(81.0, $results['results'][0]['grade']);
    }

    public function test_delivery_course_stays_locked_until_bounded_module_backfill_finishes(): void
    {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-subject:delivery-backfill',
            'visible' => 1,
        ]);
        $user = $this->getDataGenerator()->create_user([
            'username' => 'delivery-backfill-student@example.test',
            'email' => 'delivery-backfill-student@example.test',
            'idnumber' => 'DELIVERY-BACKFILL-STUDENT',
        ]);
        $DB->insert_record('local_rtcsync_user', (object) [
            'userid' => (int) $user->id,
            'idnumber' => (string) $user->idnumber,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $manualplugin = enrol_get_plugin('manual');
        $manualinstance = $DB->get_record('enrol', [
            'courseid' => (int) $course->id,
            'enrol' => 'manual',
        ], '*', IGNORE_MISSING);
        if (!$manualinstance) {
            $manualplugin->add_default_instance($course);
            $manualinstance = $DB->get_record('enrol', [
                'courseid' => (int) $course->id,
                'enrol' => 'manual',
            ], '*', MUST_EXIST);
        }
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        $manualplugin->enrol_user(
            $manualinstance,
            (int) $user->id,
            (int) $studentrole->id,
            0,
            0,
            ENROL_USER_ACTIVE
        );
        $this->getDataGenerator()->create_module('quiz', [
            'course' => (int) $course->id,
            'name' => 'Untagged first',
            'grade' => 100,
        ]);
        $this->getDataGenerator()->create_module('quiz', [
            'course' => (int) $course->id,
            'name' => 'Untagged second',
            'grade' => 100,
        ]);

        $this->assertFalse(\local_rtcsync\local\delivery_activity_access::prepare_course((int) $course->id, 1));
        $this->assertSame(0, (int) $DB->get_field('course', 'visible', ['id' => (int) $course->id]));
        $this->assertSame(ENROL_USER_SUSPENDED, (int) $DB->get_field('user_enrolments', 'status', [
            'enrolid' => (int) $manualinstance->id,
            'userid' => (int) $user->id,
        ]));
        $this->assertFalse(\local_rtcsync\local\delivery_activity_access::prepare_course((int) $course->id, 1));
        $this->assertTrue(\local_rtcsync\local\delivery_activity_access::prepare_course((int) $course->id, 1));
        $this->assertSame(1, (int) $DB->get_field('course', 'visible', ['id' => (int) $course->id]));
        $guardedmodules = $DB->get_records('local_rtcsync_deliverycm', ['courseid' => (int) $course->id]);
        $this->assertCount(2, $guardedmodules);
        foreach ($guardedmodules as $guardedmodule) {
            $this->assertSame(0, (int) $guardedmodule->deliveryid);
            $this->assertStringContainsString('"id":-' . (int) $guardedmodule->cmid, (string) $guardedmodule->managedavailability);
        }
    }

    public function test_page_and_resource_availability_map_per_delivery_and_block_cross_delivery_reuse(): void
    {
        global $DB;

        $this->resetAfterTest();
        set_config('enableavailability', 1);
        $course = $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-subject:page-resource-map',
            'visible' => 1,
            'groupmode' => NOGROUPS,
            'groupmodeforce' => 0,
        ]);
        $pagegrouping = $this->getDataGenerator()->create_grouping([
            'courseid' => (int) $course->id,
            'name' => 'Page delivery',
            'idnumber' => 'rtc-delivery-grouping:771',
        ]);
        $resourcegrouping = $this->getDataGenerator()->create_grouping([
            'courseid' => (int) $course->id,
            'name' => 'Resource delivery',
            'idnumber' => 'rtc-delivery-grouping:772',
        ]);
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => (int) $course->id,
            'name' => 'Delivery page',
        ], ['availability' => $this->groupingAvailability([(int) $pagegrouping->id])]);
        $resource = $this->getDataGenerator()->create_module('resource', [
            'course' => (int) $course->id,
            'name' => 'Delivery resource',
        ], ['availability' => $this->groupingAvailability([(int) $resourcegrouping->id])]);

        $pagemapping = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => (int) $page->cmid], '*', MUST_EXIST);
        $resourcemapping = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => (int) $resource->cmid], '*', MUST_EXIST);
        $this->assertSame(771, (int) $pagemapping->deliveryid);
        $this->assertSame((int) $pagegrouping->id, (int) $pagemapping->groupingid);
        $this->assertSame(772, (int) $resourcemapping->deliveryid);
        $this->assertSame((int) $resourcegrouping->id, (int) $resourcemapping->groupingid);

        // Moodle's edit form preserves the old hidden managed condition while
        // the teacher changes the explicit condition from delivery A to B.
        $teacheravailability = $this->groupingAvailability([(int) $resourcegrouping->id]);
        $editedroot = \core_availability\tree::get_root_json(
            [
                json_decode($teacheravailability),
                \availability_grouping\condition::get_json((int) $pagegrouping->id),
            ],
            \core_availability\tree::OP_AND,
            [true, false]
        );
        $DB->set_field('course_modules', 'availability', json_encode($editedroot), ['id' => (int) $page->cmid]);
        $this->assertTrue(\local_rtcsync\local\delivery_activity_access::protect_course_module((int) $page->cmid));

        $remapped = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => (int) $page->cmid], '*', MUST_EXIST);
        $this->assertSame(0, (int) $remapped->deliveryid);
        $this->assertSame(-(int) $page->cmid, (int) $remapped->groupingid);
        $this->assertTrue($DB->record_exists('local_rtcsync_deliverycmhist', [
            'cmid' => (int) $page->cmid,
            'courseid' => (int) $course->id,
            'deliveryid' => 771,
        ]));
        $this->assertStringContainsString('"id":-' . (int) $page->cmid, (string) $remapped->managedavailability);
        $this->assertStringContainsString('"id":' . (int) $resourcegrouping->id, (string) $remapped->originalavailability);
        $this->assertStringNotContainsString('"id":' . (int) $pagegrouping->id, (string) $remapped->originalavailability);
        $this->assertStringNotContainsString('"id":' . (int) $pagegrouping->id, (string) $remapped->managedavailability);
        $this->assertSame(SEPARATEGROUPS, (int) $DB->get_field('course', 'groupmode', ['id' => (int) $course->id]));
        $this->assertSame(0, (int) $DB->get_field('course', 'groupmodeforce', ['id' => (int) $course->id]));

        $existingmodecourse = $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-subject:existing-group-mode',
            'groupmode' => VISIBLEGROUPS,
            'groupmodeforce' => 0,
        ]);
        \local_rtcsync\local\delivery_activity_access::ensure_grouping_ui_enabled((int) $existingmodecourse->id);
        $this->assertSame(VISIBLEGROUPS, (int) $DB->get_field('course', 'groupmode', ['id' => (int) $existingmodecourse->id]));
        $this->assertSame(0, (int) $DB->get_field('course', 'groupmodeforce', ['id' => (int) $existingmodecourse->id]));
    }

    public function test_page_and_resource_without_one_exact_delivery_restriction_fail_closed(): void
    {
        global $DB;

        $this->resetAfterTest();
        set_config('enableavailability', 1);
        $course = $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-subject:page-resource-unmapped',
        ]);
        $firstgrouping = $this->getDataGenerator()->create_grouping([
            'courseid' => (int) $course->id,
            'name' => 'First delivery',
            'idnumber' => 'rtc-delivery-grouping:781',
        ]);
        $secondgrouping = $this->getDataGenerator()->create_grouping([
            'courseid' => (int) $course->id,
            'name' => 'Second delivery',
            'idnumber' => 'rtc-delivery-grouping:782',
        ]);
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => (int) $course->id,
            'name' => 'Unmapped page',
        ]);
        $resource = $this->getDataGenerator()->create_module('resource', [
            'course' => (int) $course->id,
            'name' => 'Ambiguous resource',
        ], ['availability' => $this->groupingAvailability([
            (int) $firstgrouping->id,
            (int) $secondgrouping->id,
        ])]);

        foreach ([(int) $page->cmid, (int) $resource->cmid] as $cmid) {
            $mapping = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => $cmid], '*', MUST_EXIST);
            $this->assertSame(0, (int) $mapping->deliveryid);
            $this->assertLessThan(0, (int) $mapping->groupingid);
            $this->assertStringContainsString('"id":-' . $cmid, (string) $mapping->managedavailability);
        }
    }

    public function test_disabling_availability_locks_ready_delivery_courses_and_restores_config_only_when_managed(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('enableavailability', 0);
        $unmanagedevent = \core\event\config_log_created::create([
            'objectid' => 1,
            'context' => \context_system::instance(),
            'other' => ['name' => 'enableavailability', 'oldvalue' => '1', 'value' => '0', 'plugin' => ''],
        ]);
        \local_rtcsync\observer::config_log_created($unmanagedevent);
        $this->assertSame('0', get_config('', 'enableavailability'));

        $groupingonlycourse = $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-subject:grouping-only-lockdown',
            'visible' => 1,
        ]);
        $this->getDataGenerator()->create_grouping([
            'courseid' => (int) $groupingonlycourse->id,
            'name' => 'Existing delivery grouping',
            'idnumber' => 'rtc-delivery-grouping:760',
        ]);
        $groupingonlyevent = \core\event\config_log_created::create([
            'objectid' => 2,
            'context' => \context_system::instance(),
            'other' => ['name' => 'enableavailability', 'oldvalue' => '1', 'value' => '0', 'plugin' => ''],
        ]);
        \local_rtcsync\observer::config_log_created($groupingonlyevent);
        $this->assertSame(0, (int) $DB->get_field('local_rtcsync_deliverycourse', 'ready', [
            'courseid' => (int) $groupingonlycourse->id,
        ]));
        $this->assertSame(0, (int) $DB->get_field('course', 'visible', ['id' => (int) $groupingonlycourse->id]));

        set_config('enableavailability', 1);
        $course = $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-subject:availability-lockdown',
            'visible' => 1,
        ]);
        $user = $this->getDataGenerator()->create_user([
            'username' => 'availability-lockdown-student@example.test',
            'email' => 'availability-lockdown-student@example.test',
        ]);
        $DB->insert_record('local_rtcsync_user', (object) [
            'userid' => (int) $user->id,
            'idnumber' => (string) $user->idnumber,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $manualplugin = enrol_get_plugin('manual');
        $manualinstance = $DB->get_record('enrol', [
            'courseid' => (int) $course->id,
            'enrol' => 'manual',
        ], '*', IGNORE_MISSING);
        if (!$manualinstance) {
            $manualplugin->add_default_instance($course);
            $manualinstance = $DB->get_record('enrol', [
                'courseid' => (int) $course->id,
                'enrol' => 'manual',
            ], '*', MUST_EXIST);
        }
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        $manualplugin->enrol_user($manualinstance, (int) $user->id, (int) $studentrole->id, 0, 0, ENROL_USER_ACTIVE);
        $this->assertTrue(\local_rtcsync\local\delivery_activity_access::prepare_course((int) $course->id));
        $manualplugin->update_user_enrol($manualinstance, (int) $user->id, ENROL_USER_ACTIVE);

        set_config('enableavailability', 0);
        $managedevent = \core\event\config_log_created::create([
            'objectid' => 3,
            'context' => \context_system::instance(),
            'other' => ['name' => 'enableavailability', 'oldvalue' => '1', 'value' => '0', 'plugin' => ''],
        ]);
        \local_rtcsync\observer::config_log_created($managedevent);

        $this->assertSame('1', get_config('', 'enableavailability'));
        $this->assertSame(0, (int) $DB->get_field('local_rtcsync_deliverycourse', 'ready', ['courseid' => (int) $course->id]));
        $this->assertSame(0, (int) $DB->get_field('course', 'visible', ['id' => (int) $course->id]));
        $this->assertSame(ENROL_USER_SUSPENDED, (int) $DB->get_field('user_enrolments', 'status', [
            'enrolid' => (int) $manualinstance->id,
            'userid' => (int) $user->id,
        ]));
    }

    public function test_after_config_override_locks_ready_delivery_course_before_request(): void
    {
        global $DB;

        $this->resetAfterTest();
        set_config('enableavailability', 1);
        $course = $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-subject:availability-override',
            'visible' => 1,
        ]);
        $this->getDataGenerator()->create_module('page', [
            'course' => (int) $course->id,
            'name' => 'Guarded before override',
        ]);
        $this->assertTrue(\local_rtcsync\local\delivery_activity_access::prepare_course((int) $course->id));

        set_config('enableavailability', 0);
        \local_rtcsync\hook_callbacks::after_config(new \core\hook\after_config());

        $this->assertSame(0, (int) $DB->get_field('local_rtcsync_deliverycourse', 'ready', ['courseid' => (int) $course->id]));
        $this->assertSame(0, (int) $DB->get_field('course', 'visible', ['id' => (int) $course->id]));
    }

    public function test_course_state_compare_rejects_a_stale_ready_snapshot(): void
    {
        global $DB;

        $this->resetAfterTest();
        set_config('enableavailability', 1);
        $course = $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-subject:stale-ready-cas',
            'visible' => 1,
        ]);
        $this->assertTrue(\local_rtcsync\local\delivery_activity_access::prepare_course((int) $course->id));
        $expected = $DB->get_record('local_rtcsync_deliverycourse', ['courseid' => (int) $course->id], '*', MUST_EXIST);

        // Model an out-of-lock fail-closed signal landing after a page read its
        // snapshot but before that page attempts to publish readiness.
        $DB->set_field('local_rtcsync_deliverycourse', 'ready', 0, ['id' => (int) $expected->id]);
        $DB->set_field('local_rtcsync_deliverycourse', 'lastcmid', 0, ['id' => (int) $expected->id]);
        $DB->set_field(
            'local_rtcsync_deliverycourse',
            'timemodified',
            (int) $expected->timemodified + 1,
            ['id' => (int) $expected->id]
        );

        $compare = new \ReflectionMethod(
            \local_rtcsync\local\delivery_activity_access::class,
            'compare_and_update_course_state'
        );
        $compare->setAccessible(true);
        $accepted = $compare->invoke(
            null,
            $expected,
            1,
            (int) $expected->lastcmid,
            (int) $expected->timemodified + 2
        );

        $this->assertFalse($accepted);
        $this->assertSame(0, (int) $DB->get_field('local_rtcsync_deliverycourse', 'ready', ['id' => (int) $expected->id]));
    }

    public function test_ready_course_callback_keeps_the_access_lock_held(): void
    {
        $this->resetAfterTest();
        set_config('enableavailability', 1);
        $course = $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-subject:atomic-active-enrolment',
            'visible' => 1,
        ]);
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_rtcsync_delivery_access');
        $callbackran = false;

        $result = \local_rtcsync\local\delivery_activity_access::with_ready_course(
            (int) $course->id,
            function() use ($lockfactory, $course, &$callbackran): string {
                $competinglock = $lockfactory->get_lock('course:' . (int) $course->id, 0);
                if ($competinglock) {
                    $competinglock->release();
                }
                $this->assertFalse($competinglock);
                $callbackran = true;
                return 'written';
            }
        );

        $this->assertTrue($callbackran);
        $this->assertSame('written', $result);
    }

    public function test_module_event_lock_timeout_fails_closed_after_the_module_row_is_already_written(): void
    {
        global $DB;

        $this->resetAfterTest();
        set_config('enableavailability', 1);
        $course = $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-subject:module-lock-timeout',
            'visible' => 1,
        ]);
        $module = $this->getDataGenerator()->create_module('page', [
            'course' => (int) $course->id,
            'name' => 'Already written module',
        ]);
        $grouping = $this->getDataGenerator()->create_grouping([
            'courseid' => (int) $course->id,
            'name' => 'Delivery marker',
            'idnumber' => 'rtc-delivery-grouping:791',
        ]);
        // The grouping makes this course delivery-managed; prepare creates state.
        $this->assertTrue(\local_rtcsync\local\delivery_activity_access::prepare_course((int) $course->id));
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_rtcsync_delivery_access');
        $lock = $lockfactory->get_lock('course:' . (int) $course->id, 0);
        $this->assertNotFalse($lock);
        $caught = false;
        try {
            \local_rtcsync\local\delivery_activity_access::handle_module_event((int) $module->cmid);
        } catch (\moodle_exception $exception) {
            $caught = true;
        } finally {
            $lock->release();
        }

        $this->assertTrue($caught);
        $this->assertSame(0, (int) $DB->get_field('local_rtcsync_deliverycourse', 'ready', ['courseid' => (int) $course->id]));
        $this->assertSame(0, (int) $DB->get_field('course', 'visible', ['id' => (int) $course->id]));
        $this->assertLessThan(0, (int) $DB->get_field('local_rtcsync_deliverycm', 'groupingid', ['cmid' => (int) $module->cmid]));
    }

    public function test_marker_sync_clears_only_the_requested_source(): void
    {
        global $DB;

        $this->resetAfterTest();
        $student = $this->createHistoryStudent('source-clear');
        $this->getDataGenerator()->create_grouping([
            'courseid' => (int) $student['course']->id,
            'name' => 'History delivery',
            'idnumber' => 'rtc-delivery-grouping:77',
        ]);
        \local_rtcsync\local\student_history::sync_marker(
            (int) $student['user']->id,
            (int) $student['course']->id,
            1,
            'delivery_participation',
            9001,
            77
        );

        \local_rtcsync\local\student_history::sync_marker(
            (int) $student['user']->id,
            (int) $student['course']->id,
            0,
            'delivery_participation',
            9001,
            77
        );

        $this->assertTrue($DB->record_exists('local_rtcsync_history', [
            'userid' => (int) $student['user']->id,
            'courseid' => (int) $student['course']->id,
            'sourcetype' => 'user_program',
            'sourceid' => 1000 + (int) $student['user']->id,
        ]));
        $this->assertFalse($DB->record_exists('local_rtcsync_history', [
            'userid' => (int) $student['user']->id,
            'courseid' => (int) $student['course']->id,
            'sourcetype' => 'delivery_participation',
            'sourceid' => 9001,
        ]));
    }

    public function test_marker_sync_clears_legacy_delivery_source_with_null_delivery_id(): void
    {
        global $DB;

        $this->resetAfterTest();
        $student = $this->createHistoryStudent('legacy-source-clear');
        $DB->insert_record('local_rtcsync_history', (object) [
            'userid' => (int) $student['user']->id,
            'courseid' => (int) $student['course']->id,
            'sourcetype' => 'delivery_participation',
            'sourceid' => 9002,
            'deliveryid' => null,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        \local_rtcsync\local\student_history::sync_marker(
            (int) $student['user']->id,
            (int) $student['course']->id,
            0,
            'delivery_participation',
            9002,
            77
        );

        $this->assertFalse($DB->record_exists('local_rtcsync_history', [
            'userid' => (int) $student['user']->id,
            'courseid' => (int) $student['course']->id,
            'sourcetype' => 'delivery_participation',
            'sourceid' => 9002,
        ]));
        $this->assertTrue($DB->record_exists('local_rtcsync_history', [
            'userid' => (int) $student['user']->id,
            'courseid' => (int) $student['course']->id,
            'sourcetype' => 'user_program',
            'sourceid' => 1000 + (int) $student['user']->id,
        ]));
    }

    public function test_history_marker_cannot_be_created_for_an_active_enrolment(): void
    {
        global $DB;

        $this->resetAfterTest();
        $student = $this->createHistoryStudent('active-marker');
        $DB->delete_records('local_rtcsync_history', [
            'userid' => (int) $student['user']->id,
            'courseid' => (int) $student['course']->id,
        ]);
        $DB->set_field('user_enrolments', 'status', ENROL_USER_ACTIVE, [
            'userid' => (int) $student['user']->id,
        ]);

        $this->expectException(\invalid_parameter_exception::class);
        \local_rtcsync\local\student_history::sync_marker(
            (int) $student['user']->id,
            (int) $student['course']->id,
            1,
            'user_program',
            7001,
            0
        );
    }
}
