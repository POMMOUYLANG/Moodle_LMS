<?php

defined('MOODLE_INTERNAL') || die();

function xmldb_local_rtcsync_install(): void
{
    global $CFG;

    require_once($CFG->dirroot . '/local/rtcsync/locallib.php');
    local_rtcsync_ensure_profile_fields();
}