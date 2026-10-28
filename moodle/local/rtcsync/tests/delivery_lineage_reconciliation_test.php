<?php

namespace local_rtcsync;

use PHPUnit\Framework\Attributes\CoversClass;
use local_rtcsync\local\delivery_activity_access;
use local_rtcsync\local\delivery_lineage_evidence_bundle;
use local_rtcsync\local\delivery_lineage_reconciliation;
use local_rtcsync\local\delivery_lineage_reconciliation_verifier;
use local_rtcsync\local\student_history;

defined('MOODLE_INTERNAL') || die();

/** Regressions for reviewed delivery-lineage reconciliation and quarantine. */
#[CoversClass(delivery_lineage_reconciliation::class)]
final class delivery_lineage_reconciliation_test extends \advanced_testcase
{
    private function sourceSnapshotPath(): string
    {
        return __DIR__ . '/fixtures/lineage-source-backup.txt';
    }

    private function sourceSnapshotHash(): string
    {
        return hash_file('sha256', $this->sourceSnapshotPath());
    }

    private function createTarget(string $suffix, int $deliveryid): array
    {
        global $DB;

        set_config('enableavailability', 1);
        $courseidnumber = 'rtc-subject:' . (900000 + random_int(1, 99999));
        $course = $this->getDataGenerator()->create_course([
            'idnumber' => $courseidnumber,
            'showgrades' => 1,
            'visible' => 1,
        ]);
        $grouping = $this->getDataGenerator()->create_grouping([
            'courseid' => (int) $course->id,
            'name' => 'Delivery ' . $deliveryid,
            'idnumber' => 'rtc-delivery-grouping:' . $deliveryid,
        ]);
        $module = $this->getDataGenerator()->create_module('quiz', [
            'course' => (int) $course->id,
            'name' => 'Activity ' . $suffix,
            'grade' => 100,
        ]);
        $cmid = (int) $module->cmid;
        $DB->set_field('course_modules', 'groupingid', (int) $grouping->id, ['id' => $cmid]);
        delivery_activity_access::protect_course_module($cmid, false);
        $cm = $DB->get_record_sql(
            'SELECT cm.id, cm.course, cm.module, cm.instance, cm.idnumber, m.name AS modname
               FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = :cmid',
            ['cmid' => $cmid],
            MUST_EXIST
        );

        return [
            'course' => $course,
            'course_idnumber' => $courseidnumber,
            'grouping' => $grouping,
            'module' => $module,
            'cm' => $cm,
            'deliveryid' => $deliveryid,
        ];
    }

    private function manifest(
        array $target,
        string $decision = 'current',
        ?array $deliveryids = null,
        ?string $manifestid = null
    ): string
    {
        $identity = [
            'course_idnumber' => $target['course_idnumber'],
            'cmid' => (int) $target['cm']->id,
            'modname' => (string) $target['cm']->modname,
            'instanceid' => (int) $target['cm']->instance,
            'idnumber' => (string) $target['cm']->idnumber,
        ];
        return json_encode([
            'schema_version' => 1,
            'manifest_id' => $manifestid ?? 'test-' . bin2hex(random_bytes(8)),
            'course_idnumber' => $target['course_idnumber'],
            'source_snapshot_sha256' => $this->sourceSnapshotHash(),
            'source_plugin_version' => 2026102700,
            'review' => [
                'approved_by' => 'RTC Data Owner',
                'approved_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 1),
                'approval_reference' => 'DATA-REVIEW-TEST',
            ],
            'entries' => [[
                'source' => $identity,
                'target' => $identity,
                'decision' => $decision,
                'delivery_ids' => $deliveryids ?? [$target['deliveryid']],
                'reason' => 'Reviewed against the fixture source snapshot.',
            ]],
            'grade_items' => [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Build an append-only v2 child containing only the parent's quarantined records. */
    private function amendmentManifest(
        string $parentjson,
        string $manifestid,
        string $moduledecision = 'quarantine',
        ?array $deliveryids = null
    ): string
    {
        $manifest = json_decode($parentjson, true);
        $parentid = (string) $manifest['manifest_id'];
        $manifest['schema_version'] = 2;
        $manifest['manifest_id'] = $manifestid;
        $manifest['amendment'] = [
            'parent_manifest_id' => $parentid,
            'parent_manifest_sha256' => hash('sha256', $parentjson),
        ];
        $manifest['review'] = [
            'approved_by' => 'RTC Data Owner',
            'approved_at' => gmdate('Y-m-d\\TH:i:s\\Z', time()),
            'approval_reference' => 'DATA-AMENDMENT-REVIEW-TEST',
        ];
        $manifest['entries'] = array_values(array_filter($manifest['entries'], static function(array $entry): bool {
            return $entry['decision'] === 'quarantine';
        }));
        foreach ($manifest['entries'] as &$entry) {
            $entry['decision'] = $moduledecision;
            $entry['delivery_ids'] = $deliveryids ?? [];
            $entry['reason'] = 'Reviewed parent quarantine amendment.';
        }
        unset($entry);
        $manifest['grade_items'] = array_values(array_filter($manifest['grade_items'], static function(array $entry): bool {
            return $entry['decision'] === 'quarantine';
        }));
        foreach ($manifest['grade_items'] as &$entry) {
            $entry['decision'] = 'quarantine';
            $entry['delivery_id'] = 0;
            $entry['reason'] = 'Reviewed parent grade quarantine remains unresolved.';
        }
        unset($entry);
        return json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function createUnverifiedGradeItem(array $target, string $idnumber): \grade_item
    {
        global $DB;

        $item = new \grade_item([
            'courseid' => (int) $target['course']->id,
            'itemname' => 'Unresolved delivery grade',
            'itemtype' => 'manual',
            'itemmodule' => null,
            'iteminstance' => null,
            'itemnumber' => 0,
            'idnumber' => $idnumber,
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
        ], false);
        $item->insert('lineage-amendment-test');
        $DB->insert_record('local_rtcsync_deliverygrade', (object) [
            'gradeitemid' => (int) $item->id,
            'courseid' => (int) $target['course']->id,
            'deliveryid' => (int) $target['deliveryid'],
            'timecreated' => time(),
            'timemodified' => time(),
            'lineageverified' => 0,
            'lineagemanifestid' => null,
        ]);
        return $item;
    }

    private function gradeQuarantineEntry(array $target, \grade_item $item): array
    {
        $identity = [
            'course_idnumber' => $target['course_idnumber'],
            'itemid' => (int) $item->id,
            'itemtype' => 'manual',
            'itemmodule' => '',
            'iteminstance' => 0,
            'idnumber' => (string) $item->idnumber,
        ];
        return [
            'source' => $identity,
            'target' => $identity,
            'decision' => 'quarantine',
            'delivery_id' => 0,
            'reason' => 'Legacy grade lineage requires owner review.',
        ];
    }

    private function applyManifest(
        string $json,
        string $planhash,
        string $operator = 'local-test-operator',
        ?string $sourceartifactpath = null
    ): array
    {
        $manifest = json_decode($json, true);
        return delivery_lineage_reconciliation::apply(
            $json,
            $planhash,
            $operator,
            $this->provenance($manifest),
            $sourceartifactpath ?? $this->sourceSnapshotPath()
        );
    }

    private function provenance(array $manifest): array
    {
        return [
            'approved_by' => $manifest['review']['approved_by'],
            'approved_at' => $manifest['review']['approved_at'],
            'approval_reference' => $manifest['review']['approval_reference'],
            'source_snapshot_sha256' => $manifest['source_snapshot_sha256'],
            'source_plugin_version' => $manifest['source_plugin_version'],
        ];
    }

    private function sampleEvidenceVerification(): array
    {
        $manifestid = 'sample-evidence-root';
        $manifesthash = str_repeat('a', 64);
        $sourcehash = str_repeat('c', 64);
        $planhash = str_repeat('b', 64);
        return [
            'status' => 'match',
            'clean' => true,
            'exit_code' => 0,
            'import' => [
                'manifest_id' => $manifestid,
                'manifest_sha256' => $manifesthash,
                'parent_manifest_id' => '',
                'parent_manifest_sha256' => '',
                'apply_plan_sha256' => $planhash,
                'source_snapshot_sha256' => $sourcehash,
                'source_plugin_version' => 2026102700,
                'manifest_schema_version' => 1,
                'course_id' => 12345,
                'applied_at' => 100,
                'installed_plugin_version' => 2026103100,
                'database_schema_version' => 2026103100,
            ],
            'lineage_chain' => [[
                'manifest_id' => $manifestid,
                'manifest_sha256' => $manifesthash,
                'parent_manifest_id' => '',
                'parent_manifest_sha256' => '',
                'entry_count' => 1,
                'amendment' => false,
                'apply_plan_sha256' => $planhash,
                'source_snapshot_sha256' => $sourcehash,
                'source_artifact_sha256' => $sourcehash,
                'source_artifact_verified' => true,
                'source_artifact_verified_at' => 90,
                'source_plugin_version' => 2026102700,
                'manifest_schema_version' => 1,
                'applied_at' => 100,
            ]],
            'record_outcomes' => [[
                'manifest_id' => $manifestid,
                'record_type' => 'module',
                'target_id' => 987,
                'decision' => 'quarantine',
                'delivery_ids' => [],
                'entry_sha256' => str_repeat('d', 64),
                'outcome' => 'target_matches_audit',
                'fields' => [],
            ]],
            'source_artifact_verification' => [
                'verified' => true,
                'sha256' => $sourcehash,
                'verified_at' => 90,
            ],
            'counts' => [
                'expected_records' => 1,
                'verified_records' => 1,
                'missing_audit_records' => 0,
            ],
        ];
    }

    public function test_reused_legacy_module_is_quarantined_and_its_grade_is_not_released(): void
    {
        global $DB;

        $this->resetAfterTest();
        $target = $this->createTarget('reused', 910001);
        $user = $this->getDataGenerator()->create_user([
            'username' => 'lineage-reused-student',
            'email' => 'lineage-reused-student@example.test',
            'idnumber' => 'LINEAGE-REUSED-STUDENT',
        ]);
        $DB->insert_record('local_rtcsync_user', (object) [
            'userid' => (int) $user->id,
            'idnumber' => (string) $user->idnumber,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('local_rtcsync_deliverycmhist', (object) [
            'cmid' => (int) $target['cm']->id,
            'courseid' => (int) $target['course']->id,
            'deliveryid' => 909998,
            'timecreated' => time(),
            'lineageverified' => 0,
            'lineagemanifestid' => null,
        ]);
        $DB->insert_record('local_rtcsync_deliverycmhist', (object) [
            'cmid' => (int) $target['cm']->id,
            'courseid' => (int) $target['course']->id,
            'deliveryid' => 909999,
            'timecreated' => time(),
            'lineageverified' => 0,
            'lineagemanifestid' => null,
        ]);
        $manual = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['courseid' => (int) $target['course']->id, 'enrol' => 'manual'], '*', IGNORE_MISSING);
        if (!$instance) {
            $manual->add_default_instance($target['course']);
            $instance = $DB->get_record('enrol', ['courseid' => (int) $target['course']->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        }
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        $manual->enrol_user($instance, (int) $user->id, (int) $studentrole->id, 0, 0, ENROL_USER_SUSPENDED);
        student_history::sync_marker(
            (int) $user->id,
            (int) $target['course']->id,
            1,
            'delivery_participation',
            941001,
            (int) $target['deliveryid']
        );
        foreach ([909998, 909999] as $ambiguousdeliveryid) {
            student_history::sync_marker(
                (int) $user->id,
                (int) $target['course']->id,
                1,
                'delivery_participation',
                941002 + $ambiguousdeliveryid - 909998,
                $ambiguousdeliveryid
            );
        }
        $item = \grade_item::fetch([
            'courseid' => (int) $target['course']->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => (int) $target['module']->id,
            'itemnumber' => 0,
        ]);
        $this->assertNotFalse($item);
        $item->update_final_grade((int) $user->id, 88.0, 'lineage-test');
        $manualitem = new \grade_item([
            'courseid' => (int) $target['course']->id,
            'itemname' => 'Unreviewed legacy manual result',
            'itemtype' => 'manual',
            'itemmodule' => null,
            'iteminstance' => null,
            'itemnumber' => 0,
            'idnumber' => 'legacy-manual-result',
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
        ], false);
        $manualitem->insert('lineage-test');
        $manualitem->update_final_grade((int) $user->id, 92.0, 'lineage-test');
        $DB->insert_record('local_rtcsync_deliverygrade', (object) [
            'gradeitemid' => (int) $manualitem->id,
            'courseid' => (int) $target['course']->id,
            'deliveryid' => (int) $target['deliveryid'],
            'timecreated' => time(),
            'timemodified' => time(),
            'lineageverified' => 0,
            'lineagemanifestid' => null,
        ]);

        $mapping = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => (int) $target['cm']->id], '*', MUST_EXIST);
        $this->assertSame(delivery_activity_access::LINEAGE_UNREVIEWED, (int) $mapping->lineagestatus);
        $this->assertSame(0, (int) $mapping->deliveryid);
        $this->assertSame(-(int) $target['cm']->id, (int) $mapping->groupingid);
        $currentclaim = delivery_lineage_reconciliation::preview($this->manifest($target, 'current'));
        $this->assertFalse($currentclaim['valid']);
        $this->assertStringContainsString('already has historical associations', implode(' ', $currentclaim['errors']));

        $ready = false;
        for ($attempt = 0; $attempt < 5 && !$ready; $attempt++) {
            $ready = delivery_activity_access::prepare_course((int) $target['course']->id, 1);
        }
        $this->assertTrue($ready, 'The bounded legacy module backfill should finish with the course guarded.');
        $mapping = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => (int) $target['cm']->id], '*', MUST_EXIST);
        $this->assertSame(delivery_activity_access::LINEAGE_UNREVIEWED, (int) $mapping->lineagestatus);
        $this->assertSame(0, (int) $mapping->deliveryid);
        $this->assertSame(-(int) $target['cm']->id, (int) $mapping->groupingid);
        $this->assertFalse(delivery_activity_access::student_module_content_is_in_current_delivery(
            (int) $target['course']->id,
            (int) $target['cm']->id,
            (int) $user->id
        ));
        $this->assertSame(2, $DB->count_records_select(
            'local_rtcsync_deliverycmhist',
            'cmid = :cmid AND courseid = :courseid AND lineageverified = 0',
            ['cmid' => (int) $target['cm']->id, 'courseid' => (int) $target['course']->id]
        ));

        $this->setUser($user);
        $results = (new student_history())->visible_results();
        $this->assertSame([], $results['results']);
    }

    public function test_source_artifact_is_rechecked_after_preview_and_digest_only_is_audited(): void
    {
        global $DB;

        $this->resetAfterTest();
        $target = $this->createTarget('source-artifact', 919901);
        $json = $this->manifest($target, 'current', null, 'source-artifact-manifest');
        $defaultpreview = delivery_lineage_reconciliation::preview($json);
        $this->assertFalse($defaultpreview['source_artifact_verification']['checked']);
        $contents = file_get_contents($this->sourceSnapshotPath());
        $changedpath = tempnam(sys_get_temp_dir(), 'rtcsync-lineage-changed-');
        $matchingpath = tempnam(sys_get_temp_dir(), 'rtcsync-lineage-matching-');
        $this->assertNotFalse($changedpath);
        $this->assertNotFalse($matchingpath);
        try {
            $this->assertNotFalse(file_put_contents($changedpath, $contents));
            $preview = delivery_lineage_reconciliation::preview($json, $changedpath);
            $this->assertTrue($preview['valid'], implode('; ', $preview['errors']));
            $this->assertTrue($preview['source_artifact_verification']['checked']);
            $this->assertTrue($preview['source_artifact_verification']['verified']);
            $this->assertSame($this->sourceSnapshotHash(), $preview['source_artifact_verification']['sha256']);

            $writesbefore = $DB->perf_get_writes();
            $this->assertNotFalse(file_put_contents($changedpath, $contents . 'changed after preview'));
            try {
                $this->applyManifest($json, $preview['plan_sha256'], 'local-test-operator', $changedpath);
                $this->fail('Apply must recheck the artifact after dry-run and reject changed bytes.');
            } catch (\invalid_parameter_exception $exception) {
                $this->assertStringContainsString('could not be verified', $exception->getMessage());
                $this->assertStringNotContainsString($changedpath, $exception->getMessage());
                $this->assertStringNotContainsString('changed after preview', $exception->getMessage());
            }
            $this->assertSame($writesbefore, $DB->perf_get_writes());
            $this->assertFalse($DB->record_exists('local_rtcsync_linemanifest', ['manifestid' => 'source-artifact-manifest']));
            $this->assertSame(0, $DB->count_records('local_rtcsync_lineageaudit', ['manifestid' => 'source-artifact-manifest']));

            $this->assertNotFalse(file_put_contents($matchingpath, $contents));
            $applied = $this->applyManifest($json, $preview['plan_sha256'], 'local-test-operator', $matchingpath);
            $this->assertTrue($applied['applied']);
            $record = $DB->get_record('local_rtcsync_linemanifest', [
                'manifestid' => 'source-artifact-manifest',
            ], '*', MUST_EXIST);
            $this->assertSame($this->sourceSnapshotHash(), $record->sourcefilehash);
            $this->assertSame(1, (int) $record->sourcefileverified);
            $this->assertGreaterThan(0, (int) $record->sourcefileverifiedat);

            $repeat = $this->applyManifest($json, $preview['plan_sha256'], 'local-test-operator', $matchingpath);
            $this->assertTrue($repeat['already_applied']);
            $this->assertTrue($repeat['source_artifact_verification']['verified']);
            $this->assertSame(1, $DB->count_records('local_rtcsync_linemanifest', [
                'manifestid' => 'source-artifact-manifest',
            ]));
            $verified = delivery_lineage_reconciliation_verifier::verify('source-artifact-manifest', $json);
            $this->assertSame('match', $verified['status']);
            $this->assertTrue($verified['source_artifact_verification']['verified']);
            $this->assertSame($this->sourceSnapshotHash(), $verified['source_artifact_verification']['sha256']);
            $encoded = json_encode([$preview, $applied, $verified], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString($changedpath, $encoded);
            $this->assertStringNotContainsString($matchingpath, $encoded);
            $this->assertStringNotContainsString('RTC Sync delivery-lineage test artifact', $encoded);
        } finally {
            @unlink($changedpath);
            @unlink($matchingpath);
        }
    }

    public function test_apply_rejects_absent_nonregular_and_mismatched_source_artifacts_without_writes(): void
    {
        global $DB;

        $this->resetAfterTest();
        $target = $this->createTarget('source-artifact-invalid', 919902);
        $json = $this->manifest($target, 'current', null, 'source-artifact-invalid-manifest');
        $preview = delivery_lineage_reconciliation::preview($json);
        $this->assertTrue($preview['valid'], implode('; ', $preview['errors']));
        $directory = tempnam(sys_get_temp_dir(), 'rtcsync-lineage-dir-');
        $this->assertNotFalse($directory);
        @unlink($directory);
        $this->assertTrue(mkdir($directory));
        $mismatchpath = tempnam(sys_get_temp_dir(), 'rtcsync-lineage-mismatch-');
        $this->assertNotFalse($mismatchpath);
        $this->assertNotFalse(file_put_contents($mismatchpath, 'not the reviewed snapshot'));
        try {
            $mismatchpreview = delivery_lineage_reconciliation::preview($json, $mismatchpath);
            $this->assertFalse($mismatchpreview['valid']);
            $this->assertFalse($mismatchpreview['source_artifact_verification']['verified']);
            $this->assertStringNotContainsString($mismatchpath, json_encode($mismatchpreview));
            foreach ([$directory . DIRECTORY_SEPARATOR . 'absent', $directory, $mismatchpath] as $path) {
                $writesbefore = $DB->perf_get_writes();
                try {
                    $this->applyManifest($json, $preview['plan_sha256'], 'local-test-operator', $path);
                    $this->fail('Apply must reject an absent, nonregular, or mismatched artifact.');
                } catch (\invalid_parameter_exception $exception) {
                    $this->assertStringContainsString('could not be verified', $exception->getMessage());
                    $this->assertStringNotContainsString($path, $exception->getMessage());
                }
                $this->assertSame($writesbefore, $DB->perf_get_writes());
                $this->assertFalse($DB->record_exists('local_rtcsync_linemanifest', [
                    'manifestid' => 'source-artifact-invalid-manifest',
                ]));
                $this->assertSame(0, $DB->count_records('local_rtcsync_lineageaudit', [
                    'manifestid' => 'source-artifact-invalid-manifest',
                ]));
            }
        } finally {
            @unlink($mismatchpath);
            @rmdir($directory);
        }
    }

    public function test_historical_manifest_is_applied_once_and_keeps_activity_locked(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $target = $this->createTarget('historic', 910002);
        $user = $this->getDataGenerator()->create_user([
            'username' => 'lineage-historic-student',
            'email' => 'lineage-historic-student@example.test',
            'idnumber' => 'LINEAGE-HISTORIC-STUDENT',
        ]);
        $DB->insert_record('local_rtcsync_user', (object) [
            'userid' => (int) $user->id,
            'idnumber' => (string) $user->idnumber,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $manual = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', [
            'courseid' => (int) $target['course']->id,
            'enrol' => 'manual',
        ], '*', IGNORE_MISSING);
        if (!$instance) {
            $manual->add_default_instance($target['course']);
            $instance = $DB->get_record('enrol', [
                'courseid' => (int) $target['course']->id,
                'enrol' => 'manual',
            ], '*', MUST_EXIST);
        }
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        $manual->enrol_user($instance, (int) $user->id, (int) $studentrole->id, 0, 0, ENROL_USER_SUSPENDED);
        student_history::sync_marker(
            (int) $user->id,
            (int) $target['course']->id,
            1,
            'delivery_participation',
            942001,
            (int) $target['deliveryid']
        );
        $item = \grade_item::fetch([
            'courseid' => (int) $target['course']->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => (int) $target['module']->id,
            'itemnumber' => 0,
        ]);
        $this->assertNotFalse($item);
        $item->update_final_grade((int) $user->id, 73.0, 'lineage-test');

        $ready = false;
        for ($attempt = 0; $attempt < 5 && !$ready; $attempt++) {
            $ready = delivery_activity_access::prepare_course((int) $target['course']->id, 1);
        }
        $this->assertTrue($ready);
        $this->setUser($user);
        $historyservice = new student_history();
        $this->assertSame([], $historyservice->visible_results()['results']);
        $this->assertFalse(delivery_activity_access::student_module_content_is_in_current_delivery(
            (int) $target['course']->id,
            (int) $target['cm']->id,
            (int) $user->id
        ));

        $json = $this->manifest($target, 'historical');
        $preview = delivery_lineage_reconciliation::preview($json);
        $this->assertTrue($preview['valid'], implode('; ', $preview['errors']));

        $this->setAdminUser();
        $applied = $this->applyManifest($json, $preview['plan_sha256']);
        $this->assertTrue($applied['applied']);
        $mapping = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => (int) $target['cm']->id], '*', MUST_EXIST);
        $historymapping = $DB->get_record('local_rtcsync_deliverycmhist', [
            'cmid' => (int) $target['cm']->id,
            'courseid' => (int) $target['course']->id,
            'deliveryid' => (int) $target['deliveryid'],
        ], '*', MUST_EXIST);
        $this->assertSame(delivery_activity_access::LINEAGE_HISTORICAL, (int) $mapping->lineagestatus);
        $this->assertSame(1, (int) $historymapping->lineageverified);
        $this->assertSame(0, (int) $mapping->deliveryid);
        $this->assertSame(1, $DB->count_records('local_rtcsync_lineageaudit', ['manifestid' => $preview['manifest_id']]));

        $this->setUser($user);
        $results = $historyservice->visible_results();
        $this->assertCount(1, $results['results']);
        $this->assertSame(73.0, $results['results'][0]['grade']);
        $this->assertFalse(delivery_activity_access::student_module_content_is_in_current_delivery(
            (int) $target['course']->id,
            (int) $target['cm']->id,
            (int) $user->id
        ));

        $this->setAdminUser();
        $repeat = $this->applyManifest($json, $preview['plan_sha256']);
        $this->assertFalse($repeat['applied']);
        $this->assertTrue($repeat['already_applied']);
        $this->assertSame($applied['mapping_counts'], $repeat['mapping_counts']);
        $this->assertSame($applied['disposition_counts'], $repeat['disposition_counts']);
        $this->assertSame(1, $DB->count_records('local_rtcsync_linemanifest', ['manifestid' => $preview['manifest_id']]));
    }

    public function test_current_reconciliation_releases_only_the_approved_scoped_activity_and_grade(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $target = $this->createTarget('current-release', 910003);
        $user = $this->getDataGenerator()->create_user([
            'username' => 'lineage-current-student',
            'email' => 'lineage-current-student@example.test',
            'idnumber' => 'LINEAGE-CURRENT-STUDENT',
        ]);
        $DB->insert_record('local_rtcsync_user', (object) [
            'userid' => (int) $user->id,
            'idnumber' => (string) $user->idnumber,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $manual = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', [
            'courseid' => (int) $target['course']->id,
            'enrol' => 'manual',
        ], '*', IGNORE_MISSING);
        if (!$instance) {
            $manual->add_default_instance($target['course']);
            $instance = $DB->get_record('enrol', [
                'courseid' => (int) $target['course']->id,
                'enrol' => 'manual',
            ], '*', MUST_EXIST);
        }
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        $manual->enrol_user($instance, (int) $user->id, (int) $studentrole->id, 0, 0, ENROL_USER_ACTIVE);
        $item = \grade_item::fetch([
            'courseid' => (int) $target['course']->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => (int) $target['module']->id,
            'itemnumber' => 0,
        ]);
        $this->assertNotFalse($item);
        $item->update_final_grade((int) $user->id, 81.0, 'lineage-test');

        $ready = false;
        for ($attempt = 0; $attempt < 5 && !$ready; $attempt++) {
            $ready = delivery_activity_access::prepare_course((int) $target['course']->id, 1);
        }
        $this->assertTrue($ready);
        $this->assertFalse(delivery_activity_access::student_module_content_is_in_current_delivery(
            (int) $target['course']->id,
            (int) $target['cm']->id,
            (int) $user->id
        ));

        $this->setAdminUser();
        $manual->update_user_enrol($instance, (int) $user->id, ENROL_USER_ACTIVE);
        $scope = delivery_activity_access::with_course_access_lock(
            (int) $target['course']->id,
            static function() use ($DB, $target, $user): array {
                $transaction = $DB->start_delegated_transaction();
                $result = delivery_activity_access::sync_student_delivery_scope_locked(
                    (int) $target['course']->id,
                    (int) $user->id,
                    [(int) $target['deliveryid']],
                    20261028000000001
                );
                $transaction->allow_commit();
                return $result;
            }
        );
        $this->assertSame([(int) $target['deliveryid']], $scope['deliveryids']);

        $group = $this->getDataGenerator()->create_group([
            'courseid' => (int) $target['course']->id,
            'idnumber' => 'rtc-delivery-class-group:' . (int) $target['deliveryid'] . ':1',
        ]);
        $DB->insert_record('groupings_groups', (object) [
            'groupingid' => (int) $target['grouping']->id,
            'groupid' => (int) $group->id,
        ]);
        groups_add_member((int) $group->id, (int) $user->id);
        student_history::sync_marker(
            (int) $user->id,
            (int) $target['course']->id,
            1,
            'delivery_participation',
            943001,
            (int) $target['deliveryid']
        );

        $this->setUser($user);
        $history = new student_history();
        $this->assertSame([], $history->visible_results()['results']);
        $this->assertFalse(delivery_activity_access::student_module_content_is_in_current_delivery(
            (int) $target['course']->id,
            (int) $target['cm']->id,
            (int) $user->id
        ));

        $json = $this->manifest($target, 'current', null, 'current-release');
        $preview = delivery_lineage_reconciliation::preview($json);
        $this->assertTrue($preview['valid'], implode('; ', $preview['errors']));
        $this->setAdminUser();
        $applied = $this->applyManifest($json, $preview['plan_sha256']);
        $this->assertTrue($applied['applied']);

        $mapping = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => (int) $target['cm']->id], '*', MUST_EXIST);
        $this->assertSame(delivery_activity_access::LINEAGE_CURRENT, (int) $mapping->lineagestatus);
        $this->assertSame('current-release', (string) $mapping->lineagemanifestid);
        $verified = delivery_lineage_reconciliation_verifier::verify('current-release', $json);
        $this->assertSame('match', $verified['status'], json_encode($verified['issues']));
        $this->setUser($user);
        $results = $history->visible_results();
        $this->assertCount(1, $results['results']);
        $this->assertSame(81.0, $results['results'][0]['grade']);
        $this->assertTrue(delivery_activity_access::student_module_content_is_in_current_delivery(
            (int) $target['course']->id,
            (int) $target['cm']->id,
            (int) $user->id
        ));
        delivery_activity_access::require_student_module_access(
            (int) $target['course']->id,
            (int) $target['cm']->id,
            (int) $user->id
        );

        $gradebeforetransfer = $DB->get_record('grade_grades', [
            'itemid' => (int) $item->id,
            'userid' => (int) $user->id,
        ], '*', MUST_EXIST);
        $oldmarker = $DB->get_record('local_rtcsync_history', [
            'userid' => (int) $user->id,
            'courseid' => (int) $target['course']->id,
            'sourcetype' => 'delivery_participation',
            'sourceid' => 943001,
            'deliveryid' => (int) $target['deliveryid'],
        ], '*', MUST_EXIST);
        $nextdeliveryid = (int) $target['deliveryid'] + 1000000000;
        $this->getDataGenerator()->create_grouping([
            'courseid' => (int) $target['course']->id,
            'name' => 'Next active delivery',
            'idnumber' => 'rtc-delivery-grouping:' . $nextdeliveryid,
        ]);
        $transferscope = delivery_activity_access::with_course_access_lock(
            (int) $target['course']->id,
            static function() use ($DB, $target, $user, $nextdeliveryid): array {
                $transaction = $DB->start_delegated_transaction();
                $result = delivery_activity_access::sync_student_delivery_scope_locked(
                    (int) $target['course']->id,
                    (int) $user->id,
                    [$nextdeliveryid],
                    20261028000000002
                );
                $transaction->allow_commit();
                return $result;
            }
        );
        $this->assertSame([$nextdeliveryid], $transferscope['deliveryids']);
        $this->assertFalse($DB->record_exists('groups_members', [
            'groupid' => (int) $group->id,
            'userid' => (int) $user->id,
        ]), 'Transfer must prune the previous delivery group membership.');
        $this->assertFalse(delivery_activity_access::student_module_content_is_in_current_delivery(
            (int) $target['course']->id,
            (int) $target['cm']->id,
            (int) $user->id
        ));
        try {
            delivery_activity_access::require_student_module_access(
                (int) $target['course']->id,
                (int) $target['cm']->id,
                (int) $user->id
            );
            $this->fail('A reviewed prior-delivery activity must be inaccessible after transfer.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('activityiscurrentlyhidden', $exception->errorcode);
        }

        $resultsaftertransfer = $history->visible_results();
        $this->assertCount(1, $resultsaftertransfer['results']);
        $this->assertSame(81.0, $resultsaftertransfer['results'][0]['grade']);
        $this->assertEquals($gradebeforetransfer, $DB->get_record('grade_grades', [
            'itemid' => (int) $item->id,
            'userid' => (int) $user->id,
        ], '*', MUST_EXIST));
        $this->assertEquals($oldmarker, $DB->get_record('local_rtcsync_history', [
            'id' => (int) $oldmarker->id,
        ], '*', MUST_EXIST));

        $DB->set_field('user', 'suspended', 1, ['id' => (int) $user->id]);
        try {
            delivery_activity_access::require_student_module_access(
                (int) $target['course']->id,
                (int) $target['cm']->id,
                (int) $user->id
            );
            $this->fail('A locally suspended RTC account must not retain activity access.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('activityiscurrentlyhidden', $exception->errorcode);
        }
        try {
            $history->require_current_rtc_student([
                'id' => 42,
                'email' => (string) $user->email,
                'account_is_active' => false,
                'user_detail' => ['id_card' => (string) $user->idnumber],
                'roles' => ['Student'],
            ]);
            $this->fail('An inactive SMS account must not read released history through a retained session.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('studenthistorydenied', $exception->errorcode);
        }
    }

    public function test_preview_is_read_only_and_reports_only_mapping_counts_and_outcomes(): void
    {
        global $DB;

        $this->resetAfterTest();
        $target = $this->createTarget('preview-only', 910008);
        $before = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => (int) $target['cm']->id], '*', MUST_EXIST);
        $manifestjson = $this->manifest($target, 'quarantine', [], 'preview-only-manifest');
        $preview = delivery_lineage_reconciliation::preview($manifestjson);

        $this->assertTrue($preview['valid'], implode('; ', $preview['errors']));
        $this->assertSame(0, $preview['mapping_counts']['current_modules']);
        $this->assertSame(0, $preview['mapping_counts']['historical_modules']);
        $this->assertSame(0, $preview['mapping_counts']['mapped_grade_items']);
        $this->assertSame(1, $preview['disposition_counts']['quarantined_modules']);
        $this->assertSame([(int) $target['cm']->id], $preview['quarantine_outcomes']['module_cmids']);
        $this->assertSame([], $preview['quarantine_outcomes']['grade_item_ids']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $preview['plan_sha256']);
        $this->assertSame(0, $preview['conflict_count']);
        $this->assertSame([], $preview['conflicts']);
        $this->assertFalse($preview['approval_provenance']['authenticated']);
        $this->assertArrayNotHasKey('learner', $preview);
        $this->assertArrayNotHasKey('source', $preview);
        foreach (['userid', 'username', 'email', 'grade'] as $learnerfield) {
            $this->assertArrayNotHasKey($learnerfield, $preview);
        }

        $after = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => (int) $target['cm']->id], '*', MUST_EXIST);
        $this->assertEquals($before, $after);
        $this->assertSame(0, $DB->count_records('local_rtcsync_linemanifest', ['manifestid' => 'preview-only-manifest']));
        $this->assertSame(0, $DB->count_records('local_rtcsync_lineageaudit', ['manifestid' => 'preview-only-manifest']));
    }

    public function test_apply_rejects_target_snapshot_changed_after_preview(): void
    {
        global $DB;

        $this->resetAfterTest();
        $target = $this->createTarget('stale-plan-hash', 910009);
        $json = $this->manifest($target, 'current', null, 'stale-plan-hash');
        $preview = delivery_lineage_reconciliation::preview($json);
        $this->assertTrue($preview['valid'], implode('; ', $preview['errors']));
        $DB->set_field('local_rtcsync_deliverycm', 'groupingid', (int) $target['grouping']->id, [
            'cmid' => (int) $target['cm']->id,
        ]);

        try {
            $this->applyManifest($json, $preview['plan_sha256']);
            $this->fail('A changed mapping snapshot must reject the old dry-run hash.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('target state changed after dry run', $exception->getMessage());
        }

        $mapping = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => (int) $target['cm']->id], '*', MUST_EXIST);
        $this->assertSame(delivery_activity_access::LINEAGE_UNREVIEWED, (int) $mapping->lineagestatus);
        $this->assertFalse($DB->record_exists('local_rtcsync_linemanifest', ['manifestid' => 'stale-plan-hash']));
    }

    public function test_apply_requires_matching_approval_provenance_and_records_it(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $target = $this->createTarget('provenance', 910010);
        $json = $this->manifest($target, 'current', null, 'provenance-manifest');
        $manifest = json_decode($json, true);
        $preview = delivery_lineage_reconciliation::preview($json);
        $this->assertTrue($preview['valid'], implode('; ', $preview['errors']));
        $wrongprovenance = $this->provenance($manifest);
        $wrongprovenance['approval_reference'] = 'unreviewed-reference';

        try {
            delivery_lineage_reconciliation::apply(
                $json,
                $preview['plan_sha256'],
                'local-test-operator',
                $wrongprovenance,
                $this->sourceSnapshotPath()
            );
            $this->fail('Apply must reject provenance that differs from the reviewed manifest.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('must exactly match', $exception->getMessage());
        }

        $this->assertFalse($DB->record_exists('local_rtcsync_linemanifest', ['manifestid' => 'provenance-manifest']));
        $this->assertSame(0, $DB->count_records('local_rtcsync_lineageaudit', ['manifestid' => 'provenance-manifest']));
        $applied = $this->applyManifest($json, $preview['plan_sha256']);
        $this->assertTrue($applied['applied']);
        $this->assertFalse($applied['approval_provenance']['authenticated']);
        $this->assertSame(1, $applied['mapping_counts']['current_modules']);

        $record = $DB->get_record('local_rtcsync_linemanifest', ['manifestid' => 'provenance-manifest'], '*', MUST_EXIST);
        $this->assertSame('RTC Data Owner', (string) $record->approvedby);
        $this->assertSame('DATA-REVIEW-TEST', (string) $record->approvalref);
        $this->assertSame(strtotime($manifest['review']['approved_at']), (int) $record->approvedat);
        $this->assertSame(str_repeat('a', 64), (string) $record->sourcehash);
        $this->assertSame(2026102700, (int) $record->sourceversion);
        $this->assertSame('local-test-operator', (string) $record->operator);
        $this->assertSame($json, (string) $record->manifestjson);
        $this->assertSame($preview['plan_sha256'], (string) $record->applyplanhash);
    }

    public function test_failed_apply_rolls_back_mapping_and_manifest_when_audit_write_fails(): void
    {
        global $DB;

        $this->resetAfterTest();
        $target = $this->createTarget('failed-apply', 910011);
        $json = $this->manifest($target, 'current', null, 'failed-apply-manifest');
        $manifest = json_decode($json, true);
        $preview = delivery_lineage_reconciliation::preview($json);
        $this->assertTrue($preview['valid'], implode('; ', $preview['errors']));
        $before = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => (int) $target['cm']->id], '*', MUST_EXIST);
        // Simulate an orphaned partial audit collision. The per-entry audit
        // insert fails after the module update and must roll back the transaction.
        $DB->insert_record('local_rtcsync_lineageaudit', (object) [
            'manifestid' => 'failed-apply-manifest',
            'manifesthash' => hash('sha256', $json),
            'courseid' => (int) $target['course']->id,
            'recordtype' => 'module',
            'targetid' => (int) $target['cm']->id,
            'decision' => 'quarantine',
            'deliveryids' => '[]',
            'sourceidentity' => '{}',
            'targetidentity' => '{}',
            'reason' => 'Injected unique-key collision for rollback coverage.',
            'entryhash' => hash('sha256', 'injected-collision'),
            'resultstate' => '{}',
            'timecreated' => time(),
        ]);

        $failed = false;
        try {
            $this->applyManifest($json, $preview['plan_sha256']);
        } catch (\dml_write_exception $exception) {
            $failed = true;
        }
        $this->assertTrue($failed, 'The conflicting per-record audit row must fail apply.');

        $after = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => (int) $target['cm']->id], '*', MUST_EXIST);
        $this->assertEquals($before, $after);
        $this->assertFalse($DB->record_exists('local_rtcsync_linemanifest', ['manifestid' => 'failed-apply-manifest']));
        $this->assertSame(1, $DB->count_records('local_rtcsync_lineageaudit', ['manifestid' => 'failed-apply-manifest']));
    }

    public function test_conflicting_stale_and_ambiguous_manifests_fail_validation(): void
    {
        global $DB;

        $this->resetAfterTest();
        $target = $this->createTarget('stale', 910003);
        $json = $this->manifest($target, 'current', null, 'stale-manifest');
        $preview = delivery_lineage_reconciliation::preview($json);
        $this->assertTrue($preview['valid'], implode('; ', $preview['errors']));
        $DB->set_field('course_modules', 'idnumber', 'changed-after-review', ['id' => (int) $target['cm']->id]);
        try {
            $this->applyManifest($json, $preview['plan_sha256']);
            $this->fail('A changed target identity must invalidate a reviewed plan.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('target', $exception->getMessage());
        }

        $DB->set_field('course_modules', 'idnumber', (string) $target['cm']->idnumber, ['id' => (int) $target['cm']->id]);
        $collision = delivery_lineage_reconciliation::preview($json);
        $this->assertTrue($collision['valid']);
        $this->applyManifest($json, $collision['plan_sha256']);
        $changedcontent = json_decode($json, true);
        $changedcontent['entries'][0]['reason'] = 'Changed reviewer decision under a reused manifest id.';
        $conflict = delivery_lineage_reconciliation::preview(json_encode($changedcontent));
        $this->assertFalse($conflict['valid']);
        $this->assertStringContainsString('different content', implode(' ', $conflict['errors']));

        $ambiguous = json_decode($json, true);
        $ambiguous['entries'][0]['decision'] = 'historical';
        $ambiguous['entries'][0]['delivery_ids'] = [910003, 910004];
        $ambiguous['manifest_id'] = 'ambiguous-manifest';
        $this->getDataGenerator()->create_grouping([
            'courseid' => (int) $target['course']->id,
            'name' => 'Second historic delivery',
            'idnumber' => 'rtc-delivery-grouping:910004',
        ]);
        $ambiguouspreview = delivery_lineage_reconciliation::preview(json_encode($ambiguous));
        $this->assertFalse($ambiguouspreview['valid']);
        $this->assertStringContainsString('exactly one delivery', implode(' ', $ambiguouspreview['errors']));
    }

    public function test_wrong_course_module_and_delivery_references_are_rejected(): void
    {
        $this->resetAfterTest();
        $target = $this->createTarget('wrongrefs', 910005);
        $manifest = json_decode($this->manifest($target), true);
        $manifest['entries'][0]['source']['course_idnumber'] = 'rtc-subject:999999';
        $manifest['entries'][0]['target']['modname'] = 'assign';
        $manifest['entries'][0]['delivery_ids'] = [919999];
        $preview = delivery_lineage_reconciliation::preview(json_encode($manifest));
        $this->assertFalse($preview['valid']);
        $errors = implode(' ', $preview['errors']);
        $this->assertStringContainsString('stable course identity', $errors);
        $this->assertStringContainsString('does not exactly identify a module', $errors);
        $this->assertStringContainsString('without exactly one matching target-course grouping', $errors);

        $wrongcourse = json_decode($this->manifest($target), true);
        $wrongcourse['course_idnumber'] = 'rtc-subject:999999';
        $wrongcourse['entries'][0]['source']['course_idnumber'] = 'rtc-subject:999999';
        $wrongcourse['entries'][0]['target']['course_idnumber'] = 'rtc-subject:999999';
        $wrongcoursepreview = delivery_lineage_reconciliation::preview(json_encode($wrongcourse));
        $this->assertFalse($wrongcoursepreview['valid']);
        $this->assertStringContainsString(
            'Stable target course identity must resolve to exactly one Moodle course',
            implode(' ', $wrongcoursepreview['errors'])
        );
    }

    public function test_manifest_requires_explicit_quarantine_for_each_unresolved_mapping(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $target = $this->createTarget('coverage', 910006);
        $candidategrade = new \grade_item([
            'courseid' => (int) $target['course']->id,
            'itemname' => 'Unmapped canonical delivery score',
            'itemtype' => 'manual',
            'itemmodule' => null,
            'iteminstance' => null,
            'itemnumber' => 0,
            'idnumber' => 'rtc-subject-score:' . substr($target['course_idnumber'], strlen('rtc-subject:'))
                . ':delivery:' . $target['deliveryid'],
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
        ], false);
        $candidategrade->insert('lineage-test');
        $secondmodule = $this->getDataGenerator()->create_module('quiz', [
            'course' => (int) $target['course']->id,
            'name' => 'Unresolved second module',
            'grade' => 100,
        ]);
        $secondcmid = (int) $secondmodule->cmid;
        delivery_activity_access::protect_course_module($secondcmid, false);
        $secondidentity = $DB->get_record_sql(
            'SELECT cm.id, cm.course, cm.instance, cm.idnumber, m.name AS modname
               FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = :cmid',
            ['cmid' => $secondcmid],
            MUST_EXIST
        );
        $identity = [
            'course_idnumber' => $target['course_idnumber'],
            'cmid' => (int) $secondidentity->id,
            'modname' => (string) $secondidentity->modname,
            'instanceid' => (int) $secondidentity->instance,
            'idnumber' => (string) $secondidentity->idnumber,
        ];

        $partial = $this->manifest($target);
        $partialpreview = delivery_lineage_reconciliation::preview($partial);
        $this->assertFalse($partialpreview['valid']);
        $this->assertStringContainsString('omits unresolved delivery module ' . $secondcmid,
            implode(' ', $partialpreview['errors']));
        $this->assertStringContainsString('omits unresolved delivery-score grade item ' . $candidategrade->id,
            implode(' ', $partialpreview['errors']));

        $complete = json_decode($partial, true);
        $complete['manifest_id'] = 'coverage-complete';
        $complete['entries'][] = [
            'source' => $identity,
            'target' => $identity,
            'decision' => 'quarantine',
            'delivery_ids' => [],
            'reason' => 'Source backup cannot establish this module lineage.',
        ];
        $gradeidentity = [
            'course_idnumber' => $target['course_idnumber'],
            'itemid' => (int) $candidategrade->id,
            'itemtype' => 'manual',
            'itemmodule' => '',
            'iteminstance' => 0,
            'idnumber' => (string) $candidategrade->idnumber,
        ];
        $complete['grade_items'][] = [
            'source' => $gradeidentity,
            'target' => $gradeidentity,
            'decision' => 'quarantine',
            'delivery_id' => 0,
            'reason' => 'No authoritative source identifies the stored values.',
        ];
        $preview = delivery_lineage_reconciliation::preview(json_encode($complete));
        $this->assertTrue($preview['valid'], implode('; ', $preview['errors']));
        $this->applyManifest(json_encode($complete), $preview['plan_sha256']);
        $mapping = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => $secondcmid], '*', MUST_EXIST);
        $this->assertSame(delivery_activity_access::LINEAGE_QUARANTINED, (int) $mapping->lineagestatus);
        $this->assertSame('coverage-complete', (string) $mapping->lineagemanifestid);
        $this->assertFalse($DB->record_exists('local_rtcsync_deliverygrade', ['gradeitemid' => (int) $candidategrade->id]));
        $gradeaudit = $DB->get_record('local_rtcsync_lineageaudit', [
            'manifestid' => 'coverage-complete',
            'recordtype' => 'gradeitem',
            'targetid' => (int) $candidategrade->id,
        ], '*', MUST_EXIST);
        $this->assertSame('quarantine', (string) $gradeaudit->decision);
    }

    public function test_grade_item_disposition_is_audited_and_bad_grade_references_fail(): void
    {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $target = $this->createTarget('grade-disposition', 910007);
        $item = new \grade_item([
            'courseid' => (int) $target['course']->id,
            'itemname' => 'Unresolved imported score',
            'itemtype' => 'manual',
            'itemmodule' => null,
            'iteminstance' => null,
            'itemnumber' => 0,
            'idnumber' => 'legacy-score',
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
        ], false);
        $item->insert('lineage-test');
        $DB->insert_record('local_rtcsync_deliverygrade', (object) [
            'gradeitemid' => (int) $item->id,
            'courseid' => (int) $target['course']->id,
            'deliveryid' => (int) $target['deliveryid'],
            'timecreated' => time(),
            'timemodified' => time(),
            'lineageverified' => 0,
            'lineagemanifestid' => null,
        ]);
        $gradeidentity = [
            'course_idnumber' => $target['course_idnumber'],
            'itemid' => (int) $item->id,
            'itemtype' => 'manual',
            'itemmodule' => '',
            'iteminstance' => 0,
            'idnumber' => 'legacy-score',
        ];
        $manifest = json_decode($this->manifest($target), true);
        $manifest['manifest_id'] = 'grade-disposition';
        $manifest['grade_items'][] = [
            'source' => $gradeidentity,
            'target' => $gradeidentity,
            'decision' => 'quarantine',
            'delivery_id' => 0,
            'reason' => 'No authoritative delivery can be identified for these stored values.',
        ];

        $badreference = $manifest;
        $badreference['grade_items'][0]['source']['course_idnumber'] = 'rtc-subject:999999';
        $badreferencepreview = delivery_lineage_reconciliation::preview(json_encode($badreference));
        $this->assertFalse($badreferencepreview['valid']);
        $this->assertStringContainsString('stable course identity', implode(' ', $badreferencepreview['errors']));

        $badmodule = $manifest;
        $badmodule['grade_items'][0]['target']['itemmodule'] = 'quiz';
        $badmodulepreview = delivery_lineage_reconciliation::preview(json_encode($badmodule));
        $this->assertFalse($badmodulepreview['valid']);
        $this->assertStringContainsString('does not exactly identify a manual grade item',
            implode(' ', $badmodulepreview['errors']));

        $baddelivery = $manifest;
        $baddelivery['grade_items'][0]['decision'] = 'map';
        $baddelivery['grade_items'][0]['delivery_id'] = 919999;
        $baddeliverypreview = delivery_lineage_reconciliation::preview(json_encode($baddelivery));
        $this->assertFalse($baddeliverypreview['valid']);
        $this->assertStringContainsString('without exactly one matching target-course grouping',
            implode(' ', $baddeliverypreview['errors']));

        $preview = delivery_lineage_reconciliation::preview(json_encode($manifest));
        $this->assertTrue($preview['valid'], implode('; ', $preview['errors']));
        $this->applyManifest(json_encode($manifest), $preview['plan_sha256']);
        $mapping = $DB->get_record('local_rtcsync_deliverygrade', ['gradeitemid' => (int) $item->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $mapping->lineageverified);
        $this->assertSame('grade-disposition', (string) $mapping->lineagemanifestid);
        $audit = $DB->get_record('local_rtcsync_lineageaudit', [
            'manifestid' => 'grade-disposition',
            'recordtype' => 'gradeitem',
            'targetid' => (int) $item->id,
        ], '*', MUST_EXIST);
        $this->assertSame('quarantine', (string) $audit->decision);
    }

    public function test_post_apply_verifier_matches_recorded_results_without_writes_or_personal_data(): void
    {
        global $DB;

        $this->resetAfterTest();
        $target = $this->createTarget('verify-clean', 910021);
        $privateidnumber = 'PRIVATE_IDNUMBER_SENTINEL';
        $DB->set_field('course_modules', 'idnumber', $privateidnumber, ['id' => (int) $target['cm']->id]);
        $target['cm']->idnumber = $privateidnumber;
        $student = $this->getDataGenerator()->create_user([
            'username' => 'PRIVATE_LEARNER_SENTINEL',
            'email' => 'private-learner-sentinel@example.test',
        ]);
        $gradeitem = new \grade_item([
            'courseid' => (int) $target['course']->id,
            'itemname' => 'PRIVATE_GRADE_ITEM_NAME_SENTINEL',
            'itemtype' => 'manual',
            'itemmodule' => null,
            'iteminstance' => null,
            'itemnumber' => 0,
            'idnumber' => 'PRIVATE_GRADE_IDNUMBER_SENTINEL',
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
        ], false);
        $gradeitem->insert('lineage-verifier-test');
        $gradeitem->update_final_grade((int) $student->id, 73.25, 'lineage-verifier-test');

        $manifest = json_decode($this->manifest($target, 'current', null, 'verify-clean-manifest'), true);
        $manifest['entries'][0]['reason'] = 'PRIVATE_REASON_SENTINEL';
        $gradeidentity = [
            'course_idnumber' => $target['course_idnumber'],
            'itemid' => (int) $gradeitem->id,
            'itemtype' => 'manual',
            'itemmodule' => '',
            'iteminstance' => 0,
            'idnumber' => 'PRIVATE_GRADE_IDNUMBER_SENTINEL',
        ];
        $manifest['grade_items'][] = [
            'source' => $gradeidentity,
            'target' => $gradeidentity,
            'decision' => 'quarantine',
            'delivery_id' => 0,
            'reason' => 'PRIVATE_GRADE_REASON_SENTINEL',
        ];
        $json = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $preview = delivery_lineage_reconciliation::preview($json);
        $this->assertTrue($preview['valid'], implode('; ', $preview['errors']));
        $this->applyManifest($json, $preview['plan_sha256']);

        $writesbefore = $DB->perf_get_writes();
        $result = delivery_lineage_reconciliation_verifier::verify('verify-clean-manifest', $json);
        $writesafter = $DB->perf_get_writes();

        $this->assertSame($writesbefore, $writesafter, 'Verification must issue no database writes.');
        $this->assertSame('match', $result['status']);
        $this->assertTrue($result['clean']);
        $this->assertSame(0, $result['exit_code']);
        $this->assertSame(2, $result['counts']['expected_records']);
        $this->assertSame(2, $result['counts']['verified_records']);
        $this->assertSame(0, $result['counts']['missing_recorded_results']);
        $this->assertSame(0, $result['counts']['missing_source_artifact_verification']);
        $this->assertTrue($result['source_artifact_verification']['verified']);
        $this->assertSame($this->sourceSnapshotHash(), $result['source_artifact_verification']['sha256']);
        $encodedresult = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach ([
            'PRIVATE_LEARNER_SENTINEL',
            'private-learner-sentinel@example.test',
            '73.25',
            'PRIVATE_GRADE_ITEM_NAME_SENTINEL',
            'PRIVATE_GRADE_IDNUMBER_SENTINEL',
            'PRIVATE_IDNUMBER_SENTINEL',
            'PRIVATE_REASON_SENTINEL',
            'PRIVATE_GRADE_REASON_SENTINEL',
        ] as $privatevalue) {
            $this->assertStringNotContainsString($privatevalue, $encodedresult);
        }
        $this->assertStringContainsString('does not authenticate', $result['interpretation']);

        $DB->set_field('local_rtcsync_linemanifest', 'sourcefilehash', str_repeat('f', 64), [
            'manifestid' => 'verify-clean-manifest',
        ]);
        $artifactdrift = delivery_lineage_reconciliation_verifier::verify('verify-clean-manifest', $json);
        $this->assertSame('drift', $artifactdrift['status']);
        $this->assertSame(1, $artifactdrift['counts']['inconsistent_import_fields']);
        $this->assertFalse($artifactdrift['source_artifact_verification']['verified']);
    }

    public function test_post_apply_verifier_reports_target_drift_with_nonzero_exit_code(): void
    {
        global $DB;

        $this->resetAfterTest();
        $target = $this->createTarget('verify-drift', 910022);
        $json = $this->manifest($target, 'current', null, 'verify-drift-manifest');
        $preview = delivery_lineage_reconciliation::preview($json);
        $this->assertTrue($preview['valid'], implode('; ', $preview['errors']));
        $this->applyManifest($json, $preview['plan_sha256']);

        $DB->set_field('local_rtcsync_deliverycm', 'lineagestatus', delivery_activity_access::LINEAGE_QUARANTINED, [
            'cmid' => (int) $target['cm']->id,
        ]);
        $result = delivery_lineage_reconciliation_verifier::verify('verify-drift-manifest', $json);

        $this->assertSame('drift', $result['status']);
        $this->assertFalse($result['clean']);
        $this->assertSame(1, $result['exit_code']);
        $this->assertSame(1, $result['counts']['changed_target_records']);
        $this->assertSame((int) $target['cm']->id, $result['issues']['changed_target_records'][0]['target_id']);
    }

    public function test_post_apply_verifier_reports_missing_and_extra_audit_records(): void
    {
        global $DB;

        $this->resetAfterTest();
        $target = $this->createTarget('verify-audit', 910023);
        $json = $this->manifest($target, 'current', null, 'verify-audit-manifest');
        $preview = delivery_lineage_reconciliation::preview($json);
        $this->assertTrue($preview['valid'], implode('; ', $preview['errors']));
        $this->applyManifest($json, $preview['plan_sha256']);
        $audit = $DB->get_record('local_rtcsync_lineageaudit', [
            'manifestid' => 'verify-audit-manifest',
            'recordtype' => 'module',
            'targetid' => (int) $target['cm']->id,
        ], '*', MUST_EXIST);

        $DB->delete_records('local_rtcsync_lineageaudit', ['id' => (int) $audit->id]);
        $missing = delivery_lineage_reconciliation_verifier::verify('verify-audit-manifest', $json);
        $this->assertSame(1, $missing['exit_code']);
        $this->assertSame(1, $missing['counts']['missing_audit_records']);

        unset($audit->id);
        $DB->insert_record('local_rtcsync_lineageaudit', $audit);
        $extra = clone $audit;
        unset($extra->id);
        $extra->targetid = (int) $target['cm']->id + 900000;
        $extra->entryhash = hash('sha256', 'unexpected-extra-audit');
        $DB->insert_record('local_rtcsync_lineageaudit', $extra);
        $result = delivery_lineage_reconciliation_verifier::verify('verify-audit-manifest', $json);

        $this->assertSame('drift', $result['status']);
        $this->assertSame(1, $result['exit_code']);
        $this->assertSame(0, $result['counts']['missing_audit_records']);
        $this->assertSame(1, $result['counts']['extra_audit_records']);
        $this->assertSame((int) $target['cm']->id + 900000, $result['issues']['extra_audit_records'][0]['target_id']);
    }

    public function test_post_apply_verifier_rejects_malformed_unknown_and_unverifiable_imports(): void
    {
        global $DB;

        $this->resetAfterTest();
        $target = $this->createTarget('verify-invalid', 910024);
        $validjson = $this->manifest($target, 'current', null, 'verify-valid-manifest');
        $malformed = delivery_lineage_reconciliation_verifier::verify('verify-valid-manifest', '{');
        $this->assertSame('invalid', $malformed['status']);
        $this->assertSame(2, $malformed['exit_code']);

        $unknownjson = $this->manifest($target, 'current', null, 'verify-unknown-manifest');
        $unknown = delivery_lineage_reconciliation_verifier::verify('verify-unknown-manifest', $unknownjson);
        $this->assertSame('invalid', $unknown['status']);
        $this->assertSame(['unknown_import'], $unknown['issues']['validation']);
        $identitymismatch = delivery_lineage_reconciliation_verifier::verify('verify-valid-manifest', $unknownjson);
        $this->assertSame('invalid', $identitymismatch['status']);

        $preview = delivery_lineage_reconciliation::preview($validjson);
        $this->assertTrue($preview['valid'], implode('; ', $preview['errors']));
        $this->applyManifest($validjson, $preview['plan_sha256']);
        $DB->set_field('local_rtcsync_linemanifest', 'sourcefileverified', 0, [
            'manifestid' => 'verify-valid-manifest',
        ]);
        $missingartifact = delivery_lineage_reconciliation_verifier::verify('verify-valid-manifest', $validjson);
        $this->assertSame('incomplete', $missingartifact['status']);
        $this->assertSame(2, $missingartifact['exit_code']);
        $this->assertSame(1, $missingartifact['counts']['missing_source_artifact_verification']);
        $this->assertFalse($missingartifact['source_artifact_verification']['verified']);
        $DB->set_field('local_rtcsync_linemanifest', 'sourcefileverified', 1, [
            'manifestid' => 'verify-valid-manifest',
        ]);
        $DB->set_field('local_rtcsync_lineageaudit', 'resultstate', null, [
            'manifestid' => 'verify-valid-manifest',
            'recordtype' => 'module',
        ]);
        $incomplete = delivery_lineage_reconciliation_verifier::verify('verify-valid-manifest', $validjson);
        $this->assertSame('incomplete', $incomplete['status']);
        $this->assertSame(2, $incomplete['exit_code']);
        $this->assertSame(1, $incomplete['counts']['missing_recorded_results']);
    }

    public function test_quarantined_reused_module_amendment_is_append_only_and_verifies_full_chain(): void
    {
        global $DB;

        $this->resetAfterTest();
        $target = $this->createTarget('amendment-chain', 910031);
        $DB->insert_record('local_rtcsync_deliverycmhist', (object) [
            'cmid' => (int) $target['cm']->id,
            'courseid' => (int) $target['course']->id,
            'deliveryid' => (int) $target['deliveryid'],
            'timecreated' => time(),
            'lineageverified' => 0,
            'lineagemanifestid' => null,
        ]);
        $gradeitem = $this->createUnverifiedGradeItem($target, 'unresolved-amendment-grade');
        $parent = json_decode($this->manifest($target, 'quarantine', [], 'amendment-root'), true);
        $parent['grade_items'][] = $this->gradeQuarantineEntry($target, $gradeitem);
        $parentjson = json_encode($parent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $parentpreview = delivery_lineage_reconciliation::preview($parentjson);
        $this->assertTrue($parentpreview['valid'], implode('; ', $parentpreview['errors']));
        $this->applyManifest($parentjson, $parentpreview['plan_sha256']);
        $parentaudit = $DB->get_record('local_rtcsync_lineageaudit', [
            'manifestid' => 'amendment-root',
            'recordtype' => 'module',
            'targetid' => (int) $target['cm']->id,
        ], '*', MUST_EXIST);
        $parentauditbefore = clone $parentaudit;

        $childjson = $this->amendmentManifest(
            $parentjson,
            'amendment-history-resolution',
            'historical',
            [(int) $target['deliveryid']]
        );
        $mappingbeforepreview = $DB->get_record('local_rtcsync_deliverycm', [
            'cmid' => (int) $target['cm']->id,
        ], '*', MUST_EXIST);
        $historybeforepreview = $DB->get_record('local_rtcsync_deliverycmhist', [
            'cmid' => (int) $target['cm']->id,
            'courseid' => (int) $target['course']->id,
            'deliveryid' => (int) $target['deliveryid'],
        ], '*', MUST_EXIST);
        $childpreview = delivery_lineage_reconciliation::preview($childjson);
        $this->assertTrue($childpreview['valid'], implode('; ', $childpreview['errors']));
        $this->assertEquals($mappingbeforepreview, $DB->get_record('local_rtcsync_deliverycm', [
            'cmid' => (int) $target['cm']->id,
        ], '*', MUST_EXIST));
        $this->assertEquals($historybeforepreview, $DB->get_record('local_rtcsync_deliverycmhist', [
            'cmid' => (int) $target['cm']->id,
            'courseid' => (int) $target['course']->id,
            'deliveryid' => (int) $target['deliveryid'],
        ], '*', MUST_EXIST));
        $this->assertFalse($DB->record_exists('local_rtcsync_linemanifest', [
            'manifestid' => 'amendment-history-resolution',
        ]));
        $this->assertSame('amendment-root', $childpreview['amendment']['parent_manifest_id']);
        $this->assertSame(hash('sha256', $parentjson), $childpreview['amendment']['parent_manifest_sha256']);
        $this->assertSame([(int) $target['cm']->id], $childpreview['amendment']['affected_module_cmids']);
        $applied = $this->applyManifest($childjson, $childpreview['plan_sha256']);
        $this->assertTrue($applied['applied']);
        $this->assertSame('amendment-root', $applied['amendment']['parent_manifest_id']);
        $this->assertFalse($applied['approval_provenance']['authenticated']);

        $childrecord = $DB->get_record('local_rtcsync_linemanifest', [
            'manifestid' => 'amendment-history-resolution',
        ], '*', MUST_EXIST);
        $parentrecord = $DB->get_record('local_rtcsync_linemanifest', [
            'manifestid' => 'amendment-root',
        ], '*', MUST_EXIST);
        $this->assertSame('amendment-root', (string) $childrecord->parentmanifestid);
        $this->assertSame(hash('sha256', $parentjson), (string) $childrecord->parentmanifesthash);
        $this->assertSame((string) $parentrecord->sourcefilehash, (string) $childrecord->sourcefilehash);
        $this->assertSame($childpreview['plan_sha256'], (string) $childrecord->applyplanhash);
        $this->assertSame('RTC Data Owner', (string) $childrecord->approvedby);
        $this->assertSame('DATA-AMENDMENT-REVIEW-TEST', (string) $childrecord->approvalref);
        $this->assertSame(1, (int) $childrecord->sourcefileverified);

        $childaudit = $DB->get_record('local_rtcsync_lineageaudit', [
            'manifestid' => 'amendment-history-resolution',
            'recordtype' => 'module',
            'targetid' => (int) $target['cm']->id,
        ], '*', MUST_EXIST);
        $this->assertSame((string) $parentauditbefore->entryhash, (string) $childaudit->parententryhash);
        $this->assertSame((string) $parentauditbefore->resultstate, (string) $childaudit->parentresultstate);
        $parentauditafter = $DB->get_record('local_rtcsync_lineageaudit', ['id' => (int) $parentauditbefore->id], '*', MUST_EXIST);
        $this->assertEquals($parentauditbefore, $parentauditafter, 'An amendment must retain the original quarantine audit unchanged.');

        $history = $DB->get_record('local_rtcsync_deliverycmhist', [
            'cmid' => (int) $target['cm']->id,
            'courseid' => (int) $target['course']->id,
            'deliveryid' => (int) $target['deliveryid'],
        ], '*', MUST_EXIST);
        $this->assertSame(1, (int) $history->lineageverified);
        $this->assertSame('amendment-history-resolution', (string) $history->lineagemanifestid);
        $gradeassociation = $DB->get_record('local_rtcsync_deliverygrade', [
            'gradeitemid' => (int) $gradeitem->id,
        ], '*', MUST_EXIST);
        $this->assertSame(0, (int) $gradeassociation->lineageverified);
        $this->assertSame('amendment-history-resolution', (string) $gradeassociation->lineagemanifestid);

        $verifiedchild = delivery_lineage_reconciliation_verifier::verify(
            'amendment-history-resolution',
            $childjson
        );
        $this->assertSame('match', $verifiedchild['status'], json_encode($verifiedchild['issues']));
        $this->assertSame(0, $verifiedchild['counts']['lineage_chain_errors']);
        $this->assertSame(
            ['amendment-root', 'amendment-history-resolution'],
            array_column($verifiedchild['lineage_chain'], 'manifest_id')
        );
        $verifiedparent = delivery_lineage_reconciliation_verifier::verify('amendment-root', $parentjson);
        $this->assertSame('match', $verifiedparent['status'], json_encode($verifiedparent['issues']));
        $this->assertSame(
            ['amendment-root', 'amendment-history-resolution'],
            array_column($verifiedparent['lineage_chain'], 'manifest_id')
        );

        $DB->set_field('local_rtcsync_deliverycmhist', 'lineageverified', 0, [
            'cmid' => (int) $target['cm']->id,
            'courseid' => (int) $target['course']->id,
            'deliveryid' => (int) $target['deliveryid'],
        ]);
        $targetdrift = delivery_lineage_reconciliation_verifier::verify(
            'amendment-history-resolution',
            $childjson
        );
        $this->assertSame('drift', $targetdrift['status']);
        $this->assertGreaterThan(0, $targetdrift['counts']['changed_target_records']);
        $DB->set_field('local_rtcsync_deliverycmhist', 'lineageverified', 1, [
            'cmid' => (int) $target['cm']->id,
            'courseid' => (int) $target['course']->id,
            'deliveryid' => (int) $target['deliveryid'],
        ]);

        $DB->set_field('local_rtcsync_lineageaudit', 'parententryhash', str_repeat('0', 64), [
            'manifestid' => 'amendment-history-resolution',
            'recordtype' => 'module',
            'targetid' => (int) $target['cm']->id,
        ]);
        $brokenchain = delivery_lineage_reconciliation_verifier::verify(
            'amendment-history-resolution',
            $childjson
        );
        $this->assertSame('drift', $brokenchain['status']);
        $this->assertGreaterThan(0, $brokenchain['counts']['lineage_chain_errors']);
        $DB->set_field('local_rtcsync_lineageaudit', 'parententryhash', (string) $parentauditbefore->entryhash, [
            'manifestid' => 'amendment-history-resolution',
            'recordtype' => 'module',
            'targetid' => (int) $target['cm']->id,
        ]);
        $DB->set_field('local_rtcsync_linemanifest', 'manifestjson', $parentjson . "\n", [
            'manifestid' => 'amendment-root',
        ]);
        $brokenmanifest = delivery_lineage_reconciliation_verifier::verify(
            'amendment-history-resolution',
            $childjson
        );
        $this->assertSame('drift', $brokenmanifest['status']);
        $this->assertGreaterThan(0, $brokenmanifest['counts']['lineage_chain_errors']);
        $DB->set_field('local_rtcsync_linemanifest', 'manifestjson', $parentjson, [
            'manifestid' => 'amendment-root',
        ]);

        $repeatpreview = delivery_lineage_reconciliation::preview($childjson);
        $this->assertTrue($repeatpreview['valid'], implode('; ', $repeatpreview['errors']));
        $this->assertTrue($repeatpreview['already_applied']);
        $this->assertSame($childpreview['plan_sha256'], $repeatpreview['plan_sha256']);
        $repeat = $this->applyManifest($childjson, $childpreview['plan_sha256']);
        $this->assertTrue($repeat['already_applied']);
        $this->assertSame($childpreview['plan_sha256'], $repeat['plan_sha256']);
        $this->assertSame(1, $DB->count_records('local_rtcsync_lineageaudit', [
            'manifestid' => 'amendment-history-resolution',
        ]));
        try {
            $this->applyManifest($childjson, str_repeat('f', 64));
            $this->fail('Repeated apply must present the exact plan hash stored by the original apply.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('original dry-run plan hash', $exception->getMessage());
        }

        $secondchild = json_decode($this->amendmentManifest($parentjson, 'amendment-fork-attempt'), true);
        $forkpreview = delivery_lineage_reconciliation::preview(json_encode($secondchild));
        $this->assertFalse($forkpreview['valid']);
        $this->assertStringContainsString('already has an amendment', implode(' ', $forkpreview['errors']));
    }

    public function test_amendment_rejects_stale_parent_wrong_identity_and_incomplete_quarantine_coverage(): void
    {
        global $DB;

        $this->resetAfterTest();
        $target = $this->createTarget('amendment-conflicts', 910032);
        $gradeitem = $this->createUnverifiedGradeItem($target, 'unresolved-amendment-grade-conflict');
        $parent = json_decode($this->manifest($target, 'quarantine', [], 'amendment-conflict-root'), true);
        $parent['grade_items'][] = $this->gradeQuarantineEntry($target, $gradeitem);
        $parentjson = json_encode($parent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $parentpreview = delivery_lineage_reconciliation::preview($parentjson);
        $this->assertTrue($parentpreview['valid'], implode('; ', $parentpreview['errors']));
        $this->applyManifest($parentjson, $parentpreview['plan_sha256']);

        $stalehash = json_decode($this->amendmentManifest($parentjson, 'amendment-stale-hash'), true);
        $stalehash['amendment']['parent_manifest_sha256'] = str_repeat('f', 64);
        $stalepreview = delivery_lineage_reconciliation::preview(json_encode($stalehash));
        $this->assertFalse($stalepreview['valid']);
        $this->assertStringContainsString('parent hash is stale', implode(' ', $stalepreview['errors']));

        $changedsource = json_decode($this->amendmentManifest($parentjson, 'amendment-changed-source'), true);
        $changedsource['source_snapshot_sha256'] = str_repeat('e', 64);
        $changedsourcepreview = delivery_lineage_reconciliation::preview(json_encode($changedsource));
        $this->assertFalse($changedsourcepreview['valid']);
        $this->assertStringContainsString(
            'source digest and source version must match its parent',
            implode(' ', $changedsourcepreview['errors'])
        );

        $wrongidentity = json_decode($this->amendmentManifest($parentjson, 'amendment-wrong-identity'), true);
        $wrongidentity['entries'][0]['source']['instanceid']++;
        $wrongpreview = delivery_lineage_reconciliation::preview(json_encode($wrongidentity));
        $this->assertFalse($wrongpreview['valid']);
        $this->assertStringContainsString('cannot change the source or target identity', implode(' ', $wrongpreview['errors']));

        $wrongcourse = json_decode($this->amendmentManifest($parentjson, 'amendment-wrong-course'), true);
        $wrongcourse['course_idnumber'] = 'rtc-subject:999999999';
        $wrongcoursepreview = delivery_lineage_reconciliation::preview(json_encode($wrongcourse));
        $this->assertFalse($wrongcoursepreview['valid']);
        $this->assertStringContainsString('parent belongs to a different stable course identity',
            implode(' ', $wrongcoursepreview['errors']));

        $wrongmodule = json_decode($this->amendmentManifest($parentjson, 'amendment-wrong-module'), true);
        $wrongmodule['entries'][0]['target']['cmid']++;
        $wrongmodulepreview = delivery_lineage_reconciliation::preview(json_encode($wrongmodule));
        $this->assertFalse($wrongmodulepreview['valid']);
        $this->assertStringContainsString('explicitly quarantined record', implode(' ', $wrongmodulepreview['errors']));

        $wrongdelivery = json_decode($this->amendmentManifest(
            $parentjson,
            'amendment-wrong-delivery',
            'historical',
            [(int) $target['deliveryid'] + 1]
        ), true);
        $wrongdeliverypreview = delivery_lineage_reconciliation::preview(json_encode($wrongdelivery));
        $this->assertFalse($wrongdeliverypreview['valid']);
        $this->assertStringContainsString('delivery', implode(' ', $wrongdeliverypreview['errors']));

        $incomplete = json_decode($this->amendmentManifest($parentjson, 'amendment-incomplete'), true);
        $incomplete['grade_items'] = [];
        $incompletepreview = delivery_lineage_reconciliation::preview(json_encode($incomplete));
        $this->assertFalse($incompletepreview['valid']);
        $this->assertStringContainsString('must be explicitly resolved', implode(' ', $incompletepreview['errors']));

        $DB->set_field('local_rtcsync_deliverycm', 'lineagestatus', delivery_activity_access::LINEAGE_UNREVIEWED, [
            'cmid' => (int) $target['cm']->id,
        ]);
        $staleparentpreview = delivery_lineage_reconciliation::preview(
            $this->amendmentManifest($parentjson, 'amendment-stale-target')
        );
        $this->assertFalse($staleparentpreview['valid']);
        $this->assertStringContainsString('target state no longer matches', implode(' ', $staleparentpreview['errors']));
        $this->assertFalse($DB->record_exists('local_rtcsync_linemanifest', ['manifestid' => 'amendment-stale-target']));
    }

    public function test_amendment_cannot_reinterpret_previously_verified_module_history(): void
    {
        global $DB;

        $this->resetAfterTest();
        $target = $this->createTarget('amendment-verified-history', 910034);
        $DB->insert_record('local_rtcsync_deliverycmhist', (object) [
            'cmid' => (int) $target['cm']->id,
            'courseid' => (int) $target['course']->id,
            'deliveryid' => (int) $target['deliveryid'],
            'timecreated' => time(),
            'lineageverified' => 1,
            'lineagemanifestid' => 'previously-reviewed-history',
        ]);
        $parentjson = $this->manifest($target, 'quarantine', [], 'verified-history-root');
        $parentpreview = delivery_lineage_reconciliation::preview($parentjson);
        $this->assertTrue($parentpreview['valid'], implode('; ', $parentpreview['errors']));
        $this->applyManifest($parentjson, $parentpreview['plan_sha256']);

        $childjson = $this->amendmentManifest(
            $parentjson,
            'verified-history-child',
            'historical',
            [(int) $target['deliveryid']]
        );
        $childpreview = delivery_lineage_reconciliation::preview($childjson);
        $this->assertFalse($childpreview['valid']);
        $this->assertStringContainsString(
            'already-verified historical lineage and cannot be remapped',
            implode(' ', $childpreview['errors'])
        );
        $this->assertFalse($DB->record_exists('local_rtcsync_linemanifest', [
            'manifestid' => 'verified-history-child',
        ]));
    }

    public function test_amendment_cannot_remap_a_previously_verified_grade_lineage(): void
    {
        global $DB;

        $this->resetAfterTest();
        $target = $this->createTarget('amendment-verified-grade', 910035);
        $seconddeliveryid = (int) $target['deliveryid'] + 1;
        $this->getDataGenerator()->create_grouping([
            'courseid' => (int) $target['course']->id,
            'name' => 'Delivery ' . $seconddeliveryid,
            'idnumber' => 'rtc-delivery-grouping:' . $seconddeliveryid,
        ]);
        $gradeitem = $this->createUnverifiedGradeItem($target, 'verified-amendment-grade');
        $parent = json_decode($this->manifest($target, 'quarantine', [], 'verified-grade-root'), true);
        $gradeentry = $this->gradeQuarantineEntry($target, $gradeitem);
        $gradeentry['decision'] = 'map';
        $gradeentry['delivery_id'] = (int) $target['deliveryid'];
        $gradeentry['reason'] = 'Reviewed manual grade lineage for the source delivery.';
        $parent['grade_items'][] = $gradeentry;
        $parentjson = json_encode($parent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $parentpreview = delivery_lineage_reconciliation::preview($parentjson);
        $this->assertTrue($parentpreview['valid'], implode('; ', $parentpreview['errors']));
        $this->applyManifest($parentjson, $parentpreview['plan_sha256']);

        $child = json_decode($this->amendmentManifest($parentjson, 'verified-grade-child'), true);
        $remappedgrade = $gradeentry;
        $remappedgrade['delivery_id'] = $seconddeliveryid;
        $remappedgrade['reason'] = 'Attempt to reinterpret the already-verified mapping.';
        $child['grade_items'][] = $remappedgrade;
        $childjson = json_encode($child, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $childpreview = delivery_lineage_reconciliation::preview($childjson);
        $this->assertFalse($childpreview['valid']);
        $this->assertStringContainsString(
            'must refer to an explicitly quarantined record',
            implode(' ', $childpreview['errors'])
        );
        $this->assertStringContainsString(
            'already reconciled',
            implode(' ', $childpreview['errors'])
        );
        $this->assertFalse($DB->record_exists('local_rtcsync_linemanifest', [
            'manifestid' => 'verified-grade-child',
        ]));
    }

    public function test_quarantined_grade_mapping_can_be_resolved_by_a_reviewed_amendment(): void
    {
        global $DB;

        $this->resetAfterTest();
        $target = $this->createTarget('amendment-grade-resolution', 910036);
        $gradeitem = $this->createUnverifiedGradeItem($target, 'amendment-grade-resolution-item');
        $unresolveditem = $this->createUnverifiedGradeItem($target, 'amendment-grade-remains-quarantined');
        $gradeitem->itemname = 'Reviewed grade target';
        $gradeitem->update('lineage-amendment-test');
        $unresolveditem->itemname = 'Still quarantined grade';
        $unresolveditem->update('lineage-amendment-test');
        $user = $this->getDataGenerator()->create_user([
            'username' => 'lineage-amendment-student',
            'email' => 'lineage-amendment-student@example.test',
            'idnumber' => 'LINEAGE-AMENDMENT-STUDENT',
        ]);
        $DB->insert_record('local_rtcsync_user', (object) [
            'userid' => (int) $user->id,
            'idnumber' => (string) $user->idnumber,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $manual = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', [
            'courseid' => (int) $target['course']->id,
            'enrol' => 'manual',
        ], '*', IGNORE_MISSING);
        if (!$instance) {
            $manual->add_default_instance($target['course']);
            $instance = $DB->get_record('enrol', [
                'courseid' => (int) $target['course']->id,
                'enrol' => 'manual',
            ], '*', MUST_EXIST);
        }
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        $manual->enrol_user($instance, (int) $user->id, (int) $studentrole->id, 0, 0, ENROL_USER_SUSPENDED);
        $gradeitem->update_final_grade((int) $user->id, 76.0, 'lineage-amendment-test');
        $unresolveditem->update_final_grade((int) $user->id, 93.0, 'lineage-amendment-test');
        student_history::sync_marker(
            (int) $user->id,
            (int) $target['course']->id,
            1,
            'delivery_participation',
            946036,
            (int) $target['deliveryid']
        );

        $history = new student_history();
        $this->setUser($user);
        $this->assertSame([], $history->visible_results()['results'], 'Unverified manual grade mappings stay out of Released results.');
        $this->setAdminUser();

        $parent = json_decode($this->manifest($target, 'quarantine', [], 'grade-amendment-root'), true);
        $parent['grade_items'][] = $this->gradeQuarantineEntry($target, $gradeitem);
        $parent['grade_items'][] = $this->gradeQuarantineEntry($target, $unresolveditem);
        $parentjson = json_encode($parent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $parentpreview = delivery_lineage_reconciliation::preview($parentjson);
        $this->assertTrue($parentpreview['valid'], implode('; ', $parentpreview['errors']));
        $this->applyManifest($parentjson, $parentpreview['plan_sha256']);
        $parentaudit = $DB->get_record('local_rtcsync_lineageaudit', [
            'manifestid' => 'grade-amendment-root',
            'recordtype' => 'gradeitem',
            'targetid' => (int) $gradeitem->id,
        ], '*', MUST_EXIST);
        $parentauditbefore = clone $parentaudit;
        $unresolvedparentauditbefore = $DB->get_record('local_rtcsync_lineageaudit', [
            'manifestid' => 'grade-amendment-root',
            'recordtype' => 'gradeitem',
            'targetid' => (int) $unresolveditem->id,
        ], '*', MUST_EXIST);
        $unresolvedparentauditbefore = clone $unresolvedparentauditbefore;

        $this->setUser($user);
        $this->assertSame([], $history->visible_results()['results'], 'Applying a quarantine decision cannot release either grade.');
        $this->setAdminUser();

        $child = json_decode($this->amendmentManifest($parentjson, 'grade-amendment-child'), true);
        $this->assertCount(2, $child['grade_items']);
        $child['grade_items'][0]['decision'] = 'map';
        $child['grade_items'][0]['delivery_id'] = (int) $target['deliveryid'];
        $child['grade_items'][0]['reason'] = 'Reviewed parent quarantine resolves to this delivery.';
        $this->assertSame('quarantine', $child['grade_items'][1]['decision']);
        $childjson = json_encode($child, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $childpreview = delivery_lineage_reconciliation::preview($childjson);
        $this->assertTrue($childpreview['valid'], implode('; ', $childpreview['errors']));
        $applied = $this->applyManifest($childjson, $childpreview['plan_sha256']);
        $this->assertTrue($applied['applied']);

        $gradeassociation = $DB->get_record('local_rtcsync_deliverygrade', [
            'gradeitemid' => (int) $gradeitem->id,
        ], '*', MUST_EXIST);
        $this->assertSame(1, (int) $gradeassociation->lineageverified);
        $this->assertSame((int) $target['deliveryid'], (int) $gradeassociation->deliveryid);
        $this->assertSame('grade-amendment-child', (string) $gradeassociation->lineagemanifestid);
        $unresolvedassociation = $DB->get_record('local_rtcsync_deliverygrade', [
            'gradeitemid' => (int) $unresolveditem->id,
        ], '*', MUST_EXIST);
        $this->assertSame(0, (int) $unresolvedassociation->lineageverified);
        $this->assertSame((int) $target['deliveryid'], (int) $unresolvedassociation->deliveryid);
        $this->setUser($user);
        $released = $history->visible_results()['results'];
        $this->assertSame(['Reviewed grade target'], array_column($released, 'item'));
        $this->assertSame(76.0, $released[0]['grade']);

        $parentauditafter = $DB->get_record('local_rtcsync_lineageaudit', [
            'id' => (int) $parentauditbefore->id,
        ], '*', MUST_EXIST);
        $this->assertEquals($parentauditbefore, $parentauditafter);
        $unresolvedparentauditafter = $DB->get_record('local_rtcsync_lineageaudit', [
            'id' => (int) $unresolvedparentauditbefore->id,
        ], '*', MUST_EXIST);
        $this->assertEquals($unresolvedparentauditbefore, $unresolvedparentauditafter);

        $this->setAdminUser();
        $verified = delivery_lineage_reconciliation_verifier::verify('grade-amendment-child', $childjson);
        $this->assertSame('match', $verified['status'], json_encode($verified['issues']));
        $this->assertSame(
            ['grade-amendment-root', 'grade-amendment-child'],
            array_column($verified['lineage_chain'], 'manifest_id')
        );
    }

    public function test_evidence_bundle_is_deterministic_redacted_and_read_only(): void
    {
        global $DB;

        $this->resetAfterTest();
        $target = $this->createTarget('evidence-redaction', 910037);
        $manifest = json_decode($this->manifest($target, 'quarantine', [], 'evidence-redaction-root'), true);
        $manifest['review']['approved_by'] = 'PRIVATE_APPROVER_NAME_731';
        $manifest['review']['approval_reference'] = 'PRIVATE_APPROVAL_REFERENCE_845';
        $manifest['entries'][0]['reason'] = 'PRIVATE_REASON_WITH_LEARNER_GRADE_98_ANSWER_secret';
        $manifestjson = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $preview = delivery_lineage_reconciliation::preview($manifestjson);
        $this->assertTrue($preview['valid'], implode('; ', $preview['errors']));
        $this->applyManifest($manifestjson, $preview['plan_sha256']);

        $writesbefore = $DB->perf_get_writes();
        $verification = delivery_lineage_reconciliation_verifier::verify('evidence-redaction-root', $manifestjson);
        $bundle = delivery_lineage_evidence_bundle::create($verification);
        $json = delivery_lineage_evidence_bundle::encode($bundle);
        $repeated = delivery_lineage_evidence_bundle::create($verification);
        $this->assertSame($json, delivery_lineage_evidence_bundle::encode($repeated));
        $validation = delivery_lineage_evidence_bundle::validate($json);
        $this->assertTrue($validation['valid'], implode(', ', $validation['issue_codes']));
        $this->assertTrue($validation['digest_valid']);
        $this->assertFalse($validation['external_digest_supplied']);
        $anchored = delivery_lineage_evidence_bundle::validate(
            $json,
            (string) $bundle['integrity']['sha256']
        );
        $this->assertTrue($anchored['valid']);
        $this->assertTrue($anchored['external_digest_matches']);
        $this->assertSame($writesbefore, $DB->perf_get_writes(), 'Verification and bundle operations must not write to Moodle.');

        foreach ([
            'PRIVATE_APPROVER_NAME_731',
            'PRIVATE_APPROVAL_REFERENCE_845',
            'PRIVATE_REASON_WITH_LEARNER_GRADE_98_ANSWER_secret',
            $this->sourceSnapshotPath(),
        ] as $privatevalue) {
            $this->assertStringNotContainsString($privatevalue, $json);
        }
        $this->assertSame('evidence-redaction-root', $bundle['evidence']['import']['manifest_id']);
        $this->assertCount(1, $bundle['evidence']['records']);
        $this->assertSame((int) $target['cm']->id, $bundle['evidence']['records'][0]['target_id']);
        $this->assertSame(1, $bundle['evidence']['counts']['unresolved_records']);
    }

    public function test_evidence_bundle_rejects_malformed_tampered_and_unanchored_digests(): void
    {
        $bundle = delivery_lineage_evidence_bundle::create($this->sampleEvidenceVerification());
        $json = delivery_lineage_evidence_bundle::encode($bundle);
        $malformed = delivery_lineage_evidence_bundle::validate('{');
        $this->assertFalse($malformed['valid']);
        $this->assertContains('malformed_json', $malformed['issue_codes']);

        $tamperedbundle = json_decode($json, true);
        $tamperedbundle['evidence']['records'][0]['target_id']++;
        $tampered = delivery_lineage_evidence_bundle::validate(json_encode($tamperedbundle));
        $this->assertFalse($tampered['valid']);
        $this->assertFalse($tampered['digest_valid']);
        $this->assertContains('embedded_digest_mismatch', $tampered['issue_codes']);

        $externalmismatch = delivery_lineage_evidence_bundle::validate($json, str_repeat('f', 64));
        $this->assertFalse($externalmismatch['valid']);
        $this->assertTrue($externalmismatch['digest_valid']);
        $this->assertFalse($externalmismatch['external_digest_matches']);
        $this->assertContains('external_digest_mismatch', $externalmismatch['issue_codes']);
    }

    public function test_evidence_export_redacts_corrupt_persisted_chain_and_record_values(): void
    {
        $verification = $this->sampleEvidenceVerification();
        $verification['status'] = 'drift';
        $verification['clean'] = false;
        $verification['exit_code'] = 1;
        $verification['counts']['lineage_chain_errors'] = 1;
        $verification['lineage_chain'][0]['manifest_sha256'] = 'PRIVATE_CHAIN_CORRUPTION_WITH_NAME_Anna';
        $verification['lineage_chain'][0]['parent_manifest_id'] = 'PRIVATE_PARENT_NAME_Bob';
        $verification['lineage_chain'][0]['source_snapshot_sha256'] = 'PRIVATE_SOURCE_CORRUPTION';
        $verification['lineage_chain'][0]['source_artifact_sha256'] = 'PRIVATE_SOURCE_CORRUPTION';
        $verification['lineage_chain'][0]['source_artifact_verified'] = false;
        $verification['lineage_chain'][0]['source_artifact_verified_at'] = 0;
        $verification['record_outcomes'][0]['manifest_id'] = 'PRIVATE_RECORD_OWNER_Carla';
        $verification['record_outcomes'][0]['record_type'] = 'PRIVATE_RECORD_TYPE_Daniel';
        $verification['record_outcomes'][0]['decision'] = 'PRIVATE_APPROVAL_REASON_Eve';
        $verification['record_outcomes'][0]['entry_sha256'] = 'PRIVATE_ENTRY_CORRUPTION';
        $verification['record_outcomes'][0]['fields'] = ['target_snapshot', 'PRIVATE_FREE_TEXT_FIELD_Finn'];

        $bundle = delivery_lineage_evidence_bundle::create($verification);
        $json = delivery_lineage_evidence_bundle::encode($bundle);
        $validated = delivery_lineage_evidence_bundle::validate($json);
        $this->assertTrue($validated['valid'], implode(', ', $validated['issue_codes']));
        $this->assertSame('drift', $bundle['evidence']['verification']['status']);
        $this->assertSame('drift', $bundle['evidence']['chain_verification']['status']);
        $this->assertSame(1, $bundle['evidence']['chain_verification']['error_count']);
        $this->assertSame('unknown', $bundle['evidence']['records'][0]['record_type']);
        $this->assertSame('unknown', $bundle['evidence']['records'][0]['decision']);
        $this->assertSame('redacted-record-000001', $bundle['evidence']['records'][0]['manifest_id']);
        $this->assertSame(['target_snapshot'], $bundle['evidence']['records'][0]['fields']);
        foreach ([
            'PRIVATE_CHAIN_CORRUPTION_WITH_NAME_Anna',
            'PRIVATE_PARENT_NAME_Bob',
            'PRIVATE_SOURCE_CORRUPTION',
            'PRIVATE_RECORD_OWNER_Carla',
            'PRIVATE_RECORD_TYPE_Daniel',
            'PRIVATE_APPROVAL_REASON_Eve',
            'PRIVATE_ENTRY_CORRUPTION',
            'PRIVATE_FREE_TEXT_FIELD_Finn',
        ] as $privatevalue) {
            $this->assertStringNotContainsString($privatevalue, $json);
        }
    }

    public function test_evidence_current_comparison_detects_target_drift_without_writes(): void
    {
        global $DB;

        $this->resetAfterTest();
        $target = $this->createTarget('evidence-target-drift', 910038);
        $manifestjson = $this->manifest($target, 'current', null, 'evidence-target-drift-root');
        $preview = delivery_lineage_reconciliation::preview($manifestjson);
        $this->assertTrue($preview['valid'], implode('; ', $preview['errors']));
        $this->applyManifest($manifestjson, $preview['plan_sha256']);
        $originalverification = delivery_lineage_reconciliation_verifier::verify(
            'evidence-target-drift-root',
            $manifestjson
        );
        $bundle = delivery_lineage_evidence_bundle::create($originalverification);
        $DB->set_field('local_rtcsync_deliverycm', 'lineagestatus', delivery_activity_access::LINEAGE_QUARANTINED, [
            'cmid' => (int) $target['cm']->id,
        ]);

        $writesbefore = $DB->perf_get_writes();
        $currentverification = delivery_lineage_reconciliation_verifier::verify(
            'evidence-target-drift-root',
            $manifestjson
        );
        $comparison = delivery_lineage_evidence_bundle::compare_current($bundle, $currentverification);
        $this->assertSame('drift', $currentverification['status']);
        $this->assertFalse($comparison['matches']);
        $this->assertSame('drift', $comparison['current_status']);
        $this->assertSame($writesbefore, $DB->perf_get_writes(), 'Current verification must not write to Moodle.');
    }

    public function test_failed_amendment_rolls_back_target_and_child_import_without_rewriting_parent_audit(): void
    {
        global $DB;

        $this->resetAfterTest();
        $target = $this->createTarget('amendment-rollback', 910033);
        $DB->insert_record('local_rtcsync_deliverycmhist', (object) [
            'cmid' => (int) $target['cm']->id,
            'courseid' => (int) $target['course']->id,
            'deliveryid' => (int) $target['deliveryid'],
            'timecreated' => time(),
            'lineageverified' => 0,
            'lineagemanifestid' => null,
        ]);
        $parentjson = $this->manifest($target, 'quarantine', [], 'amendment-rollback-root');
        $parentpreview = delivery_lineage_reconciliation::preview($parentjson);
        $this->assertTrue($parentpreview['valid'], implode('; ', $parentpreview['errors']));
        $this->applyManifest($parentjson, $parentpreview['plan_sha256']);
        $parentaudit = $DB->get_record('local_rtcsync_lineageaudit', [
            'manifestid' => 'amendment-rollback-root',
            'recordtype' => 'module',
            'targetid' => (int) $target['cm']->id,
        ], '*', MUST_EXIST);
        $parentmapping = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => (int) $target['cm']->id], '*', MUST_EXIST);
        $childjson = $this->amendmentManifest(
            $parentjson,
            'amendment-rollback-child',
            'historical',
            [(int) $target['deliveryid']]
        );
        $childpreview = delivery_lineage_reconciliation::preview($childjson);
        $this->assertTrue($childpreview['valid'], implode('; ', $childpreview['errors']));
        $DB->insert_record('local_rtcsync_lineageaudit', (object) [
            'manifestid' => 'amendment-rollback-child',
            'manifesthash' => hash('sha256', $childjson),
            'courseid' => (int) $target['course']->id,
            'recordtype' => 'module',
            'targetid' => (int) $target['cm']->id,
            'decision' => 'quarantine',
            'deliveryids' => '[]',
            'sourceidentity' => '{}',
            'targetidentity' => '{}',
            'reason' => 'Injected unique-key collision.',
            'entryhash' => hash('sha256', 'injected-child-audit'),
            'resultstate' => '{}',
            'timecreated' => time(),
        ]);

        try {
            $this->applyManifest($childjson, $childpreview['plan_sha256']);
            $this->fail('A child audit collision must roll back the amendment transaction.');
        } catch (\dml_write_exception $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $aftermapping = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => (int) $target['cm']->id], '*', MUST_EXIST);
        $this->assertSame((int) $parentmapping->lineagestatus, (int) $aftermapping->lineagestatus);
        $this->assertSame((string) $parentmapping->lineagemanifestid, (string) $aftermapping->lineagemanifestid);
        $this->assertSame('amendment-rollback-root', (string) $aftermapping->lineagemanifestid);
        $this->assertFalse($DB->record_exists('local_rtcsync_linemanifest', [
            'manifestid' => 'amendment-rollback-child',
        ]));
        $parentauditafter = $DB->get_record('local_rtcsync_lineageaudit', ['id' => (int) $parentaudit->id], '*', MUST_EXIST);
        $this->assertEquals($parentaudit, $parentauditafter);
        $history = $DB->get_record('local_rtcsync_deliverycmhist', [
            'cmid' => (int) $target['cm']->id,
            'courseid' => (int) $target['course']->id,
            'deliveryid' => (int) $target['deliveryid'],
        ], '*', MUST_EXIST);
        $this->assertSame(0, (int) $history->lineageverified);
        $this->assertNull($history->lineagemanifestid);
    }
}
