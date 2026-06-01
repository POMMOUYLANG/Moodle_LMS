<?php
// RTC token-based Moodle SSO bootstrap.

defined('MOODLE_INTERNAL') || die();

function rtc_sso_log_debug(string $message): void
{
    global $CFG;

    $debugfile = !empty($CFG->dataroot) ? $CFG->dataroot . '/rtc_sso_debug.log' : '/tmp/rtc_sso_debug.log';
    @file_put_contents($debugfile, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
}

function rtc_sso_env(string $name, string $default = ''): string
{
    $value = getenv($name);
    return $value !== false && $value !== '' ? $value : $default;
}

function rtc_sso_add_api_base_url(array &$urls, string $url): void
{
    $url = rtrim(trim($url), '/');
    if ($url !== '') {
        $urls[] = $url;
    }
}

function rtc_sso_site_registry(): array
{
    $sites = [];
    $entries = preg_split('/\s*,\s*/', rtc_sso_env('RTC_SSO_SITE_REGISTRY', ''), -1, PREG_SPLIT_NO_EMPTY);

    foreach ($entries as $entry) {
        [$prefix, $api, $emaildomain] = array_pad(explode('|', $entry, 3), 3, '');
        $prefix = trim($prefix);
        $api = trim($api);
        $emaildomain = core_text::strtolower(trim($emaildomain));

        if (
            !preg_match('/^[a-z0-9][a-z0-9-]*$/', $prefix) ||
            $api === '' ||
            !validate_email('user@' . $emaildomain)
        ) {
            rtc_sso_log_debug("Skipping invalid RTC SSO registry entry: {$entry}");
            continue;
        }

        $sites[] = [
            'prefix' => $prefix,
            'api' => $api,
            'emaildomain' => $emaildomain,
        ];
    }

    return $sites;
}

function rtc_sso_api_base_urls(): array
{
    $urls = [];
    rtc_sso_add_api_base_url($urls, rtc_sso_env('RTC_API_BASE_URL', ''));

    $sites = rtc_sso_site_registry();
    $prefixes = [rtc_sso_env('CONTAINER_PREFIX', '')];
    foreach ($sites as $site) {
        $prefixes[] = $site['prefix'];
    }

    foreach (array_unique(array_filter($prefixes)) as $prefix) {
        rtc_sso_add_api_base_url($urls, 'http://' . $prefix . '-backend-webserver');
    }

    foreach ($sites as $site) {
        $url = strpos($site['api'], 'http') === 0
            ? $site['api']
            : 'https://' . $site['api'];
        rtc_sso_add_api_base_url($urls, $url);
    }

    $gatewayapidomain = rtc_sso_env('GATEWAY_API_DOMAIN', '');
    if ($gatewayapidomain !== '') {
        $url = strpos($gatewayapidomain, 'http') === 0
            ? rtrim($gatewayapidomain, '/')
            : 'https://' . $gatewayapidomain;
        rtc_sso_add_api_base_url($urls, $url);
    }

    rtc_sso_add_api_base_url($urls, 'http://backend-webserver');

    return array_values(array_unique($urls));
}

function rtc_sso_fetch_user_detail(string $token): ?array
{
    if (!function_exists('curl_init')) {
        rtc_sso_log_debug('PHP curl extension is unavailable; skipping RTC API fetch.');
    } else {
        foreach (rtc_sso_api_base_urls() as $baseurl) {
            $url = rtrim($baseurl, '/') . '/api/auth/get_detail_user';
            rtc_sso_log_debug("RTC API URL: {$url}");

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
            ]);

            $response = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            rtc_sso_log_debug("RTC API code: {$code}");

            if (!$error && $code === 200 && !empty($response)) {
                $data = json_decode($response, true);
                if (is_array($data) && !empty($data['user'])) {
                    if (empty($data['user']['role']) && !empty($data['roles']) && is_array($data['roles'])) {
                        $data['user']['role'] = reset($data['roles']);
                    }
                    return $data['user'];
                }
            }

            if ($error) {
                rtc_sso_log_debug("RTC API curl error: {$error}");
            } else {
                rtc_sso_log_debug('RTC API invalid response: ' . ($response ?: 'empty'));
            }
        }
    }

    rtc_sso_log_debug('RTC token verification failed; refusing query-parameter fallback.');
    return null;
}

function rtc_sso_set_token_cookie(string $token): void
{
    @setcookie('auth_token', $token, [
        'expires' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Return useful database diagnostics without logging parameters or credentials.
 */
function rtc_sso_exception_context(Throwable $exception): string
{
    $details = [];

    foreach (['errorcode', 'error', 'sql'] as $property) {
        if (!empty($exception->{$property})) {
            $value = preg_replace('/\s+/', ' ', trim((string) $exception->{$property}));
            $details[] = $property . '=' . $value;
        }
    }

    return $details ? ' | ' . implode(' | ', $details) : '';
}

/**
 * Repair legacy backend responses that used an internal Docker hostname as the email domain.
 */
function rtc_sso_normalize_verified_email(string $email): string
{
    $email = trim($email);
    if (substr_count($email, '@') !== 1) {
        return $email;
    }

    [$localpart, $domain] = explode('@', $email, 2);
    $internaldomains = [
        'backend-webserver' => rtc_sso_env('RTC_SITE_EMAIL_DOMAIN', ''),
    ];
    foreach (rtc_sso_site_registry() as $site) {
        $internaldomains[$site['prefix'] . '-backend-webserver'] = $site['emaildomain'];
    }

    $sitedomain = $internaldomains[core_text::strtolower($domain)] ?? null;
    if ($sitedomain === null) {
        return $email;
    }

    $sitedomain = core_text::strtolower(trim($sitedomain));
    $normalized = trim($localpart) . '@' . $sitedomain;
    if ($sitedomain === '' || !validate_email($normalized)) {
        return $email;
    }

    rtc_sso_log_debug('Normalized verified RTC email from internal service domain to configured site domain.');
    return $normalized;
}

function rtc_sso_autologin_to_moodle(string $token): bool
{
    global $DB, $CFG, $SESSION;

    rtc_sso_log_debug('Starting Moodle auto-login...');
    $stage = 'initialization';

    try {
        $stage = 'load Moodle user API';
        require_once($CFG->dirroot . '/user/lib.php');

        $stage = 'fetch verified RTC user';
        $rtcuser = rtc_sso_fetch_user_detail($token);
        if (!$rtcuser) {
            rtc_sso_log_debug('Auto-login failed: cannot fetch RTC user detail.');
            return false;
        }

        $email = rtc_sso_normalize_verified_email((string) ($rtcuser['email'] ?? ''));
        $name = trim((string) ($rtcuser['name'] ?? 'User'));
        $detail = $rtcuser['user_detail'] ?? [];

        $firstname = core_text::substr(trim((string) ($detail['latin_name'] ?? $name ?: 'User')), 0, 100);
        $lastname = core_text::substr(trim((string) ($detail['last_name'] ?? $detail['family_name'] ?? 'RTC')), 0, 100);
        $phone = core_text::substr(trim((string) ($detail['phone_number'] ?? '')), 0, 20);
        $idcard = core_text::substr(trim((string) ($detail['id_card'] ?? '')), 0, 255);

        if ($email === '' || !validate_email($email) || core_text::strlen($email) > 100) {
            rtc_sso_log_debug('Auto-login failed: RTC user has no valid email.');
            return false;
        }

        $stage = 'resolve Moodle user';
        $username = core_text::strtolower($email);
        $muser = core_user::get_user_by_username($username, '*', null, IGNORE_MISSING);
        if ($muser && !empty($muser->deleted)) {
            $muser = false;
        }

        if (!$muser) {
            $muser = $DB->get_record(
                'user',
                ['email' => $email, 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0],
                '*',
                IGNORE_MISSING
            );
        }

        if ($muser) {
            $stage = 'update Moodle user';
            $updaterecord = new stdClass();
            $updaterecord->id = $muser->id;
            $updaterecord->firstname = $firstname ?: ($muser->firstname ?: 'User');
            $updaterecord->lastname = $lastname ?: ($muser->lastname ?: 'RTC');
            $updaterecord->phone1 = $phone;
            $updaterecord->timemodified = time();

            user_update_user($updaterecord, false);
            rtc_sso_log_debug("Updated existing Moodle user: {$email}");
        } else {
            $stage = 'create Moodle user';
            $newuser = new stdClass();
            $newuser->auth = 'manual';
            $newuser->confirmed = 1;
            $newuser->mnethostid = $CFG->mnet_localhost_id;
            $newuser->username = $username;
            $newuser->email = $email;
            $newuser->firstname = $firstname ?: 'User';
            $newuser->lastname = $lastname ?: 'RTC';
            $newuser->idnumber = $idcard;
            $newuser->phone1 = $phone;
            $newuser->phone2 = '';
            $newuser->institution = '';
            $newuser->department = '';
            $newuser->address = '';
            $newuser->city = '';
            $newuser->country = '';
            $newuser->theme = '';
            $newuser->lastip = getremoteaddr();
            $newuser->secret = '';
            $newuser->lang = !empty($CFG->lang) ? $CFG->lang : 'en';
            $newuser->timezone = !empty($CFG->timezone) ? $CFG->timezone : '99';
            $newuser->password = hash_internal_user_password(random_string(32));

            $newuser->id = user_create_user($newuser, false);
            $stage = 'reload created Moodle user';
            $muser = $DB->get_record('user', ['id' => $newuser->id], '*', MUST_EXIST);

            rtc_sso_log_debug("Created new Moodle user: {$email} (id={$muser->id})");
        }

        $stage = 'complete Moodle login';
        complete_user_login($muser);

        $SESSION->rtc_token = $token;
        $SESSION->rtc_email = $email;
        $SESSION->rtc_idcard = $idcard;
        rtc_sso_set_token_cookie($token);

        try {
            $rtcrole = $rtcuser['role'] ?? ($detail['role'] ?? 'student');
            $rtcrolelower = core_text::strtolower(trim($rtcrole));
            rtc_sso_log_debug("RTC role detected: {$rtcrole}");

            $roleid = 5;
            switch ($rtcrolelower) {
                case 'admin':
                case 'administrator':
                case 'director':
                    $roleid = 1;
                    break;
                case 'head_department':
                case 'head department':
                case 'teacher':
                case 'instructor':
                case 'staff':
                case 'employee':
                    $roleid = 3;
                    break;
            }

            $systemcontext = context_system::instance();
            if (in_array($rtcrolelower, ['admin', 'administrator'], true)) {
                $siteadmins = array_filter(array_map('trim', explode(',', (string) ($CFG->siteadmins ?? ''))));
                if (!in_array((string) $muser->id, $siteadmins, true)) {
                    $siteadmins[] = (string) $muser->id;
                    set_config('siteadmins', implode(',', array_unique($siteadmins)));
                    $CFG->siteadmins = implode(',', array_unique($siteadmins));
                    rtc_sso_log_debug("Added Moodle site admin access for RTC admin user {$muser->id}.");
                }
            }

            if (!is_siteadmin($muser->id)) {
                $existingra = $DB->get_record('role_assignments', [
                    'roleid' => $roleid,
                    'userid' => $muser->id,
                    'contextid' => $systemcontext->id,
                ]);
                if (!$existingra) {
                    role_assign($roleid, $muser->id, $systemcontext->id);
                    rtc_sso_log_debug("Assigned Moodle role ID {$roleid} to user {$muser->id}.");
                }
            }
        } catch (Throwable $roleexception) {
            rtc_sso_log_debug('Role assignment warning: ' . $roleexception->getMessage());
        }

        rtc_sso_log_debug("Moodle auto-login success: {$email}");
        return true;
    } catch (Throwable $exception) {
        rtc_sso_log_debug(
            'Auto-login exception during ' . $stage . ': '
            . get_class($exception) . ': ' . $exception->getMessage()
            . rtc_sso_exception_context($exception)
        );
        return false;
    }
}

function rtc_sso_bootstrap(): void
{
    global $SESSION;

    rtc_sso_log_debug('Moodle frontpage SSO bootstrap loaded.');

    $requesttoken = optional_param('token', '', PARAM_RAW);
    if ($requesttoken === '' && !empty($_POST['token'])) {
        $requesttoken = $_POST['token'];
    }
    if ($requesttoken !== '') {
        $requesttoken = urldecode($requesttoken);
    }

    $rtctoken = $requesttoken;
    if ($rtctoken === '' && !empty($_COOKIE['auth_token'])) {
        $rtctoken = urldecode((string) $_COOKIE['auth_token']);
    }

    $cookietoken = $_COOKIE['auth_token'] ?? null;
    $sessiontoken = $SESSION->rtc_token ?? null;

    if (isloggedin() && !isguestuser() && $sessiontoken) {
        if ($requesttoken !== '' && $requesttoken !== $sessiontoken) {
            rtc_sso_log_debug('Incoming RTC token differs from Moodle session; relogin.');
            require_logout();
            if (rtc_sso_autologin_to_moodle($requesttoken)) {
                redirect(new moodle_url('/my/'));
            }
        } else if ($requesttoken !== '') {
            rtc_sso_log_debug('Incoming RTC token matches active Moodle session.');
            rtc_sso_set_token_cookie($requesttoken);
            redirect(new moodle_url('/my/'));
        } else if (!$cookietoken) {
            rtc_sso_log_debug('Cookie removed but Moodle session exists; logout.');
            require_logout();
            redirect(new moodle_url('/'));
        } else if ($cookietoken !== $sessiontoken) {
            rtc_sso_log_debug('Token mismatch; relogin with cookie token.');
            require_logout();
            rtc_sso_autologin_to_moodle((string) $cookietoken);
            redirect(new moodle_url('/my/'));
        }
    }

    if ((!isloggedin() || isguestuser()) && $rtctoken !== '') {
        rtc_sso_log_debug('Token detected: ' . substr($rtctoken, 0, 25) . '...');
        if (rtc_sso_autologin_to_moodle($rtctoken)) {
            redirect(new moodle_url('/my/'));
        }
        rtc_sso_log_debug('Auto-login failed; continuing Moodle normal flow.');
    }
}
