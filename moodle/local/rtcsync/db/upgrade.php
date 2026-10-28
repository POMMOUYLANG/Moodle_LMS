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
        $itemtable->add_field('label', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
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

    if ($oldversion < 2026100101) {
        require_once($CFG->dirroot . '/local/rtcsync/locallib.php');
        local_rtcsync_ensure_profile_fields();
        upgrade_plugin_savepoint(true, 2026100101, 'local', 'rtcsync');
    }

    if ($oldversion < 2026100102) {
        // Code-only release: managed-course inventory uses the existing
        // external-service contract and requires no schema migration.
        upgrade_plugin_savepoint(true, 2026100102, 'local', 'rtcsync');
    }

    if ($oldversion < 2026100103) {
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_rtcsync_user');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('idnumber', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('user_fk', XMLDB_KEY_FOREIGN_UNIQUE, ['userid'], 'user', ['id']);
        $table->add_index('idnumber_ix', XMLDB_INDEX_NOTUNIQUE, ['idnumber']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026100103, 'local', 'rtcsync');
    }

    if ($oldversion < 2026100201) {
        // Code-only release: credit-course role assignments now carry an
        // RTC ownership marker so unmanaged Moodle permissions are preserved.
        upgrade_plugin_savepoint(true, 2026100201, 'local', 'rtcsync');
    }

    if ($oldversion < 2026100202) {
        // Code-only release: acceptance now validates profile metadata and
        // supports fingerprinted review of legacy credit-course roles.
        upgrade_plugin_savepoint(true, 2026100202, 'local', 'rtcsync');
    }

    if ($oldversion < 2026100203) {
        // Code-only release: all RTC-Sync write paths now require explicit
        // ownership markers for target users and reserved course IDs.
        upgrade_plugin_savepoint(true, 2026100203, 'local', 'rtcsync');
    }

    if ($oldversion < 2026100204) {
        // Code-only release: acceptance now verifies the capability contract
        // for every registered RTC-Sync external function.
        upgrade_plugin_savepoint(true, 2026100204, 'local', 'rtcsync');
    }

    if ($oldversion < 2026102401) {
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_rtcsync_history');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('sourcetype', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL);
        $table->add_field('sourceid', XMLDB_TYPE_INTEGER, '20', XMLDB_UNSIGNED, XMLDB_NOTNULL);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('user_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('course_fk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_index('usercourse_src_uix', XMLDB_INDEX_UNIQUE, ['userid', 'courseid', 'sourcetype', 'sourceid']);
        $table->add_index('courseuser_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026102401, 'local', 'rtcsync');
    }

    if ($oldversion < 2026102501) {
        $dbman = $DB->get_manager();

        // Old delivery markers have a participation ID but no reliable
        // delivery ID. Keep them for reconciliation, but leave deliveryid
        // NULL so they cannot grant or disclose delivery-scoped history.
        $history = new xmldb_table('local_rtcsync_history');
        $deliveryid = new xmldb_field(
            'deliveryid',
            XMLDB_TYPE_INTEGER,
            '10',
            XMLDB_UNSIGNED,
            null,
            null,
            null,
            'sourceid'
        );
        if ($dbman->table_exists($history) && !$dbman->field_exists($history, $deliveryid)) {
            $dbman->add_field($history, $deliveryid);
        }
        if ($dbman->table_exists($history) && $dbman->field_exists($history, $deliveryid)) {
            $DB->set_field('local_rtcsync_history', 'deliveryid', 0, ['sourcetype' => 'user_program']);
            $historyindex = new xmldb_index('coursedeliv_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'deliveryid']);
            if (!$dbman->index_exists($history, $historyindex)) {
                $dbman->add_index($history, $historyindex);
            }
        }

        $deliverycm = new xmldb_table('local_rtcsync_deliverycm');
        $deliverycm->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $deliverycm->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $deliverycm->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $deliverycm->add_field('deliveryid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0');
        $deliverycm->add_field('groupingid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $deliverycm->add_field('originalavailability', XMLDB_TYPE_TEXT, null, null, null);
        $deliverycm->add_field('managedavailability', XMLDB_TYPE_TEXT, null, null, null);
        $deliverycm->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $deliverycm->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $deliverycm->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $deliverycm->add_key('cm_fk', XMLDB_KEY_FOREIGN_UNIQUE, ['cmid'], 'course_modules', ['id']);
        $deliverycm->add_key('course_fk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $deliverycm->add_index('coursedeliv_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'deliveryid']);
        if (!$dbman->table_exists($deliverycm)) {
            $dbman->create_table($deliverycm);
        }
        $deliverycmgroupingid = new xmldb_field(
            'groupingid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'deliveryid'
        );
        if ($dbman->table_exists($deliverycm) && !$dbman->field_exists($deliverycm, $deliverycmgroupingid)) {
            $dbman->add_field($deliverycm, $deliverycmgroupingid);
        }

        $deliverygrade = new xmldb_table('local_rtcsync_deliverygrade');
        $deliverygrade->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $deliverygrade->add_field('gradeitemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $deliverygrade->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $deliverygrade->add_field('deliveryid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL);
        $deliverygrade->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $deliverygrade->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $deliverygrade->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $deliverygrade->add_key('gradeitem_fk', XMLDB_KEY_FOREIGN_UNIQUE, ['gradeitemid'], 'grade_items', ['id']);
        $deliverygrade->add_key('course_fk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $deliverygrade->add_index('coursedeliv_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'deliveryid']);
        if (!$dbman->table_exists($deliverygrade)) {
            $dbman->create_table($deliverygrade);
        }

        $deliverycourse = new xmldb_table('local_rtcsync_deliverycourse');
        $deliverycourse->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $deliverycourse->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $deliverycourse->add_field('coursevisible', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $deliverycourse->add_field('ready', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $deliverycourse->add_field('lastcmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $deliverycourse->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $deliverycourse->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $deliverycourse->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $deliverycourse->add_key('course_fk', XMLDB_KEY_FOREIGN_UNIQUE, ['courseid'], 'course', ['id']);
        $deliverycourse->add_index('ready_ix', XMLDB_INDEX_NOTUNIQUE, ['ready', 'courseid']);
        if (!$dbman->table_exists($deliverycourse)) {
            $dbman->create_table($deliverycourse);
        }

        // Close the preflight rollout window for every existing RTC subject
        // course. Courses are hidden and marked pending now; the bounded task
        // restores their prior visibility only after every module has a
        // standard availability grouping condition (impossible for untagged
        // modules).
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->libdir . '/grouplib.php');
        require_once($CFG->libdir . '/enrollib.php');
        $deliverycourses = $DB->get_records_sql(
            "SELECT DISTINCT c.id AS courseid, c.visible, c.groupmode
               FROM {course} c
              WHERE c.idnumber LIKE :subjectcourse
                AND c.id <> :siteid",
            [
                'subjectcourse' => 'rtc-subject:%',
                'siteid' => SITEID,
            ]
        );
        foreach ($deliverycourses as $deliverycourse) {
            $courseid = (int) $deliverycourse->courseid;
            if (!$DB->record_exists('local_rtcsync_deliverycourse', ['courseid' => $courseid])) {
                $now = time();
                $DB->insert_record('local_rtcsync_deliverycourse', (object) [
                    'courseid' => $courseid,
                    'coursevisible' => (int) $deliverycourse->visible,
                    'ready' => 0,
                    'lastcmid' => 0,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
            }

            $DB->set_field('course', 'visible', 0, ['id' => $courseid]);
            if ((int) $deliverycourse->groupmode === NOGROUPS) {
                // The activity edit form hides grouping selectors in this
                // mode. Enable separate groups, while preserving groupmodeforce.
                $DB->set_field('course', 'groupmode', SEPARATEGROUPS, ['id' => $courseid]);
            }
            $enrolments = $DB->get_records_sql(
                "SELECT DISTINCT e.id AS enrolid, ue.userid
                   FROM {enrol} e
                   JOIN {user_enrolments} ue ON ue.enrolid = e.id
                   JOIN {local_rtcsync_user} managed ON managed.userid = ue.userid
                   JOIN {context} ctx ON ctx.instanceid = e.courseid AND ctx.contextlevel = :coursecontext
                   JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = ue.userid
                   JOIN {role} r ON r.id = ra.roleid AND r.shortname = :student
                  WHERE e.courseid = :courseid
                    AND e.enrol = :manual
                    AND ue.status = :active",
                [
                    'coursecontext' => CONTEXT_COURSE,
                    'student' => 'student',
                    'courseid' => $courseid,
                    'manual' => 'manual',
                    'active' => ENROL_USER_ACTIVE,
                ]
            );
            $manualplugin = enrol_get_plugin('manual');
            $instances = [];
            foreach ($enrolments as $enrolment) {
                $enrolid = (int) $enrolment->enrolid;
                if (!isset($instances[$enrolid])) {
                    $instances[$enrolid] = $DB->get_record('enrol', ['id' => $enrolid], '*', MUST_EXIST);
                }
                $manualplugin->update_user_enrol(
                    $instances[$enrolid],
                    (int) $enrolment->userid,
                    ENROL_USER_SUSPENDED
                );
            }
            rebuild_course_cache($courseid, true);
        }

        upgrade_plugin_savepoint(true, 2026102501, 'local', 'rtcsync');
    }

    if ($oldversion < 2026102502) {
        $dbman = $DB->get_manager();
        $deliverycmhistory = new xmldb_table('local_rtcsync_deliverycmhist');
        $deliverycmhistory->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $deliverycmhistory->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $deliverycmhistory->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $deliverycmhistory->add_field('deliveryid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL);
        $deliverycmhistory->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $deliverycmhistory->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        if (!$dbman->table_exists($deliverycmhistory)) {
            $dbman->create_table($deliverycmhistory);
        }
        $moduleDeliveryIndex = new xmldb_index('cmcoursedeliv_uix', XMLDB_INDEX_UNIQUE, ['cmid', 'courseid', 'deliveryid']);
        if (!$dbman->index_exists($deliverycmhistory, $moduleDeliveryIndex)) {
            $dbman->add_index($deliverycmhistory, $moduleDeliveryIndex);
        }
        $courseDeliveryIndex = new xmldb_index('coursedeliv_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'deliveryid']);
        if (!$dbman->index_exists($deliverycmhistory, $courseDeliveryIndex)) {
            $dbman->add_index($deliverycmhistory, $courseDeliveryIndex);
        }

        // Existing deliverycm rows hold the current access mapping. A past
        // mapping already overwritten before this upgrade cannot be recovered.
        upgrade_plugin_savepoint(true, 2026102502, 'local', 'rtcsync');
    }

    if ($oldversion < 2026102503) {
        // Code-only release: enforce delivery grouping access after every
        // course-module-scoped require_login request.
        upgrade_plugin_savepoint(true, 2026102503, 'local', 'rtcsync');
    }

    if ($oldversion < 2026102504) {
        // Code-only release: expose the installed DB version in delivery
        // readiness responses so SMS can reject an outdated access contract.
        upgrade_plugin_savepoint(true, 2026102504, 'local', 'rtcsync');
    }

    if ($oldversion < 2026102505) {
        $dbman = $DB->get_manager();
        $studentscope = new xmldb_table('local_rtcsync_studentscope');
        $studentscope->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $studentscope->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $studentscope->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $studentscope->add_field('deliveryids', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $studentscope->add_field('revision', XMLDB_TYPE_INTEGER, '18', null, XMLDB_NOTNULL, null, '0');
        $studentscope->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $studentscope->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $studentscope->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $studentscope->add_key('course_fk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $studentscope->add_key('user_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $studentscope->add_index('courseuser_uix', XMLDB_INDEX_UNIQUE, ['courseid', 'userid']);
        if (!$dbman->table_exists($studentscope)) {
            $dbman->create_table($studentscope);
        }

        // No scope rows are backfilled from Moodle memberships: those rows may
        // still contain a learner's previous delivery. Missing scope therefore
        // fails closed until SMS synchronizes the learner's current delivery set.
        upgrade_plugin_savepoint(true, 2026102505, 'local', 'rtcsync');
    }

    if ($oldversion < 2026102506) {
        $dbman = $DB->get_manager();
        $studentscope = new xmldb_table('local_rtcsync_studentscope');
        $revision = new xmldb_field('revision', XMLDB_TYPE_INTEGER, '18', null, XMLDB_NOTNULL, null, '0');
        if ($dbman->field_exists($studentscope, $revision)) {
            // The SMS date floor is YYYYMMDD * 1,000,000,000; a signed
            // 64-bit column is required to retain that value and increments.
            $dbman->change_field_precision($studentscope, $revision);
        }

        upgrade_plugin_savepoint(true, 2026102506, 'local', 'rtcsync');
    }

    if ($oldversion < 2026102600) {
        $dbman = $DB->get_manager();

        // Page-level reconciliation enumerates scopes by user ID. The unique
        // course-first index cannot accelerate that reverse lookup.
        $studentscope = new xmldb_table('local_rtcsync_studentscope');
        $userCourseIndex = new xmldb_index('usercourse_ix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);
        if (!$dbman->index_exists($studentscope, $userCourseIndex)) {
            $dbman->add_index($studentscope, $userCourseIndex);
        }

        $deliverymember = new xmldb_table('local_rtcsync_deliverymember');
        $deliverymember->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $deliverymember->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $deliverymember->add_field('groupid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $deliverymember->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $deliverymember->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $deliverymember->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $deliverymember->add_key('course_fk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $deliverymember->add_key('user_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        if (!$dbman->table_exists($deliverymember)) {
            $dbman->create_table($deliverymember);
        }
        $groupUserIndex = new xmldb_index('groupuser_uix', XMLDB_INDEX_UNIQUE, ['groupid', 'userid']);
        if (!$dbman->index_exists($deliverymember, $groupUserIndex)) {
            $dbman->add_index($deliverymember, $groupUserIndex);
        }
        $courseUserIndex = new xmldb_index('courseuser_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'userid']);
        if (!$dbman->index_exists($deliverymember, $courseUserIndex)) {
            $dbman->add_index($deliverymember, $courseUserIndex);
        }

        // Keep desired delivery-group rosters separate from active Moodle group
        // memberships so suspended users and out-of-scope syncs cannot expose
        // previous-delivery calendar events.
        upgrade_plugin_savepoint(true, 2026102600, 'local', 'rtcsync');
    }

    if ($oldversion < 2026102700) {
        $dbman = $DB->get_manager();

        // Keep the unique delivery mappings idempotent for both upgraded and fresh installs.
        // XMLDB_KEY_FOREIGN_UNIQUE creates the unique index when the engine does not enforce
        // foreign keys, and add_key skips the index when an equivalent one already exists.
        $deliverykeys = [
            ['local_rtcsync_deliverycm', 'cm_fk', ['cmid'], 'course_modules', ['id']],
            ['local_rtcsync_deliverygrade', 'gradeitem_fk', ['gradeitemid'], 'grade_items', ['id']],
            ['local_rtcsync_deliverycourse', 'course_fk', ['courseid'], 'course', ['id']],
        ];

        foreach ($deliverykeys as [$tablename, $keyname, $fields, $reftable, $reffields]) {
            $table = new xmldb_table($tablename);
            if ($dbman->table_exists($table)) {
                $key = new xmldb_key($keyname, XMLDB_KEY_FOREIGN_UNIQUE, $fields, $reftable, $reffields);
                $dbman->add_key($table, $key);
            }
        }

        // add_key() can mistake a same-column non-unique index for the index required
        // by a foreign-unique key. Verify the exact column set and uniqueness, then repair
        // partial schemas using Moodle's database-specific index generator.
        foreach ($deliverykeys as [$tablename, $keyname, $fields]) {
            $table = new xmldb_table($tablename);
            if (!$dbman->table_exists($table)) {
                continue;
            }

            $existingindexes = $DB->get_indexes($tablename);
            $hasuniqueindex = false;
            foreach ($existingindexes as $existingindex) {
                if ((bool) ($existingindex['unique'] ?? false)
                        && array_values($existingindex['columns'] ?? []) === $fields) {
                    $hasuniqueindex = true;
                    break;
                }
            }

            if (!$hasuniqueindex) {
                $existingindexnames = array_map('strtolower', array_keys($existingindexes));
                $indexname = $keyname . '_uix';
                $suffix = 1;
                while (in_array(strtolower($indexname), $existingindexnames, true)) {
                    $indexname = $keyname . '_uix' . $suffix;
                    $suffix++;
                }
                $uniqueindex = new xmldb_index($indexname, XMLDB_INDEX_UNIQUE, $fields);
                $DB->change_database_structure(
                    $dbman->generator->getAddIndexSQL($table, $uniqueindex),
                    [$table->getName()],
                );
            }
        }

        // XMLDB index_exists()/add_index() match on the column set only, ignoring order and
        // uniqueness, so the unique (courseid, userid) index makes them treat the reverse
        // (userid, courseid) lookup index as present and skip it. Check the exact ordered
        // columns, then use the XMLDB generator directly to preserve database-specific SQL.
        $studentscope = new xmldb_table('local_rtcsync_studentscope');
        if ($dbman->table_exists($studentscope)) {
            $hasusercourseindex = false;
            foreach ($DB->get_indexes('local_rtcsync_studentscope') as $existingindex) {
                if (!$existingindex['unique'] && array_values($existingindex['columns']) === ['userid', 'courseid']) {
                    $hasusercourseindex = true;
                    break;
                }
            }
            if (!$hasusercourseindex) {
                $usercourseindex = new xmldb_index('usercourse_ix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);
                $DB->change_database_structure(
                    $dbman->generator->getAddIndexSQL($studentscope, $usercourseindex),
                    [$studentscope->getName()],
                );
            }
        }

        upgrade_plugin_savepoint(true, 2026102700, 'local', 'rtcsync');
    }

    if ($oldversion < 2026102800) {
        $dbman = $DB->get_manager();

        $lineagefields = [
            ['local_rtcsync_deliverycm', 'lineagestatus', XMLDB_TYPE_INTEGER, '2', XMLDB_NOTNULL, '0', 'timemodified'],
            ['local_rtcsync_deliverycm', 'lineagemanifestid', XMLDB_TYPE_CHAR, '64', null, null, 'lineagestatus'],
            ['local_rtcsync_deliverycmhist', 'lineageverified', XMLDB_TYPE_INTEGER, '1', XMLDB_NOTNULL, '0', 'timecreated'],
            ['local_rtcsync_deliverycmhist', 'lineagemanifestid', XMLDB_TYPE_CHAR, '64', null, null, 'lineageverified'],
            ['local_rtcsync_deliverygrade', 'lineageverified', XMLDB_TYPE_INTEGER, '1', XMLDB_NOTNULL, '0', 'timemodified'],
            ['local_rtcsync_deliverygrade', 'lineagemanifestid', XMLDB_TYPE_CHAR, '64', null, null, 'lineageverified'],
        ];
        foreach ($lineagefields as [$tablename, $fieldname, $type, $length, $notnull, $default, $after]) {
            $table = new xmldb_table($tablename);
            if (!$dbman->table_exists($table)) {
                continue;
            }
            $field = new xmldb_field($fieldname, $type, $length, null, $notnull, null, $default, $after);
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        $manifesttable = new xmldb_table('local_rtcsync_linemanifest');
        $manifesttable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $manifesttable->add_field('manifestid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $manifesttable->add_field('manifesthash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $manifesttable->add_field('sourcehash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $manifesttable->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $manifesttable->add_field('courseidnumber', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
        $manifesttable->add_field('sourceversion', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $manifesttable->add_field('approvedby', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $manifesttable->add_field('approvalref', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $manifesttable->add_field('approvedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $manifesttable->add_field('actorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $manifesttable->add_field('operator', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
        $manifesttable->add_field('entrycount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $manifesttable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $manifesttable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $manifesttable->add_index('manifest_uix', XMLDB_INDEX_UNIQUE, ['manifestid']);
        $manifesttable->add_index('course_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        if (!$dbman->table_exists($manifesttable)) {
            $dbman->create_table($manifesttable);
        }

        $audit = new xmldb_table('local_rtcsync_lineageaudit');
        $audit->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $audit->add_field('manifestid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $audit->add_field('manifesthash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $audit->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $audit->add_field('recordtype', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL);
        $audit->add_field('targetid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $audit->add_field('decision', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL);
        $audit->add_field('deliveryids', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $audit->add_field('sourceidentity', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $audit->add_field('targetidentity', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $audit->add_field('reason', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $audit->add_field('entryhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $audit->add_field('resultstate', XMLDB_TYPE_TEXT, null, null, null);
        $audit->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $audit->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $audit->add_index('manifesttarget_uix', XMLDB_INDEX_UNIQUE, ['manifestid', 'recordtype', 'targetid']);
        $audit->add_index('course_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        if (!$dbman->table_exists($audit)) {
            $dbman->create_table($audit);
        }

        // Existing activity mappings prove only the last observed grouping. Mark
        // them unreviewed and force each managed course through the locking
        // backfill before students can use activities again.
        if ($dbman->table_exists(new xmldb_table('local_rtcsync_deliverycm'))) {
            $DB->set_field('local_rtcsync_deliverycm', 'lineagestatus', 0);
            $DB->set_field('local_rtcsync_deliverycm', 'lineagemanifestid', null);
        }
        if ($dbman->table_exists(new xmldb_table('local_rtcsync_deliverycmhist'))) {
            $DB->set_field('local_rtcsync_deliverycmhist', 'lineageverified', 0);
            $DB->set_field('local_rtcsync_deliverycmhist', 'lineagemanifestid', null);
        }

        // A grade-item row is explicit current evidence only when its stable
        // idnumber, course identity, and delivery grouping still agree.
        if ($dbman->table_exists(new xmldb_table('local_rtcsync_deliverygrade'))) {
            $DB->set_field('local_rtcsync_deliverygrade', 'lineageverified', 0);
            $DB->set_field('local_rtcsync_deliverygrade', 'lineagemanifestid', null);
        }

        if ($dbman->table_exists(new xmldb_table('local_rtcsync_deliverycourse'))) {
            $DB->set_field('local_rtcsync_deliverycourse', 'ready', 0);
            $DB->set_field('local_rtcsync_deliverycourse', 'lastcmid', 0);
            $DB->set_field('local_rtcsync_deliverycourse', 'timemodified', time());
            foreach ($DB->get_records('local_rtcsync_deliverycourse', null, 'courseid ASC', 'courseid') as $state) {
                $DB->set_field('course', 'visible', 0, ['id' => (int) $state->courseid]);
            }
        }

        upgrade_plugin_savepoint(true, 2026102800, 'local', 'rtcsync');
    }

    if ($oldversion < 2026102900) {
        $dbman = $DB->get_manager();
        $audit = new xmldb_table('local_rtcsync_lineageaudit');
        if ($dbman->table_exists($audit)) {
            // Older imports have no trustworthy apply-result snapshot. Keep the
            // field nullable so the verifier reports those imports as incomplete.
            $resultstate = new xmldb_field('resultstate', XMLDB_TYPE_TEXT, null, null, null, null, null, 'entryhash');
            if (!$dbman->field_exists($audit, $resultstate)) {
                $dbman->add_field($audit, $resultstate);
            }
        }
        upgrade_plugin_savepoint(true, 2026102900, 'local', 'rtcsync');
    }

    if ($oldversion < 2026103000) {
        $dbman = $DB->get_manager();
        $manifest = new xmldb_table('local_rtcsync_linemanifest');
        if ($dbman->table_exists($manifest)) {
            // Existing import records have no artifact-verification evidence.
            // Keep the digest nullable and the verified marker false; do not
            // infer verification from the manifest's declared source hash.
            $sourcefilehash = new xmldb_field('sourcefilehash', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'sourcehash');
            if (!$dbman->field_exists($manifest, $sourcefilehash)) {
                $dbman->add_field($manifest, $sourcefilehash);
            }
            $sourcefileverified = new xmldb_field(
                'sourcefileverified', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'sourcefilehash'
            );
            if (!$dbman->field_exists($manifest, $sourcefileverified)) {
                $dbman->add_field($manifest, $sourcefileverified);
            }
            $sourcefileverifiedat = new xmldb_field(
                'sourcefileverifiedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'sourcefileverified'
            );
            if (!$dbman->field_exists($manifest, $sourcefileverifiedat)) {
                $dbman->add_field($manifest, $sourcefileverifiedat);
            }
        }
        upgrade_plugin_savepoint(true, 2026103000, 'local', 'rtcsync');
    }

    if ($oldversion < 2026103100) {
        $dbman = $DB->get_manager();
        $manifest = new xmldb_table('local_rtcsync_linemanifest');
        if ($dbman->table_exists($manifest)) {
            $manifestjson = new xmldb_field(
                'manifestjson', XMLDB_TYPE_TEXT, null, null, null, null, null, 'manifesthash'
            );
            if (!$dbman->field_exists($manifest, $manifestjson)) {
                $dbman->add_field($manifest, $manifestjson);
            }
            $parentmanifestid = new xmldb_field(
                'parentmanifestid', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'sourcefileverifiedat'
            );
            $applyplanhash = new xmldb_field(
                'applyplanhash', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'sourcefileverifiedat'
            );
            if (!$dbman->field_exists($manifest, $applyplanhash)) {
                $dbman->add_field($manifest, $applyplanhash);
            }
            if (!$dbman->field_exists($manifest, $parentmanifestid)) {
                $dbman->add_field($manifest, $parentmanifestid);
            }
            $parentmanifesthash = new xmldb_field(
                'parentmanifesthash', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'parentmanifestid'
            );
            if (!$dbman->field_exists($manifest, $parentmanifesthash)) {
                $dbman->add_field($manifest, $parentmanifesthash);
            }
            $parentindex = new xmldb_index('parent_ix', XMLDB_INDEX_NOTUNIQUE, ['parentmanifestid']);
            if (!$dbman->index_exists($manifest, $parentindex)) {
                $dbman->add_index($manifest, $parentindex);
            }
        }

        $audit = new xmldb_table('local_rtcsync_lineageaudit');
        if ($dbman->table_exists($audit)) {
            $parententryhash = new xmldb_field('parententryhash', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'resultstate');
            if (!$dbman->field_exists($audit, $parententryhash)) {
                $dbman->add_field($audit, $parententryhash);
            }
            $parentresultstate = new xmldb_field('parentresultstate', XMLDB_TYPE_TEXT, null, null, null, null, null, 'parententryhash');
            if (!$dbman->field_exists($audit, $parentresultstate)) {
                $dbman->add_field($audit, $parentresultstate);
            }
        }
        upgrade_plugin_savepoint(true, 2026103100, 'local', 'rtcsync');
    }

    return true;
}
