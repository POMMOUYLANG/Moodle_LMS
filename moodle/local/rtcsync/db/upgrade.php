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

    if ($oldversion < 2026072701) {
        upgrade_plugin_savepoint(true, 2026072701, 'local', 'rtcsync');
    }

    if ($oldversion < 2026081503) {
        $dbman = $DB->get_manager();

        $configtable = new xmldb_table('local_rtcsync_formcfg');
        $configtable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $configtable->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $configtable->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $configtable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $configtable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $configtable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $configtable->add_key('course_fk', XMLDB_KEY_FOREIGN_UNIQUE, ['courseid'], 'course', ['id']);
        if (!$dbman->table_exists($configtable)) {
            $dbman->create_table($configtable);
        }

        $itemtable = new xmldb_table('local_rtcsync_formitem');
        $itemtable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $itemtable->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $itemtable->add_field('itemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $itemtable->add_field('included', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $itemtable->add_field('label', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
        $itemtable->add_field('weight', XMLDB_TYPE_NUMBER, '5, 2', null, XMLDB_NOTNULL, null, '0');
        $itemtable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $itemtable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $itemtable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $itemtable->add_key('course_fk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $itemtable->add_key('item_fk', XMLDB_KEY_FOREIGN, ['itemid'], 'grade_items', ['id']);
        $itemtable->add_index('courseitem_uix', XMLDB_INDEX_UNIQUE, ['courseid', 'itemid']);
        $itemtable->add_index('included_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'included']);
        if (!$dbman->table_exists($itemtable)) {
            $dbman->create_table($itemtable);
        }

        upgrade_plugin_savepoint(true, 2026081503, 'local', 'rtcsync');
    }

    return true;
}
