<?php

namespace local_rtcsync\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Build the student-facing read-only released-history page.
 */
final class student_history_page
{
    /**
     * @param \Closure(string):?array $identityprovider Fetch a fresh RTC user identity for the session token.
     */
    public function __construct(private readonly \Closure $identityprovider)
    {
    }

    /**
     * Render released history, returning null when RTC account verification fails.
     *
     * The cursor reader is called only after the fresh RTC identity and local
     * Moodle account have both passed validation. The page entry point owns
     * session clearing and logout when this method returns null.
     *
     * @param callable():string $cursorreader Return the sanitized Moodle request cursor.
     */
    public function render(string $token, callable $cursorreader, \core_renderer $output): ?string
    {
        if (trim($token) === '') {
            throw new \moodle_exception('studenthistorydenied', 'local_rtcsync');
        }

        $rtcuser = ($this->identityprovider)($token);
        if (!$rtcuser || ($rtcuser['account_is_active'] ?? null) !== true) {
            return null;
        }

        $history = new student_history();
        $history->require_current_rtc_student($rtcuser);
        $cursor = $cursorreader();
        $resultpage = $history->visible_results($cursor);

        $html = $output->header();
        $html .= $output->heading(get_string('studenthistorytitle', 'local_rtcsync'));
        $html .= $output->notification(get_string('studenthistoryintro', 'local_rtcsync'), 'info');

        if ($resultpage['results'] === []) {
            $html .= $output->notification(get_string('studenthistoryempty', 'local_rtcsync'), 'info');
        } else {
            $table = new \html_table();
            $table->attributes['class'] = 'generaltable';
            $table->head = [
                get_string('studenthistorycourse', 'local_rtcsync'),
                get_string('studenthistoryitem', 'local_rtcsync'),
                get_string('studenthistorygrade', 'local_rtcsync'),
            ];
            foreach ($resultpage['results'] as $row) {
                $table->data[] = [
                    format_string($row['course'], true, [
                        'context' => \context_course::instance($row['courseid']),
                    ]),
                    format_string($row['item'], true, [
                        'context' => \context_course::instance($row['courseid']),
                    ]),
                    $row['formatted'],
                ];
            }
            $html .= \html_writer::table($table);
        }

        $navigation = [];
        if ($resultpage['hasprevious'] && $resultpage['previouscursor'] !== null) {
            $navigation[] = \html_writer::link(
                new \moodle_url('/local/rtcsync/student_history.php', ['cursor' => $resultpage['previouscursor']]),
                get_string('previous')
            );
        }
        if ($resultpage['hasnext'] && $resultpage['nextcursor'] !== null) {
            $navigation[] = \html_writer::link(
                new \moodle_url('/local/rtcsync/student_history.php', ['cursor' => $resultpage['nextcursor']]),
                get_string('next')
            );
        }
        if ($navigation !== []) {
            $html .= \html_writer::div(implode(' ', $navigation), 'mt-3');
        }

        return $html . $output->footer();
    }
}
