<?php

namespace local_rtcsync\local;

defined('MOODLE_INTERNAL') || die();

/** Independently verify an applied delivery-lineage import using reads only. */
final class delivery_lineage_reconciliation_verifier
{
    /** Verify one exact applied manifest and its persisted effects in a DB transaction. */
    public static function verify(string $manifestid, string $json): array
    {
        global $DB;

        $manifestid = trim($manifestid);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', $manifestid)) {
            return self::invalid_result('', 'invalid_manifest_identity');
        }

        $manifest = json_decode($json, true);
        if (!is_array($manifest) || json_last_error() !== JSON_ERROR_NONE
                || !is_string($manifest['manifest_id'] ?? null)
                || trim($manifest['manifest_id']) !== $manifestid
                || !self::manifest_has_supported_contract($manifest)) {
            return self::invalid_result($manifestid, 'manifest_identity_or_json_mismatch');
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            $result = self::verify_in_transaction($manifestid, $json, $manifest);
            $transaction->allow_commit();
            return $result;
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
            throw $exception;
        }
    }

    /** Compare the imported declaration, its per-record audit, and the current target snapshot. */
    private static function verify_in_transaction(string $manifestid, string $json, array $manifest): array
    {
        global $DB;

        $manifestrecord = $DB->get_record('local_rtcsync_linemanifest', ['manifestid' => $manifestid], '*', IGNORE_MISSING);
        if (!$manifestrecord) {
            return self::invalid_result($manifestid, 'unknown_import');
        }

        $manifesthash = hash('sha256', $json);
        if (!hash_equals((string) $manifestrecord->manifesthash, $manifesthash)) {
            return self::invalid_result($manifestid, 'manifest_hash_mismatch');
        }
        if (!is_array($manifest['entries'] ?? null) || !array_is_list($manifest['entries'])
                || !is_array($manifest['grade_items'] ?? null) || !array_is_list($manifest['grade_items'])) {
            return self::invalid_result($manifestid, 'manifest_records_malformed');
        }

        $expected = [];
        $expectedmoduleids = [];
        $expectedgradeids = [];
        $issues = [
            'missing_audit_records' => [],
            'extra_audit_records' => [],
            'changed_audit_records' => [],
            'missing_target_records' => [],
            'extra_target_records' => [],
            'changed_target_records' => [],
            'missing_recorded_results' => [],
            'missing_apply_plan_hashes' => [],
            'missing_source_artifact_verification' => [],
            'inconsistent_records' => [],
            'inconsistent_import_fields' => [],
            'lineage_chain_errors' => [],
        ];

        foreach ($manifest['entries'] as $entry) {
            if (!is_array($entry) || !is_array($entry['source'] ?? null) || !is_array($entry['target'] ?? null)
                    || !is_int($entry['target']['cmid'] ?? null) || $entry['target']['cmid'] <= 0) {
                return self::invalid_result($manifestid, 'manifest_records_malformed');
            }
            $targetid = $entry['target']['cmid'];
            $key = 'module:' . $targetid;
            if (isset($expected[$key])) {
                return self::invalid_result($manifestid, 'manifest_has_duplicate_targets');
            }
            $expected[$key] = [
                'recordtype' => 'module',
                'targetid' => $targetid,
                'entry' => $entry,
            ];
            $expectedmoduleids[$targetid] = true;
        }
        foreach ($manifest['grade_items'] as $entry) {
            if (!is_array($entry) || !is_array($entry['source'] ?? null) || !is_array($entry['target'] ?? null)
                    || !is_int($entry['target']['itemid'] ?? null) || $entry['target']['itemid'] <= 0) {
                return self::invalid_result($manifestid, 'manifest_records_malformed');
            }
            $targetid = $entry['target']['itemid'];
            $key = 'gradeitem:' . $targetid;
            if (isset($expected[$key])) {
                return self::invalid_result($manifestid, 'manifest_has_duplicate_targets');
            }
            $expected[$key] = [
                'recordtype' => 'gradeitem',
                'targetid' => $targetid,
                'entry' => $entry,
            ];
            $expectedgradeids[$targetid] = true;
        }
        if (!$expected) {
            return self::invalid_result($manifestid, 'manifest_has_no_records');
        }

        self::compare_manifest_record($issues, $manifestrecord, $manifest, $manifesthash, count($expected));
        self::compare_course_identity($issues, $manifestrecord, $manifest);
        $lineagechain = self::verify_lineage_chain($manifestid, $manifestrecord, $manifest, $json);
        $issues['lineage_chain_errors'] = $lineagechain['errors'];
        $sourceartifactverified = (int) ($manifestrecord->sourcefileverified ?? 0) === 1;
        $sourceartifacthash = strtolower((string) ($manifestrecord->sourcefilehash ?? ''));
        $sourceartifactverifiedat = (int) ($manifestrecord->sourcefileverifiedat ?? 0);
        if (!$sourceartifactverified || !preg_match('/^[a-f0-9]{64}$/', $sourceartifacthash)
                || $sourceartifactverifiedat <= 0) {
            $issues['missing_source_artifact_verification'][] = 'source_artifact';
        }

        $seen = [];
        $auditmatch = [];
        foreach ($DB->get_records('local_rtcsync_lineageaudit', ['manifestid' => $manifestid], 'id ASC') as $audit) {
            $recordtype = in_array((string) $audit->recordtype, ['module', 'gradeitem'], true)
                ? (string) $audit->recordtype : 'unknown';
            $targetid = (int) $audit->targetid;
            $key = $recordtype . ':' . $targetid;
            if (!isset($expected[$key]) || isset($seen[$key])) {
                $issues['extra_audit_records'][] = self::target_identifier($recordtype, $targetid);
                continue;
            }
            $seen[$key] = true;
            $expectedrecord = $expected[$key];
            $changedfields = self::audit_changed_fields(
                $audit,
                $expectedrecord,
                (int) $manifestrecord->courseid,
                $manifesthash,
                $manifestid,
                (int) $manifestrecord->timecreated
            );
            if ($changedfields) {
                $issues['changed_audit_records'][] = self::target_identifier($recordtype, $targetid, $changedfields);
            } else {
                $auditmatch[$key] = $audit;
            }
        }

        foreach ($expected as $key => $expectedrecord) {
            if (!isset($seen[$key])) {
                $issues['missing_audit_records'][] = self::target_identifier(
                    $expectedrecord['recordtype'],
                    $expectedrecord['targetid']
                );
            }
        }

        self::compare_orphaned_target_mappings(
            $issues,
            $manifestid,
            $expectedmoduleids,
            $expectedgradeids
        );

        $verifiedrecords = 0;
        foreach ($expected as $key => $expectedrecord) {
            $recordtype = $expectedrecord['recordtype'];
            $targetid = $expectedrecord['targetid'];
            $audit = $auditmatch[$key] ?? null;
            $recordedstate = null;
            if ($audit) {
                $recordedstate = json_decode((string) ($audit->resultstate ?? ''), true);
                if (!is_array($recordedstate) || json_last_error() !== JSON_ERROR_NONE
                        || ($recordedstate['version'] ?? null) !== 1
                        || ($recordedstate['record_type'] ?? null) !== $recordtype
                        || ($recordedstate['target_id'] ?? null) !== $targetid) {
                    $issues['missing_recorded_results'][] = self::target_identifier($recordtype, $targetid);
                    $recordedstate = null;
                }
            }

            if ($lineagechain['leaf_manifest_id'] !== $manifestid) {
                // This import is an ancestor. Later append-only amendments are
                // expected to supersede its quarantined target state; the
                // chain verifier compares current targets with the effective
                // leaf audit instead of misreporting legitimate amendments.
                if ($audit && $recordedstate !== null) {
                    $verifiedrecords++;
                }
            } else {
                $currentstate = $recordtype === 'module'
                    ? self::read_module_state($targetid)
                    : self::read_grade_item_state($targetid);
                $targetfields = self::target_changed_fields(
                    $recordtype,
                    $currentstate,
                    $expectedrecord['entry'],
                    (int) $manifestrecord->courseid,
                    $manifestid
                );
                if ($targetfields) {
                    $issues['inconsistent_records'][] = self::target_identifier($recordtype, $targetid, $targetfields);
                }
                if ($recordedstate !== null && self::canonical_json($recordedstate) !== self::canonical_json($currentstate)) {
                    $targetfields[] = 'persisted_result_state';
                }
                $targetfields = array_values(array_unique($targetfields));
                if ($targetfields) {
                    if (($recordtype === 'module' && $currentstate['course_module_exists'] === false)
                            || ($recordtype === 'gradeitem' && $currentstate['target_exists'] === false)) {
                        $issues['missing_target_records'][] = self::target_identifier($recordtype, $targetid, $targetfields);
                    } else {
                        $issues['changed_target_records'][] = self::target_identifier($recordtype, $targetid, $targetfields);
                    }
                } else if ($audit && $recordedstate !== null) {
                    $verifiedrecords++;
                }
            }
        }

        foreach ($issues as $name => $rows) {
            if (in_array($name, [
                'inconsistent_import_fields', 'missing_source_artifact_verification',
                'missing_apply_plan_hashes', 'lineage_chain_errors',
            ], true)) {
                $issues[$name] = array_values(array_unique($rows));
            } else {
                $issues[$name] = self::unique_identifiers($rows);
            }
        }

        $counts = [
            'expected_records' => count($expected),
            'verified_records' => $verifiedrecords,
        ];
        foreach ($issues as $name => $rows) {
            $counts[$name] = count($rows);
        }

        $hasdrift = $counts['missing_audit_records'] > 0 || $counts['extra_audit_records'] > 0
            || $counts['changed_audit_records'] > 0 || $counts['missing_target_records'] > 0
            || $counts['extra_target_records'] > 0 || $counts['changed_target_records'] > 0
            || $counts['inconsistent_records'] > 0 || $counts['inconsistent_import_fields'] > 0
            || $counts['lineage_chain_errors'] > 0;
        $hasincomplete = $counts['missing_recorded_results'] > 0
            || $counts['missing_source_artifact_verification'] > 0
            || $counts['missing_apply_plan_hashes'] > 0;
        $status = $hasdrift ? 'drift' : ($hasincomplete ? 'incomplete' : 'match');

        return [
            'status' => $status,
            'clean' => $status === 'match',
            'exit_code' => $status === 'match' ? 0 : ($status === 'drift' ? 1 : 2),
            'manifest_id' => $manifestid,
            'manifest_sha256' => $manifesthash,
            'import' => [
                'manifest_id' => (string) $manifestrecord->manifestid,
                'manifest_sha256' => strtolower((string) $manifestrecord->manifesthash),
                'parent_manifest_id' => (string) ($manifestrecord->parentmanifestid ?? ''),
                'parent_manifest_sha256' => strtolower((string) ($manifestrecord->parentmanifesthash ?? '')),
                'apply_plan_sha256' => strtolower((string) ($manifestrecord->applyplanhash ?? '')),
                'source_snapshot_sha256' => strtolower((string) $manifestrecord->sourcehash),
                'source_plugin_version' => (int) $manifestrecord->sourceversion,
                'manifest_schema_version' => (int) ($manifest['schema_version'] ?? 0),
                'course_id' => (int) $manifestrecord->courseid,
                'reviewed_at' => max(0, (int) $manifestrecord->approvedat),
                'applied_at' => max(0, (int) $manifestrecord->timecreated),
                'installed_plugin_version' => max(0, (int) get_config('local_rtcsync', 'version')),
                'database_schema_version' => max(0, (int) get_config('local_rtcsync', 'version')),
            ],
            'lineage_chain' => $lineagechain['public'],
            'record_outcomes' => $lineagechain['record_outcomes'],
            'course_id' => (int) $manifestrecord->courseid,
            'source_artifact_verification' => [
                'verified' => $sourceartifactverified
                    && preg_match('/^[a-f0-9]{64}$/', $sourceartifacthash) === 1
                    && hash_equals(strtolower((string) ($manifest['source_snapshot_sha256'] ?? '')), $sourceartifacthash)
                    && $sourceartifactverifiedat > 0,
                'sha256' => preg_match('/^[a-f0-9]{64}$/', $sourceartifacthash) === 1 ? $sourceartifacthash : '',
                'verified_at' => max(0, $sourceartifactverifiedat),
            ],
            'counts' => $counts,
            'issues' => $issues,
            'interpretation' => 'A match means the target matches this import. It does not authenticate the source, '
                . 'reviewer, approval, or operator declarations.',
        ];
    }

    /** Compare import-level provenance declarations without authenticating them. */
    private static function compare_manifest_record(
        array &$issues,
        \stdClass $record,
        array $manifest,
        string $manifesthash,
        int $entrycount
    ): void {
        $review = is_array($manifest['review'] ?? null) ? $manifest['review'] : [];
        $approvedat = is_string($review['approved_at'] ?? null) ? strtotime($review['approved_at']) : false;
        $expected = [
            'manifesthash' => $manifesthash,
            'sourcehash' => strtolower((string) ($manifest['source_snapshot_sha256'] ?? '')),
            'courseidnumber' => (string) ($manifest['course_idnumber'] ?? ''),
            'sourceversion' => (int) ($manifest['source_plugin_version'] ?? 0),
            'approvedby' => trim((string) ($review['approved_by'] ?? '')),
            'approvalref' => trim((string) ($review['approval_reference'] ?? '')),
            'approvedat' => $approvedat === false ? 0 : $approvedat,
            'entrycount' => $entrycount,
        ];
        foreach ($expected as $field => $value) {
            if ((string) $record->{$field} !== (string) $value) {
                $issues['inconsistent_import_fields'][] = $field;
            }
        }
        if (trim((string) $record->operator) === '' || (int) $record->actorid < 0) {
            $issues['inconsistent_import_fields'][] = 'operator_declaration';
        }
        $declaredsourcehash = strtolower((string) ($manifest['source_snapshot_sha256'] ?? ''));
        $storedartifacthash = strtolower((string) ($record->sourcefilehash ?? ''));
        if ((int) ($record->sourcefileverified ?? 0) === 1) {
            if (!preg_match('/^[a-f0-9]{64}$/', $storedartifacthash)
                    || !hash_equals($declaredsourcehash, $storedartifacthash)) {
                $issues['inconsistent_import_fields'][] = 'sourcefilehash';
            }
            if ((int) ($record->sourcefileverifiedat ?? 0) <= 0) {
                $issues['inconsistent_import_fields'][] = 'sourcefileverifiedat';
            }
        } else if ((int) ($record->sourcefileverified ?? 0) !== 0) {
            $issues['inconsistent_import_fields'][] = 'sourcefileverified';
        }
        $applyplanhash = strtolower((string) ($record->applyplanhash ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $applyplanhash)) {
            $issues['missing_apply_plan_hashes'][] = 'apply_plan_hash';
        }
        $expectedparentid = is_array($manifest['amendment'] ?? null)
            ? trim((string) ($manifest['amendment']['parent_manifest_id'] ?? '')) : '';
        $expectedparenthash = is_array($manifest['amendment'] ?? null)
            ? strtolower((string) ($manifest['amendment']['parent_manifest_sha256'] ?? '')) : '';
        if ((string) ($record->parentmanifestid ?? '') !== $expectedparentid
                || strtolower((string) ($record->parentmanifesthash ?? '')) !== $expectedparenthash) {
            $issues['inconsistent_import_fields'][] = 'parent_manifest_link';
        }
    }

    /** Read and verify the root-to-head append-only amendment chain. */
    private static function verify_lineage_chain(
        string $requestedid,
        \stdClass $requested,
        array $requestedmanifest,
        string $requestedjson
    ): array
    {
        global $DB;

        $errors = [];
        $backwards = [$requested];
        $visited = [$requestedid => true];
        $current = $requested;
        while (trim((string) ($current->parentmanifestid ?? '')) !== '') {
            $parentid = trim((string) $current->parentmanifestid);
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', $parentid)
                    || isset($visited[$parentid])) {
                $errors[] = 'invalid_or_cyclic_parent_link';
                break;
            }
            $parent = $DB->get_record('local_rtcsync_linemanifest', ['manifestid' => $parentid], '*', IGNORE_MISSING);
            if (!$parent) {
                $errors[] = 'missing_parent_import';
                break;
            }
            if (!preg_match('/^[a-f0-9]{64}$/i', (string) ($current->parentmanifesthash ?? ''))
                    || !hash_equals(strtolower((string) $parent->manifesthash),
                        strtolower((string) $current->parentmanifesthash))) {
                $errors[] = 'parent_manifest_hash_mismatch';
            }
            $visited[$parentid] = true;
            $backwards[] = $parent;
            $current = $parent;
        }
        $chain = array_reverse($backwards);
        $root = reset($chain);
        if ($root && (trim((string) ($root->parentmanifestid ?? '')) !== ''
                || trim((string) ($root->parentmanifesthash ?? '')) !== '')) {
            $errors[] = 'root_import_has_parent_link';
        }

        // Follow a single descendant at every step. Forks are drift and do not
        // select a branch for the caller.
        $leaf = end($chain);
        while ($leaf) {
            $children = $DB->get_records(
                'local_rtcsync_linemanifest',
                ['parentmanifestid' => (string) $leaf->manifestid],
                'id ASC'
            );
            if (count($children) > 1) {
                $errors[] = 'amendment_chain_fork';
                break;
            }
            if (!$children) {
                break;
            }
            $child = reset($children);
            $childid = (string) $child->manifestid;
            if (isset($visited[$childid])) {
                $errors[] = 'invalid_or_cyclic_child_link';
                break;
            }
            $visited[$childid] = true;
            $chain[] = $child;
            $leaf = $child;
        }

        $auditsbymanifest = [];
        $declarationsbymanifest = [];
        foreach ($chain as $record) {
            $recordid = (string) $record->manifestid;
            $declaration = $recordid === $requestedid ? $requestedmanifest : null;
            $manifestjson = $recordid === $requestedid ? $requestedjson : null;
            $declarationsbymanifest[$recordid] = $declaration
                ?? json_decode((string) ($record->manifestjson ?? ''), true);
            $auditsbymanifest[$recordid] = self::load_chain_audits(
                $record,
                $errors,
                $declaration,
                $manifestjson
            );
        }
        $index = 0;
        while ($index < count($chain) - 1) {
            $parent = $chain[$index];
            $child = $chain[$index + 1];
            self::verify_chain_edge(
                $parent,
                $child,
                $auditsbymanifest[(string) $parent->manifestid] ?? [],
                $auditsbymanifest[(string) $child->manifestid] ?? [],
                is_array($declarationsbymanifest[(string) $child->manifestid] ?? null)
                    ? $declarationsbymanifest[(string) $child->manifestid] : [],
                $errors
            );
            $index++;
        }

        $effective = [];
        foreach ($chain as $record) {
            $recordaudits = $auditsbymanifest[(string) $record->manifestid] ?? [];
            if ($effective) {
                foreach ($recordaudits as $key => $audit) {
                    $effective[$key] = $audit;
                }
            } else {
                $effective = $recordaudits;
            }
        }

        $leafid = $leaf ? (string) $leaf->manifestid : '';
        $effectiveoutcomes = [];
        foreach ($effective as $key => $audit) {
            $recordtype = (string) $audit->recordtype;
            $targetid = (int) $audit->targetid;
            $state = $recordtype === 'module'
                ? self::read_module_state($targetid)
                : self::read_grade_item_state($targetid);
            $recorded = json_decode((string) ($audit->resultstate ?? ''), true);
            $exists = $recordtype === 'module'
                ? (bool) ($state['course_module_exists'] ?? false)
                : (bool) ($state['target_exists'] ?? false);
            $matches = is_array($recorded) && json_last_error() === JSON_ERROR_NONE
                && self::canonical_json($recorded) === self::canonical_json($state)
                && self::audit_result_semantics_match($audit, $recorded);
            if (!$matches && $leafid !== '' && $leafid !== $requestedid) {
                $errors[] = 'chain_head_target_snapshot_mismatch';
            }
            $effectiveoutcomes[$key] = [
                'outcome' => !$exists ? 'target_missing' : ($matches ? 'target_matches_audit' : 'target_drift'),
                'fields' => $matches ? [] : ['target_snapshot'],
            ];
        }

        $recordoutcomes = [];
        foreach ($chain as $record) {
            $recordid = (string) $record->manifestid;
            foreach (($auditsbymanifest[$recordid] ?? []) as $key => $audit) {
                $recordtype = (string) $audit->recordtype;
                $deliveryids = json_decode((string) ($audit->deliveryids ?? ''), true);
                if (!is_array($deliveryids) || !array_is_list($deliveryids)) {
                    $deliveryids = [];
                }
                $effectiveaudit = $effective[$key] ?? null;
                $issuperseded = !$effectiveaudit
                    || (string) $effectiveaudit->manifestid !== $recordid;
                $recordoutcomes[] = [
                    'manifest_id' => $recordid,
                    'record_type' => $recordtype,
                    'target_id' => (int) $audit->targetid,
                    'decision' => (string) $audit->decision,
                    'delivery_ids' => array_values(array_map('intval', $deliveryids)),
                    'entry_sha256' => strtolower((string) $audit->entryhash),
                    'outcome' => $issuperseded
                        ? 'superseded_by_amendment'
                        : ($effectiveoutcomes[$key]['outcome'] ?? 'target_drift'),
                    'fields' => $issuperseded ? [] : ($effectiveoutcomes[$key]['fields'] ?? ['target_snapshot']),
                ];
            }
        }
        usort($recordoutcomes, static function(array $left, array $right): int {
            return [$left['manifest_id'], $left['record_type'], $left['target_id']]
                <=> [$right['manifest_id'], $right['record_type'], $right['target_id']];
        });

        $public = [];
        foreach ($chain as $record) {
            $public[] = [
                'manifest_id' => (string) $record->manifestid,
                'manifest_sha256' => self::safe_sha256((string) $record->manifesthash),
                'parent_manifest_id' => (string) ($record->parentmanifestid ?? ''),
                'parent_manifest_sha256' => self::safe_sha256((string) ($record->parentmanifesthash ?? '')),
                'entry_count' => max(0, (int) $record->entrycount),
                'amendment' => trim((string) ($record->parentmanifestid ?? '')) !== '',
                'apply_plan_sha256' => self::safe_sha256((string) ($record->applyplanhash ?? '')),
                'source_snapshot_sha256' => self::safe_sha256((string) $record->sourcehash),
                'source_artifact_sha256' => self::safe_sha256((string) ($record->sourcefilehash ?? '')),
                'source_artifact_verified' => (int) ($record->sourcefileverified ?? 0) === 1
                    && (int) ($record->sourcefileverifiedat ?? 0) > 0
                    && hash_equals(strtolower((string) $record->sourcehash),
                        strtolower((string) ($record->sourcefilehash ?? ''))),
                'source_artifact_verified_at' => max(0, (int) ($record->sourcefileverifiedat ?? 0)),
                'source_plugin_version' => (int) $record->sourceversion,
                'manifest_schema_version' => is_array($declarationsbymanifest[(string) $record->manifestid] ?? null)
                    ? (int) ($declarationsbymanifest[(string) $record->manifestid]['schema_version'] ?? 0)
                    : 0,
                'reviewed_at' => max(0, (int) $record->approvedat),
                'applied_at' => max(0, (int) $record->timecreated),
            ];
        }
        return [
            'public' => $public,
            'record_outcomes' => $recordoutcomes,
            'errors' => array_values(array_unique($errors)),
            'leaf_manifest_id' => $leafid,
        ];
    }

    /** Verify one parent-to-child edge and its exact carried quarantine set. */
    private static function verify_chain_edge(
        \stdClass $parent,
        \stdClass $child,
        array $parentaudits,
        array $childaudits,
        array $childmanifest,
        array &$errors
    ): void
    {
        $parentid = (string) $parent->manifestid;
        if ((string) ($child->parentmanifestid ?? '') !== $parentid
                || !preg_match('/^[a-f0-9]{64}$/i', (string) ($child->parentmanifesthash ?? ''))
                || !hash_equals(strtolower((string) $parent->manifesthash),
                    strtolower((string) ($child->parentmanifesthash ?? '')))) {
            $errors[] = 'parent_child_link_mismatch';
        }
        if (!is_array($childmanifest['amendment'] ?? null)
                || (string) ($childmanifest['amendment']['parent_manifest_id'] ?? '') !== $parentid
                || strtolower((string) ($childmanifest['amendment']['parent_manifest_sha256'] ?? ''))
                    !== strtolower((string) $parent->manifesthash)) {
            $errors[] = 'child_manifest_parent_link_mismatch';
        }
        foreach (['courseid', 'courseidnumber', 'sourcehash', 'sourceversion'] as $field) {
            if ((string) $parent->{$field} !== (string) $child->{$field}) {
                $errors[] = 'parent_child_import_metadata_mismatch';
                break;
            }
        }
        foreach ([$parent, $child] as $record) {
            $artifacthash = strtolower((string) ($record->sourcefilehash ?? ''));
            if ((int) ($record->sourcefileverified ?? 0) !== 1
                    || (int) ($record->sourcefileverifiedat ?? 0) <= 0
                    || !preg_match('/^[a-f0-9]{64}$/', $artifacthash)
                    || !hash_equals(strtolower((string) $record->sourcehash), $artifacthash)
                    || !preg_match('/^[a-f0-9]{64}$/', strtolower((string) ($record->applyplanhash ?? '')))) {
                $errors[] = 'parent_child_verification_evidence_incomplete';
                break;
            }
        }

        $expected = [];
        foreach ($parentaudits as $key => $audit) {
            if ((string) $audit->decision !== 'quarantine') {
                continue;
            }
            if (!self::valid_quarantine_parent_state($audit)) {
                $errors[] = 'parent_quarantine_state_invalid';
                continue;
            }
            $expected[$key] = $audit;
        }
        if (!$expected || count($expected) !== count($childaudits)
                || (int) $child->entrycount !== count($childaudits)) {
            $errors[] = 'amendment_quarantine_coverage_mismatch';
        }

        foreach ($expected as $key => $parentaudit) {
            $childaudit = $childaudits[$key] ?? null;
            if (!$childaudit) {
                $errors[] = 'amendment_quarantine_coverage_mismatch';
                continue;
            }
            $parentstate = json_decode((string) ($parentaudit->resultstate ?? ''), true);
            $childparentstate = json_decode((string) ($childaudit->parentresultstate ?? ''), true);
            $parentsource = json_decode((string) $parentaudit->sourceidentity, true);
            $childsource = json_decode((string) $childaudit->sourceidentity, true);
            $parenttarget = json_decode((string) $parentaudit->targetidentity, true);
            $childtarget = json_decode((string) $childaudit->targetidentity, true);
            if ((string) ($childaudit->parententryhash ?? '') !== (string) $parentaudit->entryhash
                    || !is_array($parentstate) || !is_array($childparentstate)
                    || self::canonical_json($parentstate) !== self::canonical_json($childparentstate)
                    || !is_array($parentsource) || !is_array($childsource)
                    || self::canonical_json($parentsource) !== self::canonical_json($childsource)
                    || !is_array($parenttarget) || !is_array($childtarget)
                    || self::canonical_json($parenttarget) !== self::canonical_json($childtarget)) {
                $errors[] = 'amendment_parent_record_link_mismatch';
            }
            $allowed = (string) $childaudit->recordtype === 'module'
                ? ['current', 'historical', 'quarantine']
                : ['map', 'quarantine'];
            if (!in_array((string) $childaudit->decision, $allowed, true)) {
                $errors[] = 'amendment_decision_invalid';
            }
        }
        foreach ($childaudits as $key => $audit) {
            if (!isset($expected[$key])) {
                $errors[] = 'amendment_contains_nonquarantined_record';
            }
        }
    }

    /** Load each chain import's exact manifest and verify every stored entry against its audit row. */
    private static function load_chain_audits(
        \stdClass $record,
        array &$errors,
        ?array $declaration = null,
        ?string $providedjson = null
    ): array
    {
        global $DB;

        $storedjson = (string) ($record->manifestjson ?? '');
        $manifestjson = $providedjson ?? $storedjson;
        if ($manifestjson === '') {
            $errors[] = 'chain_manifest_content_missing';
        } else if (!hash_equals(strtolower((string) $record->manifesthash), hash('sha256', $manifestjson))) {
            $errors[] = 'chain_manifest_content_hash_mismatch';
        }
        if ($providedjson !== null && $storedjson !== '' && !hash_equals($storedjson, $providedjson)) {
            $errors[] = 'requested_manifest_content_mismatch';
        }
        $manifest = $declaration ?? json_decode($manifestjson, true);
        if (!is_array($manifest) || !self::manifest_has_supported_contract($manifest)
                || (string) ($manifest['manifest_id'] ?? '') !== (string) $record->manifestid
                || (string) ($manifest['course_idnumber'] ?? '') !== (string) $record->courseidnumber
                || strtolower((string) ($manifest['source_snapshot_sha256'] ?? ''))
                    !== strtolower((string) $record->sourcehash)
                || (int) ($manifest['source_plugin_version'] ?? 0) !== (int) $record->sourceversion) {
            $errors[] = 'chain_manifest_declaration_inconsistent';
            $manifest = [];
        }
        $manifestparentid = is_array($manifest['amendment'] ?? null)
            ? (string) ($manifest['amendment']['parent_manifest_id'] ?? '') : '';
        $manifestparenthash = is_array($manifest['amendment'] ?? null)
            ? strtolower((string) ($manifest['amendment']['parent_manifest_sha256'] ?? '')) : '';
        if ((string) ($record->parentmanifestid ?? '') !== $manifestparentid
                || strtolower((string) ($record->parentmanifesthash ?? '')) !== $manifestparenthash) {
            $errors[] = 'chain_manifest_parent_metadata_inconsistent';
        }
        $review = is_array($manifest['review'] ?? null) ? $manifest['review'] : [];
        $approvedat = is_string($review['approved_at'] ?? null) ? strtotime($review['approved_at']) : false;
        if ($approvedat === false
                || (string) ($record->approvedby ?? '') !== trim((string) ($review['approved_by'] ?? ''))
                || (string) ($record->approvalref ?? '') !== trim((string) ($review['approval_reference'] ?? ''))
                || (int) ($record->approvedat ?? 0) !== $approvedat
                || trim((string) ($record->operator ?? '')) === ''
                || (int) ($record->actorid ?? -1) < 0) {
            $errors[] = 'chain_manifest_provenance_inconsistent';
        }

        $expected = [];
        foreach (($manifest['entries'] ?? []) as $entry) {
            $targetid = (int) ($entry['target']['cmid'] ?? 0);
            $key = 'module:' . $targetid;
            if ($targetid <= 0 || isset($expected[$key])) {
                $errors[] = 'chain_manifest_target_set_invalid';
            }
            $expected[$key] = ['recordtype' => 'module', 'targetid' => $targetid, 'entry' => $entry];
        }
        foreach (($manifest['grade_items'] ?? []) as $entry) {
            $targetid = (int) ($entry['target']['itemid'] ?? 0);
            $key = 'gradeitem:' . $targetid;
            if ($targetid <= 0 || isset($expected[$key])) {
                $errors[] = 'chain_manifest_target_set_invalid';
            }
            $expected[$key] = ['recordtype' => 'gradeitem', 'targetid' => $targetid, 'entry' => $entry];
        }

        $result = [];
        $rows = $DB->get_records('local_rtcsync_lineageaudit', ['manifestid' => (string) $record->manifestid], 'id ASC');
        foreach ($rows as $audit) {
            $key = (string) $audit->recordtype . ':' . (int) $audit->targetid;
            if (!in_array((string) $audit->recordtype, ['module', 'gradeitem'], true)
                    || isset($result[$key])
                    || (string) $audit->manifesthash !== (string) $record->manifesthash
                    || (int) $audit->courseid !== (int) $record->courseid
                    || !preg_match('/^[a-f0-9]{64}$/i', (string) $audit->entryhash)) {
                $errors[] = 'chain_audit_import_inconsistent';
            }
            if (trim((string) ($record->parentmanifestid ?? '')) === ''
                    && (trim((string) ($audit->parententryhash ?? '')) !== ''
                        || trim((string) ($audit->parentresultstate ?? '')) !== '')) {
                $errors[] = 'root_audit_has_parent_link';
            }
            $result[$key] = $audit;
        }
        if (count($rows) !== (int) $record->entrycount
                || count($rows) !== count($expected)) {
            $errors[] = 'chain_audit_coverage_incomplete';
        }
        foreach ($expected as $key => $expectedrecord) {
            $audit = $result[$key] ?? null;
            if (!$audit || self::audit_changed_fields(
                    $audit,
                    $expectedrecord,
                    (int) $record->courseid,
                    (string) $record->manifesthash,
                    (string) $record->manifestid,
                    (int) $record->timecreated
            )) {
                $errors[] = 'chain_manifest_audit_mismatch';
            }
        }
        return $result;
    }

    /** Parent amendments are allowed only from an audited, still-unverified quarantine snapshot. */
    private static function valid_quarantine_parent_state(\stdClass $audit): bool
    {
        $state = json_decode((string) ($audit->resultstate ?? ''), true);
        if (!is_array($state) || json_last_error() !== JSON_ERROR_NONE
                || ($state['record_type'] ?? null) !== (string) $audit->recordtype
                || ($state['target_id'] ?? null) !== (int) $audit->targetid) {
            return false;
        }
        $mapping = $state['mapping'] ?? null;
        if ((string) $audit->recordtype === 'module') {
            if (!is_array($mapping)
                    || (int) ($mapping['lineage_status'] ?? -1) !== delivery_activity_access::LINEAGE_QUARANTINED
                    || (string) ($mapping['manifest_id'] ?? '') !== (string) $audit->manifestid) {
                return false;
            }
            foreach (($state['history'] ?? []) as $history) {
                if ((int) ($history['lineage_verified'] ?? 0) === 1) {
                    return false;
                }
            }
            return true;
        }
        return !is_array($mapping)
            || ((int) ($mapping['lineage_verified'] ?? 0) === 0
                && (string) ($mapping['manifest_id'] ?? '') === (string) $audit->manifestid);
    }

    /** Check a head audit snapshot against its explicit decision without exposing learner data. */
    private static function audit_result_semantics_match(\stdClass $audit, array $state): bool
    {
        $recordtype = (string) $audit->recordtype;
        $decision = (string) $audit->decision;
        $targetid = (int) $audit->targetid;
        if (($state['record_type'] ?? null) !== $recordtype || ($state['target_id'] ?? null) !== $targetid) {
            return false;
        }
        $target = json_decode((string) $audit->targetidentity, true);
        $deliveryids = json_decode((string) $audit->deliveryids, true);
        if (!is_array($target) || !is_array($deliveryids) || !array_is_list($deliveryids)) {
            return false;
        }
        $courseid = (int) $audit->courseid;
        if ($recordtype === 'module') {
            $identity = [
                'course_id' => $courseid,
                'cmid' => (int) ($target['cmid'] ?? 0),
                'modname' => (string) ($target['modname'] ?? ''),
                'instance_id' => (int) ($target['instanceid'] ?? 0),
                'idnumber' => (string) ($target['idnumber'] ?? ''),
            ];
            $expectedidentityhash = hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $mapping = $state['mapping'] ?? null;
            if (!is_array($mapping) || (int) ($mapping['course_id'] ?? 0) !== $courseid
                    || (string) ($state['identity_sha256'] ?? '') !== $expectedidentityhash
                    || (string) ($state['availability_sha256'] ?? '')
                        !== (string) ($mapping['managed_availability_sha256'] ?? '')) {
                return false;
            }
            $status = match ($decision) {
                'current' => delivery_activity_access::LINEAGE_CURRENT,
                'historical' => delivery_activity_access::LINEAGE_HISTORICAL,
                'archive' => delivery_activity_access::LINEAGE_ARCHIVED,
                'dispose' => delivery_activity_access::LINEAGE_DISPOSED,
                default => delivery_activity_access::LINEAGE_QUARANTINED,
            };
            if ((int) ($mapping['lineage_status'] ?? -1) !== $status
                    || (string) ($mapping['manifest_id'] ?? '') !== (string) $audit->manifestid) {
                return false;
            }
            if ($decision === 'current') {
                return count($deliveryids) === 1 && is_int($deliveryids[0]) && $deliveryids[0] > 0
                    && (int) ($mapping['delivery_id'] ?? 0) === $deliveryids[0]
                    && (int) ($mapping['grouping_id'] ?? 0) === self::matching_grouping_id($courseid, $deliveryids[0])
                    && (int) ($state['course_module_grouping_id'] ?? 0) === (int) $mapping['grouping_id']
                    && empty($state['history']);
            }
            if ($decision === 'historical') {
                if (count($deliveryids) !== 1 || !is_int($deliveryids[0]) || $deliveryids[0] <= 0
                        || (int) ($mapping['delivery_id'] ?? 0) !== 0
                        || (int) ($mapping['grouping_id'] ?? 0) !== -max(1, $targetid)) {
                    return false;
                }
                $verified = array_values(array_filter($state['history'] ?? [], static function($history) use ($deliveryids, $audit): bool {
                    return (int) ($history['lineage_verified'] ?? 0) === 1
                        && (int) ($history['delivery_id'] ?? 0) === $deliveryids[0]
                        && (string) ($history['manifest_id'] ?? '') === (string) $audit->manifestid;
                }));
                return count($verified) === 1 && count(array_filter($state['history'] ?? [], static function($history): bool {
                    return (int) ($history['lineage_verified'] ?? 0) === 1;
                })) === 1;
            }
            return $deliveryids === [] && (int) ($mapping['delivery_id'] ?? 0) === 0
                && (int) ($mapping['grouping_id'] ?? 0) === -max(1, $targetid)
                && count(array_filter($state['history'] ?? [], static function($history): bool {
                    return (int) ($history['lineage_verified'] ?? 0) === 1;
                })) === 0;
        }

        $identity = [
            'course_id' => $courseid,
            'item_id' => (int) ($target['itemid'] ?? 0),
            'item_type' => (string) ($target['itemtype'] ?? ''),
            'item_module' => (string) ($target['itemmodule'] ?? ''),
            'item_instance' => (int) ($target['iteminstance'] ?? 0),
            'idnumber' => (string) ($target['idnumber'] ?? ''),
        ];
        if ((string) ($state['identity_sha256'] ?? '') !== hash('sha256', json_encode(
                $identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))) {
            return false;
        }
        $mapping = $state['mapping'] ?? null;
        if ($decision === 'map') {
            return is_array($mapping) && count($deliveryids) === 1 && is_int($deliveryids[0])
                && (int) ($mapping['course_id'] ?? 0) === $courseid
                && (int) ($mapping['delivery_id'] ?? 0) === $deliveryids[0]
                && (int) ($mapping['lineage_verified'] ?? 0) === 1
                && (string) ($mapping['manifest_id'] ?? '') === (string) $audit->manifestid
                && self::matching_grouping_id($courseid, $deliveryids[0]) > 0;
        }
        return $decision === 'quarantine' && $deliveryids === []
            && (!is_array($mapping) || ((int) ($mapping['course_id'] ?? 0) === $courseid
                && (int) ($mapping['lineage_verified'] ?? -1) === 0
                && (string) ($mapping['manifest_id'] ?? '') === (string) $audit->manifestid));
    }

    /** Confirm the persisted course key still resolves to the imported stable course. */
    private static function compare_course_identity(array &$issues, \stdClass $record, array $manifest): void
    {
        global $DB;

        $courseidnumber = trim((string) ($manifest['course_idnumber'] ?? ''));
        $course = $DB->get_record('course', ['id' => (int) $record->courseid], 'id,idnumber', IGNORE_MISSING);
        $matches = $DB->get_records('course', ['idnumber' => $courseidnumber], 'id ASC', 'id');
        if (!$course || (string) $course->idnumber !== $courseidnumber || count($matches) !== 1
                || (int) reset($matches)->id !== (int) $record->courseid) {
            $issues['inconsistent_import_fields'][] = 'course_identity';
        }
    }

    /** Find the audit fields which differ from the exact reviewed manifest entry. */
    private static function audit_changed_fields(
        \stdClass $audit,
        array $expected,
        int $courseid,
        string $manifesthash,
        string $manifestid,
        int $timecreated
    ): array {
        $entry = $expected['entry'];
        $recordtype = $expected['recordtype'];
        $deliveryids = $recordtype === 'module'
            ? ($entry['delivery_ids'] ?? null)
            : (((string) ($entry['decision'] ?? '') === 'map' && is_int($entry['delivery_id'] ?? null))
                ? [$entry['delivery_id']] : []);
        $entryjson = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $entryhash = hash('sha256', $entryjson === false ? '' : $entryjson);
        $source = self::normalise_identity($entry['source'] ?? [], $recordtype);
        $target = self::normalise_identity($entry['target'] ?? [], $recordtype);
        $fields = [];

        if ((string) $audit->manifesthash !== $manifesthash) {
            $fields[] = 'manifest_hash';
        }
        if ((int) $audit->courseid !== $courseid) {
            $fields[] = 'course_id';
        }
        if ((string) $audit->recordtype !== $recordtype) {
            $fields[] = 'record_type';
        }
        if ((int) $audit->targetid !== (int) $expected['targetid']) {
            $fields[] = 'target_id';
        }
        if ((string) $audit->decision !== (string) ($entry['decision'] ?? '')) {
            $fields[] = 'decision';
        }
        if (self::canonical_json(json_decode((string) $audit->deliveryids, true))
                !== self::canonical_json($deliveryids)) {
            $fields[] = 'delivery_ids';
        }
        if (self::canonical_json(json_decode((string) $audit->sourceidentity, true)) !== self::canonical_json($source)) {
            $fields[] = 'source_identity';
        }
        if (self::canonical_json(json_decode((string) $audit->targetidentity, true)) !== self::canonical_json($target)) {
            $fields[] = 'target_identity';
        }
        if ((string) $audit->reason !== trim((string) ($entry['reason'] ?? ''))) {
            $fields[] = 'reason';
        }
        if ((string) $audit->entryhash !== $entryhash) {
            $fields[] = 'entry_hash';
        }
        if ((int) $audit->timecreated !== $timecreated || $manifestid === '') {
            $fields[] = 'audit_provenance';
        }
        return $fields;
    }

    /** Compare rows carrying this import ID with expected manifest target IDs. */
    private static function compare_orphaned_target_mappings(
        array &$issues,
        string $manifestid,
        array $expectedmoduleids,
        array $expectedgradeids
    ): void {
        global $DB;

        foreach ($DB->get_records('local_rtcsync_deliverycm', ['lineagemanifestid' => $manifestid], 'cmid ASC', 'cmid') as $row) {
            if (!isset($expectedmoduleids[(int) $row->cmid])) {
                $issues['extra_target_records'][] = self::target_identifier('module', (int) $row->cmid);
            }
        }
        foreach ($DB->get_records('local_rtcsync_deliverycmhist', ['lineagemanifestid' => $manifestid],
                'cmid ASC, deliveryid ASC', 'id,cmid,deliveryid') as $row) {
            if (!isset($expectedmoduleids[(int) $row->cmid])) {
                $issues['extra_target_records'][] = self::target_identifier('module', (int) $row->cmid);
            }
        }
        foreach ($DB->get_records(
                'local_rtcsync_deliverygrade',
                ['lineagemanifestid' => $manifestid],
                'gradeitemid ASC',
                'gradeitemid'
        ) as $row) {
            if (!isset($expectedgradeids[(int) $row->gradeitemid])) {
                $issues['extra_target_records'][] = self::target_identifier('gradeitem', (int) $row->gradeitemid);
            }
        }
    }

    /** Read a module and all its lineage state, excluding activity or learner content. */
    private static function read_module_state(int $cmid): array
    {
        global $DB;

        $module = $DB->get_record_sql(
            'SELECT cm.id, cm.course, cm.instance, cm.idnumber, cm.groupingid, cm.availability,
                    m.name AS modname
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = :cmid',
            ['cmid' => $cmid],
            IGNORE_MISSING
        );
        $mapping = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => $cmid], '*', IGNORE_MISSING);
        $history = array_values($DB->get_records('local_rtcsync_deliverycmhist', ['cmid' => $cmid],
            'courseid ASC, deliveryid ASC'));
        $identity = $module ? [
            'course_id' => (int) $module->course,
            'cmid' => (int) $module->id,
            'modname' => (string) $module->modname,
            'instance_id' => (int) $module->instance,
            'idnumber' => (string) $module->idnumber,
        ] : null;

        return [
            'version' => 1,
            'record_type' => 'module',
            'target_id' => $cmid,
            'course_module_exists' => (bool) $module,
            'identity_sha256' => $identity === null ? '' : hash('sha256', json_encode(
                $identity,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            )),
            'course_module_grouping_id' => $module ? (int) $module->groupingid : null,
            'availability_sha256' => $module ? hash('sha256', (string) $module->availability) : '',
            'mapping' => $mapping ? [
                'course_id' => (int) $mapping->courseid,
                'delivery_id' => (int) $mapping->deliveryid,
                'grouping_id' => (int) $mapping->groupingid,
                'lineage_status' => (int) $mapping->lineagestatus,
                'manifest_id' => (string) ($mapping->lineagemanifestid ?? ''),
                'managed_availability_sha256' => hash('sha256', (string) $mapping->managedavailability),
            ] : null,
            'history' => array_map(static function($row): array {
                return [
                    'course_id' => (int) $row->courseid,
                    'delivery_id' => (int) $row->deliveryid,
                    'lineage_verified' => (int) $row->lineageverified,
                    'manifest_id' => (string) ($row->lineagemanifestid ?? ''),
                ];
            }, $history),
        ];
    }

    /** Read a grade item and its plugin mapping without reading grade values. */
    private static function read_grade_item_state(int $itemid): array
    {
        global $DB;

        $item = $DB->get_record('grade_items', ['id' => $itemid],
            'id,courseid,itemtype,itemmodule,iteminstance,idnumber', IGNORE_MISSING);
        $mapping = $DB->get_record('local_rtcsync_deliverygrade', ['gradeitemid' => $itemid], '*', IGNORE_MISSING);
        $identity = $item ? [
            'course_id' => (int) $item->courseid,
            'item_id' => (int) $item->id,
            'item_type' => (string) $item->itemtype,
            'item_module' => (string) ($item->itemmodule ?? ''),
            'item_instance' => (int) ($item->iteminstance ?? 0),
            'idnumber' => (string) $item->idnumber,
        ] : null;

        return [
            'version' => 1,
            'record_type' => 'gradeitem',
            'target_id' => $itemid,
            'target_exists' => (bool) $item,
            'identity_sha256' => $identity === null ? '' : hash('sha256', json_encode(
                $identity,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            )),
            'mapping' => $mapping ? [
                'course_id' => (int) $mapping->courseid,
                'delivery_id' => (int) $mapping->deliveryid,
                'lineage_verified' => (int) $mapping->lineageverified,
                'manifest_id' => (string) ($mapping->lineagemanifestid ?? ''),
            ] : null,
        ];
    }

    /** Validate current target semantics in addition to comparing the recorded snapshot. */
    private static function target_changed_fields(
        string $recordtype,
        array $state,
        array $entry,
        int $courseid,
        string $manifestid
    ): array {
        global $DB;

        $target = $entry['target'];
        $decision = (string) ($entry['decision'] ?? '');
        $fields = [];
        if ($recordtype === 'module') {
            $identity = [
                'course_id' => $courseid,
                'cmid' => (int) ($target['cmid'] ?? 0),
                'modname' => (string) ($target['modname'] ?? ''),
                'instance_id' => (int) ($target['instanceid'] ?? 0),
                'idnumber' => (string) ($target['idnumber'] ?? ''),
            ];
            $expectedhash = hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            if (!$state['course_module_exists'] || !hash_equals($expectedhash, (string) $state['identity_sha256'])) {
                $fields[] = 'target_identity';
            }

            $mapping = $state['mapping'];
            if (!is_array($mapping)) {
                $fields[] = 'module_mapping_missing';
                return $fields;
            }
            $status = match ($decision) {
                'current' => delivery_activity_access::LINEAGE_CURRENT,
                'historical' => delivery_activity_access::LINEAGE_HISTORICAL,
                'archive' => delivery_activity_access::LINEAGE_ARCHIVED,
                'dispose' => delivery_activity_access::LINEAGE_DISPOSED,
                default => delivery_activity_access::LINEAGE_QUARANTINED,
            };
            $deliveryid = $decision === 'current' ? (int) ($entry['delivery_ids'][0] ?? 0) : 0;
            $groupingid = $decision === 'current'
                ? self::matching_grouping_id($courseid, $deliveryid)
                : -max(1, (int) $target['cmid']);
            if ((int) $mapping['course_id'] !== $courseid || (int) $mapping['delivery_id'] !== $deliveryid
                    || (int) $mapping['lineage_status'] !== $status || (string) $mapping['manifest_id'] !== $manifestid
                    || (int) $mapping['grouping_id'] !== $groupingid) {
                $fields[] = 'module_mapping';
            }
            if ((string) $state['availability_sha256'] !== (string) $mapping['managed_availability_sha256']) {
                $fields[] = 'module_availability_guard';
            }
            if ($decision === 'current') {
                if ((int) $state['course_module_grouping_id'] !== $groupingid) {
                    $fields[] = 'current_grouping';
                }
                if (!empty($state['history'])) {
                    $fields[] = 'unexpected_module_history';
                }
            } else if ($decision === 'historical') {
                $deliveryid = (int) ($entry['delivery_ids'][0] ?? 0);
                $expectedhistory = [[
                    'course_id' => $courseid,
                    'delivery_id' => $deliveryid,
                    'lineage_verified' => 1,
                    'manifest_id' => $manifestid,
                ]];
                if (self::canonical_json($state['history']) !== self::canonical_json($expectedhistory)) {
                    $fields[] = 'historical_delivery_mapping';
                }
                if (self::matching_grouping_id($courseid, $deliveryid) <= 0) {
                    $fields[] = 'historical_delivery_reference';
                }
            }
            if ($decision === 'current' && $groupingid <= 0) {
                $fields[] = 'current_delivery_reference';
            }
            return $fields;
        }

        $identity = [
            'course_id' => $courseid,
            'item_id' => (int) ($target['itemid'] ?? 0),
            'item_type' => (string) ($target['itemtype'] ?? ''),
            'item_module' => (string) ($target['itemmodule'] ?? ''),
            'item_instance' => (int) ($target['iteminstance'] ?? 0),
            'idnumber' => (string) ($target['idnumber'] ?? ''),
        ];
        $expectedhash = hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if (!$state['target_exists'] || !hash_equals($expectedhash, (string) $state['identity_sha256'])) {
            $fields[] = 'target_identity';
        }
        $mapping = $state['mapping'];
        if ($decision === 'map') {
            $deliveryid = (int) ($entry['delivery_id'] ?? 0);
            if (!is_array($mapping) || (int) $mapping['course_id'] !== $courseid
                    || (int) $mapping['delivery_id'] !== $deliveryid || (int) $mapping['lineage_verified'] !== 1
                    || (string) $mapping['manifest_id'] !== $manifestid) {
                $fields[] = 'grade_item_mapping';
            }
            if (self::matching_grouping_id($courseid, $deliveryid) <= 0) {
                $fields[] = 'grade_item_delivery_reference';
            }
        } else if (is_array($mapping)
                && ((int) $mapping['course_id'] !== $courseid || (int) $mapping['lineage_verified'] !== 0
                    || (string) $mapping['manifest_id'] !== $manifestid)) {
            $fields[] = 'grade_item_disposition';
        }
        return $fields;
    }

    /** Return the sole grouping for an explicit course/delivery identity, or zero when ambiguous. */
    private static function matching_grouping_id(int $courseid, int $deliveryid): int
    {
        global $DB;

        if ($deliveryid <= 0) {
            return 0;
        }
        $groupings = $DB->get_records('groupings', [
            'courseid' => $courseid,
            'idnumber' => 'rtc-delivery-grouping:' . $deliveryid,
        ], 'id ASC', 'id');
        return count($groupings) === 1 ? (int) reset($groupings)->id : 0;
    }

    /** Return stable, redacted import status for malformed or unknown identities. */
    private static function invalid_result(string $manifestid, string $reason): array
    {
        return [
            'status' => 'invalid',
            'clean' => false,
            'exit_code' => 2,
            'manifest_id' => $manifestid,
            'manifest_sha256' => '',
            'import' => null,
            'course_id' => null,
            'lineage_chain' => [],
            'record_outcomes' => [],
            'counts' => [
                'expected_records' => 0,
                'verified_records' => 0,
                'missing_audit_records' => 0,
                'extra_audit_records' => 0,
                'changed_audit_records' => 0,
                'missing_target_records' => 0,
                'extra_target_records' => 0,
                'changed_target_records' => 0,
                'missing_recorded_results' => 0,
                'missing_apply_plan_hashes' => 0,
                'missing_source_artifact_verification' => 0,
                'inconsistent_records' => 0,
                'inconsistent_import_fields' => 0,
                'lineage_chain_errors' => 0,
            ],
            'issues' => ['validation' => [$reason], 'lineage_chain_errors' => []],
            'interpretation' => 'Verification could not establish a matching import. It does not authenticate the '
                . 'source, reviewer, approval, or operator declaration.',
        ];
    }

    /** Keep issue output to record type, numeric target ID, and field names. */
    private static function target_identifier(string $recordtype, int $targetid, array $fields = []): array
    {
        return [
            'record_type' => in_array($recordtype, ['module', 'gradeitem'], true) ? $recordtype : 'unknown',
            'target_id' => max(0, $targetid),
            'fields' => array_values(array_unique($fields)),
        ];
    }

    /** Remove duplicate identifiers while preserving deterministic result order. */
    private static function unique_identifiers(array $rows): array
    {
        $unique = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['record_type'], $row['target_id'])) {
                continue;
            }
            $key = $row['record_type'] . ':' . $row['target_id'];
            if (!isset($unique[$key])) {
                $unique[$key] = $row;
            } else {
                $unique[$key]['fields'] = array_values(array_unique(array_merge(
                    $unique[$key]['fields'] ?? [],
                    $row['fields'] ?? []
                )));
            }
        }
        ksort($unique, SORT_STRING);
        return array_values($unique);
    }

    /** Normalize a manifest identity into the exact fields stored in each audit row. */
    private static function normalise_identity(array $identity, string $recordtype): array
    {
        if ($recordtype === 'module') {
            return [
                'course_idnumber' => (string) ($identity['course_idnumber'] ?? ''),
                'cmid' => (int) ($identity['cmid'] ?? 0),
                'modname' => trim((string) ($identity['modname'] ?? '')),
                'instanceid' => (int) ($identity['instanceid'] ?? 0),
                'idnumber' => (string) ($identity['idnumber'] ?? ''),
            ];
        }
        return [
            'course_idnumber' => (string) ($identity['course_idnumber'] ?? ''),
            'itemid' => (int) ($identity['itemid'] ?? 0),
            'itemtype' => (string) ($identity['itemtype'] ?? ''),
            'itemmodule' => (string) ($identity['itemmodule'] ?? ''),
            'iteminstance' => (int) ($identity['iteminstance'] ?? 0),
            'idnumber' => (string) ($identity['idnumber'] ?? ''),
        ];
    }

    /** Validate a supported declaration shape without rebuilding an apply plan. */
    private static function manifest_has_supported_contract(array $manifest): bool
    {
        $rootfields = [
            'schema_version', 'manifest_id', 'course_idnumber', 'source_snapshot_sha256',
            'source_plugin_version', 'review', 'entries', 'grade_items',
        ];
        $isamendment = ($manifest['schema_version'] ?? null) === 2;
        $allowedrootfields = $rootfields;
        if ($isamendment) {
            $allowedrootfields[] = 'amendment';
            $amendment = $manifest['amendment'] ?? null;
            if (!is_array($amendment)
                    || array_diff(array_keys($amendment), ['parent_manifest_id', 'parent_manifest_sha256'])
                    || array_diff(['parent_manifest_id', 'parent_manifest_sha256'], array_keys($amendment))
                    || !is_string($amendment['parent_manifest_id'])
                    || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', $amendment['parent_manifest_id'])
                    || !is_string($amendment['parent_manifest_sha256'])
                    || !preg_match('/^[a-f0-9]{64}$/i', $amendment['parent_manifest_sha256'])) {
                return false;
            }
        }
        if (!in_array($manifest['schema_version'] ?? null, [1, 2], true)
                || array_diff(array_keys($manifest), $allowedrootfields)
                || array_diff($rootfields, array_keys($manifest))) {
            return false;
        }
        if (!is_string($manifest['manifest_id'] ?? null)
                || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', $manifest['manifest_id'])) {
            return false;
        }
        $courseidnumber = trim((string) ($manifest['course_idnumber'] ?? ''));
        if (!is_string($manifest['course_idnumber'] ?? null)
                || !preg_match('/^rtc-subject:[1-9][0-9]*$/', $courseidnumber)
                || !preg_match('/^[a-f0-9]{64}$/', strtolower((string) ($manifest['source_snapshot_sha256'] ?? '')))
                || !is_int($manifest['source_plugin_version'] ?? null)
                || $manifest['source_plugin_version'] <= 0
                || !is_array($manifest['review'] ?? null)
                || !is_array($manifest['entries'] ?? null) || !array_is_list($manifest['entries'])
                || (!$isamendment && !$manifest['entries'])
                || !is_array($manifest['grade_items'] ?? null) || !array_is_list($manifest['grade_items'])) {
            return false;
        }
        if (!$manifest['entries'] && !$manifest['grade_items']) {
            return false;
        }
        $review = $manifest['review'];
        if (array_diff(array_keys($review), ['approved_by', 'approved_at', 'approval_reference'])
                || array_diff(['approved_by', 'approved_at', 'approval_reference'], array_keys($review))
                || !is_string($review['approved_by']) || trim($review['approved_by']) === ''
                || !is_string($review['approval_reference']) || trim($review['approval_reference']) === ''
                || !is_string($review['approved_at'])
                || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $review['approved_at'])
                || strtotime($review['approved_at']) === false) {
            return false;
        }

        foreach ($manifest['entries'] as $entry) {
            if (!is_array($entry)
                    || array_diff(array_keys($entry), ['source', 'target', 'decision', 'delivery_ids', 'reason'])
                    || array_diff(['source', 'target', 'decision', 'delivery_ids', 'reason'], array_keys($entry))
                    || !in_array($entry['decision'], $isamendment
                        ? ['current', 'historical', 'quarantine']
                        : ['current', 'historical', 'quarantine', 'archive', 'dispose'], true)
                    || !is_array($entry['delivery_ids']) || !array_is_list($entry['delivery_ids'])
                    || !is_string($entry['reason']) || trim($entry['reason']) === ''
                    || core_text::strlen($entry['reason']) > 2000) {
                return false;
            }
            if (in_array($entry['decision'], ['current', 'historical'], true)) {
                if (count($entry['delivery_ids']) !== 1 || !is_int($entry['delivery_ids'][0])
                        || $entry['delivery_ids'][0] <= 0) {
                    return false;
                }
            } else if ($entry['delivery_ids'] !== []) {
                return false;
            }
            foreach (['source', 'target'] as $side) {
                $identity = $entry[$side];
                if (!is_array($identity)
                        || array_diff(array_keys($identity), ['course_idnumber', 'cmid', 'modname', 'instanceid', 'idnumber'])
                        || array_diff(['course_idnumber', 'cmid', 'modname', 'instanceid', 'idnumber'], array_keys($identity))
                        || !is_string($identity['course_idnumber'])
                        || $identity['course_idnumber'] !== $courseidnumber
                        || !is_int($identity['cmid']) || $identity['cmid'] <= 0
                        || !is_int($identity['instanceid']) || $identity['instanceid'] <= 0
                        || !is_string($identity['modname']) || !preg_match('/^[a-z][a-z0-9_]{0,99}$/', trim($identity['modname']))
                        || !is_string($identity['idnumber']) || core_text::strlen($identity['idnumber']) > 100) {
                    return false;
                }
            }
        }

        foreach ($manifest['grade_items'] as $entry) {
            if (!is_array($entry)
                    || array_diff(array_keys($entry), ['source', 'target', 'decision', 'delivery_id', 'reason'])
                    || array_diff(['source', 'target', 'decision', 'delivery_id', 'reason'], array_keys($entry))
                    || !in_array($entry['decision'], $isamendment
                        ? ['map', 'quarantine'] : ['map', 'quarantine', 'archive', 'dispose'], true)
                    || !is_int($entry['delivery_id'])
                    || ($entry['decision'] === 'map' ? $entry['delivery_id'] <= 0 : $entry['delivery_id'] !== 0)
                    || !is_string($entry['reason']) || trim($entry['reason']) === ''
                    || core_text::strlen($entry['reason']) > 2000) {
                return false;
            }
            foreach (['source', 'target'] as $side) {
                $identity = $entry[$side];
                if (!is_array($identity)
                        || array_diff(array_keys($identity), ['course_idnumber', 'itemid', 'itemtype', 'itemmodule', 'iteminstance', 'idnumber'])
                        || array_diff(['course_idnumber', 'itemid', 'itemtype', 'itemmodule', 'iteminstance', 'idnumber'], array_keys($identity))
                        || !is_string($identity['course_idnumber'])
                        || $identity['course_idnumber'] !== $courseidnumber
                        || !is_int($identity['itemid']) || $identity['itemid'] <= 0
                        || $identity['itemtype'] !== 'manual' || $identity['itemmodule'] !== ''
                        || $identity['iteminstance'] !== 0 || !is_string($identity['idnumber'])
                        || core_text::strlen($identity['idnumber']) > 255) {
                    return false;
                }
            }
        }
        return true;
    }

    /** Canonically encode structures so database JSON key order cannot hide differences. */
    private static function canonical_json($value): string
    {
        $sort = static function($item) use (&$sort) {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $sort($child);
            }
            return $item;
        };
        $encoded = json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $encoded === false ? '' : $encoded;
    }

    /** Do not echo malformed persisted digest text into exported evidence. */
    private static function safe_sha256(string $value): string
    {
        $value = strtolower($value);
        return preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : '';
    }
}
