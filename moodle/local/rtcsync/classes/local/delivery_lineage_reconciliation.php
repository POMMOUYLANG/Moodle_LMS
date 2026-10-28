<?php

namespace local_rtcsync\local;

defined('MOODLE_INTERNAL') || die();

/** Validate and apply a data-owner-reviewed Moodle delivery-lineage manifest. */
final class delivery_lineage_reconciliation
{
    private const SCHEMA_VERSION = 1;
    private const AMENDMENT_SCHEMA_VERSION = 2;
    private const DECISIONS = ['current', 'historical', 'quarantine', 'archive', 'dispose'];

    /** Inspect a manifest without changing Moodle data. */
    public static function preview(string $json, ?string $sourceartifactpath = null): array
    {
        $prepared = self::prepare($json);
        $artifactverification = $sourceartifactpath === null
            ? self::source_artifact_not_checked()
            : source_snapshot_artifact::verify(
                $sourceartifactpath,
                (string) ($prepared['manifest']['source_snapshot_sha256'] ?? '')
            );
        if ($sourceartifactpath !== null && !$artifactverification['verified']) {
            $prepared['errors'][] = 'The supplied source snapshot artifact does not match its manifest declaration.';
        }
        $summary = $prepared['already_applied']
            ? self::summary_from_audit($prepared['manifestid'])
            : self::summary_from_operations($prepared['modules'], $prepared['gradeitems']);
        return [
            'valid' => $prepared['errors'] === [],
            'already_applied' => $prepared['already_applied'],
            'manifest_id' => $prepared['manifestid'],
            'manifest_sha256' => $prepared['manifesthash'],
            'plan_sha256' => $prepared['planhash'],
            'amendment' => $prepared['is_amendment'] ? [
                'parent_manifest_id' => (string) ($prepared['manifest']['amendment']['parent_manifest_id'] ?? ''),
                'parent_manifest_sha256' => strtolower((string) ($prepared['manifest']['amendment']['parent_manifest_sha256'] ?? '')),
                'affected_module_cmids' => array_values(array_map(
                    static fn(array $operation): int => (int) $operation['target']['cmid'],
                    $prepared['modules']
                )),
                'affected_grade_item_ids' => array_values(array_map(
                    static fn(array $operation): int => (int) $operation['target']['itemid'],
                    $prepared['gradeitems']
                )),
            ] : null,
            'source_artifact_verification' => $artifactverification,
            'course_id' => $prepared['courseid'],
            'module_entries' => $summary['module_entry_count'],
            'grade_item_entries' => $summary['grade_item_entry_count'],
            'approval_provenance' => self::declared_provenance($prepared['manifest']),
            'mapping_counts' => $summary['mapping_counts'],
            'disposition_counts' => $summary['disposition_counts'],
            'quarantine_outcomes' => $summary['quarantine_outcomes'],
            'conflict_count' => count($prepared['errors']),
            'conflicts' => $prepared['errors'],
            'errors' => $prepared['errors'],
        ];
    }

    /** Apply only a valid plan whose target snapshot still matches the dry-run. */
    public static function apply(
        string $json,
        string $expectedplanhash,
        string $operator,
        array $provenance,
        string $sourceartifactpath
    ): array
    {
        global $DB;

        if (!preg_match('/^[a-f0-9]{64}$/', $expectedplanhash)) {
            throw new \invalid_parameter_exception('Apply requires the SHA-256 plan printed by a successful dry run.');
        }
        $operator = trim(clean_param($operator, PARAM_TEXT));
        if ($operator === '' || core_text::strlen($operator) > 100) {
            throw new \invalid_parameter_exception('The importing operator is required and must be at most 100 characters.');
        }

        $prepared = self::prepare($json);
        if ($prepared['errors']) {
            throw new \invalid_parameter_exception(implode(' ', $prepared['errors']));
        }
        self::validate_apply_provenance($prepared['manifest'], $provenance);
        $sourcehash = strtolower((string) $prepared['manifest']['source_snapshot_sha256']);
        $initialartifactverification = source_snapshot_artifact::verify($sourceartifactpath, $sourcehash);
        self::require_verified_source_artifact($initialartifactverification);
        if ($prepared['already_applied']) {
            self::require_repeat_plan_hash($prepared['manifestid'], $expectedplanhash);
            return self::already_applied_result(
                $prepared['manifestid'],
                $prepared['manifesthash'],
                $initialartifactverification['sha256']
            );
        }
        if (!hash_equals($prepared['planhash'], $expectedplanhash)) {
            throw new \invalid_parameter_exception('The target state changed after dry run. Run validation again and review the new plan.');
        }

        return delivery_activity_access::with_course_access_lock(
            $prepared['courseid'],
            static function() use ($DB, $json, $expectedplanhash, $operator, $provenance,
                    $sourceartifactpath, $sourcehash, $initialartifactverification): array {
                $fresh = self::prepare($json);
                if ($fresh['errors']) {
                    throw new \invalid_parameter_exception(implode(' ', $fresh['errors']));
                }
                self::validate_apply_provenance($fresh['manifest'], $provenance);
                $artifactverification = source_snapshot_artifact::verify($sourceartifactpath, $sourcehash);
                self::require_verified_source_artifact($artifactverification);
                if (!hash_equals($initialartifactverification['sha256'], $artifactverification['sha256'])) {
                    throw new \invalid_parameter_exception('The source snapshot artifact changed during apply. No changes were made.');
                }
                if ($fresh['already_applied']) {
                    self::require_repeat_plan_hash($fresh['manifestid'], $expectedplanhash);
                    return self::already_applied_result(
                        $fresh['manifestid'],
                        $fresh['manifesthash'],
                        $artifactverification['sha256']
                    );
                }
                if (!hash_equals($fresh['planhash'], $expectedplanhash)) {
                    throw new \invalid_parameter_exception('The target state changed while acquiring the course lock. Run validation again.');
                }

                // Recheck after locking and immediately before the transaction so a
                // dry-run verification cannot be reused after the file has changed.
                $artifactverification = source_snapshot_artifact::verify($sourceartifactpath, $sourcehash);
                self::require_verified_source_artifact($artifactverification);
                if (!hash_equals($initialartifactverification['sha256'], $artifactverification['sha256'])) {
                    throw new \invalid_parameter_exception('The source snapshot artifact changed during apply. No changes were made.');
                }

                $manifest = $fresh['manifest'];
                $courseid = $fresh['courseid'];
                $transaction = $DB->start_delegated_transaction();
                $now = time();
                global $USER;
                try {
                    $DB->insert_record('local_rtcsync_linemanifest', (object) [
                        'manifestid' => $fresh['manifestid'],
                        'manifesthash' => $fresh['manifesthash'],
                        'manifestjson' => $json,
                        'sourcehash' => strtolower($manifest['source_snapshot_sha256']),
                        'sourcefilehash' => $artifactverification['sha256'],
                        'sourcefileverified' => 1,
                        'sourcefileverifiedat' => $now,
                        'applyplanhash' => $fresh['planhash'],
                        'parentmanifestid' => $fresh['is_amendment']
                            ? (string) $manifest['amendment']['parent_manifest_id'] : null,
                        'parentmanifesthash' => $fresh['is_amendment']
                            ? strtolower((string) $manifest['amendment']['parent_manifest_sha256']) : null,
                        'courseid' => $courseid,
                        'courseidnumber' => $manifest['course_idnumber'],
                        'sourceversion' => (int) $manifest['source_plugin_version'],
                        'approvedby' => trim($manifest['review']['approved_by']),
                        'approvalref' => trim($manifest['review']['approval_reference']),
                        'approvedat' => strtotime($manifest['review']['approved_at']),
                        'actorid' => (int) ($USER->id ?? 0),
                        'operator' => $operator,
                        'entrycount' => count($fresh['modules']) + count($fresh['gradeitems']),
                        'timecreated' => $now,
                    ]);

                    foreach ($fresh['modules'] as $operation) {
                        self::apply_module_operation($operation, $fresh['manifestid']);
                        self::insert_audit(
                            $operation,
                            'module',
                            $courseid,
                            $fresh['manifestid'],
                            $fresh['manifesthash'],
                            $now,
                            $operation['parentaudit'] ?? null
                        );
                    }
                    foreach ($fresh['gradeitems'] as $operation) {
                        self::apply_grade_operation($operation, $fresh['manifestid'], $fresh['is_amendment']);
                        self::insert_audit(
                            $operation,
                            'gradeitem',
                            $courseid,
                            $fresh['manifestid'],
                            $fresh['manifesthash'],
                            $now,
                            $operation['parentaudit'] ?? null
                        );
                    }

                    $transaction->allow_commit();
                } catch (\Exception $exception) {
                    $transaction->rollback($exception);
                }
                $summary = self::summary_from_operations($fresh['modules'], $fresh['gradeitems']);
                return [
                    'applied' => true,
                    'already_applied' => false,
                    'manifest_id' => $fresh['manifestid'],
                    'manifest_sha256' => $fresh['manifesthash'],
                    'plan_sha256' => $fresh['planhash'],
                    'amendment' => $fresh['is_amendment'] ? [
                        'parent_manifest_id' => (string) $manifest['amendment']['parent_manifest_id'],
                        'parent_manifest_sha256' => strtolower((string) $manifest['amendment']['parent_manifest_sha256']),
                    ] : null,
                    'operator' => $operator,
                    'approval_provenance' => self::declared_provenance($manifest),
                    'source_artifact_verification' => [
                        'checked' => true,
                        'verified' => true,
                        'sha256' => $artifactverification['sha256'],
                        'verified_at' => $now,
                    ],
                    'module_entries' => count($fresh['modules']),
                    'grade_item_entries' => count($fresh['gradeitems']),
                    'mapping_counts' => $summary['mapping_counts'],
                    'disposition_counts' => $summary['disposition_counts'],
                    'quarantine_outcomes' => $summary['quarantine_outcomes'],
                ];
            }
        );
    }

    /** Return a safe result when preview was not asked to inspect an artifact. */
    private static function source_artifact_not_checked(): array
    {
        return [
            'checked' => false,
            'verified' => false,
            'sha256' => '',
            'reason' => 'not_supplied',
        ];
    }

    /** Fail without including an operator-supplied path or artifact content. */
    private static function require_verified_source_artifact(array $verification): void
    {
        if (!$verification['verified']) {
            throw new \invalid_parameter_exception(
                'The supplied source snapshot artifact could not be verified against the manifest declaration.'
            );
        }
    }

    /** Require apply-time provenance to match the reviewed values in the manifest. */
    private static function validate_apply_provenance(array $manifest, array $provenance): void
    {
        $required = [
            'approved_by',
            'approved_at',
            'approval_reference',
            'source_snapshot_sha256',
            'source_plugin_version',
        ];
        foreach (array_keys($provenance) as $field) {
            if (!in_array($field, $required, true)) {
                throw new \invalid_parameter_exception("Unsupported apply provenance field '$field'.");
            }
        }
        foreach ($required as $field) {
            if (!array_key_exists($field, $provenance)) {
                throw new \invalid_parameter_exception("Apply requires explicit '$field' provenance matching the manifest.");
            }
        }

        $review = $manifest['review'] ?? [];
        $matches = is_string($provenance['approved_by'])
            && $provenance['approved_by'] === trim((string) ($review['approved_by'] ?? ''))
            && is_string($provenance['approved_at'])
            && $provenance['approved_at'] === (string) ($review['approved_at'] ?? '')
            && is_string($provenance['approval_reference'])
            && $provenance['approval_reference'] === trim((string) ($review['approval_reference'] ?? ''))
            && is_string($provenance['source_snapshot_sha256'])
            && hash_equals(
                strtolower((string) ($manifest['source_snapshot_sha256'] ?? '')),
                strtolower($provenance['source_snapshot_sha256'])
            )
            && is_int($provenance['source_plugin_version'])
            && $provenance['source_plugin_version'] === (int) ($manifest['source_plugin_version'] ?? 0);
        if (!$matches) {
            throw new \invalid_parameter_exception('Apply provenance must exactly match the reviewer and source declarations in the manifest.');
        }
    }

    /** Return concise, learner-free counts and quarantined target identifiers. */
    private static function summary_from_operations(array $modules, array $gradeitems): array
    {
        $mappingcounts = [
            'current_modules' => 0,
            'historical_modules' => 0,
            'mapped_grade_items' => 0,
        ];
        $dispositioncounts = [
            'quarantined_modules' => 0,
            'archived_modules' => 0,
            'disposed_modules' => 0,
            'quarantined_grade_items' => 0,
            'archived_grade_items' => 0,
            'disposed_grade_items' => 0,
        ];
        $quarantine = [
            'module_cmids' => [],
            'grade_item_ids' => [],
        ];
        $moduleentrycount = 0;
        $gradeentrycount = 0;

        foreach ($modules as $operation) {
            $moduleentrycount++;
            $decision = $operation['decision'];
            if ($decision === 'current') {
                $mappingcounts['current_modules']++;
            } else if ($decision === 'historical') {
                $mappingcounts['historical_modules']++;
            } else {
                $key = match ($decision) {
                    'archive' => 'archived_modules',
                    'dispose' => 'disposed_modules',
                    default => 'quarantined_modules',
                };
                $dispositioncounts[$key]++;
                if ($decision === 'quarantine') {
                    $quarantine['module_cmids'][] = (int) $operation['target']['cmid'];
                }
            }
        }
        foreach ($gradeitems as $operation) {
            $gradeentrycount++;
            $decision = $operation['decision'];
            if ($decision === 'map') {
                $mappingcounts['mapped_grade_items']++;
            } else {
                $key = match ($decision) {
                    'archive' => 'archived_grade_items',
                    'dispose' => 'disposed_grade_items',
                    default => 'quarantined_grade_items',
                };
                $dispositioncounts[$key]++;
                if ($decision === 'quarantine') {
                    $quarantine['grade_item_ids'][] = (int) $operation['target']['itemid'];
                }
            }
        }

        return [
            'module_entry_count' => $moduleentrycount,
            'grade_item_entry_count' => $gradeentrycount,
            'mapping_counts' => $mappingcounts,
            'disposition_counts' => $dispositioncounts,
            'quarantine_outcomes' => $quarantine,
        ];
    }

    /** Recover the same compact summary for an idempotent repeated import. */
    private static function summary_from_audit(string $manifestid): array
    {
        global $DB;

        $modules = [];
        $gradeitems = [];
        foreach ($DB->get_records('local_rtcsync_lineageaudit', ['manifestid' => $manifestid], 'id ASC') as $audit) {
            $operation = [
                'decision' => (string) $audit->decision,
                'target' => [(string) $audit->recordtype === 'module' ? 'cmid' : 'itemid' => (int) $audit->targetid],
            ];
            if ((string) $audit->recordtype === 'module') {
                $modules[] = $operation;
            } else if ((string) $audit->recordtype === 'gradeitem') {
                $gradeitems[] = $operation;
            }
        }
        return self::summary_from_operations($modules, $gradeitems);
    }

    /** Provenance as declared by the manifest; these values are not authenticated here. */
    private static function declared_provenance(?array $manifest): array
    {
        if ($manifest === null) {
            return [];
        }
        $review = is_array($manifest['review'] ?? null) ? $manifest['review'] : [];
        return [
            'source_snapshot_sha256' => is_string($manifest['source_snapshot_sha256'] ?? null)
                ? strtolower($manifest['source_snapshot_sha256']) : '',
            'source_plugin_version' => is_int($manifest['source_plugin_version'] ?? null)
                ? $manifest['source_plugin_version'] : 0,
            'approved_by' => is_string($review['approved_by'] ?? null) ? trim($review['approved_by']) : '',
            'approved_at' => is_string($review['approved_at'] ?? null) ? $review['approved_at'] : '',
            'approval_reference' => is_string($review['approval_reference'] ?? null)
                ? trim($review['approval_reference']) : '',
            'authenticated' => false,
        ];
    }

    /** Output for an exact manifest that was already applied. */
    private static function already_applied_result(
        string $manifestid,
        string $manifesthash,
        string $actualsourcehash
    ): array
    {
        global $DB;

        $record = $DB->get_record('local_rtcsync_linemanifest', ['manifestid' => $manifestid], '*', MUST_EXIST);
        $summary = self::summary_from_audit($manifestid);
        return [
            'applied' => false,
            'already_applied' => true,
            'manifest_id' => $manifestid,
            'manifest_sha256' => $manifesthash,
            'plan_sha256' => (string) ($record->applyplanhash ?? ''),
            'amendment' => !empty($record->parentmanifestid) ? [
                'parent_manifest_id' => (string) $record->parentmanifestid,
                'parent_manifest_sha256' => strtolower((string) ($record->parentmanifesthash ?? '')),
            ] : null,
            'operator' => (string) $record->operator,
            'approval_provenance' => [
                'source_snapshot_sha256' => (string) $record->sourcehash,
                'source_plugin_version' => (int) $record->sourceversion,
                'approved_by' => (string) $record->approvedby,
                'approved_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $record->approvedat),
                'approval_reference' => (string) $record->approvalref,
                'authenticated' => false,
            ],
            'source_artifact_verification' => [
                'checked' => true,
                'verified' => (int) ($record->sourcefileverified ?? 0) === 1
                    && (int) ($record->sourcefileverifiedat ?? 0) > 0
                    && hash_equals(
                        strtolower((string) ($record->sourcehash ?? '')),
                        strtolower((string) ($record->sourcefilehash ?? ''))
                    )
                    && hash_equals(
                        strtolower((string) ($record->sourcefilehash ?? '')),
                        strtolower($actualsourcehash)
                    ),
                'sha256' => (string) ($record->sourcefilehash ?? ''),
                'verified_at' => max(0, (int) ($record->sourcefileverifiedat ?? 0)),
            ],
            'module_entries' => $summary['module_entry_count'],
            'grade_item_entries' => $summary['grade_item_entry_count'],
            'mapping_counts' => $summary['mapping_counts'],
            'disposition_counts' => $summary['disposition_counts'],
            'quarantine_outcomes' => $summary['quarantine_outcomes'],
        ];
    }

    /** Require an exact repeat to present the dry-run plan recorded by its original apply. */
    private static function require_repeat_plan_hash(string $manifestid, string $expectedplanhash): void
    {
        global $DB;

        $stored = $DB->get_field('local_rtcsync_linemanifest', 'applyplanhash', ['manifestid' => $manifestid]);
        if (!is_string($stored) || !preg_match('/^[a-f0-9]{64}$/', $stored)
                || !hash_equals($stored, $expectedplanhash)) {
            throw new \invalid_parameter_exception(
                'Repeated apply requires the original dry-run plan hash recorded for this import.'
            );
        }
    }

    /** Build a deterministic, read-only target snapshot and validate the contract. */
    private static function prepare(string $json): array
    {
        global $DB;

        $manifesthash = hash('sha256', $json);
        $manifest = json_decode($json, true);
        $errors = [];
        $modules = [];
        $gradeitems = [];
        $snapshot = [];
        $courseid = 0;
        $manifestid = '';
        $isamendment = false;
        $parentmanifest = null;
        $parentaudits = [];
        if (!is_array($manifest) || json_last_error() !== JSON_ERROR_NONE) {
            $errors[] = 'Manifest must be valid JSON with an object at the root.';
            return self::prepared_result(null, $errors, $manifesthash, $manifestid, $courseid, $modules, $gradeitems, $snapshot);
        }

        $required = [
            'schema_version', 'manifest_id', 'course_idnumber', 'source_snapshot_sha256',
            'source_plugin_version', 'review', 'entries', 'grade_items',
        ];
        $isamendment = ($manifest['schema_version'] ?? null) === self::AMENDMENT_SCHEMA_VERSION;
        $allowed = $required;
        if ($isamendment) {
            $allowed[] = 'amendment';
        }
        foreach ($required as $field) {
            if (!array_key_exists($field, $manifest)) {
                $errors[] = "Manifest field '$field' is required.";
            }
        }
        foreach (array_keys($manifest) as $field) {
            if (!in_array($field, $allowed, true)) {
                $errors[] = "Unsupported manifest field '$field'.";
            }
        }
        if (!in_array($manifest['schema_version'] ?? null, [self::SCHEMA_VERSION, self::AMENDMENT_SCHEMA_VERSION], true)) {
            $errors[] = 'Unsupported manifest schema_version; expected 1 or amendment version 2.';
        }
        if ($isamendment) {
            $amendment = $manifest['amendment'] ?? null;
            if (!is_array($amendment)
                    || array_diff(array_keys($amendment), ['parent_manifest_id', 'parent_manifest_sha256'])
                    || array_diff(['parent_manifest_id', 'parent_manifest_sha256'], array_keys($amendment))) {
                $errors[] = 'amendment must identify its parent manifest ID and SHA-256.';
            } else if (!is_string($amendment['parent_manifest_id'])
                    || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', $amendment['parent_manifest_id'])
                    || !is_string($amendment['parent_manifest_sha256'])
                    || !preg_match('/^[a-f0-9]{64}$/i', $amendment['parent_manifest_sha256'])) {
                $errors[] = 'amendment parent identity and SHA-256 are invalid.';
            }
        }
        $manifestid = is_string($manifest['manifest_id'] ?? null) ? trim($manifest['manifest_id']) : '';
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', $manifestid)) {
            $errors[] = 'manifest_id must be a stable identifier of at most 64 safe characters.';
        }
        $courseidnumber = is_string($manifest['course_idnumber'] ?? null) ? trim($manifest['course_idnumber']) : '';
        if (!preg_match('/^rtc-subject:[1-9][0-9]*$/', $courseidnumber)) {
            $errors[] = 'course_idnumber must be the stable rtc-subject:<id> identity.';
        }
        $sourcehash = is_string($manifest['source_snapshot_sha256'] ?? null)
            ? strtolower($manifest['source_snapshot_sha256']) : '';
        if (!preg_match('/^[a-f0-9]{64}$/', $sourcehash)) {
            $errors[] = 'source_snapshot_sha256 must identify the authoritative source backup or snapshot.';
        }
        if (!is_int($manifest['source_plugin_version'] ?? null) || $manifest['source_plugin_version'] <= 0) {
            $errors[] = 'source_plugin_version must be a positive Moodle plugin version.';
        }
        $review = $manifest['review'] ?? null;
        if (!is_array($review)) {
            $errors[] = 'review must include approved_by, approved_at, and approval_reference.';
            $review = [];
        }
        foreach (array_keys($review) as $field) {
            if (!in_array($field, ['approved_by', 'approved_at', 'approval_reference'], true)) {
                $errors[] = "review contains unsupported field '$field'.";
            }
        }
        foreach (['approved_by', 'approved_at', 'approval_reference'] as $field) {
            if (!is_string($review[$field] ?? null) || trim($review[$field]) === '') {
                $errors[] = "review.$field is required.";
            }
        }
        $approvaltime = is_string($review['approved_at'] ?? null)
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $review['approved_at'])
            ? strtotime($review['approved_at']) : false;
        if ($approvaltime === false || $approvaltime <= 0) {
            $errors[] = 'review.approved_at must be a valid UTC timestamp in YYYY-MM-DDTHH:MM:SSZ form.';
        }
        if (core_text::strlen(trim((string) ($review['approved_by'] ?? ''))) > 255
                || core_text::strlen(trim((string) ($review['approval_reference'] ?? ''))) > 255) {
            $errors[] = 'Reviewer and approval reference fields are limited to 255 characters.';
        }

        if ($errors) {
            return self::prepared_result(
                $manifest,
                $errors,
                $manifesthash,
                $manifestid,
                $courseid,
                $modules,
                $gradeitems,
                $snapshot,
                false,
                $isamendment
            );
        }

        if ($isamendment) {
            $parentmanifestid = trim((string) $manifest['amendment']['parent_manifest_id']);
            $parentmanifesthash = strtolower((string) $manifest['amendment']['parent_manifest_sha256']);
            $parentmanifest = $DB->get_record(
                'local_rtcsync_linemanifest',
                ['manifestid' => $parentmanifestid],
                '*',
                IGNORE_MISSING
            );
            if (!$parentmanifest) {
                $errors[] = 'The declared amendment parent import does not exist.';
            } else {
                if (!hash_equals((string) $parentmanifest->manifesthash, $parentmanifesthash)) {
                    $errors[] = 'The declared amendment parent hash is stale or incorrect.';
                }
                if ((string) $parentmanifest->courseidnumber !== $courseidnumber
                        || (int) $parentmanifest->courseid <= 0) {
                    $errors[] = 'The amendment parent belongs to a different stable course identity.';
                }
                if (strtolower((string) $parentmanifest->sourcehash) !== $sourcehash
                        || (int) $parentmanifest->sourceversion !== (int) $manifest['source_plugin_version']) {
                    $errors[] = 'The amendment source digest and source version must match its parent import.';
                }
                if ((int) ($parentmanifest->sourcefileverified ?? 0) !== 1
                        || (int) ($parentmanifest->sourcefileverifiedat ?? 0) <= 0
                        || !preg_match('/^[a-f0-9]{64}$/i', (string) ($parentmanifest->sourcefilehash ?? ''))
                        || !hash_equals($sourcehash, strtolower((string) ($parentmanifest->sourcefilehash ?? '')))) {
                    $errors[] = 'The amendment parent lacks matching verified source-artifact evidence.';
                }
                if (!preg_match('/^[a-f0-9]{64}$/', strtolower((string) ($parentmanifest->applyplanhash ?? '')))) {
                    $errors[] = 'The amendment parent lacks its original applied dry-run plan evidence.';
                }
                $parentjson = (string) ($parentmanifest->manifestjson ?? '');
                $decodedparent = json_decode($parentjson, true);
                if ($parentjson === ''
                        || !hash_equals(strtolower((string) $parentmanifest->manifesthash), hash('sha256', $parentjson))
                        || !is_array($decodedparent)
                        || !is_int($decodedparent['schema_version'] ?? null)
                        || !in_array($decodedparent['schema_version'], [self::SCHEMA_VERSION, self::AMENDMENT_SCHEMA_VERSION], true)
                        || !is_string($decodedparent['manifest_id'] ?? null)
                        || trim($decodedparent['manifest_id']) !== $parentmanifestid
                        || (string) ($decodedparent['course_idnumber'] ?? '') !== (string) $parentmanifest->courseidnumber
                        || strtolower((string) ($decodedparent['source_snapshot_sha256'] ?? ''))
                            !== strtolower((string) $parentmanifest->sourcehash)
                        || (int) ($decodedparent['source_plugin_version'] ?? 0) !== (int) $parentmanifest->sourceversion) {
                    $errors[] = 'The amendment parent manifest content is missing or does not match its stored digest.';
                }
                $snapshot['parent_manifest'] = [
                    'manifest_id' => $parentmanifestid,
                    'manifest_sha256' => strtolower((string) $parentmanifest->manifesthash),
                    'course_id' => (int) $parentmanifest->courseid,
                    'source_sha256' => strtolower((string) $parentmanifest->sourcehash),
                    'source_artifact_sha256' => strtolower((string) ($parentmanifest->sourcefilehash ?? '')),
                    'source_artifact_verified_at' => (int) ($parentmanifest->sourcefileverifiedat ?? 0),
                    'apply_plan_sha256' => strtolower((string) ($parentmanifest->applyplanhash ?? '')),
                ];
                foreach ($DB->get_records(
                    'local_rtcsync_lineageaudit',
                    ['manifestid' => $parentmanifestid],
                    'id ASC'
                ) as $parentaudit) {
                    $parentaudits[(string) $parentaudit->recordtype . ':' . (int) $parentaudit->targetid] = $parentaudit;
                    if ((string) $parentaudit->manifesthash !== (string) $parentmanifest->manifesthash
                            || (int) $parentaudit->courseid !== (int) $parentmanifest->courseid) {
                        $errors[] = 'The amendment parent audit is inconsistent with its import record.';
                    }
                }
                if (count($parentaudits) !== (int) $parentmanifest->entrycount) {
                    $errors[] = 'The amendment parent import does not have complete per-record audit coverage.';
                }
                if (is_array($decodedparent)) {
                    $parentreview = is_array($decodedparent['review'] ?? null) ? $decodedparent['review'] : [];
                    $parentapprovedat = is_string($parentreview['approved_at'] ?? null)
                        ? strtotime($parentreview['approved_at']) : false;
                    if ($parentapprovedat === false
                            || (string) $parentmanifest->approvedby !== trim((string) ($parentreview['approved_by'] ?? ''))
                            || (string) $parentmanifest->approvalref !== trim((string) ($parentreview['approval_reference'] ?? ''))
                            || (int) $parentmanifest->approvedat !== $parentapprovedat
                            || trim((string) ($parentmanifest->operator ?? '')) === ''
                            || (int) ($parentmanifest->actorid ?? -1) < 0) {
                        $errors[] = 'The amendment parent approval or operator provenance does not match its stored manifest.';
                    }
                    $parentexpected = [];
                    foreach (($decodedparent['entries'] ?? []) as $entry) {
                        if (!is_array($entry) || !is_array($entry['target'] ?? null)) {
                            $errors[] = 'The amendment parent manifest has an invalid module record.';
                            continue;
                        }
                        $targetid = (int) ($entry['target']['cmid'] ?? 0);
                        $key = 'module:' . $targetid;
                        $parentexpected[$key] = ['recordtype' => 'module', 'targetid' => $targetid, 'entry' => $entry];
                    }
                    foreach (($decodedparent['grade_items'] ?? []) as $entry) {
                        if (!is_array($entry) || !is_array($entry['target'] ?? null)) {
                            $errors[] = 'The amendment parent manifest has an invalid grade-item record.';
                            continue;
                        }
                        $targetid = (int) ($entry['target']['itemid'] ?? 0);
                        $key = 'gradeitem:' . $targetid;
                        $parentexpected[$key] = ['recordtype' => 'gradeitem', 'targetid' => $targetid, 'entry' => $entry];
                    }
                    if (count($parentexpected) !== count($parentaudits)
                            || count($parentexpected) !== (int) $parentmanifest->entrycount) {
                        $errors[] = 'The amendment parent manifest and its audit do not contain the same records.';
                    }
                    foreach ($parentexpected as $key => $expectedrecord) {
                        $parentaudit = $parentaudits[$key] ?? null;
                        if (!$parentaudit || !self::parent_audit_matches_manifest(
                                $parentaudit,
                                $expectedrecord,
                                (int) $parentmanifest->courseid,
                                (string) $parentmanifest->manifesthash
                        )) {
                            $errors[] = 'The amendment parent manifest and its per-record audit disagree.';
                        }
                    }
                }
            }
            if ($errors) {
                return self::prepared_result(
                    $manifest,
                    $errors,
                    $manifesthash,
                    $manifestid,
                    $courseid,
                    $modules,
                    $gradeitems,
                    $snapshot,
                    false,
                    true
                );
            }
        }

        // A repeated exact import is a no-op even if target modules were later changed.
        $existingmanifest = $DB->get_record('local_rtcsync_linemanifest', ['manifestid' => $manifestid], '*', IGNORE_MISSING);
        if ($existingmanifest) {
            if (hash_equals((string) $existingmanifest->manifesthash, $manifesthash)) {
                if ($isamendment
                        && ((string) ($existingmanifest->parentmanifestid ?? '')
                                !== trim((string) $manifest['amendment']['parent_manifest_id'])
                            || strtolower((string) ($existingmanifest->parentmanifesthash ?? ''))
                                !== strtolower((string) $manifest['amendment']['parent_manifest_sha256']))) {
                    $errors[] = 'The stored amendment parent link does not match the reviewed manifest.';
                    return self::prepared_result(
                        $manifest,
                        $errors,
                        $manifesthash,
                        $manifestid,
                        (int) $existingmanifest->courseid,
                        $modules,
                        $gradeitems,
                        $snapshot,
                        false,
                        true
                    );
                }
                $result = self::prepared_result(
                    $manifest,
                    [],
                    $manifesthash,
                    $manifestid,
                    (int) $existingmanifest->courseid,
                    $modules,
                    $gradeitems,
                    $snapshot,
                    true,
                    $isamendment
                );
                $result['planhash'] = strtolower((string) ($existingmanifest->applyplanhash ?? ''));
                if (!preg_match('/^[a-f0-9]{64}$/', $result['planhash'])) {
                    $result['errors'][] = 'The applied import has no stored original plan hash and cannot be safely repeated or amended.';
                }
                return $result;
            }
            $errors[] = 'manifest_id already exists with different content; use a new reviewed manifest_id.';
            return self::prepared_result($manifest, $errors, $manifesthash, $manifestid, $courseid, $modules, $gradeitems, $snapshot);
        }

        if ($isamendment) {
            $children = $DB->get_records('local_rtcsync_linemanifest', [
                'parentmanifestid' => trim((string) $manifest['amendment']['parent_manifest_id']),
            ], 'id ASC', 'id');
            if ($children) {
                $errors[] = 'The declared parent already has an amendment; amend the current chain head instead.';
                return self::prepared_result(
                    $manifest,
                    $errors,
                    $manifesthash,
                    $manifestid,
                    $courseid,
                    $modules,
                    $gradeitems,
                    $snapshot,
                    false,
                    true
                );
            }
        }

        $courses = $DB->get_records('course', ['idnumber' => $courseidnumber], 'id ASC');
        if (count($courses) !== 1) {
            $errors[] = 'Stable target course identity must resolve to exactly one Moodle course.';
        } else {
            $course = reset($courses);
            $courseid = (int) $course->id;
            $snapshot['course'] = ['id' => $courseid, 'idnumber' => (string) $course->idnumber];
        }
        if ($isamendment && $parentmanifest && $courseid > 0
                && $courseid !== (int) $parentmanifest->courseid) {
            $errors[] = 'The amendment target resolves to a different course record than its parent import.';
        }

        $entries = is_array($manifest['entries'] ?? null) && array_is_list($manifest['entries'])
            ? $manifest['entries'] : [];
        if (isset($manifest['entries']) && (!is_array($manifest['entries']) || !array_is_list($manifest['entries']))) {
            $errors[] = 'entries must be a JSON array.';
        }
        if (!$entries && !$isamendment) {
            $errors[] = 'entries must contain at least one explicit module decision.';
        }
        $seenmodules = [];
        $seensources = [];
        foreach ($entries as $index => $entry) {
            $prefix = 'entries[' . $index . ']';
            if (!is_array($entry)) {
                $errors[] = "$prefix must be an object.";
                continue;
            }
            foreach (array_keys($entry) as $field) {
                if (!in_array($field, ['source', 'target', 'decision', 'delivery_ids', 'reason'], true)) {
                    $errors[] = "$prefix contains unsupported field '$field'.";
                }
            }
            $source = self::validate_module_identity($entry['source'] ?? null, $courseidnumber, "$prefix.source", $errors);
            $target = self::validate_module_identity($entry['target'] ?? null, $courseidnumber, "$prefix.target", $errors);
            $decision = is_string($entry['decision'] ?? null) ? $entry['decision'] : '';
            $alloweddecisions = $isamendment ? ['current', 'historical', 'quarantine'] : self::DECISIONS;
            if (!in_array($decision, $alloweddecisions, true)) {
                $errors[] = $isamendment
                    ? "$prefix.decision must resolve the parent quarantine as current, historical, or remain quarantined."
                    : "$prefix.decision must be current, historical, quarantine, archive, or dispose.";
            }
            $deliveryids = self::validate_delivery_ids($entry['delivery_ids'] ?? null, "$prefix.delivery_ids", $errors);
            if (($decision === 'current' || $decision === 'historical') && count($deliveryids) !== 1) {
                $errors[] = "$prefix must approve exactly one delivery for a current or historical module mapping.";
            }
            if (in_array($decision, ['quarantine', 'archive', 'dispose'], true) && $deliveryids !== []) {
                $errors[] = "$prefix disposition decisions cannot carry delivery IDs.";
            }
            $reason = is_string($entry['reason'] ?? null) ? trim($entry['reason']) : '';
            if ($reason === '' || core_text::strlen($reason) > 2000) {
                $errors[] = "$prefix.reason is required and limited to 2,000 characters.";
            }
            if (!is_array($source) || !is_array($target) || !in_array($decision, $alloweddecisions, true)) {
                continue;
            }
            $targetkey = (string) $target['cmid'];
            $sourcekey = implode(':', [$source['cmid'], $source['modname'], $source['instanceid'], $source['idnumber']]);
            if (isset($seenmodules[$targetkey])) {
                $errors[] = "$prefix duplicates target course module $targetkey.";
            }
            if (isset($seensources[$sourcekey])) {
                $errors[] = "$prefix duplicates a source module identity; its delivery lineage is ambiguous.";
            }
            $seenmodules[$targetkey] = true;
            $seensources[$sourcekey] = true;
            $parentaudit = $isamendment ? ($parentaudits['module:' . $targetkey] ?? null) : null;
            if ($isamendment) {
                self::validate_amendment_parent_record(
                    $parentaudit,
                    'module',
                    $source,
                    $target,
                    (int) $target['cmid'],
                    $prefix,
                    $errors,
                    $snapshot
                );
            }

            $targetrecord = null;
            $mapping = null;
            $history = [];
            $targetgroupingid = 0;
            if ($courseid > 0) {
                $targetrecord = $DB->get_record_sql(
                    'SELECT cm.id, cm.course, cm.module, cm.instance, cm.idnumber, cm.groupingid, cm.availability,
                            m.name AS modname
                       FROM {course_modules} cm
                       JOIN {modules} m ON m.id = cm.module
                      WHERE cm.id = :cmid',
                    ['cmid' => $target['cmid']],
                    IGNORE_MISSING
                );
                if (!$targetrecord
                        || (int) $targetrecord->course !== $courseid
                        || (string) $targetrecord->modname !== $target['modname']
                        || (int) $targetrecord->instance !== $target['instanceid']
                        || (string) $targetrecord->idnumber !== $target['idnumber']) {
                    $errors[] = "$prefix.target does not exactly identify a module in the stable target course.";
                } else {
                    $mapping = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => (int) $targetrecord->id], '*', IGNORE_MISSING);
                    $history = array_values($DB->get_records('local_rtcsync_deliverycmhist', [
                        'cmid' => (int) $targetrecord->id,
                    ], 'courseid ASC, deliveryid ASC'));
                    foreach ($history as $historyrow) {
                        if ((int) $historyrow->courseid !== $courseid) {
                            $errors[] = "$prefix target module has a historical mapping that references another course.";
                        }
                    }
                    if ($mapping && (int) $mapping->courseid !== $courseid) {
                        $errors[] = "$prefix target module has an existing delivery mapping for another course.";
                    }
                    $candidateavailability = $mapping && $mapping->originalavailability !== null
                        ? (string) $mapping->originalavailability : $targetrecord->availability;
                    [$observeddeliveryid, $targetgroupingid] = delivery_activity_access::manifest_grouping_reference(
                        $courseid,
                        (int) $targetrecord->groupingid,
                        (int) $targetrecord->id,
                        $candidateavailability
                    );
                    if ($decision === 'current' && ($deliveryids[0] ?? 0) !== $observeddeliveryid) {
                        $errors[] = "$prefix current delivery does not match the manifest's explicit delivery_id and target grouping.";
                    }
                    if ($decision === 'current' && !delivery_activity_access::supports_standard_grouping_restrictions()) {
                        $errors[] = "$prefix cannot be opened because Moodle's standard grouping availability guard is unavailable.";
                    }
                    if ($decision === 'current' && $history) {
                        $errors[] = "$prefix target module already has historical associations; use a separate current module.";
                    }
                    if ($decision === 'historical') {
                        foreach ($history as $historyrow) {
                            if ((int) $historyrow->deliveryid !== ($deliveryids[0] ?? 0)) {
                                $errors[] = "$prefix target module has a second historical delivery; one grade item cannot safely represent multiple deliveries.";
                                break;
                            }
                        }
                    }
                    if (!$isamendment && $mapping && ((int) $mapping->lineagestatus !== delivery_activity_access::LINEAGE_UNREVIEWED
                            || trim((string) ($mapping->lineagemanifestid ?? '')) !== '')) {
                        $errors[] = "$prefix target module was already dispositioned or reconciled by an earlier decision.";
                    }
                    $snapshot['modules'][] = [
                        'target' => $target,
                        'courseid' => $courseid,
                        'groupingid' => (int) $targetrecord->groupingid,
                        'availability' => $targetrecord->availability,
                        'observeddeliveryid' => $observeddeliveryid,
                        'targetgroupingid' => $targetgroupingid,
                        'mapping' => $mapping ? self::mapping_snapshot($mapping) : null,
                        'history' => array_map(static function($row): array {
                            return [
                                'deliveryid' => (int) $row->deliveryid,
                                'lineageverified' => (int) $row->lineageverified,
                                'lineagemanifestid' => (string) ($row->lineagemanifestid ?? ''),
                            ];
                        }, $history),
                    ];
                }
            }
            foreach ($deliveryids as $deliveryid) {
                if ($courseid > 0) {
                    $groupings = $DB->get_records('groupings', [
                        'courseid' => $courseid,
                        'idnumber' => 'rtc-delivery-grouping:' . $deliveryid,
                    ], 'id ASC', 'id');
                    if (count($groupings) !== 1) {
                        $errors[] = "$prefix references delivery $deliveryid without exactly one matching target-course grouping.";
                    }
                    $snapshot['deliverygroupings'][] = [
                        'deliveryid' => $deliveryid,
                        'groupingids' => array_map('intval', array_keys($groupings)),
                    ];
                }
            }
            $modules[] = [
                'entry' => $entry,
                'source' => $source,
                'target' => $target,
                'decision' => $decision,
                'deliveryids' => $deliveryids,
                'reason' => $reason,
                'courseid' => $courseid,
                'targetgroupingid' => $targetgroupingid,
                'parentaudit' => $parentaudit,
            ];
        }

        $gradeentries = is_array($manifest['grade_items'] ?? null) && array_is_list($manifest['grade_items'])
            ? $manifest['grade_items'] : null;
        if ($gradeentries === null) {
            $errors[] = 'grade_items must be a JSON array, even when empty.';
            $gradeentries = [];
        }
        $seengradeitems = [];
        foreach ($gradeentries as $index => $entry) {
            $prefix = 'grade_items[' . $index . ']';
            if (!is_array($entry)) {
                $errors[] = "$prefix must be an object.";
                continue;
            }
            foreach (array_keys($entry) as $field) {
                if (!in_array($field, ['source', 'target', 'decision', 'delivery_id', 'reason'], true)) {
                    $errors[] = "$prefix contains unsupported field '$field'.";
                }
            }
            $source = self::validate_grade_identity($entry['source'] ?? null, $courseidnumber, "$prefix.source", $errors);
            $target = self::validate_grade_identity($entry['target'] ?? null, $courseidnumber, "$prefix.target", $errors);
            $decision = is_string($entry['decision'] ?? null) ? $entry['decision'] : '';
            $alloweddecisions = $isamendment ? ['map', 'quarantine'] : ['map', 'quarantine', 'archive', 'dispose'];
            if (!in_array($decision, $alloweddecisions, true)) {
                $errors[] = $isamendment
                    ? "$prefix.decision must map the parent quarantine or keep it quarantined."
                    : "$prefix.decision must be map, quarantine, archive, or dispose.";
            }
            $deliveryid = is_int($entry['delivery_id'] ?? null) ? $entry['delivery_id'] : 0;
            if ($decision === 'map' && $deliveryid <= 0) {
                $errors[] = "$prefix.delivery_id must be an explicit positive ID for a map decision.";
            }
            if (in_array($decision, ['quarantine', 'archive', 'dispose'], true) && $deliveryid !== 0) {
                $errors[] = "$prefix disposition decisions must use delivery_id 0 and cannot assign lineage.";
            }
            $reason = is_string($entry['reason'] ?? null) ? trim($entry['reason']) : '';
            if ($reason === '' || core_text::strlen($reason) > 2000) {
                $errors[] = "$prefix.reason is required and limited to 2,000 characters.";
            }
            if (!is_array($source) || !is_array($target) || !in_array($decision, $alloweddecisions, true)) {
                continue;
            }
            if (isset($seengradeitems[(string) $target['itemid']])) {
                $errors[] = "$prefix duplicates target grade item {$target['itemid']}.";
            }
            $seengradeitems[(string) $target['itemid']] = true;
            $parentaudit = $isamendment ? ($parentaudits['gradeitem:' . (int) $target['itemid']] ?? null) : null;
            if ($isamendment) {
                self::validate_amendment_parent_record(
                    $parentaudit,
                    'gradeitem',
                    $source,
                    $target,
                    (int) $target['itemid'],
                    $prefix,
                    $errors,
                    $snapshot
                );
            }
            $item = $courseid > 0 ? $DB->get_record('grade_items', ['id' => $target['itemid']], '*', IGNORE_MISSING) : null;
            if (!$item || (int) $item->courseid !== $courseid
                    || (string) $item->itemtype !== 'manual'
                    || (string) ($item->itemmodule ?? '') !== $target['itemmodule']
                    || (int) ($item->iteminstance ?? 0) !== $target['iteminstance']
                    || (string) $item->idnumber !== $target['idnumber']) {
                $errors[] = "$prefix.target does not exactly identify a manual grade item in the stable target course.";
            }
            if ($courseid > 0 && $decision === 'map' && $deliveryid > 0) {
                $groupings = $DB->get_records('groupings', [
                    'courseid' => $courseid,
                    'idnumber' => 'rtc-delivery-grouping:' . $deliveryid,
                ], 'id ASC', 'id');
                if (count($groupings) !== 1) {
                    $errors[] = "$prefix references delivery $deliveryid without exactly one matching target-course grouping.";
                }
                $snapshot['deliverygroupings'][] = [
                    'deliveryid' => $deliveryid,
                    'groupingids' => array_map('intval', array_keys($groupings)),
                ];
            }
            $mapping = $item ? $DB->get_record('local_rtcsync_deliverygrade', ['gradeitemid' => $target['itemid']], '*', IGNORE_MISSING) : null;
            if ($mapping && (int) $mapping->courseid !== $courseid) {
                $errors[] = "$prefix conflicts with an existing grade-item mapping for another course.";
            } else if ($mapping && (int) $mapping->lineageverified === 1) {
                $errors[] = "$prefix target grade item was already reconciled; a separate controlled correction is required.";
            } else if ($mapping && $decision === 'map' && !$isamendment
                    && (int) $mapping->deliveryid !== $deliveryid) {
                $errors[] = "$prefix conflicts with an existing grade-item delivery mapping.";
            }
            $snapshot['gradeitems'][] = [
                'target' => $target,
                'courseid' => $courseid,
                'itemtype' => $item ? (string) $item->itemtype : '',
                'itemmodule' => $item ? (string) ($item->itemmodule ?? '') : '',
                'iteminstance' => $item ? (int) ($item->iteminstance ?? 0) : 0,
                'idnumber' => $item ? (string) $item->idnumber : '',
                'mapping' => $mapping ? [
                    'courseid' => (int) $mapping->courseid,
                    'deliveryid' => (int) $mapping->deliveryid,
                    'lineageverified' => (int) $mapping->lineageverified,
                    'lineagemanifestid' => (string) ($mapping->lineagemanifestid ?? ''),
                ] : null,
            ];
            $gradeitems[] = [
                'entry' => $entry,
                'source' => $source,
                'target' => $target,
                'decision' => $decision,
                'deliveryids' => $deliveryid > 0 ? [$deliveryid] : [],
                'reason' => $reason,
                'courseid' => $courseid,
                'parentaudit' => $parentaudit,
            ];
        }

        if ($isamendment) {
            $expectedmoduleids = [];
            $expectedgradeids = [];
            foreach ($parentaudits as $key => $parentaudit) {
                if ((string) $parentaudit->decision !== 'quarantine') {
                    continue;
                }
                if ((string) $parentaudit->recordtype === 'module') {
                    $expectedmoduleids[(int) $parentaudit->targetid] = true;
                    if (!isset($seenmodules[(string) $parentaudit->targetid])) {
                        $errors[] = 'Amendment omits a quarantined module record from the complete parent review.';
                    }
                } else if ((string) $parentaudit->recordtype === 'gradeitem') {
                    $expectedgradeids[(int) $parentaudit->targetid] = true;
                    if (!isset($seengradeitems[(string) $parentaudit->targetid])) {
                        $errors[] = 'Amendment omits a quarantined grade-item record from the complete parent review.';
                    }
                }
            }
            if (!$expectedmoduleids && !$expectedgradeids) {
                $errors[] = 'The parent import has no quarantined records eligible for amendment.';
            }
            $snapshot['amendment_coverage'] = [
                'parent_quarantined_modules' => array_map('intval', array_keys($expectedmoduleids)),
                'parent_quarantined_grade_items' => array_map('intval', array_keys($expectedgradeids)),
            ];
        }

        // A manifest is exhaustive for unresolved plugin mappings in this
        // target course. Omitted rows stay hidden, but omission is not an
        // auditable quarantine/disposition decision.
        if ($courseid > 0) {
            foreach ($DB->get_records('local_rtcsync_deliverycm', ['courseid' => $courseid], 'cmid ASC', 'cmid,lineagestatus') as $row) {
                if ((int) $row->lineagestatus === delivery_activity_access::LINEAGE_UNREVIEWED
                        && !isset($seenmodules[(string) $row->cmid])) {
                    $errors[] = "Manifest omits unresolved delivery module {$row->cmid}; include a mapping or explicit quarantine/archive/disposal decision.";
                }
            }
            foreach ($DB->get_records('local_rtcsync_deliverycmhist', [
                'courseid' => $courseid,
                'lineageverified' => 0,
            ], 'cmid ASC, deliveryid ASC', 'cmid,deliveryid') as $row) {
                if (!isset($seenmodules[(string) $row->cmid])) {
                    $errors[] = "Manifest omits unresolved module-history mapping for course module {$row->cmid}; include a module decision.";
                }
            }
            foreach ($DB->get_records('local_rtcsync_deliverygrade', [
                'courseid' => $courseid,
                'lineageverified' => 0,
            ], 'gradeitemid ASC', 'gradeitemid') as $row) {
                if (!isset($seengradeitems[(string) $row->gradeitemid])) {
                    $errors[] = "Manifest omits unresolved delivery-grade mapping for grade item {$row->gradeitemid}; include a map or explicit quarantine/archive/disposal decision.";
                }
            }
            if (preg_match('/^rtc-subject:([1-9][0-9]*)$/', $courseidnumber, $subjectmatches)) {
                $deliveryidnumberpattern = '/^rtc-subject-score:' . preg_quote($subjectmatches[1], '/')
                    . ':delivery:[1-9][0-9]*$/';
                foreach ($DB->get_records('grade_items', [
                    'courseid' => $courseid,
                    'itemtype' => 'manual',
                ], 'id ASC', 'id,idnumber') as $item) {
                    if (!preg_match($deliveryidnumberpattern, (string) $item->idnumber)) {
                        continue;
                    }
                    $mapping = $DB->get_record('local_rtcsync_deliverygrade', [
                        'gradeitemid' => (int) $item->id,
                    ], 'courseid,lineageverified', IGNORE_MISSING);
                    if ((!$mapping || (int) $mapping->courseid !== $courseid
                            || (int) $mapping->lineageverified !== 1)
                            && !isset($seengradeitems[(string) $item->id])) {
                        $errors[] = "Manifest omits unresolved delivery-score grade item {$item->id}; include an explicit map or quarantine/archive/disposal decision.";
                    }
                }
            }
        }

        return self::prepared_result($manifest, $errors, $manifesthash, $manifestid, $courseid,
            $modules, $gradeitems, $snapshot, false, $isamendment);
    }

    /** Validate stable module identity fields supplied by the reviewer. */
    private static function validate_module_identity($identity, string $courseidnumber, string $prefix, array &$errors): ?array
    {
        if (!is_array($identity)) {
            $errors[] = "$prefix must be an object.";
            return null;
        }
        $allowed = ['course_idnumber', 'cmid', 'modname', 'instanceid', 'idnumber'];
        foreach (array_keys($identity) as $field) {
            if (!in_array($field, $allowed, true)) {
                $errors[] = "$prefix contains unsupported field '$field'.";
            }
        }
        if (($identity['course_idnumber'] ?? null) !== $courseidnumber) {
            $errors[] = "$prefix.course_idnumber must equal the manifest stable course identity.";
        }
        if (!is_int($identity['cmid'] ?? null) || $identity['cmid'] <= 0
                || !is_int($identity['instanceid'] ?? null) || $identity['instanceid'] <= 0) {
            $errors[] = "$prefix must include positive cmid and instanceid values.";
        }
        $modname = is_string($identity['modname'] ?? null) ? trim($identity['modname']) : '';
        if (!preg_match('/^[a-z][a-z0-9_]{0,99}$/', $modname)) {
            $errors[] = "$prefix.modname must be a Moodle module name.";
        }
        if (!is_string($identity['idnumber'] ?? null) || core_text::strlen($identity['idnumber']) > 100) {
            $errors[] = "$prefix.idnumber must be the exact course-module idnumber (empty is allowed).";
        }
        if (!is_int($identity['cmid'] ?? null) || !is_int($identity['instanceid'] ?? null)
                || $modname === '' || !is_string($identity['idnumber'] ?? null)) {
            return null;
        }
        return [
            'course_idnumber' => $courseidnumber,
            'cmid' => $identity['cmid'],
            'modname' => $modname,
            'instanceid' => $identity['instanceid'],
            'idnumber' => $identity['idnumber'],
        ];
    }

    /** Validate grade-item identity fields. */
    private static function validate_grade_identity(
        $identity,
        string $courseidnumber,
        string $prefix,
        array &$errors
    ): ?array
    {
        if (!is_array($identity)) {
            $errors[] = "$prefix must be an object.";
            return null;
        }
        foreach (array_keys($identity) as $field) {
            if (!in_array($field, ['course_idnumber', 'itemid', 'itemtype', 'itemmodule', 'iteminstance', 'idnumber'], true)) {
                $errors[] = "$prefix contains unsupported field '$field'.";
            }
        }
        if (($identity['course_idnumber'] ?? null) !== $courseidnumber) {
            $errors[] = "$prefix.course_idnumber must equal the manifest stable course identity.";
        }
        if (!is_int($identity['itemid'] ?? null) || $identity['itemid'] <= 0
                || !is_string($identity['itemtype'] ?? null)
                || !is_string($identity['itemmodule'] ?? null)
                || !is_int($identity['iteminstance'] ?? null) || $identity['iteminstance'] < 0
                || !is_string($identity['idnumber'] ?? null) || core_text::strlen($identity['idnumber']) > 255) {
            $errors[] = "$prefix must include itemid, itemtype, itemmodule, iteminstance, and exact idnumber.";
            return null;
        }
        if ($identity['itemtype'] !== 'manual' || $identity['itemmodule'] !== '' || $identity['iteminstance'] !== 0) {
            $errors[] = "$prefix must identify a manual grade item (empty itemmodule and iteminstance 0).";
        }
        return [
            'course_idnumber' => $courseidnumber,
            'itemid' => $identity['itemid'],
            'itemtype' => $identity['itemtype'],
            'itemmodule' => $identity['itemmodule'],
            'iteminstance' => $identity['iteminstance'],
            'idnumber' => $identity['idnumber'],
        ];
    }

    /** Validate a one-delivery-or-empty mapping set without coercing strings. */
    private static function validate_delivery_ids($deliveryids, string $prefix, array &$errors): array
    {
        if (!is_array($deliveryids) || !array_is_list($deliveryids)) {
            $errors[] = "$prefix must be an array of explicit positive integer delivery IDs.";
            return [];
        }
        $normalized = [];
        foreach ($deliveryids as $deliveryid) {
            if (!is_int($deliveryid) || $deliveryid <= 0 || in_array($deliveryid, $normalized, true)) {
                $errors[] = "$prefix contains a non-positive, non-integer, or duplicate delivery ID.";
                return [];
            }
            $normalized[] = $deliveryid;
        }
        return $normalized;
    }

    /** Check that a stored parent audit row exactly reflects its persisted manifest entry. */
    private static function parent_audit_matches_manifest(
        \stdClass $audit,
        array $expected,
        int $courseid,
        string $manifesthash
    ): bool {
        $recordtype = (string) $expected['recordtype'];
        $targetid = (int) $expected['targetid'];
        $entry = $expected['entry'];
        $identity = static function(array $value) use ($recordtype): array {
            if ($recordtype === 'module') {
                return [
                    'course_idnumber' => (string) ($value['course_idnumber'] ?? ''),
                    'cmid' => (int) ($value['cmid'] ?? 0),
                    'modname' => trim((string) ($value['modname'] ?? '')),
                    'instanceid' => (int) ($value['instanceid'] ?? 0),
                    'idnumber' => (string) ($value['idnumber'] ?? ''),
                ];
            }
            return [
                'course_idnumber' => (string) ($value['course_idnumber'] ?? ''),
                'itemid' => (int) ($value['itemid'] ?? 0),
                'itemtype' => (string) ($value['itemtype'] ?? ''),
                'itemmodule' => (string) ($value['itemmodule'] ?? ''),
                'iteminstance' => (int) ($value['iteminstance'] ?? 0),
                'idnumber' => (string) ($value['idnumber'] ?? ''),
            ];
        };
        $encodedentry = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $deliveryids = $recordtype === 'module'
            ? ($entry['delivery_ids'] ?? [])
            : (((string) ($entry['decision'] ?? '') === 'map' && is_int($entry['delivery_id'] ?? null))
                ? [$entry['delivery_id']] : []);
        $resultstate = json_decode((string) ($audit->resultstate ?? ''), true);
        $auditsource = json_decode((string) $audit->sourceidentity, true);
        $audittarget = json_decode((string) $audit->targetidentity, true);
        $auditdeliveryids = json_decode((string) $audit->deliveryids, true);
        return (string) $audit->manifesthash === $manifesthash
            && (int) $audit->courseid === $courseid
            && (string) $audit->recordtype === $recordtype
            && (int) $audit->targetid === $targetid
            && (string) $audit->decision === (string) ($entry['decision'] ?? '')
            && (string) $audit->reason === trim((string) ($entry['reason'] ?? ''))
            && $encodedentry !== false
            && hash_equals((string) $audit->entryhash, hash('sha256', $encodedentry))
            && is_array($auditsource)
            && self::canonical_json($auditsource) === self::canonical_json($identity($entry['source'] ?? []))
            && is_array($audittarget)
            && self::canonical_json($audittarget) === self::canonical_json($identity($entry['target'] ?? []))
            && is_array($auditdeliveryids)
            && self::canonical_json($auditdeliveryids) === self::canonical_json($deliveryids)
            && is_array($resultstate)
            && ($resultstate['version'] ?? null) === 1
            && ($resultstate['record_type'] ?? null) === $recordtype
            && ($resultstate['target_id'] ?? null) === $targetid;
    }

    /** Verify a child amendment repeats the exact identity and snapshot of a quarantined parent record. */
    private static function validate_amendment_parent_record(
        $parentaudit,
        string $recordtype,
        array $source,
        array $target,
        int $targetid,
        string $prefix,
        array &$errors,
        array &$snapshot
    ): void {
        if (!$parentaudit || (string) $parentaudit->decision !== 'quarantine'
                || (string) $parentaudit->recordtype !== $recordtype
                || (int) $parentaudit->targetid !== $targetid
                || !preg_match('/^[a-f0-9]{64}$/i', (string) ($parentaudit->entryhash ?? ''))) {
            $errors[] = "$prefix must refer to an explicitly quarantined record in the declared parent import.";
            return;
        }

        $parentsource = json_decode((string) $parentaudit->sourceidentity, true);
        $parenttarget = json_decode((string) $parentaudit->targetidentity, true);
        if (!is_array($parentsource) || !is_array($parenttarget)
                || self::canonical_json($parentsource) !== self::canonical_json($source)
                || self::canonical_json($parenttarget) !== self::canonical_json($target)) {
            $errors[] = "$prefix cannot change the source or target identity recorded by its parent.";
            return;
        }

        $parentstate = json_decode((string) ($parentaudit->resultstate ?? ''), true);
        $currentstate = $recordtype === 'module'
            ? self::module_result_state($targetid)
            : self::grade_item_result_state($targetid);
        if (!is_array($parentstate) || json_last_error() !== JSON_ERROR_NONE
                || self::canonical_json($parentstate) !== self::canonical_json($currentstate)) {
            $errors[] = "$prefix target state no longer matches the recorded parent snapshot.";
            return;
        }

        if ($recordtype === 'module') {
            $mapping = $parentstate['mapping'] ?? null;
            if (!is_array($mapping)
                    || (int) ($mapping['lineage_status'] ?? -1) !== delivery_activity_access::LINEAGE_QUARANTINED
                    || (string) ($mapping['manifest_id'] ?? '') !== (string) $parentaudit->manifestid) {
                $errors[] = "$prefix parent state is not an explicitly quarantined module mapping.";
            }
            foreach (($parentstate['history'] ?? []) as $history) {
                if ((int) ($history['lineage_verified'] ?? 0) === 1) {
                    $errors[] = "$prefix contains already-verified historical lineage and cannot be remapped by amendment.";
                    break;
                }
            }
        } else {
            $mapping = $parentstate['mapping'] ?? null;
            if (is_array($mapping) && (int) ($mapping['lineage_verified'] ?? 0) === 1) {
                $errors[] = "$prefix grade lineage is already verified and cannot be remapped by amendment.";
            } else if (is_array($mapping)
                    && (string) ($mapping['manifest_id'] ?? '') !== (string) $parentaudit->manifestid) {
                $errors[] = "$prefix grade mapping is not the unverified record recorded by its parent.";
            }
        }

        $snapshot['amendment_records'][] = [
            'record_type' => $recordtype,
            'target_id' => $targetid,
            'parent_entry_sha256' => (string) $parentaudit->entryhash,
            'parent_result_sha256' => hash('sha256', self::canonical_json($parentstate)),
            'current_result_sha256' => hash('sha256', self::canonical_json($currentstate)),
        ];
    }

    /** Encode nested maps deterministically while preserving list order. */
    private static function canonical_json($value): string
    {
        $value = self::canonicalise($value);
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? '' : $json;
    }

    /** Sort object-like maps recursively without changing list order. */
    private static function canonicalise($value)
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $child) {
                $value[$key] = self::canonicalise($child);
            }
        }
        return $value;
    }

    /** Return a deterministic result and hash all live target facts into the plan. */
    private static function prepared_result(
        ?array $manifest,
        array $errors,
        string $manifesthash,
        string $manifestid,
        int $courseid,
        array $modules,
        array $gradeitems,
        array $snapshot,
        bool $alreadyapplied = false,
        bool $isamendment = false
    ): array {
        $isamendment = $isamendment || (($manifest['schema_version'] ?? null) === self::AMENDMENT_SCHEMA_VERSION);
        $planpayload = [
            'manifest_sha256' => $manifesthash,
            'snapshot' => $snapshot,
            'errors' => $errors,
        ];
        $encoded = json_encode($planpayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return [
            'manifest' => $manifest,
            'manifestid' => $manifestid,
            'manifesthash' => $manifesthash,
            'courseid' => $courseid,
            'modules' => $modules,
            'gradeitems' => $gradeitems,
            'snapshot' => $snapshot,
            'errors' => array_values(array_unique($errors)),
            'planhash' => hash('sha256', $encoded === false ? '' : $encoded),
            'already_applied' => $alreadyapplied,
            'is_amendment' => $isamendment,
        ];
    }

    /** A deliberately narrow snapshot of lineage state that makes dry-runs stale on drift. */
    private static function mapping_snapshot(\stdClass $mapping): array
    {
        return [
            'courseid' => (int) $mapping->courseid,
            'deliveryid' => (int) $mapping->deliveryid,
            'groupingid' => (int) $mapping->groupingid,
            'lineagestatus' => (int) $mapping->lineagestatus,
            'lineagemanifestid' => (string) ($mapping->lineagemanifestid ?? ''),
            'originalavailability' => (string) ($mapping->originalavailability ?? ''),
            'managedavailability' => (string) ($mapping->managedavailability ?? ''),
        ];
    }

    /** Apply a module decision, keeping historical and disposed activities locked. */
    private static function apply_module_operation(array $operation, string $manifestid): void
    {
        global $DB;

        $cmid = (int) $operation['target']['cmid'];
        $courseid = (int) $operation['courseid'];
        $now = time();
        $mapping = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => $cmid], '*', IGNORE_MISSING);
        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        if (!$mapping) {
            $mapping = (object) [
                'cmid' => $cmid,
                'courseid' => $courseid,
                'deliveryid' => 0,
                'groupingid' => -max(1, $cmid),
                'lineagestatus' => delivery_activity_access::LINEAGE_UNREVIEWED,
                'lineagemanifestid' => null,
                'originalavailability' => $cm->availability,
                'managedavailability' => null,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $mapping->id = $DB->insert_record('local_rtcsync_deliverycm', $mapping);
        }

        $decision = $operation['decision'];
        $mapping->courseid = $courseid;
        $mapping->lineagemanifestid = $manifestid;
        $mapping->timemodified = $now;
        if ($decision === 'current') {
            $mapping->lineagestatus = delivery_activity_access::LINEAGE_CURRENT;
            $DB->update_record('local_rtcsync_deliverycm', $mapping);
            delivery_activity_access::protect_course_module($cmid, false);
            $mapping = $DB->get_record('local_rtcsync_deliverycm', ['cmid' => $cmid], '*', MUST_EXIST);
            if ((int) $mapping->lineagestatus !== delivery_activity_access::LINEAGE_CURRENT
                    || (int) $mapping->deliveryid !== (int) $operation['deliveryids'][0]
                    || (int) $mapping->groupingid !== (int) $operation['targetgroupingid']) {
                throw new \moodle_exception('error', 'local_rtcsync', '', null,
                    'The protected module no longer matches the reviewed current delivery.');
            }
        } else {
            $status = match ($decision) {
                'historical' => delivery_activity_access::LINEAGE_HISTORICAL,
                'archive' => delivery_activity_access::LINEAGE_ARCHIVED,
                'dispose' => delivery_activity_access::LINEAGE_DISPOSED,
                default => delivery_activity_access::LINEAGE_QUARANTINED,
            };
            $mapping->lineagestatus = $status;
            $mapping->deliveryid = 0;
            $mapping->groupingid = -max(1, $cmid);
            $DB->update_record('local_rtcsync_deliverycm', $mapping);
            delivery_activity_access::protect_course_module($cmid, false);

            if ($decision === 'historical') {
                $deliveryid = (int) $operation['deliveryids'][0];
                $history = $DB->get_record('local_rtcsync_deliverycmhist', [
                    'cmid' => $cmid,
                    'courseid' => $courseid,
                    'deliveryid' => $deliveryid,
                ], '*', IGNORE_MISSING);
                if ($history) {
                    $history->lineageverified = 1;
                    $history->lineagemanifestid = $manifestid;
                    $DB->update_record('local_rtcsync_deliverycmhist', $history);
                } else {
                    $DB->insert_record('local_rtcsync_deliverycmhist', (object) [
                        'cmid' => $cmid,
                        'courseid' => $courseid,
                        'deliveryid' => $deliveryid,
                        'timecreated' => $now,
                        'lineageverified' => 1,
                        'lineagemanifestid' => $manifestid,
                    ]);
                }
            }
        }

        rebuild_course_cache($courseid, true);
    }

    /** Apply one reviewed explicit mapping for a manual grade item. */
    private static function apply_grade_operation(array $operation, string $manifestid, bool $isamendment = false): void
    {
        global $DB;

        $gradeitemid = (int) $operation['target']['itemid'];
        $mapping = $DB->get_record('local_rtcsync_deliverygrade', ['gradeitemid' => $gradeitemid], '*', IGNORE_MISSING);
        $now = time();
        if ($operation['decision'] !== 'map') {
            if ($mapping) {
                if ((int) $mapping->courseid !== (int) $operation['courseid']
                        || (int) $mapping->lineageverified === 1) {
                    throw new \moodle_exception('error', 'local_rtcsync', '', null,
                        'The grade-item mapping changed after disposition validation.');
                }
                $mapping->lineageverified = 0;
                $mapping->lineagemanifestid = $manifestid;
                $mapping->timemodified = $now;
                $DB->update_record('local_rtcsync_deliverygrade', $mapping);
            }
            return;
        }
        if ($mapping) {
            if ((int) $mapping->courseid !== (int) $operation['courseid']
                    || ((int) $mapping->deliveryid !== (int) $operation['deliveryids'][0] && !$isamendment)
                    || ($isamendment && (int) $mapping->lineageverified === 1)) {
                throw new \moodle_exception('error', 'local_rtcsync', '', null,
                    'The grade-item mapping changed after manifest validation.');
            }
            if ((int) $mapping->lineageverified === 1) {
                return;
            }
            if ($isamendment) {
                $mapping->deliveryid = (int) $operation['deliveryids'][0];
            }
            $mapping->lineageverified = 1;
            $mapping->lineagemanifestid = $manifestid;
            $mapping->timemodified = $now;
            $DB->update_record('local_rtcsync_deliverygrade', $mapping);
            return;
        }

        $DB->insert_record('local_rtcsync_deliverygrade', (object) [
            'gradeitemid' => $gradeitemid,
            'courseid' => (int) $operation['courseid'],
            'deliveryid' => (int) $operation['deliveryids'][0],
            'timecreated' => $now,
            'timemodified' => $now,
            'lineageverified' => 1,
            'lineagemanifestid' => $manifestid,
        ]);
    }

    /** Store the immutable per-record reviewer decision and source/target identities. */
    private static function insert_audit(
        array $operation,
        string $recordtype,
        int $courseid,
        string $manifestid,
        string $manifesthash,
        int $now,
        ?\stdClass $parentaudit = null
    ): void {
        global $DB;

        $source = json_encode($operation['source'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $target = json_encode($operation['target'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $deliveryids = json_encode($operation['deliveryids']);
        $entry = json_encode($operation['entry'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $resultstate = $recordtype === 'module'
            ? self::module_result_state((int) $operation['target']['cmid'])
            : self::grade_item_result_state((int) $operation['target']['itemid']);
        $encodedresultstate = json_encode($resultstate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encodedresultstate === false) {
            throw new \moodle_exception('error', 'local_rtcsync', '', null,
                'Could not record the applied delivery-lineage target state.');
        }
        $DB->insert_record('local_rtcsync_lineageaudit', (object) [
            'manifestid' => $manifestid,
            'manifesthash' => $manifesthash,
            'courseid' => $courseid,
            'recordtype' => $recordtype,
            'targetid' => (int) $operation['target'][$recordtype === 'module' ? 'cmid' : 'itemid'],
            'decision' => $operation['decision'],
            'deliveryids' => $deliveryids === false ? '[]' : $deliveryids,
            'sourceidentity' => $source === false ? '{}' : $source,
            'targetidentity' => $target === false ? '{}' : $target,
            'reason' => $operation['reason'],
            'entryhash' => hash('sha256', $entry === false ? '' : $entry),
            'resultstate' => $encodedresultstate,
            'parententryhash' => $parentaudit ? (string) $parentaudit->entryhash : null,
            'parentresultstate' => $parentaudit ? (string) $parentaudit->resultstate : null,
            'timecreated' => $now,
        ]);
    }

    /** Capture the safe persisted result of a module decision inside the apply transaction. */
    private static function module_result_state(int $cmid): array
    {
        global $DB;

        $module = $DB->get_record_sql(
            'SELECT cm.id, cm.course, cm.module, cm.instance, cm.idnumber, cm.groupingid, cm.availability,
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

    /** Capture the safe persisted result of a grade-item decision inside the apply transaction. */
    private static function grade_item_result_state(int $itemid): array
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
}
