<?php

namespace local_rtcsync;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests the RTC Sync webservice correlation event observer.
 */
final class correlation_observer_test extends \advanced_testcase
{
    public function test_observer_is_registered_for_webservice_function_calls(): void
    {
        $this->resetAfterTest();

        $observers = \core\event\manager::get_all_observers();
        $webserviceobservers = $observers['\\core\\event\\webservice_function_called'] ?? [];
        $callbacks = array_map(
            static fn($observer): string => $observer->callable,
            $webserviceobservers
        );

        $this->assertContains('\\local_rtcsync\\observer::webservice_function_called', $callbacks);
    }

    public function test_valid_uuid_emits_only_anonymous_correlation_and_function(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();

        $correlationid = '87ef003a-b954-4ce0-8b28-ccf90a8173c5';
        $events = $this->captureCorrelatedEvents('local_rtcsync_upsert_course', $correlationid);

        $this->assertCount(1, $events);
        $this->assertSame([
            'correlation_id' => $correlationid,
            'function' => 'local_rtcsync_upsert_course',
        ], $events[0]->other);
        $this->assertSame(0, (int) $events[0]->userid);
        $this->assertNull($events[0]->relateduserid);
        $this->assertSame(1, (int) $events[0]->anonymous);
    }

    public function test_malformed_uuid_does_not_emit_correlation_event(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();

        $events = $this->captureCorrelatedEvents('local_rtcsync_upsert_course', 'not-a-uuid');

        $this->assertCount(0, $events);
    }

    public function test_missing_header_does_not_emit_correlation_event(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();

        $events = $this->captureCorrelatedEvents('local_rtcsync_upsert_course', null);

        $this->assertCount(0, $events);
    }

    public function test_valid_uuid_for_unrelated_function_does_not_emit_correlation_event(): void
    {
        $this->resetAfterTest();
        $this->setAdminUser();

        $events = $this->captureCorrelatedEvents(
            'core_user_get_users',
            '87ef003a-b954-4ce0-8b28-ccf90a8173c5'
        );

        $this->assertCount(0, $events);
    }

    /**
     * Invoke the observer with Moodle's webservice event and capture its RTC event.
     *
     * @param string $function Moodle external function name.
     * @param string|null $correlationid HTTP correlation header value.
     * @return array
     */
    private function captureCorrelatedEvents(string $function, ?string $correlationid): array
    {
        $header = 'HTTP_X_RTC_CORRELATION_ID';
        $hadheader = array_key_exists($header, $_SERVER);
        $previousheader = $_SERVER[$header] ?? null;

        if ($correlationid === null) {
            unset($_SERVER[$header]);
        } else {
            $_SERVER[$header] = $correlationid;
        }

        $sink = $this->redirectEvents();
        try {
            $event = \core\event\webservice_function_called::create([
                'other' => ['function' => $function],
            ]);
            // Moodle's PHPUnit event sink intercepts trigger() before normal observers run.
            \local_rtcsync\observer::webservice_function_called($event);
            $events = $sink->get_events();
        } finally {
            $sink->close();
            if ($hadheader) {
                $_SERVER[$header] = $previousheader;
            } else {
                unset($_SERVER[$header]);
            }
        }

        return array_values(array_filter(
            $events,
            static fn($event): bool => $event instanceof \local_rtcsync\event\webservice_call_correlated
        ));
    }
}
