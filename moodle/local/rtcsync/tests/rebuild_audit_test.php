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

    public function test_acceptance_reads_current_protocol_state_without_legacy_table(): void
    {
        $this->resetAfterTest();
        set_config('enablewebservices', 0);
        set_config('webserviceprotocols', '');

        $acceptance = rebuild_audit::acceptance();

        $this->assertArrayHasKey('rest_protocol_enabled', $acceptance['checks']);
        $this->assertFalse($acceptance['checks']['rest_protocol_enabled']);
        $this->assertIsBool($acceptance['passed']);
        $this->assertSame('2026100205', $acceptance['required_plugin_version']);
        $this->assertSame('2026100205', $acceptance['plugin_version']);
        $this->assertTrue($acceptance['checks']['plugin_version_supported']);
        $this->assertTrue($acceptance['checks']['required_profile_fields_present']);
        $this->assertTrue($acceptance['checks']['profile_fields_match_contract']);
        $this->assertTrue($acceptance['checks']['unmanaged_credit_role_assignments_reviewed']);
        $this->assertTrue($acceptance['checks']['function_capabilities_match_contract']);
        $this->assertSame([], $acceptance['missing_profile_fields']);
        $this->assertSame([], $acceptance['profile_field_mismatches']);
        $this->assertSame([], $acceptance['function_capability_mismatches']);
        $this->assertNull($acceptance['unmanaged_credit_role_assignments_fingerprint']);
    }

    public function test_acceptance_rejects_external_function_capability_drift(): void
    {
        global $DB;

        $this->resetAfterTest();
        $DB->set_field(
            'external_functions',
            'capabilities',
            'moodle/user:create',
            ['name' => 'local_rtcsync_upsert_user']
        );

        $acceptance = rebuild_audit::acceptance();

        $this->assertFalse($acceptance['checks']['function_capabilities_match_contract']);
        $this->assertSame(
            ['moodle/user:create,moodle/user:update'],
            array_map(
                static fn(array $mismatch): string => implode(',', $mismatch['expected']),
                [$acceptance['function_capability_mismatches']['local_rtcsync_upsert_user']]
            )
        );
        $this->assertFalse($acceptance['passed']);
    }

    public function test_acceptance_rejects_an_older_plugin_contract(): void
    {
        $this->resetAfterTest();
        set_config('version', '2026100103', 'local_rtcsync');

        $acceptance = rebuild_audit::acceptance();

        $this->assertSame('2026100103', $acceptance['plugin_version']);
        $this->assertFalse($acceptance['checks']['plugin_version_supported']);
        $this->assertFalse($acceptance['passed']);
    }

    public function test_acceptance_rejects_missing_required_profile_field(): void
    {
        global $DB;

        $this->resetAfterTest();
        $field = $DB->get_record('user_info_field', ['shortname' => 'rtc_batch_code'], '*', MUST_EXIST);
        $DB->delete_records('user_info_field', ['id' => $field->id]);

        $acceptance = rebuild_audit::acceptance();

        $this->assertFalse($acceptance['checks']['required_profile_fields_present']);
        $this->assertContains('rtc_batch_code', $acceptance['missing_profile_fields']);
        $this->assertFalse($acceptance['passed']);
    }

    public function test_acceptance_rejects_profile_field_contract_drift(): void
    {
        global $DB;

        $this->resetAfterTest();
        $othercategory = (object) [
            'name' => 'Unexpected Profile Category',
            'sortorder' => 999,
        ];
        $othercategory->id = $DB->insert_record('user_info_category', $othercategory);
        $field = $DB->get_record('user_info_field', ['shortname' => 'rtc_batch_code'], '*', MUST_EXIST);
        $field->name = 'Unexpected batch field label';
        $field->datatype = 'textarea';
        $field->categoryid = $othercategory->id;
        $DB->update_record('user_info_field', $field);

        $acceptance = rebuild_audit::acceptance();

        $this->assertFalse($acceptance['checks']['profile_fields_match_contract']);
        $this->assertSame(
            ['name', 'datatype', 'category'],
            $acceptance['profile_field_mismatches']['rtc_batch_code']
        );
        $this->assertFalse($acceptance['passed']);
    }

    public function test_acceptance_requires_review_of_unmanaged_credit_course_roles(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-credit-course:legacy-501',
        ]);
        $user = $this->getDataGenerator()->create_user();
        $role = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        role_assign(
            (int) $role->id,
            (int) $user->id,
            \context_course::instance((int) $course->id)->id
        );

        $acceptance = rebuild_audit::acceptance();

        $this->assertFalse($acceptance['checks']['unmanaged_credit_role_assignments_reviewed']);
        $this->assertCount(1, $acceptance['unmanaged_credit_role_assignments']);
        $this->assertSame(
            'rtc-credit-course:legacy-501',
            $acceptance['unmanaged_credit_role_assignments'][0]['course_idnumber']
        );
        $this->assertSame((int) $user->id, $acceptance['unmanaged_credit_role_assignments'][0]['userid']);
        $this->assertNotNull($acceptance['unmanaged_credit_role_assignments_fingerprint']);
        $this->assertFalse($acceptance['passed']);

        set_config(
            'unmanaged_credit_role_assignments_acknowledged',
            $acceptance['unmanaged_credit_role_assignments_fingerprint'],
            'local_rtcsync'
        );
        $reviewed = rebuild_audit::acceptance();
        $this->assertTrue($reviewed['checks']['unmanaged_credit_role_assignments_reviewed']);
        $this->assertSame(
            $acceptance['unmanaged_credit_role_assignments_fingerprint'],
            $reviewed['unmanaged_credit_role_assignments_acknowledged']
        );
    }
}
