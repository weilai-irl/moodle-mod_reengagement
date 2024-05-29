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
 * Ad-hoc task to process email sending.
 *
 * @package     mod_reengagement
 * @copyright   (c) 2024, Enovation Solutions
 * @author      Lai Wei <lai.wei@enovation.ie>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_reengagement\task;

use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/reengagement/lib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/enrollib.php');

/**
 * Ad-hoc task to process email sending.
 *
 * @copyright  (c) 2024, Enovation Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_email extends reengagement_adhoc_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('adhoctasksendemailtask', 'reengagement');
    }

    /**
     * Do the job.
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();

        $timenow = time();

        $reengagementdata = $data->reengagement;
        $inprogressdata = $data->inprogress;
        $userid = $inprogressdata->userid;

        try {
            [$reengagement, $inprogress, $context] = self::validate_task($reengagementdata, $inprogressdata);
        } catch (moodle_exception $e) {
            // Validation failed, end task.
            return;
        }

        if ($inprogress->completed == COMPLETION_COMPLETE) {
            mtrace("mode $reengagement->emailuser reengagementid $reengagement->id. " .
                "User already marked complete. Deleting inprogress record for user $userid");
            $result = $DB->delete_records('reengagement_inprogress', ['id' => $inprogress->id]);
        } else {
            mtrace("mode $reengagement->emailuser reengagementid $reengagement->id. " .
                "Updating inprogress record to indicate email sent for user $userid");
            $updaterecord = new stdClass();
            $updaterecord->id = $inprogress->id;
            if ($reengagement->remindercount > $inprogress->emailsent) {
                $updaterecord->emailtime = $timenow + $reengagement->emaildelay;
            }
            $updaterecord->emailsent = $inprogress->emailsent + 1;
            $result = $DB->update_record('reengagement_inprogress', $updaterecord);
        }
        if (!empty($result)) {
            mtrace("Reengagement: sending email to $userid regarding reengagementid $reengagement->id due to emailduetime.");
            $result = reengagement_email_user($reengagement, $inprogress);

            // Queue next email if required.
            if ($result && $inprogress->completed != COMPLETION_COMPLETE &&
                $reengagement->remindercount > $inprogress->emailsent + 1) {
                mtrace("Queueing next email for user $userid");
                reengagement_queue_email_task($reengagementdata, $inprogressdata, $updaterecord->emailtime);
                $DB->set_field('reengagement_inprogress', 'emailtime', $updaterecord->emailtime, ['id' => $inprogress->id]);
            }
        }
    }
}