<?php

class Lesson_pdfs_and_identification_questions
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
    }

    public function up()
    {
        $db = $this->_lava->call->database();
        $columns = [
            'pdf_storage_name' => 'VARCHAR(80) NULL',
            'pdf_original_name' => 'VARCHAR(180) NULL',
        ];
        foreach ($columns as $name => $definition) {
            $exists = $db->raw("SHOW COLUMNS FROM lessons LIKE '" . $name . "'")->fetch(PDO::FETCH_ASSOC);
            if (!$exists) {
                $db->raw('ALTER TABLE lessons ADD COLUMN ' . $name . ' ' . $definition);
            }
        }

        $db->raw(
            "ALTER TABLE quiz_questions
             MODIFY type ENUM('multiple_choice','true_false','identification') NOT NULL,
             MODIFY correct_option VARCHAR(255) NOT NULL"
        );
    }

    public function down()
    {
        $db = $this->_lava->call->database();
        foreach (['pdf_original_name', 'pdf_storage_name'] as $name) {
            $exists = $db->raw("SHOW COLUMNS FROM lessons LIKE '" . $name . "'")->fetch(PDO::FETCH_ASSOC);
            if ($exists) {
                $db->raw('ALTER TABLE lessons DROP COLUMN ' . $name);
            }
        }

        // Keep identification-compatible quiz columns so existing answers are not truncated.
    }
}
