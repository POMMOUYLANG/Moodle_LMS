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
 * Callbacks.
 *
 * @package    core_h5p
 * @copyright  2019 Bas Brands <bas@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

use core_h5p\local\library\autoloader;

/** Require current RTC delivery access for H5P content stored by an activity module. */
function core_h5p_require_delivery_activity_access(int $h5pid): void {
    global $DB, $USER;

    if ($h5pid <= 0
            || !class_exists('\\local_rtcsync\\local\\delivery_activity_access')
            || !$DB->get_manager()->table_exists('local_rtcsync_deliverycourse')) {
        return;
    }

    $content = $DB->get_record('h5p', ['id' => $h5pid], 'pathnamehash', IGNORE_MISSING);
    if (!$content) {
        return;
    }

    $sourcefile = get_file_storage()->get_file_by_hash((string) $content->pathnamehash);
    if (!$sourcefile
            || $sourcefile->get_component() !== 'mod_h5pactivity'
            || $sourcefile->get_filearea() !== 'package') {
        return;
    }

    $sourcecontext = \context::instance_by_id((int) $sourcefile->get_contextid(), IGNORE_MISSING);
    if (!$sourcecontext || $sourcecontext->contextlevel !== CONTEXT_MODULE) {
        return;
    }

    $cmrecord = $DB->get_record('course_modules', [
        'id' => (int) $sourcecontext->instanceid,
    ], 'id,course,module', IGNORE_MISSING);
    $modulename = $cmrecord
        ? $DB->get_field('modules', 'name', ['id' => (int) $cmrecord->module], IGNORE_MISSING)
        : false;
    if (!$cmrecord || $modulename !== 'h5pactivity'
            || !\local_rtcsync\local\delivery_activity_access::is_enabled((int) $cmrecord->course)) {
        return;
    }

    [$course, $cm] = get_course_and_cm_from_cmid((int) $cmrecord->id, 'h5pactivity');
    require_login($course, false, $cm);
    if (isguestuser()) {
        throw new \moodle_exception('activityiscurrentlyhidden', 'moodle');
    }
    \local_rtcsync\local\delivery_activity_access::require_student_module_access(
        (int) $course->id,
        (int) $cm->id,
        (int) $USER->id
    );
}

/**
 * Serve the files from the core_h5p file areas.
 *
 * @package core_h5p
 * @category files
 *
 * @param  stdClass $course the course object
 * @param  stdClass $cm the course module object
 * @param  stdClass $context the newmodule's context
 * @param  string $filearea the name of the file area
 * @param  array $args extra arguments (itemid, path)
 * @param  bool $forcedownload whether or not force download
 * @param  array $options additional options affecting the file serving
 *
 * @return bool Returns false if we don't find a file.
 */
function core_h5p_pluginfile($course, $cm, $context, string $filearea, array $args, bool $forcedownload,
    array $options = []): bool {
    global $DB;

    // Require classes from H5P third party library
    autoloader::register();

    $filesettingsset = false;

    switch ($filearea) {
        default:
            return false; // Invalid file area.

        case \core_h5p\file_storage::LIBRARY_FILEAREA:
            if ($context->contextlevel != CONTEXT_SYSTEM) {
                 return false; // Invalid context because the libraries are loaded always in the context system.
            }

            $itemid = null;

            // The files that can be delivered to this function are unfortunately out of our control. Some of the
            // references are embedded into the JavaScript of the files and we have no ability to inject an item id.
            // We also don't know the location of the item id when we do include it, so we look for the first numeric
            // value and try to serve that file.
            foreach ($args as $key => $value) {
                if (is_numeric($value)) {
                    $itemid = $value;
                    unset($args[$key]);
                    break;
                }
            }

            if (!isset($itemid)) {
                // We didn't find an item id to use, so we fall back to retrieving the record using all the other
                // fields. The combination of component, filearea, filepath, and filename is enough for a unique
                // record.
                $filename = array_pop($args);
                $filepath = '/' . implode('/', $args) . '/';
                $itemid = $DB->get_field('files', 'itemid', [
                    'component' => \core_h5p\file_storage::COMPONENT,
                    'filearea' => \core_h5p\file_storage::LIBRARY_FILEAREA,
                    'filepath' => $filepath,
                    'filename' => $filename
                ]);
                $filesettingsset = true;
            }
            break;
        case \core_h5p\file_storage::CONTENT_FILEAREA:
            if ($context->contextlevel != CONTEXT_SYSTEM) {
                return false; // Invalid context because the content files are loaded always in the context system.
            }
            $itemid = array_shift($args);
            if (is_numeric($itemid)) {
                core_h5p_require_delivery_activity_access((int) $itemid);
            }
            break;
        case \core_h5p\file_storage::CACHED_ASSETS_FILEAREA:
        case \core_h5p\file_storage::CSS_FILEAREA:
            $itemid = 0;
            break;
        case \core_h5p\file_storage::EXPORT_FILEAREA:
            $filename = end($args);
            if (is_string($filename)
                    && preg_match('/(?:^|-)([1-9][0-9]*)\.h5p$/i', $filename, $matches) === 1) {
                core_h5p_require_delivery_activity_access((int) $matches[1]);
            }
            $itemid = 0;
            break;
    }

    if (!$filesettingsset) {
        $filename = array_pop($args);
        $filepath = (!$args ? '/' : '/' . implode('/', $args) . '/');
    }

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, \core_h5p\file_storage::COMPONENT, $filearea, $itemid, $filepath, $filename);
    if (!$file) {
        return false; // No such file.
    }

    send_stored_file($file, null, 0, $forcedownload, $options);

    return true;
}
