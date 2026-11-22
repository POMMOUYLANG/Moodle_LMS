<?php

namespace local_rtcsync;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

defined('MOODLE_INTERNAL') || die();

/**
 * Program category identity: several RTC programs can share one display name
 * inside one department. Each must keep its own category and idnumber.
 */
#[CoversClass(\local_rtcsync_external::class)]
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class category_identity_test extends \advanced_testcase
{
    private const NAME = 'Associate Degree in Midwife';

    protected function setUp(): void
    {
        parent::setUp();

        global $CFG;
        require_once($CFG->dirroot . '/local/rtcsync/externallib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('sendcoursewelcomemessage', 0, 'enrol_manual');
    }

    /** @return array<int, array<string, string>> */
    private function path(int $programid): array
    {
        return [
            ['idnumber' => 'rtc-program-' . $programid, 'name' => self::NAME],
            ['idnumber' => 'rtc-program-' . $programid . '-y1', 'name' => 'Year 1'],
        ];
    }

    /** Sync one subject course under the given program's category path. */
    private function sync(int $programid, int $subjectid): array
    {
        $path = $this->path($programid);
        $last = $path[count($path) - 1];

        return \local_rtcsync_external::upsert_course([
            'fullname' => 'Subject ' . $subjectid,
            'shortname' => 'RTC-CAT-' . $subjectid,
            'idnumber' => 'rtc-subject:' . $subjectid,
            'category_idnumber' => $last['idnumber'],
            'category_name' => $last['name'],
            'category_path' => $path,
            'visible' => 1,
        ]);
    }

    public function test_same_named_programs_keep_distinct_categories_and_idnumbers_across_alternating_syncs(): void
    {
        global $DB;

        $firsts = [];
        // Alternate the two programs several times, in both orders.
        foreach ([[3, 301], [5, 501], [3, 301], [5, 501], [5, 501], [3, 301], [4, 401], [6, 601], [3, 301], [5, 501]] as [$program, $subject]) {
            $saved = $this->sync($program, $subject);
            $firsts[$program] = $firsts[$program] ?? (int) $saved['categoryid'];
            $this->assertSame($firsts[$program], (int) $saved['categoryid'], 'Program ' . $program . ' changed its category node.');
        }

        foreach ([3, 4, 5, 6] as $program) {
            $this->assertSame(1, $DB->count_records('course_categories', ['idnumber' => 'rtc-program-' . $program]), 'program ' . $program);
        }
        $this->assertSame(4, $DB->count_records('course_categories', ['name' => self::NAME, 'parent' => 0]));
        $this->assertCount(4, array_unique($firsts), 'Each program must have its own year node.');

        // The year node of each program hangs below that program's own node.
        foreach ([3, 4, 5, 6] as $program) {
            $year = $DB->get_record('course_categories', ['idnumber' => 'rtc-program-' . $program . '-y1'], '*', MUST_EXIST);
            $parent = $DB->get_record('course_categories', ['id' => $year->parent], '*', MUST_EXIST);
            $this->assertSame('rtc-program-' . $program, (string) $parent->idnumber);
        }
        // Each course stays under its own program.
        $this->assertSame($firsts[3], (int) $DB->get_field('course', 'category', ['idnumber' => 'rtc-subject:301'], MUST_EXIST));
        $this->assertSame($firsts[5], (int) $DB->get_field('course', 'category', ['idnumber' => 'rtc-subject:501'], MUST_EXIST));
    }

    public function test_legacy_same_name_node_with_empty_idnumber_is_still_adopted(): void
    {
        global $DB;

        $legacy = \core_course_category::create(['name' => self::NAME, 'parent' => 0]);
        $this->assertSame('', (string) $DB->get_field('course_categories', 'idnumber', ['id' => $legacy->id]));

        $this->sync(3, 301);

        $this->assertSame(1, $DB->count_records('course_categories', ['name' => self::NAME, 'parent' => 0]));
        $this->assertSame('rtc-program-3', (string) $DB->get_field('course_categories', 'idnumber', ['id' => $legacy->id]));
        // The second program, with no further legacy node, gets its own category.
        $this->sync(5, 501);
        $this->assertSame(2, $DB->count_records('course_categories', ['name' => self::NAME, 'parent' => 0]));
        $this->assertSame('rtc-program-3', (string) $DB->get_field('course_categories', 'idnumber', ['id' => $legacy->id]));
    }

    public function test_a_same_name_node_with_any_non_empty_idnumber_is_never_rewritten(): void
    {
        global $DB;

        $manual = \core_course_category::create(['name' => self::NAME, 'idnumber' => 'MIDWIFE-MANUAL', 'parent' => 0]);

        $this->sync(5, 501);

        $this->assertSame('MIDWIFE-MANUAL', (string) $DB->get_field('course_categories', 'idnumber', ['id' => $manual->id]));
        $created = $DB->get_record('course_categories', ['idnumber' => 'rtc-program-5'], '*', MUST_EXIST);
        $this->assertNotSame((int) $manual->id, (int) $created->id);
    }

    public function test_repeated_sync_is_idempotent_and_keeps_the_same_ids(): void
    {
        global $DB;

        $first = $this->sync(3, 301);
        $categories = $DB->count_records('course_categories');
        $ids = array_keys($DB->get_records('course_categories', null, 'id', 'id'));

        $second = $this->sync(3, 301);
        $third = $this->sync(3, 301);

        $this->assertSame((int) $first['id'], (int) $second['id']);
        $this->assertSame((int) $first['categoryid'], (int) $second['categoryid']);
        $this->assertSame((int) $first['categoryid'], (int) $third['categoryid']);
        $this->assertSame($categories, $DB->count_records('course_categories'));
        $this->assertSame($ids, array_keys($DB->get_records('course_categories', null, 'id', 'id')));
    }
}
