<?php

define('CLI_SCRIPT', true);

// The CLI argument parser is intentionally loaded before Moodle bootstrap so
// --check-evidence can validate a portable artifact without opening Moodle's DB.
require_once(__DIR__ . '/../../../lib/clilib.php');

use local_rtcsync\local\delivery_lineage_reconciliation;
use local_rtcsync\local\delivery_lineage_evidence_bundle;
use local_rtcsync\local\delivery_lineage_reconciliation_verifier;
use local_rtcsync\local\delivery_lineage_manifest_offline_validator;
use local_rtcsync\local\source_snapshot_artifact;

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'manifest' => '',
    'source-backup' => '',
    'export-evidence' => '',
    'check-evidence' => '',
    'check-manifest' => false,
    'parent-manifest' => '',
    'verify-current' => false,
    'expected-evidence-sha256' => '',
    'apply' => false,
    'verify' => false,
    'manifest-id' => '',
    'plan-sha256' => '',
    'operator' => '',
    'approved-by' => '',
    'approved-at' => '',
    'approval-reference' => '',
    'source-snapshot-sha256' => '',
    'source-plugin-version' => '',
], ['h' => 'help']);

if ($unrecognised) {
    cli_error('Unknown option(s): ' . implode(', ', $unrecognised), $options['verify'] ? 2 : 1);
}

if ($options['help']) {
    cli_writeln("Validate, apply, or verify a reviewed RTC delivery-lineage manifest or amendment. Dry-run is the default.\n\n"
        . "Preview first without --apply. Review valid, conflict_count/conflicts, mapping_counts, disposition_counts,\n"
        . "quarantine_outcomes, and plan_sha256. Preview changes no Moodle records and returns no learner values.\n\n"
        . "  --manifest=<path>       UTF-8 JSON v1 manifest or reviewed v2 amendment to validate.\n"
        . "  --source-backup=<path>  Source snapshot artifact to hash against the manifest declaration.\n"
        . "  --apply                 Apply after a successful dry run.\n"
        . "  --plan-sha256=<hash>    Exact plan hash returned by dry-run; required for apply.\n"
        . "  --operator=<name>       Importing operator; required for apply.\n"
        . "  --approved-by=<name>    Repeat the reviewed manifest's approver; required for apply.\n"
        . "  --approved-at=<UTC>     Repeat its exact YYYY-MM-DDTHH:MM:SSZ approval time.\n"
        . "  --approval-reference=<id> Repeat its durable approval reference.\n"
        . "  --source-snapshot-sha256=<hash> Repeat the declared source snapshot hash.\n"
        . "  --source-plugin-version=<n> Repeat the source plugin version.\n"
        . "  --verify                Verify one applied import and its full amendment chain (read only).\n"
        . "  --manifest-id=<id>      Exact import identity; required with --verify.\n"
        . "  --export-evidence=<path> Export a redacted, versioned bundle with --verify; never overwrites.\n"
        . "  --check-evidence=<path> Check bundle format/checksum before Moodle bootstrap.\n"
        . "  --check-manifest        Validate manifest format before Moodle bootstrap. Requires --manifest.\n"
        . "  --parent-manifest=<path> Reviewed parent manifest required to check v2 quarantine coverage offline.\n"
        . "  --verify-current        With --check-evidence, compare against current target verification.\n"
        . "  --expected-evidence-sha256=<hash> Optional separately retained checksum for bundle checking.\n"
        . "  -h, --help              Show this help.\n\n"
        . "Run under an OS account authorized to access this Moodle installation. Moodle records an actor only when\n"
        . "a CLI auth plugin maps one. The --operator and approval fields are declarations, not verified identities.\n"
        . "Apply options are rejected during dry-run.\n"
        . "Apply only after reviewing a successful preview; the exact plan_sha256 is required.\n"
        . "Repeated provenance must match the manifest; these declarations are recorded, not authenticated.\n"
        . "Dry-run may inspect the source backup; apply requires it and rechecks it under the course lock.\n"
        . "Manifest and evidence JSON files are limited to 8 MiB.\n"
        . "Only its SHA-256 digest is stored. A digest match does not authenticate the backup, source, reviewer, or approval.\n"
        . "The evidence checksum is unsigned. Preserve its expected digest separately to detect file replacement.\n"
        . "Verification exit codes: 0 import audit and current chain head match, 1 chain/target drift, 2 invalid or incomplete verification."
    );
    exit(0);
}

$readmanifestfile = static function(string $path): string {
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        cli_error('A manifest file is unreadable or exceeds the supported size limit.', 2);
    }
    $contents = stream_get_contents($handle, 8388609);
    fclose($handle);
    if ($contents === false || strlen($contents) > 8388608) {
        cli_error('A manifest file is unreadable or exceeds the supported size limit.', 2);
    }
    return $contents;
};

$parentmanifestpath = trim((string) $options['parent-manifest']);
if (!$options['check-manifest'] && $parentmanifestpath !== '') {
    cli_error('--parent-manifest requires --check-manifest.', 2);
}

if ($options['check-manifest']) {
    if ($options['apply'] || $options['verify'] || trim((string) $options['export-evidence']) !== ''
            || trim((string) $options['check-evidence']) !== '' || $options['verify-current']
            || trim((string) $options['expected-evidence-sha256']) !== ''
            || trim((string) $options['manifest-id']) !== '') {
        cli_error('--check-manifest cannot be combined with apply, verify, or evidence options.', 2);
    }
    foreach ([
        'plan-sha256', 'operator', 'approved-by', 'approved-at', 'approval-reference',
        'source-snapshot-sha256', 'source-plugin-version',
    ] as $field) {
        if ((string) $options[$field] !== '') {
            cli_error("--$field is not used with --check-manifest.", 2);
        }
    }
    $manifestpath = trim((string) $options['manifest']);
    if ($manifestpath === '' || !is_file($manifestpath)) {
        cli_error('--check-manifest requires a readable --manifest JSON file.', 2);
    }
    $manifestjson = $readmanifestfile($manifestpath);
    $parentjson = null;
    if ($parentmanifestpath !== '') {
        if (!is_file($parentmanifestpath)) {
            cli_error('The supplied parent manifest is unreadable or exceeds the supported size limit.', 2);
        }
        $parentjson = $readmanifestfile($parentmanifestpath);
    }

    // This mode deliberately exits before Moodle config/bootstrap and database initialization.
    define('MOODLE_INTERNAL', true);
    require_once(__DIR__ . '/../classes/local/delivery_lineage_manifest_offline_validator.php');
    require_once(__DIR__ . '/../classes/local/source_snapshot_artifact.php');
    $offline = delivery_lineage_manifest_offline_validator::validate($manifestjson, $parentjson);
    $sourcebackuppath = trim((string) $options['source-backup']);
    if ($sourcebackuppath !== '') {
        $decodedmanifest = json_decode($manifestjson);
        $sourcehash = $decodedmanifest instanceof stdClass
            && is_string($decodedmanifest->source_snapshot_sha256 ?? null)
            ? $decodedmanifest->source_snapshot_sha256 : '';
        $artifact = source_snapshot_artifact::verify($sourcebackuppath, $sourcehash);
        $offline['source_backup_check'] = [
            'checked' => (bool) $artifact['checked'],
            'verified' => (bool) $artifact['verified'],
            'sha256' => (string) $artifact['sha256'],
            'result_code' => (string) $artifact['reason'],
        ];
        if (!$artifact['verified']) {
            $offline['valid'] = false;
            $offline['source_backup_matches_manifest'] = false;
        } else {
            $offline['source_backup_matches_manifest'] = true;
        }
    }
    cli_writeln(json_encode($offline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    exit($offline['valid'] ? 0 : 2);
}

$checkevidencepath = trim((string) $options['check-evidence']);
if ($checkevidencepath !== '' && !$options['verify-current']) {
    if ($options['apply'] || $options['verify'] || trim((string) $options['export-evidence']) !== ''
            || trim((string) $options['manifest-id']) !== '' || trim((string) $options['source-backup']) !== '') {
        cli_error('--check-evidence cannot be combined with apply, verify, export, manifest-id, or source-backup.', 2);
    }
    if (trim((string) $options['manifest']) !== '') {
        cli_error('--manifest is used with --check-evidence only when --verify-current is set.', 2);
    }
    foreach ([
        'plan-sha256', 'operator', 'approved-by', 'approved-at', 'approval-reference',
        'source-snapshot-sha256', 'source-plugin-version',
    ] as $field) {
        if ((string) $options[$field] !== '') {
            cli_error("--$field is not used with --check-evidence.", 2);
        }
    }
    if (!is_file($checkevidencepath)) {
        cli_error('A readable evidence bundle file is required.', 2);
    }
    $bundlehandle = @fopen($checkevidencepath, 'rb');
    if ($bundlehandle === false) {
        cli_error('Unable to read the evidence bundle.', 2);
    }
    $bundlejson = stream_get_contents($bundlehandle, 8388609);
    fclose($bundlehandle);
    if ($bundlejson === false || strlen($bundlejson) > 8388608) {
        cli_error('The evidence bundle is unreadable or exceeds the supported size limit.', 2);
    }
    // The validator's file-only path uses PHP primitives only; no Moodle DB is needed.
    define('MOODLE_INTERNAL', true);
    require_once(__DIR__ . '/../classes/local/delivery_lineage_evidence_bundle.php');
    $expectedhash = trim((string) $options['expected-evidence-sha256']);
    $checkedbundle = delivery_lineage_evidence_bundle::validate(
        $bundlejson,
        $expectedhash === '' ? null : $expectedhash
    );
    cli_writeln(json_encode([
        'valid' => $checkedbundle['valid'],
        'format_valid' => $checkedbundle['format_valid'],
        'digest_valid' => $checkedbundle['digest_valid'],
        'bundle_sha256' => $checkedbundle['bundle_sha256'],
        'external_digest_supplied' => $checkedbundle['external_digest_supplied'],
        'external_digest_matches' => $checkedbundle['external_digest_matches'],
        'current_target_check' => ['requested' => false],
        'issue_codes' => $checkedbundle['issue_codes'],
        'interpretation' => $checkedbundle['interpretation'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    exit($checkedbundle['valid'] ? 0 : 2);
}

require(__DIR__ . '/../../../config.php');

$checkevidencepath = trim((string) $options['check-evidence']);
if ($checkevidencepath !== '') {
    if ($options['apply'] || $options['verify'] || trim((string) $options['export-evidence']) !== ''
            || trim((string) $options['manifest-id']) !== '' || trim((string) $options['source-backup']) !== '') {
        cli_error('--check-evidence cannot be combined with apply, verify, export, manifest-id, or source-backup.', 2);
    }
    $manifestpath = trim((string) $options['manifest']);
    if ($options['verify-current'] && $manifestpath === '') {
        cli_error('--verify-current requires the exact reviewed --manifest file.', 2);
    }
    if (!$options['verify-current'] && $manifestpath !== '') {
        cli_error('--manifest is used with --check-evidence only when --verify-current is set.', 2);
    }
    foreach ([
        'plan-sha256', 'operator', 'approved-by', 'approved-at', 'approval-reference',
        'source-snapshot-sha256', 'source-plugin-version',
    ] as $field) {
        if ((string) $options[$field] !== '') {
            cli_error("--$field is not used with --check-evidence.", 2);
        }
    }
    if (!is_file($checkevidencepath)) {
        cli_error('A readable evidence bundle file is required.', 2);
    }
    $bundlehandle = @fopen($checkevidencepath, 'rb');
    if ($bundlehandle === false) {
        cli_error('Unable to read the evidence bundle.', 2);
    }
    $bundlejson = stream_get_contents($bundlehandle, 8388609);
    fclose($bundlehandle);
    if ($bundlejson === false || strlen($bundlejson) > 8388608) {
        cli_error('The evidence bundle is unreadable or exceeds the supported size limit.', 2);
    }
    $expectedhash = trim((string) $options['expected-evidence-sha256']);
    $checkedbundle = delivery_lineage_evidence_bundle::validate(
        $bundlejson,
        $expectedhash === '' ? null : $expectedhash
    );
    $output = [
        'valid' => $checkedbundle['valid'],
        'format_valid' => $checkedbundle['format_valid'],
        'digest_valid' => $checkedbundle['digest_valid'],
        'bundle_sha256' => $checkedbundle['bundle_sha256'],
        'external_digest_supplied' => $checkedbundle['external_digest_supplied'],
        'external_digest_matches' => $checkedbundle['external_digest_matches'],
        'current_target_check' => ['requested' => (bool) $options['verify-current']],
        'issue_codes' => $checkedbundle['issue_codes'],
        'interpretation' => $checkedbundle['interpretation'],
    ];
    if (!$checkedbundle['valid']) {
        cli_writeln(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        exit(2);
    }

    if ($options['verify-current']) {
        if (!is_file($manifestpath)) {
            cli_error('A readable reviewed manifest file is required for current verification.', 2);
        }
        $manifestjson = $readmanifestfile($manifestpath);
        $manifestid = (string) $checkedbundle['bundle']['evidence']['import']['manifest_id'];
        $currentverification = delivery_lineage_reconciliation_verifier::verify($manifestid, $manifestjson);
        if ($currentverification['status'] === 'invalid') {
            $output['current_target_check'] += [
                'matches_bundle' => false,
                'status' => 'invalid',
                'exit_code' => 2,
            ];
            $output['valid'] = false;
            $output['issue_codes'][] = 'current_verification_invalid';
            cli_writeln(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            exit(2);
        }
        $comparison = delivery_lineage_evidence_bundle::compare_current(
            $checkedbundle['bundle'],
            $currentverification
        );
        $output['current_target_check'] += [
            'matches_bundle' => $comparison['matches'],
            'status' => $comparison['current_status'],
            'exit_code' => $comparison['current_exit_code'],
            'current_bundle_sha256' => $comparison['current_sha256'],
        ];
        cli_writeln(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        exit(!$comparison['matches'] ? max(1, $comparison['current_exit_code']) : $comparison['current_exit_code']);
    }

    cli_writeln(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    exit(0);
}

if ($options['verify-current']) {
    cli_error('--verify-current requires --check-evidence.', 2);
}
if ((string) $options['expected-evidence-sha256'] !== '') {
    cli_error('--expected-evidence-sha256 requires --check-evidence.', 2);
}
if (trim((string) $options['export-evidence']) !== '' && !$options['verify']) {
    cli_error('--export-evidence requires --verify.', 2);
}

$manifestpath = trim((string) $options['manifest']);
if ($manifestpath === '' || !is_file($manifestpath)) {
    cli_error('A readable --manifest JSON file is required.', $options['verify'] ? 2 : 1);
}
$manifestjson = $readmanifestfile($manifestpath);

if ($options['apply'] && $options['verify']) {
    cli_error('--apply and --verify are mutually exclusive.', 2);
}
if ($options['verify'] && trim((string) $options['manifest-id']) === '') {
    cli_error('--manifest-id is required with --verify.', 2);
}
if ($options['verify'] && (string) $options['source-backup'] !== '') {
    cli_error('--source-backup is not used with --verify.', 2);
}
if (!$options['verify'] && (string) $options['manifest-id'] !== '') {
    cli_error('--manifest-id requires --verify.', 2);
}

$applyfields = [
    'plan-sha256',
    'operator',
    'approved-by',
    'approved-at',
    'approval-reference',
    'source-snapshot-sha256',
    'source-plugin-version',
];
if (!$options['apply']) {
    foreach ($applyfields as $field) {
        if ((string) $options[$field] !== '') {
            cli_error("--$field requires --apply. Dry-run is the default and does not accept apply confirmations.",
                $options['verify'] ? 2 : 1);
        }
    }
}

$provenance = [];
if ($options['apply']) {
    if (trim((string) $options['source-backup']) === '') {
        cli_error('--source-backup is required with --apply.');
    }
    foreach ($applyfields as $field) {
        if ((string) $options[$field] === '') {
            cli_error("--$field is required with --apply.");
        }
    }
    $sourceversion = filter_var((string) $options['source-plugin-version'], FILTER_VALIDATE_INT);
    if ($sourceversion === false || $sourceversion <= 0) {
        cli_error('--source-plugin-version must be a positive integer.');
    }
    $provenance = [
        'approved_by' => (string) $options['approved-by'],
        'approved_at' => (string) $options['approved-at'],
        'approval_reference' => (string) $options['approval-reference'],
        'source_snapshot_sha256' => (string) $options['source-snapshot-sha256'],
        'source_plugin_version' => (int) $sourceversion,
    ];
}

try {
    if ($options['apply']) {
        $result = delivery_lineage_reconciliation::apply(
            $manifestjson,
            (string) $options['plan-sha256'],
            (string) $options['operator'],
            $provenance,
            (string) $options['source-backup']
        );
    } else if ($options['verify']) {
        $result = delivery_lineage_reconciliation_verifier::verify(
            (string) $options['manifest-id'],
            $manifestjson
        );
    } else {
        $result = delivery_lineage_reconciliation::preview(
            $manifestjson,
            trim((string) $options['source-backup']) === '' ? null : (string) $options['source-backup']
        );
    }
} catch (\Throwable $exception) {
    if ($options['verify']) {
        cli_writeln(json_encode([
            'status' => 'invalid',
            'clean' => false,
            'exit_code' => 2,
            'manifest_id' => preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', (string) $options['manifest-id'])
                ? (string) $options['manifest-id'] : '',
            'issues' => ['validation' => ['verification_failed']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        exit(2);
    }
    cli_writeln(json_encode([
        'valid' => false,
        'applied' => false,
        'error' => $exception->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    exit(2);
}

if ($options['verify'] && trim((string) $options['export-evidence']) !== '') {
    if (($result['status'] ?? 'invalid') === 'invalid') {
        cli_writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        exit(2);
    }
    try {
        $bundle = delivery_lineage_evidence_bundle::create($result);
        $bundlehash = delivery_lineage_evidence_bundle::write_new_file(
            (string) $options['export-evidence'],
            $bundle
        );
    } catch (\Throwable $exception) {
        cli_writeln(json_encode([
            'exported' => false,
            'status' => $result['status'],
            'verification_exit_code' => (int) ($result['exit_code'] ?? 2),
            'issue' => 'evidence_export_failed',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        exit(2);
    }
    cli_writeln(json_encode([
        'exported' => true,
        'manifest_id' => $result['manifest_id'],
        'status' => $result['status'],
        'chain_members' => count($bundle['evidence']['chain']),
        'record_outcomes' => count($bundle['evidence']['records']),
        'evidence_sha256' => $bundlehash,
        'interpretation' => 'Unsigned SHA-256 checksum; retain this expected digest separately. It does not '
            . 'authenticate the source, owner, reviewer, approval, or operator.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    exit((int) ($result['exit_code'] ?? 2));
}

cli_writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
if ($options['verify']) {
    exit((int) ($result['exit_code'] ?? 2));
}
if (($result['valid'] ?? true) === false) {
    exit(2);
}
exit(0);
