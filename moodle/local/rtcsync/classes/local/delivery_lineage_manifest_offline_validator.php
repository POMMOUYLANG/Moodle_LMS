<?php

namespace local_rtcsync\local;

defined('MOODLE_INTERNAL') || die();

/** Validate manifest structure and explicit lineage decisions without Moodle APIs. */
final class delivery_lineage_manifest_offline_validator
{
    private const ROOT_FIELDS = [
        'schema_version', 'manifest_id', 'course_idnumber', 'source_snapshot_sha256',
        'source_plugin_version', 'review', 'entries', 'grade_items',
    ];

    private const MODULE_DECISIONS = ['current', 'historical', 'quarantine', 'archive', 'dispose'];
    private const GRADE_DECISIONS = ['map', 'quarantine', 'archive', 'dispose'];

    /** Validate UTF-8 manifest bytes without loading Moodle configuration or querying a database. */
    public static function validate(string $json, ?string $parentjson = null): array
    {
        return self::validate_document($json, $parentjson, false);
    }

    /**
     * Validate one document. The parent-only mode validates its shape and quarantine records
     * without pretending to authenticate its own earlier chain links.
     */
    private static function validate_document(string $json, ?string $parentjson, bool $parentonly): array
    {
        $diagnostics = [];
        $manifest = json_decode($json);
        if (json_last_error() !== JSON_ERROR_NONE || !$manifest instanceof \stdClass) {
            self::add($diagnostics, 'manifest', 0, 0, 'manifest_json_object_required');
            return self::result($diagnostics, null, false, false);
        }

        $root = get_object_vars($manifest);
        $version = $root['schema_version'] ?? null;
        $amendment = $version === 2;
        $allowedroot = self::ROOT_FIELDS;
        if ($amendment) {
            $allowedroot[] = 'amendment';
        }
        foreach (self::ROOT_FIELDS as $field) {
            if (!array_key_exists($field, $root)) {
                self::add($diagnostics, 'manifest', 0, 0, 'missing_' . $field);
            }
        }
        if (array_diff(array_keys($root), $allowedroot)) {
            self::add($diagnostics, 'manifest', 0, 0, 'unsupported_root_field');
        }
        if (!is_int($version) || !in_array($version, [1, 2], true)) {
            self::add($diagnostics, 'manifest', 0, 0, 'unsupported_schema_version');
        }

        $manifestid = $root['manifest_id'] ?? null;
        if (!is_string($manifestid) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', $manifestid) !== 1) {
            self::add($diagnostics, 'manifest', 0, 0, 'invalid_manifest_id');
        }
        $courseidnumber = $root['course_idnumber'] ?? null;
        if (!is_string($courseidnumber) || preg_match('/^rtc-subject:[1-9][0-9]*$/', $courseidnumber) !== 1) {
            self::add($diagnostics, 'manifest', 0, 0, 'invalid_course_identity');
        }
        $sourcehash = $root['source_snapshot_sha256'] ?? null;
        if (!is_string($sourcehash) || preg_match('/^[a-f0-9]{64}$/i', $sourcehash) !== 1) {
            self::add($diagnostics, 'manifest', 0, 0, 'invalid_source_snapshot_digest');
        }
        if (!is_int($root['source_plugin_version'] ?? null) || $root['source_plugin_version'] <= 0) {
            self::add($diagnostics, 'manifest', 0, 0, 'invalid_source_plugin_version');
        }

        self::validate_review($root['review'] ?? null, $diagnostics);
        self::validate_amendment_header($root['amendment'] ?? null, $amendment, $diagnostics);

        $entries = $root['entries'] ?? null;
        if (!is_array($entries) || !array_is_list($entries)) {
            self::add($diagnostics, 'module', 0, 0, 'module_entries_array_required');
            $entries = [];
        } else if (!$entries && !$amendment) {
            self::add($diagnostics, 'module', 0, 0, 'module_entry_required');
        }

        $gradeentries = $root['grade_items'] ?? null;
        if (!is_array($gradeentries) || !array_is_list($gradeentries)) {
            self::add($diagnostics, 'grade_item', 0, 0, 'grade_items_array_required');
            $gradeentries = [];
        }

        $modules = self::validate_module_entries($entries, $courseidnumber, $amendment, $diagnostics);
        $grades = self::validate_grade_entries($gradeentries, $courseidnumber, $amendment, $diagnostics);

        if ($amendment && !$modules && !$grades) {
            self::add($diagnostics, 'manifest', 0, 0, 'amendment_has_no_records');
        }

        $parentchecked = false;
        if ($amendment) {
            if ($parentonly) {
                // The direct parent file is checked by its own child. Its prior chain is a target-side check.
            } else if ($parentjson === null) {
                self::add($diagnostics, 'manifest', 0, 0, 'amendment_parent_manifest_required');
            } else {
                $parentchecked = self::validate_parent_coverage(
                    $parentjson,
                    $root,
                    $modules,
                    $grades,
                    $diagnostics
                );
            }
        } else if ($parentjson !== null) {
            self::add($diagnostics, 'manifest', 0, 0, 'parent_manifest_not_allowed');
        }

        return self::result($diagnostics, $version, $parentchecked, true);
    }

    /** Validate reviewer fields without returning their values. */
    private static function validate_review($review, array &$diagnostics): void
    {
        if (!$review instanceof \stdClass) {
            self::add($diagnostics, 'manifest', 0, 0, 'review_object_required');
            return;
        }
        $fields = get_object_vars($review);
        $required = ['approved_by', 'approved_at', 'approval_reference'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $fields)) {
                self::add($diagnostics, 'manifest', 0, 0, 'review_' . $field . '_required');
            }
        }
        if (array_diff(array_keys($fields), $required)) {
            self::add($diagnostics, 'manifest', 0, 0, 'unsupported_review_field');
        }
        foreach (['approved_by', 'approval_reference'] as $field) {
            if (!is_string($fields[$field] ?? null) || trim($fields[$field]) === ''
                    || self::utf8_length((string) ($fields[$field] ?? '')) > 255) {
                self::add($diagnostics, 'manifest', 0, 0, 'invalid_review_' . $field);
            }
        }
        $timestamp = $fields['approved_at'] ?? null;
        if (!is_string($timestamp) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $timestamp) !== 1
                || !self::valid_utc_timestamp((string) $timestamp)) {
            self::add($diagnostics, 'manifest', 0, 0, 'invalid_review_approved_at');
        }
    }

    /** Validate the v2 amendment parent reference syntax. */
    private static function validate_amendment_header($amendment, bool $isamendment, array &$diagnostics): void
    {
        if (!$isamendment) {
            if ($amendment !== null) {
                self::add($diagnostics, 'manifest', 0, 0, 'amendment_not_allowed_for_schema_version');
            }
            return;
        }
        if (!$amendment instanceof \stdClass) {
            self::add($diagnostics, 'manifest', 0, 0, 'amendment_object_required');
            return;
        }
        $fields = get_object_vars($amendment);
        if (array_diff(array_keys($fields), ['parent_manifest_id', 'parent_manifest_sha256'])
                || array_diff(['parent_manifest_id', 'parent_manifest_sha256'], array_keys($fields))) {
            self::add($diagnostics, 'manifest', 0, 0, 'invalid_amendment_fields');
        }
        if (!is_string($fields['parent_manifest_id'] ?? null)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', (string) ($fields['parent_manifest_id'] ?? '')) !== 1
                || !is_string($fields['parent_manifest_sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/i', (string) ($fields['parent_manifest_sha256'] ?? '')) !== 1) {
            self::add($diagnostics, 'manifest', 0, 0, 'invalid_amendment_parent_reference');
        }
    }

    /** Validate module record identities, dispositions, ambiguity, and safe target IDs. */
    private static function validate_module_entries(
        array $entries,
        $courseidnumber,
        bool $amendment,
        array &$diagnostics
    ): array {
        $records = [];
        $seenSources = [];
        $seenTargets = [];
        foreach ($entries as $index => $entry) {
            $position = $index + 1;
            $targetid = self::numeric_property($entry, 'target', 'cmid');
            $type = 'module';
            if (!$entry instanceof \stdClass) {
                self::add($diagnostics, $type, $position, $targetid, 'module_entry_object_required');
                continue;
            }
            $fields = get_object_vars($entry);
            if (array_diff(array_keys($fields), ['source', 'target', 'decision', 'delivery_ids', 'reason'])) {
                self::add($diagnostics, $type, $position, $targetid, 'unsupported_module_entry_field');
            }
            $source = self::module_identity($fields['source'] ?? null, $courseidnumber);
            $target = self::module_identity($fields['target'] ?? null, $courseidnumber);
            if ($source === null) {
                self::add($diagnostics, $type, $position, $targetid, 'invalid_module_source_identity');
            }
            if ($target === null) {
                self::add($diagnostics, $type, $position, $targetid, 'invalid_module_target_identity');
            }
            if ($source === null || $target === null) {
                // Continue checking disposition without retaining malformed identities.
            }

            $decision = $fields['decision'] ?? null;
            $allowed = $amendment ? ['current', 'historical', 'quarantine'] : self::MODULE_DECISIONS;
            if (!is_string($decision) || $decision === '') {
                self::add($diagnostics, $type, $position, $targetid, 'module_disposition_required');
            } else if (!in_array($decision, $allowed, true)) {
                self::add($diagnostics, $type, $position, $targetid, 'invalid_module_disposition');
            }

            $deliveryids = $fields['delivery_ids'] ?? null;
            $validids = self::delivery_ids($deliveryids);
            if ($validids === null) {
                self::add($diagnostics, $type, $position, $targetid, 'invalid_module_delivery_ids');
            } else if (in_array($decision, ['current', 'historical'], true) && count($validids) !== 1) {
                self::add($diagnostics, $type, $position, $targetid, 'module_mapping_requires_one_delivery');
            } else if (in_array($decision, ['quarantine', 'archive', 'dispose'], true) && $validids !== []) {
                self::add($diagnostics, $type, $position, $targetid, 'module_disposition_cannot_assign_delivery');
            }
            self::validate_reason($fields['reason'] ?? null, $type, $position, $targetid, $diagnostics);

            if ($source !== null) {
                $sourcekey = $source['course_idnumber'] . ':' . $source['cmid'];
                $targetkey = $target === null ? '' : $target['course_idnumber'] . ':' . $target['cmid'];
                if (isset($seenSources[$sourcekey])) {
                    $code = $seenSources[$sourcekey]['target'] !== $targetkey
                        ? 'ambiguous_source_one_to_many_mapping' : 'duplicate_source_identity';
                    self::add($diagnostics, $type, $position, $targetid, $code);
                } else {
                    $seenSources[$sourcekey] = ['target' => $targetkey, 'position' => $position];
                }
            }
            if ($target !== null) {
                $targetkey = $target['course_idnumber'] . ':' . $target['cmid'];
                if (isset($seenTargets[$targetkey])) {
                    self::add($diagnostics, $type, $position, $targetid, 'duplicate_target_identity');
                } else {
                    $seenTargets[$targetkey] = $position;
                }
            }

            if ($source !== null && $target !== null && is_string($decision)
                    && in_array($decision, $allowed, true)) {
                $records[] = [
                    'source' => $source,
                    'target' => $target,
                    'decision' => $decision,
                    'position' => $position,
                ];
            }
        }
        return $records;
    }

    /** Validate manual grade item records and reject reused source/target identities. */
    private static function validate_grade_entries(
        array $entries,
        $courseidnumber,
        bool $amendment,
        array &$diagnostics
    ): array {
        $records = [];
        $seenSources = [];
        $seenTargets = [];
        foreach ($entries as $index => $entry) {
            $position = $index + 1;
            $targetid = self::numeric_property($entry, 'target', 'itemid');
            $type = 'grade_item';
            if (!$entry instanceof \stdClass) {
                self::add($diagnostics, $type, $position, $targetid, 'grade_item_entry_object_required');
                continue;
            }
            $fields = get_object_vars($entry);
            if (array_diff(array_keys($fields), ['source', 'target', 'decision', 'delivery_id', 'reason'])) {
                self::add($diagnostics, $type, $position, $targetid, 'unsupported_grade_item_entry_field');
            }
            $source = self::grade_identity($fields['source'] ?? null, $courseidnumber);
            $target = self::grade_identity($fields['target'] ?? null, $courseidnumber);
            if ($source === null) {
                self::add($diagnostics, $type, $position, $targetid, 'invalid_grade_item_source_identity');
            }
            if ($target === null) {
                self::add($diagnostics, $type, $position, $targetid, 'invalid_grade_item_target_identity');
            }

            $decision = $fields['decision'] ?? null;
            $allowed = $amendment ? ['map', 'quarantine'] : self::GRADE_DECISIONS;
            if (!is_string($decision) || $decision === '') {
                self::add($diagnostics, $type, $position, $targetid, 'grade_item_disposition_required');
            } else if (!in_array($decision, $allowed, true)) {
                self::add($diagnostics, $type, $position, $targetid, 'invalid_grade_item_disposition');
            }

            $deliveryid = $fields['delivery_id'] ?? null;
            if (!is_int($deliveryid)
                    || ($decision === 'map' ? $deliveryid <= 0 : $deliveryid !== 0)) {
                self::add($diagnostics, $type, $position, $targetid, 'invalid_grade_item_delivery_id');
            }
            self::validate_reason($fields['reason'] ?? null, $type, $position, $targetid, $diagnostics);

            if ($source !== null) {
                $sourcekey = $source['course_idnumber'] . ':' . $source['itemid'];
                $targetkey = $target === null ? '' : $target['course_idnumber'] . ':' . $target['itemid'];
                if (isset($seenSources[$sourcekey])) {
                    $code = $seenSources[$sourcekey]['target'] !== $targetkey
                        ? 'ambiguous_source_one_to_many_mapping' : 'duplicate_source_identity';
                    self::add($diagnostics, $type, $position, $targetid, $code);
                } else {
                    $seenSources[$sourcekey] = ['target' => $targetkey, 'position' => $position];
                }
            }
            if ($target !== null) {
                $targetkey = $target['course_idnumber'] . ':' . $target['itemid'];
                if (isset($seenTargets[$targetkey])) {
                    self::add($diagnostics, $type, $position, $targetid, 'duplicate_target_identity');
                } else {
                    $seenTargets[$targetkey] = $position;
                }
            }

            if ($source !== null && $target !== null && is_string($decision)
                    && in_array($decision, $allowed, true)) {
                $records[] = [
                    'source' => $source,
                    'target' => $target,
                    'decision' => $decision,
                    'position' => $position,
                ];
            }
        }
        return $records;
    }

    /** Return canonical module identity only when every importer-required field is valid. */
    private static function module_identity($identity, $courseidnumber): ?array
    {
        if (!$identity instanceof \stdClass) {
            return null;
        }
        $fields = get_object_vars($identity);
        if (array_diff(array_keys($fields), ['course_idnumber', 'cmid', 'modname', 'instanceid', 'idnumber'])
                || array_diff(['course_idnumber', 'cmid', 'modname', 'instanceid', 'idnumber'], array_keys($fields))) {
            return null;
        }
        if (!is_string($courseidnumber) || ($fields['course_idnumber'] ?? null) !== $courseidnumber
                || !is_int($fields['cmid'] ?? null) || $fields['cmid'] <= 0
                || !is_int($fields['instanceid'] ?? null) || $fields['instanceid'] <= 0
                || !is_string($fields['modname'] ?? null)
                || preg_match('/^[a-z][a-z0-9_]{0,99}$/', trim($fields['modname'])) !== 1
                || !is_string($fields['idnumber'] ?? null)
                || self::utf8_length($fields['idnumber']) > 100) {
            return null;
        }
        return [
            'course_idnumber' => $fields['course_idnumber'],
            'cmid' => $fields['cmid'],
            'modname' => trim($fields['modname']),
            'instanceid' => $fields['instanceid'],
            'idnumber' => $fields['idnumber'],
        ];
    }

    /** Return canonical manual grade identity only when importer-required fields are valid. */
    private static function grade_identity($identity, $courseidnumber): ?array
    {
        if (!$identity instanceof \stdClass) {
            return null;
        }
        $fields = get_object_vars($identity);
        if (array_diff(array_keys($fields), ['course_idnumber', 'itemid', 'itemtype', 'itemmodule', 'iteminstance', 'idnumber'])
                || array_diff(['course_idnumber', 'itemid', 'itemtype', 'itemmodule', 'iteminstance', 'idnumber'], array_keys($fields))) {
            return null;
        }
        if (!is_string($courseidnumber) || ($fields['course_idnumber'] ?? null) !== $courseidnumber
                || !is_int($fields['itemid'] ?? null) || $fields['itemid'] <= 0
                || ($fields['itemtype'] ?? null) !== 'manual'
                || ($fields['itemmodule'] ?? null) !== ''
                || !is_int($fields['iteminstance'] ?? null) || $fields['iteminstance'] !== 0
                || !is_string($fields['idnumber'] ?? null)
                || self::utf8_length($fields['idnumber']) > 255) {
            return null;
        }
        return [
            'course_idnumber' => $fields['course_idnumber'],
            'itemid' => $fields['itemid'],
            'itemtype' => $fields['itemtype'],
            'itemmodule' => $fields['itemmodule'],
            'iteminstance' => $fields['iteminstance'],
            'idnumber' => $fields['idnumber'],
        ];
    }

    /** Validate amendment's exact direct-parent quarantine coverage from reviewed files. */
    private static function validate_parent_coverage(
        string $parentjson,
        array $child,
        array $childmodules,
        array $childgrades,
        array &$diagnostics
    ): bool {
        $parentresult = self::validate_document($parentjson, null, true);
        if (!$parentresult['format_valid']) {
            self::add($diagnostics, 'manifest', 0, 0, 'parent_manifest_structure_invalid');
            return false;
        }
        $parent = json_decode($parentjson);
        if (!$parent instanceof \stdClass) {
            self::add($diagnostics, 'manifest', 0, 0, 'parent_manifest_structure_invalid');
            return false;
        }
        $parentfields = get_object_vars($parent);
        $amendment = $child['amendment'] instanceof \stdClass
            ? get_object_vars($child['amendment']) : [];
        if (!is_string($amendment['parent_manifest_id'] ?? null)
                || !is_string($parentfields['manifest_id'] ?? null)
                || !hash_equals((string) $parentfields['manifest_id'], (string) $amendment['parent_manifest_id'])
                || !is_string($amendment['parent_manifest_sha256'] ?? null)
                || !hash_equals(strtolower((string) $amendment['parent_manifest_sha256']), hash('sha256', $parentjson))) {
            self::add($diagnostics, 'manifest', 0, 0, 'parent_manifest_identity_or_digest_mismatch');
            return false;
        }
        foreach (['course_idnumber', 'source_snapshot_sha256', 'source_plugin_version'] as $field) {
            if (($child[$field] ?? null) !== ($parentfields[$field] ?? null)) {
                self::add($diagnostics, 'manifest', 0, 0, 'parent_manifest_source_or_course_mismatch');
                return false;
            }
        }

        $expectedmodules = self::parent_quarantine_records(
            $parentfields['entries'] ?? [], 'module', $parentfields['course_idnumber'] ?? null, $diagnostics
        );
        $expectedgrades = self::parent_quarantine_records(
            $parentfields['grade_items'] ?? [], 'grade_item', $parentfields['course_idnumber'] ?? null, $diagnostics
        );
        if (!$expectedmodules && !$expectedgrades) {
            self::add($diagnostics, 'manifest', 0, 0, 'parent_has_no_quarantine_records');
            return false;
        }
        $moduleok = self::compare_parent_records($expectedmodules, $childmodules, 'module', $diagnostics);
        $gradeok = self::compare_parent_records($expectedgrades, $childgrades, 'grade_item', $diagnostics);
        return $moduleok && $gradeok;
    }

    /** Collect only structurally valid parent quarantine entries. */
    private static function parent_quarantine_records($entries, string $type, $courseidnumber, array &$diagnostics): array
    {
        if (!is_array($entries) || !array_is_list($entries)) {
            return [];
        }
        $records = [];
        foreach ($entries as $index => $entry) {
            if (!$entry instanceof \stdClass) {
                continue;
            }
            $fields = get_object_vars($entry);
            if (($fields['decision'] ?? null) !== 'quarantine') {
                continue;
            }
            $source = $type === 'module'
                ? self::module_identity($fields['source'] ?? null, $courseidnumber)
                : self::grade_identity($fields['source'] ?? null, $courseidnumber);
            $target = $type === 'module'
                ? self::module_identity($fields['target'] ?? null, $courseidnumber)
                : self::grade_identity($fields['target'] ?? null, $courseidnumber);
            if ($source === null || $target === null) {
                self::add($diagnostics, 'manifest', 0, 0, 'parent_quarantine_identity_invalid');
                continue;
            }
            $targetid = $type === 'module' ? $target['cmid'] : $target['itemid'];
            $records[$targetid] = [
                'source' => $source,
                'target' => $target,
                'position' => $index + 1,
            ];
        }
        return $records;
    }

    /** Ensure the child contains exactly the direct parent's quarantined identities. */
    private static function compare_parent_records(array $expected, array $actual, string $type, array &$diagnostics): bool
    {
        $ok = true;
        $actualbytarget = [];
        foreach ($actual as $record) {
            $targetid = $type === 'module' ? $record['target']['cmid'] : $record['target']['itemid'];
            $actualbytarget[$targetid] = $record;
        }
        foreach ($expected as $targetid => $parentrecord) {
            if (!isset($actualbytarget[$targetid])) {
                self::add($diagnostics, $type, 0, (int) $targetid, 'parent_quarantine_record_omitted');
                $ok = false;
                continue;
            }
            if ($actualbytarget[$targetid]['source'] !== $parentrecord['source']
                    || $actualbytarget[$targetid]['target'] !== $parentrecord['target']) {
                self::add(
                    $diagnostics,
                    $type,
                    (int) $actualbytarget[$targetid]['position'],
                    (int) $targetid,
                    'parent_quarantine_identity_changed'
                );
                $ok = false;
            }
        }
        foreach ($actualbytarget as $targetid => $record) {
            if (!isset($expected[$targetid])) {
                self::add(
                    $diagnostics,
                    $type,
                    (int) $record['position'],
                    (int) $targetid,
                    'record_not_in_parent_quarantine'
                );
                $ok = false;
            }
        }
        return $ok;
    }

    /** Validate module or grade reason without ever echoing it. */
    private static function validate_reason($reason, string $type, int $position, int $targetid, array &$diagnostics): void
    {
        if (!is_string($reason) || trim($reason) === '' || self::utf8_length($reason) > 2000) {
            self::add($diagnostics, $type, $position, $targetid, 'review_reason_required_or_too_long');
        }
    }

    /** Validate one explicit, unique list of positive integer delivery IDs. */
    private static function delivery_ids($ids): ?array
    {
        if (!is_array($ids) || !array_is_list($ids)) {
            return null;
        }
        $seen = [];
        foreach ($ids as $id) {
            if (!is_int($id) || $id <= 0 || isset($seen[$id])) {
                return null;
            }
            $seen[$id] = true;
        }
        return array_values(array_map('intval', array_keys($seen)));
    }

    /** Convert a manifest's untrusted content to stable, privacy-safe validation diagnostics. */
    private static function result(array $diagnostics, $version, bool $parentchecked, bool $jsonvalid): array
    {
        usort($diagnostics, static function(array $left, array $right): int {
            return [$left['record_type'], $left['record_position'], $left['target_id'], $left['code']]
                <=> [$right['record_type'], $right['record_position'], $right['target_id'], $right['code']];
        });
        $diagnostics = array_values($diagnostics);
        $formatvalid = $jsonvalid && !$diagnostics;
        return [
            'valid' => $formatvalid,
            'format_valid' => $formatvalid,
            'validation_scope' => 'offline_structure_only',
            'schema_version' => is_int($version) ? $version : null,
            'diagnostic_count' => count($diagnostics),
            'diagnostics' => $diagnostics,
            'checks_not_performed' => [
                'live_target_references' => 'requires_moodle_dry_run',
                'complete_target_inventory_coverage' => 'requires_moodle_dry_run',
                'delivery_grouping_consistency' => 'requires_moodle_dry_run',
                'amendment_current_chain_head_and_audit' => 'requires_moodle_dry_run',
                'source_backup_ownership' => 'not_authenticated',
                'reviewer_and_approval_authenticity' => 'not_authenticated',
            ],
            'amendment_parent_file_checked' => $parentchecked,
        ];
    }

    /** Add a diagnostic made only from controlled codes and numeric positions/IDs. */
    private static function add(array &$diagnostics, string $type, int $position, int $targetid, string $code): void
    {
        $diagnostics[] = [
            'record_type' => $type,
            'record_position' => max(0, $position),
            'target_id' => max(0, $targetid),
            'code' => $code,
        ];
    }

    /** Return only an actual positive integer target identifier from untrusted JSON. */
    private static function numeric_property($entry, string $side, string $field): int
    {
        if (!$entry instanceof \stdClass) {
            return 0;
        }
        $properties = get_object_vars($entry);
        $identity = $properties[$side] ?? null;
        if (!$identity instanceof \stdClass) {
            return 0;
        }
        $value = get_object_vars($identity)[$field] ?? 0;
        return is_int($value) && $value > 0 ? $value : 0;
    }

    /** Strictly check a calendar-valid UTC timestamp in the importer's fixed format. */
    private static function valid_utc_timestamp(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->getTimestamp() > 0 && $date->format('Y-m-d\TH:i:s\Z') === $value;
    }

    /** Count Unicode characters using core PHP only. */
    private static function utf8_length(string $value): int
    {
        $count = preg_match_all('/./us', $value, $matches);
        return $count === false ? PHP_INT_MAX : $count;
    }
}
