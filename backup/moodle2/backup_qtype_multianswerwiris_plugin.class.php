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

defined('MOODLE_INTERNAL') || die();

/**
 * Provides the information to backup multianswerwiris questions
 */

class backup_qtype_multianswerwiris_plugin extends backup_qtype_multianswer_plugin {

    /**
     * Returns the qtype information to attach to question element
     */
    /*protected function define_question_plugin_structure() {
        // Define the virtual plugin element with the condition to fulfill
        $plugin = $this->get_plugin_element(null, '../../qtype', 'multianswerwiris');

        // Create one standard named plugin element (the visible container)
        $pluginwrapper = new backup_nested_element($this->get_recommended_name());

        // connect the visible container ASAP
        $plugin->add_child($pluginwrapper);

        // This qtype uses standard question_answers, add them here
        // to the tree before any other information that will use them
        $this->add_question_question_answers($pluginwrapper);

        // Now create the qtype own structures
        $multianswer = new backup_nested_element('multianswer', array('id'), array(
            'question', 'sequence'));
        $questionxml = new backup_nested_element('question_xml', array('id'), array('xml'));

        // Now the own qtype tree
        $pluginwrapper->add_child($multianswer);
        $pluginwrapper->add_child($question_xml);

        // set source to populate the data
        $multianswer->set_source_table('question_multianswer',
                array('question' => backup::VAR_PARENTID));
        $question_xml->set_source_table('qtype_wq', array('question' => backup::VAR_PARENTID));

        return $plugin;
    }*/

    protected function define_question_plugin_structure() {
        // Call parent.
        $plugin = parent::define_question_plugin_structure();

        // Change type.
        $plugin->set_condition('../../qtype', 'multianswerwiris');

        // Add question_xml.
        $pluginwrapper = $plugin->get_child($this->get_recommended_name());
        $questionxml = new backup_nested_element('question_xml', array('id'), array('xml'));
        $pluginwrapper->add_child($questionxml);
        $questionxml->set_source_table('qtype_wq', array('question' => backup::VAR_PARENTID));

        return $plugin;

    }
}
