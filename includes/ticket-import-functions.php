<?php
/**
 * Ticket Markdown Import Functions
 *
 * Supports importing ticket metadata, comments, and manual time entries
 * from structured Markdown templates.
 */

if (!function_exists('ticket_import_get_table_columns')) {
    function ticket_import_get_table_columns($table)
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $columns = [];
        try {
            $rows = db_fetch_all('SHOW COLUMNS FROM ' . $table);
            foreach ((array) $rows as $row) {
                $field = (string) ($row['Field'] ?? '');
                if ($field !== '') {
                    $columns[$field] = true;
                }
            }
        } catch (Throwable $e) {
            $columns = [];
        }

        $cache[$table] = $columns;
        return $columns;
    }
}

if (!function_exists('ticket_import_filter_table_data')) {
    function ticket_import_filter_table_data($table, array $data)
    {
        $columns = ticket_import_get_table_columns($table);
        if (empty($columns)) {
            return $data;
        }

        $filtered = [];
        foreach ($data as $key => $value) {
            if (isset($columns[$key])) {
                $filtered[$key] = $value;
            }
        }
        return $filtered;
    }
}

if (!function_exists('ticket_import_read_uploaded_markdown')) {
    function ticket_import_read_uploaded_markdown($file, $max_bytes = 2097152)
    {
        if (empty($file) || !is_array($file)) {
            throw new InvalidArgumentException('No .md file uploaded.');
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Invalid .md upload.');
        }

        $name = (string) ($file['name'] ?? '');
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== 'md') {
            throw new InvalidArgumentException('Only .md files are supported.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw new InvalidArgumentException('Markdown file is empty.');
        }
        if ($size > (int) $max_bytes) {
            throw new InvalidArgumentException('Markdown file is too large.');
        }

        $tmp_name = (string) ($file['tmp_name'] ?? '');
        if ($tmp_name === '' || !is_uploaded_file($tmp_name)) {
            throw new InvalidArgumentException('Invalid .md upload.');
        }

        $content = @file_get_contents($tmp_name);
        if ($content === false) {
            throw new InvalidArgumentException('Could not read uploaded .md file.');
        }

        if (function_exists('ticket_import_convert_to_utf8')) {
            $content = ticket_import_convert_to_utf8((string) $content);
        }
        $content = str_replace(["\r\n", "\r"], "\n", (string) $content);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $content = str_replace("\0", '', $content);

        if (trim((string) $content) === '') {
            throw new InvalidArgumentException('Markdown file is empty.');
        }

        return $content;
    }
}

if (!function_exists('ticket_import_convert_to_utf8')) {
    function ticket_import_convert_to_utf8($content)
    {
        $content = (string) $content;
        if ($content === '') {
            return '';
        }

        $normalized = $content;

        // Handle common UTF-16 BOM variants from desktop editors.
        if (strncmp($content, "\xFF\xFE", 2) === 0 || strncmp($content, "\xFE\xFF", 2) === 0) {
            if (function_exists('mb_convert_encoding')) {
                $converted = @mb_convert_encoding($content, 'UTF-8', 'UTF-16');
                if (is_string($converted) && $converted !== '') {
                    $normalized = $converted;
                }
            } elseif (function_exists('iconv')) {
                $converted = @iconv('UTF-16', 'UTF-8//IGNORE', $content);
                if (is_string($converted) && $converted !== '') {
                    $normalized = $converted;
                }
            }
        } elseif (function_exists('mb_detect_encoding') && function_exists('mb_convert_encoding')) {
            $encoding = @mb_detect_encoding(
                $content,
                ['UTF-8', 'UTF-16LE', 'UTF-16BE', 'Windows-1250', 'ISO-8859-2', 'Windows-1252'],
                true
            );
            if (is_string($encoding) && strtoupper($encoding) !== 'UTF-8') {
                $converted = @mb_convert_encoding($content, 'UTF-8', $encoding);
                if (is_string($converted) && $converted !== '') {
                    $normalized = $converted;
                }
            }
        }

        if (!preg_match('//u', $normalized) && function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $normalized);
            if (is_string($converted) && $converted !== '') {
                $normalized = $converted;
            }
        }

        return (string) $normalized;
    }
}

if (!function_exists('ticket_import_normalize_key')) {
    function ticket_import_normalize_key($key)
    {
        $key = trim((string) $key);
        if ($key !== '' && function_exists('iconv')) {
            $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $key);
            if (is_string($transliterated) && $transliterated !== '') {
                $key = $transliterated;
            }
        }
        $key = strtolower($key);
        $key = str_replace(['-', ' '], '_', $key);
        $key = preg_replace('/[^a-z0-9_]/', '', $key);
        return $key;
    }
}

if (!function_exists('ticket_import_canonicalize_field_key')) {
    function ticket_import_canonicalize_field_key($key)
    {
        $key = ticket_import_normalize_key($key);
        if ($key === '') {
            return '';
        }

        static $aliases = [
            'title' => 'title',
            'subject' => 'title',
            'predmet' => 'title',
            'nazev' => 'title',
            'ticket_title' => 'title',

            'requester_email' => 'requester_email',
            'customer_email' => 'requester_email',
            'user_email' => 'requester_email',
            'email_zadatele' => 'requester_email',
            'zadatel_email' => 'requester_email',

            'requester_name' => 'requester_name',
            'customer_name' => 'requester_name',
            'user_name' => 'requester_name',
            'jmeno_zadatele' => 'requester_name',
            'zadatel_jmeno' => 'requester_name',

            'type' => 'type',
            'ticket_type' => 'type',
            'typ' => 'type',
            'druh' => 'type',

            'priority' => 'priority',
            'priorita' => 'priority',
            'priority_id' => 'priority_id',

            'status' => 'status',
            'stav' => 'status',
            'status_id' => 'status_id',

            'due' => 'due_date',
            'due_date' => 'due_date',
            'deadline' => 'due_date',
            'termin' => 'due_date',
            'termin_dokonceni' => 'due_date',

            'tags' => 'tags',
            'tag' => 'tags',
            'stitky' => 'tags',

            'organization' => 'organization',
            'organization_name' => 'organization',
            'company' => 'organization',
            'firma' => 'organization',
            'organizace' => 'organization',
            'organization_id' => 'organization_id',
            'company_id' => 'organization_id',

            'author_email' => 'author_email',
            'agent_email' => 'author_email',
            'email_autora' => 'author_email',
            'autor_email' => 'author_email',
            'email_agenta' => 'author_email',
            'email' => 'author_email',

            'author_name' => 'author_name',
            'agent_name' => 'author_name',
            'autor' => 'author_name',
            'jmeno_autora' => 'author_name',
            'jmeno_agenta' => 'author_name',

            'date' => 'date',
            'datum' => 'date',
            'den' => 'date',

            'start' => 'start',
            'start_time' => 'start',
            'zacatek' => 'start',
            'cas_od' => 'start',
            'od' => 'start',

            'end' => 'end',
            'end_time' => 'end',
            'konec' => 'end',
            'cas_do' => 'end',
            'do' => 'end',

            'duration_minutes' => 'duration_minutes',
            'duration' => 'duration_minutes',
            'minutes' => 'duration_minutes',
            'time_spent' => 'duration_minutes',
            'trvani' => 'duration_minutes',
            'cas' => 'duration_minutes',
            'minuty' => 'duration_minutes',

            'billable' => 'billable',
            'fakturovatelne' => 'billable',
            'fakturovatelny' => 'billable',
            'uctovat' => 'billable',

            'internal' => 'internal',
            'is_internal' => 'internal',
            'interni' => 'internal',
            'interne' => 'internal',

            'notify' => 'notify',
            'send_notification' => 'notify',
            'upozornit' => 'notify',

            'summary' => 'summary',
            'note' => 'summary',
            'souhrn' => 'summary',
            'poznamka' => 'summary',
            'shrnuti' => 'summary',

            'comment' => 'comment',
            'komentar' => 'comment',
            'text' => 'comment',
            'popis_prace' => 'comment',
        ];

        return $aliases[$key] ?? $key;
    }
}

if (!function_exists('ticket_import_parse_key_values')) {
    function ticket_import_parse_key_values($text)
    {
        $result = [];
        $lines = preg_split('/\n/', (string) $text);

        foreach ((array) $lines as $line) {
            $line = rtrim((string) $line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^\s*(?:[-*]|\d+[.)])\s*(.+)$/u', $line, $bullet_match)) {
                $line = trim((string) $bullet_match[1]);
            }
            $line = preg_replace('/^\s*>+\s*/', '', $line);

            if (
                !preg_match('/^\s*\*\*([^*]+)\*\*\s*:\s*(.*?)\s*$/u', $line, $match) &&
                !preg_match('/^\s*([\p{L}][\p{L}\p{N} _\-()\/]+)\s*:\s*(.*?)\s*$/u', $line, $match)
            ) {
                continue;
            }

            $key = ticket_import_canonicalize_field_key($match[1]);
            $value = trim((string) ($match[2] ?? ''));
            if ($key !== '') {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}

if (!function_exists('ticket_import_extract_section_by_names')) {
    function ticket_import_extract_section_by_names($markdown, array $names)
    {
        $markdown = (string) $markdown;
        foreach ($names as $name) {
            $pattern = '/^#{1,4}\s*' . preg_quote((string) $name, '/') . '\s*:?\s*$\n?(.*?)(?=^#{1,4}\s+\S|\z)/ims';
            if (preg_match($pattern, $markdown, $match)) {
                return trim((string) ($match[1] ?? ''));
            }
        }

        return '';
    }
}

if (!function_exists('ticket_import_parse_bool')) {
    function ticket_import_parse_bool($value, $default = null)
    {
        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return $default;
        }

        $truthy = ['1', 'yes', 'y', 'true', 'on'];
        $falsy = ['0', 'no', 'n', 'false', 'off'];

        if (in_array($raw, $truthy, true)) {
            return true;
        }
        if (in_array($raw, $falsy, true)) {
            return false;
        }

        return $default;
    }
}

if (!function_exists('ticket_import_parse_duration_minutes')) {
    function ticket_import_parse_duration_minutes($value)
    {
        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return 0;
        }

        if (preg_match('/^\d+$/', $raw)) {
            return max(0, (int) $raw);
        }

        if (preg_match('/^(\d+)\s*m(?:in)?(?:ute)?s?$/', $raw, $m)) {
            return max(0, (int) $m[1]);
        }

        if (preg_match('/^(\d+)\s*h(?:our)?s?(?:\s*(\d+)\s*m(?:in)?(?:ute)?s?)?$/', $raw, $m)) {
            $hours = (int) ($m[1] ?? 0);
            $minutes = (int) ($m[2] ?? 0);
            return max(0, $hours * 60 + $minutes);
        }

        if (preg_match('/(\d+)/', $raw, $m)) {
            return max(0, (int) $m[1]);
        }

        return 0;
    }
}

if (!function_exists('ticket_import_parse_date_value')) {
    function ticket_import_parse_date_value($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $dt = DateTime::createFromFormat('Y-m-d', $value);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }

        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d', $ts);
    }
}

if (!function_exists('ticket_import_parse_time_value')) {
    function ticket_import_parse_time_value($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m)) {
            $hours = (int) $m[1];
            $minutes = (int) $m[2];
            $seconds = (int) ($m[3] ?? 0);
            if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59 || $seconds < 0 || $seconds > 59) {
                return null;
            }
            return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
        }

        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }

        return date('H:i:s', $ts);
    }
}

if (!function_exists('ticket_import_resolve_due_date')) {
    function ticket_import_resolve_due_date($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value . ' 23:59:00';
        }

        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $ts);
    }
}

if (!function_exists('ticket_import_find_user_by_email')) {
    function ticket_import_find_user_by_email($email)
    {
        $email = strtolower(trim((string) $email));
        if ($email === '') {
            return null;
        }

        $sql = 'SELECT * FROM users WHERE LOWER(email) = ? AND is_active = 1';
        if (function_exists('users_deleted_at_column_exists') && users_deleted_at_column_exists()) {
            $sql .= ' AND deleted_at IS NULL';
        }
        $sql .= ' LIMIT 1';

        return db_fetch_one($sql, [$email]);
    }
}

if (!function_exists('ticket_import_resolve_priority_id')) {
    function ticket_import_resolve_priority_id($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            $priority = get_priority((int) $value);
            return $priority ? (int) $priority['id'] : null;
        }

        $needle = strtolower($value);
        foreach ((array) get_priorities() as $priority) {
            $name = strtolower((string) ($priority['name'] ?? ''));
            $slug = strtolower((string) ($priority['slug'] ?? ''));
            if ($needle === $name || $needle === $slug) {
                return (int) ($priority['id'] ?? 0);
            }
        }

        return null;
    }
}

if (!function_exists('ticket_import_resolve_status_id')) {
    function ticket_import_resolve_status_id($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            $status = get_status((int) $value);
            return $status ? (int) $status['id'] : null;
        }

        $needle = strtolower($value);
        foreach ((array) get_statuses() as $status) {
            $name = strtolower((string) ($status['name'] ?? ''));
            if ($needle === $name) {
                return (int) ($status['id'] ?? 0);
            }
        }

        return null;
    }
}

if (!function_exists('ticket_import_resolve_type_slug')) {
    function ticket_import_resolve_type_slug($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $needle = strtolower($value);
        foreach ((array) get_ticket_types(true) as $type) {
            $slug = strtolower((string) ($type['slug'] ?? ''));
            $name = strtolower((string) ($type['name'] ?? ''));
            if ($needle === $slug || $needle === $name) {
                return (string) ($type['slug'] ?? '');
            }
        }

        return null;
    }
}

if (!function_exists('ticket_import_resolve_organization_id_from_meta')) {
    function ticket_import_resolve_organization_id_from_meta(array $meta)
    {
        $raw_id = trim((string) ($meta['organization_id'] ?? $meta['company_id'] ?? ''));
        if ($raw_id !== '' && ctype_digit($raw_id)) {
            $organization = get_organization((int) $raw_id);
            if ($organization) {
                return (int) $organization['id'];
            }
        }

        $raw_name = trim((string) ($meta['organization'] ?? $meta['organization_name'] ?? $meta['company'] ?? ''));
        if ($raw_name === '') {
            return null;
        }

        $needle = strtolower($raw_name);
        foreach ((array) get_organizations(true) as $organization) {
            $name = strtolower(trim((string) ($organization['name'] ?? '')));
            if ($name !== '' && $name === $needle) {
                return (int) ($organization['id'] ?? 0);
            }
        }

        return null;
    }
}

if (!function_exists('ticket_import_parse_worklog_entry_chunk')) {
    function ticket_import_parse_worklog_entry_chunk($chunk)
    {
        $fields = [];
        $comment_lines = [];
        $in_comment = false;
        $comment_fence = false;

        $lines = preg_split('/\n/', (string) $chunk);
        foreach ((array) $lines as $line) {
            $line = rtrim((string) $line);

            if (!$in_comment) {
                if (
                    preg_match('/^\s*\*\*([^*]+)\*\*\s*:\s*(.*?)\s*$/u', $line, $match) ||
                    preg_match('/^\s*([\p{L}][\p{L}\p{N} _\-()\/]+)\s*:\s*(.*?)\s*$/u', $line, $match)
                ) {
                    $canonical_key = ticket_import_canonicalize_field_key((string) $match[1]);
                    $value = trim((string) ($match[2] ?? ''));

                    if ($canonical_key === 'comment') {
                        $in_comment = true;
                        $tail = $value;
                        if (preg_match('/^```/', trim($tail))) {
                            $comment_fence = true;
                            $tail = preg_replace('/^```(?:md|markdown)?\s*/i', '', trim($tail));
                            if ($tail !== '') {
                                $comment_lines[] = $tail;
                            }
                        } elseif (trim($tail) !== '') {
                            $comment_lines[] = $tail;
                        }
                        continue;
                    }

                    if ($canonical_key !== '') {
                        $fields[$canonical_key] = $value;
                    }
                    continue;
                }

                if (preg_match('/^\s*Comment\s*:\s*(.*)$/i', $line, $comment_match)) {
                    $in_comment = true;
                    $tail = (string) ($comment_match[1] ?? '');
                    if (preg_match('/^```/', trim($tail))) {
                        $comment_fence = true;
                        $tail = preg_replace('/^```(?:md|markdown)?\s*/i', '', trim($tail));
                        if ($tail !== '') {
                            $comment_lines[] = $tail;
                        }
                    } elseif (trim($tail) !== '') {
                        $comment_lines[] = $tail;
                    }
                    continue;
                }

                if (preg_match('/^\s*(?:[-*]|\d+[.)])\s*(.+)$/u', $line, $bullet_match)) {
                    $line = trim((string) $bullet_match[1]);
                }

                if (
                    preg_match('/^\s*\*\*([^*]+)\*\*\s*:\s*(.*?)\s*$/u', $line, $match) ||
                    preg_match('/^\s*([\p{L}][\p{L}\p{N} _\-()\/]+)\s*:\s*(.*?)\s*$/u', $line, $match)
                ) {
                    $key = ticket_import_canonicalize_field_key((string) $match[1]);
                    $value = trim((string) ($match[2] ?? ''));
                    if ($key !== '') {
                        $fields[$key] = $value;
                    }
                }

                continue;
            }

            if ($comment_fence) {
                if (preg_match('/^\s*```\s*$/', $line)) {
                    $comment_fence = false;
                    continue;
                }
                $comment_lines[] = $line;
                continue;
            }

            $comment_lines[] = $line;
        }

        return [
            'author_email' => trim((string) ($fields['author_email'] ?? '')),
            'author_name' => trim((string) ($fields['author_name'] ?? '')),
            'date' => trim((string) ($fields['date'] ?? '')),
            'start' => trim((string) ($fields['start'] ?? '')),
            'end' => trim((string) ($fields['end'] ?? '')),
            'duration_minutes' => trim((string) ($fields['duration_minutes'] ?? '')),
            'billable' => trim((string) ($fields['billable'] ?? '')),
            'internal' => trim((string) ($fields['internal'] ?? '')),
            'notify' => trim((string) ($fields['notify'] ?? '')),
            'summary' => trim((string) ($fields['summary'] ?? '')),
            'comment' => trim(implode("\n", $comment_lines)),
        ];
    }
}

if (!function_exists('ticket_import_parse_worklog_entries')) {
    function ticket_import_parse_worklog_entries($worklog_section)
    {
        $worklog_section = trim((string) $worklog_section);
        if ($worklog_section === '') {
            return [];
        }

        $chunks = [];
        $entry_separator_pattern = '/^\s*(?:#{1,4}\s*)?(?:[-*]\s*)?(Entry|Record|Z[aá]znam|Polo[zž]ka)\b(?:\s*[:#-]?\s*\d+)?\s*$/imu';
        if (preg_match($entry_separator_pattern, $worklog_section)) {
            $parts = preg_split($entry_separator_pattern, $worklog_section);
            $parts = array_slice((array) $parts, 1);
            foreach ($parts as $part) {
                if (trim((string) $part) !== '') {
                    $chunks[] = (string) $part;
                }
            }
        } else {
            $chunks[] = $worklog_section;
        }

        $entries = [];
        foreach ($chunks as $chunk) {
            $entry = ticket_import_parse_worklog_entry_chunk($chunk);
            $has_content =
                trim((string) ($entry['comment'] ?? '')) !== '' ||
                trim((string) ($entry['summary'] ?? '')) !== '' ||
                trim((string) ($entry['duration_minutes'] ?? '')) !== '' ||
                trim((string) ($entry['start'] ?? '')) !== '' ||
                trim((string) ($entry['end'] ?? '')) !== '';

            if ($has_content) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}

if (!function_exists('ticket_import_extract_first_heading')) {
    function ticket_import_extract_first_heading($markdown)
    {
        if (preg_match('/^\s*#\s+(.+?)\s*$/m', (string) $markdown, $match)) {
            return trim((string) ($match[1] ?? ''));
        }
        return '';
    }
}

if (!function_exists('ticket_import_extract_first_meaningful_line')) {
    function ticket_import_extract_first_meaningful_line($markdown)
    {
        $lines = preg_split('/\n/', (string) $markdown);
        foreach ((array) $lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || preg_match('/^[-*`>#]/', $line)) {
                continue;
            }
            if (preg_match('/^\s*([\p{L}][\p{L}\p{N} _\-()\/]+)\s*:\s*(.*?)\s*$/u', $line, $match)) {
                $key = ticket_import_canonicalize_field_key((string) ($match[1] ?? ''));
                $value = trim((string) ($match[2] ?? ''));
                if ($key === 'title' && $value !== '') {
                    return $value;
                }
                if (in_array($key, ['date', 'start', 'end', 'duration_minutes', 'billable', 'internal', 'notify', 'author_email'], true)) {
                    continue;
                }
                if ($value !== '') {
                    $line = $value;
                }
            }
            $line = preg_replace('/^\d+\.\s*/', '', $line);
            $line = trim((string) $line);
            if ($line !== '') {
                return $line;
            }
        }
        return '';
    }
}

if (!function_exists('ticket_import_has_worklog_cues')) {
    function ticket_import_has_worklog_cues($markdown)
    {
        $markdown = (string) $markdown;
        if (preg_match('/^\s*(?:#{1,4}\s*)?(?:[-*]\s*)?(Entry|Record|Z[aá]znam|Polo[zž]ka)\b(?:\s*[:#-]?\s*\d+)?\s*$/imu', $markdown)) {
            return true;
        }
        return (bool) preg_match('/^(date|datum|start|zacatek|konec|end|duration|trvani|minutes|minuty|hours|hodiny|summary|souhrn|comment|komentar)\s*:/imu', $markdown);
    }
}

if (!function_exists('ticket_import_parse_markdown')) {
    function ticket_import_parse_markdown($markdown)
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", (string) $markdown);
        $markdown = preg_replace('/^\xEF\xBB\xBF/', '', $markdown);

        if (trim((string) $markdown) === '') {
            throw new InvalidArgumentException('Markdown file is empty.');
        }

        $ticket_section = ticket_import_extract_section_by_names($markdown, [
            'Ticket', 'Ticket Metadata', 'Ticket Fields', 'Request', 'Metadata',
            'Požadavek', 'Pozadavek', 'Zadání', 'Zadani', 'Informace'
        ]);
        $description = ticket_import_extract_section_by_names($markdown, [
            'Description', 'Ticket Description', 'Details', 'Body',
            'Popis', 'Detaily', 'Text'
        ]);
        $worklog_section = ticket_import_extract_section_by_names($markdown, [
            'Worklog', 'Work Log', 'Entries', 'Activity',
            'Výkaz práce', 'Vykaz prace', 'Záznamy', 'Zaznamy', 'Komentáře', 'Komentare'
        ]);
        if ($worklog_section === '') {
            if (preg_match('/^\s*(Worklog|Work Log|Entries|Activity|V[ýy]kaz pr[aá]ce|Z[aá]znamy|Koment[aá][ře])\s*:?\s*$(.*)$/ims', $markdown, $match)) {
                $worklog_section = trim((string) ($match[2] ?? ''));
            }
        }

        $meta = ticket_import_parse_key_values($ticket_section);
        if (empty($meta)) {
            $meta = ticket_import_parse_key_values($markdown);
        }

        $entries = ticket_import_parse_worklog_entries($worklog_section);
        if (empty($entries) && $worklog_section === '' && ticket_import_has_worklog_cues($markdown)) {
            $entries = ticket_import_parse_worklog_entries($markdown);
        }

        if (trim((string) $description) === '') {
            $description = $markdown;
            $heading = ticket_import_extract_first_heading($description);
            if ($heading !== '') {
                $description = preg_replace('/^\s*#\s+' . preg_quote($heading, '/') . '\s*$/m', '', $description, 1);
            }
            $description = trim((string) $description);
        }

        if (empty($meta['title'])) {
            $fallback_title = ticket_import_extract_first_heading($markdown);
            if ($fallback_title === '') {
                $fallback_title = ticket_import_extract_first_meaningful_line($markdown);
            }
            if ($fallback_title !== '') {
                $meta['title'] = function_exists('mb_substr')
                    ? mb_substr($fallback_title, 0, 160)
                    : substr($fallback_title, 0, 160);
            }
        }

        if (empty($entries) && trim((string) $description) !== '' && empty($meta['title'])) {
            $meta['title'] = 'Imported ticket ' . date('Y-m-d H:i');
        }

        if (trim((string) ($meta['title'] ?? '')) === '' && trim((string) $description) === '' && empty($entries)) {
            $meta['title'] = 'Imported ticket ' . date('Y-m-d H:i');
            $description = 'Imported from markdown file.';
        }

        return [
            'meta' => $meta,
            'description' => trim((string) $description),
            'entries' => $entries,
            'raw' => $markdown,
        ];
    }
}

if (!function_exists('ticket_import_prepare_comment_content')) {
    function ticket_import_prepare_comment_content($summary, $comment)
    {
        $summary = trim((string) $summary);
        $comment = trim((string) $comment);

        if ($summary === '' && $comment === '') {
            return '';
        }

        if ($summary !== '' && $comment !== '') {
            return 'Summary: ' . $summary . "\n\n" . $comment;
        }

        if ($summary !== '') {
            return 'Summary: ' . $summary;
        }

        return $comment;
    }
}

if (!function_exists('ticket_import_apply_entry_to_ticket')) {
    function ticket_import_apply_entry_to_ticket($ticket_id, array $ticket, array $entry, array $actor_user, array $options = [])
    {
        $entry_index = (int) ($options['entry_index'] ?? 0);
        $allow_author_override = !empty($options['allow_author_override']);
        $default_internal = !empty($options['default_internal']);

        $warnings = [];
        $result = [
            'comment_id' => 0,
            'time_entry_id' => 0,
            'warnings' => [],
        ];

        $author_user = $actor_user;
        $author_email = trim((string) ($entry['author_email'] ?? ''));
        if ($allow_author_override && $author_email !== '') {
            $resolved_user = ticket_import_find_user_by_email($author_email);
            if ($resolved_user) {
                $author_user = $resolved_user;
            } else {
                $warnings[] = 'Entry ' . $entry_index . ': author ' . $author_email . ' was not found, current user used instead.';
            }
        }

        $internal_value = ticket_import_parse_bool($entry['internal'] ?? '', null);
        $is_internal = $internal_value === null ? ($default_internal ? 1 : 0) : ($internal_value ? 1 : 0);

        $billable_value = ticket_import_parse_bool($entry['billable'] ?? '', true);
        $is_billable = $billable_value ? 1 : 0;

        $entry_date = ticket_import_parse_date_value($entry['date'] ?? '');
        $start_time = ticket_import_parse_time_value($entry['start'] ?? '');
        $end_time = ticket_import_parse_time_value($entry['end'] ?? '');
        $duration_minutes = ticket_import_parse_duration_minutes($entry['duration_minutes'] ?? '');

        $has_time_data =
            $entry_date !== null ||
            $start_time !== null ||
            $end_time !== null ||
            $duration_minutes > 0;

        $start_dt = null;
        $end_dt = null;

        if ($has_time_data) {
            if ($entry_date === null) {
                throw new InvalidArgumentException('Entry ' . $entry_index . ': date is required when time fields are used.');
            }

            if ($start_time !== null && $end_time !== null) {
                $start_dt = DateTime::createFromFormat('Y-m-d H:i:s', $entry_date . ' ' . $start_time);
                $end_dt = DateTime::createFromFormat('Y-m-d H:i:s', $entry_date . ' ' . $end_time);
                if (!$start_dt || !$end_dt || $end_dt <= $start_dt) {
                    throw new InvalidArgumentException('Entry ' . $entry_index . ': end time must be after start time.');
                }
                $duration_minutes = max(1, (int) floor(($end_dt->getTimestamp() - $start_dt->getTimestamp()) / 60));
            } elseif ($start_time !== null && $duration_minutes > 0) {
                $start_dt = DateTime::createFromFormat('Y-m-d H:i:s', $entry_date . ' ' . $start_time);
                if (!$start_dt) {
                    throw new InvalidArgumentException('Entry ' . $entry_index . ': invalid start time.');
                }
                $end_dt = clone $start_dt;
                $end_dt->modify('+' . $duration_minutes . ' minutes');
            } elseif ($end_time !== null && $duration_minutes > 0) {
                $end_dt = DateTime::createFromFormat('Y-m-d H:i:s', $entry_date . ' ' . $end_time);
                if (!$end_dt) {
                    throw new InvalidArgumentException('Entry ' . $entry_index . ': invalid end time.');
                }
                $start_dt = clone $end_dt;
                $start_dt->modify('-' . $duration_minutes . ' minutes');
            } elseif ($duration_minutes > 0) {
                $start_dt = DateTime::createFromFormat('Y-m-d H:i:s', $entry_date . ' 09:00:00');
                $end_dt = clone $start_dt;
                $end_dt->modify('+' . $duration_minutes . ' minutes');
                $warnings[] = 'Entry ' . $entry_index . ': start/end time missing, default start 09:00 used.';
            } else {
                throw new InvalidArgumentException('Entry ' . $entry_index . ': provide start and end time or duration.');
            }
        }

        $comment_content = ticket_import_prepare_comment_content($entry['summary'] ?? '', $entry['comment'] ?? '');
        if ($comment_content === '' && $has_time_data) {
            $comment_content = 'Imported worklog entry';
        }

        if ($comment_content === '' && !$has_time_data) {
            $warnings[] = 'Entry ' . $entry_index . ': skipped because it is empty.';
            $result['warnings'] = $warnings;
            return $result;
        }

        $comment_created_at = date('Y-m-d H:i:s');
        if ($end_dt instanceof DateTime) {
            $comment_created_at = $end_dt->format('Y-m-d H:i:s');
        } elseif ($start_dt instanceof DateTime) {
            $comment_created_at = $start_dt->format('Y-m-d H:i:s');
        }

        $comment_data = [
            'ticket_id' => (int) $ticket_id,
            'user_id' => (int) ($author_user['id'] ?? $actor_user['id']),
            'content' => $comment_content,
            'is_internal' => $is_internal,
            'time_spent' => $duration_minutes,
            'created_at' => $comment_created_at,
        ];
        $comment_data = ticket_import_filter_table_data('comments', $comment_data);
        $comment_id = (int) db_insert('comments', $comment_data);
        $result['comment_id'] = $comment_id;

        if ($has_time_data && ticket_time_table_exists() && $start_dt instanceof DateTime && $end_dt instanceof DateTime && $duration_minutes > 0) {
            $org_billable_rate = 0.0;
            if (!empty($ticket['organization_id'])) {
                $organization = get_organization((int) $ticket['organization_id']);
                if ($organization && isset($organization['billable_rate'])) {
                    $org_billable_rate = (float) $organization['billable_rate'];
                }
            }

            $time_data = [
                'ticket_id' => (int) $ticket_id,
                'user_id' => (int) ($author_user['id'] ?? $actor_user['id']),
                'comment_id' => $comment_id,
                'started_at' => $start_dt->format('Y-m-d H:i:s'),
                'ended_at' => $end_dt->format('Y-m-d H:i:s'),
                'duration_minutes' => $duration_minutes,
                'summary' => trim((string) ($entry['summary'] ?? '')),
                'is_billable' => $is_billable,
                'billable_rate' => $org_billable_rate,
                'cost_rate' => (float) ($author_user['cost_rate'] ?? 0),
                'is_manual' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ];
            $time_data = ticket_import_filter_table_data('ticket_time_entries', $time_data);
            if (!empty($time_data)) {
                $result['time_entry_id'] = (int) db_insert('ticket_time_entries', $time_data);
            }
        }

        $result['warnings'] = $warnings;
        return $result;
    }
}

if (!function_exists('ticket_import_create_ticket_from_payload')) {
    function ticket_import_create_ticket_from_payload(array $payload, array $actor_user, array $options = [])
    {
        $actor_id = (int) ($actor_user['id'] ?? 0);
        if ($actor_id <= 0) {
            throw new InvalidArgumentException('Invalid current user.');
        }

        $meta = (array) ($payload['meta'] ?? []);
        $description = trim((string) ($payload['description'] ?? ''));
        $entries = (array) ($payload['entries'] ?? []);

        $title = trim((string) ($meta['title'] ?? $meta['subject'] ?? ''));
        if ($title === '') {
            $title = 'Imported ticket ' . date('Y-m-d H:i');
        }

        $type_raw = (string) ($meta['type'] ?? $meta['ticket_type'] ?? '');
        $type_slug = ticket_import_resolve_type_slug($type_raw);
        if ($type_slug === null || $type_slug === '') {
            $default_type = null;
            foreach ((array) get_ticket_types() as $type) {
                if (!empty($type['is_default'])) {
                    $default_type = $type;
                    break;
                }
            }
            if (!$default_type) {
                $types = get_ticket_types();
                $default_type = $types[0] ?? ['slug' => 'general'];
            }
            $type_slug = (string) ($default_type['slug'] ?? 'general');
        }

        $priority_id = ticket_import_resolve_priority_id((string) ($meta['priority'] ?? $meta['priority_id'] ?? ''));
        $status_id = ticket_import_resolve_status_id((string) ($meta['status'] ?? $meta['status_id'] ?? ''));

        $warnings = [];

        $due_raw = (string) ($meta['due_date'] ?? $meta['due'] ?? '');
        $due_date = null;
        if (trim($due_raw) !== '') {
            $due_date = ticket_import_resolve_due_date($due_raw);
            if ($due_date === null) {
                $warnings[] = 'Due date format was not recognized, due date was skipped.';
            }
        }

        $organization_id = null;
        $organization_override = (int) ($options['organization_id_override'] ?? 0);
        if ($organization_override > 0) {
            $organization_id = $organization_override;
        } else {
            $organization_id = ticket_import_resolve_organization_id_from_meta($meta);
        }

        $allowed_org_ids = array_values(array_unique(array_map('intval', (array) ($options['allowed_organization_ids'] ?? []))));
        $enforce_org_scope = array_key_exists('allowed_organization_ids', $options);
        if ($organization_id !== null && $organization_id > 0 && $enforce_org_scope) {
            if (empty($allowed_org_ids) || !in_array((int) $organization_id, $allowed_org_ids, true)) {
                $warnings[] = 'Organization from .md was not available in your scope and was skipped.';
                $organization_id = null;
            }
        }

        $requester_id = $actor_id;
        $allow_requester_override = !empty($options['allow_requester_override']);
        $requester_email = trim((string) ($meta['requester_email'] ?? $meta['user_email'] ?? ''));
        if ($allow_requester_override && $requester_email !== '') {
            $requester = ticket_import_find_user_by_email($requester_email);
            if ($requester) {
                $requester_id = (int) $requester['id'];
            } else {
                $warnings[] = 'Requester ' . $requester_email . ' was not found, current user used instead.';
            }
        }

        $db = get_db();
        $ticket_id = 0;
        $comment_count = 0;
        $time_count = 0;

        try {
            $db->beginTransaction();

            $ticket_id = (int) create_ticket([
                'title' => $title,
                'description' => $description,
                'type' => $type_slug,
                'priority_id' => $priority_id,
                'status_id' => $status_id,
                'user_id' => $requester_id,
                'organization_id' => $organization_id,
                'due_date' => $due_date,
                'tags' => (string) ($meta['tags'] ?? ''),
            ]);

            if ($ticket_id <= 0) {
                throw new RuntimeException('Could not create ticket from .md file.');
            }

            log_activity($ticket_id, $actor_id, 'created', 'Ticket created via Markdown import');

            $ticket = get_ticket($ticket_id);
            if (!$ticket) {
                throw new RuntimeException('Ticket was created but could not be loaded.');
            }

            foreach ($entries as $index => $entry) {
                $entry_result = ticket_import_apply_entry_to_ticket($ticket_id, $ticket, (array) $entry, $actor_user, [
                    'entry_index' => $index + 1,
                    'allow_author_override' => !empty($options['allow_author_override']),
                    'default_internal' => !empty($options['default_internal']),
                ]);

                if (!empty($entry_result['comment_id'])) {
                    $comment_count++;
                }
                if (!empty($entry_result['time_entry_id'])) {
                    $time_count++;
                }
                foreach ((array) ($entry_result['warnings'] ?? []) as $warning) {
                    $warnings[] = (string) $warning;
                }
            }

            if ($comment_count > 0 || $time_count > 0) {
                log_activity(
                    $ticket_id,
                    $actor_id,
                    'md_import',
                    'Imported worklog entries: comments=' . $comment_count . ', time_entries=' . $time_count
                );
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return [
            'ticket_id' => $ticket_id,
            'comments' => $comment_count,
            'time_entries' => $time_count,
            'warnings' => $warnings,
        ];
    }
}

if (!function_exists('ticket_import_append_payload_to_ticket')) {
    function ticket_import_append_payload_to_ticket($ticket_id, array $payload, array $actor_user, array $options = [])
    {
        $ticket_id = (int) $ticket_id;
        if ($ticket_id <= 0) {
            throw new InvalidArgumentException('Invalid ticket selected for import.');
        }

        $actor_id = (int) ($actor_user['id'] ?? 0);
        if ($actor_id <= 0) {
            throw new InvalidArgumentException('Invalid current user.');
        }

        $ticket = get_ticket($ticket_id);
        if (!$ticket) {
            throw new InvalidArgumentException('Ticket not found.');
        }

        $entries = (array) ($payload['entries'] ?? []);
        $description = trim((string) ($payload['description'] ?? ''));
        if ($description === '') {
            $description = trim((string) ($payload['raw'] ?? ''));
        }

        if (empty($entries) && $description !== '') {
            $entries[] = [
                'author_email' => '',
                'author_name' => '',
                'date' => date('Y-m-d'),
                'start' => '',
                'end' => '',
                'duration_minutes' => '',
                'billable' => '',
                'internal' => '',
                'notify' => '',
                'summary' => 'Imported note',
                'comment' => $description,
            ];
        }

        if (empty($entries)) {
            throw new InvalidArgumentException('No worklog entries found in .md file. Use the template or include Entry blocks with Date/Start/Duration/Comment fields.');
        }

        $warnings = [];
        $comment_count = 0;
        $time_count = 0;
        $db = get_db();

        try {
            $db->beginTransaction();

            foreach ($entries as $index => $entry) {
                $entry_result = ticket_import_apply_entry_to_ticket($ticket_id, $ticket, (array) $entry, $actor_user, [
                    'entry_index' => $index + 1,
                    'allow_author_override' => !empty($options['allow_author_override']),
                    'default_internal' => !empty($options['default_internal']),
                ]);

                if (!empty($entry_result['comment_id'])) {
                    $comment_count++;
                }
                if (!empty($entry_result['time_entry_id'])) {
                    $time_count++;
                }
                foreach ((array) ($entry_result['warnings'] ?? []) as $warning) {
                    $warnings[] = (string) $warning;
                }
            }

            if ($comment_count === 0 && $time_count === 0) {
                $raw_fallback = trim((string) ($payload['raw'] ?? ''));
                if ($raw_fallback !== '') {
                    $fallback_entry = [
                        'author_email' => '',
                        'author_name' => '',
                        'date' => date('Y-m-d'),
                        'start' => '',
                        'end' => '',
                        'duration_minutes' => '',
                        'billable' => '',
                        'internal' => '',
                        'notify' => '',
                        'summary' => 'Imported note',
                        'comment' => $raw_fallback,
                    ];
                    $fallback_result = ticket_import_apply_entry_to_ticket($ticket_id, $ticket, $fallback_entry, $actor_user, [
                        'entry_index' => count($entries) + 1,
                        'allow_author_override' => !empty($options['allow_author_override']),
                        'default_internal' => !empty($options['default_internal']),
                    ]);
                    if (!empty($fallback_result['comment_id'])) {
                        $comment_count++;
                    }
                    if (!empty($fallback_result['time_entry_id'])) {
                        $time_count++;
                    }
                    foreach ((array) ($fallback_result['warnings'] ?? []) as $warning) {
                        $warnings[] = (string) $warning;
                    }
                }
            }

            if ($comment_count === 0 && $time_count === 0) {
                throw new InvalidArgumentException('No importable worklog data found in .md file. Use the provided template in Admin > Settings.');
            }

            log_activity(
                $ticket_id,
                $actor_id,
                'md_import',
                'Imported worklog entries: comments=' . $comment_count . ', time_entries=' . $time_count
            );

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return [
            'ticket_id' => $ticket_id,
            'comments' => $comment_count,
            'time_entries' => $time_count,
            'warnings' => $warnings,
        ];
    }
}

if (!function_exists('ticket_import_get_available_templates')) {
    function ticket_import_get_available_templates()
    {
        return [
            'new_ticket_en' => [
                'label' => 'New ticket + worklog template (EN)',
                'description' => 'Create a ticket with description, comments, and time entries.',
            ],
            'append_worklog_en' => [
                'label' => 'Worklog append template (EN)',
                'description' => 'Append comments and time entries to an existing ticket.',
            ],
        ];
    }
}

if (!function_exists('ticket_import_get_template_filename')) {
    function ticket_import_get_template_filename($template_key)
    {
        $map = [
            'new_ticket_en' => 'ticket-import-new-ticket-template.en.md',
            'append_worklog_en' => 'ticket-import-worklog-append-template.en.md',
        ];

        return $map[$template_key] ?? null;
    }
}

if (!function_exists('ticket_import_get_template_content')) {
    function ticket_import_get_template_content($template_key)
    {
        if ($template_key === 'new_ticket_en') {
            return <<<'MD'
# FoxDesk Ticket Import (v1)

## Ticket
Title: Example ticket title
Requester Email: client@example.com
Requester Name: Client Name
Type: general
Priority: medium
Status: Open
Due Date: 2026-02-20 17:00
Tags: website, maintenance
Organization: Example Company

## Description
Describe the request in Markdown.
- What happened
- What is expected
- Any relevant context

## Worklog
### Entry
Author Email: agent@example.com
Date: 2026-02-13
Start: 09:00
End: 10:15
Duration Minutes:
Billable: yes
Internal: no
Summary: Initial diagnostics
Comment:
Checked logs and validated the issue scope.
Prepared next steps for implementation.

### Entry
Author Email: agent@example.com
Date: 2026-02-13
Start: 10:30
Duration Minutes: 45
Billable: yes
Internal: no
Summary: Implemented fix
Comment:
Applied the fix and verified functionality.
MD;
        }

        if ($template_key === 'append_worklog_en') {
            return <<<'MD'
# FoxDesk Worklog Import (v1)

## Worklog
### Entry
Author Email: agent@example.com
Date: 2026-02-13
Start: 13:00
Duration Minutes: 30
Billable: yes
Internal: no
Summary: Follow-up check
Comment:
Confirmed that the solution is stable and no regressions were found.

### Entry
Author Email: agent@example.com
Date: 2026-02-13
Start: 14:00
End: 14:40
Billable: no
Internal: yes
Summary: Internal coordination
Comment:
Aligned next actions with the delivery team.
MD;
        }

        return null;
    }
}

