<?php
defined('MOODLE_INTERNAL') or die('Direct access to this script is forbidden.');
require_once($CFG->dirroot . '/local/joulegrader/lib/pane/grade/abstract.php');

/**
 * joule Grader mod_hsuforum_posts grade pane class
 *
 * @author Sam Chaffee
 * @package local/joulegrader
 */
class local_joulegrader_lib_pane_grade_mod_hsuforum_posts_class extends local_joulegrader_lib_pane_grade_abstract {

    protected $cm;
    /**
     * @var context_module
     */
    protected $context;
    protected $forum;

    /**
     * @var gradingform_controller
     */
    protected $controller;

    /**
     * @var gradingform_instance
     */
    protected $gradinginstance;

    /**
     * @var boolean
     */
    protected $teachercap;

    /**
     * Do some initialization
     */
    public function init() {
        global $DB, $USER;

        $this->context = $this->gradingarea->get_gradingmanager()->get_context();
        $this->cm      = get_coursemodule_from_id('hsuforum', $this->context->instanceid, 0, false, MUST_EXIST);
        $this->forum   = $DB->get_record('hsuforum', array('id' => $this->cm->instance), '*', MUST_EXIST);
        $this->courseid = $this->cm->course;

        $this->gradinginfo = grade_get_grades($this->cm->course, 'mod', 'hsuforum', $this->forum->id, array($this->gradingarea->get_guserid()));

        $this->gradingdisabled = $this->gradinginfo->items[0]->grades[$this->gradingarea->get_guserid()]->locked;

        if (($gradingmethod = $this->gradingarea->get_active_gradingmethod()) && in_array($gradingmethod, self::get_supportedplugins())) {
            $this->controller = $this->gradingarea->get_gradingmanager()->get_controller($gradingmethod);
            if ($this->controller->is_form_available()) {
                if ($this->gradingdisabled) {
                    $this->gradinginstance = $this->controller->get_current_instance($USER->id, $this->gradingarea->get_guserid());
                } else {
                    $instanceid = optional_param('gradinginstanceid', 0, PARAM_INT);
                    $this->gradinginstance = $this->controller->get_or_create_instance($instanceid, $USER->id, $this->gradingarea->get_guserid());
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
                $this->advancedgradingerror = $this->controller->form_unavailable_notification();
            }
        }


        $this->teachercap = has_capability($this->gradingarea->get_teachercapability(), $this->context);
    }

    /**
     * Returns whether or not the area can be graded.
     *
     * @return bool
     */
    public function has_grading() {
        $hasgrading = true;

        if ($this->forum->scale == 0) {
            $hasgrading = false;
        }

        return $hasgrading;
    }

    /**
     * Get the current grade for the user for the grading area.
     *
     * @return int
     */
    public function get_currentgrade() {
        return $this->gradinginfo->items[0]->grades[$this->gradingarea->get_guserid()]->grade;
    }

    /**
     * Returns true if the user has active advanced grading instances.
     *
     * @return bool
     */
    public function has_active_gradinginstances() {
        return $this->controller->get_active_instances($this->gradingarea->get_guserid());
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
     * Get the "max" grade for the activity
     *
     * @return int
     */
    public function get_grade() {
        return $this->forum->scale;
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
     * Get the course id
     *
     * @return int
     */
    public function get_courseid() {
        return $this->courseid;
    }

    /**
     * Should return true if the grade has been overridden in the gradebook and if Joule Grader will allow
     * a grade to be pushed to gradebook regardless.
     *
     * @return bool
     */
    public function has_override() {
        return false;
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
     * Returns the advanced grading "itemid" used in advanced grading controller's render_grade() method.
     *
     * @return null|int
     */
    public function get_agitemid() {
        return $this->gradingarea->get_guserid();
    }

    /**
     * Process the grade data
     * @param $data
     * @param mr_html_notify $notify
     * @throws moodle_exception
     */
    public function process($data, $notify) {
        //set up a redirect url
        $redirecturl = new moodle_url('/local/joulegrader/view.php', array('courseid' => $this->cm->course
                , 'garea' => $this->get_gradingarea()->get_areaid(), 'guser' => $this->get_gradingarea()->get_guserid()));

        //get the data from the form
        if ($data) {

//            if ($data->instance != $this->forum->id) {
//                //throw an exception, could be some funny business going on here
//                throw new moodle_exception('assignmentnotmatched', 'local_joulegrader');
//            }

            if (isset($data->gradinginstanceid)) {
                //using advanced grading
                $gradinginstance = $this->gradinginstance;
                $this->controller->set_grade_range(make_grades_menu($this->forum->scale));
                $grade = $gradinginstance->submit_and_get_grade($data->grade, $this->gradingarea->get_guserid());
            } else if ($this->forum->scale < 0) {
                //scale grade
                $grade = clean_param($data->grade, PARAM_INT);
            } else {
                //just using regular grading
                $lettergrades = grade_get_letters(context_course::instance($this->cm->course));
                $grade = $data->grade;

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
                    $grade = (int) ($this->forum->scale * ($percentvalue / 100));

                } else if (strpos($grade, '%') !== false) {
                    //trying to submit percentage
                    $percentgrade = trim(strstr($grade, '%', true));
                    $percentgrade = clean_param($percentgrade, PARAM_FLOAT);

                    //transform to an integer within the range of the assignment
                    $grade = (int) ($this->forum->scale * ($percentgrade / 100));

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
     * @return bool
     */
    public function is_validated() {
        $validated = $this->mform->is_validated();
        return $validated;
    }

    /**
     * Returns whether or not there is a grade yet for the area/user
     *
     * @return boolean
     */
    public function not_graded() {
        if (!empty($this->gradinginfo) && is_null($this->gradinginfo->items[0]->grades[$this->gradingarea->get_guserid()]->grade)) {
            return true;
        }
        return false;
    }

    /**
     * @param $grade
     * @param $override
     *
     * @return bool
     */
    protected function save_grade($grade, $override) {
        $gradeitem = grade_item::fetch(array(
            'courseid'     => $this->cm->course,
            'itemtype'     => 'mod',
            'itemmodule'   => 'hsuforum',
            'iteminstance' => $this->forum->id,
            'itemnumber'   => 0,
        ));

        //if no grade item, create a new one
        if (!empty($gradeitem)) {
            //if grade is -1 in assignment_submissions table, it should be passed as null
            if ($grade == -1) {
                $grade = null;
            }
            return $gradeitem->update_final_grade($this->gradingarea->get_guserid(), $grade, 'local/joulegrader');
        }
        return false;
    }
}