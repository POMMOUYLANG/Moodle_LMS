<?php

namespace local_rtcsync;

use local_rtcsync\local\rebuild_audit;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for guarded rebuild evidence collection.
 */
#[CoversClass(rebuild_audit::class)]
final class rebuild_audit_test extends \advanced_testcase
{
    public function test_inventory_separates_managed_content_and_blocks_destructive_rebuild(): void
    {
        $this->resetAfterTest();

        $managed = $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-subject:255',
        ]);
        $this->getDataGenerator()->create_course([
            'idnumber' => 'legacy-manual-course',
        ]);
        $this->getDataGenerator()->create_module('assign', [
            'course' => $managed->id,
        ]);

        $inventory = rebuild_audit::inventory();

        $this->assertSame(1, $inventory['managed']['courses']);
        $this->assertSame(1, $inventory['managed']['meaningful_activities']);
        $this->assertSame(1, $inventory['unmanaged']['courses']);
        $this->assertContains('managed.meaningful_activities', $inventory['blockers']);
        $this->assertFalse($inventory['safe_to_discard_without_content_migration']);
    }

    public function test_inventory_allows_empty_academic_structure(): void
    {
        $this->resetAfterTest();

        $inventory = rebuild_audit::inventory();

        $this->assertSame(0, $inventory['managed']['courses']);
        $this->assertSame(0, $inventory['unmanaged']['courses']);
        $this->assertSame([], $inventory['blockers']);
        $this->assertTrue($inventory['safe_to_discard_without_content_migration']);
    }
}