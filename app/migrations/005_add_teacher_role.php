<?php

class Add_teacher_role
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
    }

    public function up()
    {
        $db = $this->_lava->call->database();
        $column = $db->raw("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
        if (!$column || strpos($column['Type'], "'teacher'") !== false) {
            return;
        }
        $db->raw("ALTER TABLE users MODIFY role ENUM('admin','moderator','teacher','user') NOT NULL DEFAULT 'user'");
    }

    public function down()
    {
        $db = $this->_lava->call->database();
        $db->raw("UPDATE users SET role = 'moderator' WHERE role = 'teacher'");
        $db->raw("ALTER TABLE users MODIFY role ENUM('admin','moderator','user') NOT NULL DEFAULT 'user'");
    }
}
