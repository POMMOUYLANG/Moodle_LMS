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
        foreach ([
            ['local_rtcsync_deliverycm', 'lineagestatus'],
            ['local_rtcsync_deliverycm', 'lineagemanifestid'],
            ['local_rtcsync_deliverycmhist', 'lineageverified'],
            ['local_rtcsync_deliverycmhist', 'lineagemanifestid'],
            ['local_rtcsync_deliverygrade', 'lineageverified'],
            ['local_rtcsync_deliverygrade', 'lineagemanifestid'],
        ] as [$tablename, $fieldname]) {
            $this->assertNotNull($schema->getTable($tablename)->getField($fieldname), $tablename . '.' . $fieldname);
        }
        $this->assertNull($schema->getTable('local_rtcsync_formcfg')->getField('lineagestatus'));
        $this->assertNull($schema->getTable('local_rtcsync_formcfg')->getField('lineagemanifestid'));
        $this->assertNotNull($schema->getTable('local_rtcsync_linemanifest'));
        foreach ([
            'manifestjson', 'sourcefilehash', 'sourcefileverified', 'sourcefileverifiedat', 'applyplanhash',
            'parentmanifestid', 'parentmanifesthash',
        ] as $fieldname) {
            $this->assertNotNull($schema->getTable('local_rtcsync_linemanifest')->getField($fieldname));
        }
        $this->assertNotNull($schema->getTable('local_rtcsync_lineageaudit'));
        foreach (['resultstate', 'parententryhash', 'parentresultstate'] as $fieldname) {
            $this->assertNotNull($schema->getTable('local_rtcsync_lineageaudit')->getField($fieldname));
        }
        $this->assertNotNull($schema->getTable('local_rtcsync_linemanifest')->getIndex('parent_ix'));
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

        // A canonical current item and matching grouping still cannot prove
        // that its stored grade values belong to one delivery at transition.
        $course = $this->getDataGenerator()->create_course([
            'idnumber' => 'rtc-subject:' . random_int(700000, 799999),
            'visible' => 1,
        ]);
        $deliveryid = random_int(800000, 899999);
        $grouping = $this->getDataGenerator()->create_grouping([
            'courseid' => (int) $course->id,
            'name' => 'Upgrade lineage grouping',
            'idnumber' => 'rtc-delivery-grouping:' . $deliveryid,
        ]);
        $subjectid = substr((string) $course->idnumber, strlen('rtc-subject:'));
        $item = new \grade_item([
            'courseid' => (int) $course->id,
            'itemname' => 'Existing delivery grade',
            'itemtype' => 'manual',
            'itemmodule' => null,
            'iteminstance' => null,
            'itemnumber' => 0,
            'idnumber' => 'rtc-subject-score:' . $subjectid . ':delivery:' . $deliveryid,
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
        ], false);
        $item->insert('upgrade-test');
        $student = $this->getDataGenerator()->create_user();
        $item->update_final_grade((int) $student->id, 77.0, 'upgrade-test');
        $module = $this->getDataGenerator()->create_module('quiz', [
            'course' => (int) $course->id,
            'name' => 'Existing delivery activity',
            'grade' => 100,
        ]);
        $cmid = (int) $module->cmid;
        $DB->set_field('course_modules', 'groupingid', (int) $grouping->id, ['id' => $cmid]);
        $now = time();
        $DB->insert_record('local_rtcsync_deliverycm', (object) [
            'cmid' => $cmid,
            'courseid' => (int) $course->id,
            'deliveryid' => $deliveryid,
            'groupingid' => (int) $grouping->id,
            'lineagestatus' => 1,
            'lineagemanifestid' => null,
            'originalavailability' => null,
            'managedavailability' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('local_rtcsync_deliverygrade', (object) [
            'gradeitemid' => (int) $item->id,
            'courseid' => (int) $course->id,
            'deliveryid' => $deliveryid,
            'timecreated' => $now,
            'timemodified' => $now,
            'lineageverified' => 1,
            'lineagemanifestid' => null,
        ]);

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
        $this->assertSame('2026103100', (string) get_config('local_rtcsync', 'version'));
        $this->assertSame(0, (int) $DB->get_field('local_rtcsync_deliverycm', 'lineagestatus', ['cmid' => $cmid]));
        $this->assertSame(0, (int) $DB->get_field('local_rtcsync_deliverygrade', 'lineageverified', ['gradeitemid' => (int) $item->id]));

        // A site already at v2026102800 receives a nullable result snapshot field;
        // historical imports remain explicitly unverifiable until re-imported.
        $audit = new \xmldb_table('local_rtcsync_lineageaudit');
        $resultstatefield = new \xmldb_field('resultstate');
        $this->assertTrue($dbman->field_exists($audit, $resultstatefield));
        $dbman->drop_field($audit, $resultstatefield);
        set_config('version', '2026102800', 'local_rtcsync');
        $this->assertTrue(xmldb_local_rtcsync_upgrade(2026102800));
        $this->assertSame('2026103100', (string) get_config('local_rtcsync', 'version'));
        $this->assertTrue($dbman->field_exists($audit, $resultstatefield));

        // A v2026102900 import must not gain fabricated file-verification evidence.
        $import = $DB->insert_record('local_rtcsync_linemanifest', (object) [
            'manifestid' => 'upgrade-old-import',
            'manifesthash' => hash('sha256', 'old-manifest'),
            'sourcehash' => hash('sha256', 'declared-source'),
            'courseid' => (int) $course->id,
            'courseidnumber' => (string) $course->idnumber,
            'sourceversion' => 2026102700,
            'approvedby' => 'upgrade-test-owner',
            'approvalref' => 'upgrade-test-reference',
            'approvedat' => $now,
            'actorid' => 0,
            'operator' => 'upgrade-test-operator',
            'entrycount' => 1,
            'timecreated' => $now,
        ]);
        $manifesttable = new \xmldb_table('local_rtcsync_linemanifest');
        foreach (['sourcefilehash', 'sourcefileverified', 'sourcefileverifiedat'] as $fieldname) {
            $dbman->drop_field($manifesttable, new \xmldb_field($fieldname));
        }
        set_config('version', '2026102900', 'local_rtcsync');
        $this->assertTrue(xmldb_local_rtcsync_upgrade(2026102900));
        $this->assertSame('2026103100', (string) get_config('local_rtcsync', 'version'));
        $this->assertNull($DB->get_field('local_rtcsync_linemanifest', 'sourcefilehash', ['id' => $import]));
        $this->assertSame(0, (int) $DB->get_field('local_rtcsync_linemanifest', 'sourcefileverified', ['id' => $import]));
        $this->assertSame(0, (int) $DB->get_field('local_rtcsync_linemanifest', 'sourcefileverifiedat', ['id' => $import]));
        $this->assertNull($DB->get_field('local_rtcsync_linemanifest', 'applyplanhash', ['id' => $import]));
        $this->assertNull($DB->get_field('local_rtcsync_linemanifest', 'manifestjson', ['id' => $import]));

        // A v2026103000 import has no amendment parent. The v2026103100 DDL
        // must add chain metadata without inventing a parent or parent record.
        $parentindex = new \xmldb_index('parent_ix', XMLDB_INDEX_NOTUNIQUE, ['parentmanifestid']);
        if ($dbman->index_exists($manifesttable, $parentindex)) {
            $dbman->drop_index($manifesttable, $parentindex);
        }
        foreach (['manifestjson', 'applyplanhash', 'parentmanifestid', 'parentmanifesthash'] as $fieldname) {
            $dbman->drop_field($manifesttable, new \xmldb_field($fieldname));
        }
        $audittable = new \xmldb_table('local_rtcsync_lineageaudit');
        foreach (['parententryhash', 'parentresultstate'] as $fieldname) {
            $dbman->drop_field($audittable, new \xmldb_field($fieldname));
        }
        set_config('version', '2026103000', 'local_rtcsync');
        $this->assertTrue(xmldb_local_rtcsync_upgrade(2026103000));
        $this->assertSame('2026103100', (string) get_config('local_rtcsync', 'version'));
        $this->assertNull($DB->get_field('local_rtcsync_linemanifest', 'parentmanifestid', ['id' => $import]));
        $this->assertNull($DB->get_field('local_rtcsync_linemanifest', 'parentmanifesthash', ['id' => $import]));
        foreach (['manifestjson', 'applyplanhash', 'parentmanifestid', 'parentmanifesthash'] as $fieldname) {
            $this->assertTrue($dbman->field_exists($manifesttable, new \xmldb_field($fieldname)));
        }
        foreach (['parententryhash', 'parentresultstate'] as $fieldname) {
            $this->assertTrue($dbman->field_exists($audittable, new \xmldb_field($fieldname)));
        }
        $this->assertTrue($dbman->index_exists($manifesttable, $parentindex));

        // Recreate transition-era mappings, then remove only lineage schema to
        // exercise the 2026102600 -> 2026103100 path, including v2026102700 repair.
        $legacyhistorydeliveryid = $deliveryid + 1;
        $DB->insert_record('local_rtcsync_deliverycmhist', (object) [
            'cmid' => $cmid,
            'courseid' => (int) $course->id,
            'deliveryid' => $legacyhistorydeliveryid,
            'timecreated' => $now,
            'lineageverified' => 1,
            'lineagemanifestid' => 'pre-transition-manifest',
        ]);
        $DB->set_field('local_rtcsync_deliverycm', 'lineagestatus', 1, ['cmid' => $cmid]);
        $DB->set_field('local_rtcsync_deliverycm', 'lineagemanifestid', 'pre-transition-manifest', ['cmid' => $cmid]);
        $DB->set_field('local_rtcsync_deliverygrade', 'lineageverified', 1, ['gradeitemid' => (int) $item->id]);
        $DB->set_field('local_rtcsync_deliverygrade', 'lineagemanifestid', 'pre-transition-manifest', [
            'gradeitemid' => (int) $item->id,
        ]);
        $DB->insert_record('local_rtcsync_deliverycourse', (object) [
            'courseid' => (int) $course->id,
            'coursevisible' => 1,
            'ready' => 1,
            'lastcmid' => $cmid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->set_field('course', 'visible', 1, ['id' => (int) $course->id]);

        foreach ([
            ['local_rtcsync_deliverycm', 'lineagestatus'],
            ['local_rtcsync_deliverycm', 'lineagemanifestid'],
            ['local_rtcsync_deliverycmhist', 'lineageverified'],
            ['local_rtcsync_deliverycmhist', 'lineagemanifestid'],
            ['local_rtcsync_deliverygrade', 'lineageverified'],
            ['local_rtcsync_deliverygrade', 'lineagemanifestid'],
        ] as [$tablename, $fieldname]) {
            $table = new \xmldb_table($tablename);
            $field = new \xmldb_field($fieldname);
            if ($dbman->field_exists($table, $field)) {
                $dbman->drop_field($table, $field);
            }
        }
        foreach (['local_rtcsync_linemanifest', 'local_rtcsync_lineageaudit'] as $tablename) {
            $table = new \xmldb_table($tablename);
            if ($dbman->table_exists($table)) {
                $dbman->drop_table($table);
            }
        }
        $this->assertFalse($dbman->field_exists(
            new \xmldb_table('local_rtcsync_deliverycm'),
            new \xmldb_field('lineagestatus')
        ));
        $this->assertFalse($dbman->table_exists(new \xmldb_table('local_rtcsync_linemanifest')));

        // Model a partial 2026102600 schema where same-column non-unique indexes cause
        // XMLDB add_key() to skip the unique index required for each mapping.
        foreach ($deliverymappingindexes as [$tablename, $field]) {
            $table = new \xmldb_table($tablename);
            foreach ($DB->get_indexes($tablename) as $indexname => $index) {
                if (array_values($index['columns'] ?? []) !== [$field]) {
                    continue;
                }
                $indextype = (bool) ($index['unique'] ?? false)
                    ? XMLDB_INDEX_UNIQUE
                    : XMLDB_INDEX_NOTUNIQUE;
                $dbman->drop_index($table, new \xmldb_index($indexname, $indextype, [$field]));
            }

            $partialindexname = 'partial_' . $field . '_ix';
            $dbman->add_index(
                $table,
                new \xmldb_index($partialindexname, XMLDB_INDEX_NOTUNIQUE, [$field])
            );
            $this->assertSame(0, $this->count_indexes_with_columns($tablename, [$field], true), $tablename);
            $this->assertSame(1, $this->count_indexes_with_columns($tablename, [$field], false), $tablename);
        }

        set_config('version', '2026102600', 'local_rtcsync');
        $this->assertTrue(xmldb_local_rtcsync_upgrade(2026102600));
        $this->assertSame('2026103100', (string) get_config('local_rtcsync', 'version'));
        foreach ($deliverymappingindexes as [$tablename, $field]) {
            $this->assertSame(1, $this->count_indexes_with_columns($tablename, [$field], true), $tablename);
        }
        $this->assertTrue($dbman->field_exists(
            new \xmldb_table('local_rtcsync_deliverycm'),
            new \xmldb_field('lineagestatus')
        ));
        $this->assertTrue($dbman->table_exists(new \xmldb_table('local_rtcsync_linemanifest')));
        $this->assertTrue($dbman->table_exists(new \xmldb_table('local_rtcsync_lineageaudit')));
        $this->assertSame(0, (int) $DB->get_field('local_rtcsync_deliverycm', 'lineagestatus', ['cmid' => $cmid]));
        $this->assertNull($DB->get_field('local_rtcsync_deliverycm', 'lineagemanifestid', ['cmid' => $cmid]));
        $this->assertSame(0, (int) $DB->get_field('local_rtcsync_deliverygrade', 'lineageverified', [
            'gradeitemid' => (int) $item->id,
        ]));
        $this->assertNull($DB->get_field('local_rtcsync_deliverygrade', 'lineagemanifestid', [
            'gradeitemid' => (int) $item->id,
        ]));
        $this->assertSame(0, (int) $DB->get_field('local_rtcsync_deliverycmhist', 'lineageverified', [
            'cmid' => $cmid,
            'courseid' => (int) $course->id,
            'deliveryid' => $legacyhistorydeliveryid,
        ]));
        $this->assertNull($DB->get_field('local_rtcsync_deliverycmhist', 'lineagemanifestid', [
            'cmid' => $cmid,
            'courseid' => (int) $course->id,
            'deliveryid' => $legacyhistorydeliveryid,
        ]));
        $state = $DB->get_record('local_rtcsync_deliverycourse', ['courseid' => (int) $course->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $state->ready);
        $this->assertSame(0, (int) $state->lastcmid);
        $this->assertSame(0, (int) $DB->get_field('course', 'visible', ['id' => (int) $course->id]));

        // Re-running from the prior savepoint must recognize the completed DDL and leave the
        // disposable site at the latest schema without duplicate-table/index errors.
        set_config('version', (string) $oldversion, 'local_rtcsync');
        $this->assertTrue(xmldb_local_rtcsync_upgrade($oldversion));
        $this->assertSame('2026103100', (string) get_config('local_rtcsync', 'version'));
        $this->assertTrue($dbman->field_exists(
            new \xmldb_table('local_rtcsync_lineageaudit'),
            new \xmldb_field('resultstate')
        ));
        foreach ([
            'sourcefilehash', 'sourcefileverified', 'sourcefileverifiedat',
            'manifestjson', 'applyplanhash', 'parentmanifestid', 'parentmanifesthash',
        ] as $fieldname) {
            $this->assertTrue($dbman->field_exists(
                new \xmldb_table('local_rtcsync_linemanifest'),
                new \xmldb_field($fieldname)
            ));
        }
        foreach (['parententryhash', 'parentresultstate'] as $fieldname) {
            $this->assertTrue($dbman->field_exists(
                new \xmldb_table('local_rtcsync_lineageaudit'),
                new \xmldb_field($fieldname)
            ));
        }
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
