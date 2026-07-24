<?php

namespace local_rtcsync;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/rtcsync/externallib.php');

final class externallib_test extends \advanced_testcase
{
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
}
