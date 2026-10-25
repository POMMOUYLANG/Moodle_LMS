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

namespace core_xapi\local\helper;

use core_component;
use core_xapi\local\state;
use core_xapi\local\statement\item_agent;
use core_xapi\xapi_exception;
use JsonException;
use stdClass;

/**
 * State trait helper, with common methods.
 *
 * @package    core_xapi
 * @since      Moodle 4.2
 * @copyright  2023 Sara Arjona (sara@moodle.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait state_trait {
    /**
     * Check component name.
     *
     * Note: this function is separated mainly for testing purposes to
     * be overridden to fake components.
     *
     * @throws xapi_exception if component is not available
     * @param string $component component name
     */
    protected static function validate_component(string $component): void {
        // Check that $component is a real component name.
        $dir = core_component::get_component_directory($component);
        if (!$dir) {
            throw new xapi_exception("Component $component not available.");
        }
    }

    /**
     * Convert a JSON agent into a valid item_agent.
     *
     * @throws xapi_exception if JSON cannot be parsed
     * @param string $agentjson JSON encoded agent structure
     * @return item_agent the agent
     */
    private static function get_agent_from_json(string $agentjson): item_agent {
        try {
            $agentdata = json_decode($agentjson, null, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new xapi_exception('No agent detected');
        }
        return item_agent::create_from_data($agentdata);
    }

    /**
     * Check that $USER is actor in state.
     *
     * @param state $state The state
     * @return bool if $USER is actor of the state
     */
    private static function check_state_user(state $state): bool {
        global $USER;
        $user = $state->get_user();
        if ($user->id != $USER->id) {
            return false;
        }
        return true;
    }

    /** Enforce RTC delivery access when a state operation targets an H5P activity. */
    private static function require_managed_h5p_activity_access(
        string $component,
        string $activityid,
        int $userid
    ): void {
        global $DB;

        if ($component !== 'mod_h5pactivity'
                || !class_exists('\\local_rtcsync\\local\\delivery_activity_access')
                || !$DB->get_manager()->table_exists('local_rtcsync_deliverycourse')
                || !$DB->get_manager()->table_exists('local_rtcsync_deliverycm')) {
            return;
        }

        // H5P uses the module context ID as its xAPI activity ID, with an optional subcontent query.
        $contextid = explode('?', $activityid, 2)[0];
        if (!ctype_digit($contextid) || (int) $contextid <= 0) {
            throw new xapi_exception('Invalid H5P activity context');
        }

        try {
            $context = \context::instance_by_id((int) $contextid);
        } catch (\moodle_exception $exception) {
            throw new xapi_exception('Invalid H5P activity context');
        }
        if (!$context instanceof \context_module) {
            throw new xapi_exception('Invalid H5P activity context');
        }

        $cm = get_coursemodule_from_id('h5pactivity', $context->instanceid, 0, false);
        if (!$cm) {
            throw new xapi_exception('Invalid H5P activity context');
        }

        $courseid = (int) $cm->course;
        if (\local_rtcsync\local\delivery_activity_access::is_enabled($courseid)) {
            \local_rtcsync\local\delivery_activity_access::require_student_module_access(
                $courseid,
                (int) $cm->id,
                $userid
            );
        }
    }

    /**
     * Convert the state data JSON into valid object.
     *
     * @throws xapi_exception if JSON cannot be parsed
     * @param string $statedatajson JSON encoded structure
     * @return stdClass the state data structure
     */
    private static function get_statedata_from_json(string $statedatajson): stdClass {
        try {
            // Force it to be an object, because some statedata might be sent as array instead of JSON.
            $statedata = json_decode($statedatajson, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new xapi_exception('Invalid state data format');
        }
        return $statedata;
    }
}
