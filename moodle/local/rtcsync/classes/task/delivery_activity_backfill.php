<?php

namespace local_rtcsync\task;

defined('MOODLE_INTERNAL') || die();

/** Bounded scheduled backfill for delivery-managed subject courses. */
final class delivery_activity_backfill extends \core\task\scheduled_task
{
    /** @return string Human-readable task name. */
    public function get_name(): string
    {
        return get_string('taskdeliveryactivitybackfill', 'local_rtcsync');
    }

    /** Process a bounded number of courses and course modules. */
    public function execute(): void
    {
        \local_rtcsync\local\delivery_activity_access::backfill_pending_courses(10, 100);
    }
}
