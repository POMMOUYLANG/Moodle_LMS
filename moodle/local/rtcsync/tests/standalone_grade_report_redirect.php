<?php

declare(strict_types=1);

// This script intentionally avoids Moodle's config.php, PHPUnit bootstrap, and database.
define('MOODLE_INTERNAL', true);

require_once __DIR__ . '/../classes/local/grade_report_redirect.php';

function grade_report_expect_same(mixed $expected, mixed $actual, string $case): void {
    if ($expected !== $actual) {
        throw new RuntimeException($case . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}

function grade_report_expect_routed(
    string $path,
    array $params,
    int $viewerid,
    ?int $expectedscope,
    string $case
): void {
    $scopes = [];
    $redirected = \local_rtcsync\local\grade_report_redirect::should_redirect(
        $path,
        $params,
        $viewerid,
        static function (int $courseid) use (&$scopes): bool {
            $scopes[] = $courseid;
            return true;
        }
    );

    grade_report_expect_same($expectedscope !== null, $redirected, $case . ' redirect decision');
    grade_report_expect_same($expectedscope === null ? [] : [$expectedscope], $scopes, $case . ' policy scope');
}

$viewerid = 42;

grade_report_expect_routed(
    '/moodle/grade/report/overview/index.php',
    ['id' => 1],
    $viewerid,
    0,
    'Overview defaults to the signed-in learner'
);
grade_report_expect_routed(
    '/moodle/grade/report/overview/index.php',
    ['id' => 8, 'userid' => '42'],
    $viewerid,
    0,
    'Overview self report uses cross-course scope'
);
grade_report_expect_routed(
    '/grade/report/overview/index.php',
    ['userid' => 73],
    $viewerid,
    null,
    'Overview of another user is preserved'
);
grade_report_expect_routed(
    '/grade/report/overview/index.php',
    ['userid' => 0],
    $viewerid,
    null,
    'Overview all-users report is preserved'
);

grade_report_expect_routed(
    '/grade/report/index.php',
    ['id' => '17'],
    $viewerid,
    17,
    'Course grade-report dispatcher'
);
grade_report_expect_routed(
    '/grade/report/user/index.php',
    ['id' => 17],
    $viewerid,
    null,
    'Normalized PAGE URL for user report omits userid and defers to the core guard'
);
grade_report_expect_routed(
    '/grade/report/user/index.php',
    ['id' => '17', 'userid' => '42'],
    $viewerid,
    null,
    'User report self target is handled by the core post-authorization guard'
);
grade_report_expect_routed(
    '/grade/report/user/index.php',
    ['id' => 17, 'userid' => 0],
    $viewerid,
    null,
    'User report all-users mode is preserved'
);
grade_report_expect_routed(
    '/grade/report/user/index.php',
    ['id' => 17, 'userid' => 73],
    $viewerid,
    null,
    'User report of another learner is preserved'
);
grade_report_expect_routed(
    '/grade/report/user/index.php',
    ['id' => 'not-a-course'],
    $viewerid,
    null,
    'Invalid course parameter does not redirect'
);

// Moodle's core page strips userid from $PAGE->url before require_login(), so
// the generic callback must not infer that a normalized user-report URL is a
// self-report. Verify the core page retains its authorization-aware guard.
$userreportsource = file_get_contents(__DIR__ . '/../../../grade/report/user/index.php');
if (!is_string($userreportsource)) {
    throw new RuntimeException('Unable to read the Moodle core user grade report for the integration contract check.');
}
$userreportaccessposition = strpos($userreportsource, 'require_capability(\'gradereport/user:view\', $context);');
$userreportdeniedposition = strpos($userreportsource, 'if (!$access)');
$userreportguardposition = strpos($userreportsource, 'should_restrict_grade_report(');
$userreportselfguard = strpos($userreportsource, '($userid === null || $userid === (int) $USER->id)');
if ($userreportaccessposition === false
        || $userreportdeniedposition === false
        || $userreportguardposition === false
        || $userreportselfguard === false
        || $userreportguardposition < $userreportaccessposition
        || $userreportguardposition < $userreportdeniedposition) {
    throw new RuntimeException('The core user grade report must preserve its self-only redirect after authorization checks.');
}

grade_report_expect_routed(
    '/course/user.php',
    ['id' => 17, 'user' => 42, 'mode' => 'grade'],
    $viewerid,
    17,
    'Course profile grade details for self'
);
grade_report_expect_routed(
    '/course/user.php',
    ['id' => 17, 'user' => 42, 'mode' => 'GRADE'],
    $viewerid,
    17,
    'Case-variant course profile mode falls back to grade'
);
grade_report_expect_routed(
    '/course/user.php',
    ['id' => 17, 'user' => 42, 'mode' => 'unsupported'],
    $viewerid,
    17,
    'Unknown course profile mode falls back to grade'
);
grade_report_expect_routed(
    '/course/user.php',
    ['id' => 17, 'user' => 73, 'mode' => 'grade'],
    $viewerid,
    null,
    'Course profile grade details for another user are preserved'
);
foreach ([
    'outline',
    'complete',
    'todaylogs',
    'alllogs',
    'stats',
    'coursecompletions',
    'coursecompletion',
] as $mode) {
    grade_report_expect_routed(
        '/course/user.php',
        ['id' => 17, 'user' => 42, 'mode' => $mode],
        $viewerid,
        null,
        'Allowed non-grade course profile mode ' . $mode . ' is preserved'
    );
}
grade_report_expect_routed(
    '/local/rtcsync/student_history.php',
    [],
    $viewerid,
    null,
    'Released results page cannot redirect back to itself'
);
grade_report_expect_routed(
    '/grade/report/user/index.php',
    ['id' => 17],
    1,
    null,
    'Guest and system users are not redirected'
);

fwrite(STDOUT, "Standalone grade-report redirect checks passed.\n");
