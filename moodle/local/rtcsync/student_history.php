<?php

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/rtc_sso.php');

require_login();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_cacheable(false);
$PAGE->set_url(new moodle_url('/local/rtcsync/student_history.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('studenthistorytitle', 'local_rtcsync'));
$PAGE->set_heading(get_string('studenthistorytitle', 'local_rtcsync'));

$token = trim((string) ($SESSION->rtc_token ?? ''));
if ($token === '') {
    throw new moodle_exception('studenthistorydenied', 'local_rtcsync');
}

// The SSO bootstrap can reuse an existing Moodle session without checking its
// RTC token again. This page always revalidates before showing released grades.
$rtcuser = rtc_sso_fetch_user_detail($token);
if (!$rtcuser || ($rtcuser['account_is_active'] ?? null) !== true) {
    rtc_sso_clear_login_state();
    require_logout();
    throw new moodle_exception('studenthistorydenied', 'local_rtcsync');
}

$history = new \local_rtcsync\local\student_history();
$history->require_current_rtc_student($rtcuser);
$cursor = optional_param('cursor', '', PARAM_ALPHANUMEXT);
$resultpage = $history->visible_results($cursor);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('studenthistorytitle', 'local_rtcsync'));
echo $OUTPUT->notification(get_string('studenthistoryintro', 'local_rtcsync'), 'info');

if ($resultpage['results'] === []) {
    echo $OUTPUT->notification(get_string('studenthistoryempty', 'local_rtcsync'), 'info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable';
    $table->head = [
        get_string('studenthistorycourse', 'local_rtcsync'),
        get_string('studenthistoryitem', 'local_rtcsync'),
        get_string('studenthistorygrade', 'local_rtcsync'),
    ];
    foreach ($resultpage['results'] as $row) {
        $table->data[] = [
            format_string($row['course'], true, [
                'context' => context_course::instance($row['courseid']),
            ]),
            format_string($row['item'], true, [
                'context' => context_course::instance($row['courseid']),
            ]),
            $row['formatted'],
        ];
    }
    echo html_writer::table($table);
}

$navigation = [];
if ($resultpage['hasprevious'] && $resultpage['previouscursor'] !== null) {
    $navigation[] = html_writer::link(
        new moodle_url('/local/rtcsync/student_history.php', ['cursor' => $resultpage['previouscursor']]),
        get_string('previous')
    );
}
if ($resultpage['hasnext'] && $resultpage['nextcursor'] !== null) {
    $navigation[] = html_writer::link(
        new moodle_url('/local/rtcsync/student_history.php', ['cursor' => $resultpage['nextcursor']]),
        get_string('next')
    );
}
if ($navigation !== []) {
    echo html_writer::div(implode(' ', $navigation), 'mt-3');
}

echo $OUTPUT->footer();
