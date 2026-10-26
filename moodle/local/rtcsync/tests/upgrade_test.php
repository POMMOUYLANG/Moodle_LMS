<?php

namespace local_rtcsync;

use PHPUnit\Framework\Attributes\CoversNothing;

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade regressions that deliberately exercise DDL on the isolated MySQL PHPUnit database.
 *
 * Moodle's MySQL test DB does not roll back DDL. Keep these tests guarded to the repository's
 * dedicated `phpunit-db` / `rtc_moodle_phpunit` configuration, and leave the schema at latest.
 */
#[CoversNothing]
final class upgrade_test extends \advanced_testcase
{
    public function test_upgrade_2026102600_creates_and_recreates_delivery_membership_schema(): void
    {
        global $CFG, $DB;

        $this->assertSame('phpunit-db', (string) $CFG->dbhost);
        $this->assertSame('rtc_moodle_phpunit', (string) $CFG->dbname);
        $this->resetAfterTest();

        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/rtcsync/db/upgrade.php');

        $dbman = $DB->get_manager();
        $studentscope = new \xmldb_table('local_rtcsync_studentscope');
        $usercourseindex = new \xmldb_index(
            'usercourse_ix',
            XMLDB_INDEX_NOTUNIQUE,
            ['userid', 'courseid']
        );
        $deliverymember = new \xmldb_table('local_rtcsync_deliverymember');

        $this->assertTrue($dbman->table_exists($studentscope));
        $this->assertTrue($dbman->table_exists($deliverymember));

        // The current install schema already has these additions. Remove only those two
        // artifacts to model a site upgrading from the immediately prior plugin version.
        $dbman->drop_table($deliverymember);
        if ($dbman->index_exists($studentscope, $usercourseindex)) {
            $dbman->drop_index($studentscope, $usercourseindex);
        }

        $oldversion = 2026102506;
        set_config('version', (string) $oldversion, 'local_rtcsync');
        $this->assertTrue(xmldb_local_rtcsync_upgrade($oldversion));

        $this->assertTrue($dbman->table_exists($deliverymember));
        foreach (['id', 'courseid', 'groupid', 'userid', 'timecreated'] as $field) {
            $this->assertTrue($dbman->field_exists($deliverymember, $field), $field);
        }
        $this->assertTrue($dbman->index_exists(
            $studentscope,
            new \xmldb_index('usercourse_ix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid'])
        ));
        $this->assertTrue($dbman->index_exists(
            $deliverymember,
            new \xmldb_index('groupuser_uix', XMLDB_INDEX_UNIQUE, ['groupid', 'userid'])
        ));
        $this->assertTrue($dbman->index_exists(
            $deliverymember,
            new \xmldb_index('courseuser_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'userid'])
        ));
        $this->assertSame('2026102600', (string) get_config('local_rtcsync', 'version'));

        // Re-running from the prior savepoint must recognize the completed DDL and leave the
        // disposable site at the latest schema without duplicate-table/index errors.
        set_config('version', (string) $oldversion, 'local_rtcsync');
        $this->assertTrue(xmldb_local_rtcsync_upgrade($oldversion));
        $this->assertSame('2026102600', (string) get_config('local_rtcsync', 'version'));
    }
}
