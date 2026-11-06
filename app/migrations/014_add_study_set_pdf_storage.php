<?php

class Add_study_set_pdf_storage
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
            'custom_topic' => 'VARCHAR(180) NULL AFTER topic_id',
            'pdf_original_name' => 'VARCHAR(180) NULL AFTER custom_topic',
            'pdf_data' => 'LONGBLOB NULL AFTER pdf_original_name',
        ];
        foreach ($columns as $name => $definition) {
            $exists = $db->raw("SHOW COLUMNS FROM study_sets LIKE '" . $name . "'")->fetch(PDO::FETCH_ASSOC);
            if (!$exists) {
                $db->raw('ALTER TABLE study_sets ADD COLUMN ' . $name . ' ' . $definition);
            } elseif ($name === 'pdf_data' && stripos($exists['Type'], 'longblob') === false) {
                $db->raw('ALTER TABLE study_sets MODIFY COLUMN pdf_data LONGBLOB NULL');
            }
        }
    }

    public function down()
    {
        // Preserve user-uploaded study material when rolling back application code.
    }
}
