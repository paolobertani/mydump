<?php

declare(strict_types=1);

require_once __DIR__ . '/src/argv.php';
require_once __DIR__ . '/src/xlsx.php';

mydump_main($argv);

function mydump_main(array $argv): void
{
    try {
        mydump_include_kalei();

        $cli = mydump_parse_cli($argv);
        if (($cli['options']['help'] ?? false) === true) {
            mydump_print_usage();
            return;
        }

        $mode = $cli['mode'];
        if ($mode !== 'dump' && $mode !== 'write') {
            $modeInput = strtolower(mydump_prompt('Mode (dump/write)', 'dump', true));
            $mode = $modeInput === 'write' ? 'write' : 'dump';
        }

        if ($mode === 'dump') {
            mydump_run_dump_mode($cli);
            return;
        }

        mydump_run_write_mode($cli);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

function mydump_run_dump_mode(array $cli): void
{
    $options = $cli['options'];
    $outputFile = (string) ($options['output'] ?? '');
    if ($outputFile === '') {
        $outputFile = mydump_prompt('Output file (.xlsx/.json/.js)', null, true);
    }

    $format = mydump_detect_format($outputFile);
    if ($format === null) {
        throw new RuntimeException('Unsupported output extension. Use .xlsx, .json or .js');
    }

    $conn = mydump_resolve_connection($options, null);

    $pdo = mydump_connect_server($conn, false);
    if (!mydump_database_exists($pdo, $conn['db'])) {
        throw new RuntimeException("Database '{$conn['db']}' does not exist.");
    }

    $schema = mydump_fetch_schema($pdo, $conn['db']);

    mydump_write_schema_file($schema, $outputFile, $format);
    fwrite(STDOUT, "Dump complete: {$outputFile}" . PHP_EOL);
}

function mydump_run_write_mode(array $cli): void
{
    $options = $cli['options'];
    $inputFile = (string) ($cli['input'] ?? '');
    if ($inputFile === '') {
        $inputFile = mydump_prompt('Input file (.xlsx/.json/.js)', null, true);
    }

    if (!is_file($inputFile)) {
        throw new RuntimeException("Input file not found: {$inputFile}");
    }

    $format = mydump_detect_format($inputFile);
    if ($format === null) {
        throw new RuntimeException('Unsupported input extension. Use .xlsx, .json or .js');
    }

    $schema = mydump_read_schema_file($inputFile, $format);
    $schemaDb = mydump_schema_database_name($schema);
    $conn = mydump_resolve_connection($options, $schemaDb);

    $pdo = mydump_connect_server($conn, false);

    $dbExists = mydump_database_exists($pdo, $conn['db']);
    if (!$dbExists) {
        $dbMeta = $schema['database'] ?? [];
        $charset = (string) ($dbMeta['default_character_set'] ?? 'utf8mb4');
        $collation = (string) ($dbMeta['default_collation'] ?? 'utf8mb4_unicode_ci');
        mydump_create_database($pdo, $conn['db'], $charset, $collation);
        fwrite(STDOUT, "Created database `{$conn['db']}`" . PHP_EOL);
    }

    $pdo->exec('USE ' . mydump_quote_identifier($conn['db']));

    $plan = mydump_build_write_plan($pdo, $conn['db'], $schema);

    if ($dbExists && !$options['run'] && !empty($plan['statements'])) {
        $ok = mydump_confirm(
            "Database `{$conn['db']}` already exists. Apply " . count($plan['statements']) . ' statement(s)?',
            true
        );
        if (!$ok) {
            fwrite(STDOUT, 'Aborted by user.' . PHP_EOL);
            return;
        }
    }

    if (empty($plan['statements'])) {
        fwrite(STDOUT, 'No changes required.' . PHP_EOL);
        return;
    }

    foreach ($plan['statements'] as $stmt) {
        $sql = (string) $stmt['sql'];
        $pdo->exec($sql);
        fwrite(STDOUT, '[OK] ' . $sql . PHP_EOL);
    }

    fwrite(STDOUT, 'Write complete.' . PHP_EOL);
}

function mydump_include_kalei(): void
{
    $kaleiPath = __DIR__ . DIRECTORY_SEPARATOR . 'kalei.php';
    if (is_file($kaleiPath)) {
        require_once $kaleiPath;
    }
}

function mydump_resolve_connection(array $options, ?string $dbDefault): array
{
    $constants = mydump_detect_connection_constants();

    $host = mydump_pick_value($constants['host'], $options['host'] ?? null, '127.0.0.1');
    $port = mydump_pick_value($constants['port'], $options['port'] ?? null, '3306');
    $user = mydump_pick_value($constants['user'], $options['user'] ?? null, 'root');

    $passFromConstOrArg = mydump_pick_value($constants['pass'], $options['pass'] ?? null, null);
    if ($passFromConstOrArg === null) {
        $passFromConstOrArg = mydump_prompt_secret('MySQL password (leave empty for none)');
    }

    $db = mydump_pick_value($constants['db'], $options['db'] ?? null, $dbDefault);
    if ($db === null || trim($db) === '') {
        $db = mydump_prompt('Database name', $dbDefault, true);
    }

    $port = trim((string) $port);
    while (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        $port = mydump_prompt('MySQL port', '3306', true);
    }

    return [
        'host' => (string) $host,
        'port' => (int) $port,
        'user' => (string) $user,
        'pass' => (string) $passFromConstOrArg,
        'db' => (string) $db,
    ];
}

function mydump_pick_value(?string $fromConst, ?string $fromArg, ?string $fallback): ?string
{
    if ($fromConst !== null && $fromConst !== '') {
        return $fromConst;
    }
    if ($fromArg !== null && $fromArg !== '') {
        return $fromArg;
    }
    return $fallback;
}

function mydump_detect_connection_constants(): array
{
    $userConstants = get_defined_constants(true)['user'] ?? [];

    return [
        'host' => mydump_pick_constant($userConstants, [
            'DB_HOST', 'MYSQL_HOST', 'DATABASE_HOST', 'DBHOST', 'DB_SERVER', 'SQL_HOST',
        ], '/(db|mysql|sql).*(host)|(host).*(db|mysql|sql)/i'),
        'port' => mydump_pick_constant($userConstants, [
            'DB_PORT', 'MYSQL_PORT', 'DATABASE_PORT', 'DBPORT', 'SQL_PORT',
        ], '/(db|mysql|sql).*(port)|(port).*(db|mysql|sql)/i'),
        'user' => mydump_pick_constant($userConstants, [
            'DB_USER', 'DB_USERNAME', 'MYSQL_USER', 'DATABASE_USER', 'DB_LOGIN', 'SQL_USER',
        ], '/(db|mysql|sql).*(user|username|login)|(user|username|login).*(db|mysql|sql)/i'),
        'pass' => mydump_pick_constant($userConstants, [
            'DB_PASS', 'DB_PASSWORD', 'MYSQL_PASS', 'MYSQL_PASSWORD', 'DATABASE_PASS', 'SQL_PASSWORD',
        ], '/(db|mysql|sql).*(pass|password)|(pass|password).*(db|mysql|sql)/i'),
        'db' => mydump_pick_constant($userConstants, [
            'DB_NAME', 'MYSQL_DB', 'MYSQL_DATABASE', 'DATABASE_NAME', 'DB_DATABASE', 'DB',
        ], '/(db|database|schema).*(name)?|(name).*(db|database|schema)/i'),
    ];
}

function mydump_pick_constant(array $constants, array $preferredNames, string $regex): ?string
{
    foreach ($preferredNames as $name) {
        if (array_key_exists($name, $constants) && is_scalar($constants[$name])) {
            return trim((string) $constants[$name]);
        }
    }

    foreach ($constants as $name => $value) {
        if (!is_scalar($value)) {
            continue;
        }
        $nameText = (string) $name;
        if (preg_match($regex, $nameText) === 1) {
            return trim((string) $value);
        }
    }

    return null;
}

function mydump_detect_format(string $path): ?string
{
    $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
        return 'xlsx';
    }
    if ($ext === 'json' || $ext === 'js') {
        return 'json';
    }
    return null;
}

function mydump_connect_server(array $conn, bool $withDb): PDO
{
    $dsn = 'mysql:host=' . $conn['host'] . ';port=' . $conn['port'] . ';charset=utf8mb4';
    if ($withDb) {
        $dsn .= ';dbname=' . $conn['db'];
    }

    return new PDO(
        $dsn,
        $conn['user'],
        $conn['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

function mydump_database_exists(PDO $pdo, string $dbName): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :db LIMIT 1');
    $stmt->execute(['db' => $dbName]);
    return (bool) $stmt->fetchColumn();
}

function mydump_create_database(PDO $pdo, string $dbName, string $charset, string $collation): void
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $charset)) {
        $charset = 'utf8mb4';
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $collation)) {
        $collation = 'utf8mb4_unicode_ci';
    }

    $sql = 'CREATE DATABASE ' . mydump_quote_identifier($dbName)
        . ' CHARACTER SET ' . $charset
        . ' COLLATE ' . $collation;

    $pdo->exec($sql);
}

function mydump_fetch_schema(PDO $pdo, string $dbName): array
{
    $schemaStmt = $pdo->prepare(
        'SELECT SCHEMA_NAME, DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME
         FROM information_schema.SCHEMATA
         WHERE SCHEMA_NAME = :db'
    );
    $schemaStmt->execute(['db' => $dbName]);
    $schemaRow = $schemaStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $tablesStmt = $pdo->prepare(
        'SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = :db
         ORDER BY TABLE_NAME'
    );
    $tablesStmt->execute(['db' => $dbName]);
    $tableRows = $tablesStmt->fetchAll(PDO::FETCH_ASSOC);

    $objects = [];
    foreach ($tableRows as $tableRow) {
        $tableName = (string) $tableRow['TABLE_NAME'];
        $tableType = strtoupper((string) $tableRow['TABLE_TYPE']);
        $isView = ($tableType === 'VIEW');

        $fields = mydump_fetch_columns($pdo, $dbName, $tableName);
        $indexes = $isView ? [] : mydump_fetch_indexes($pdo, $tableName);
        $createSql = mydump_fetch_create_sql($pdo, $tableName, $isView);

        $objects[] = [
            'name' => $tableName,
            'type' => $isView ? 'view' : 'table',
            'engine' => $isView ? null : (string) ($tableRow['ENGINE'] ?? ''),
            'collation' => (string) ($tableRow['TABLE_COLLATION'] ?? ''),
            'create_sql' => $createSql,
            'fields' => $fields,
            'indexes' => $indexes,
        ];
    }

    return [
        'database' => [
            'name' => (string) ($schemaRow['SCHEMA_NAME'] ?? $dbName),
            'default_character_set' => (string) ($schemaRow['DEFAULT_CHARACTER_SET_NAME'] ?? 'utf8mb4'),
            'default_collation' => (string) ($schemaRow['DEFAULT_COLLATION_NAME'] ?? 'utf8mb4_unicode_ci'),
        ],
        'objects' => $objects,
        'generated_at' => date('c'),
    ];
}

function mydump_fetch_columns(PDO $pdo, string $dbName, string $tableName): array
{
    $sql = 'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_KEY, COLLATION_NAME, COLUMN_COMMENT, ORDINAL_POSITION, GENERATION_EXPRESSION
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table
            ORDER BY ORDINAL_POSITION';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['db' => $dbName, 'table' => $tableName]);

    $fields = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $defaultMeta = mydump_detect_default_meta(
            $row['COLUMN_DEFAULT'],
            strtoupper((string) $row['IS_NULLABLE']) === 'YES',
            (string) ($row['EXTRA'] ?? '')
        );

        $fields[] = [
            'name' => (string) $row['COLUMN_NAME'],
            'type' => (string) $row['COLUMN_TYPE'],
            'nullable' => strtoupper((string) $row['IS_NULLABLE']) === 'YES',
            'default_kind' => $defaultMeta['kind'],
            'default_value' => $defaultMeta['value'],
            'extra' => (string) ($row['EXTRA'] ?? ''),
            'key' => (string) ($row['COLUMN_KEY'] ?? ''),
            'collation' => (string) ($row['COLLATION_NAME'] ?? ''),
            'comment' => (string) ($row['COLUMN_COMMENT'] ?? ''),
            'position' => (int) ($row['ORDINAL_POSITION'] ?? 0),
            'generation_expression' => (string) ($row['GENERATION_EXPRESSION'] ?? ''),
        ];
    }

    return $fields;
}

function mydump_detect_default_meta($columnDefault, bool $nullable, string $extra): array
{
    if ($columnDefault === null) {
        return [
            'kind' => $nullable ? 'null' : 'none',
            'value' => '',
        ];
    }

    $value = (string) $columnDefault;
    if (mydump_is_expression_default($value, $extra)) {
        return ['kind' => 'expression', 'value' => $value];
    }

    return ['kind' => 'literal', 'value' => $value];
}

function mydump_is_expression_default(string $value, string $extra): bool
{
    if (stripos($extra, 'DEFAULT_GENERATED') !== false) {
        return true;
    }

    return preg_match('/^(CURRENT_TIMESTAMP(?:\(\d+\))?|NOW\(\)|UUID\(\)|NULL|\(.+\))$/i', trim($value)) === 1;
}

function mydump_fetch_indexes(PDO $pdo, string $tableName): array
{
    $sql = 'SHOW INDEX FROM ' . mydump_quote_identifier($tableName);
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $grouped = [];
    foreach ($rows as $row) {
        $name = (string) $row['Key_name'];
        if (!isset($grouped[$name])) {
            $grouped[$name] = [
                'name' => $name,
                'unique' => ((int) $row['Non_unique']) === 0,
                'type' => strtoupper((string) ($row['Index_type'] ?? 'BTREE')),
                'columns' => [],
            ];
        }

        $seq = (int) ($row['Seq_in_index'] ?? 0);
        $grouped[$name]['columns'][$seq] = [
            'name' => (string) ($row['Column_name'] ?? ''),
            'length' => $row['Sub_part'] !== null ? (int) $row['Sub_part'] : null,
        ];
    }

    foreach ($grouped as &$index) {
        ksort($index['columns']);
        $index['columns'] = array_values($index['columns']);
    }
    unset($index);

    uasort($grouped, static function (array $a, array $b): int {
        if ($a['name'] === 'PRIMARY') {
            return -1;
        }
        if ($b['name'] === 'PRIMARY') {
            return 1;
        }
        return strcmp($a['name'], $b['name']);
    });

    return array_values($grouped);
}

function mydump_fetch_create_sql(PDO $pdo, string $name, bool $isView): string
{
    $sql = $isView
        ? ('SHOW CREATE VIEW ' . mydump_quote_identifier($name))
        : ('SHOW CREATE TABLE ' . mydump_quote_identifier($name));

    $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return '';
    }

    foreach ($row as $key => $value) {
        if (stripos((string) $key, 'Create ') === 0) {
            return trim((string) $value);
        }
    }

    return '';
}

function mydump_write_schema_file(array $schema, string $path, string $format): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        throw new RuntimeException("Directory does not exist: {$dir}");
    }

    if ($format === 'json') {
        $json = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Unable to encode JSON output.');
        }
        file_put_contents($path, $json . PHP_EOL);
        return;
    }

    $worksheets = mydump_schema_to_worksheets($schema);
    mydump_xlsx_write($path, $worksheets);
}

function mydump_schema_to_worksheets(array $schema): array
{
    $headers = [
        'kind',
        'table_name',
        'field_name',
        'position',
        'column_type',
        'nullable',
        'default_kind',
        'default_value',
        'extra',
        'key',
        'collation',
        'comment',
        'generation_expression',
        'indexes_json',
        'table_engine',
        'table_collation',
        'create_sql',
    ];

    $worksheets = [];
    foreach (($schema['objects'] ?? []) as $object) {
        $name = (string) ($object['name'] ?? 'Sheet');
        $type = strtolower((string) ($object['type'] ?? 'table'));
        $fields = $object['fields'] ?? [];

        if (!is_array($fields) || empty($fields)) {
            $fields = [[
                'name' => '',
                'position' => 1,
                'type' => '',
                'nullable' => true,
                'default_kind' => 'none',
                'default_value' => '',
                'extra' => '',
                'key' => '',
                'collation' => '',
                'comment' => '',
                'generation_expression' => '',
            ]];
        }

        $rows = [$headers];
        $first = true;
        foreach ($fields as $field) {
            $rows[] = [
                $type,
                $name,
                (string) ($field['name'] ?? ''),
                (string) ($field['position'] ?? ''),
                (string) ($field['type'] ?? ''),
                !empty($field['nullable']) ? 'YES' : 'NO',
                (string) ($field['default_kind'] ?? 'none'),
                (string) ($field['default_value'] ?? ''),
                (string) ($field['extra'] ?? ''),
                (string) ($field['key'] ?? ''),
                (string) ($field['collation'] ?? ''),
                (string) ($field['comment'] ?? ''),
                (string) ($field['generation_expression'] ?? ''),
                $first ? json_encode($object['indexes'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
                $first ? (string) ($object['engine'] ?? '') : '',
                $first ? (string) ($object['collation'] ?? '') : '',
                $first ? (string) ($object['create_sql'] ?? '') : '',
            ];
            $first = false;
        }

        $worksheets[$name] = $rows;
    }

    if (empty($worksheets)) {
        $worksheets['schema'] = [$headers];
    }

    return $worksheets;
}

function mydump_read_schema_file(string $path, string $format): array
{
    if ($format === 'json') {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON input file.');
        }
        return mydump_normalize_schema($decoded);
    }

    $worksheets = mydump_xlsx_read($path);
    return mydump_normalize_schema(mydump_schema_from_worksheets($worksheets));
}

function mydump_schema_from_worksheets(array $worksheets): array
{
    $objects = [];

    foreach ($worksheets as $sheetName => $rows) {
        if (!is_array($rows) || count($rows) === 0) {
            continue;
        }

        $header = array_map('mydump_normalize_header', (array) $rows[0]);
        if (empty($header)) {
            continue;
        }

        $object = [
            'name' => (string) $sheetName,
            'type' => 'table',
            'engine' => '',
            'collation' => '',
            'create_sql' => '',
            'fields' => [],
            'indexes' => [],
        ];

        for ($i = 1, $n = count($rows); $i < $n; $i++) {
            $row = (array) $rows[$i];
            $assoc = [];
            foreach ($header as $idx => $key) {
                if ($key === '') {
                    continue;
                }
                $assoc[$key] = (string) ($row[$idx] ?? '');
            }

            if (mydump_row_is_empty($assoc)) {
                continue;
            }

            if (!empty($assoc['table_name'])) {
                $object['name'] = $assoc['table_name'];
            }
            if (!empty($assoc['kind'])) {
                $kind = strtolower($assoc['kind']);
                $object['type'] = ($kind === 'view') ? 'view' : 'table';
            }
            if ($object['engine'] === '' && !empty($assoc['table_engine'])) {
                $object['engine'] = $assoc['table_engine'];
            }
            if ($object['collation'] === '' && !empty($assoc['table_collation'])) {
                $object['collation'] = $assoc['table_collation'];
            }
            if ($object['create_sql'] === '' && !empty($assoc['create_sql'])) {
                $object['create_sql'] = $assoc['create_sql'];
            }

            if (empty($object['indexes']) && !empty($assoc['indexes_json'])) {
                $parsed = json_decode($assoc['indexes_json'], true);
                if (is_array($parsed)) {
                    $object['indexes'] = $parsed;
                }
            }

            $fieldName = (string) ($assoc['field_name'] ?? ($assoc['column_name'] ?? ''));
            if ($fieldName === '') {
                continue;
            }

            $nullable = mydump_parse_yes_no($assoc['nullable'] ?? 'YES', true);
            $defaultKind = strtolower(trim((string) ($assoc['default_kind'] ?? '')));
            $defaultValue = (string) ($assoc['default_value'] ?? ($assoc['default'] ?? ''));
            if ($defaultKind === '') {
                if ($defaultValue !== '') {
                    $defaultKind = 'literal';
                } else {
                    $defaultKind = $nullable ? 'null' : 'none';
                }
            }

            $object['fields'][] = [
                'name' => $fieldName,
                'position' => (int) ($assoc['position'] ?? ($i + 1)),
                'type' => (string) ($assoc['column_type'] ?? ($assoc['type'] ?? 'varchar(255)')),
                'nullable' => $nullable,
                'default_kind' => $defaultKind,
                'default_value' => $defaultValue,
                'extra' => (string) ($assoc['extra'] ?? ''),
                'key' => (string) ($assoc['key'] ?? ''),
                'collation' => (string) ($assoc['collation'] ?? ''),
                'comment' => (string) ($assoc['comment'] ?? ''),
                'generation_expression' => (string) ($assoc['generation_expression'] ?? ''),
            ];
        }

        if (!empty($object['fields']) || $object['create_sql'] !== '') {
            $objects[] = $object;
        }
    }

    return ['objects' => $objects];
}

function mydump_normalize_schema(array $schema): array
{
    $database = [
        'name' => mydump_extract_database_name($schema),
        'default_character_set' => mydump_extract_database_charset($schema),
        'default_collation' => mydump_extract_database_collation($schema),
    ];

    $objectsRaw = [];
    if (isset($schema['objects']) && is_array($schema['objects'])) {
        $objectsRaw = $schema['objects'];
    } elseif (isset($schema['tables']) && is_array($schema['tables'])) {
        $objectsRaw = $schema['tables'];
        if (isset($schema['views']) && is_array($schema['views'])) {
            foreach ($schema['views'] as $view) {
                if (is_array($view)) {
                    $view['type'] = 'view';
                    $objectsRaw[] = $view;
                }
            }
        }
    }

    if (mydump_is_assoc($objectsRaw)) {
        $named = [];
        foreach ($objectsRaw as $name => $objectRaw) {
            if (is_array($objectRaw)) {
                if (!isset($objectRaw['name'])) {
                    $objectRaw['name'] = (string) $name;
                }
                $named[] = $objectRaw;
            }
        }
        $objectsRaw = $named;
    }

    $objects = [];
    foreach ($objectsRaw as $objectRaw) {
        if (!is_array($objectRaw)) {
            continue;
        }
        $object = mydump_normalize_object($objectRaw);
        if ($object !== null) {
            $objects[] = $object;
        }
    }

    return [
        'database' => $database,
        'objects' => $objects,
    ];
}

function mydump_normalize_object(array $objectRaw): ?array
{
    $name = (string) ($objectRaw['name'] ?? ($objectRaw['table_name'] ?? ''));
    if ($name === '') {
        return null;
    }

    $typeRaw = strtolower((string) ($objectRaw['type'] ?? $objectRaw['kind'] ?? 'table'));
    $type = ($typeRaw === 'view') ? 'view' : 'table';

    $fieldsRaw = $objectRaw['fields'] ?? ($objectRaw['columns'] ?? []);
    if (mydump_is_assoc($fieldsRaw)) {
        $tmp = [];
        foreach ($fieldsRaw as $fieldName => $fieldRaw) {
            if (is_array($fieldRaw) && !isset($fieldRaw['name'])) {
                $fieldRaw['name'] = (string) $fieldName;
            }
            $tmp[] = $fieldRaw;
        }
        $fieldsRaw = $tmp;
    }

    $fields = [];
    if (is_array($fieldsRaw)) {
        foreach ($fieldsRaw as $fieldRaw) {
            if (!is_array($fieldRaw)) {
                continue;
            }
            $field = mydump_normalize_field($fieldRaw);
            if ($field !== null) {
                $fields[] = $field;
            }
        }
    }

    usort($fields, static function (array $a, array $b): int {
        return ($a['position'] <=> $b['position']) ?: strcmp($a['name'], $b['name']);
    });

    $indexesRaw = $objectRaw['indexes'] ?? [];
    if (is_string($indexesRaw)) {
        $decoded = json_decode($indexesRaw, true);
        if (is_array($decoded)) {
            $indexesRaw = $decoded;
        } else {
            $indexesRaw = [];
        }
    }
    $indexes = mydump_normalize_indexes($indexesRaw);

    return [
        'name' => $name,
        'type' => $type,
        'engine' => (string) ($objectRaw['engine'] ?? ($objectRaw['table_engine'] ?? '')),
        'collation' => (string) ($objectRaw['collation'] ?? ($objectRaw['table_collation'] ?? '')),
        'create_sql' => (string) ($objectRaw['create_sql'] ?? ($objectRaw['sql'] ?? '')),
        'fields' => $fields,
        'indexes' => $indexes,
    ];
}

function mydump_normalize_field(array $fieldRaw): ?array
{
    $name = (string) ($fieldRaw['name'] ?? ($fieldRaw['field'] ?? ($fieldRaw['column_name'] ?? '')));
    if ($name === '') {
        return null;
    }

    $nullable = $fieldRaw['nullable'] ?? ($fieldRaw['null'] ?? true);
    if (is_string($nullable)) {
        $nullable = mydump_parse_yes_no($nullable, true);
    } else {
        $nullable = (bool) $nullable;
    }

    $defaultKind = strtolower(trim((string) ($fieldRaw['default_kind'] ?? '')));
    $defaultValue = (string) ($fieldRaw['default_value'] ?? ($fieldRaw['default'] ?? ''));
    if ($defaultKind === '') {
        if ($defaultValue !== '') {
            $defaultKind = 'literal';
        } else {
            $defaultKind = $nullable ? 'null' : 'none';
        }
    }

    return [
        'name' => $name,
        'position' => (int) ($fieldRaw['position'] ?? ($fieldRaw['ordinal_position'] ?? 0)),
        'type' => (string) ($fieldRaw['type'] ?? ($fieldRaw['column_type'] ?? 'varchar(255)')),
        'nullable' => $nullable,
        'default_kind' => $defaultKind,
        'default_value' => $defaultValue,
        'extra' => (string) ($fieldRaw['extra'] ?? ''),
        'key' => (string) ($fieldRaw['key'] ?? ''),
        'collation' => (string) ($fieldRaw['collation'] ?? ''),
        'comment' => (string) ($fieldRaw['comment'] ?? ''),
        'generation_expression' => (string) ($fieldRaw['generation_expression'] ?? ''),
    ];
}

function mydump_normalize_indexes($indexesRaw): array
{
    if (!is_array($indexesRaw)) {
        return [];
    }

    $indexes = [];
    foreach ($indexesRaw as $indexRaw) {
        if (!is_array($indexRaw)) {
            continue;
        }

        $name = (string) ($indexRaw['name'] ?? '');
        if ($name === '') {
            continue;
        }

        $colsRaw = $indexRaw['columns'] ?? [];
        $columns = [];
        if (is_array($colsRaw)) {
            foreach ($colsRaw as $colRaw) {
                if (is_string($colRaw)) {
                    $columns[] = ['name' => $colRaw, 'length' => null];
                } elseif (is_array($colRaw)) {
                    $colName = (string) ($colRaw['name'] ?? '');
                    if ($colName === '') {
                        continue;
                    }
                    $length = $colRaw['length'] ?? ($colRaw['sub_part'] ?? null);
                    $columns[] = [
                        'name' => $colName,
                        'length' => $length !== null && $length !== '' ? (int) $length : null,
                    ];
                }
            }
        }

        if (empty($columns)) {
            continue;
        }

        $indexes[] = [
            'name' => $name,
            'unique' => (bool) ($indexRaw['unique'] ?? false),
            'type' => strtoupper((string) ($indexRaw['type'] ?? 'BTREE')),
            'columns' => $columns,
        ];
    }

    usort($indexes, static function (array $a, array $b): int {
        if ($a['name'] === 'PRIMARY') {
            return -1;
        }
        if ($b['name'] === 'PRIMARY') {
            return 1;
        }
        return strcmp($a['name'], $b['name']);
    });

    return $indexes;
}

function mydump_schema_database_name(array $schema): ?string
{
    $name = (string) (($schema['database']['name'] ?? '') ?: '');
    return $name !== '' ? $name : null;
}

function mydump_extract_database_name(array $schema): string
{
    if (isset($schema['database']) && is_array($schema['database'])) {
        $candidate = (string) ($schema['database']['name'] ?? ($schema['database']['db'] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }
    }

    $candidate = (string) ($schema['db'] ?? ($schema['database_name'] ?? ''));
    return $candidate;
}

function mydump_extract_database_charset(array $schema): string
{
    if (isset($schema['database']) && is_array($schema['database'])) {
        $candidate = (string) ($schema['database']['default_character_set'] ?? ($schema['database']['charset'] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return 'utf8mb4';
}

function mydump_extract_database_collation(array $schema): string
{
    if (isset($schema['database']) && is_array($schema['database'])) {
        $candidate = (string) ($schema['database']['default_collation'] ?? ($schema['database']['collation'] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return 'utf8mb4_unicode_ci';
}

function mydump_build_write_plan(PDO $pdo, string $dbName, array $schema): array
{
    $existingMap = mydump_fetch_existing_object_map($pdo, $dbName);
    $statements = [];

    foreach (($schema['objects'] ?? []) as $object) {
        if (!is_array($object)) {
            continue;
        }

        $name = (string) ($object['name'] ?? '');
        if ($name === '') {
            continue;
        }

        $desiredType = strtolower((string) ($object['type'] ?? 'table'));
        $existsType = $existingMap[$name] ?? null;

        if ($desiredType === 'view') {
            $viewSql = mydump_prepare_view_create_sql((string) ($object['create_sql'] ?? ''), $name);
            if ($viewSql === '') {
                throw new RuntimeException("View '{$name}' is missing create_sql in input.");
            }

            if ($existsType === 'BASE TABLE') {
                $statements[] = ['sql' => 'DROP TABLE ' . mydump_quote_identifier($name)];
            }

            $statements[] = ['sql' => $viewSql];
            continue;
        }

        if ($existsType === null) {
            $createSql = mydump_build_create_table_sql($object);
            $statements[] = ['sql' => $createSql];
            continue;
        }

        if ($existsType === 'VIEW') {
            $statements[] = ['sql' => 'DROP VIEW ' . mydump_quote_identifier($name)];
            $statements[] = ['sql' => mydump_build_create_table_sql($object)];
            continue;
        }

        $alterSql = mydump_build_alter_table_sql($pdo, $dbName, $object);
        if ($alterSql !== null) {
            $statements[] = ['sql' => $alterSql];
        }
    }

    return ['statements' => $statements];
}

function mydump_fetch_existing_object_map(PDO $pdo, string $dbName): array
{
    $stmt = $pdo->prepare(
        'SELECT TABLE_NAME, TABLE_TYPE
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = :db'
    );
    $stmt->execute(['db' => $dbName]);

    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(string) $row['TABLE_NAME']] = strtoupper((string) $row['TABLE_TYPE']);
    }
    return $map;
}

function mydump_build_create_table_sql(array $object): string
{
    $createSql = trim((string) ($object['create_sql'] ?? ''));
    if ($createSql !== '') {
        return rtrim($createSql, ';');
    }

    $name = (string) ($object['name'] ?? '');
    $fields = $object['fields'] ?? [];
    if (!is_array($fields) || empty($fields)) {
        throw new RuntimeException("Table '{$name}' has no fields and no create_sql.");
    }

    usort($fields, static function (array $a, array $b): int {
        return ($a['position'] <=> $b['position']) ?: strcmp($a['name'], $b['name']);
    });

    $lines = [];
    foreach ($fields as $field) {
        $lines[] = '  ' . mydump_column_definition_sql($field);
    }

    $indexes = mydump_normalize_indexes($object['indexes'] ?? []);
    foreach ($indexes as $index) {
        $indexDef = mydump_index_definition_sql($index, false);
        if ($indexDef !== '') {
            $lines[] = '  ' . $indexDef;
        }
    }

    $sql = 'CREATE TABLE ' . mydump_quote_identifier($name) . " (\n" . implode(",\n", $lines) . "\n)";

    $engine = (string) ($object['engine'] ?? '');
    if ($engine !== '' && preg_match('/^[A-Za-z0-9_]+$/', $engine) === 1) {
        $sql .= ' ENGINE=' . $engine;
    }

    $collation = (string) ($object['collation'] ?? '');
    if ($collation !== '' && preg_match('/^[A-Za-z0-9_]+$/', $collation) === 1) {
        $sql .= ' COLLATE=' . $collation;
    }

    return $sql;
}

function mydump_build_alter_table_sql(PDO $pdo, string $dbName, array $object): ?string
{
    $tableName = (string) ($object['name'] ?? '');
    $desiredFields = $object['fields'] ?? [];
    if (!is_array($desiredFields) || empty($desiredFields)) {
        return null;
    }

    $currentFields = mydump_fetch_columns($pdo, $dbName, $tableName);
    $currentIndexes = mydump_fetch_indexes($pdo, $tableName);

    $tableMetaStmt = $pdo->prepare(
        'SELECT ENGINE, TABLE_COLLATION
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table'
    );
    $tableMetaStmt->execute(['db' => $dbName, 'table' => $tableName]);
    $tableMeta = $tableMetaStmt->fetch(PDO::FETCH_ASSOC) ?: ['ENGINE' => '', 'TABLE_COLLATION' => ''];

    usort($desiredFields, static function (array $a, array $b): int {
        return ($a['position'] <=> $b['position']) ?: strcmp($a['name'], $b['name']);
    });

    $currentByName = [];
    $currentOrder = [];
    foreach ($currentFields as $idx => $field) {
        $name = (string) $field['name'];
        $currentByName[$name] = $field;
        $currentOrder[$name] = $idx + 1;
    }

    $desiredByName = [];
    $columnOps = [];
    $prevFieldName = null;
    foreach ($desiredFields as $idx => $field) {
        $fieldName = (string) $field['name'];
        if ($fieldName === '') {
            continue;
        }

        $desiredByName[$fieldName] = $field;
        $positionSql = ($prevFieldName === null)
            ? ' FIRST'
            : (' AFTER ' . mydump_quote_identifier($prevFieldName));

        if (!isset($currentByName[$fieldName])) {
            $columnOps[] = 'ADD COLUMN ' . mydump_column_definition_sql($field) . $positionSql;
            $prevFieldName = $fieldName;
            continue;
        }

        $currentField = $currentByName[$fieldName];
        $sameDef = mydump_column_sign($currentField) === mydump_column_sign($field);
        $samePos = ($currentOrder[$fieldName] ?? 0) === ($idx + 1);

        if (!$sameDef || !$samePos) {
            $columnOps[] = 'MODIFY COLUMN ' . mydump_column_definition_sql($field) . $positionSql;
        }

        $prevFieldName = $fieldName;
    }

    foreach ($currentByName as $fieldName => $field) {
        if (!isset($desiredByName[$fieldName])) {
            $columnOps[] = 'DROP COLUMN ' . mydump_quote_identifier($fieldName);
        }
    }

    $indexOps = mydump_build_index_ops(
        mydump_normalize_indexes($currentIndexes),
        mydump_normalize_indexes($object['indexes'] ?? [])
    );

    $optionOps = [];
    $desiredEngine = (string) ($object['engine'] ?? '');
    $currentEngine = (string) ($tableMeta['ENGINE'] ?? '');
    if (
        $desiredEngine !== ''
        && preg_match('/^[A-Za-z0-9_]+$/', $desiredEngine) === 1
        && strcasecmp($desiredEngine, $currentEngine) !== 0
    ) {
        $optionOps[] = 'ENGINE=' . $desiredEngine;
    }

    $desiredCollation = (string) ($object['collation'] ?? '');
    $currentCollation = (string) ($tableMeta['TABLE_COLLATION'] ?? '');
    if (
        $desiredCollation !== ''
        && preg_match('/^[A-Za-z0-9_]+$/', $desiredCollation) === 1
        && strcasecmp($desiredCollation, $currentCollation) !== 0
    ) {
        $optionOps[] = 'COLLATE=' . $desiredCollation;
    }

    $ops = array_merge($indexOps['drop'], $columnOps, $indexOps['add'], $optionOps);
    if (empty($ops)) {
        return null;
    }

    return 'ALTER TABLE ' . mydump_quote_identifier($tableName) . ' ' . implode(', ', $ops);
}

function mydump_build_index_ops(array $currentIndexes, array $desiredIndexes): array
{
    $currentMap = [];
    foreach ($currentIndexes as $idx) {
        $currentMap[$idx['name']] = $idx;
    }

    $desiredMap = [];
    foreach ($desiredIndexes as $idx) {
        $desiredMap[$idx['name']] = $idx;
    }

    $dropOps = [];
    $addOps = [];

    foreach ($currentMap as $name => $current) {
        if (!isset($desiredMap[$name])) {
            $dropOps[] = mydump_drop_index_sql($name);
        }
    }

    foreach ($desiredMap as $name => $desired) {
        if (!isset($currentMap[$name])) {
            $add = mydump_index_definition_sql($desired, true);
            if ($add !== '') {
                $addOps[] = $add;
            }
            continue;
        }

        if (mydump_index_sign($currentMap[$name]) !== mydump_index_sign($desired)) {
            $dropOps[] = mydump_drop_index_sql($name);
            $add = mydump_index_definition_sql($desired, true);
            if ($add !== '') {
                $addOps[] = $add;
            }
        }
    }

    return ['drop' => $dropOps, 'add' => $addOps];
}

function mydump_drop_index_sql(string $indexName): string
{
    if ($indexName === 'PRIMARY') {
        return 'DROP PRIMARY KEY';
    }
    return 'DROP INDEX ' . mydump_quote_identifier($indexName);
}

function mydump_index_definition_sql(array $index, bool $withAddKeyword): string
{
    $name = (string) ($index['name'] ?? '');
    $columns = $index['columns'] ?? [];
    if ($name === '' || !is_array($columns) || empty($columns)) {
        return '';
    }

    $parts = [];
    foreach ($columns as $col) {
        $colName = (string) ($col['name'] ?? '');
        if ($colName === '') {
            continue;
        }
        $part = mydump_quote_identifier($colName);
        $length = $col['length'] ?? null;
        if ($length !== null && $length !== '') {
            $part .= '(' . (int) $length . ')';
        }
        $parts[] = $part;
    }

    if (empty($parts)) {
        return '';
    }

    $columnsSql = '(' . implode(', ', $parts) . ')';
    $type = strtoupper((string) ($index['type'] ?? 'BTREE'));
    $isUnique = (bool) ($index['unique'] ?? false);

    if ($name === 'PRIMARY') {
        return ($withAddKeyword ? 'ADD ' : '') . 'PRIMARY KEY ' . $columnsSql;
    }

    if ($type === 'FULLTEXT') {
        return ($withAddKeyword ? 'ADD ' : '')
            . 'FULLTEXT KEY ' . mydump_quote_identifier($name) . ' ' . $columnsSql;
    }

    if ($type === 'SPATIAL') {
        return ($withAddKeyword ? 'ADD ' : '')
            . 'SPATIAL KEY ' . mydump_quote_identifier($name) . ' ' . $columnsSql;
    }

    if ($isUnique) {
        return ($withAddKeyword ? 'ADD ' : '')
            . 'UNIQUE KEY ' . mydump_quote_identifier($name) . ' ' . $columnsSql;
    }

    return ($withAddKeyword ? 'ADD ' : '')
        . 'KEY ' . mydump_quote_identifier($name) . ' ' . $columnsSql;
}

function mydump_index_sign(array $index): string
{
    $columns = [];
    foreach (($index['columns'] ?? []) as $col) {
        $columns[] = strtolower((string) ($col['name'] ?? '')) . ':' . ((string) ($col['length'] ?? ''));
    }

    return implode('|', [
        strtoupper((string) ($index['name'] ?? '')),
        !empty($index['unique']) ? '1' : '0',
        strtoupper((string) ($index['type'] ?? 'BTREE')),
        implode(',', $columns),
    ]);
}

function mydump_column_definition_sql(array $field): string
{
    $name = (string) ($field['name'] ?? '');
    $type = trim((string) ($field['type'] ?? 'varchar(255)'));
    if ($name === '' || $type === '') {
        throw new RuntimeException('Invalid field definition while generating SQL.');
    }

    $sql = mydump_quote_identifier($name) . ' ' . $type;

    $generationExpr = trim((string) ($field['generation_expression'] ?? ''));
    $extra = trim((string) ($field['extra'] ?? ''));
    if ($generationExpr !== '') {
        $sql .= ' AS (' . $generationExpr . ')';
        if (stripos($extra, 'STORED') !== false) {
            $sql .= ' STORED';
        } else {
            $sql .= ' VIRTUAL';
        }

        $comment = (string) ($field['comment'] ?? '');
        if ($comment !== '') {
            $sql .= ' COMMENT ' . mydump_quote_string($comment);
        }

        return $sql;
    }

    $collation = trim((string) ($field['collation'] ?? ''));
    if ($collation !== '' && preg_match('/^[A-Za-z0-9_]+$/', $collation) === 1) {
        $sql .= ' COLLATE ' . $collation;
    }

    $nullable = !empty($field['nullable']);
    $sql .= $nullable ? ' NULL' : ' NOT NULL';

    $defaultKind = strtolower((string) ($field['default_kind'] ?? 'none'));
    $defaultValue = (string) ($field['default_value'] ?? '');
    if ($defaultKind === 'null') {
        $sql .= ' DEFAULT NULL';
    } elseif ($defaultKind === 'literal') {
        $sql .= ' DEFAULT ' . mydump_quote_string($defaultValue);
    } elseif ($defaultKind === 'expression') {
        $sql .= ' DEFAULT ' . $defaultValue;
    }

    $cleanExtra = trim((string) preg_replace('/\bDEFAULT_GENERATED\b/i', '', $extra));
    if ($cleanExtra !== '') {
        $sql .= ' ' . $cleanExtra;
    }

    $comment = (string) ($field['comment'] ?? '');
    if ($comment !== '') {
        $sql .= ' COMMENT ' . mydump_quote_string($comment);
    }

    return $sql;
}

function mydump_column_sign(array $field): string
{
    return implode('|', [
        strtolower(trim((string) ($field['name'] ?? ''))),
        strtolower(trim((string) ($field['type'] ?? ''))),
        !empty($field['nullable']) ? '1' : '0',
        strtolower((string) ($field['default_kind'] ?? 'none')),
        (string) ($field['default_value'] ?? ''),
        strtolower(trim((string) preg_replace('/\bDEFAULT_GENERATED\b/i', '', (string) ($field['extra'] ?? '')))),
        strtolower((string) ($field['collation'] ?? '')),
        (string) ($field['comment'] ?? ''),
        (string) ($field['generation_expression'] ?? ''),
    ]);
}

function mydump_prepare_view_create_sql(string $createSql, string $viewName): string
{
    $sql = trim($createSql);
    if ($sql === '') {
        return '';
    }

    $sql = rtrim($sql, ';');
    $sql = preg_replace('/\s+DEFINER=`[^`]+`@`[^`]+`/i', '', $sql) ?? $sql;

    if (preg_match('/^CREATE\s+OR\s+REPLACE\s+/i', $sql) !== 1) {
        $sql = preg_replace('/^CREATE\s+/i', 'CREATE OR REPLACE ', $sql, 1) ?? $sql;
    }

    if (preg_match('/^CREATE\s+/i', $sql) !== 1) {
        throw new RuntimeException("Invalid create_sql for view '{$viewName}'.");
    }

    return $sql;
}

function mydump_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function mydump_quote_string(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}

function mydump_parse_yes_no(string $value, bool $default): bool
{
    $v = strtolower(trim($value));
    if ($v === '') {
        return $default;
    }

    if (in_array($v, ['yes', 'y', '1', 'true', 'on', 'null'], true)) {
        return true;
    }
    if (in_array($v, ['no', 'n', '0', 'false', 'off'], true)) {
        return false;
    }

    return $default;
}

function mydump_row_is_empty(array $assoc): bool
{
    foreach ($assoc as $value) {
        if (trim((string) $value) !== '') {
            return false;
        }
    }
    return true;
}

function mydump_normalize_header(string $value): string
{
    $key = strtolower(trim($value));
    $key = str_replace([' ', '-', '.'], '_', $key);
    return preg_replace('/[^a-z0-9_]/', '', $key) ?? '';
}

function mydump_is_assoc(array $array): bool
{
    $keys = array_keys($array);
    return $keys !== array_keys($keys);
}
