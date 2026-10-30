<?php

class Add_teacher_applications
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
    }

    public function up()
    {
        $db = $this->_lava->call->database();
        $role = $db->raw("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
        if ($role && strpos($role['Type'], "'teacher'") === false) {
            $db->raw("ALTER TABLE users MODIFY role ENUM('admin','moderator','teacher','user') NOT NULL DEFAULT 'user'");
        }

        $columns = $db->raw("SHOW COLUMNS FROM users LIKE 'teacher_id'")->fetch(PDO::FETCH_ASSOC);
        if ($columns) {
            return;
        }

        $db->raw("ALTER TABLE users
            ADD COLUMN teacher_id VARCHAR(60) NULL AFTER role,
            ADD COLUMN teacher_status ENUM('pending','approved','rejected') NULL AFTER teacher_id,
            ADD COLUMN teacher_reviewed_at DATETIME NULL AFTER teacher_status,
            ADD INDEX teacher_application_status_idx (teacher_status)");
    }

    public function down()
    {
        $db = $this->_lava->call->database();
        $columns = $db->raw("SHOW COLUMNS FROM users LIKE 'teacher_id'")->fetch(PDO::FETCH_ASSOC);
        if (!$columns) {
            return;
        }

        $db->raw("ALTER TABLE users
            DROP INDEX teacher_application_status_idx,
            DROP COLUMN teacher_reviewed_at,
            DROP COLUMN teacher_status,
            DROP COLUMN teacher_id");
    }
}
