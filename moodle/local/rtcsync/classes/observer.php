<?php

namespace local_rtcsync;

defined('MOODLE_INTERNAL') || die();

/** Moodle event observers for delivery-aware activity access. */
final class observer
{
    /** Restore grouping availability immediately after an attempted disable. */
    public static function config_log_created(\core\event\config_log_created $event): void
    {
        $setting = $event->other;
        if (($setting['name'] ?? '') !== 'enableavailability'
                || (string) ($setting['value'] ?? '') !== '0'
                || !in_array((string) ($setting['plugin'] ?? ''), ['', 'core'], true)) {
            return;
        }

        // Do not change the site-wide setting when this plugin has no managed
        // delivery courses. The override only exists to protect those courses.
        if (!\local_rtcsync\local\delivery_activity_access::has_guarded_courses()) {
            return;
        }

        \local_rtcsync\local\delivery_activity_access::enforce_availability_lockdown();
        set_config('enableavailability', 1);
    }

    /**
     * Guard a newly-created course module in a delivery-managed subject course.
     *
     * @param \core\event\course_module_created $event Event.
     */
    public static function course_module_created(\core\event\course_module_created $event): void
    {
        \local_rtcsync\local\delivery_activity_access::handle_module_event((int) $event->objectid);
    }

    /**
     * Refresh a course module's delivery grouping guard after an edit.
     *
     * @param \core\event\course_module_updated $event Event.
     */
    public static function course_module_updated(\core\event\course_module_updated $event): void
    {
        \local_rtcsync\local\delivery_activity_access::handle_module_event((int) $event->objectid);
    }
}
