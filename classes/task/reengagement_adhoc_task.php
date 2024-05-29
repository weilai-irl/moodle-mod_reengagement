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
 * Parent ad-hoc task class for reengagement that contains common methods.
 *
 * @package     mod_reengagement
 * @copyright   (c) 2024, Enovation Solutions
 * @author      Lai Wei <lai.wei@enovation.ie>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_reengagement\task;

use context_module;
use core\task\adhoc_task;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Parent ad-hoc task class for reengagement that contains common methods.
 *
 * @copyright  (c) 2024, Enovation Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class reengagement_adhoc_task extends adhoc_task {
    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('adhoctaskreengagementtask', 'reengagement');
    }

    /**
     * Do the job - nothing to execute.
     */
    public function execute() {
        // Nothing to execute.
    }

    /**
     * Validate the task.
     *
     * @param object $reengagementdata The reengagement data
     * @param object $inprogressdata The in progress data
     * @return array
     * @throws moodle_exception
     */
    protected function validate_task($reengagementdata, $inprogressdata) {
        global $DB;

        $userid = $inprogressdata->userid;

        // Ensure the user still exists.
        if (!$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            mtrace("Reengagement: invalid userid $userid. Delete the in progress record and the task.");
            $DB->delete_records('reengagement_inprogress', ['id' => $inprogressdata->id]);

            throw new moodle_exception('errorinvalidtask', 'mod_reengagement');
        }

        // Check if the user is still enrolled in the course.
        $context = context_module::instance($reengagementdata->cmid);
        if (!is_enrolled($context, $userid, 'mod/reengagement:startreengagement', true)) {
            mtrace("Reengagement: user $userid is not enrolled in the course any more. " .
                "Delete the in progress record and the task.");
            $DB->delete_records('reengagement_inprogress', ['id' => $inprogressdata->id]);

            throw new moodle_exception('errorinvalidtask', 'mod_reengagement');
        }

        // Ensure the in progress record still exists.
        if (!$inprogress = $DB->get_record('reengagement_inprogress', ['id' => $inprogressdata->id])) {
            mtrace("Reengagement: invalid inprogressid $inprogressdata->id. Delete the task.");

            throw new moodle_exception('errorinvalidtask', 'mod_reengagement');
        }

        // Ensure the reengagement activity is still exists.
        if (!$reengagement = $DB->get_record('reengagement', ['id' => $reengagementdata->rid])) {
            mtrace("Reengagement: invalid reengagementid $reengagementdata->rid. Delete the in progress record and the task.");
            $DB->delete_records('reengagement_inprogress', ['id' => $inprogress->id]);

            throw new moodle_exception('errorinvalidtask', 'mod_reengagement');
        }

        return [$reengagement, $inprogress, $context];
    }
}