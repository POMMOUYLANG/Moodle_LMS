<?php

defined('MOODLE_INTERNAL') || die();

function xmldb_local_rtcsync_upgrade(int $oldversion): bool
{
    global $CFG;

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

    return true;
}