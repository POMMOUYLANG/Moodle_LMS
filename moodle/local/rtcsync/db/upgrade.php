<?php

defined('MOODLE_INTERNAL') || die();

function xmldb_local_rtcsync_upgrade(int $oldversion): bool
{
    global $CFG, $DB;

    if ($oldversion < 2026072401) {
        require_once($CFG->dirroot . '/local/rtcsync/locallib.php');
        local_rtcsync_ensure_profile_fields();
        upgrade_plugin_savepoint(true, 2026072401, 'local', 'rtcsync');
    }

    if ($oldversion < 2026072402) {
        require_once($CFG->dirroot . '/local/rtcsync/locallib.php');
        local_rtcsync_ensure_profile_fields();
        upgrade_plugin_savepoint(true, 2026072402, 'local', 'rtcsync');
    }

    if ($oldversion < 2026072601) {
        $systemcontextid = $DB->get_field('context', 'id', [
            'contextlevel' => CONTEXT_SYSTEM,
            'instanceid' => 0,
        ]);
        $legacyroleids = $DB->get_fieldset_select(
            'role',
            'id',
            'shortname IN (:editingteacher, :student)',
            ['editingteacher' => 'editingteacher', 'student' => 'student']
        );

        if ($systemcontextid && $legacyroleids) {
            [$rolesql, $roleparams] = $DB->get_in_or_equal(
                $legacyroleids,
                SQL_PARAMS_NAMED,
                'legacyrole'
            );
            $DB->delete_records_select(
                'role_assignments',
                "contextid = :systemcontextid
                    AND component = :component
                    AND roleid {$rolesql}",
                $roleparams + [
                    'systemcontextid' => $systemcontextid,
                    'component' => 'local_rtc_sso',
                ]
            );
        }

        // Manager and all manually assigned roles are intentionally preserved.
        // Moodle siteadmins are never modified by this automated cleanup.
        upgrade_plugin_savepoint(true, 2026072601, 'local', 'rtcsync');
    }

    if ($oldversion < 2026072602) {
        upgrade_plugin_savepoint(true, 2026072602, 'local', 'rtcsync');
    }

    return true;
}
