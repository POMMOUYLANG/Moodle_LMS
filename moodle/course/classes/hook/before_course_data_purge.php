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

namespace core_course\hook;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Hook before course data is irreversibly purged.
 *
 * The emitter must check whether propagation was stopped and abort before it
 * performs any destructive work.
 *
 * @package    core_course
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins or features to prevent a course data purge.')]
#[\core\attribute\tags('course', 'delete', 'restore', 'reset', 'activity', 'grade')]
class before_course_data_purge implements StoppableEventInterface {
    use \core\hook\stoppable_trait;

    /** Course deletion operation. */
    public const OPERATION_DELETE = 'delete';

    /** Restore-overwrite operation. */
    public const OPERATION_RESTORE_OVERWRITE = 'restore_overwrite';

    /** Course reset operation. */
    public const OPERATION_RESET = 'reset';

    /** Course activity deletion operation. */
    public const OPERATION_ACTIVITY_DELETE = 'activity_delete';

    /** Grade deletion or bulk-clear operation. */
    public const OPERATION_GRADE_ITEM_GRADES_DELETE = 'grade_item_grades_delete';

    /** Final course unenrolment that would purge the user's course grades. */
    public const OPERATION_USER_UNENROL = 'user_unenrol';

    /**
     * Constructor.
     *
     * @param int $courseid Course whose data is about to be purged.
     * @param string $operation One of the OPERATION_* constants.
     * @param array $options Selected operation options, including scoped record identifiers.
     */
    public function __construct(
        /** @var int Course whose data is about to be purged */
        public readonly int $courseid,
        /** @var string Operation that will purge the course data */
        public readonly string $operation,
        /** @var array Selected options for the operation */
        public readonly array $options = [],
    ) {
    }
}
