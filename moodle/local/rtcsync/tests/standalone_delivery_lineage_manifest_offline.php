<?php

// Standalone regression checks for offline authoring. This file intentionally needs no Moodle bootstrap or database.
define('MOODLE_INTERNAL', true);
require_once(__DIR__ . '/../classes/local/delivery_lineage_manifest_offline_validator.php');

use local_rtcsync\local\delivery_lineage_manifest_offline_validator;

function offline_manifest_check(bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $label);
    }
}

function offline_manifest_encode(array $manifest): string {
    $json = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('Unable to encode regression manifest.');
    }
    return $json;
}

function offline_module_identity(int $cmid, int $instanceid, string $courseid = 'rtc-subject:991001'): array {
    return [
        'course_idnumber' => $courseid,
        'cmid' => $cmid,
        'modname' => 'quiz',
        'instanceid' => $instanceid,
        'idnumber' => '',
    ];
}

function offline_grade_identity(int $itemid, string $courseid = 'rtc-subject:991001'): array {
    return [
        'course_idnumber' => $courseid,
        'itemid' => $itemid,
        'itemtype' => 'manual',
        'itemmodule' => '',
        'iteminstance' => 0,
        'idnumber' => '',
    ];
}

function offline_manifest_sample(): array {
    return [
        'schema_version' => 1,
        'manifest_id' => 'offline-validation-sample',
        'course_idnumber' => 'rtc-subject:991001',
        'source_snapshot_sha256' => str_repeat('a', 64),
        'source_plugin_version' => 2026102700,
        'review' => [
            'approved_by' => 'Data Owner Placeholder',
            'approved_at' => '2026-10-28T00:00:00Z',
            'approval_reference' => 'REVIEW-RECORD-PLACEHOLDER',
        ],
        'entries' => [[
            'source' => offline_module_identity(51, 61),
            'target' => offline_module_identity(101, 201),
            'decision' => 'quarantine',
            'delivery_ids' => [],
            'reason' => 'Reviewed source evidence is required; this placeholder makes no lineage claim.',
        ]],
        'grade_items' => [[
            'source' => offline_grade_identity(71),
            'target' => offline_grade_identity(171),
            'decision' => 'map',
            'delivery_id' => 77,
            'reason' => 'Explicitly approved delivery evidence placeholder.',
        ]],
    ];
}

function offline_manifest_has_code(array $result, string $code): bool {
    foreach ($result['diagnostics'] as $diagnostic) {
        if ($diagnostic['code'] === $code) {
            return true;
        }
    }
    return false;
}

$validjson = offline_manifest_encode(offline_manifest_sample());
$valid = delivery_lineage_manifest_offline_validator::validate($validjson);
offline_manifest_check($valid['valid'], 'valid v1 manifest is accepted structurally');
offline_manifest_check($valid['checks_not_performed']['complete_target_inventory_coverage'] === 'requires_moodle_dry_run',
    'offline validity does not claim complete live target coverage');

$parent = offline_manifest_sample();
$parent['manifest_id'] = 'offline-amendment-parent';
$parent['entries'][0]['decision'] = 'quarantine';
$parent['entries'][0]['delivery_ids'] = [];
$parent['grade_items'][0]['decision'] = 'quarantine';
$parent['grade_items'][0]['delivery_id'] = 0;
$parentjson = offline_manifest_encode($parent);
$child = $parent;
$child['schema_version'] = 2;
$child['manifest_id'] = 'offline-amendment-child';
$child['amendment'] = [
    'parent_manifest_id' => $parent['manifest_id'],
    'parent_manifest_sha256' => hash('sha256', $parentjson),
];
$child['review']['approval_reference'] = 'AMENDMENT-REVIEW-PLACEHOLDER';
$child['entries'][0]['decision'] = 'historical';
$child['entries'][0]['delivery_ids'] = [77];
$child['grade_items'][0]['decision'] = 'map';
$child['grade_items'][0]['delivery_id'] = 77;
$childjson = offline_manifest_encode($child);
$validchild = delivery_lineage_manifest_offline_validator::validate($childjson, $parentjson);
offline_manifest_check($validchild['valid'] && $validchild['amendment_parent_file_checked'],
    'valid v2 amendment and exact direct parent quarantine coverage are accepted');

$wrongversion = offline_manifest_sample();
$wrongversion['schema_version'] = 99;
$wrongversionresult = delivery_lineage_manifest_offline_validator::validate(offline_manifest_encode($wrongversion));
offline_manifest_check(!$wrongversionresult['valid'] && offline_manifest_has_code($wrongversionresult, 'unsupported_schema_version'),
    'wrong manifest version is rejected');

$malformed = offline_manifest_sample();
$malformed['source_snapshot_sha256'] = 'NOT-A-DIGEST';
$malformed['review']['approved_at'] = '2026-02-30T25:61:00Z';
$malformed['entries'][0]['target']['cmid'] = '101';
unset($malformed['entries'][0]['decision']);
$malformedresult = delivery_lineage_manifest_offline_validator::validate(offline_manifest_encode($malformed));
offline_manifest_check(!$malformedresult['valid']
        && offline_manifest_has_code($malformedresult, 'invalid_source_snapshot_digest')
        && offline_manifest_has_code($malformedresult, 'invalid_review_approved_at')
        && offline_manifest_has_code($malformedresult, 'invalid_module_target_identity')
        && offline_manifest_has_code($malformedresult, 'module_disposition_required'),
    'malformed digest, timestamp, target reference, and missing disposition are rejected');

$duplicate = offline_manifest_sample();
$duplicate['entries'][] = $duplicate['entries'][0];
$duplicateresult = delivery_lineage_manifest_offline_validator::validate(offline_manifest_encode($duplicate));
offline_manifest_check(offline_manifest_has_code($duplicateresult, 'duplicate_source_identity')
        && offline_manifest_has_code($duplicateresult, 'duplicate_target_identity'),
    'duplicate source and target identities are diagnosed');

$ambiguous = offline_manifest_sample();
$ambiguous['entries'][] = $ambiguous['entries'][0];
$ambiguous['entries'][1]['target'] = offline_module_identity(102, 202);
$ambiguousresult = delivery_lineage_manifest_offline_validator::validate(offline_manifest_encode($ambiguous));
offline_manifest_check(offline_manifest_has_code($ambiguousresult, 'ambiguous_source_one_to_many_mapping'),
    'one source identity cannot map to multiple targets');

$incomplete = offline_manifest_sample();
$incomplete['entries'] = [];
$incompleteresult = delivery_lineage_manifest_offline_validator::validate(offline_manifest_encode($incomplete));
offline_manifest_check(offline_manifest_has_code($incompleteresult, 'module_entry_required'),
    'a root manifest without module dispositions is rejected');
$incompletechild = $child;
$incompletechild['entries'] = [];
$incompletechild['grade_items'] = [];
$incompletechildjson = offline_manifest_encode($incompletechild);
$coverage = delivery_lineage_manifest_offline_validator::validate($incompletechildjson, $parentjson);
offline_manifest_check(offline_manifest_has_code($coverage, 'parent_quarantine_record_omitted'),
    'an amendment that omits parent quarantine records is rejected');

$redacted = offline_manifest_sample();
$redacted['review']['approved_by'] = 'LEARNER_NAME_PRIVATE_SENTINEL';
$redacted['entries'][0]['source']['idnumber'] = 'LEARNER_ID_PRIVATE_SENTINEL';
$redacted['entries'][0]['reason'] = 'ANSWER_PRIVATE_SENTINEL GRADE_98765 BACKUP_PATH_C:\\private\\snapshot.sql';
unset($redacted['entries'][0]['decision']);
$redacted['untrusted_extra'] = 'GRADE_PRIVATE_SENTINEL';
$redactedjson = offline_manifest_encode($redacted);
$redactedresult = delivery_lineage_manifest_offline_validator::validate($redactedjson);
$redactedoutput = json_encode($redactedresult, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
foreach ([
    'LEARNER_NAME_PRIVATE_SENTINEL', 'LEARNER_ID_PRIVATE_SENTINEL', 'ANSWER_PRIVATE_SENTINEL',
    'GRADE_98765', 'GRADE_PRIVATE_SENTINEL', 'BACKUP_PATH_C:', 'snapshot.sql',
] as $privatevalue) {
    offline_manifest_check(!str_contains($redactedoutput, $privatevalue), 'diagnostic redacts untrusted manifest value');
}
offline_manifest_check((bool) array_filter($redactedresult['diagnostics'], static fn(array $item): bool => $item['target_id'] === 101),
    'diagnostics retain only the safe numeric target ID');
$repeatresult = delivery_lineage_manifest_offline_validator::validate($redactedjson);
offline_manifest_check(
    json_encode($redactedresult, JSON_UNESCAPED_SLASHES) === json_encode($repeatresult, JSON_UNESCAPED_SLASHES),
    'diagnostics are deterministic for identical bytes'
);

$templatev1 = json_decode(file_get_contents(__DIR__ . '/../../../../docs/rtc-delivery-lineage-manifest-template-v1.json'));
$templatev2 = json_decode(file_get_contents(__DIR__ . '/../../../../docs/rtc-delivery-lineage-manifest-template-v2-amendment.json'));
offline_manifest_check($templatev1 instanceof stdClass && $templatev2 instanceof stdClass,
    'versioned authoring templates are valid JSON objects');

// Execute the actual pre-bootstrap CLI branch with a shutdown probe that observes loaded files and DB globals.
$pluginroot = dirname(__DIR__);
$clifile = $pluginroot . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . 'reconcile_delivery_lineage.php';
$tempfiles = [];
try {
    $backupfile = tempnam(sys_get_temp_dir(), 'rtc-lineage-source-');
    $manifestfile = tempnam(sys_get_temp_dir(), 'rtc-lineage-manifest-');
    $probeoutput = tempnam(sys_get_temp_dir(), 'rtc-lineage-probe-output-');
    $probefile = tempnam(sys_get_temp_dir(), 'rtc-lineage-probe-');
    $tempfiles = [$backupfile, $manifestfile, $probeoutput, $probefile];
    $backupcontents = 'offline source snapshot fixture';
    file_put_contents($backupfile, $backupcontents);
    $climanifest = offline_manifest_sample();
    $climanifest['source_snapshot_sha256'] = hash('sha256', $backupcontents);
    file_put_contents($manifestfile, offline_manifest_encode($climanifest));
    $probeprogram = "<?php\n"
        . '$probeoutput = ' . var_export($probeoutput, true) . ";\n"
        . "register_shutdown_function(static function() use (\$probeoutput): void {\n"
        . "    \$configloaded = false;\n"
        . "    foreach (get_included_files() as \$included) {\n"
        . "        if (basename(\$included) === 'config.php') { \$configloaded = true; }\n"
        . "    }\n"
        . "    file_put_contents(\$probeoutput, json_encode([\n"
        . "        'config_loaded' => \$configloaded,\n"
        . "        'database_global_initialized' => array_key_exists('DB', \$GLOBALS),\n"
        . "    ]));\n"
        . "});\n";
    file_put_contents($probefile, $probeprogram);
    $command = [
        PHP_BINARY,
        '-d',
        'auto_prepend_file=' . $probefile,
        $clifile,
        '--check-manifest',
        '--manifest=' . $manifestfile,
        '--source-backup=' . $backupfile,
    ];
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to run the offline CLI regression.');
    }
    fclose($pipes[0]);
    $clioutput = stream_get_contents($pipes[1]);
    $clierrors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $cliexit = proc_close($process);
    $clijson = json_decode((string) $clioutput, true);
    offline_manifest_check($cliexit === 0 && is_array($clijson) && ($clijson['valid'] ?? false) === true,
        'offline check-manifest CLI accepts a valid manifest without Moodle bootstrap: '
            . $cliexit . ' ' . (string) $clioutput . ' ' . (string) $clierrors);
    offline_manifest_check(($clijson['source_backup_check']['verified'] ?? false) === true,
        'offline CLI optionally verifies source snapshot bytes');
    offline_manifest_check(!str_contains((string) $clioutput, $manifestfile)
            && !str_contains((string) $clioutput, $backupfile)
            && trim((string) $clierrors) === '',
        'offline CLI output omits local manifest and backup paths');
    $probe = json_decode((string) file_get_contents($probeoutput), true);
    offline_manifest_check(is_array($probe) && $probe['config_loaded'] === false
            && $probe['database_global_initialized'] === false,
        'offline CLI exits before loading config.php or initializing Moodle DB');
} finally {
    foreach ($tempfiles as $tempfile) {
        if (is_string($tempfile) && is_file($tempfile)) {
            @unlink($tempfile);
        }
    }
}

fwrite(STDOUT, "Offline delivery-lineage manifest regressions passed.\n");
