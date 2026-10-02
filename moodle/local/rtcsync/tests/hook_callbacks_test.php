<?php

namespace local_rtcsync;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for contextual Moodle user guidance.
 */
final class hook_callbacks_test extends \advanced_testcase
{
    public function test_group_guide_requires_the_right_page_and_capability(): void
    {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();

        // Moodle distributions may ship editingteacher without group management.
        // Grant the capability for this course so the test exercises the
        // page/capability gate rather than depending on a site's role preset.
        $teacherrole = $DB->get_record('role', ['shortname' => 'editingteacher'], '*', MUST_EXIST);
        $coursecontext = \context_course::instance((int) $course->id);
        assign_capability(
            'moodle/course:managegroups',
            CAP_ALLOW,
            (int) $teacherrole->id,
            $coursecontext->id,
            true
        );
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $groupPage = new \moodle_page();
        $groupPage->set_url(new \moodle_url('/group/index.php', ['id' => $course->id]));
        $this->assertTrue(hook_callbacks::should_show_group_guide($groupPage, $course));

        $coursePage = new \moodle_page();
        $coursePage->set_url(new \moodle_url('/course/view.php', ['id' => $course->id]));
        $this->assertFalse(hook_callbacks::should_show_group_guide($coursePage, $course));
    }

    public function test_student_does_not_receive_group_management_guide(): void
    {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        $page = new \moodle_page();
        $page->set_url(new \moodle_url('/group/index.php', ['id' => $course->id]));
        $this->assertFalse(hook_callbacks::should_show_group_guide($page, $course));
    }

    public function test_campus_label_follows_moodle_host(): void
    {
        global $CFG;

        $this->resetAfterTest();
        $original = $CFG->wwwroot;

        $CFG->wwwroot = 'https://lms.kc-rtc-edu.com';
        $this->assertSame('RTC KC', hook_callbacks::campus_label());
        $CFG->wwwroot = 'https://lms.kampot-rtc-edu.com';
        $this->assertSame('RTC KP', hook_callbacks::campus_label());
        $CFG->wwwroot = 'https://lms.bb-rtc-edu.com';
        $this->assertSame('RTC BB', hook_callbacks::campus_label());

        $CFG->wwwroot = $original;
    }
}

