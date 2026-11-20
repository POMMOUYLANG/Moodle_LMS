<?php

namespace local_rtcsync\local;

defined('MOODLE_INTERNAL') || die();

/** Map transient RTC Sync failures to HTTP status codes recognized by REST clients. */
final class retryable_webservice_error
{
    /** Mark the current REST response as retryable by clients that classify HTTP 5xx. */
    public static function mark_service_unavailable(): void
    {
        if (!headers_sent()) {
            http_response_code(503);
        }
    }

    /** Create Moodle's standard lock-timeout error with a retryable REST status. */
    public static function lock_timeout(): \moodle_exception
    {
        self::mark_service_unavailable();
        return new \moodle_exception('locktimeout');
    }
}
