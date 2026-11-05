<?php

class Repair_teacher_application_schema
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

        $columns = [
            'teacher_id' => 'VARCHAR(60) NULL AFTER role',
            'teacher_status' => "ENUM('pending','approved','rejected') NULL AFTER teacher_id",
            'teacher_reviewed_at' => 'DATETIME NULL AFTER teacher_status',
        ];
        foreach ($columns as $name => $definition) {
            $exists = $db->raw("SHOW COLUMNS FROM users LIKE '" . $name . "'")->fetch(PDO::FETCH_ASSOC);
            if (!$exists) {
                $db->raw('ALTER TABLE users ADD COLUMN ' . $name . ' ' . $definition);
            }
        }

        $index = $db->raw("SHOW INDEX FROM users WHERE Key_name = 'teacher_application_status_idx'")->fetch(PDO::FETCH_ASSOC);
        if (!$index) {
            $db->raw('ALTER TABLE users ADD INDEX teacher_application_status_idx (teacher_status)');
        }
    }

    public function down()
    {
        // Preserve teacher application data when rolling back application code.
    }
}
