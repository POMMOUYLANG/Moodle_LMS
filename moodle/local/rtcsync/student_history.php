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

$token = (string) ($SESSION->rtc_token ?? '');

$historypage = new \local_rtcsync\local\student_history_page(
    static fn (string $token): ?array => rtc_sso_fetch_user_detail($token)
);
$cursorreader = static fn (): string => optional_param('cursor', '', PARAM_ALPHANUMEXT);
$request = new \local_rtcsync\local\student_history_request();
$renderedpage = $request->handle(
    $token,
    static fn (string $token): ?string => $historypage->render($token, $cursorreader, $OUTPUT),
    static function (): void {
        rtc_sso_clear_login_state();
    },
    static function (): void {
        require_logout();
    },
    static function (): void {
        throw new moodle_exception('studenthistorydenied', 'local_rtcsync');
    }
);

if ($renderedpage !== null) {
    echo $renderedpage;
}
