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

class restore_qtype_multianswerwiris_plugin extends restore_qtype_multianswer_plugin {

    /**
     * Returns the paths to be handled by the plugin at question level.
     */
    protected function define_question_plugin_structure() {
        $paths = array();

        // This qtype uses question_answers, add them.
        $this->add_question_question_answers($paths);

        // Add own qtype stuff.
        $elename = 'multianswer';
        $xmlname = 'qtype_wq_multianswerwiris';

        $elepath = $this->get_pathfor('/multianswer');
        $xmlpath = $this->get_pathfor('/question_xml');

        $paths[] = new restore_path_element($elename, $elepath);
        $paths[] = new restore_path_element($xmlname, $xmlpath);

        return $paths; // And we return the interesting paths.
    }

    public static function convert_backup_to_questiondata(array $backupdata): \stdClass {

        // Moodle abstract implementation for this function assumes that the qtype plugin options are stored in the
        // ['plugin_qtype_{qtypename}_question']['{qtypename}'] array, so we need map the options from the base qtype.
        if (isset($backupdata['plugin_qtype_multianswerwiris_question']['multianswer'])) {
            $backupdata['plugin_qtype_multianswerwiris_question']['multianswerwiris'] = $backupdata['plugin_qtype_multianswerwiris_question']['multianswer'];
        }

        // Convert the backup data to question data.
        $questiondata = parent::convert_backup_to_questiondata($backupdata);

        // Include Wiris question XML if it exists.
        if (isset($backupdata['plugin_qtype_multianswerwiris_question']['question_xml'][0]['xml'])) {
            $questiondata->options->wirisquestion = $backupdata['plugin_qtype_multianswerwiris_question']['question_xml'][0]['xml'];
        }

        return $questiondata;
    }

    public function define_excluded_identity_hash_fields(): array {
        // Only truefalsewiris uses wirisoptions. Exclude them for other qtypes.
        return array_merge(
            parent::define_excluded_identity_hash_fields(),
            [
                '/options/wirisoptions'
            ]
        );
    }

    public function process_qtype_wq_multianswerwiris($data) {
        global $DB;

        $data = (object)$data;
        $data->xml = $this->decode_html_entities($data->xml);
        $oldid = $data->id;

        // Detect if the question is created or mapped.
        $oldquestionid   = $this->get_old_parentid('question');
        $newquestionid   = $this->get_new_parentid('question');
        $questioncreated = $this->get_mappingid('question_created', $oldquestionid) ? true : false;

        // If the question has been created by restore, we need to fill
        // qtype_wq tables too.
        if ($questioncreated) {
            // Adjust some columns.
            $data->question = $newquestionid;
            // Insert record.
            $newitemid = $DB->insert_record('qtype_wq', $data);
            // Create mapping.
            $this->set_mapping('qtype_wq', $oldid, $newitemid);
        }
    }

    /**
     * This method is executed once the whole restore_structure_step
     * this step is part of ({@link restore_create_categories_and_questions})
     * has ended processing the whole xml structure. Its name is:
     * "after_execute_" + connectionpoint ("question")
     *
     * For multianswerwiris qtype we use it to restore the sequence column in
     * {question_multianswer}, which contains the list of subquestion IDs.
     *
     * --- Why this override exists ---
     * The parent implementation ({@see restore_qtype_multianswer_plugin::after_execute_question()})
     * queries ALL question_multianswer records regardless of qtype. When a backup contains
     * both native 'multianswer' and 'multianswerwiris' questions, both plugins run their
     * after_execute_question() in sequence. Without this override, the parent would run
     * twice: once via the native multianswer plugin (correct) and once via this class
     * (corrupt), mapping already-remapped IDs through the backup→restore mapping table
     * a second time and producing null IDs, which empties the sequence and causes all
     * embedded answer fields to disappear after restore.
     *
     * --- Strategy ---
     * 1. Scope the query to 'multianswerwiris' parent questions only, so native
     *    'multianswer' records are not touched by this plugin.
     * 2. Apply an idempotency guard: skip any record whose sequence IDs have already
     *    been remapped to new IDs by the native multianswer plugin. This makes the
     *    method safe whether the native plugin ran first or not (e.g. backups that
     *    contain only multianswerwiris questions and no native multianswer ones).
     *
     * @see restore_qtype_multianswer_plugin::after_execute_question()
     */
    public function after_execute_question() {
        global $DB;

        // Fetch only question_multianswer records whose parent question is a
        // multianswerwiris type and was newly created (not mapped to an existing one)
        // during this restore session.
        $rs = $DB->get_recordset_sql("
                SELECT qma.id, qma.sequence
                FROM {question_multianswer} qma
                JOIN {backup_ids_temp} bi ON bi.newitemid = qma.question
                JOIN {question} q ON q.id = qma.question
                WHERE bi.backupid = :backupid
                AND bi.itemname = 'question_created'
                AND q.qtype = 'multianswerwiris'",
                ['backupid' => $this->get_restoreid()]);

        foreach ($rs as $rec) {
            $subquestionids = preg_split('/,/', $rec->sequence, -1, PREG_SPLIT_NO_EMPTY);

            // Idempotency guard: if none of the IDs in the sequence resolve to a
            // backup→restore mapping, they have already been remapped to new IDs
            // by the native multianswer plugin. Skip to avoid a destructive second pass.
            $hasunmappedids = false;
            foreach ($subquestionids as $subquestionid) {
                if ($this->get_mappingid('question', $subquestionid)) {
                    $hasunmappedids = true;
                    break;
                }
            }
            if (!$hasunmappedids) {
                continue;
            }

            // Remap each subquestion ID from the old backup ID to the new restored ID.
            // IDs that have no mapping are dropped via array_filter (should not happen
            // in a healthy backup, but mirrors the behaviour of the parent class).
            foreach ($subquestionids as $key => $subquestionid) {
                $newid = $this->get_mappingid('question', $subquestionid);
                if ($newid) {
                    $subquestionids[$key] = $newid;
                }
            }

            $DB->set_field(
                'question_multianswer',
                'sequence',
                implode(',', array_filter($subquestionids)),
                ['id' => $rec->id]
            );
        }

        $rs->close();
    }

    protected function decode_html_entities($xml) {
        $htmlentitiestable = get_html_translation_table(HTML_ENTITIES, ENT_QUOTES, 'UTF-8');
        $xmlentitiestable = get_html_translation_table(HTML_SPECIALCHARS, ENT_COMPAT, 'UTF-8');
        $entitiestable = array_diff($htmlentitiestable, $xmlentitiestable);
        $decodetable = array_flip($entitiestable);
        $xml = str_replace(array_keys($decodetable), array_values($decodetable), $xml);
        return $xml;
    }
}
