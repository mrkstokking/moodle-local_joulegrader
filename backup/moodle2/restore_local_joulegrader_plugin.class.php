<?php
/**
 * joule Grader restore plugin
 *
 * @author Sam Chaffee
 * @package local/joulegrader
 */
class restore_local_joulegrader_plugin extends restore_local_plugin {
    /**
     * @return array
     */
    protected function define_area_plugin_structure() {
        // Only restore if user info is being included
        if (!$this->get_setting_value('userinfo')) {
            return array();
        }

        $modulename = $this->task->get_modulename();
        // Only restore if the activity supports advanced grading
        if (!plugin_supports('mod', $modulename, FEATURE_ADVANCED_GRADING, false)) {
            return array();
        }

        // Return the paths
        return array(
            new restore_path_element('joulegrader_comment', $this->get_pathfor('/comments/comment')),
        );
    }

    /**
     * Restore a single comment
     *
     * @param stdClass $data
     */
    public function process_joulegrader_comment($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        // Remap user ids
        $data->guserid = $this->get_mappingid('user', $data->guserid);
        $data->commenterid = $this->get_mappingid('user', $data->commenterid);

        // Remap grading area id
        $data->gareaid = $this->get_new_parentid('grading_area');

        // Insert new comment record
        $newitemid = $DB->insert_record('local_joulegrader_comments', $data);
        $this->set_mapping('comment', $oldid, $newitemid, true);
    }

    /**
     * Add related files
     */
    public function after_execute_area() {
       $this->add_related_files('local_joulegrader', 'comment', 'comment');
    }
}