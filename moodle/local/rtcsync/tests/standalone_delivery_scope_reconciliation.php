<?php

declare(strict_types=1);

// Static contract regression; intentionally avoids Moodle bootstrap and DB.
$sourcepath = dirname(__DIR__) . '/classes/local/delivery_activity_access.php';
$source = file_get_contents($sourcepath);
if ($source === false) {
    throw new RuntimeException('Unable to read delivery activity access source.');
}

function delivery_scope_contract_expect(bool $condition, string $case): void {
    if (!$condition) {
        throw new RuntimeException('Delivery scope contract failed: ' . $case);
    }
}

function delivery_scope_contract_region(string $source, string $start, string $end): string {
    $startposition = strpos($source, $start);
    $endposition = strpos($source, $end, $startposition === false ? 0 : $startposition + strlen($start));
    if ($startposition === false || $endposition === false) {
        throw new RuntimeException('Unable to locate delivery scope contract methods.');
    }
    return preg_replace('/\s+/', ' ', substr($source, $startposition, $endposition - $startposition));
}

$requestreconcile = delivery_scope_contract_region(
    $source,
    'public static function reconcile_user_delivery_group_memberships(',
    'public static function reconcile_course_delivery_group_memberships_locked('
);
delivery_scope_contract_expect(
    strpos($requestreconcile, 'self::with_course_access_lock(') !== false,
    'page reconciliation must serialize with scope and roster updates'
);
delivery_scope_contract_expect(
    strpos($requestreconcile, 'reconcile_course_delivery_group_memberships_locked(') !== false,
    'page reconciliation must use the lock-held path'
);
delivery_scope_contract_expect(
    strpos($requestreconcile, 'deliveryids') === false,
    'page reconciliation must not carry a stale decoded scope outside the lock'
);

$lockedreconcile = delivery_scope_contract_region(
    $source,
    'public static function reconcile_course_delivery_group_memberships_locked(',
    'private static function reconcile_delivery_group_memberships_for_scope('
);
delivery_scope_contract_expect(
    strpos($lockedreconcile, "get_record('local_rtcsync_studentscope'") !== false,
    'the locked reconciliation must reread the authoritative scope'
);

$stage = delivery_scope_contract_region(
    $source,
    'public static function stage_delivery_group_roster(',
    'private static function apply_staged_delivery_group_memberships('
);
delivery_scope_contract_expect(
    strpos($stage, 'self::is_active_manual_student(') !== false
        && strpos($stage, 'self::student_delivery_scope_allows(') !== false,
    'roster writes must require active manual Student enrolment and current delivery scope'
);

$apply = delivery_scope_contract_region(
    $source,
    'private static function apply_staged_delivery_group_memberships(',
    'private static function reconcile_staged_delivery_group_memberships('
);
delivery_scope_contract_expect(
    strpos($apply, 'self::is_active_manual_student(') !== false
        && strpos($apply, 'in_array((int) $groupingmatch[1], $deliveryids, true)') !== false,
    'staged application must recheck active enrolment and current scope'
);

$externalsource = file_get_contents(dirname(__DIR__) . '/externallib.php');
if ($externalsource === false) {
    throw new RuntimeException('Unable to read RTC external API source.');
}
$scopeapi = delivery_scope_contract_region(
    $externalsource,
    'public static function sync_student_delivery_scope(array $access)',
    'public static function sync_student_delivery_scope_returns()'
);
delivery_scope_contract_expect(
    strpos($scopeapi, '::with_course_access_lock(') !== false
        && strpos($scopeapi, 'sync_student_delivery_scope_locked(') !== false,
    'scope replacement must remain serialized under the course access lock'
);
$enrolapi = delivery_scope_contract_region(
    $externalsource,
    'public static function enrol_user(array $enrolment)',
    'public static function enrol_user_returns()'
);
delivery_scope_contract_expect(
    strpos($enrolapi, '::with_ready_course(') !== false
        && strpos($enrolapi, 'reconcile_course_delivery_group_memberships_locked(') !== false,
    'active enrolment must apply current staged rosters under the ready-course lock'
);

$historyapi = delivery_scope_contract_region(
    $externalsource,
    'public static function sync_history_marker_parameters()',
    'public static function sync_history_marker_returns()'
);
delivery_scope_contract_expect(
    strpos($historyapi, 'expectedscoperevision') !== false
        && strpos($historyapi, "'scoperevision'") !== false,
    'history marker API must accept an expected scope revision and return the effective revision'
);
delivery_scope_contract_expect(
    substr_count($externalsource, "'expectedscoperevision' => new external_value") === 3
        && strpos($externalsource, "'historyapplied' => new external_value") !== false,
    'enrol and unenrol marker side paths must use the same revision guard and expose its outcome'
);
$unenrolapi = delivery_scope_contract_region(
    $externalsource,
    'public static function unenrol_user(array $enrolment)',
    'public static function unenrol_user_returns()'
);
$markerapi = delivery_scope_contract_region(
    $externalsource,
    'public static function sync_history_marker(array $history)',
    'public static function sync_history_marker_returns()'
);
delivery_scope_contract_expect(
    strpos($enrolapi, '$expectedscoperevision') !== false
        && strpos($enrolapi, '\'historyapplied\' => (int) $historyresult[\'applied\']') !== false
        && strpos($unenrolapi, '$expectedscoperevision') !== false
        && strpos($unenrolapi, '\'historyapplied\' => (int) $historyresult[\'applied\']') !== false
        && strpos($markerapi, '$expectedscoperevision') !== false
        && strpos($markerapi, '\'applied\' => (int) $result[\'applied\']') !== false,
    'all three history mutation routes must forward expected revisions and report the guarded result'
);

$historysource = file_get_contents(dirname(__DIR__) . '/classes/local/student_history.php');
if ($historysource === false) {
    throw new RuntimeException('Unable to read student history source.');
}
$historyclear = delivery_scope_contract_region(
    $historysource,
    'public static function sync_marker_locked(',
    'public function require_current_rtc_student('
);
delivery_scope_contract_expect(
    strpos($historyclear, '$expectedscoperevision !== $scopestate[\'revision\']') !== false
        && strpos($historyclear, '$expectedscoperevision <= 0') !== false
        && strpos($historyclear, '!in_array($deliveryid, $scopestate[\'deliveryids\'], true)') !== false
        && strpos($historyclear, "'applied' => 0") !== false,
    'delivery marker clears must fail closed on omitted or stale revisions and out-of-scope deliveries'
);
delivery_scope_contract_expect(
    strpos($historyclear, 'if ($historyaccess === 1)') !== false
        && strpos($historyclear, '$expectedscoperevision !==') !== false
        && strpos($historyclear, 'if ($historyaccess === 1)') < strpos($historyclear, '$expectedscoperevision !=='),
    'source history grants must remain independent of scope clear revisions'
);
$accesssource = file_get_contents(dirname(__DIR__) . '/classes/local/delivery_activity_access.php');
if ($accesssource === false) {
    throw new RuntimeException('Unable to read delivery activity access source.');
}
delivery_scope_contract_expect(
    strpos($accesssource, 'public const MIN_STUDENT_SCOPE_REVISION = 2026102505;') !== false
        && strpos($accesssource, "'revision, deliveryids', IGNORE_MISSING") !== false
        && strpos($accesssource, '(int) $scope->revision < self::MIN_STUDENT_SCOPE_REVISION') !== false
        && strpos($historysource, 'delivery_activity_access::MIN_STUDENT_SCOPE_REVISION') !== false,
    'activity, roster, and history readers must reject stored scopes below the supported revision floor'
);
delivery_scope_contract_expect(
    substr_count($externalsource, "'expectedscoperevision' => new external_value(PARAM_INT") === 3
        && substr_count($externalsource, 'VALUE_DEFAULT, 0)') >= 3,
    'omitted scope revisions must default to zero because valid scope revisions are positive'
);

$version = file_get_contents(dirname(__DIR__) . '/version.php');
$readme = file_get_contents(dirname(__DIR__) . '/README.md');
$preflightapi = delivery_scope_contract_region(
    $externalsource,
    'public static function prepare_delivery_course(int $courseid)',
    'public static function prepare_delivery_course_returns()'
);
delivery_scope_contract_expect(
    $version !== false && strpos($version, '$plugin->version = 2026103101;') !== false
        && $readme !== false && strpos($readme, '`2026103101`') !== false
        && strpos($externalsource, 'MIN_REVISIONED_HISTORY_SCOPE_VERSION = 2026103101;') !== false
        && strpos($preflightapi, 'MIN_REVISIONED_HISTORY_SCOPE_VERSION') !== false,
    'the published Backend minimum must match the installed plugin contract version'
);

$installxml = file_get_contents(dirname(__DIR__) . '/db/install.xml');
$upgradesource = file_get_contents(dirname(__DIR__) . '/db/upgrade.php');
if ($installxml === false || $upgradesource === false) {
    throw new RuntimeException('Unable to read delivery-scope schema sources.');
}
delivery_scope_contract_expect(
    strpos($installxml, 'INDEX NAME="usercourse_ix" UNIQUE="false" FIELDS="userid,courseid"') !== false,
    'fresh installs must index page-level user scope lookups'
);
delivery_scope_contract_expect(
    strpos($upgradesource, "new xmldb_index('usercourse_ix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid'])") !== false,
    'upgrades must add the reverse scope index'
);
delivery_scope_contract_expect(
    strpos($upgradesource, 'if ($oldversion < 2026103101)') !== false
        && strpos($upgradesource, "upgrade_plugin_savepoint(true, 2026103101, 'local', 'rtcsync')") !== false,
    'the revision-aware API release must advance installed plugin versions without changing schema'
);

echo "Standalone delivery-scope reconciliation checks passed.\n";
