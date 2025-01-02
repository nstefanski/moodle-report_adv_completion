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
		//$nick = $DB->get_record('user', array('id'=>'4'));
		//email_to_user($nick, $nick, "Event listener working for " . $event->relateduserid, serialize($event), serialize($event) );
		
		//check for a 'manual completion by others' criteria in this course
		$ccc = $DB->get_record('course_completion_criteria', array('course' => $event->courseid, 'criteriatype' => 7));
		
		if($ccc){
			require_once($CFG->libdir.'/gradelib.php');
			require_once($CFG->dirroot.'/grade/querylib.php');
			
			//get user's CVue id
			$user = get_complete_user_data('id', $event->relateduserid);
			if ($user->profile['cvueid'] > 0) {
				$StudentId = $user->profile['cvueid'];
			} elseif ($user->idnumber > 0) {
				//$StudentId = cvGetSyStudentId($user->idnumber, $token);
			}
			
			//get ClassSectionId
			$course = get_course($event->courseid);
			$sql = "SELECT COALESCE( (SELECT MAX(CAST(g.name AS UNSIGNED)) FROM {groups} g JOIN {groups_members} gm
					WHERE g.courseid = c.id AND gm.groupid = g.id AND gm.userid = $event->relateduserid ),
					CAST(c.idnumber AS UNSIGNED)) AS cvid FROM {course} c WHERE c.id = $event->courseid ";
			$ClassSectionId = reset($DB->get_records_sql($sql))->cvid;
			
			//get user's course grade
			$grading_info = grade_get_course_grades($event->courseid, $event->relateduserid);
			$grade_item = grade_item::fetch(array('itemtype'=>'course', 'courseid'=>$event->courseid));
			$rawgrade = $grading_info->grades[$event->relateduserid]->grade;
			if( !is_null($rawgrade) ){
				$grade = grade_format_gradevalue($rawgrade, $grade_item);
			}
			
			if($StudentId && $ClassSectionId && !is_null($grade)){
				require_once($CFG->dirroot.'/local/campusvue/lib.php');
				//email_to_user($nick, $nick, "Updating grade for user $StudentId in course $ClassSectionId to $grade", $grade, $grade );
				
				//get existing StudentCourse
				$queryURL = "/ds/campusnexus/StudentCourses?%24filter=StudentId%20eq%20$StudentId%20and%20ClassSectionId%20eq%20$ClassSectionId";
				$StudentCourse = cvCurlWrap($queryURL, null, 'GET')->value[0];
				//email_to_user($nick, $nick, "Got existing StudentCourse", serialize($StudentCourse), serialize($StudentCourse) );
				
				//set grade vars
				switch (true) {
				  case  ($grade >= 90):
					$gp = 4;
					$letter = "A";
					$pass = true;
					break;
				  case  ($grade >= 80):
					$gp = 3;
					$letter = "B";
					$pass = true;
					break;
				  case  ($grade >= 70):
					$gp = 2;
					$letter = "C";
					$pass = true;
					break;
				  case  ($grade >= 60):
					$gp = 1;
					$letter = "D";
					$pass = true;
					break;
				  case  ($grade >= 0):
					$gp = 0;
					$letter = "F";
					$pass = false;
					break;
				  default:
					//cannot evaluate grade...
				}
        
				$todayString = explode('T', date('Y-m-d\TH:i:s'))[0] . 'T00:00:00';
				
				//set campus
				switch ($user->profile['campus']) {
				  case "Escoffier Austin":
					$campus = 5;
					break;
				  case "Escoffier Boulder":
					$campus = 6;
					break;
				  case "Escoffier Online":
					$campus = 7;
					break;
				  case "Escoffier Boulder Online":
				  default:
					$campus = 8;
				}
				
				//change necessary values
				$updateCourse = $StudentCourse;
				$updateCourse->ClockHoursAttempted = $StudentCourse->ClockHours;
				$updateCourse->ClockHoursEarned = $pass ? $StudentCourse->ClockHours : 0; //0 if fail
				$updateCourse->CreditHoursAttempted = $StudentCourse->CreditHours;
				$updateCourse->CreditHoursEarned = $pass ? $StudentCourse->CreditHours : 0; //0 if fail
				$updateCourse->EndDate = $StudentCourse->ExpectedEndDate;
				$updateCourse->ExpectedEndDate = null;
				$updateCourse->GradePoints = $gp;
				$updateCourse->GradePostedDate = $todayString;
				$updateCourse->LetterGrade = $letter;
				$updateCourse->LmsExtractStatus = 1; //tk set
				$updateCourse->NumericGrade = $grade;
				$updateCourse->PreviousStatus = $StudentCourse->Status;
				$updateCourse->QualityPoints = $pass ? ($gp * $updateCourse->CreditHoursEarned) : null; //GradePoints * CreditHours, null for Fail
				$updateCourse->Status = "P"; //always P, even for fails (withdraws are "D")
				
				$overwriteGrade = ($StudentCourse->RetakeFlag == "C") ? true : false; //tk 11/28/18
				
				//push to CVue
				$actionURL = "/api/commands/Academics/StudentCourse/PostFinalGrade";
				$postfields = json_encode( (object) array("payload" =>
					(object) array("PostFinalGradeForExistingGrade" => $overwriteGrade,
						"AllowOverrideExpectedDeadlineDate" => false,
						"CampusId" => "$campus",
						"StudentCourse" => $updateCourse) ) );
				$grade_result = cvCurlWrap($actionURL, $postfields, 'POST');
				//email_to_user($nick, $nick, "Posted updated StudentCourse", serialize($grade_result), serialize($grade_result) );
      	
				//confirm edited StudentCourse -- testing only
				//$queryURL = "/ds/campusnexus/StudentCourses?%24filter=StudentId%20eq%20$StudentId%20and%20ClassSectionId%20eq%20$ClassSectionId";
				//$StudentCourse = cvCurlWrap($queryURL, null, 'GET')->value[0];
				//email_to_user($nick, $nick, "New StudentCourse info", serialize($StudentCourse), serialize($StudentCourse) );
				
				//now let's push attendance...
				//get all the student's completion minutes:
				$sql = "SELECT SUM(active) + SUM(live) AS mins FROM (
						  SELECT SUM( CASE WHEN is_live = 0 THEN mins ELSE 0 END ) AS active,
							MAX( CASE WHEN is_live = 1 THEN mins ELSE 0 END ) AS live FROM (
							
							SELECT cm.id, cm.section, COALESCE( z.duration / 60, RIGHT(cm.idnumber,4)*1 ) AS mins,
							  CASE WHEN cm.idnumber LIKE 'archive%' OR cm.module = 33 THEN 1 ELSE 0 END AS is_live
							FROM {course_modules_completion} cmc
							  JOIN {course_modules} cm ON cmc.coursemoduleid = cm.id
								LEFT JOIN {zoom} z ON cm.instance = z.id AND cm.module = 33 #ZOOM_ID
								JOIN {course} c ON cm.course = c.id
								  JOIN {course_categories} cc ON c.category = cc.id
							WHERE cm.course = $event->courseid AND cmc.userid = $event->relateduserid AND cc.path LIKE '/42/43%'
							  AND cm.visible = 1 AND cmc.completionstate > 0
							  
						  ) AS comp GROUP BY section
						) AS sums";
				$att_mins = reset($DB->get_records_sql($sql))->mins;
				
				if($att_mins){
					require_once($CFG->dirroot.'/local/campusvue/classes/cvAttendancesMsg.php');
					
					//get max att mins for course (CVue REST API)
					$queryURL = "/ds/campusnexus/ClassSectionMeetingDates?%24filter=ClassSectionId%20eq%20$ClassSectionId&%24select=Id,LengthMinutes%20and%20ClassSectionMeetingPatternId%20gt%200";
					$meeting_dates = cvCurlWrap($queryURL, null, 'GET')->value;
					$max_mins = 0;
					foreach($meeting_dates AS $meeting_date){
						$max_mins += $meeting_date->LengthMinutes;
					}
					$att_mins = ($att_mins > $max_mins && $max_mins > 0) ? $max_mins : $att_mins; //cap at max
					
					//convert startdate to timestamp
					$startdate = date('Y-m-d', $course->startdate).'T00:00:00';
					
					//send attendance message via SOAP
					$msg = new cvAttendancesMsg();
					$msg->addAttendance($StudentId, $ClassSectionId, $startdate, null, $att_mins, false, false, false, false);
					//email_to_user($nick, $nick, "Ready to post attendance", serialize($msg), serialize($msg) );
					if ($msg->Attendances) {
						try {
							$att_result = $msg->postAttendanceTransaction();
						} catch (moodle_exception $e) {
							$att_result = $e;
						}
					}
					//email_to_user($nick, $nick, "Attendance result", serialize($att_result), serialize($att_result) );
					
					//lastly, we'll try to update LDA
					//get LDA
					$sql = "SELECT MAX(cmc.timemodified) AS lda
							FROM {course_modules_completion} cmc
								JOIN {course_modules} cm ON cmc.coursemoduleid = cm.id
									JOIN {course_sections} cs ON cm.section = cs.id
									JOIN {course} c ON cm.course = c.id
										JOIN {course_categories} cc ON c.category = cc.id
							WHERE cmc.userid = $event->relateduserid AND cmc.completionstate > 0
								AND cm.visible = 1 AND cs.section > 0
								AND cc.path LIKE '/42/43%'
								AND c.shortname NOT LIKE '%orientation%'";
					$unix_ts = reset($DB->get_records_sql($sql))->lda;
					if($unix_ts){
						$LDA = date('Y-m-d', $unix_ts).'T00:00:00';
						
						//get AdEnrollId (CVue REST API)
						$queryURL = "/ds/campusnexus/StudentEnrollmentPeriods?%24filter=Student/Id%20eq%20$StudentId&%24select=Id";
						$enrollment_periods = cvCurlWrap($queryURL, null, 'GET')->value;
						//$AdEnrollId = $enrollment_periods[count($enrollment_periods)-1]->Id; //last element
						$AdEnrollId = $enrollment_periods[0]->Id; //first element seems more accurate, we'll need some logging to check this
						//email_to_user($nick, $nick, "Update LDA for AdEnrollId $AdEnrollId to $LDA", $LDA, $LDA );
						
						//send LDA via SOAP
						try {
							$lda_result = cvUpdateLDA($AdEnrollId, $LDA);
						} catch (moodle_exception $e) {
							$lda_result = $e;
						}
						//email_to_user($nick, $nick, "LDA result", serialize($lda_result), serialize($lda_result) );
					}
				}
			}
		}
	}
	
}
