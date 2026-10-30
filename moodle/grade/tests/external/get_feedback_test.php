<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace core_grades\external;

defined('MOODLE_INTERNAL') || die;

global $CFG;

require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * Unit tests for the core_grades\external\get_feedback webservice.
 *
 * @package    core_grades
 * @category   external
 * @copyright  2023 Kevin Percy <kevin.percy@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 4.2
 * @covers \core_grades\external\get_feedback
 */
final class get_feedback_test extends \externallib_advanced_testcase {

    /**
     * Test get_feedback.
     *
     * @dataProvider get_feedback_provider
     * @param string|null $feedback The feedback text added for the grade item.
     * @param array $expected The expected feedback data.
     * @return void
     */
    public function test_get_feedback(?string $feedback, array $expected): void {

        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'John', 'lastname' => 'Doe',
            'email' => 'johndoe@example.com']);
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $gradeitem = $this->getDataGenerator()->create_grade_item(['itemname' => 'Grade item 1',
            'courseid' => $course->id]);

        $gradegradedata = [
            'itemid' => $gradeitem->id,
            'userid' => $user->id,
        ];

        if ($feedback) {
            $gradegradedata['feedback'] = $feedback;
        }

        $this->getDataGenerator()->create_grade_grade($gradegradedata);
        $this->setAdminUser();

        $feedbackdata = get_feedback::execute($course->id, $user->id, $gradeitem->id);

        $this->assertEquals($expected['feedbacktext'], $feedbackdata['feedbacktext']);
        $this->assertEquals($expected['title'], $feedbackdata['title']);
        $this->assertEquals($expected['fullname'], $feedbackdata['fullname']);
        $this->assertEquals($expected['additionalfield'], $feedbackdata['additionalfield']);
    }

    /**
     * Data provider for test_get_feedback().
     *
     * @return array
     */
    public static function get_feedback_provider(): array {
        return [
            'Return when feedback is set.' => [
                'Test feedback',
                [
                    'feedbacktext' => 'Test feedback',
                    'title' => 'Grade item 1',
                    'fullname' => 'John Doe',
                    'additionalfield' => 'johndoe@example.com'
                ]
            ],
            'Return when feedback is not set.' => [
                null,
                [
                    'feedbacktext' => null,
                    'title' => 'Grade item 1',
                    'fullname' => 'John Doe',
                    'additionalfield' => 'johndoe@example.com'
                ]
            ]
        ];
    }

    /**
     * A managed Student must not read grade feedback from the grader endpoint.
     *
     * The nonexistent-item request proves the policy runs before grade-item
     * lookup; the real-item request confirms stored feedback is also denied.
     *
     * @return void
     */
    public function test_managed_student_is_denied_before_grade_feedback_lookup(): void {
        global $DB, $PAGE;

        $this->resetAfterTest(true);
        $this->require_rtcsync_delivery_schema();
        [$course, $student, $gradeitem] = $this->create_feedback_fixture(true);

        $coursecontext = \context_course::instance((int) $course->id);
        $studentrole = $this->getDataGenerator()->create_role([
            'shortname' => 'feedbackstudent' . random_int(100000, 999999),
        ]);
        assign_capability('gradereport/grader:view', CAP_ALLOW, $studentrole, $coursecontext->id, true);
        role_assign($studentrole, (int) $student->id, $coursecontext->id);
        $this->setUser($student);
        $PAGE->set_url(new \moodle_url('/grade/feedback_test.php', ['id' => $course->id]));

        $this->assertTrue(has_capability('gradereport/grader:view', $coursecontext));
        $this->assertFalse(has_capability('moodle/grade:viewall', $coursecontext));

        $missinggradeitemid = (int) $DB->get_field_sql('SELECT MAX(id) FROM {grade_items}') + 1;
        $this->assert_student_feedback_is_restricted(
            (int) $course->id,
            (int) $student->id,
            $missinggradeitemid
        );
        $this->assert_student_feedback_is_restricted(
            (int) $course->id,
            (int) $student->id,
            (int) $gradeitem->id
        );
    }

    /**
     * Gradebook staff with grade:viewall retain feedback access in a managed course.
     *
     * @return void
     */
    public function test_gradebook_staff_with_viewall_can_fetch_managed_course_feedback(): void {
        global $PAGE;

        $this->resetAfterTest(true);
        $this->require_rtcsync_delivery_schema();
        [$course, $student, $gradeitem] = $this->create_feedback_fixture(true);

        $coursecontext = \context_course::instance((int) $course->id);
        $roleid = $this->getDataGenerator()->create_role([
            'shortname' => 'feedbackstaff' . random_int(100000, 999999),
        ]);
        assign_capability('gradereport/grader:view', CAP_ALLOW, $roleid, $coursecontext->id, true);
        assign_capability('moodle/grade:viewall', CAP_ALLOW, $roleid, $coursecontext->id, true);
        role_assign($roleid, (int) $student->id, $coursecontext->id);
        $this->setUser($student);
        $PAGE->set_url(new \moodle_url('/grade/feedback_test.php', ['id' => $course->id]));

        $this->assertTrue(has_capability('gradereport/grader:view', $coursecontext));
        $this->assertTrue(has_capability('moodle/grade:viewall', $coursecontext));

        $feedback = get_feedback::execute((int) $course->id, (int) $student->id, (int) $gradeitem->id);
        $this->assertSame('RTC private feedback', $feedback['feedbacktext']);
    }

    /**
     * A Student in an unmanaged course retains the existing feedback endpoint behavior.
     *
     * @return void
     */
    public function test_student_can_fetch_feedback_in_unmanaged_course(): void {
        global $PAGE;

        $this->resetAfterTest(true);
        [$course, $student, $gradeitem] = $this->create_feedback_fixture(false);

        $coursecontext = \context_course::instance((int) $course->id);
        $studentrole = $this->getDataGenerator()->create_role([
            'shortname' => 'feedbackstudent' . random_int(100000, 999999),
        ]);
        assign_capability('gradereport/grader:view', CAP_ALLOW, $studentrole, $coursecontext->id, true);
        role_assign($studentrole, (int) $student->id, $coursecontext->id);
        $this->setUser($student);
        $PAGE->set_url(new \moodle_url('/grade/feedback_test.php', ['id' => $course->id]));

        $this->assertTrue(has_capability('gradereport/grader:view', $coursecontext));
        $feedback = get_feedback::execute((int) $course->id, (int) $student->id, (int) $gradeitem->id);
        $this->assertSame('RTC private feedback', $feedback['feedbacktext']);
    }

    /**
     * Test get_feedback with invalid requests.
     *
     * @dataProvider get_feedback_invalid_request_provider
     * @param string $loggeduserrole The role of the logged user.
     * @param bool $feedbacknotincourse Whether to request a feedback for a grade item which is not a part of the course.
     * @param array $expectedexception The expected exception.
     * @return void
     */
    public function test_get_feedback_invalid_request(string $loggeduserrole, bool $feedbacknotincourse,
            array $expectedexception = []): void {

        $this->resetAfterTest(true);
        // Create a course with a user and a grade item.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $gradeitem = $this->getDataGenerator()->create_grade_item(['courseid' => $course->id]);
        // Add feedback for the grade item in course.
        $gradegradedata = [
            'itemid' => $gradeitem->id,
            'userid' => $user->id,
            'feedback' => 'Test feedback',
        ];

        $this->getDataGenerator()->create_grade_grade($gradegradedata);
        // Set the current user as specified.
        if ($loggeduserrole === 'user') {
            $this->setUser($user);
        } else if ($loggeduserrole === 'guest') {
            $this->setGuestUser();
        } else {
            $this->setAdminUser();
        }

        if ($feedbacknotincourse) { // Create a new course which will be later used in the feedback request call.
            $course = $this->getDataGenerator()->create_course();
        }

        $this->expectException($expectedexception['exceptionclass']);

        if (!empty($expectedexception['exceptionmessage'])) {
            $this->expectExceptionMessage($expectedexception['exceptionmessage']);
        }

        get_feedback::execute($course->id, $user->id, $gradeitem->id);
    }

    /**
     * Data provider for test_get_feedback_invalid_request().
     *
     * @return array
     */
    public static function get_feedback_invalid_request_provider(): array {
        return [
            'Logged user does not have permissions to view feedback.' => [
                'user',
                false,
                ['exceptionclass' => \required_capability_exception::class]
            ],
            'Guest user cannot view feedback.' => [
                'guest',
                false,
                ['exceptionclass' => \require_login_exception::class]
            ],
            'Request feedback for a grade item which is not a part of the course.' => [
                'admin',
                true,
                [
                    'exceptionclass' => \invalid_parameter_exception::class,
                    'exceptionmessage' => 'Course ID and item ID mismatch',
                ]
            ]
        ];
    }

    /**
     * Create a course, Student, grade item, and feedback record for endpoint tests.
     *
     * @param bool $managed Whether to mark the course as delivery-managed.
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass}
     */
    private function create_feedback_fixture(bool $managed): array {
        global $DB;

        $student = $this->getDataGenerator()->create_user([
            'firstname' => 'Feedback',
            'lastname' => 'Student',
            'email' => 'feedback-student@example.test',
        ]);
        $course = $this->getDataGenerator()->create_course([
            'idnumber' => $managed ? 'rtc-subject:' . (900000000 + (int) $student->id) : '',
            'showgrades' => 1,
        ]);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $gradeitem = $this->getDataGenerator()->create_grade_item([
            'itemname' => 'Feedback test item',
            'courseid' => (int) $course->id,
        ]);
        $this->getDataGenerator()->create_grade_grade([
            'itemid' => (int) $gradeitem->id,
            'userid' => (int) $student->id,
            'feedback' => 'RTC private feedback',
        ]);

        if ($managed) {
            $idnumber = 'rtc-user:' . (int) $student->id;
            $DB->set_field('user', 'idnumber', $idnumber, ['id' => (int) $student->id]);
            $DB->insert_record('local_rtcsync_user', (object) [
                'userid' => (int) $student->id,
                'idnumber' => $idnumber,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
            $DB->insert_record('local_rtcsync_deliverycourse', (object) [
                'courseid' => (int) $course->id,
                'coursevisible' => 1,
                'ready' => 1,
                'lastcmid' => 0,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        return [$course, $student, $gradeitem];
    }

    /**
     * Skip optional integration cases when the RTC Sync schema is not installed.
     *
     * @return void
     */
    private function require_rtcsync_delivery_schema(): void {
        global $DB;

        if (!class_exists('\\local_rtcsync\\local\\delivery_activity_access')
                || !$DB->get_manager()->table_exists('local_rtcsync_user')
                || !$DB->get_manager()->table_exists('local_rtcsync_deliverycourse')) {
            $this->markTestSkipped('The local_rtcsync delivery schema is not installed.');
        }
    }

    /**
     * Assert that the managed Student receives the Released-results restriction.
     *
     * @param int $courseid Course containing the grade item.
     * @param int $userid Managed Student whose feedback request is denied.
     * @param int $itemid Grade item to request.
     * @return void
     */
    private function assert_student_feedback_is_restricted(int $courseid, int $userid, int $itemid): void {
        try {
            get_feedback::execute($courseid, $userid, $itemid);
        } catch (\moodle_exception $exception) {
            $this->assertSame('studenthistoryreportonly', $exception->errorcode);
            return;
        }

        $this->fail('Managed Student feedback must be restricted to Released results.');
    }
}
