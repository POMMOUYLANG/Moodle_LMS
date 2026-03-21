<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Moodle frontpage.
 *
 * @package    core
 * @copyright  1999 onwards Martin Dougiamas (http://dougiamas.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if (!file_exists('./config.php')) {
    header('Location: install.php');
    die;
}

require_once('config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/filelib.php');

/* ==========================================================
   RTC SSO PATCH
   - Accept ?token=... or cookie auth_token
   - Call RTC API get_detail_user
   - Create/update Moodle user
   - complete_user_login()
   - Token sync with cookie (logout/relogin)
   ========================================================== */

$rtc_debugfile = __DIR__ . '/rtc_sso_debug.log';
function rtc_log_debug($msg)
{
    global $rtc_debugfile;
    @file_put_contents($rtc_debugfile, "[" . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
}

rtc_log_debug("=== Moodle index.php loaded ===");

// 1) Get token from GET/POST first, then cookie
$rtctoken = optional_param('token', '', PARAM_RAW);
if (empty($rtctoken) && !empty($_POST['token'])) {
    $rtctoken = $_POST['token'];
}
if (empty($rtctoken) && !empty($_COOKIE['auth_token'])) {
    $rtctoken = $_COOKIE['auth_token'];
}

if (!empty($rtctoken)) {
    $rtctoken = urldecode($rtctoken);
    rtc_log_debug("Token detected: " . substr($rtctoken, 0, 25) . "...");
}

// 2) Call RTC API to get user detail
function rtc_fetch_user_detail($token)
{
    $url = "https://api.rtc-bb.camai.kh/api/auth/get_detail_user";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    rtc_log_debug("RTC API code: {$code}");
    if ($err) {
        rtc_log_debug("RTC API curl error: {$err}");
        return null;
    }
    if ($code !== 200 || empty($response)) {
        rtc_log_debug("RTC API invalid response: " . ($response ?: 'empty'));
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['user'])) {
        rtc_log_debug("RTC API missing user field");
        return null;
    }
    return $data['user'];
}

// 3) Create/update Moodle user + login
function rtc_autologin_to_moodle($token)
{
    global $DB, $CFG, $SESSION;

    rtc_log_debug("Starting Moodle auto-login...");

    $rtcuser = rtc_fetch_user_detail($token);
    if (!$rtcuser) {
        rtc_log_debug("Auto-login failed: cannot fetch RTC user detail.");
        return false;
    }

    $email = $rtcuser['email'] ?? '';
    $name  = $rtcuser['name'] ?? 'User';

    $detail = $rtcuser['user_detail'] ?? [];
    $firstname = $detail['latin_name'] ?? $name;
    $lastname  = ''; // optional
    $phone     = $detail['phone_number'] ?? '';
    $idcard    = $detail['id_card'] ?? '';

    if (empty($email)) {
        rtc_log_debug("Auto-login failed: RTC user has no email.");
        return false;
    }

    // Moodle username must be unique
    $username = core_text::strtolower(trim($email));

    // Find existing user by email
    $muser = $DB->get_record('user', ['email' => $email, 'deleted' => 0], '*', IGNORE_MISSING);

    if ($muser) {
        // Update basic fields
        $muser->firstname = $firstname ?: $muser->firstname;
        $muser->lastname  = $lastname ?: ($muser->lastname ?? '');
        $muser->phone1    = $phone;
        $muser->timemodified = time();
        $DB->update_record('user', $muser);
        rtc_log_debug("Updated existing Moodle user: {$email}");
    } else {
        // Create new Moodle user
        $newuser = new stdClass();
        $newuser->auth       = 'manual';
        $newuser->confirmed  = 1;
        $newuser->mnethostid = $CFG->mnet_localhost_id;
        $newuser->username   = $username;
        $newuser->email      = $email;
        $newuser->firstname  = $firstname ?: 'User';
        $newuser->lastname   = $lastname ?: 'RTC';
        $newuser->phone1     = $phone;
        $newuser->lang       = $CFG->lang;
        $newuser->timezone   = $CFG->timezone;
        $newuser->timecreated = time();
        $newuser->timemodified = time();
        $newuser->password = hash_internal_user_password(random_string(32));

        $newuser->id = $DB->insert_record('user', $newuser);
        $muser = $DB->get_record('user', ['id' => $newuser->id], '*', MUST_EXIST);

        rtc_log_debug("Created new Moodle user: {$email} (id={$muser->id})");
    }

    // Log in user to Moodle
    complete_user_login($muser);

    // Store RTC token in Moodle session for sync
    $SESSION->rtc_token = $token;
    $SESSION->rtc_email = $email;
    $SESSION->rtc_idcard = $idcard;

    // 3.5) Role mapping and assignment
    $rtcrole = $rtcuser['role'] ?? ($detail['role'] ?? 'student');
    rtc_log_debug("RTC role detected: {$rtcrole}");

    $roleid = 5; // Default to Student
    $rtcrole_lower = core_text::strtolower(trim($rtcrole));
    switch ($rtcrole_lower) {
        case 'admin':
        case 'administrator':
            $roleid = 1; // Manager
            break;
        case 'teacher':
        case 'instructor':
        case 'staff':
            $roleid = 3; // Editing teacher
            break;
        case 'student':
        case 'learner':
        case 'user':
        default:
            $roleid = 5; // Student
            break;
    }

    $systemcontext = context_system::instance();
    // Assign role at system context if not already assigned
    if (!is_siteadmin($muser->id)) {
        // Check if role is already assigned to avoid duplicates (though role_assign handles basic cases)
        $existing_ra = $DB->get_record('role_assignments', ['roleid' => $roleid, 'userid' => $muser->id, 'contextid' => $systemcontext->id]);
        if (!$existing_ra) {
            role_assign($roleid, $muser->id, $systemcontext->id);
            rtc_log_debug("Assigned Moodle role ID {$roleid} (from RTC role: {$rtcrole}) to user {$muser->id} at system context.");
        }
    }

    rtc_log_debug("✅ Moodle auto-login success: {$email}");
    return true;
}

// 4) Token sync: if session token exists but cookie removed => logout
$cookieToken  = $_COOKIE['auth_token'] ?? null;
$sessionToken = $SESSION->rtc_token ?? null;

if (isloggedin() && !isguestuser() && $sessionToken) {
    if (!$cookieToken) {
        rtc_log_debug("Cookie removed but Moodle session exists => logging out.");
        require_logout();
        redirect(new moodle_url('/'));
    } else if ($cookieToken !== $sessionToken) {
        rtc_log_debug("Token mismatch => relogin with new token.");
        require_logout();
        rtc_autologin_to_moodle($cookieToken);
        redirect(new moodle_url('/my/'));
    }
}

// 5) If not logged in but token exists => auto-login
if (!isloggedin() || isguestuser()) {
    if (!empty($rtctoken)) {
        if (rtc_autologin_to_moodle($rtctoken)) {
            redirect(new moodle_url('/my/   '));
        } else {
            rtc_log_debug("Auto-login failed; continue Moodle normal flow.");
        }
    }
}

/* =========================
   END RTC SSO PATCH
   ========================= */

redirect_if_major_upgrade_required();

// Redirect logged-in users to homepage if required.
$redirect = optional_param('redirect', 1, PARAM_BOOL);

$urlparams = array();
if (
    !empty($CFG->defaulthomepage) &&
    ($CFG->defaulthomepage == HOMEPAGE_MY || $CFG->defaulthomepage == HOMEPAGE_MYCOURSES) &&
    $redirect === 0
) {
    $urlparams['redirect'] = 0;
}
$PAGE->set_url('/', $urlparams);
$PAGE->set_pagelayout('frontpage');
$PAGE->add_body_class('limitedwidth');
$PAGE->set_other_editing_capability('moodle/course:update');
$PAGE->set_other_editing_capability('moodle/course:manageactivities');
$PAGE->set_other_editing_capability('moodle/course:activityvisibility');

// Prevent caching of this page to stop confusion when changing page after making AJAX changes.
$PAGE->set_cacheable(false);

require_course_login($SITE);

$hasmaintenanceaccess = has_capability('moodle/site:maintenanceaccess', context_system::instance());

// If the site is currently under maintenance, then print a message.
if (!empty($CFG->maintenance_enabled) and !$hasmaintenanceaccess) {
    print_maintenance_message();
}

$hassiteconfig = has_capability('moodle/site:config', context_system::instance());

if ($hassiteconfig && moodle_needs_upgrading()) {
    redirect($CFG->wwwroot . '/' . $CFG->admin . '/index.php');
}

// If site registration needs updating, redirect.
\core\hub\registration::registration_reminder('/index.php');

$homepage = get_home_page();
if ($homepage != HOMEPAGE_SITE) {
    if (optional_param('setdefaulthome', false, PARAM_BOOL)) {
        set_user_preference('user_home_page_preference', HOMEPAGE_SITE);
    } else if (!empty($CFG->defaulthomepage) && ($CFG->defaulthomepage == HOMEPAGE_MY) && $redirect === 1) {
        redirect($CFG->wwwroot . '/my/');
    } else if (!empty($CFG->defaulthomepage) && ($CFG->defaulthomepage == HOMEPAGE_MYCOURSES) && $redirect === 1) {
        redirect($CFG->wwwroot . '/my/courses.php');
    } else if ($homepage == HOMEPAGE_URL) {
        redirect(get_default_home_page_url());
    } else if (!empty($CFG->defaulthomepage) && ($CFG->defaulthomepage == HOMEPAGE_USER)) {
        $frontpagenode = $PAGE->settingsnav->find('frontpage', null);
        if ($frontpagenode) {
            $frontpagenode->add(
                get_string('makethismyhome'),
                new moodle_url('/', array('setdefaulthome' => true)),
                navigation_node::TYPE_SETTING
            );
        } else {
            $frontpagenode = $PAGE->settingsnav->add(get_string('frontpagesettings'), null, navigation_node::TYPE_SETTING, null);
            $frontpagenode->force_open();
            $frontpagenode->add(
                get_string('makethismyhome'),
                new moodle_url('/', array('setdefaulthome' => true)),
                navigation_node::TYPE_SETTING
            );
        }
    }
}

// Trigger event.
course_view(context_course::instance(SITEID));

$PAGE->set_pagetype('site-index');
$PAGE->set_docs_path('');
$editing = $PAGE->user_is_editing();
$PAGE->set_title(get_string('home'));
$PAGE->set_heading($SITE->fullname);
$PAGE->set_secondary_active_tab('coursehome');

$siteformatoptions = course_get_format($SITE)->get_format_options();
$modinfo = get_fast_modinfo($SITE);
$modnamesused = $modinfo->get_used_module_names();

include_course_ajax($SITE, $modnamesused);

$courserenderer = $PAGE->get_renderer('core', 'course');

if ($hassiteconfig) {
    $editurl = new moodle_url('/course/view.php', ['id' => SITEID, 'sesskey' => sesskey()]);
    $editbutton = $OUTPUT->edit_button($editurl);
    $PAGE->set_button($editbutton);
}

echo $OUTPUT->header();

if (!empty($CFG->customfrontpageinclude)) {
    $modnames = get_module_types_names();
    $modnamesplural = get_module_types_names(true);
    $mods = $modinfo->get_cms();

    include($CFG->customfrontpageinclude);
} else if ($siteformatoptions['numsections'] > 0) {
    echo $courserenderer->frontpage_section1();
}

echo $courserenderer->frontpage();

if ($editing && has_capability('moodle/course:create', context_system::instance())) {
    echo $courserenderer->add_new_course_button();
}
echo $OUTPUT->footer();