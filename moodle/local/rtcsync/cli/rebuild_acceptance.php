<?php

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_rtcsync\local\rebuild_audit;

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'json' => false,
], ['h' => 'help']);

if ($unrecognised) {
    cli_error('Unknown option(s): ' . implode(', ', $unrecognised));
}

if ($options['help']) {
    cli_writeln("Read-only acceptance checks for a candidate RTC Moodle instance.\n\n"
        . "  --json      Emit machine-readable JSON.\n"
        . "  -h, --help  Show this help.\n\n"
        . "Exits 2 when a mandatory acceptance check fails.");
    exit(0);
}

$acceptance = rebuild_audit::acceptance();

if ($options['json']) {
    cli_writeln(json_encode($acceptance, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
} else {
    cli_heading('RTC Moodle rebuild acceptance (read-only)');
    cli_writeln('Generated: ' . $acceptance['generated_at']);
    cli_writeln('Site: ' . $acceptance['wwwroot']);
    cli_writeln('');

    foreach ($acceptance['checks'] as $name => $passed) {
        cli_writeln(sprintf('%-52s %s', $name, $passed ? 'PASS' : 'FAIL'));
    }

    if ($acceptance['missing_functions'] !== []) {
        cli_writeln('');
        cli_writeln('Missing RTC Sync functions:');
        foreach ($acceptance['missing_functions'] as $function) {
            cli_writeln('  - ' . $function);
        }
    }

    cli_writeln('');
    cli_writeln($acceptance['passed'] ? 'ACCEPTANCE PASSED' : 'ACCEPTANCE FAILED');
}

exit($acceptance['passed'] ? 0 : 2);