<?php
/**
 * Admin CRUD Helper Functions
 *
 * Common utility functions for admin CRUD operations.
 * Provides standardized patterns for create, update, delete, and reorder.
 */

/**
 * Generate a URL-safe slug from a string
 */
function generate_slug($string) {
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $string));
    return trim($slug, '_');
}

/**
 * Ensure slug is unique in a table
 */
function ensure_unique_slug($table, $slug, $exclude_id = null) {
    validate_sql_identifier($table);
    $check_sql = "SELECT id FROM {$table} WHERE slug = ?";
    $params = [$slug];

    if ($exclude_id) {
        $check_sql .= " AND id != ?";
        $params[] = $exclude_id;
    }

    $existing = db_fetch_one($check_sql, $params);

    if ($existing) {
        return $slug . '_' . time();
    }

    return $slug;
}

/**
 * Get next sort order for a table
 */
function get_next_sort_order($table) {
    validate_sql_identifier($table);
    $max = db_fetch_one("SELECT MAX(sort_order) as max_order FROM {$table}");
    return ($max['max_order'] ?? 0) + 1;
}

/**
 * Reorder items in a table via sort_order column
 */
function reorder_items($table, $order_array) {
    validate_sql_identifier($table);
    if (!is_array($order_array) || empty($order_array)) {
        return false;
    }

    foreach ($order_array as $index => $id) {
        db_update($table, ['sort_order' => $index + 1], 'id = ?', [(int)$id]);
    }

    return true;
}

/**
 * Move an item up in sort order
 */
function move_item_up($table, $id) {
    validate_sql_identifier($table);
    $item = db_fetch_one("SELECT id, sort_order FROM {$table} WHERE id = ?", [$id]);

    if (!$item) {
        return ['success' => false, 'message' => 'Item not found'];
    }

    $above = db_fetch_one(
        "SELECT id, sort_order FROM {$table} WHERE sort_order < ? ORDER BY sort_order DESC LIMIT 1",
        [$item['sort_order']]
    );

    if (!$above) {
        return ['success' => false, 'message' => 'Already at top'];
    }

    // Swap sort orders
    db_update($table, ['sort_order' => $above['sort_order']], 'id = ?', [$item['id']]);
    db_update($table, ['sort_order' => $item['sort_order']], 'id = ?', [$above['id']]);

    return ['success' => true];
}

/**
 * Move an item down in sort order
 */
function move_item_down($table, $id) {
    validate_sql_identifier($table);
    $item = db_fetch_one("SELECT id, sort_order FROM {$table} WHERE id = ?", [$id]);

    if (!$item) {
        return ['success' => false, 'message' => 'Item not found'];
    }

    $below = db_fetch_one(
        "SELECT id, sort_order FROM {$table} WHERE sort_order > ? ORDER BY sort_order ASC LIMIT 1",
        [$item['sort_order']]
    );

    if (!$below) {
        return ['success' => false, 'message' => 'Already at bottom'];
    }

    // Swap sort orders
    db_update($table, ['sort_order' => $below['sort_order']], 'id = ?', [$item['id']]);
    db_update($table, ['sort_order' => $item['sort_order']], 'id = ?', [$below['id']]);

    return ['success' => true];
}

/**
 * Set default item in a table (unsets all others)
 */
function set_default_item($table, $id) {
    validate_sql_identifier($table);
    db_query("UPDATE {$table} SET is_default = 0");
    db_update($table, ['is_default' => 1], 'id = ?', [$id]);

    return true;
}

/**
 * Check if an item is in use by counting related records
 */
function count_item_usage($related_table, $foreign_key, $id) {
    validate_sql_identifier($related_table);
    validate_sql_identifier($foreign_key);
    $result = db_fetch_one(
        "SELECT COUNT(*) as count FROM {$related_table} WHERE {$foreign_key} = ?",
        [$id]
    );

    return (int)($result['count'] ?? 0);
}

/**
 * Safe delete with usage check
 */
function safe_delete_item($table, $id, $related_table, $foreign_key, $entity_name) {
    $usage = count_item_usage($related_table, $foreign_key, $id);

    if ($usage > 0) {
        return [
            'success' => false,
            'message' => t("Cannot delete {entity} that is used by {count} records.", [
                'entity' => $entity_name,
                'count' => $usage
            ])
        ];
    }

    db_delete($table, 'id = ?', [$id]);

    return ['success' => true];
}

/**
 * Validate required fields
 */
function validate_required_fields($data, $required_fields) {
    $errors = [];

    foreach ($required_fields as $field => $label) {
        if (empty(trim($data[$field] ?? ''))) {
            $errors[$field] = t('{field} is required.', ['field' => $label]);
        }
    }

    return $errors;
}

/**
 * Process POST data with trim
 */
function process_post_data($fields, $source = null) {
    $source = $source ?? $_POST;
    $data = [];

    foreach ($fields as $field => $default) {
        $value = $source[$field] ?? $default;

        if (is_string($value)) {
            $value = trim($value);
        }

        $data[$field] = $value;
    }

    return $data;
}

/**
 * Standard API response helpers
 */
function api_success($data = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

function api_error($message, $code = 400) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message, 'success' => false]);
    exit;
}

/**
 * Check for admin permission and POST method
 */
function require_admin_post() {
    if (!is_admin()) {
        api_error('Forbidden', 403);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }

    require_csrf_token(true);
}

/**
 * Get JSON input from request body
 */
function get_json_input() {
    $input = json_decode(file_get_contents('php://input'), true);
    return is_array($input) ? $input : [];
}


