<?php

namespace local_rtcsync\local;

defined('MOODLE_INTERNAL') || die();

/** Create and validate a deterministic, redacted lineage evidence bundle. */
final class delivery_lineage_evidence_bundle
{
    private const FORMAT = 'rtc-delivery-lineage-evidence';
    private const FORMAT_VERSION = 1;
    private const MAX_BYTES = 8388608;

    /** Build a bundle from the independent verifier's safe result only. */
    public static function create(array $verification): array
    {
        $import = $verification['import'] ?? null;
        $chain = $verification['lineage_chain'] ?? null;
        $recordoutcomes = $verification['record_outcomes'] ?? null;
        $counts = $verification['counts'] ?? null;
        $sourceartifact = $verification['source_artifact_verification'] ?? null;
        if (!is_array($import) || !is_array($chain) || !array_is_list($chain) || !$chain
                || !is_array($recordoutcomes) || !array_is_list($recordoutcomes)
                || !is_array($counts) || !is_array($sourceartifact)
                || !in_array($verification['status'] ?? null, ['match', 'drift', 'incomplete'], true)) {
            throw new \invalid_parameter_exception('A complete applied-import verification result is required for evidence export.');
        }

        $chainidmap = [];
        $chainidsbyindex = [];
        $chainidsused = [];
        foreach ($chain as $index => $member) {
            if (!is_array($member)) {
                throw new \invalid_parameter_exception('The verifier returned an invalid amendment-chain member.');
            }
            $rawid = (string) ($member['manifest_id'] ?? '');
            $safeid = self::valid_identity($rawid) ? $rawid : '';
            if ($safeid === '' || isset($chainidsused[$safeid])) {
                $base = 'redacted-chain-' . sprintf('%04d', $index + 1);
                $safeid = $base;
                $suffix = 1;
                while (isset($chainidsused[$safeid])) {
                    $safeid = $base . '-' . $suffix++;
                }
            }
            $chainidsused[$safeid] = true;
            $chainidsbyindex[$index] = $safeid;
            if (!array_key_exists($rawid, $chainidmap)) {
                $chainidmap[$rawid] = $safeid;
            }
        }

        $records = [];
        $recordidsequence = 0;
        foreach ($recordoutcomes as $record) {
            if (!is_array($record)) {
                throw new \invalid_parameter_exception('The verifier returned an invalid per-record outcome.');
            }
            $rawdeliveryids = is_array($record['delivery_ids'] ?? null) ? $record['delivery_ids'] : [];
            $deliveryids = [];
            foreach ($rawdeliveryids as $deliveryid) {
                if (is_int($deliveryid) || (is_string($deliveryid) && ctype_digit($deliveryid))) {
                    $deliveryid = (int) $deliveryid;
                    if ($deliveryid > 0) {
                        $deliveryids[$deliveryid] = $deliveryid;
                    }
                }
            }
            $fields = is_array($record['fields'] ?? null) ? $record['fields'] : [];
            $rawmanifestid = (string) ($record['manifest_id'] ?? '');
            $recordmanifestid = $chainidmap[$rawmanifestid] ?? '';
            if ($recordmanifestid === '') {
                $recordidsequence++;
                $recordmanifestid = 'redacted-record-' . sprintf('%06d', $recordidsequence);
            }
            $recordtype = (string) ($record['record_type'] ?? '');
            if (!in_array($recordtype, ['module', 'gradeitem'], true)) {
                $recordtype = 'unknown';
            }
            $decision = (string) ($record['decision'] ?? '');
            $decisions = $recordtype === 'module'
                ? ['current', 'historical', 'quarantine', 'archive', 'dispose']
                : ($recordtype === 'gradeitem' ? ['map', 'quarantine', 'archive', 'dispose'] : []);
            if (!in_array($decision, $decisions, true)) {
                $decision = 'unknown';
            }
            if ($decision === 'unknown') {
                $deliveryids = [];
            }
            $outcome = (string) ($record['outcome'] ?? '');
            if (!in_array($outcome, [
                'target_matches_audit', 'superseded_by_amendment', 'target_drift', 'target_missing',
            ], true)) {
                $outcome = 'unknown';
            }
            $records[] = [
                'manifest_id' => $recordmanifestid,
                'record_type' => $recordtype,
                'target_id' => max(0, (int) ($record['target_id'] ?? 0)),
                'decision' => $decision,
                'delivery_ids' => array_values($deliveryids),
                'entry_sha256' => self::safe_sha256((string) ($record['entry_sha256'] ?? '')),
                'outcome' => $outcome,
                'fields' => array_values(array_unique(array_filter($fields, static fn($field): bool =>
                    is_string($field) && preg_match('/^[a-z][a-z0-9_]{0,63}$/', $field) === 1))),
            ];
        }
        usort($records, static function(array $left, array $right): int {
            return [$left['manifest_id'], $left['record_type'], $left['target_id']]
                <=> [$right['manifest_id'], $right['record_type'], $right['target_id']];
        });

        $safechain = [];
        foreach ($chain as $index => $member) {
            $rawid = (string) ($member['manifest_id'] ?? '');
            $rawparentid = (string) ($member['parent_manifest_id'] ?? '');
            $safepid = $rawparentid === '' ? '' : ($chainidmap[$rawparentid] ?? 'redacted-parent');
            $safechain[] = [
                'manifest_id' => $chainidsbyindex[$index],
                'manifest_sha256' => self::safe_sha256((string) ($member['manifest_sha256'] ?? '')),
                'parent_manifest_id' => $safepid,
                'parent_manifest_sha256' => self::safe_sha256((string) ($member['parent_manifest_sha256'] ?? '')),
                'entry_count' => (int) ($member['entry_count'] ?? 0),
                'amendment' => (bool) ($member['amendment'] ?? false),
                'apply_plan_sha256' => self::safe_sha256((string) ($member['apply_plan_sha256'] ?? '')),
                'source_snapshot_sha256' => self::safe_sha256((string) ($member['source_snapshot_sha256'] ?? '')),
                'source_artifact_sha256' => self::safe_sha256((string) ($member['source_artifact_sha256'] ?? '')),
                'source_artifact_verified' => (bool) ($member['source_artifact_verified'] ?? false),
                'source_artifact_verified_at' => max(0, (int) ($member['source_artifact_verified_at'] ?? 0)),
                'source_plugin_version' => max(0, (int) ($member['source_plugin_version'] ?? 0)),
                'manifest_schema_version' => max(0, (int) ($member['manifest_schema_version'] ?? 0)),
                'reviewed_at' => max(0, (int) ($member['reviewed_at'] ?? 0)),
                'applied_at' => max(0, (int) ($member['applied_at'] ?? 0)),
            ];
        }

        $safeimport = [
            'manifest_id' => (string) ($import['manifest_id'] ?? ''),
            'manifest_sha256' => self::safe_sha256((string) ($import['manifest_sha256'] ?? '')),
            'parent_manifest_id' => (string) ($import['parent_manifest_id'] ?? '') === ''
                ? '' : ($chainidmap[(string) $import['parent_manifest_id']] ?? 'redacted-parent'),
            'parent_manifest_sha256' => self::safe_sha256((string) ($import['parent_manifest_sha256'] ?? '')),
            'apply_plan_sha256' => self::safe_sha256((string) ($import['apply_plan_sha256'] ?? '')),
            'source_snapshot_sha256' => self::safe_sha256((string) ($import['source_snapshot_sha256'] ?? '')),
            'source_plugin_version' => max(0, (int) ($import['source_plugin_version'] ?? 0)),
            'manifest_schema_version' => max(0, (int) ($import['manifest_schema_version'] ?? 0)),
            'course_id' => max(0, (int) ($import['course_id'] ?? 0)),
            'reviewed_at' => max(0, (int) ($import['reviewed_at'] ?? 0)),
            'applied_at' => max(0, (int) ($import['applied_at'] ?? 0)),
            'installed_plugin_version' => max(0, (int) ($import['installed_plugin_version'] ?? 0)),
            'database_schema_version' => max(0, (int) ($import['database_schema_version'] ?? 0)),
        ];

        $safeverificationcounts = [];
        foreach ($counts as $name => $value) {
            if (is_string($name) && is_int($value) && $value >= 0) {
                $safeverificationcounts[$name] = $value;
            }
        }
        ksort($safeverificationcounts, SORT_STRING);

        $unresolved = 0;
        $verified = 0;
        $superseded = 0;
        $conflicts = 0;
        foreach ($records as $record) {
            if ($record['outcome'] === 'superseded_by_amendment') {
                $superseded++;
                continue;
            }
            if ($record['decision'] === 'quarantine') {
                $unresolved++;
            }
            if ($record['outcome'] === 'target_matches_audit') {
                $verified++;
            } else if (in_array($record['outcome'], ['target_drift', 'target_missing'], true)) {
                $conflicts++;
            }
        }
        $issuecount = 0;
        foreach ($safeverificationcounts as $name => $value) {
            if (!in_array($name, ['expected_records', 'verified_records'], true)) {
                $issuecount += $value;
            }
        }

        $evidence = [
            'import' => $safeimport,
            'chain' => $safechain,
            'chain_verification' => [
                'status' => (int) ($counts['lineage_chain_errors'] ?? 0) === 0 ? 'match' : 'drift',
                'error_count' => max(0, (int) ($counts['lineage_chain_errors'] ?? 0)),
                'member_count' => count($safechain),
                'leaf_manifest_id' => $safechain[count($safechain) - 1]['manifest_id'],
            ],
            'source_artifact_verification' => [
                'verified' => (bool) ($sourceartifact['verified'] ?? false),
                'sha256' => self::safe_sha256((string) ($sourceartifact['sha256'] ?? '')),
                'verified_at' => max(0, (int) ($sourceartifact['verified_at'] ?? 0)),
            ],
            'verification' => [
                'status' => (string) $verification['status'],
                'exit_code' => (int) ($verification['exit_code'] ?? 2),
                'clean' => (bool) ($verification['clean'] ?? false),
                'counts' => $safeverificationcounts,
            ],
            'records' => $records,
            'counts' => [
                'record_outcomes' => count($records),
                'verified_current_records' => $verified,
                'superseded_records' => $superseded,
                'unresolved_records' => $unresolved,
                'conflict_records' => $conflicts,
                'verification_issue_count' => $issuecount,
            ],
        ];
        $payload = [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'evidence' => $evidence,
        ];
        $bundle = $payload + ['integrity' => [
            'algorithm' => 'sha256',
            'sha256' => hash('sha256', self::canonical_json($payload)),
        ]];
        $validation = self::validate_shape($bundle);
        if ($validation) {
            throw new \coding_exception('The generated evidence bundle failed its own format validation.');
        }
        return $bundle;
    }

    /** Encode a bundle using stable key order and deterministic formatting. */
    public static function encode(array $bundle): string
    {
        $errors = self::validate_shape($bundle);
        if ($errors) {
            throw new \invalid_parameter_exception('Cannot encode an invalid lineage evidence bundle.');
        }
        $payload = $bundle;
        unset($payload['integrity']);
        $expected = hash('sha256', self::canonical_json($payload));
        if (!hash_equals($expected, (string) $bundle['integrity']['sha256'])) {
            throw new \invalid_parameter_exception('Cannot encode a lineage evidence bundle with a stale checksum.');
        }
        $json = json_encode(
            self::canonicalise($bundle),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false || strlen($json) > self::MAX_BYTES) {
            throw new \invalid_parameter_exception('The evidence bundle could not be encoded within the supported size limit.');
        }
        return $json . "\n";
    }

    /** Validate the strict bundle schema, embedded checksum, and optional external digest. */
    public static function validate(string $json, ?string $expecteddigest = null): array
    {
        $errors = [];
        $bundle = null;
        $actualdigest = '';
        $digestvalid = false;
        if (strlen($json) > self::MAX_BYTES) {
            $errors[] = 'bundle_too_large';
        } else {
            $bundle = json_decode($json, true);
            if (!is_array($bundle) || json_last_error() !== JSON_ERROR_NONE) {
                $errors[] = 'malformed_json';
                $bundle = null;
            } else {
                $errors = self::validate_shape($bundle);
                if (!$errors) {
                    $payload = $bundle;
                    unset($payload['integrity']);
                    $actualdigest = hash('sha256', self::canonical_json($payload));
                    $digestvalid = hash_equals((string) $bundle['integrity']['sha256'], $actualdigest);
                    if (!$digestvalid) {
                        $errors[] = 'embedded_digest_mismatch';
                    }
                }
            }
        }

        $externalmatches = null;
        if ($expecteddigest !== null) {
            $expecteddigest = strtolower(trim($expecteddigest));
            if (!preg_match('/^[a-f0-9]{64}$/', $expecteddigest)) {
                $errors[] = 'expected_digest_invalid';
                $externalmatches = false;
            } else if ($actualdigest !== '') {
                $externalmatches = hash_equals($expecteddigest, $actualdigest);
                if (!$externalmatches) {
                    $errors[] = 'external_digest_mismatch';
                }
            } else {
                $externalmatches = false;
            }
        }

        return [
            'valid' => $errors === [],
            'format_valid' => $bundle !== null && !array_intersect($errors, [
                'malformed_json', 'bundle_too_large', 'root_shape_invalid', 'evidence_shape_invalid',
                'import_shape_invalid', 'chain_shape_invalid', 'verification_shape_invalid',
                'source_artifact_shape_invalid', 'record_shape_invalid', 'counts_shape_invalid',
                'bundle_semantics_invalid',
            ]),
            'digest_valid' => $digestvalid,
            'external_digest_supplied' => $expecteddigest !== null,
            'external_digest_matches' => $externalmatches,
            'bundle_sha256' => $actualdigest,
            'issue_codes' => array_values(array_unique($errors)),
            'interpretation' => self::integrity_interpretation(),
            'bundle' => $bundle,
        ];
    }

    /** Compare a validated bundle with a fresh current-target verifier result. */
    public static function compare_current(array $bundle, array $currentverification): array
    {
        // This public method accepts arrays from callers other than the CLI too.
        // Refuse to compare a malformed or tampered artifact as though it were trusted input.
        self::encode($bundle);
        $current = self::create($currentverification);
        $storedhash = strtolower((string) ($bundle['integrity']['sha256'] ?? ''));
        $currenthash = strtolower((string) ($current['integrity']['sha256'] ?? ''));
        return [
            'matches' => preg_match('/^[a-f0-9]{64}$/', $storedhash) === 1
                && hash_equals($storedhash, $currenthash),
            'bundle_sha256' => $storedhash,
            'current_sha256' => $currenthash,
            'current_status' => (string) ($currentverification['status'] ?? 'invalid'),
            'current_exit_code' => (int) ($currentverification['exit_code'] ?? 2),
        ];
    }

    /** Create a new file without overwriting a prior evidence artifact. */
    public static function write_new_file(string $path, array $bundle): string
    {
        $path = trim($path);
        if ($path === '' || is_dir($path)) {
            throw new \invalid_parameter_exception('A new evidence output file path is required.');
        }
        $json = self::encode($bundle);
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new \RuntimeException('The evidence output file could not be created; existing files are never overwritten.');
        }
        $written = 0;
        $length = strlen($json);
        try {
            while ($written < $length) {
                $chunk = fwrite($handle, substr($json, $written));
                if ($chunk === false || $chunk === 0) {
                    throw new \RuntimeException('The evidence output file could not be written completely.');
                }
                $written += $chunk;
            }
            if (!fflush($handle)) {
                throw new \RuntimeException('The evidence output file could not be flushed.');
            }
        } catch (\Throwable $exception) {
            fclose($handle);
            @unlink($path);
            throw $exception;
        }
        fclose($handle);
        return (string) $bundle['integrity']['sha256'];
    }

    /** Return a bounded, privacy-safe interpretation of this unsigned checksum. */
    private static function integrity_interpretation(): string
    {
        return 'The unsigned SHA-256 checksum detects changes only when the expected digest is retained separately. '
            . 'It does not authenticate the source, owner, reviewer, approval, or operator.';
    }

    /** Return strict format issues without echoing untrusted bundle values. */
    private static function validate_shape(array $bundle): array
    {
        if (!self::has_exact_keys($bundle, ['format', 'format_version', 'evidence', 'integrity'])
                || ($bundle['format'] ?? null) !== self::FORMAT
                || ($bundle['format_version'] ?? null) !== self::FORMAT_VERSION
                || !is_array($bundle['evidence'] ?? null)
                || !is_array($bundle['integrity'] ?? null)
                || !self::has_exact_keys($bundle['integrity'], ['algorithm', 'sha256'])
                || ($bundle['integrity']['algorithm'] ?? null) !== 'sha256'
                || !self::valid_sha($bundle['integrity']['sha256'] ?? null)) {
            return ['root_shape_invalid'];
        }
        $evidence = $bundle['evidence'];
        if (!self::has_exact_keys($evidence, [
                'import', 'chain', 'chain_verification', 'source_artifact_verification', 'verification', 'records', 'counts',
            ]) || !is_array($evidence['import'] ?? null) || !is_array($evidence['chain'] ?? null)
                || !array_is_list($evidence['chain']) || !$evidence['chain']
                || !is_array($evidence['chain_verification'] ?? null)
                || !is_array($evidence['source_artifact_verification'] ?? null)
                || !is_array($evidence['verification'] ?? null) || !is_array($evidence['records'] ?? null)
                || !array_is_list($evidence['records']) || !is_array($evidence['counts'] ?? null)) {
            return ['evidence_shape_invalid'];
        }

        $importkeys = [
            'manifest_id', 'manifest_sha256', 'parent_manifest_id', 'parent_manifest_sha256',
            'apply_plan_sha256', 'source_snapshot_sha256', 'source_plugin_version',
            'manifest_schema_version', 'course_id', 'applied_at', 'installed_plugin_version',
            'database_schema_version', 'reviewed_at',
        ];
        $import = $evidence['import'];
        if (!self::has_exact_keys($import, $importkeys)
                || !self::valid_identity($import['manifest_id'] ?? null)
                || !self::valid_optional_sha($import['manifest_sha256'] ?? null)
                || !self::valid_optional_identity($import['parent_manifest_id'] ?? null)
                || !self::valid_optional_sha($import['parent_manifest_sha256'] ?? null)
                || !self::valid_optional_sha($import['apply_plan_sha256'] ?? null)
                || !self::valid_optional_sha($import['source_snapshot_sha256'] ?? null)
                || !self::valid_nonnegative_ints($import, [
                    'source_plugin_version', 'course_id', 'applied_at',
                    'installed_plugin_version', 'database_schema_version', 'reviewed_at',
                ])
                || !in_array($import['manifest_schema_version'] ?? null, [1, 2], true)) {
            return ['import_shape_invalid'];
        }

        $chainkeys = [
            'manifest_id', 'manifest_sha256', 'parent_manifest_id', 'parent_manifest_sha256',
            'entry_count', 'amendment', 'apply_plan_sha256', 'source_snapshot_sha256',
            'source_artifact_sha256', 'source_artifact_verified', 'source_artifact_verified_at',
            'source_plugin_version', 'manifest_schema_version', 'applied_at',
            'reviewed_at',
        ];
        $chainids = [];
        foreach ($evidence['chain'] as $member) {
            if (!is_array($member) || !self::has_exact_keys($member, $chainkeys)
                    || !self::valid_identity($member['manifest_id'] ?? null)
                    || isset($chainids[$member['manifest_id']])
                    || !self::valid_optional_sha($member['manifest_sha256'] ?? null)
                    || !self::valid_optional_identity($member['parent_manifest_id'] ?? null)
                    || !self::valid_optional_sha($member['parent_manifest_sha256'] ?? null)
                    || !self::valid_nonnegative_ints($member, [
                        'entry_count', 'source_artifact_verified_at', 'source_plugin_version', 'applied_at', 'reviewed_at',
                    ])
                    || !self::valid_optional_sha($member['apply_plan_sha256'] ?? null)
                    || !self::valid_optional_sha($member['source_snapshot_sha256'] ?? null)
                    || !self::valid_optional_sha($member['source_artifact_sha256'] ?? null)
                    || !is_bool($member['amendment'] ?? null)
                    || !is_bool($member['source_artifact_verified'] ?? null)
                    || !in_array($member['manifest_schema_version'] ?? null, [0, 1, 2], true)
                    || ($member['source_artifact_verified'] && (
                        $member['source_artifact_sha256'] === ''
                        || !hash_equals($member['source_snapshot_sha256'], $member['source_artifact_sha256'])
                        || $member['source_artifact_verified_at'] <= 0
                    ))) {
                return ['chain_shape_invalid'];
            }
            $chainids[$member['manifest_id']] = true;
        }
        if (!isset($chainids[$import['manifest_id']])) {
            return ['bundle_semantics_invalid'];
        }

        $chainverification = $evidence['chain_verification'];
        $leaf = end($evidence['chain']);
        if (!self::has_exact_keys($chainverification, ['status', 'error_count', 'member_count', 'leaf_manifest_id'])
                || !in_array($chainverification['status'] ?? null, ['match', 'drift'], true)
                || !self::valid_nonnegative_ints($chainverification, ['error_count', 'member_count'])
                || !self::valid_identity($chainverification['leaf_manifest_id'] ?? null)
                || $chainverification['member_count'] !== count($evidence['chain'])
                || $chainverification['leaf_manifest_id'] !== $leaf['manifest_id']
                || $chainverification['status'] !== ($chainverification['error_count'] === 0 ? 'match' : 'drift')) {
            return ['bundle_semantics_invalid'];
        }

        $artifact = $evidence['source_artifact_verification'];
        if (!self::has_exact_keys($artifact, ['verified', 'sha256', 'verified_at'])
                || !is_bool($artifact['verified'] ?? null)
                || !self::valid_optional_sha($artifact['sha256'] ?? null)
                || !self::valid_nonnegative_ints($artifact, ['verified_at'])
                || ($artifact['verified'] && ($artifact['sha256'] === ''
                    || !hash_equals($import['source_snapshot_sha256'], $artifact['sha256'])
                    || $artifact['verified_at'] <= 0))) {
            return ['source_artifact_shape_invalid'];
        }

        $verification = $evidence['verification'];
        if (!self::has_exact_keys($verification, ['status', 'exit_code', 'clean', 'counts'])
                || !in_array($verification['status'] ?? null, ['match', 'drift', 'incomplete'], true)
                || !in_array($verification['exit_code'] ?? null, [0, 1, 2], true)
                || !is_bool($verification['clean'] ?? null)
                || $verification['clean'] !== ($verification['status'] === 'match')
                || $verification['exit_code'] !== ($verification['status'] === 'match'
                    ? 0 : ($verification['status'] === 'drift' ? 1 : 2))
                || !self::valid_count_map($verification['counts'] ?? null)) {
            return ['verification_shape_invalid'];
        }

        $recordkeys = [
            'manifest_id', 'record_type', 'target_id', 'decision', 'delivery_ids',
            'entry_sha256', 'outcome', 'fields',
        ];
        $seenrecords = [];
        $lastrecord = null;
        $unresolved = 0;
        $verified = 0;
        $superseded = 0;
        $conflicts = 0;
        foreach ($evidence['records'] as $record) {
            if (!is_array($record) || !self::has_exact_keys($record, $recordkeys)
                    || !self::valid_identity($record['manifest_id'] ?? null)
                    || (!isset($chainids[$record['manifest_id']])
                        && preg_match('/^redacted-record-[0-9]{6,}$/', $record['manifest_id']) !== 1)
                    || !in_array($record['record_type'] ?? null, ['module', 'gradeitem', 'unknown'], true)
                    || !is_int($record['target_id'] ?? null) || $record['target_id'] < 0
                    || !is_array($record['delivery_ids'] ?? null) || !array_is_list($record['delivery_ids'])
                    || !self::valid_optional_sha($record['entry_sha256'] ?? null)
                    || !in_array($record['outcome'] ?? null, [
                        'target_matches_audit', 'superseded_by_amendment', 'target_drift', 'target_missing',
                        'unknown',
                    ], true)
                    || !is_array($record['fields'] ?? null) || !array_is_list($record['fields'])
                    || array_filter($record['fields'], static fn($field): bool =>
                        !is_string($field) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $field))) {
                return ['record_shape_invalid'];
            }
            $decisionlist = $record['record_type'] === 'module'
                ? ['current', 'historical', 'quarantine', 'archive', 'dispose']
                : ($record['record_type'] === 'gradeitem'
                    ? ['map', 'quarantine', 'archive', 'dispose'] : ['unknown']);
            if ($record['record_type'] === 'module') {
                $decisionlist[] = 'unknown';
            }
            if (!in_array($record['decision'], $decisionlist, true)) {
                return ['record_shape_invalid'];
            }
            foreach ($record['delivery_ids'] as $deliveryid) {
                if (!is_int($deliveryid) || $deliveryid <= 0) {
                    return ['record_shape_invalid'];
                }
            }
            $requiresone = $record['decision'] === 'current' || $record['decision'] === 'historical'
                || $record['decision'] === 'map';
            if (($requiresone && count($record['delivery_ids']) !== 1)
                    || (!$requiresone && $record['delivery_ids'] !== [])) {
                return ['record_shape_invalid'];
            }
            $key = $record['manifest_id'] . ':' . $record['record_type'] . ':' . $record['target_id'];
            $recordorder = [$record['manifest_id'], $record['record_type'], $record['target_id']];
            if (isset($seenrecords[$key]) || ($lastrecord !== null && $lastrecord > $recordorder)) {
                return ['bundle_semantics_invalid'];
            }
            $seenrecords[$key] = true;
            $lastrecord = $recordorder;
            if ($record['outcome'] === 'superseded_by_amendment') {
                $superseded++;
            } else {
                if ($record['decision'] === 'quarantine') {
                    $unresolved++;
                }
                if ($record['outcome'] === 'target_matches_audit') {
                    $verified++;
                } else if (in_array($record['outcome'], ['target_drift', 'target_missing'], true)) {
                    $conflicts++;
                }
            }
        }

        $countkeys = [
            'record_outcomes', 'verified_current_records', 'superseded_records',
            'unresolved_records', 'conflict_records', 'verification_issue_count',
        ];
        $evidencecounts = $evidence['counts'];
        if (!self::has_exact_keys($evidencecounts, $countkeys)
                || !self::valid_nonnegative_ints($evidencecounts, $countkeys)
                || $evidencecounts['record_outcomes'] !== count($evidence['records'])
                || $evidencecounts['verified_current_records'] !== $verified
                || $evidencecounts['superseded_records'] !== $superseded
                || $evidencecounts['unresolved_records'] !== $unresolved
                || $evidencecounts['conflict_records'] !== $conflicts) {
            return ['counts_shape_invalid'];
        }
        $issuecount = 0;
        foreach ($verification['counts'] as $name => $value) {
            if (!in_array($name, ['expected_records', 'verified_records'], true)) {
                $issuecount += $value;
            }
        }
        if ($evidencecounts['verification_issue_count'] !== $issuecount) {
            return ['counts_shape_invalid'];
        }

        return [];
    }

    private static function valid_count_map($counts): bool
    {
        if (!is_array($counts) || array_is_list($counts)) {
            return false;
        }
        foreach ($counts as $name => $value) {
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name)
                    || !is_int($value) || $value < 0) {
                return false;
            }
        }
        return true;
    }

    private static function valid_nonnegative_ints(array $values, array $names): bool
    {
        foreach ($names as $name) {
            if (!is_int($values[$name] ?? null) || $values[$name] < 0) {
                return false;
            }
        }
        return true;
    }

    private static function has_exact_keys(array $values, array $expected): bool
    {
        $keys = array_keys($values);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        return $keys === $expected;
    }

    private static function valid_identity($value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', $value) === 1;
    }

    private static function valid_optional_identity($value): bool
    {
        return $value === '' || self::valid_identity($value);
    }

    private static function valid_sha($value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }

    /** Do not copy malformed persisted digest text into a portable artifact. */
    private static function safe_sha256(string $value): string
    {
        $value = strtolower($value);
        return self::valid_sha($value) ? $value : '';
    }

    private static function valid_optional_sha($value): bool
    {
        return $value === '' || self::valid_sha($value);
    }

    private static function canonical_json($value): string
    {
        $encoded = json_encode(self::canonicalise($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $encoded === false ? '' : $encoded;
    }

    private static function canonicalise($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = self::canonicalise($child);
        }
        return $value;
    }
}
