<?php

// CLI contract regression checks; help and offline validation never bootstrap Moodle or connect to a database.
declare(strict_types=1);

function lineage_cli_expect(bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException('Delivery-lineage CLI check failed: ' . $label);
    }
}

$script = dirname(__DIR__) . '/cli/reconcile_delivery_lineage.php';
$runcli = static function(array $arguments) use ($script): array {
    $pipes = [];
    $process = proc_open(
        array_merge([PHP_BINARY, $script], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the delivery-lineage CLI for its standalone check.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), (string) $stdout, (string) $stderr];
};

[$helpstatus, $help, $helperrors] = $runcli(['--help']);
lineage_cli_expect($helpstatus === 0 && $helperrors === '', 'help is available before Moodle bootstrap');
foreach ([
    'Dry-run is the default',
    '--apply',
    '--plan-sha256',
    '--operator',
    '--approved-by',
    '--approval-reference',
    'not authenticated',
    'OS account authorized',
    'declarations, not verified identities',
    'limited to 8 MiB',
] as $requiredtext) {
    lineage_cli_expect(stripos($help, $requiredtext) !== false, 'help documents ' . $requiredtext);
}

$oversizedmanifest = tempnam(sys_get_temp_dir(), 'rtc-lineage-manifest-');
if ($oversizedmanifest === false) {
    throw new RuntimeException('Unable to create a temporary manifest for the size-limit check.');
}
try {
    $handle = fopen($oversizedmanifest, 'wb');
    $created = is_resource($handle) && ftruncate($handle, 8388609);
    if (is_resource($handle)) {
        fclose($handle);
    }
    if (!$created) {
        throw new RuntimeException('Unable to create the oversized manifest fixture.');
    }
    [$status, $stdout, $stderr] = $runcli([
        '--check-manifest',
        '--manifest=' . $oversizedmanifest,
    ]);
    lineage_cli_expect($status === 2, 'oversized manifest is rejected with the validation exit code');
    lineage_cli_expect($stdout === '', 'oversized manifest rejection does not print file contents');
    lineage_cli_expect(
        str_contains($stderr, 'unreadable or exceeds the supported size limit'),
        'oversized manifest rejection is clear and does not require Moodle bootstrap'
    );
} finally {
    @unlink($oversizedmanifest);
}

echo "Delivery-lineage CLI contract checks passed.\n";
