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
 * Ad-hoc task to process in progress reengagement completion.
 *
 * @package     mod_reengagement
 * @copyright   (c) 2024, Enovation Solutions
 * @author      Lai Wei <lai.wei@enovation.ie>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_reengagement\task;

use cache;
use context_module;
use core\event\course_module_completion_updated;
use core\task\adhoc_task;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/reengagement/lib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/enrollib.php');

class mark_complete extends adhoc_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('adhoctaskmarkcompletetask', 'reengagement');
    }

    /**
     * Do the job.
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();

        $timenow = time();

        $reengagementdata = $data->reengagement;
        $cmid = $reengagementdata->cmid;
        $inprogressdata = $data->inprogress;
        $userid = $inprogressdata->userid;

        // Check if user is still enrolled in the course.
        $context = context_module::instance($cmid);
        if (!is_enrolled($context, $userid, 'mod/reengagement:startreengagement', true)) {
            mtrace("Reengagement: invalid inprogressid $inprogressdata->id. Delete the in progress record and the task.");
            $DB->delete_records('reengagement_inprogress', ['id' => $inprogressdata->id]);

            return;
        }

        // Ensure the in progress record still exists.
        if (!$inprogress = $DB->get_record('reengagement_inprogress', ['id' => $inprogressdata->id])) {
            mtrace("Reengagement: invalid inprogressid $inprogressdata->id. Delete the task.");

            return;
        }

        // Ensure the reengagement activity is still exists.
        if (!$reengagement = $DB->get_record('reengagement', ['id' => $reengagementdata->rid])) {
            mtrace("Reengagement: invalid reengagementid $reengagementdata->rid. Delete the in progress record and the task.");
            $DB->delete_records('reengagement_inprogress', ['id' => $inprogress->id]);

            return;
        }

        // Ensure the user still exists.
        if (!$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            mtrace("Reengagement: invalid userid $userid. Delete the in progress record and the task.");
            $DB->delete_records('reengagement_inprogress', ['id' => $inprogress->id]);

            return;
        }

        // Update completion record to indicate completion so the user can continue with any dependant activities.
        $completionrecord = $DB->get_record('course_modules_completion', ['coursemoduleid' => $cmid, 'userid' => $userid]);
        if (empty($completionrecord)) {
            mtrace("Could not find completion record to update complete state, userid: $userid, cmid: $cmid - recreating record.");
            // This might happen when reset_all_state has been triggered, deleting an "in-progress" record. so recreate it.
            $completionrecord = new stdClass();
            $completionrecord->coursemoduleid = $cmid;
            $completionrecord->completionstate = COMPLETION_COMPLETE_PASS;
            $completionrecord->viewed = COMPLETION_VIEWED;
            $completionrecord->overrideby = null;
            $completionrecord->timemodified = $timenow;
            $completionrecord->userid = $userid;
            $completionrecord->id = $DB->insert_record('course_modules_completion', $completionrecord);
        } else {
            mtrace("Updating activity complete state to completed, userid: $userid, cmid: $cmid.");
            $updaterecord = new stdClass();
            $updaterecord->id = $completionrecord->id;
            $updaterecord->completionstate = COMPLETION_COMPLETE_PASS;
            $updaterecord->timemodified = $timenow;
            $DB->update_record('course_modules_completion', $updaterecord) . " \n";
        }
        $completioncache = cache::make('core', 'completion');
        $completioncache->delete($userid . '_' . $reengagement->course);

        // Trigger an event for course module completion changed.
        $event = course_module_completion_updated::create([
            'objectid' => $completionrecord->id,
            'context' => $context,
            'relateduserid' => $userid,
            'other' => [
                'relateduserid' => $userid,
            ],
        ]);
        $event->add_record_snapshot('course_modules_completion', $completionrecord);
        $event->trigger();

        if (($reengagement->emailuser == REENGAGEMENT_EMAILUSER_COMPLETION) ||
            ($reengagement->emailuser == REENGAGEMENT_EMAILUSER_NEVER) ||
            ($reengagement->emailuser == REENGAGEMENT_EMAILUSER_TIME && !empty($inprogressdata->emailsent))) {
            // No need to keep 'inprogress' record for later emailing.
            // Delete inprogress record.
            mtrace("mode $reengagement->emailuser reengagementid $reengagement->id. " .
                "User marked complete, deleting inprogress record for user $userid");
            $result = $DB->delete_records('reengagement_inprogress', ['id' => $inprogress->id]);
        } else {
            // Update inprogress record to indicate completion done.
            mtrace("mode $reengagement->emailuser reengagementid $reengagement->id. " .
                "Updating inprogress record for user $userid to indicate completion");
            $updaterecord = new stdClass();
            $updaterecord->id = $inprogress->id;
            $updaterecord->completed = COMPLETION_COMPLETE;
            $result = $DB->update_record('reengagement_inprogress', $updaterecord);
        }
        if (empty($result)) {
            // Skip emailing. Go on to next completion record so we don't risk emailing users continuously each cron.
            mtrace("Reengagement: not sending email to $userid regarding reengagementid $reengagement->id " .
                "due to failure to update db.");
        } else if ($reengagement->emailuser == REENGAGEMENT_EMAILUSER_COMPLETION) {
            mtrace("Reengagement: sending email to $userid regarding reengagementid $reengagement->id due to completion.");
            reengagement_email_user($reengagement, $inprogress);
        }
    }
}

