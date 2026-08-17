<?php

return static function (PDO $db): void {
    $column = $db->query("SHOW COLUMNS FROM users LIKE 'language'")->fetch(PDO::FETCH_ASSOC);
    if (!$column) {
        return;
    }

    // Existing values remain explicit preferences. NULL is reserved for new
    // accounts that inherit the workspace default.
    $db->exec("ALTER TABLE users MODIFY COLUMN language VARCHAR(35) NULL DEFAULT NULL");
};
