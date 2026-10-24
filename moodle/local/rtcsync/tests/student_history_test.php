<?php

namespace local_rtcsync;

use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the read-only student released-history page data boundary.
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
            1000 + (int) $user->id
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
        $result = $history->visible_results(0);

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
        $result = $history->visible_results(0);
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
        $page = $history->visible_results(0);

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
        $page = $history->visible_results(0);

        $this->assertSame(['Visible after hidden rows'], array_column($page['results'], 'item'));
        $this->assertFalse($page['hasnext']);
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
        $history->require_current_rtc_student($this->rtcStudentIdentity($student['user']));
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

    public function test_hidden_course_marker_does_not_grant_student_history_access(): void
    {
        global $DB;

        $this->resetAfterTest();
        $student = $this->createHistoryStudent('hidden-course');
        $DB->set_field('course', 'visible', 0, ['id' => (int) $student['course']->id]);
        $history = new \local_rtcsync\local\student_history();
        $this->setUser($student['user']);

        $this->assertSame([], $history->visible_results(0)['results']);
        $this->expectException(\moodle_exception::class);
        $history->require_current_rtc_student($this->rtcStudentIdentity($student['user']));
    }

    public function test_visible_history_never_reactivates_suspended_course_enrolment(): void
    {
        global $DB;

        $this->resetAfterTest();
        $student = $this->createHistoryStudent('suspended');
        $this->createQuizGrade($student['course'], $student['user'], 'Released result', 80.0);
        $this->setUser($student['user']);

        $history = new \local_rtcsync\local\student_history();
        $result = $history->visible_results(0);
        $enrolment = $DB->get_record('user_enrolments', [
            'userid' => (int) $student['user']->id,
            'status' => ENROL_USER_SUSPENDED,
        ], '*', MUST_EXIST);

        $this->assertNotEmpty($result['results']);
        $this->assertSame(ENROL_USER_SUSPENDED, (int) $enrolment->status);
        $this->assertFalse(is_enrolled(\context_course::instance((int) $student['course']->id), $student['user'], '', true));
    }

    public function test_marker_sync_clears_only_the_requested_source(): void
    {
        global $DB;

        $this->resetAfterTest();
        $student = $this->createHistoryStudent('source-clear');
        \local_rtcsync\local\student_history::sync_marker(
            (int) $student['user']->id,
            (int) $student['course']->id,
            1,
            'delivery_participation',
            9001
        );

        \local_rtcsync\local\student_history::sync_marker(
            (int) $student['user']->id,
            (int) $student['course']->id,
            0,
            'delivery_participation',
            9001
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
            7001
        );
    }
}
