<?php

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_rtcsync\local\rebuild_audit;

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'json' => false,
    'strict' => false,
], ['h' => 'help']);

if ($unrecognised) {
    cli_error('Unknown option(s): ' . implode(', ', $unrecognised));
}

if ($options['help']) {
    cli_writeln("Read-only inventory for a guarded RTC Moodle rebuild.\n\n"
        . "  --json      Emit machine-readable JSON.\n"
        . "  --strict    Exit 2 when learning content requires migration.\n"
        . "  -h, --help  Show this help.");
    exit(0);
}

$inventory = rebuild_audit::inventory();

if ($options['json']) {
    cli_writeln(json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
} else {
    cli_heading('RTC Moodle rebuild inventory (read-only)');
    cli_writeln('Generated: ' . $inventory['generated_at']);
    cli_writeln('Site: ' . $inventory['site']['shortname'] . ' (' . $inventory['site']['wwwroot'] . ')');
    cli_writeln('');

    foreach (['managed', 'unmanaged'] as $scope) {
        cli_heading(ucfirst($scope) . ' course data');
        foreach ($inventory[$scope] as $metric => $count) {
            cli_writeln(str_pad($metric, 28) . $count);
        }
        cli_writeln('');
    }

    cli_heading('Rebuild decision');
    if ($inventory['safe_to_discard_without_content_migration']) {
        cli_writeln('PASS: no learning-content records requiring migration were detected.');
    } else {
        cli_writeln('BLOCKED: preserve or migrate these data sets before cutover:');
        foreach ($inventory['blockers'] as $blocker) {
            cli_writeln('  - ' . $blocker);
        }
    }

    if ($inventory['unmanaged_course_samples'] !== []) {
        cli_writeln('');
        cli_heading('Unmanaged course samples');
        foreach ($inventory['unmanaged_course_samples'] as $course) {
            cli_writeln(sprintf(
                '  #%d [%s] %s (%d activities)',
                $course['id'],
                $course['idnumber'] !== '' ? $course['idnumber'] : 'no idnumber',
                $course['fullname'],
                $course['activities']
            ));
        }
    }
}

exit($options['strict'] && !$inventory['safe_to_discard_without_content_migration'] ? 2 : 0);