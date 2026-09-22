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

function rtc_sso_primary_api_base_urls(): array
{
    $urls = [];
    rtc_sso_add_api_base_url($urls, rtc_sso_env('RTC_API_BASE_URL', ''));

    $prefix = rtc_sso_env('CONTAINER_PREFIX', '');
    if ($prefix !== '') {
        rtc_sso_add_api_base_url($urls, 'http://' . $prefix . '-backend-webserver');
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
                    $data['user']['rtc_roles'] = !empty($data['roles']) && is_array($data['roles'])
                        ? $data['roles']
                        : [];
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

function rtc_sso_fetch_login_token(string $email, string $password): ?string
{
    if (!function_exists('curl_init')) {
        rtc_sso_log_debug('PHP curl extension is unavailable; skipping RTC credential login.');
        return null;
    }

    foreach (rtc_sso_primary_api_base_urls() as $baseurl) {
        $url = rtrim($baseurl, '/') . '/api/auth/login';
        rtc_sso_log_debug("RTC credential login URL: {$url}");

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'email' => $email,
                'password' => $password,
            ]),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        rtc_sso_log_debug("RTC credential login API code: {$code}");

        if (!$error && $code === 200 && !empty($response)) {
            $data = json_decode($response, true);
            if (is_array($data) && !empty($data['token']) && is_string($data['token'])) {
                return $data['token'];
            }
        }

        if ($error) {
            rtc_sso_log_debug("RTC credential login curl error: {$error}");
        } else {
            rtc_sso_log_debug('RTC credential login was not accepted by this API endpoint.');
            if ($code >= 400 && $code < 500) {
                return null;
            }
        }
    }

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

function rtc_sso_token_cookie_domains(): array
{
    $domains = [''];
    $shareddomain = core_text::strtolower(trim(rtc_sso_env('RTC_SITE_EMAIL_DOMAIN', '')));

    if ($shareddomain !== '' && validate_email('user@' . $shareddomain)) {
        $domains[] = '.' . $shareddomain;
    }

    return array_unique($domains);
}

function rtc_sso_clear_login_state(): void
{
    global $SESSION;

    foreach (rtc_sso_token_cookie_domains() as $domain) {
        @setcookie('auth_token', '', time() - 3600, '/', $domain, true, true);
        @setcookie('auth_token', '', time() - 3600, '/', $domain, false, true);
    }

    unset($_COOKIE['auth_token']);
    unset($SESSION->rtc_token, $SESSION->rtc_email, $SESSION->rtc_idcard, $SESSION->rtc_roles);
}

function rtc_sso_revoke_token(string $token): bool
{
    if ($token === '') {
        return true;
    }

    if (!function_exists('curl_init')) {
        rtc_sso_log_debug('PHP curl extension is unavailable; skipping RTC token revocation.');
        return false;
    }

    foreach (rtc_sso_primary_api_base_urls() as $baseurl) {
        $url = rtrim($baseurl, '/') . '/api/auth/logout';
        rtc_sso_log_debug("RTC logout API URL: {$url}");

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                "Authorization: Bearer {$token}",
            ],
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 4,
        ]);

        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        rtc_sso_log_debug("RTC logout API code: {$code}");

        if (!$error && (($code >= 200 && $code < 300) || $code === 401)) {
            rtc_sso_log_debug('RTC token revoked or already inactive.');
            return true;
        }

        if ($error) {
            rtc_sso_log_debug("RTC logout API curl error: {$error}");
        }
    }

    rtc_sso_log_debug('RTC token revocation could not be confirmed; continuing Moodle local logout.');
    return false;
}

function rtc_sso_logout(): void
{
    global $SESSION;

    $token = trim((string) ($SESSION->rtc_token ?? ''));
    if ($token === '' && !empty($_COOKIE['auth_token'])) {
        $token = trim(urldecode((string) $_COOKIE['auth_token']));
    }

    if ($token !== '') {
        rtc_sso_revoke_token($token);
    }

    rtc_sso_clear_login_state();
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

function rtc_sso_verified_roles(array $rtcuser, array $detail): array
{
    $roles = $rtcuser['rtc_roles'] ?? [];
    if (!is_array($roles)) {
        $roles = [$roles];
    }

    foreach ([$rtcuser['role'] ?? null, $detail['role'] ?? null] as $role) {
        if ($role !== null && $role !== '') {
            $roles[] = $role;
        }
    }

    $normalizedroles = [];
    foreach ($roles as $role) {
        if (is_array($role)) {
            $role = $role['role_key'] ?? $role['name'] ?? '';
        } else if (is_object($role)) {
            $role = $role->role_key ?? $role->name ?? '';
        }

        $role = core_text::strtolower(trim((string) $role));
        $role = str_replace(['-', '_'], ' ', $role);
        $role = preg_replace('/\s+/', ' ', $role);
        if ($role !== '') {
            $normalizedroles[] = $role;
        }
    }

    return array_values(array_unique($normalizedroles ?: ['student']));
}

function rtc_sso_moodle_role_shortname(array $rtcroles): ?string
{
    if (array_intersect($rtcroles, ['super admin', 'admin', 'administrator'])) {
        return 'manager';
    }

    // Teacher and student access is course-scoped and must come from RTC
    // subject assignments and enrolments, never from broad system roles.
    return null;
}

function rtc_sso_sync_role_access(stdClass $muser, array $rtcroles): void
{
    global $DB;

    $component = 'local_rtc_sso';
    $systemcontext = context_system::instance();
    $shortname = rtc_sso_moodle_role_shortname($rtcroles);
    $moodlerole = $shortname === null
        ? null
        : $DB->get_record('role', ['shortname' => $shortname], '*', MUST_EXIST);

    $hasdesiredrole = false;
    foreach ($DB->get_records('role_assignments', [
        'userid' => $muser->id,
        'contextid' => $systemcontext->id,
        'component' => $component,
    ]) as $assignment) {
        if ($moodlerole !== null && (int) $assignment->roleid === (int) $moodlerole->id) {
            $hasdesiredrole = true;
            continue;
        }

        role_unassign((int) $assignment->roleid, $muser->id, $systemcontext->id, $component);
    }

    if ($moodlerole !== null && !$hasdesiredrole) {
        role_assign($moodlerole->id, $muser->id, $systemcontext->id, $component);
    }
    $result = $shortname ?? 'no system role';
    rtc_sso_log_debug("Synchronized verified RTC access policy to {$result} for user {$muser->id}.");
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
        $rtcroles = rtc_sso_verified_roles($rtcuser, $detail);

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

        $stage = 'synchronize verified RTC roles';
        rtc_sso_sync_role_access($muser, $rtcroles);

        $stage = 'complete Moodle login';
        complete_user_login($muser);

        $SESSION->rtc_token = $token;
        $SESSION->rtc_email = $email;
        $SESSION->rtc_idcard = $idcard;
        $SESSION->rtc_roles = $rtcroles;
        rtc_sso_set_token_cookie($token);

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

function rtc_sso_try_credentials_login(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }

    $email = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if ($email === '' || $password === '' || !validate_email($email)) {
        return;
    }

    rtc_sso_log_debug('Trying RTC credential login before Moodle native authentication.');
    $token = rtc_sso_fetch_login_token($email, $password);
    if ($token === null) {
        rtc_sso_log_debug('RTC credential login did not succeed; continuing Moodle native authentication.');
        return;
    }

    if (rtc_sso_autologin_to_moodle($token)) {
        redirect(new moodle_url('/my/'));
    }

    rtc_sso_revoke_token($token);
    rtc_sso_log_debug('RTC credential login succeeded but Moodle provisioning failed; continuing Moodle native authentication.');
}

function rtc_sso_bootstrap(): void
{
    global $SESSION;

    rtc_sso_log_debug('Moodle RTC SSO bootstrap loaded.');

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
            rtc_sso_logout();
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
        rtc_sso_log_debug('RTC token detected.');
        if (rtc_sso_autologin_to_moodle($rtctoken)) {
            redirect(new moodle_url('/my/'));
        }
        rtc_sso_log_debug('Auto-login failed; continuing Moodle normal flow.');
    }
}
