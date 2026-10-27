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
    public function test_install_schema_uses_noncolliding_foreign_unique_keys(): void
    {
        global $CFG;

        $file = new \xmldb_file($CFG->dirroot . '/local/rtcsync/db/install.xml');
        $this->assertTrue($file->loadXMLStructure());

        $schema = $file->getStructure();
        $expected = [
            ['local_rtcsync_deliverycm', 'cm_fk', ['cmid'], 'cm_uix'],
            ['local_rtcsync_deliverygrade', 'gradeitem_fk', ['gradeitemid'], 'gradeitem_uix'],
            ['local_rtcsync_deliverycourse', 'course_fk', ['courseid'], 'course_uix'],
        ];

        foreach ($expected as [$tablename, $keyname, $fields, $legacyindex]) {
            $table = $schema->getTable($tablename);
            $key = $table->getKey($keyname);
            $this->assertNotNull($key, $tablename);
            $this->assertSame(XMLDB_KEY_FOREIGN_UNIQUE, $key->getType(), $tablename);
            $this->assertSame($fields, $key->getFields(), $tablename);
            $this->assertNull($table->getIndex($legacyindex), $tablename);
        }
    }

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
        $courseuserindex = new \xmldb_index(
            'courseuser_uix',
            XMLDB_INDEX_UNIQUE,
            ['courseid', 'userid']
        );
        $deliverymember = new \xmldb_table('local_rtcsync_deliverymember');

        $this->assertTrue($dbman->table_exists($studentscope));
        $this->assertTrue($dbman->table_exists($deliverymember));

        // Model the prior MySQL schema's separate unique indexes for delivery mappings.
        // Moodle's MySQL generator does not create physical foreign keys, so the old
        // FOREIGN + unique-index and new FOREIGN_UNIQUE forms share this indexed state.
        $deliverymappingindexes = [
            ['local_rtcsync_deliverycm', 'cmid', 'cm_uix'],
            ['local_rtcsync_deliverygrade', 'gradeitemid', 'gradeitem_uix'],
            ['local_rtcsync_deliverycourse', 'courseid', 'course_uix'],
        ];
        foreach ($deliverymappingindexes as [$tablename, $field, $legacyindexname]) {
            $table = new \xmldb_table($tablename);
            foreach ($DB->get_indexes($tablename) as $indexname => $index) {
                if ((bool) ($index['unique'] ?? false)
                        && array_values($index['columns'] ?? []) === [$field]) {
                    $dbman->drop_index(
                        $table,
                        new \xmldb_index($indexname, XMLDB_INDEX_UNIQUE, [$field])
                    );
                }
            }
            $dbman->add_index(
                $table,
                new \xmldb_index($legacyindexname, XMLDB_INDEX_UNIQUE, [$field])
            );
            $this->assertSame(1, $this->count_indexes_with_columns($tablename, [$field], true));
        }

        // Model the prior schema with only its course-first unique index. XMLDB drop_index()
        // removes every index with the same column set, regardless of order or uniqueness.
        $dbman->drop_table($deliverymember);
        if ($dbman->index_exists($studentscope, $usercourseindex)) {
            $dbman->drop_index($studentscope, $usercourseindex);
        }
        $dbman->add_index($studentscope, $courseuserindex);
        $this->assertTrue($this->has_index_with_columns('local_rtcsync_studentscope', ['courseid', 'userid'], true));
        $this->assertFalse($this->has_index_with_columns('local_rtcsync_studentscope', ['userid', 'courseid'], false));

        $oldversion = 2026102506;
        set_config('version', (string) $oldversion, 'local_rtcsync');
        $this->assertTrue(xmldb_local_rtcsync_upgrade($oldversion));

        $this->assertTrue($dbman->table_exists($deliverymember));
        foreach (['id', 'courseid', 'groupid', 'userid', 'timecreated'] as $field) {
            $this->assertTrue($dbman->field_exists($deliverymember, $field), $field);
        }
        $this->assertTrue($this->has_index_with_columns('local_rtcsync_studentscope', ['userid', 'courseid'], false));
        $this->assertTrue($this->has_index_with_columns('local_rtcsync_studentscope', ['courseid', 'userid'], true));
        $this->assertTrue($dbman->index_exists(
            $deliverymember,
            new \xmldb_index('groupuser_uix', XMLDB_INDEX_UNIQUE, ['groupid', 'userid'])
        ));
        $this->assertTrue($dbman->index_exists(
            $deliverymember,
            new \xmldb_index('courseuser_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'userid'])
        ));
        foreach ([
            ['local_rtcsync_deliverycm', 'cmid'],
            ['local_rtcsync_deliverygrade', 'gradeitemid'],
            ['local_rtcsync_deliverycourse', 'courseid'],
        ] as [$tablename, $field]) {
            $this->assertSame(1, $this->count_indexes_with_columns($tablename, [$field], true), $tablename);
        }
        $this->assertSame('2026102700', (string) get_config('local_rtcsync', 'version'));

        // A site already at 2026102600 receives only the schema repair savepoint.
        set_config('version', '2026102600', 'local_rtcsync');
        $this->assertTrue(xmldb_local_rtcsync_upgrade(2026102600));
        $this->assertSame('2026102700', (string) get_config('local_rtcsync', 'version'));

        // Re-running from the prior savepoint must recognize the completed DDL and leave the
        // disposable site at the latest schema without duplicate-table/index errors.
        set_config('version', (string) $oldversion, 'local_rtcsync');
        $this->assertTrue(xmldb_local_rtcsync_upgrade($oldversion));
        $this->assertSame('2026102700', (string) get_config('local_rtcsync', 'version'));
        foreach ($deliverymappingindexes as [$tablename, $field]) {
            $this->assertSame(1, $this->count_indexes_with_columns($tablename, [$field], true), $tablename);
        }
    }

    private function count_indexes_with_columns(string $table, array $columns, bool $unique): int
    {
        global $DB;

        $count = 0;
        foreach ($DB->get_indexes($table) as $index) {
            if ((bool) ($index['unique'] ?? false) === $unique
                && array_values($index['columns'] ?? []) === $columns) {
                $count++;
            }
        }

        return $count;
    }

    private function has_index_with_columns(string $table, array $columns, bool $unique): bool
    {
        global $DB;

        foreach ($DB->get_indexes($table) as $index) {
            if ((bool) ($index['unique'] ?? false) === $unique
                && array_values($index['columns'] ?? []) === $columns) {
                return true;
            }
        }

        return false;
    }
}
