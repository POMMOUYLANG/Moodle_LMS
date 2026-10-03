<?php

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_rtcsync\local\rebuild_audit;

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'json' => false,
    'acknowledge' => false,
], ['h' => 'help']);

if ($unrecognised) {
    cli_error('Unknown option(s): ' . implode(', ', $unrecognised));
}

if ($options['help']) {
    cli_writeln("Review unmanaged roles on legacy RTC isolated credit courses.\n\n"
        . "  --json          Emit machine-readable JSON.\n"
        . "  --acknowledge   Record the current assignment fingerprint as reviewed.\n"
        . "  -h, --help      Show this help.\n\n"
        . "Without --acknowledge this command is read-only. Any assignment change\n"
        . "invalidates the acknowledgment and requires a new review.");
    exit(0);
}

$assignments = rebuild_audit::unmanaged_credit_role_assignments();
$fingerprint = rebuild_audit::unmanaged_credit_role_fingerprint($assignments);

if ($options['acknowledge'] && $assignments !== []) {
    set_config(
        'unmanaged_credit_role_assignments_acknowledged',
        $fingerprint,
        'local_rtcsync'
    );
}

$result = [
    'assignment_count' => count($assignments),
    'assignments' => $assignments,
    'fingerprint' => $assignments === [] ? null : $fingerprint,
    'acknowledged' => $options['acknowledge'] && $assignments !== [],
];

if ($options['json']) {
    cli_writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
} else {
    cli_heading('RTC unmanaged credit-role review');
    cli_writeln('Assignments requiring review: ' . $result['assignment_count']);
    if ($assignments !== []) {
        foreach ($assignments as $assignment) {
            cli_writeln(sprintf(
                '  - %s / user %d / role %s / component %s',
                $assignment['course_idnumber'],
                $assignment['userid'],
                $assignment['role_shortname'],
                $assignment['component'] !== '' ? $assignment['component'] : '[manual]'
            ));
        }
        cli_writeln('Fingerprint: ' . $fingerprint);
        cli_writeln($result['acknowledged']
            ? 'Review acknowledged for the current fingerprint.'
            : 'Read-only review. Re-run with --acknowledge only after manual approval.');
    } else {
        cli_writeln('No unmanaged credit-course role assignments found.');
    }
}

exit($assignments === [] || $result['acknowledged'] ? 0 : 2);
