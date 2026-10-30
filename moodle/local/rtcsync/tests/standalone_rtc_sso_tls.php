<?php

declare(strict_types=1);

// This regression checks TLS options, strict origin selection, and call-site
// policy without Moodle bootstrap, HTTP requests, services, or databases.
define('MOODLE_INTERNAL', true);
require_once dirname(__DIR__, 2) . '/rtc_sso.php';

function rtc_sso_tls_expect_same(mixed $expected, mixed $actual, string $case): void {
    if ($expected !== $actual) {
        throw new RuntimeException($case . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}

$options = rtc_sso_secure_curl_options([
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_RETURNTRANSFER => true,
]);

rtc_sso_tls_expect_same(true, $options[CURLOPT_SSL_VERIFYPEER] ?? null, 'TLS peer verification');
rtc_sso_tls_expect_same(2, $options[CURLOPT_SSL_VERIFYHOST] ?? null, 'TLS hostname verification');
rtc_sso_tls_expect_same(false, $options[CURLOPT_FOLLOWLOCATION] ?? null, 'redirect following');
rtc_sso_tls_expect_same(
    'rtc-user:42',
    rtc_sso_stable_user_idnumber(['id' => 42]),
    'canonical stable RTC user idnumber'
);
rtc_sso_tls_expect_same(
    null,
    rtc_sso_stable_user_idnumber(['id' => 0]),
    'invalid RTC user identity rejection'
);
rtc_sso_tls_expect_same(
    true,
    rtc_sso_moodle_user_can_login((object) ['auth' => 'manual', 'deleted' => 0, 'suspended' => 0]),
    'active local Moodle account eligibility'
);
rtc_sso_tls_expect_same(
    false,
    rtc_sso_moodle_user_can_login((object) ['auth' => 'manual', 'deleted' => 0, 'suspended' => 1]),
    'suspended local Moodle account rejection'
);
rtc_sso_tls_expect_same(
    false,
    rtc_sso_moodle_user_can_login((object) ['auth' => 'manual', 'deleted' => 1, 'suspended' => 0]),
    'deleted local Moodle account rejection'
);
rtc_sso_tls_expect_same(
    false,
    rtc_sso_moodle_user_can_login((object) ['auth' => 'nologin', 'deleted' => 0, 'suspended' => 0]),
    'nologin local Moodle account rejection'
);
rtc_sso_tls_expect_same(
    false,
    rtc_sso_moodle_user_can_login((object) ['auth' => 'manual']),
    'incomplete local Moodle account rejection'
);

putenv('RTC_API_BASE_URL=https://api.staging.example.test:8443');
putenv('RTC_SSO_STRICT_API_BASE_URLS=true');
putenv('GATEWAY_API_DOMAIN=https://api.staging.example.test');
putenv('RTC_SSO_SITE_REGISTRY=legacy|legacy-api.staging.example.test|student@example.test');
putenv('CONTAINER_PREFIX=legacy');
$expectedOrigins = ['https://api.staging.example.test:8443'];
rtc_sso_tls_expect_same($expectedOrigins, rtc_sso_api_base_urls(), 'strict staging identity origins');
rtc_sso_tls_expect_same($expectedOrigins, rtc_sso_primary_api_base_urls(), 'strict staging login/logout origins');
putenv('RTC_API_BASE_URL');
putenv('RTC_SSO_STRICT_API_BASE_URLS');
putenv('GATEWAY_API_DOMAIN');
putenv('RTC_SSO_SITE_REGISTRY');
putenv('CONTAINER_PREFIX');

$sourcepath = dirname(__DIR__, 2) . '/rtc_sso.php';
$source = file_get_contents($sourcepath);
if ($source === false) {
    throw new RuntimeException('Unable to read RTC SSO source for the static contract check.');
}

foreach (['rtc_sso_fetch_user_detail', 'rtc_sso_fetch_login_token', 'rtc_sso_revoke_token'] as $function) {
    $start = strpos($source, 'function ' . $function . '(');
    if ($start === false) {
        throw new RuntimeException('Missing function: ' . $function);
    }

    $next = strpos($source, "\nfunction ", $start + 1);
    $body = substr($source, $start, $next === false ? null : $next - $start);
    if (!preg_match('/curl_setopt_array\s*\(\s*\$ch\s*,\s*rtc_sso_secure_curl_options\s*\(/', $body)) {
        throw new RuntimeException($function . ' does not use the shared secure cURL options.');
    }
}

$autologinstart = strpos($source, 'function rtc_sso_autologin_to_moodle(');
$autologinnext = strpos($source, "\nfunction ", $autologinstart + 1);
$autologinbody = substr($source, $autologinstart, $autologinnext === false ? null : $autologinnext - $autologinstart);
$initialaccountcheck = strpos($autologinbody, 'if ($muser && !rtc_sso_moodle_user_can_login($muser))');
$userupdate = strpos($autologinbody, "\$stage = 'update Moodle user';");
$finalaccountcheck = strrpos($autologinbody, 'if (!rtc_sso_moodle_user_can_login($muser))');
$sessioncompletion = strpos($autologinbody, 'complete_user_login($muser);');
if (!str_contains($autologinbody, '$newuser->idnumber = $stableidnumber;')
        || !str_contains($autologinbody, 'managed.idnumber = :idnumber')
        || !str_contains($autologinbody, 'stable RTC identity maps to multiple Moodle users')
        || $initialaccountcheck === false
        || $userupdate === false
        || $initialaccountcheck > $userupdate
        || $finalaccountcheck === false
        || $sessioncompletion === false
        || $finalaccountcheck > $sessioncompletion) {
    throw new RuntimeException('RTC SSO must create and resolve Moodle accounts through the stable RTC user mapping.');
}

$templatepath = dirname(__DIR__, 4) . '/docker/moodle-staging-config.template.php';
$template = file_get_contents($templatepath);
if ($template === false ||
        !str_contains($template, "putenv('RTC_SSO_STRICT_API_BASE_URLS=true');") ||
        !str_contains($template, "putenv('GATEWAY_API_DOMAIN=' . @@RTC_API_BASE_URL@@);")) {
    throw new RuntimeException('Generated staging config must keep SSO restricted to the complete configured API origin.');
}

fwrite(STDOUT, "RTC SSO TLS contract checks passed.\n");
