<?php

class Add_lesson_pdf_database_storage
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
    }

    public function up()
    {
        $db = $this->_lava->call->database();
        $column = $db->raw("SHOW COLUMNS FROM lessons LIKE 'pdf_data'")->fetch(PDO::FETCH_ASSOC);
        if (!$column) {
            $db->raw('ALTER TABLE lessons ADD COLUMN pdf_data LONGBLOB NULL AFTER pdf_original_name');
        } elseif (stripos($column['Type'], 'longblob') === false) {
            $db->raw('ALTER TABLE lessons MODIFY COLUMN pdf_data LONGBLOB NULL');
        }
    }

    public function down()
    {
        // Keep uploaded lesson PDFs when rolling back application code.
    }
}
