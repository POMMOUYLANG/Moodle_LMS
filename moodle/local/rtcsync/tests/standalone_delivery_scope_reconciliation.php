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

echo "Standalone delivery-scope reconciliation checks passed.\n";
