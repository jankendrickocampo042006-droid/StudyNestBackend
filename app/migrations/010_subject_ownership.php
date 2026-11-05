<?php

class Subject_ownership
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
    }

    public function up()
    {
        $db = $this->_lava->call->database();
        $column = $db->raw("SHOW COLUMNS FROM subjects LIKE 'owner_id'")->fetch(PDO::FETCH_ASSOC);
        if (!$column) {
            $db->raw('ALTER TABLE subjects ADD COLUMN owner_id INT UNSIGNED NULL AFTER description');
        }

        $index = $db->raw("SHOW INDEX FROM subjects WHERE Key_name = 'subjects_owner_idx'")->fetch(PDO::FETCH_ASSOC);
        if (!$index) {
            $db->raw('ALTER TABLE subjects ADD INDEX subjects_owner_idx (owner_id)');
        }

        $constraint = $db->raw(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subjects'
             AND CONSTRAINT_NAME = 'subjects_owner_fk'"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$constraint) {
            $db->raw(
                'ALTER TABLE subjects ADD CONSTRAINT subjects_owner_fk
                 FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL'
            );
        }

        $activities_table = $db->raw("SHOW TABLES LIKE 'user_activities'")->fetch(PDO::FETCH_ASSOC);
        if ($activities_table) {
            $db->raw(
                "UPDATE subjects s
                 JOIN (
                    SELECT title, MAX(id) AS activity_id
                    FROM user_activities
                    WHERE activity_type = 'content_created' AND details = 'Subject'
                    GROUP BY title
                 ) latest ON latest.title = s.name
                 JOIN user_activities activity ON activity.id = latest.activity_id
                 SET s.owner_id = activity.user_id
                 WHERE s.owner_id IS NULL"
            );
        }
    }

    public function down()
    {
        $db = $this->_lava->call->database();
        $constraint = $db->raw(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subjects'
             AND CONSTRAINT_NAME = 'subjects_owner_fk'"
        )->fetch(PDO::FETCH_ASSOC);
        if ($constraint) {
            $db->raw('ALTER TABLE subjects DROP FOREIGN KEY subjects_owner_fk');
        }

        $index = $db->raw("SHOW INDEX FROM subjects WHERE Key_name = 'subjects_owner_idx'")->fetch(PDO::FETCH_ASSOC);
        if ($index) {
            $db->raw('ALTER TABLE subjects DROP INDEX subjects_owner_idx');
        }

        $column = $db->raw("SHOW COLUMNS FROM subjects LIKE 'owner_id'")->fetch(PDO::FETCH_ASSOC);
        if ($column) {
            $db->raw('ALTER TABLE subjects DROP COLUMN owner_id');
        }
    }
}
