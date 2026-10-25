<?php

declare(strict_types=1);

// This script intentionally avoids Moodle's config.php, PHPUnit bootstrap, and database.
define('MOODLE_INTERNAL', true);

final class core_renderer
{
}

final class StudentHistoryRequestDenied extends RuntimeException
{
}

require_once __DIR__ . '/../classes/local/student_history_request.php';
require_once __DIR__ . '/../classes/local/student_history_page.php';

function student_history_expect_same(mixed $expected, mixed $actual, string $case): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($case . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}

function student_history_expect_denied(callable $action, string $case): void
{
    try {
        $action();
    } catch (StudentHistoryRequestDenied) {
        return;
    }

    throw new RuntimeException($case . ': expected the request to be denied.');
}

$request = new \local_rtcsync\local\student_history_request();

foreach (['', " \t "] as $missingtoken) {
    $events = [];
    student_history_expect_denied(
        static function () use ($request, $missingtoken, &$events): void {
            $request->handle(
                $missingtoken,
                static function (string $token) use (&$events): ?string {
                    $events[] = 'render';
                    return '<p>should not render</p>';
                },
                static function () use (&$events): void {
                    $events[] = 'clear';
                },
                static function () use (&$events): void {
                    $events[] = 'logout';
                },
                static function () use (&$events): void {
                    $events[] = 'deny';
                    throw new StudentHistoryRequestDenied();
                }
            );
        },
        'missing token'
    );
    student_history_expect_same(['deny'], $events, 'missing token must stop before lookup or cleanup');
}

foreach ([
    'inactive identity' => ['account_is_active' => false],
    'fetch failure' => null,
] as $case => $identity) {
    $events = [];
    $identityprovider = static function (string $token) use (&$events, $identity): ?array {
        $events[] = 'identity';
        return $identity;
    };
    $historypage = new \local_rtcsync\local\student_history_page($identityprovider);
    $cursorreader = static function () use (&$events): string {
        $events[] = 'cursor';
        return '';
    };

    student_history_expect_denied(
        static function () use ($request, $historypage, $cursorreader, &$events): void {
            $request->handle(
                'sentinel-token',
                static fn (string $token): ?string => $historypage->render(
                    $token,
                    $cursorreader,
                    new core_renderer()
                ),
                static function () use (&$events): void {
                    $events[] = 'clear';
                },
                static function () use (&$events): void {
                    $events[] = 'logout';
                },
                static function () use (&$events): void {
                    $events[] = 'deny';
                    throw new StudentHistoryRequestDenied();
                }
            );
        },
        $case
    );
    student_history_expect_same(
        ['identity', 'clear', 'logout', 'deny'],
        $events,
        $case . ' cleanup order'
    );
}

$events = [];
$renderedtoken = null;
$rendered = $request->handle(
    " \tsentinel-token \n",
    static function (string $token) use (&$events, &$renderedtoken): ?string {
        $events[] = 'render';
        $renderedtoken = $token;
        return '<p>released results</p>';
    },
    static function () use (&$events): void {
        $events[] = 'clear';
    },
    static function () use (&$events): void {
        $events[] = 'logout';
    },
    static function () use (&$events): void {
        $events[] = 'deny';
        throw new StudentHistoryRequestDenied();
    }
);
student_history_expect_same('<p>released results</p>', $rendered, 'successful rendering');
student_history_expect_same('sentinel-token', $renderedtoken, 'successful rendering receives the trimmed RTC token');
student_history_expect_same(['render'], $events, 'successful rendering must not clean up or log out');

fwrite(STDOUT, "Standalone student-history request checks passed.\n");
