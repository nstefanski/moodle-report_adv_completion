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
 * Event observers used in Report user advanced completion plugin
 *
 * @package    report_adv_completion
 * @copyright  2020 Nick Stefanski <nmstefanski@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Event observer for report_adv_completion.
 */
class report_adv_completion_observer {

    /**
     * Triggered via course_completed event.
     *
     * @param \core\event\course_completed $event
	 * @return void
     */
  public static function course_completed(\core\event\course_completed $event) {
		global $CFG, $DB;
		//DEBUG
		$nick = $DB->get_record('user', array('id'=>'4'));
		//email_to_user($nick, $nick, "Event listener working for user " . $event->relateduserid, null, serialize($event) );
		
		//check for a 'manual completion by others' criteria in this course
		$ccc = $DB->get_record('course_completion_criteria', array('course' => $event->courseid, 'criteriatype' => 7));
		
		if($ccc){
			//email_to_user($nick, $nick, "Criteria found for user " . $event->relateduserid, null, serialize($ccc) );
			require_once($CFG->libdir.'/gradelib.php');
			require_once($CFG->dirroot.'/grade/querylib.php');
			
			//get user & course info
			$user = get_complete_user_data('id', $event->relateduserid);
			$course = get_course($event->courseid);
			
			//get user's course grade
			$grading_info = grade_get_course_grades($event->courseid, $event->relateduserid);
			$grade_item = grade_item::fetch(array('itemtype'=>'course', 'courseid'=>$event->courseid));
			$rawgrade = $grading_info->grades[$event->relateduserid]->grade;
			//email_to_user($nick, $nick, "Raw grade for user " . $event->relateduserid, null, serialize($rawgrade) );
			if( !is_null($rawgrade) ){
				$finalgrade = grade_format_gradevalue($rawgrade, $grade_item, true, GRADE_DISPLAY_TYPE_REAL);
				if($grade_item->scale){
					$letter = $finalgrade;
					$finalgrade = null;
				} else {
					$letter = grade_format_gradevalue($rawgrade, $grade_item, true, GRADE_DISPLAY_TYPE_LETTER);
				}
			}
			//email_to_user($nick, $nick, "Grade and letter for user " . $event->relateduserid, null, serialize(array($finalgrade, $letter)) );
			
			if($user->idnumber && $course->idnumber && !is_null($letter)){
				require_once($CFG->dirroot.'/local/salesforce_attendance/locallib.php');
				//email_to_user($nick, $nick, "Updating grade for user $user->id in course $course->id to $finalgrade($letter)", null, serialize($grade_item) );
				
				//get Salesforce enrollment
				$token = get_salesforce_token();
				$enrollment = salesforce_query( array('Id','Course_Score__c','Letter_Grade__c','RPL__c','Final__c'), 'mdl_Enrollment__c',
							array('Contact__c' => $user->idnumber, 'Course__c' => $course->idnumber), $token);
				//email_to_user($nick, $nick, "Got existing enrollment", null, serialize($enrollment) );
				
				//push to SF
				if($enrollment->records[0]->Id){
					if($enrollment->records[0]->RPL__c != "" || $enrollment->records[0]->Letter_Grade__c == "W"
						 || $enrollment->records[0]->Final__c){
						//no update
					} else {
						$result = make_salesforce_call('sobjects/mdl_Enrollment__c/' . $enrollment->records[0]->Id,
						                               array('Course_Score__c' => $finalgrade, 'Letter_Grade__c' => $letter, 'Final__c' => 1),
						                               $method = 'PATCH', $token);
					}
				}
				//email_to_user($nick, $nick, "Posted updated grade", null, serialize($result) );
			}
		}
	}
	
}
