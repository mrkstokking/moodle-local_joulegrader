<?php
defined('MOODLE_INTERNAL') or die('Direct access to this script is forbidden.');
require_once($CFG->dirroot . '/local/joulegrader/lib/pane/grade/abstract.php');

/**
 * joule Grader mod_assignment_submission grade pane class
 *
 * @author Sam Chaffee
 * @package local/joulegrader
 */
class local_joulegrader_lib_pane_grade_mod_assignment_submission_class extends local_joulegrader_lib_pane_grade_abstract {

    /**
     * Do some initialization
     */
    public function init() {
        global $USER;

        $assignment = $this->gradingarea->get_assignment();
        $submission = $this->gradingarea->get_submission();

        $this->courseid = $assignment->course->id;

        $this->gradinginfo = grade_get_grades($assignment->course->id, 'mod', 'assignment', $assignment->assignment->id, array($this->gradingarea->get_guserid()));

        $this->gradingdisabled = $this->gradinginfo->items[0]->grades[$this->gradingarea->get_guserid()]->locked;
        $this->gradeoverridden = $this->gradinginfo->items[0]->grades[$this->gradingarea->get_guserid()]->overridden;

        if (($gradingmethod = $this->gradingarea->get_active_gradingmethod()) && in_array($gradingmethod, self::get_supportedplugins())) {
            $controller = $this->gradingarea->get_gradingmanager()->get_controller($gradingmethod);
            $this->controller = $controller;
            if ($controller->is_form_available()) {
                $itemid = null;
                if (!empty($submission->id)) {
                    $itemid = $submission->id;
                }
                if ($this->gradingdisabled && $itemid) {
                    $this->gradinginstance = $controller->get_current_instance($USER->id, $itemid);
                } else if (!$this->gradingdisabled) {
                    $instanceid = optional_param('gradinginstanceid', 0, PARAM_INT);
                    $this->gradinginstance = $controller->get_or_create_instance($instanceid, $USER->id, $itemid);
                }

                $currentinstance = null;
                if (!empty($this->gradinginstance)) {
                    $currentinstance = $this->gradinginstance->get_current_instance();
                }
                $this->needsupdate = false;
                if (!empty($currentinstance) && $currentinstance->get_status() == gradingform_instance::INSTANCE_STATUS_NEEDUPDATE) {
                    $this->needsupdate = true;
                }
            } else {
                $this->advancedgradingerror = $controller->form_unavailable_notification();
            }
        }


        $this->teachercap = has_capability($this->gradingarea->get_teachercapability(), $this->gradingarea->get_gradingmanager()->get_context());
    }

    /**
     * Returns whether or not the area can be graded.
     *
     * @return bool
     */
    public function has_grading() {
        $hasgrading = true;
        $assignment = $this->get_gradingarea()->get_assignment();

        if ($assignment->assignment->grade == 0) {
            $hasgrading = false;
        }

        return $hasgrading;
    }

    /**
     * Returns true if the user has a "teacher" capability.
     *
     * @return bool
     */
    public function has_teachercap() {
        return $this->teachercap;
    }

    /**
     * Returns true if the grading area is using the modal form (e.g. advanced grading form in a modal)
     *
     * @return bool
     */
    public function has_modal() {
        return !(empty($this->controller) || empty($this->gradinginstance) || (!empty($this->controller) && !$this->controller->is_form_available()));
    }

    /**
     * Returns true if the advanced grading instance has the NEEDS_UPDATE_STATUS.
     *
     * @return mixed
     */
    public function get_needsupdate() {
        return $this->needsupdate;
    }

    /**
     * Get the course id
     *
     * @return int
     */
    public function get_courseid() {
        return $this->courseid;
    }

    /**
     * Get the "max" grade for the activity
     *
     * @return int
     */
    public function get_grade() {
        $assignment = $this->get_gradingarea()->get_assignment();
        return $assignment->assignment->grade;
    }

    /**
     * Should return true if the grade has been overridden in the gradebook and if Joule Grader will allow
     * a grade to be pushed to gradebook regardless.
     *
     * @return bool
     */
    public function has_override() {
        return !$this->gradingdisabled && $this->gradeoverridden;
    }

    /**
     * Get the current grade for the user for the grading area.
     *
     * @return int
     */
    public function get_currentgrade() {
        $submission = $this->gradingarea->get_submission();

        $grade = -1;
        if (!empty($submission) && isset($submission->grade)) {
            $grade = $submission->grade;
        }

        return $grade;
    }

    /**
     * Returns true if the grading area is using the pane form (e.g. simple grading)
     *
     * @return bool
     */
    public function has_paneform() {
        return (empty($this->controller) || empty($this->gradinginstance) || (!empty($this->controller) && !$this->controller->is_form_available()));
    }

    /**
     * Returns true if the user has active advanced grading instances.
     *
     * @return bool
     */
    public function has_active_gradinginstances() {
        $submission = $this->gradingarea->get_submission();
        return !(empty($submission) || !$this->controller->get_active_instances($submission->id));
    }

    /**
     * Returns the advanced grading "itemid" used in advanced grading controller's render_grade() method.
     *
     * @return null|int
     */
    public function get_agitemid() {
        $submission = $this->gradingarea->get_submission();
        return $submission->id;
    }

    /**
     * Process the grade data
     * @param $data
     * @param $notify
     * @throws moodle_exception
     */
    public function process($data, $notify) {
        //a little setup
        $assignment = $this->get_gradingarea()->get_assignment();
        $submission = $this->get_gradingarea()->get_submission(true);

        //set up a redirect url
        $redirecturl = new moodle_url('/local/joulegrader/view.php', array('courseid' => $assignment->course->id
                , 'garea' => $this->get_gradingarea()->get_areaid(), 'guser' => $this->get_gradingarea()->get_guserid()));

        //get the data from the form
        if ($data) {

//            if ($data->assignment != $assignment->assignment->id) {
//                //throw an exception, could be some funny business going on here
//                throw new moodle_exception('assignmentnotmatched', 'local_joulegrader');
//            }

            if (isset($data->gradinginstanceid)) {
                //using advanced grading
                $gradinginstance = $this->gradinginstance;
                $this->controller->set_grade_range(make_grades_menu($assignment->assignment->grade));
                $grade = $gradinginstance->submit_and_get_grade($data->grade, $submission->id);
            } else if ($assignment->assignment->grade < 0) {
                //scale grade
                $grade = clean_param($data->grade, PARAM_INT);
            } else {
                //just using regular grading
                $lettergrades = grade_get_letters(context_course::instance($assignment->course->id));
                $grade = $data->grade;

                //determine if user is submitting as a letter grade, percentage or float
                $touppergrade = textlib::strtoupper($grade);
                $toupperlettergrades = array_map('textlib::strtoupper', $lettergrades);
                if (in_array($touppergrade, $toupperlettergrades)) {
                    //submitting lettergrade, find percent grade
                    $percentvalue = 0;
                    $max = 100;
                    foreach ($toupperlettergrades as $value => $letter) {
                        if ($touppergrade == $letter) {
                            $percentvalue = ($max + $value) / 2;
                            break;
                        }
                        $max = $value - 1;
                    }

                    //transform to an integer within the range of the assignment
                    $grade = (int) ($assignment->assignment->grade * ($percentvalue / 100));

                } else if (strpos($grade, '%') !== false) {
                    //trying to submit percentage
                    $percentgrade = trim(strstr($grade, '%', true));
                    $percentgrade = clean_param($percentgrade, PARAM_FLOAT);

                    //transform to an integer within the range of the assignment
                    $grade = (int) ($assignment->assignment->grade * ($percentgrade / 100));

                } else if ($grade === '') {
                    //setting to "No grade"
                    $grade = -1;
                } else {
                    //just a numeric value, clean it as int b/c that's what assignment module accepts
                    $grade = clean_param($grade, PARAM_INT);
                }
            }

            //redirect to next user if set
            if (optional_param('saveandnext', 0, PARAM_BOOL) && !empty($data->nextuser)) {
                $redirecturl->param('guser', $data->nextuser);
            }

            if (optional_param('needsgrading', 0, PARAM_BOOL)) {
                $redirecturl->param('needsgrading', 1);
            }

            //save the grade
            if ($this->save_grade($grade, isset($data->override))) {
                $notify->good('gradesaved');
            }
        }

        redirect($redirecturl);
    }

    /**
     * Save the grades coming from grading forms
     *
     * @param $grade
     * @param $override
     *
     * @return bool
     */
    protected function save_grade($grade, $override) {
        global $USER, $DB;

        $success = true;

        //need to do two things here
        //1) update the grade in assignment_submission table
        //2) update the grade in the gradebook if the grade is NOT overridden or if it IS overridden AND the override check
        //   was checked

        $assignment = $this->get_gradingarea()->get_assignment();
        $submission = $this->get_gradingarea()->get_submission();

        $submission->grade      = $grade;
        $submission->teacher    = $USER->id;
        $mailinfo = get_user_preferences('assignment_mailinfo', 0);
        if (!$mailinfo) {
            $submission->mailed = 1;       // treat as already mailed
        } else {
            $submission->mailed = 0;       // Make sure mail goes out (again, even)
        }
        $submission->timemarked = time();

        unset($submission->data1);  // Don't need to update this.
        unset($submission->data2);  // Don't need to update this.

        //update the submission
        $success = $success && (bool) $DB->update_record('assignment_submissions', $submission);

        //now need to update the gradebook
        $gradeoverridden = $this->gradinginfo->items[0]->grades[$this->gradingarea->get_guserid()]->overridden;
        if (!$gradeoverridden) {
            $assignment->update_grade($submission);
        } else if ($override) {
            //try to fetch the gradeitem first
            $params = array('courseid' => $assignment->assignment->course, 'itemtype' => 'mod'
                    , 'itemmodule' => 'assignment', 'iteminstance' => $assignment->assignment->id);

            $gradeitem = grade_item::fetch($params);

            //if no grade item, create a new one
            if (empty($gradeitem)) {

                $params['itemname'] = $assignment->assignment->name;
                $params['idnumber'] = $assignment->assignment->cmidnumber;

                //set up additional params for the grade item
                if ($assignment->assignment->grade > 0) {
                    $params['gradetype'] = GRADE_TYPE_VALUE;
                    $params['grademax']  = $assignment->assignment->grade;
                    $params['grademin']  = 0;

                } else if ($assignment->assignment->grade < 0) {
                    $params['gradetype'] = GRADE_TYPE_SCALE;
                    $params['scaleid']   = -$assignment->assignment->grade;

                } else {
                    $params['gradetype'] = GRADE_TYPE_TEXT; // allow text comments only
                }

                //create and insert the new grade item
                $gradeitem = new grade_item($params);
                $gradeitem->insert();
            }

            //if grade is -1 in assignment_submissions table, it should be passed as null
            $grade = $submission->grade;
            if ($grade == -1) {
                $grade = null;
            }

            $success = $success && (bool) $gradeitem->update_final_grade($submission->userid, $grade, 'local/joulegrader', false, FORMAT_MOODLE, $submission->teacher);
        }

        return $success;
    }

    /**
     * Determines whether or not there is a grade for the current grading area/user
     *
     * @return boolean
     */
    public function not_graded() {
        $notgraded = false;

        $assignment = $this->get_gradingarea()->get_assignment();
        $submission = $this->get_gradingarea()->get_submission();

        if ($assignment->assignment->grade != 0) {
            //check the submission first
            if (!empty($submission) && $submission->grade == -1) {
                $notgraded = true;
            } else if (!empty($this->gradinginfo) && is_null($this->gradinginfo->items[0]->grades[$this->gradingarea->get_guserid()]->grade)) {
                //check the gradebook
                $notgraded = true;
            }
        }

        return $notgraded;
    }
}