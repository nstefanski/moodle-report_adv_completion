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
 * Version details.
 *
 * @package    report
 * @subpackage adv_completion
 * @copyright  2018 Nick Stefanski
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot.'/report/adv_completion/classes/event/grade_posted.php');

$courseid = optional_param('course', 0, PARAM_INT);
$user = optional_param('user', 0, PARAM_INT);
$rolec = optional_param('rolec', 0, PARAM_INT);
//$backto = optional_param('backto', null, PARAM_URL);
$grader = optional_param('grader', 0, PARAM_INT);
$grade = optional_param('grade', 0, PARAM_INT);
$gradeitem = optional_param('gradeitem', 0, PARAM_INT);

$backto = new moodle_url('/report/adv_completion/index.php', array('course'=>$course->id));
$backto->set_anchor('user-'.$user); //tk

$toggleurl = new moodle_url(
	'/course/togglecompletion.php',
	array(
		'user' => $user,
		'course' => $courseid,
		'rolec' => $rolec,
		'sesskey' => sesskey(),
		//'fromajax' => 1,
		'backto' => $backto->out()
	)
);

$cx = context_course::instance($courseid);
$event = \report_adv_completion\event\grade_posted::create(array('user' => $grader, 'relateduserid' => $user, 'context' => $cx, 'courseid' => $courseid, 'objectid' => $gradeitem, 
	'other' => array('relateduserid' => $user, 'grade' => $grade)));
$event->trigger();

redirect($toggleurl);
