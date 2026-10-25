<?php

namespace local_rtcsync\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Keep student-history request cleanup policy independent of Moodle globals.
 */
final class student_history_request
{
    /**
     * Render history, or deny the request and clear an RTC session on identity failure.
     *
     * A missing token is denied before identity lookup and preserves the existing
     * session state. A failed identity lookup clears RTC state before Moodle logout.
     *
     * @param callable(string):?string $renderpage
     * @param callable():void $clearloginstate
     * @param callable():void $logout
     * @param callable():void $deny Expected to stop the request, normally by throwing.
     */
    public function handle(
        string $token,
        callable $renderpage,
        callable $clearloginstate,
        callable $logout,
        callable $deny
    ): ?string {
        $token = trim($token);
        if ($token === '') {
            $deny();
            return null;
        }

        $renderedpage = $renderpage($token);
        if ($renderedpage === null) {
            $clearloginstate();
            $logout();
            $deny();
            return null;
        }

        return $renderedpage;
    }
}
