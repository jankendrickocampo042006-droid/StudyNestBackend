<?php

class Persist_teacher_id_photos
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
    }

    public function up()
    {
        $db = $this->_lava->call->database();
        $column = $db->raw("SHOW COLUMNS FROM users LIKE 'teacher_id_photo_data'")->fetch(PDO::FETCH_ASSOC);
        if (!$column) {
            $db->raw('ALTER TABLE users ADD COLUMN teacher_id_photo_data MEDIUMBLOB NULL AFTER teacher_id_photo');
        }
    }

    public function down()
    {
        $db = $this->_lava->call->database();
        $column = $db->raw("SHOW COLUMNS FROM users LIKE 'teacher_id_photo_data'")->fetch(PDO::FETCH_ASSOC);
        if ($column) {
            $db->raw('ALTER TABLE users DROP COLUMN teacher_id_photo_data');
        }
    }
}
