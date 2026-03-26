<?php

declare(strict_types=1);

const MYDUMP_VERSION = '0.9';

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    mydump_main($argv);
}

/**
 * Coordinate the whole command-line tool: parse the shell arguments, decide
 * whether the user wants the read or write program, and route execution into
 * the chosen half of the file while keeping all fatal errors user-friendly.
 */
function mydump_main(array $argv): void
{
    try {
        $cli = mydump_parse_cli($argv);
        if ($cli['options']['version'] === true) {
            fwrite(STDOUT, 'mydump ' . MYDUMP_VERSION . PHP_EOL);
            return;
        }
        if ($cli['help'] === true) {
            mydump_print_usage();
            return;
        }

        $mode = $cli['mode'];
        if ($mode === null) {
            $modeInput = strtolower(mydump_prompt('Mode (read/write)', 'read', true));
            $mode = ($modeInput === 'write') ? 'write' : 'read';
        }

        if ($mode === 'read') {
            mydump_run_read_program($cli);
            return;
        }

        mydump_run_write_program($cli);
    } catch (Throwable $throwable) {
        fwrite(STDERR, 'Error: ' . $throwable->getMessage() . PHP_EOL);
        exit(1);
    }
}

/**
 * Parse the historical `mydump` shell syntax, preserve its old shortcuts and
 * defaults where they still make sense, and reduce everything to one compact
 * structure that both programs can consume without sharing any later logic.
 */
function mydump_parse_cli(array $argv): array
{
    $tokens = $argv;
    array_shift($tokens);

    $command = null;
    if (isset($tokens[0])) {
        $head = strtolower((string) $tokens[0]);
        if (in_array($head, ['read', 'write', 'dump'], true)) {
            $command = ($head === 'dump') ? 'read' : $head;
            array_shift($tokens);
        } elseif ($head === 'help') {
            $command = 'help';
            array_shift($tokens);
        }
    }

    $rawOptions = [];
    $positionals = [];
    $flagOptions = ['run', 'help', '?'];

    for ($index = 0, $count = count($tokens); $index < $count; $index++) {
        $token = (string) $tokens[$index];

        if ($token === '--') {
            for ($tailIndex = $index + 1; $tailIndex < $count; $tailIndex++) {
                $positionals[] = (string) $tokens[$tailIndex];
            }
            break;
        }

        if ($token === '' || $token === '-' || $token[0] !== '-') {
            $positionals[] = $token;
            continue;
        }

        $trimmed = ltrim($token, '-');
        if ($trimmed === '') {
            $positionals[] = $token;
            continue;
        }

        $name = strtolower($trimmed);
        $value = true;

        if (str_contains($trimmed, '=')) {
            [$nameRaw, $valueRaw] = explode('=', $trimmed, 2);
            $name = strtolower($nameRaw);
            $value = (string) $valueRaw;
        } elseif (!in_array($name, $flagOptions, true)) {
            $next = $tokens[$index + 1] ?? null;
            if ($next !== null && (string) $next !== '--' && !str_starts_with((string) $next, '-')) {
                $value = (string) $next;
                $index++;
            } else {
                $value = null;
            }
        }

        if (!isset($rawOptions[$name])) {
            $rawOptions[$name] = [];
        }
        $rawOptions[$name][] = $value;
    }

    $options = [
        'host' => mydump_option_value($rawOptions, ['host']),
        'port' => mydump_option_value($rawOptions, ['port']),
        'user' => mydump_option_value($rawOptions, ['user']),
        'pass' => mydump_option_value($rawOptions, ['pass', 'password']),
        'pass_provided' => mydump_option_present($rawOptions, ['pass', 'password']),
        'db' => mydump_option_value($rawOptions, ['db', 'database']),
        'input' => mydump_option_value($rawOptions, ['i', 'input']),
        'output' => mydump_option_value($rawOptions, ['o', 'output']),
        'outputs' => mydump_option_values($rawOptions, ['o', 'output']),
        'run' => mydump_option_flag($rawOptions, ['run']),
        'version' => mydump_option_flag($rawOptions, ['version', 'v']),
        'help' => $command === 'help' || mydump_option_flag($rawOptions, ['help', '?']),
        'positionals' => $positionals,
    ];

    $mode = null;
    if ($command === 'read' || $command === 'write') {
        $mode = $command;
    }

    if ($mode === null && $options['help'] !== true) {
        if (!empty($options['outputs'])) {
            $mode = 'read';
        } elseif (!empty($options['input'])) {
            $mode = 'write';
        } elseif (mydump_find_tsv_positional($positionals) !== null) {
            $mode = 'write';
        }
    }

    $inputPath = null;
    if ($mode === 'write') {
        $inputPath = $options['input'] ?: mydump_find_tsv_positional($positionals);
    }

    $outputPath = null;
    if ($mode === 'read') {
        $outputCandidates = $options['outputs'];
        if ($command === 'read') {
            foreach (mydump_find_tsv_positionals($positionals) as $candidate) {
                $outputCandidates[] = $candidate;
            }
        }

        $outputCandidates = mydump_unique_values($outputCandidates);
        if (!empty($outputCandidates)) {
            $outputPath = $outputCandidates[count($outputCandidates) - 1];
        } elseif (!empty($options['output'])) {
            $outputPath = $options['output'];
        }
    }

    return [
        'mode' => $mode,
        'command' => $command,
        'version' => $options['version'],
        'help' => $options['help'],
        'options' => $options,
        'input' => $inputPath,
        'output' => $outputPath,
    ];
}

/**
 * Print the user-facing help text for the new TSV-only tool, documenting the
 * canonical `read`/`write` flow while also calling out the compatibility alias
 * and shortcuts that were inherited from the older version of `mydump`.
 */
function mydump_print_usage(): void
{
    $usage = <<<TXT
mydump 0.9
read:  php mydump.php read -host H -port P -user U -pass PW -db DB -o out.tsv
write: php mydump.php write in.tsv -host H -port P -user U -pass PW -db DB
conn:  shell args only, no local config include
help:  php mydump.php --help
ver:   php mydump.php --version
TXT;

    fwrite(STDOUT, $usage . PHP_EOL);
}

/**
 * Prompt the user for a normal text value when the historical CLI contract
 * expects interactive fallback, including optional defaults and retrying when
 * the field is required and the user submits an empty answer.
 */
function mydump_prompt(string $label, ?string $default = null, bool $required = true): string
{
    while (true) {
        $suffix = ($default !== null && $default !== '') ? " [{$default}]" : '';
        $value = mydump_readline($label . $suffix . ': ');
        if ($value === '' && $default !== null) {
            return $default;
        }
        if ($required === false || $value !== '') {
            return $value;
        }
    }
}

/**
 * Ask a yes/no question using the old command-line style, interpret blank
 * answers according to the requested default, and return a strict boolean that
 * later code can use without any additional string parsing.
 */
function mydump_confirm(string $question, bool $defaultNo = true): bool
{
    $suffix = $defaultNo ? ' [y/N]: ' : ' [Y/n]: ';
    $value = strtolower(trim(mydump_readline($question . $suffix)));

    if ($value === '') {
        return !$defaultNo;
    }

    return in_array($value, ['y', 'yes'], true);
}

/**
 * Read a single line from the terminal, preferring PHP's readline support when
 * it is available and falling back to standard input so the script keeps
 * working in plain shells and minimal PHP installations.
 */
function mydump_readline(string $prompt): string
{
    if (function_exists('readline')) {
        $line = readline($prompt);
        if ($line === false) {
            return '';
        }
        return trim($line);
    }

    fwrite(STDOUT, $prompt);
    $line = fgets(STDIN);
    if ($line === false) {
        return '';
    }

    return trim($line);
}

/**
 * Resolve boolean flags from the raw option bag, accepting both bare flags and
 * the common truthy textual forms so the parser remains forgiving in the same
 * spirit as the older version of the tool.
 */
function mydump_option_flag(array $options, array $aliases): bool
{
    foreach ($aliases as $alias) {
        $key = strtolower($alias);
        if (!isset($options[$key])) {
            continue;
        }

        foreach ((array) $options[$key] as $value) {
            if (is_bool($value)) {
                if ($value === true) {
                    return true;
                }
                continue;
            }

            $text = strtolower((string) $value);
            if ($text === '' || in_array($text, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Resolve the last non-empty scalar value for an option alias set, which keeps
 * the parser predictable when the same option is supplied multiple times and
 * matches the "last one wins" convention used by many CLI tools.
 */
function mydump_option_value(array $options, array $aliases): ?string
{
    $values = mydump_option_values($options, $aliases);
    if (empty($values)) {
        return null;
    }

    return $values[count($values) - 1];
}

/**
 * Collect all non-empty option values for one alias set, also splitting simple
 * comma-separated lists so the `-o a.tsv,b.tsv` style inherited from the older
 * code path still behaves sensibly even though the new format uses one file.
 */
function mydump_option_values(array $options, array $aliases): array
{
    $values = [];

    foreach ($aliases as $alias) {
        $key = strtolower($alias);
        if (!isset($options[$key])) {
            continue;
        }

        foreach ((array) $options[$key] as $value) {
            if ($value === true || $value === null) {
                continue;
            }

            $text = trim((string) $value);
            if ($text === '') {
                continue;
            }

            foreach (array_map('trim', explode(',', $text)) as $part) {
                if ($part !== '') {
                    $values[] = $part;
                }
            }
        }
    }

    return $values;
}

/**
 * Detect whether any alias in one option family was provided at all, even when
 * the user intentionally supplied an empty value such as `-pass=` for a blank
 * password that still needs to count as an explicit shell argument.
 */
function mydump_option_present(array $options, array $aliases): bool
{
    foreach ($aliases as $alias) {
        if (array_key_exists(strtolower($alias), $options)) {
            return true;
        }
    }

    return false;
}

/**
 * Return the first positional argument that looks like a TSV path so the write
 * shortcut `php mydump.php schema.tsv` continues to work without needing an
 * explicit `write` command or `--input` flag.
 */
function mydump_find_tsv_positional(array $positionals): ?string
{
    $values = mydump_find_tsv_positionals($positionals);
    return $values[0] ?? null;
}

/**
 * Return every positional argument that ends in `.tsv`, which lets the parser
 * reuse the same simple file-detection rule for both the write shortcut and
 * the explicit read-mode positional output convenience.
 */
function mydump_find_tsv_positionals(array $positionals): array
{
    $matches = [];

    foreach ($positionals as $value) {
        $candidate = trim((string) $value);
        if ($candidate === '') {
            continue;
        }

        $extension = strtolower((string) pathinfo($candidate, PATHINFO_EXTENSION));
        if ($extension === 'tsv') {
            $matches[] = $candidate;
        }
    }

    return mydump_unique_values($matches);
}

/**
 * Deduplicate a list of textual values case-insensitively while preserving the
 * original order, which is useful for shell arguments where later processing
 * still wants stable and human-intuitive ordering.
 */
function mydump_unique_values(array $values): array
{
    $seen = [];
    $result = [];

    foreach ($values as $value) {
        $text = trim((string) $value);
        if ($text === '') {
            continue;
        }

        $key = strtolower($text);
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $result[] = $text;
    }

    return $result;
}

// ---------------------------------------------------------------------------
// mydump-read
// ---------------------------------------------------------------------------

/**
 * Execute the read half of the tool: connect to MySQL, inspect the current
 * schema, flatten it into the new TSV row model, emit warnings for every table
 * feature the TSV cannot preserve, and finally write the output file.
 */
function mydump_run_read_program(array $cli): void
{
    $outputPath = mydump_read_resolve_output_path($cli);
    $connection = mydump_read_resolve_connection($cli['options']);
    $pdo = mydump_read_connect_server($connection, false);

    if (!mydump_read_database_exists($pdo, $connection['db'])) {
        throw new RuntimeException("Database '{$connection['db']}' does not exist.");
    }

    $pdo->exec('USE ' . mydump_read_quote_identifier($connection['db']));

    $objects = mydump_read_fetch_objects($pdo, $connection['db']);
    $rows = [];
    $warnings = [];

    foreach ($objects as $object) {
        $columnRows = mydump_read_fetch_object_rows($pdo, $connection['db'], $object);
        foreach ($columnRows as $row) {
            $rows[] = $row;
        }

        $objectWarnings = mydump_read_collect_object_warnings($pdo, $connection['db'], $object);
        foreach ($objectWarnings as $warning) {
            $warnings[] = $warning;
        }
    }

    mydump_read_write_tsv($outputPath, $rows);
    mydump_read_emit_warnings($warnings);

    fwrite(STDOUT, 'Read complete: ' . $outputPath . PHP_EOL);
    if (!empty($warnings)) {
        fwrite(STDOUT, 'Warnings: ' . count($warnings) . PHP_EOL);
    }
}

/**
 * Resolve and validate the TSV output path for the read program, preserving
 * the old interactive fallback and explicitly rejecting multiple outputs now
 * that the new format intentionally produces exactly one flattened file.
 */
function mydump_read_resolve_output_path(array $cli): string
{
    $outputs = mydump_read_unique_values((array) ($cli['options']['outputs'] ?? []));
    if (count($outputs) > 1) {
        throw new RuntimeException('Read mode now supports exactly one TSV output file.');
    }

    $outputPath = trim((string) ($cli['output'] ?? ''));
    if ($outputPath === '') {
        $outputPath = mydump_prompt('Output file (.tsv)', null, true);
    }

    if (strtolower((string) pathinfo($outputPath, PATHINFO_EXTENSION)) !== 'tsv') {
        throw new RuntimeException('Read mode output must end in .tsv');
    }

    $directory = dirname($outputPath);
    if (!is_dir($directory)) {
        throw new RuntimeException("Directory does not exist: {$directory}");
    }

    return $outputPath;
}

/**
 * Resolve the read-side MySQL connection strictly from explicit shell args,
 * because the tool is now intentionally literal single-file only and no longer
 * reads local PHP config files or connection constants from elsewhere.
 */
function mydump_read_resolve_connection(array $options): array
{
    $host = mydump_read_require_shell_connection_value('-host', $options['host'] ?? null);
    $portText = mydump_read_require_shell_connection_value('-port', $options['port'] ?? null);
    $user = mydump_read_require_shell_connection_value('-user', $options['user'] ?? null);
    $database = mydump_read_require_shell_connection_value('-db', $options['db'] ?? null);

    $password = $options['pass'] ?? null;
    $passwordProvided = (bool) ($options['pass_provided'] ?? false);
    if (!$passwordProvided) {
        throw new RuntimeException('Missing required shell argument: -pass');
    }

    if (!ctype_digit($portText) || (int) $portText < 1 || (int) $portText > 65535) {
        throw new RuntimeException('Shell argument -port must be an integer between 1 and 65535.');
    }

    return [
        'host' => $host,
        'port' => (int) $portText,
        'user' => $user,
        'pass' => (string) ($password ?? ''),
        'db' => $database,
    ];
}

/**
 * Require one non-empty shell-supplied connection value for the read program
 * and fail fast with a precise flag name when a mandatory argument is missing.
 */
function mydump_read_require_shell_connection_value(string $flagName, ?string $value): string
{
    $text = trim((string) $value);
    if ($text === '') {
        throw new RuntimeException("Missing required shell argument: {$flagName}");
    }

    return $text;
}

/**
 * Create a PDO connection for the read program using UTF-8 metadata access and
 * exception-based error handling, optionally attaching a database name when a
 * specific query path requires it.
 */
function mydump_read_connect_server(array $connection, bool $withDatabase): PDO
{
    $dsn = 'mysql:host=' . $connection['host'] . ';port=' . $connection['port'] . ';charset=utf8mb4';
    if ($withDatabase) {
        $dsn .= ';dbname=' . $connection['db'];
    }

    return new PDO(
        $dsn,
        $connection['user'],
        $connection['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

/**
 * Check that the target database exists before the read program attempts any
 * deeper schema inspection, so later metadata queries can assume the schema is
 * real and return clearer messages when credentials are otherwise valid.
 */
function mydump_read_database_exists(PDO $pdo, string $databaseName): bool
{
    $statement = $pdo->prepare(
        'SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :database LIMIT 1'
    );
    $statement->execute(['database' => $databaseName]);

    return (bool) $statement->fetchColumn();
}

/**
 * Fetch the list of tables and views that will become top-level TSV groups,
 * keeping only the metadata the flattened format still needs directly and the
 * extra table attributes that warning generation must inspect later.
 */
function mydump_read_fetch_objects(PDO $pdo, string $databaseName): array
{
    $statement = $pdo->prepare(
        'SELECT
            TABLE_NAME,
            TABLE_TYPE,
            ENGINE,
            TABLE_COLLATION,
            TABLE_COMMENT
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = :database
         ORDER BY TABLE_NAME'
    );
    $statement->execute(['database' => $databaseName]);

    $objects = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $objects[] = [
            'name' => (string) ($row['TABLE_NAME'] ?? ''),
            'tv' => strtoupper((string) ($row['TABLE_TYPE'] ?? '')) === 'VIEW' ? 'V' : 'T',
            'engine' => (string) ($row['ENGINE'] ?? ''),
            'table_collation' => (string) ($row['TABLE_COLLATION'] ?? ''),
            'table_comment' => (string) ($row['TABLE_COMMENT'] ?? ''),
        ];
    }

    return $objects;
}

/**
 * Build the flattened TSV rows for one table or view by reading ordered column
 * metadata, deriving the new field-level properties from the schema, and
 * repeating the table-level values on every row exactly as requested.
 */
function mydump_read_fetch_object_rows(PDO $pdo, string $databaseName, array $object): array
{
    $columns = mydump_read_fetch_columns($pdo, $databaseName, (string) $object['name']);
    $indexMap = ($object['tv'] === 'T')
        ? mydump_read_build_expressible_index_map($pdo, (string) $object['name'])
        : [];

    $rows = [];
    foreach ($columns as $column) {
        $rows[] = mydump_read_build_row($object, $column, $indexMap);
    }

    return $rows;
}

/**
 * Fetch ordered column metadata for one object using information schema, which
 * gives the read program everything it needs to derive the new TSV columns
 * without having to parse raw `SHOW CREATE TABLE` SQL.
 */
function mydump_read_fetch_columns(PDO $pdo, string $databaseName, string $tableName): array
{
    $statement = $pdo->prepare(
        'SELECT
            COLUMN_NAME,
            DATA_TYPE,
            COLUMN_TYPE,
            CHARACTER_MAXIMUM_LENGTH,
            IS_NULLABLE,
            COLUMN_DEFAULT,
            EXTRA,
            COLLATION_NAME,
            COLUMN_COMMENT,
            ORDINAL_POSITION,
            GENERATION_EXPRESSION
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :database
           AND TABLE_NAME = :table
         ORDER BY ORDINAL_POSITION'
    );
    $statement->execute([
        'database' => $databaseName,
        'table' => $tableName,
    ]);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Convert one column plus the already-indexed table metadata into the exact
 * TSV row layout, including the requested repeated table values and the new
 * compact encoding for type, properties, index marker, and defaults.
 */
function mydump_read_build_row(array $object, array $column, array $indexMap): array
{
    $columnName = (string) ($column['COLUMN_NAME'] ?? '');
    $indexMeta = $indexMap[$columnName] ?? ['properties' => [], 'index' => ''];

    return [
        'tv' => (string) $object['tv'],
        'table' => (string) $object['name'],
        'eng' => mydump_read_resolve_engine_code($object),
        'name' => $columnName,
        'type' => mydump_read_encode_type_token($column),
        'length' => mydump_read_encode_length($column),
        'properties' => mydump_read_encode_properties($column, $indexMeta),
        'index' => mydump_read_encode_index_cell($indexMeta),
        'collation' => (string) ($column['COLLATION_NAME'] ?? ''),
        'default' => mydump_read_encode_default_cell($column),
        'comment' => (string) ($column['COLUMN_COMMENT'] ?? ''),
    ];
}

/**
 * Map MySQL engines to the compact `eng` codes required by the TSV, leaving
 * views blank and warning separately when a table uses an engine outside the
 * explicit `IDB` / `MEM` vocabulary requested by the new format.
 */
function mydump_read_resolve_engine_code(array $object): string
{
    if ((string) ($object['tv'] ?? 'T') === 'V') {
        return '';
    }

    $engine = strtoupper(trim((string) ($object['engine'] ?? '')));
    if ($engine === 'INNODB') {
        return 'IDB';
    }
    if ($engine === 'MEMORY' || $engine === 'HEAP') {
        return 'MEM';
    }

    return '';
}

/**
 * Encode the TSV `type` token by removing the MySQL `unsigned` keyword from
 * the source type and replacing it with the requested `u.` prefix while also
 * splitting simple string lengths into the dedicated `length` column.
 */
function mydump_read_encode_type_token(array $column): string
{
    $dataType = strtolower(trim((string) ($column['DATA_TYPE'] ?? '')));
    $columnType = trim((string) ($column['COLUMN_TYPE'] ?? ''));
    $isUnsigned = preg_match('/\bunsigned\b/i', $columnType) === 1;
    $typeCore = mydump_read_strip_unsigned_keyword($columnType);

    if (mydump_read_is_length_driven_type($dataType)) {
        $typeCore = $dataType;
    } elseif ($dataType !== '' && mydump_read_is_simple_type_without_length($dataType)) {
        $typeCore = $dataType;
    } elseif ($typeCore === '') {
        $typeCore = $dataType;
    }

    if ($isUnsigned && mydump_read_is_unsigned_capable_type($dataType)) {
        return 'u.' . $typeCore;
    }

    return $typeCore;
}

/**
 * Encode the TSV `length` column only for the string and binary types where
 * the requested flat format stores size outside the type token, leaving every
 * other kind of column blank to avoid inventing unsupported semantics.
 */
function mydump_read_encode_length(array $column): string
{
    $dataType = strtolower(trim((string) ($column['DATA_TYPE'] ?? '')));
    if (!mydump_read_is_length_driven_type($dataType)) {
        return '';
    }

    $length = $column['CHARACTER_MAXIMUM_LENGTH'] ?? null;
    if ($length === null || $length === '') {
        return '';
    }

    return (string) (int) $length;
}

/**
 * Build the aggregated `properties` cell from both column metadata and the
 * single-column index map so PK, NN, UK, and AI end up in a stable, compact,
 * comma-separated order on every TSV row.
 */
function mydump_read_encode_properties(array $column, array $indexMeta): string
{
    $properties = [];

    if (($indexMeta['properties']['PK'] ?? false) === true) {
        $properties[] = 'PK';
    }

    $isNotNull = strtoupper((string) ($column['IS_NULLABLE'] ?? 'YES')) !== 'YES';
    if ($isNotNull) {
        $properties[] = 'NN';
    }

    if (($indexMeta['properties']['UK'] ?? false) === true) {
        $properties[] = 'UK';
    }

    $extra = strtolower((string) ($column['EXTRA'] ?? ''));
    if (str_contains($extra, 'auto_increment')) {
        $properties[] = 'AI';
    }

    return implode(',', $properties);
}

/**
 * Convert the normalized index metadata into the requested TSV marker: blank
 * when there is no ordinary non-unique index, `Y` for BTREE-like indexes, and
 * `HA` specifically for the single-column HASH case.
 */
function mydump_read_encode_index_cell(array $indexMeta): string
{
    return (string) ($indexMeta['index'] ?? '');
}

/**
 * Convert MySQL defaults into an editable TSV form that aims to be concise for
 * humans while still round-tripping through the write program: blank for no
 * default, `NULL` for nullable-null defaults, and raw literals or expressions
 * for everything else.
 */
function mydump_read_encode_default_cell(array $column): string
{
    $defaultValue = $column['COLUMN_DEFAULT'] ?? null;
    $dataType = strtolower(trim((string) ($column['DATA_TYPE'] ?? '')));
    $isNullable = strtoupper((string) ($column['IS_NULLABLE'] ?? 'YES')) === 'YES';
    $extra = (string) ($column['EXTRA'] ?? '');

    if ($defaultValue === null) {
        return $isNullable ? 'NULL' : '';
    }

    $defaultText = (string) $defaultValue;
    if (mydump_read_is_default_expression($defaultText, $extra)) {
        return $defaultText;
    }

    if ($defaultText === '') {
        return "''";
    }

    if (mydump_read_is_string_like_type($dataType)) {
        return $defaultText;
    }

    if (mydump_read_is_temporal_type($dataType)) {
        return $defaultText;
    }

    if (mydump_read_is_unsigned_capable_type($dataType) && is_numeric($defaultText)) {
        return $defaultText;
    }

    return $defaultText;
}

/**
 * Detect default expressions that must be preserved as SQL fragments instead
 * of quoted literals, covering the common server-generated expressions MySQL
 * exposes through information schema and the `DEFAULT_GENERATED` extra marker.
 */
function mydump_read_is_default_expression(string $defaultValue, string $extra): bool
{
    if (stripos($extra, 'DEFAULT_GENERATED') !== false) {
        return true;
    }

    return preg_match(
        '/^(CURRENT_TIMESTAMP(?:\(\d+\))?|CURRENT_DATE(?:\(\))?|CURRENT_TIME(?:\(\))?|NOW\(\)|UUID\(\)|\(.+\))$/i',
        trim($defaultValue)
    ) === 1;
}

/**
 * Remove the literal MySQL `unsigned` keyword from a full column type while
 * leaving every other modifier intact, because the new TSV format wants that
 * single concept represented only through the `u.` prefix.
 */
function mydump_read_strip_unsigned_keyword(string $columnType): string
{
    $stripped = preg_replace('/\s+unsigned\b/i', '', $columnType);
    if ($stripped === null) {
        return trim($columnType);
    }

    return trim(preg_replace('/\s+/', ' ', $stripped) ?? $stripped);
}

/**
 * Identify the types whose varying size belongs in the separate TSV `length`
 * column rather than inside the `type` token, namely the string and binary
 * families where the user explicitly asked for this split representation.
 */
function mydump_read_is_length_driven_type(string $dataType): bool
{
    return in_array($dataType, ['char', 'varchar', 'binary', 'varbinary'], true);
}

/**
 * Identify types that are already complete as bare names in the TSV `type`
 * column and therefore do not need to preserve any parenthesized detail from
 * MySQL's full `COLUMN_TYPE` representation.
 */
function mydump_read_is_simple_type_without_length(string $dataType): bool
{
    return in_array(
        $dataType,
        [
            'tinyint', 'smallint', 'mediumint', 'int', 'bigint',
            'date', 'datetime', 'timestamp', 'time', 'year',
            'json', 'tinytext', 'text', 'mediumtext', 'longtext',
            'tinyblob', 'blob', 'mediumblob', 'longblob',
            'geometry', 'point', 'linestring', 'polygon',
            'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection',
        ],
        true
    );
}

/**
 * Identify types that meaningfully support MySQL's `unsigned` modifier so the
 * read program can decide whether a detected unsigned column should gain the
 * `u.` prefix or simply leave the type text untouched.
 */
function mydump_read_is_unsigned_capable_type(string $dataType): bool
{
    return in_array(
        $dataType,
        ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'numeric', 'float', 'double', 'real'],
        true
    );
}

/**
 * Identify types whose defaults should be treated as textual literals by
 * default when dumping, because unquoted human-readable values are friendlier
 * in TSV and the write program can safely re-quote them later.
 */
function mydump_read_is_string_like_type(string $dataType): bool
{
    return in_array(
        $dataType,
        [
            'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext',
            'enum', 'set',
        ],
        true
    );
}

/**
 * Identify temporal types so the read program can preserve temporal defaults
 * in a readable plain-text form, leaving the write program to decide whether a
 * given value should be quoted or treated as a recognized SQL expression.
 */
function mydump_read_is_temporal_type(string $dataType): bool
{
    return in_array($dataType, ['date', 'datetime', 'timestamp', 'time', 'year'], true);
}

/**
 * Build a field-name lookup of the single-column index features that the TSV
 * can actually encode, while intentionally ignoring unsupported index shapes
 * that are instead surfaced through explicit warnings for the owning table.
 */
function mydump_read_build_expressible_index_map(PDO $pdo, string $tableName): array
{
    $rows = mydump_read_fetch_index_rows($pdo, $tableName);
    $grouped = [];

    foreach ($rows as $row) {
        $name = (string) ($row['Key_name'] ?? '');
        if ($name === '') {
            continue;
        }

        if (!isset($grouped[$name])) {
            $grouped[$name] = [];
        }
        $grouped[$name][] = $row;
    }

    $indexMap = [];
    foreach ($grouped as $name => $group) {
        if (count($group) !== 1) {
            continue;
        }

        $row = $group[0];
        $columnName = (string) ($row['Column_name'] ?? '');
        if ($columnName === '') {
            continue;
        }

        $indexType = strtoupper((string) ($row['Index_type'] ?? 'BTREE'));
        if (!in_array($indexType, ['BTREE', 'HASH'], true)) {
            continue;
        }
        if (($row['Sub_part'] ?? null) !== null) {
            continue;
        }

        if (!isset($indexMap[$columnName])) {
            $indexMap[$columnName] = [
                'properties' => ['PK' => false, 'UK' => false],
                'index' => '',
            ];
        }

        if ($name === 'PRIMARY') {
            $indexMap[$columnName]['properties']['PK'] = true;
            continue;
        }

        $isUnique = ((int) ($row['Non_unique'] ?? 1)) === 0;
        if ($isUnique) {
            $indexMap[$columnName]['properties']['UK'] = true;
            continue;
        }

        $indexMap[$columnName]['index'] = ($indexType === 'HASH') ? 'HA' : 'Y';
    }

    return $indexMap;
}

/**
 * Fetch raw index rows using `SHOW INDEX` because it exposes the practical
 * details we need both for expressing supported single-column indexes and for
 * warning about every unsupported variant that the TSV would lose.
 */
function mydump_read_fetch_index_rows(PDO $pdo, string $tableName): array
{
    $sql = 'SHOW INDEX FROM ' . mydump_read_quote_identifier($tableName);
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Collect every warning that should accompany one table or view dump, keeping
 * the read output honest whenever the schema contains information the flat TSV
 * cannot fully store and therefore cannot reliably round-trip later.
 */
function mydump_read_collect_object_warnings(PDO $pdo, string $databaseName, array $object): array
{
    $warnings = [];
    $tableName = (string) ($object['name'] ?? '');

    foreach (mydump_read_collect_view_warnings($object) as $warning) {
        $warnings[] = $warning;
    }
    foreach (mydump_read_collect_engine_warnings($object) as $warning) {
        $warnings[] = $warning;
    }
    foreach (mydump_read_collect_table_comment_warnings($object) as $warning) {
        $warnings[] = $warning;
    }
    foreach (mydump_read_collect_index_warnings($pdo, $tableName, $object) as $warning) {
        $warnings[] = $warning;
    }
    foreach (mydump_read_collect_foreign_key_warnings($pdo, $databaseName, $tableName) as $warning) {
        $warnings[] = $warning;
    }
    foreach (mydump_read_collect_check_warnings($pdo, $databaseName, $tableName) as $warning) {
        $warnings[] = $warning;
    }
    foreach (mydump_read_collect_generated_column_warnings($pdo, $databaseName, $tableName) as $warning) {
        $warnings[] = $warning;
    }

    return mydump_read_unique_values($warnings);
}

/**
 * Warn whenever the current object is a view, because the TSV can list its
 * fields but cannot preserve the SELECT definition required to recreate that
 * view during the write phase.
 */
function mydump_read_collect_view_warnings(array $object): array
{
    if ((string) ($object['tv'] ?? 'T') !== 'V') {
        return [];
    }

    $tableName = (string) ($object['name'] ?? '');
    return [
        "Warning [{$tableName}]: view definition cannot be represented in TSV; write mode will not be able to recreate this view.",
    ];
}

/**
 * Warn when a table engine falls outside the compact `IDB` / `MEM` vocabulary,
 * because the TSV file must either blank that value or normalize it in a way
 * that cannot guarantee an exact round-trip later.
 */
function mydump_read_collect_engine_warnings(array $object): array
{
    if ((string) ($object['tv'] ?? 'T') !== 'T') {
        return [];
    }

    $engine = strtoupper(trim((string) ($object['engine'] ?? '')));
    if ($engine === '' || in_array($engine, ['INNODB', 'MEMORY', 'HEAP'], true)) {
        return [];
    }

    $tableName = (string) ($object['name'] ?? '');
    return [
        "Warning [{$tableName}]: engine '{$engine}' cannot be fully represented in TSV; only IDB and MEM are supported.",
    ];
}

/**
 * Warn when a table comment exists, because the new flat TSV has no dedicated
 * place to store table-level comments and therefore that metadata would be
 * silently lost without an explicit warning.
 */
function mydump_read_collect_table_comment_warnings(array $object): array
{
    if ((string) ($object['tv'] ?? 'T') !== 'T') {
        return [];
    }

    $comment = trim((string) ($object['table_comment'] ?? ''));
    if ($comment === '') {
        return [];
    }

    $tableName = (string) ($object['name'] ?? '');
    return [
        "Warning [{$tableName}]: table comment is not represented in TSV output.",
    ];
}

/**
 * Warn about every index that does not fit the deliberately tiny TSV index
 * model: anything multi-column, partial, descending, invisible, commented, or
 * using an unsupported index family must be called out explicitly.
 */
function mydump_read_collect_index_warnings(PDO $pdo, string $tableName, array $object): array
{
    if ((string) ($object['tv'] ?? 'T') !== 'T') {
        return [];
    }

    $rows = mydump_read_fetch_index_rows($pdo, $tableName);
    $grouped = [];
    foreach ($rows as $row) {
        $name = (string) ($row['Key_name'] ?? '');
        if ($name === '') {
            continue;
        }
        if (!isset($grouped[$name])) {
            $grouped[$name] = [];
        }
        $grouped[$name][] = $row;
    }

    $warnings = [];
    foreach ($grouped as $indexName => $group) {
        $first = $group[0];
        $indexType = strtoupper((string) ($first['Index_type'] ?? 'BTREE'));
        $hasMultipleColumns = count($group) !== 1;
        $hasPrefix = false;
        $hasDescending = false;
        $hasComments = false;
        $hasUnsupportedType = !in_array($indexType, ['BTREE', 'HASH'], true);
        $hasUnrepresentableHash = $indexType === 'HASH'
            && (
                $indexName === 'PRIMARY'
                || ((int) ($first['Non_unique'] ?? 1)) === 0
            );

        foreach ($group as $row) {
            if (($row['Sub_part'] ?? null) !== null) {
                $hasPrefix = true;
            }

            $collation = strtoupper((string) ($row['Collation'] ?? 'A'));
            if ($collation !== '' && $collation !== 'A') {
                $hasDescending = true;
            }

            $comment = trim((string) ($row['Comment'] ?? ''));
            $indexComment = trim((string) ($row['Index_comment'] ?? ''));
            if ($comment !== '' || $indexComment !== '') {
                $hasComments = true;
            }
        }

        if (
            !$hasMultipleColumns
            && !$hasPrefix
            && !$hasDescending
            && !$hasComments
            && !$hasUnsupportedType
            && !$hasUnrepresentableHash
        ) {
            continue;
        }

        $reasons = [];
        if ($hasMultipleColumns) {
            $reasons[] = 'multi-column';
        }
        if ($hasPrefix) {
            $reasons[] = 'prefix length';
        }
        if ($hasDescending) {
            $reasons[] = 'descending order';
        }
        if ($hasComments) {
            $reasons[] = 'comments';
        }
        if ($hasUnsupportedType) {
            $reasons[] = 'type ' . $indexType;
        }
        if ($hasUnrepresentableHash) {
            $reasons[] = 'HASH algorithm on primary/unique index';
        }

        $warnings[] = "Warning [{$tableName}]: index '{$indexName}' is not fully representable in TSV (" . implode(', ', $reasons) . ').';
    }

    return $warnings;
}

/**
 * Warn when a table owns foreign keys, because the TSV field list has no place
 * to preserve the relationship metadata and importing it back would therefore
 * risk silently losing referential constraints.
 */
function mydump_read_collect_foreign_key_warnings(PDO $pdo, string $databaseName, string $tableName): array
{
    $statement = $pdo->prepare(
        'SELECT DISTINCT CONSTRAINT_NAME
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = :database
           AND TABLE_NAME = :table
           AND REFERENCED_TABLE_NAME IS NOT NULL
         ORDER BY CONSTRAINT_NAME'
    );
    $statement->execute([
        'database' => $databaseName,
        'table' => $tableName,
    ]);

    $warnings = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $constraintName = (string) ($row['CONSTRAINT_NAME'] ?? '');
        if ($constraintName === '') {
            continue;
        }

        $warnings[] = "Warning [{$tableName}]: foreign key '{$constraintName}' is not represented in TSV output.";
    }

    return $warnings;
}

/**
 * Warn when a table contains check constraints, because the flat TSV format is
 * intentionally column-oriented and does not include any table-level mechanism
 * for preserving those boolean validation expressions.
 */
function mydump_read_collect_check_warnings(PDO $pdo, string $databaseName, string $tableName): array
{
    $statement = $pdo->prepare(
        'SELECT DISTINCT CONSTRAINT_NAME
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = :database
           AND TABLE_NAME = :table
           AND CONSTRAINT_TYPE = \'CHECK\'
         ORDER BY CONSTRAINT_NAME'
    );
    $statement->execute([
        'database' => $databaseName,
        'table' => $tableName,
    ]);

    $warnings = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $constraintName = (string) ($row['CONSTRAINT_NAME'] ?? '');
        if ($constraintName === '') {
            continue;
        }

        $warnings[] = "Warning [{$tableName}]: check constraint '{$constraintName}' is not represented in TSV output.";
    }

    return $warnings;
}

/**
 * Warn when generated columns exist, because the TSV format only stores plain
 * column definitions and therefore cannot preserve the generation expression or
 * whether the column is virtual versus stored.
 */
function mydump_read_collect_generated_column_warnings(PDO $pdo, string $databaseName, string $tableName): array
{
    $statement = $pdo->prepare(
        'SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :database
           AND TABLE_NAME = :table
           AND GENERATION_EXPRESSION IS NOT NULL
           AND GENERATION_EXPRESSION <> \'\'
         ORDER BY ORDINAL_POSITION'
    );
    $statement->execute([
        'database' => $databaseName,
        'table' => $tableName,
    ]);

    $warnings = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $columnName = (string) ($row['COLUMN_NAME'] ?? '');
        if ($columnName === '') {
            continue;
        }

        $warnings[] = "Warning [{$tableName}]: generated column '{$columnName}' cannot be fully represented in TSV output.";
    }

    return $warnings;
}

/**
 * Write the final flattened data set as a proper TSV file with one header row
 * and one row per field, relying on PHP's CSV writer with a tab delimiter so
 * quoting stays correct even when comments contain tabs or line breaks.
 */
function mydump_read_write_tsv(string $path, array $rows): void
{
    $handle = fopen($path, 'wb');
    if ($handle === false) {
        throw new RuntimeException("Unable to open output file: {$path}");
    }

    try {
        fputcsv($handle, mydump_read_tsv_headers(), "\t");
        foreach ($rows as $row) {
            $ordered = [];
            foreach (mydump_read_tsv_headers() as $header) {
                $ordered[] = (string) ($row[$header] ?? '');
            }
            fputcsv($handle, $ordered, "\t");
        }
    } finally {
        fclose($handle);
    }
}

/**
 * Return the canonical TSV header order once so every read-side writer call
 * stays consistent and the write program later knows exactly which columns to
 * expect when it validates incoming files.
 */
function mydump_read_tsv_headers(): array
{
    return ['tv', 'table', 'eng', 'name', 'type', 'length', 'properties', 'index', 'collation', 'default', 'comment'];
}

/**
 * Deduplicate read-side values locally so the read program can stay internally
 * self-contained after CLI parsing and still emit stable warning and file-path
 * lists without repeating the same text multiple times.
 */
function mydump_read_unique_values(array $values): array
{
    $seen = [];
    $result = [];

    foreach ($values as $value) {
        $text = trim((string) $value);
        if ($text === '') {
            continue;
        }

        $key = strtolower($text);
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $result[] = $text;
    }

    return $result;
}

/**
 * Emit deduplicated warnings to standard error after the dump completes, which
 * keeps the TSV file itself clean while still making every lossy conversion
 * obvious to the person running the command.
 */
function mydump_read_emit_warnings(array $warnings): void
{
    foreach (mydump_read_unique_values($warnings) as $warning) {
        fwrite(STDERR, $warning . PHP_EOL);
    }
}

/**
 * Quote one MySQL identifier for the read program's metadata queries so table
 * names containing reserved words or special characters remain safe anywhere a
 * `SHOW INDEX` style statement must interpolate the object name directly.
 */
function mydump_read_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

// ---------------------------------------------------------------------------
// mydump-write
// ---------------------------------------------------------------------------

/**
 * Execute the write half of the tool: load the TSV file, validate and group
 * its rows, convert them into the limited schema model supported by the format,
 * and then create or alter tables accordingly while skipping unsupported views.
 */
function mydump_run_write_program(array $cli): void
{
    $inputPath = mydump_write_resolve_input_path($cli);
    $objects = mydump_write_read_tsv_schema($inputPath);
    $connection = mydump_write_resolve_connection($cli['options']);
    $pdo = mydump_write_connect_server($connection, false);

    $databaseExists = mydump_write_database_exists($pdo, $connection['db']);
    if (!$databaseExists) {
        mydump_write_create_database($pdo, $connection['db'], 'utf8mb4', 'utf8mb4_unicode_ci');
        fwrite(STDOUT, "Created database `{$connection['db']}`" . PHP_EOL);
    }

    $pdo->exec('USE ' . mydump_write_quote_identifier($connection['db']));

    $plan = mydump_write_build_plan($pdo, $connection['db'], $objects);
    mydump_write_emit_warnings($plan['warnings']);

    if (empty($plan['statements'])) {
        fwrite(STDOUT, 'No changes required.' . PHP_EOL);
        return;
    }

    if ($databaseExists && ($cli['options']['run'] ?? false) !== true) {
        $confirmed = mydump_confirm(
            "Database `{$connection['db']}` already exists. Apply " . count($plan['statements']) . ' statement(s)?',
            true
        );
        if (!$confirmed) {
            fwrite(STDOUT, 'Aborted by user.' . PHP_EOL);
            return;
        }
    }

    foreach ($plan['statements'] as $statement) {
        $pdo->exec($statement);
        fwrite(STDOUT, '[OK] ' . $statement . PHP_EOL);
    }

    fwrite(STDOUT, 'Write complete.' . PHP_EOL);
}

/**
 * Resolve and validate the TSV input path for write mode, honoring the old
 * `write <file>` and plain positional-file shortcuts while ensuring the new
 * format restriction to `.tsv` is enforced consistently.
 */
function mydump_write_resolve_input_path(array $cli): string
{
    $inputPath = trim((string) ($cli['input'] ?? ''));
    if ($inputPath === '') {
        $inputPath = mydump_prompt('Input file (.tsv)', null, true);
    }

    if (!is_file($inputPath)) {
        throw new RuntimeException("Input file not found: {$inputPath}");
    }

    if (strtolower((string) pathinfo($inputPath, PATHINFO_EXTENSION)) !== 'tsv') {
        throw new RuntimeException('Write mode input must end in .tsv');
    }

    return $inputPath;
}

/**
 * Read and validate the TSV file, verify the header shape, and group the flat
 * rows back into one in-memory object per table or view so later write logic
 * can reason about schema changes in a structured way again.
 */
function mydump_write_read_tsv_schema(string $path): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException("Unable to open input file: {$path}");
    }

    try {
        $header = fgetcsv($handle, 0, "\t");
        if (!is_array($header)) {
            throw new RuntimeException('Input TSV is empty.');
        }

        $normalizedHeader = [];
        foreach ($header as $headerCell) {
            $normalizedHeader[] = mydump_write_normalize_header((string) $headerCell);
        }

        mydump_write_validate_tsv_header($normalizedHeader);

        $objectsByName = [];
        $lineNumber = 1;
        while (($row = fgetcsv($handle, 0, "\t")) !== false) {
            $lineNumber++;
            $assoc = mydump_write_combine_row($normalizedHeader, $row);
            if (mydump_write_row_is_empty($assoc)) {
                continue;
            }

            $field = mydump_write_parse_row($assoc, $lineNumber);
            $tableName = $field['table'];

            if (!isset($objectsByName[$tableName])) {
                $objectsByName[$tableName] = [
                    'name' => $tableName,
                    'tv' => $field['tv'],
                    'eng' => $field['eng'],
                    'fields' => [],
                ];
            }

            mydump_write_assert_consistent_table_metadata($objectsByName[$tableName], $field, $lineNumber);
            $field['position'] = count($objectsByName[$tableName]['fields']) + 1;
            $objectsByName[$tableName]['fields'][] = $field;
        }

        return array_values($objectsByName);
    } finally {
        fclose($handle);
    }
}

/**
 * Normalize a TSV header cell into a safe key format so the write parser can
 * accept exact headers while still tolerating minor cosmetic differences like
 * spaces or case changes if the file was edited by hand.
 */
function mydump_write_normalize_header(string $value): string
{
    $normalized = strtolower(trim($value));
    $normalized = str_replace([' ', '-', '.'], '_', $normalized);
    return preg_replace('/[^a-z0-9_]/', '', $normalized) ?? '';
}

/**
 * Validate that the incoming TSV header contains exactly the columns required
 * by the new format, because write mode depends on each named field to rebuild
 * types, indexes, defaults, and comments without any hidden extra structure.
 */
function mydump_write_validate_tsv_header(array $header): void
{
        $expected = mydump_write_tsv_headers();
    if ($header !== $expected) {
        throw new RuntimeException(
            'Invalid TSV header. Expected: ' . implode("\t", $expected)
        );
    }
}

/**
 * Combine one raw TSV row with the normalized header into an associative array
 * so every later parsing step can refer to named columns instead of positional
 * indexes and produce clearer validation messages when data is malformed.
 */
function mydump_write_combine_row(array $header, array $row): array
{
    $assoc = [];
    foreach ($header as $index => $key) {
        $assoc[$key] = (string) ($row[$index] ?? '');
    }

    return $assoc;
}

/**
 * Detect whether a parsed associative TSV row is completely blank, allowing
 * the write parser to safely skip visual separators or accidental empty lines
 * without treating them as broken schema entries.
 */
function mydump_write_row_is_empty(array $row): bool
{
    foreach ($row as $value) {
        if (trim((string) $value) !== '') {
            return false;
        }
    }

    return true;
}

/**
 * Parse and validate one TSV row into the internal field structure used by the
 * write program, normalizing compact markers like `tv`, `eng`, `properties`,
 * and `index` into strongly interpreted values early on.
 */
function mydump_write_parse_row(array $row, int $lineNumber): array
{
    $tableName = trim((string) ($row['table'] ?? ''));
    $fieldName = trim((string) ($row['name'] ?? ''));
    $typeToken = trim((string) ($row['type'] ?? ''));

    if ($tableName === '') {
        throw new RuntimeException("Line {$lineNumber}: table is required.");
    }
    if ($fieldName === '') {
        throw new RuntimeException("Line {$lineNumber}: name is required.");
    }
    if ($typeToken === '') {
        throw new RuntimeException("Line {$lineNumber}: type is required.");
    }

    return [
        'tv' => mydump_write_normalize_tv((string) ($row['tv'] ?? ''), $lineNumber),
        'table' => $tableName,
        'eng' => mydump_write_normalize_engine_code((string) ($row['eng'] ?? ''), $lineNumber),
        'name' => $fieldName,
        'type' => $typeToken,
        'length' => trim((string) ($row['length'] ?? '')),
        'properties' => mydump_write_normalize_properties((string) ($row['properties'] ?? ''), $lineNumber),
        'index' => mydump_write_normalize_index_marker((string) ($row['index'] ?? ''), $lineNumber),
        'collation' => trim((string) ($row['collation'] ?? '')),
        'default' => (string) ($row['default'] ?? ''),
        'comment' => (string) ($row['comment'] ?? ''),
    ];
}

/**
 * Normalize the `tv` cell to a strict `T` or `V`, rejecting anything else so
 * the write plan never has to guess whether a given group of rows describes a
 * table or a view.
 */
function mydump_write_normalize_tv(string $value, int $lineNumber): string
{
    $normalized = strtoupper(trim($value));
    if (!in_array($normalized, ['T', 'V'], true)) {
        throw new RuntimeException("Line {$lineNumber}: tv must be T or V.");
    }

    return $normalized;
}

/**
 * Normalize the compact engine code and reject unknown values early, because
 * the new format intentionally narrows table engines to the explicit two-code
 * vocabulary that the user requested.
 */
function mydump_write_normalize_engine_code(string $value, int $lineNumber): string
{
    $normalized = strtoupper(trim($value));
    if ($normalized === '') {
        return '';
    }

    if (!in_array($normalized, ['IDB', 'MEM'], true)) {
        throw new RuntimeException("Line {$lineNumber}: eng must be IDB, MEM, or blank.");
    }

    return $normalized;
}

/**
 * Normalize the aggregated `properties` cell into a boolean map for PK, NN,
 * UK, and AI, rejecting unknown property tokens so schema intent stays precise
 * and manual edits fail loudly instead of being half-ignored.
 */
function mydump_write_normalize_properties(string $value, int $lineNumber): array
{
    $properties = ['PK' => false, 'NN' => false, 'UK' => false, 'AI' => false];
    $trimmed = trim($value);
    if ($trimmed === '') {
        return $properties;
    }

    foreach (array_map('trim', explode(',', $trimmed)) as $part) {
        if ($part === '') {
            continue;
        }

        $token = strtoupper($part);
        if (!array_key_exists($token, $properties)) {
            throw new RuntimeException("Line {$lineNumber}: unknown property '{$part}'.");
        }

        $properties[$token] = true;
    }

    return $properties;
}

/**
 * Normalize the TSV index marker into the tiny set of supported values, since
 * the write program only knows how to create blank, BTREE-style, or HASH-style
 * single-column ordinary indexes.
 */
function mydump_write_normalize_index_marker(string $value, int $lineNumber): string
{
    $normalized = strtoupper(trim($value));
    if ($normalized === '') {
        return '';
    }

    if (!in_array($normalized, ['Y', 'HA'], true)) {
        throw new RuntimeException("Line {$lineNumber}: index must be Y, HA, or blank.");
    }

    return $normalized;
}

/**
 * Ensure all rows belonging to the same table name repeat the same table-level
 * metadata (`tv` and `eng`), because the new file format encodes those values
 * redundantly on every field row and inconsistent edits would be ambiguous.
 */
function mydump_write_assert_consistent_table_metadata(array $object, array $field, int $lineNumber): void
{
    if ((string) $object['tv'] !== (string) $field['tv']) {
        throw new RuntimeException("Line {$lineNumber}: tv must be consistent for every row of table '{$field['table']}'.");
    }

    if ((string) $object['eng'] !== (string) $field['eng']) {
        throw new RuntimeException("Line {$lineNumber}: eng must be consistent for every row of table '{$field['table']}'.");
    }
}

/**
 * Resolve the write-side MySQL connection strictly from explicit shell args,
 * because the tool is now intentionally literal single-file only and no longer
 * reads local PHP config files or connection constants from elsewhere.
 */
function mydump_write_resolve_connection(array $options): array
{
    $host = mydump_write_require_shell_connection_value('-host', $options['host'] ?? null);
    $portText = mydump_write_require_shell_connection_value('-port', $options['port'] ?? null);
    $user = mydump_write_require_shell_connection_value('-user', $options['user'] ?? null);
    $database = mydump_write_require_shell_connection_value('-db', $options['db'] ?? null);

    $password = $options['pass'] ?? null;
    $passwordProvided = (bool) ($options['pass_provided'] ?? false);
    if (!$passwordProvided) {
        throw new RuntimeException('Missing required shell argument: -pass');
    }

    if (!ctype_digit($portText) || (int) $portText < 1 || (int) $portText > 65535) {
        throw new RuntimeException('Shell argument -port must be an integer between 1 and 65535.');
    }

    return [
        'host' => $host,
        'port' => (int) $portText,
        'user' => $user,
        'pass' => (string) ($password ?? ''),
        'db' => $database,
    ];
}

/**
 * Require one non-empty shell-supplied connection value for the write program
 * and fail fast with a precise flag name when a mandatory argument is missing.
 */
function mydump_write_require_shell_connection_value(string $flagName, ?string $value): string
{
    $text = trim((string) $value);
    if ($text === '') {
        throw new RuntimeException("Missing required shell argument: {$flagName}");
    }

    return $text;
}

/**
 * Create a PDO connection for the write program with the same error mode and
 * charset choices as the read half so DDL generation and metadata inspection
 * can both rely on uniform database behavior.
 */
function mydump_write_connect_server(array $connection, bool $withDatabase): PDO
{
    $dsn = 'mysql:host=' . $connection['host'] . ';port=' . $connection['port'] . ';charset=utf8mb4';
    if ($withDatabase) {
        $dsn .= ';dbname=' . $connection['db'];
    }

    return new PDO(
        $dsn,
        $connection['user'],
        $connection['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

/**
 * Check whether the target database already exists before the write program
 * decides whether to create it, mirroring the old tool's behavior of creating
 * missing databases automatically during import.
 */
function mydump_write_database_exists(PDO $pdo, string $databaseName): bool
{
    $statement = $pdo->prepare(
        'SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :database LIMIT 1'
    );
    $statement->execute(['database' => $databaseName]);

    return (bool) $statement->fetchColumn();
}

/**
 * Create the destination database with a conservative UTF-8 default when the
 * TSV import targets a schema that does not yet exist, since the new TSV file
 * intentionally no longer carries database-level charset metadata.
 */
function mydump_write_create_database(PDO $pdo, string $databaseName, string $charset, string $collation): void
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $charset) !== 1) {
        $charset = 'utf8mb4';
    }
    if (preg_match('/^[A-Za-z0-9_]+$/', $collation) !== 1) {
        $collation = 'utf8mb4_unicode_ci';
    }

    $sql = 'CREATE DATABASE ' . mydump_write_quote_identifier($databaseName)
        . ' CHARACTER SET ' . $charset
        . ' COLLATE ' . $collation;

    $pdo->exec($sql);
}

/**
 * Build the full write plan by comparing desired TSV objects to the current
 * database contents, generating create or alter statements for tables, and
 * collecting explicit warnings for views and other limitations the format
 * cannot safely apply during import.
 */
function mydump_write_build_plan(PDO $pdo, string $databaseName, array $objects): array
{
    $existingObjectMap = mydump_write_fetch_existing_object_map($pdo, $databaseName);
    $statements = [];
    $warnings = [];

    foreach ($objects as $object) {
        $tableName = (string) ($object['name'] ?? '');
        if ($tableName === '') {
            continue;
        }

        if ((string) ($object['tv'] ?? 'T') === 'V') {
            $warnings[] = "Warning [{$tableName}]: write mode skips views because TSV does not store a recreatable view definition.";
            continue;
        }

        $existingType = $existingObjectMap[$tableName] ?? null;
        if ($existingType === null) {
            $statements[] = mydump_write_build_create_table_sql($object);
            continue;
        }

        if ($existingType === 'VIEW') {
            $statements[] = 'DROP VIEW ' . mydump_write_quote_identifier($tableName);
            $statements[] = mydump_write_build_create_table_sql($object);
            continue;
        }

        $alterSql = mydump_write_build_alter_table_sql($pdo, $databaseName, $object);
        if ($alterSql !== null) {
            $statements[] = $alterSql;
        }
    }

    return [
        'statements' => $statements,
        'warnings' => mydump_write_unique_values($warnings),
    ];
}

/**
 * Fetch the current object map of the target database so write mode can choose
 * between `CREATE TABLE`, `DROP VIEW + CREATE TABLE`, or `ALTER TABLE` without
 * having to perform extra existence checks inside every branch.
 */
function mydump_write_fetch_existing_object_map(PDO $pdo, string $databaseName): array
{
    $statement = $pdo->prepare(
        'SELECT TABLE_NAME, TABLE_TYPE
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = :database'
    );
    $statement->execute(['database' => $databaseName]);

    $map = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(string) ($row['TABLE_NAME'] ?? '')] = strtoupper((string) ($row['TABLE_TYPE'] ?? ''));
    }

    return $map;
}

/**
 * Generate a complete `CREATE TABLE` statement from the TSV model by ordering
 * fields as they appeared in the file, rendering each column definition, and
 * then appending the expressible single-column primary, unique, and normal
 * indexes that the flat format can represent.
 */
function mydump_write_build_create_table_sql(array $object): string
{
    $tableName = (string) ($object['name'] ?? '');
    $fields = (array) ($object['fields'] ?? []);
    if (empty($fields)) {
        throw new RuntimeException("Table '{$tableName}' has no fields.");
    }

    $lines = [];
    foreach ($fields as $field) {
        $lines[] = '  ' . mydump_write_render_column_definition($field);
    }

    foreach (mydump_write_build_desired_index_specs($fields) as $indexSpec) {
        $lines[] = '  ' . mydump_write_render_index_definition($indexSpec, false);
    }

    $sql = 'CREATE TABLE ' . mydump_write_quote_identifier($tableName)
        . " (\n" . implode(",\n", $lines) . "\n)";

    $engineSql = mydump_write_render_engine_clause((string) ($object['eng'] ?? ''));
    if ($engineSql !== '') {
        $sql .= ' ' . $engineSql;
    }

    return $sql;
}

/**
 * Generate an `ALTER TABLE` statement by diffing the current database schema
 * against the desired TSV model, handling column order, column definitions,
 * and the subset of index operations the flat format can safely express.
 */
function mydump_write_build_alter_table_sql(PDO $pdo, string $databaseName, array $object): ?string
{
    $tableName = (string) ($object['name'] ?? '');
    $desiredFields = (array) ($object['fields'] ?? []);
    if (empty($desiredFields)) {
        return null;
    }

    $currentFields = mydump_write_fetch_current_fields($pdo, $databaseName, $tableName);
    $currentIndexes = mydump_write_fetch_current_expressible_indexes($pdo, $tableName);
    $currentEngine = mydump_write_fetch_current_engine_code($pdo, $databaseName, $tableName);

    $currentFieldsByName = [];
    $currentFieldOrder = [];
    foreach ($currentFields as $position => $field) {
        $name = (string) $field['name'];
        $currentFieldsByName[$name] = $field;
        $currentFieldOrder[$name] = $position + 1;
    }

    $desiredFieldsByName = [];
    $columnOperations = [];
    $previousFieldName = null;

    foreach ($desiredFields as $position => $field) {
        $fieldName = (string) ($field['name'] ?? '');
        if ($fieldName === '') {
            continue;
        }

        $desiredFieldsByName[$fieldName] = $field;
        $positionSql = ($previousFieldName === null)
            ? ' FIRST'
            : (' AFTER ' . mydump_write_quote_identifier($previousFieldName));

        if (!isset($currentFieldsByName[$fieldName])) {
            $columnOperations[] = 'ADD COLUMN ' . mydump_write_render_column_definition($field) . $positionSql;
            $previousFieldName = $fieldName;
            continue;
        }

        $currentField = $currentFieldsByName[$fieldName];
        $sameDefinition = mydump_write_field_signature($currentField) === mydump_write_field_signature($field);
        $samePosition = ($currentFieldOrder[$fieldName] ?? 0) === ($position + 1);

        if (!$sameDefinition || !$samePosition) {
            $columnOperations[] = 'MODIFY COLUMN ' . mydump_write_render_column_definition($field) . $positionSql;
        }

        $previousFieldName = $fieldName;
    }

    foreach ($currentFieldsByName as $fieldName => $field) {
        if (!isset($desiredFieldsByName[$fieldName])) {
            $columnOperations[] = 'DROP COLUMN ' . mydump_write_quote_identifier($fieldName);
        }
    }

    $indexOperations = mydump_write_build_index_diff(
        $currentIndexes,
        mydump_write_build_desired_index_specs($desiredFields)
    );

    $engineOperation = mydump_write_build_engine_alter_clause($currentEngine, (string) ($object['eng'] ?? ''));

    $operations = array_merge($indexOperations['drop'], $columnOperations, $indexOperations['add']);
    if ($engineOperation !== null) {
        $operations[] = $engineOperation;
    }

    if (empty($operations)) {
        return null;
    }

    return 'ALTER TABLE ' . mydump_write_quote_identifier($tableName) . ' ' . implode(', ', $operations);
}

/**
 * Fetch the current table fields and convert them into the same internal field
 * model used for TSV rows so definition comparison can be done deterministically
 * without trying to diff raw SQL fragments.
 */
function mydump_write_fetch_current_fields(PDO $pdo, string $databaseName, string $tableName): array
{
    $statement = $pdo->prepare(
        'SELECT
            COLUMN_NAME,
            DATA_TYPE,
            COLUMN_TYPE,
            CHARACTER_MAXIMUM_LENGTH,
            IS_NULLABLE,
            COLUMN_DEFAULT,
            EXTRA,
            COLLATION_NAME,
            COLUMN_COMMENT,
            ORDINAL_POSITION
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :database
           AND TABLE_NAME = :table
         ORDER BY ORDINAL_POSITION'
    );
    $statement->execute([
        'database' => $databaseName,
        'table' => $tableName,
    ]);

    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $indexMap = mydump_write_build_current_index_map($pdo, $tableName);

    $fields = [];
    foreach ($rows as $row) {
        $fieldName = (string) ($row['COLUMN_NAME'] ?? '');
        $indexMeta = $indexMap[$fieldName] ?? ['properties' => ['PK' => false, 'UK' => false], 'index' => ''];

        $fields[] = [
            'name' => $fieldName,
            'type' => mydump_write_encode_current_type_token($row),
            'length' => mydump_write_encode_current_length($row),
            'properties' => mydump_write_encode_current_properties($row, $indexMeta),
            'index' => (string) ($indexMeta['index'] ?? ''),
            'collation' => (string) ($row['COLLATION_NAME'] ?? ''),
            'default' => mydump_write_encode_current_default($row),
            'comment' => (string) ($row['COLUMN_COMMENT'] ?? ''),
        ];
    }

    return $fields;
}

/**
 * Convert the current column's type into the same token grammar the TSV uses,
 * which keeps current-versus-desired comparisons aligned with what users see
 * and edit in the flat file itself.
 */
function mydump_write_encode_current_type_token(array $column): string
{
    $dataType = strtolower(trim((string) ($column['DATA_TYPE'] ?? '')));
    $columnType = trim((string) ($column['COLUMN_TYPE'] ?? ''));
    $isUnsigned = preg_match('/\bunsigned\b/i', $columnType) === 1;
    $typeCore = mydump_write_strip_unsigned_keyword($columnType);

    if (mydump_write_is_length_driven_type($dataType)) {
        $typeCore = $dataType;
    } elseif ($dataType !== '' && mydump_write_is_simple_type_without_length($dataType)) {
        $typeCore = $dataType;
    } elseif ($typeCore === '') {
        $typeCore = $dataType;
    }

    if ($isUnsigned && mydump_write_is_unsigned_capable_type($dataType)) {
        return 'u.' . $typeCore;
    }

    return $typeCore;
}

/**
 * Convert the current column length into the same dedicated TSV length cell so
 * the write-side comparison logic stays faithful to the user-facing flat file
 * instead of inventing a separate internal representation.
 */
function mydump_write_encode_current_length(array $column): string
{
    $dataType = strtolower(trim((string) ($column['DATA_TYPE'] ?? '')));
    if (!mydump_write_is_length_driven_type($dataType)) {
        return '';
    }

    $length = $column['CHARACTER_MAXIMUM_LENGTH'] ?? null;
    if ($length === null || $length === '') {
        return '';
    }

    return (string) (int) $length;
}

/**
 * Convert current column and index metadata back into the aggregated property
 * map used by the TSV so comparison can focus on semantic equality rather than
 * the specific SQL clauses used to produce those semantics.
 */
function mydump_write_encode_current_properties(array $column, array $indexMeta): array
{
    return [
        'PK' => (bool) ($indexMeta['properties']['PK'] ?? false),
        'NN' => strtoupper((string) ($column['IS_NULLABLE'] ?? 'YES')) !== 'YES',
        'UK' => (bool) ($indexMeta['properties']['UK'] ?? false),
        'AI' => str_contains(strtolower((string) ($column['EXTRA'] ?? '')), 'auto_increment'),
    ];
}

/**
 * Convert current default metadata into the same flat default cell syntax the
 * TSV uses, which allows the write-side diff to compare user-edited values and
 * live database values using one common representation.
 */
function mydump_write_encode_current_default(array $column): string
{
    $defaultValue = $column['COLUMN_DEFAULT'] ?? null;
    $dataType = strtolower(trim((string) ($column['DATA_TYPE'] ?? '')));
    $isNullable = strtoupper((string) ($column['IS_NULLABLE'] ?? 'YES')) === 'YES';
    $extra = (string) ($column['EXTRA'] ?? '');

    if ($defaultValue === null) {
        return $isNullable ? 'NULL' : '';
    }

    $defaultText = (string) $defaultValue;
    if (mydump_write_is_default_expression($defaultText, $extra)) {
        return $defaultText;
    }

    if ($defaultText === '') {
        return "''";
    }

    return $defaultText;
}

/**
 * Fetch the current engine and convert it into the same compact code expected
 * by the TSV so engine changes can be diffed without exposing raw MySQL engine
 * names everywhere in the rest of the write logic.
 */
function mydump_write_fetch_current_engine_code(PDO $pdo, string $databaseName, string $tableName): string
{
    $statement = $pdo->prepare(
        'SELECT ENGINE
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = :database
           AND TABLE_NAME = :table'
    );
    $statement->execute([
        'database' => $databaseName,
        'table' => $tableName,
    ]);

    $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    $engine = strtoupper(trim((string) ($row['ENGINE'] ?? '')));
    if ($engine === 'INNODB') {
        return 'IDB';
    }
    if ($engine === 'MEMORY' || $engine === 'HEAP') {
        return 'MEM';
    }

    return '';
}

/**
 * Fetch the current expressible single-column indexes in a normalized form so
 * write mode can compare index intent semantically and avoid unnecessary churn
 * when the existing database uses different physical index names.
 */
function mydump_write_fetch_current_expressible_indexes(PDO $pdo, string $tableName): array
{
    $rows = mydump_write_fetch_index_rows($pdo, $tableName);
    $grouped = [];
    foreach ($rows as $row) {
        $name = (string) ($row['Key_name'] ?? '');
        if ($name === '') {
            continue;
        }
        if (!isset($grouped[$name])) {
            $grouped[$name] = [];
        }
        $grouped[$name][] = $row;
    }

    $indexes = [];
    foreach ($grouped as $name => $group) {
        if (count($group) !== 1) {
            continue;
        }

        $row = $group[0];
        $columnName = (string) ($row['Column_name'] ?? '');
        if ($columnName === '') {
            continue;
        }

        $indexType = strtoupper((string) ($row['Index_type'] ?? 'BTREE'));
        if (!in_array($indexType, ['BTREE', 'HASH'], true)) {
            continue;
        }
        if (($row['Sub_part'] ?? null) !== null) {
            continue;
        }

        $kind = 'IDX';
        if ($name === 'PRIMARY') {
            $kind = 'PK';
        } elseif (((int) ($row['Non_unique'] ?? 1)) === 0) {
            $kind = 'UK';
        }

        $indexes[] = [
            'actual_name' => $name,
            'kind' => $kind,
            'column' => $columnName,
            'algorithm' => ($kind === 'IDX' && $indexType === 'HASH') ? 'HA' : (($indexType === 'HASH') ? 'HA' : 'Y'),
        ];
    }

    return $indexes;
}

/**
 * Build a field-name lookup of the current table's expressible single-column
 * indexes so the write-side field normalization can reconstruct current PK, UK,
 * and ordinary-index markers using the same compact model as the TSV.
 */
function mydump_write_build_current_index_map(PDO $pdo, string $tableName): array
{
    $indexes = mydump_write_fetch_current_expressible_indexes($pdo, $tableName);
    $map = [];

    foreach ($indexes as $index) {
        $columnName = (string) ($index['column'] ?? '');
        if ($columnName === '') {
            continue;
        }

        if (!isset($map[$columnName])) {
            $map[$columnName] = [
                'properties' => ['PK' => false, 'UK' => false],
                'index' => '',
            ];
        }

        if ((string) $index['kind'] === 'PK') {
            $map[$columnName]['properties']['PK'] = true;
            continue;
        }

        if ((string) $index['kind'] === 'UK') {
            $map[$columnName]['properties']['UK'] = true;
            continue;
        }

        $map[$columnName]['index'] = (string) ($index['algorithm'] ?? 'Y');
    }

    return $map;
}

/**
 * Fetch raw `SHOW INDEX` rows for the write program, which needs the actual
 * index names in order to drop obsolete indexes even though comparison itself
 * is done using normalized semantic signatures.
 */
function mydump_write_fetch_index_rows(PDO $pdo, string $tableName): array
{
    $sql = 'SHOW INDEX FROM ' . mydump_write_quote_identifier($tableName);
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Build the desired expressible index list directly from the TSV field model,
 * turning PK, UK, and ordinary index markers into normalized single-column
 * index specs with deterministic generated names for new indexes.
 */
function mydump_write_build_desired_index_specs(array $fields): array
{
    $indexes = [];

    foreach ($fields as $field) {
        $fieldName = (string) ($field['name'] ?? '');
        if ($fieldName === '') {
            continue;
        }

        $properties = (array) ($field['properties'] ?? []);
        if (($properties['PK'] ?? false) === true) {
            $indexes[] = [
                'actual_name' => 'PRIMARY',
                'kind' => 'PK',
                'column' => $fieldName,
                'algorithm' => 'Y',
            ];
        }

        if (($properties['UK'] ?? false) === true) {
            $indexes[] = [
                'actual_name' => 'uk_' . $fieldName,
                'kind' => 'UK',
                'column' => $fieldName,
                'algorithm' => 'Y',
            ];
        }

        $indexMarker = (string) ($field['index'] ?? '');
        if ($indexMarker !== '') {
            $indexes[] = [
                'actual_name' => 'idx_' . $fieldName,
                'kind' => 'IDX',
                'column' => $fieldName,
                'algorithm' => $indexMarker,
            ];
        }
    }

    return $indexes;
}

/**
 * Compare current and desired expressible indexes semantically so redundant
 * renames are avoided, then produce the exact drop and add clauses needed for
 * the final `ALTER TABLE` statement.
 */
function mydump_write_build_index_diff(array $currentIndexes, array $desiredIndexes): array
{
    $currentBySignature = [];
    foreach ($currentIndexes as $index) {
        $signature = mydump_write_index_signature($index);
        if (!isset($currentBySignature[$signature])) {
            $currentBySignature[$signature] = $index;
        }
    }

    $desiredBySignature = [];
    foreach ($desiredIndexes as $index) {
        $signature = mydump_write_index_signature($index);
        if (!isset($desiredBySignature[$signature])) {
            $desiredBySignature[$signature] = $index;
        }
    }

    $dropOperations = [];
    foreach ($currentBySignature as $signature => $index) {
        if (!isset($desiredBySignature[$signature])) {
            $dropOperations[] = mydump_write_render_index_drop($index);
        }
    }

    $addOperations = [];
    foreach ($desiredBySignature as $signature => $index) {
        if (!isset($currentBySignature[$signature])) {
            $addOperations[] = mydump_write_render_index_definition($index, true);
        }
    }

    return [
        'drop' => $dropOperations,
        'add' => $addOperations,
    ];
}

/**
 * Render the SQL needed to drop one current expressible index, taking special
 * care of MySQL's dedicated `DROP PRIMARY KEY` syntax for primary indexes.
 */
function mydump_write_render_index_drop(array $index): string
{
    if ((string) ($index['kind'] ?? '') === 'PK') {
        return 'DROP PRIMARY KEY';
    }

    return 'DROP INDEX ' . mydump_write_quote_identifier((string) ($index['actual_name'] ?? ''));
}

/**
 * Render one expressible index definition either for `CREATE TABLE` or `ALTER
 * TABLE ADD`, using deterministic names for generated indexes and optionally
 * attaching `USING HASH` when the TSV asks for the HASH variant.
 */
function mydump_write_render_index_definition(array $index, bool $withAddKeyword): string
{
    $prefix = $withAddKeyword ? 'ADD ' : '';
    $columnSql = '(' . mydump_write_quote_identifier((string) ($index['column'] ?? '')) . ')';
    $algorithmSql = ((string) ($index['algorithm'] ?? 'Y') === 'HA') ? ' USING HASH' : '';

    if ((string) ($index['kind'] ?? '') === 'PK') {
        return $prefix . 'PRIMARY KEY ' . $columnSql . $algorithmSql;
    }

    if ((string) ($index['kind'] ?? '') === 'UK') {
        return $prefix
            . 'UNIQUE KEY '
            . mydump_write_quote_identifier((string) ($index['actual_name'] ?? ''))
            . ' ' . $columnSql . $algorithmSql;
    }

    return $prefix
        . 'KEY '
        . mydump_write_quote_identifier((string) ($index['actual_name'] ?? ''))
        . ' ' . $columnSql . $algorithmSql;
}

/**
 * Build a stable semantic signature for one expressible index so comparison can
 * ignore physical index names and focus on what the TSV format actually means:
 * kind, column, and whether the ordinary index asks for HASH semantics.
 */
function mydump_write_index_signature(array $index): string
{
    $kind = (string) ($index['kind'] ?? '');
    $algorithm = ($kind === 'IDX') ? strtoupper((string) ($index['algorithm'] ?? 'Y')) : '';

    return implode('|', [
        $kind,
        strtolower((string) ($index['column'] ?? '')),
        $algorithm,
    ]);
}

/**
 * Render one column definition from the internal TSV field model, assembling
 * type, collation, nullability, default, auto-increment, and comment into the
 * exact SQL fragment needed by both create and alter operations.
 */
function mydump_write_render_column_definition(array $field): string
{
    $name = (string) ($field['name'] ?? '');
    if ($name === '') {
        throw new RuntimeException('Invalid field definition: missing name.');
    }

    $sql = mydump_write_quote_identifier($name) . ' ' . mydump_write_render_sql_type($field);

    $collation = trim((string) ($field['collation'] ?? ''));
    if ($collation !== '' && preg_match('/^[A-Za-z0-9_]+$/', $collation) === 1) {
        $sql .= ' COLLATE ' . $collation;
    }

    $properties = (array) ($field['properties'] ?? []);
    $isNotNull = ($properties['NN'] ?? false) === true
        || ($properties['AI'] ?? false) === true
        || ($properties['PK'] ?? false) === true;
    $sql .= $isNotNull ? ' NOT NULL' : ' NULL';

    $defaultClause = mydump_write_render_default_clause($field);
    if ($defaultClause !== '') {
        $sql .= ' ' . $defaultClause;
    }

    if (($properties['AI'] ?? false) === true) {
        $sql .= ' AUTO_INCREMENT';
    }

    $comment = (string) ($field['comment'] ?? '');
    if ($comment !== '') {
        $sql .= ' COMMENT ' . mydump_write_quote_string($comment);
    }

    return $sql;
}

/**
 * Render the SQL data type from the TSV `type` and `length` columns by putting
 * size back where needed, restoring the MySQL `unsigned` keyword from the `u.`
 * prefix, and otherwise leaving advanced type expressions untouched.
 */
function mydump_write_render_sql_type(array $field): string
{
    $typeToken = trim((string) ($field['type'] ?? ''));
    $length = trim((string) ($field['length'] ?? ''));
    if ($typeToken === '') {
        throw new RuntimeException('Invalid field definition: missing type.');
    }

    $isUnsigned = str_starts_with(strtolower($typeToken), 'u.');
    $typeCore = $isUnsigned ? substr($typeToken, 2) : $typeToken;
    $baseType = strtolower(trim((string) preg_replace('/\(.*/', '', $typeCore)));

    if (mydump_write_is_length_driven_type($baseType)) {
        if ($length === '' || !ctype_digit($length) || (int) $length < 1) {
            throw new RuntimeException("Field '{$field['name']}' requires a positive integer length.");
        }
        $typeCore = $baseType . '(' . (int) $length . ')';
    }

    if ($isUnsigned && mydump_write_is_unsigned_capable_type($baseType)) {
        $typeCore .= ' unsigned';
    }

    return $typeCore;
}

/**
 * Render the SQL default clause from the flat TSV `default` cell, using light
 * type-aware heuristics so human-edited values remain convenient while still
 * preserving raw SQL expressions and already-quoted literals exactly.
 */
function mydump_write_render_default_clause(array $field): string
{
    $defaultValue = (string) ($field['default'] ?? '');
    if ($defaultValue === '') {
        return '';
    }

    if (strcasecmp($defaultValue, 'NULL') === 0) {
        return 'DEFAULT NULL';
    }

    return 'DEFAULT ' . mydump_write_render_default_expression($field, $defaultValue);
}

/**
 * Convert one TSV default cell into the exact SQL fragment to place after
 * `DEFAULT`, preserving explicit quoting when present and otherwise choosing
 * safe literal quoting rules based on the declared column type.
 */
function mydump_write_render_default_expression(array $field, string $defaultValue): string
{
    $trimmed = trim($defaultValue);
    if ($trimmed === '') {
        return "''";
    }

    if (preg_match('/^\'(?:[^\']|\'\')*\'$/', $trimmed) === 1) {
        return $trimmed;
    }

    $typeToken = trim((string) ($field['type'] ?? ''));
    $typeCore = str_starts_with(strtolower($typeToken), 'u.') ? substr($typeToken, 2) : $typeToken;
    $baseType = strtolower(trim((string) preg_replace('/\(.*/', '', $typeCore)));

    if (mydump_write_is_string_like_type($baseType)) {
        return mydump_write_quote_string($trimmed);
    }

    if (mydump_write_is_temporal_type($baseType)) {
        if (mydump_write_is_default_expression($trimmed, '')) {
            return $trimmed;
        }
        return mydump_write_quote_string($trimmed);
    }

    if (mydump_write_is_unsigned_capable_type($baseType) && is_numeric($trimmed)) {
        return $trimmed;
    }

    if (mydump_write_is_default_expression($trimmed, '')) {
        return $trimmed;
    }

    return mydump_write_quote_string($trimmed);
}

/**
 * Build the optional table-engine clause for `CREATE TABLE`, translating the
 * compact TSV engine codes back into their MySQL names and omitting the clause
 * entirely when the TSV leaves the engine blank.
 */
function mydump_write_render_engine_clause(string $engineCode): string
{
    $normalized = strtoupper(trim($engineCode));
    if ($normalized === 'IDB') {
        return 'ENGINE=InnoDB';
    }
    if ($normalized === 'MEM') {
        return 'ENGINE=MEMORY';
    }

    return '';
}

/**
 * Build the optional engine alteration clause for existing tables when the
 * compact TSV engine code differs from the current table engine and therefore
 * needs an explicit `ALTER TABLE ... ENGINE=...` operation.
 */
function mydump_write_build_engine_alter_clause(string $currentEngineCode, string $desiredEngineCode): ?string
{
    $current = strtoupper(trim($currentEngineCode));
    $desired = strtoupper(trim($desiredEngineCode));

    if ($desired === '' || $desired === $current) {
        return null;
    }

    $engineClause = mydump_write_render_engine_clause($desired);
    return $engineClause !== '' ? $engineClause : null;
}

/**
 * Build a stable signature for one field that captures only the parts of the
 * definition rendered into SQL column clauses, leaving PK, UK, and ordinary
 * indexes to the separate index diffing logic.
 */
function mydump_write_field_signature(array $field): string
{
    $properties = (array) ($field['properties'] ?? []);

    return implode('|', [
        strtolower((string) ($field['name'] ?? '')),
        strtolower(trim((string) ($field['type'] ?? ''))),
        trim((string) ($field['length'] ?? '')),
        ($properties['NN'] ?? false) || ($properties['AI'] ?? false) || ($properties['PK'] ?? false) ? '1' : '0',
        ($properties['AI'] ?? false) ? '1' : '0',
        (string) ($field['collation'] ?? ''),
        (string) ($field['default'] ?? ''),
        (string) ($field['comment'] ?? ''),
    ]);
}

/**
 * Remove the literal MySQL `unsigned` keyword from a full current column type
 * while leaving every other modifier intact, allowing current live metadata to
 * be normalized into the same `u.`-based syntax as the TSV.
 */
function mydump_write_strip_unsigned_keyword(string $columnType): string
{
    $stripped = preg_replace('/\s+unsigned\b/i', '', $columnType);
    if ($stripped === null) {
        return trim($columnType);
    }

    return trim(preg_replace('/\s+/', ' ', $stripped) ?? $stripped);
}

/**
 * Detect default expressions on the write side using the same rule as the read
 * side so live-database metadata and user-edited TSV values are interpreted in
 * the same consistent way during diffing and SQL generation.
 */
function mydump_write_is_default_expression(string $defaultValue, string $extra): bool
{
    if (stripos($extra, 'DEFAULT_GENERATED') !== false) {
        return true;
    }

    return preg_match(
        '/^(CURRENT_TIMESTAMP(?:\(\d+\))?|CURRENT_DATE(?:\(\))?|CURRENT_TIME(?:\(\))?|NOW\(\)|UUID\(\)|\(.+\))$/i',
        trim($defaultValue)
    ) === 1;
}

/**
 * Identify the string and binary types whose size belongs in the dedicated TSV
 * `length` column so the write program knows when to reassemble `type(length)`
 * during SQL generation.
 */
function mydump_write_is_length_driven_type(string $dataType): bool
{
    return in_array($dataType, ['char', 'varchar', 'binary', 'varbinary'], true);
}

/**
 * Identify types that are fully represented by their bare names in TSV and do
 * not need any parenthesized details preserved when normalizing live database
 * metadata for comparison against the imported file.
 */
function mydump_write_is_simple_type_without_length(string $dataType): bool
{
    return in_array(
        $dataType,
        [
            'tinyint', 'smallint', 'mediumint', 'int', 'bigint',
            'date', 'datetime', 'timestamp', 'time', 'year',
            'json', 'tinytext', 'text', 'mediumtext', 'longtext',
            'tinyblob', 'blob', 'mediumblob', 'longblob',
            'geometry', 'point', 'linestring', 'polygon',
            'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection',
        ],
        true
    );
}

/**
 * Identify types that meaningfully support MySQL's `unsigned` modifier so the
 * write program can decide whether a TSV `u.` prefix should become the SQL
 * keyword `unsigned` when rebuilding a column type.
 */
function mydump_write_is_unsigned_capable_type(string $dataType): bool
{
    return in_array(
        $dataType,
        ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'numeric', 'float', 'double', 'real'],
        true
    );
}

/**
 * Identify textual types whose unquoted default values in TSV should be turned
 * into SQL string literals when generating DDL, which keeps manual editing easy
 * without sacrificing correct quoting on import.
 */
function mydump_write_is_string_like_type(string $dataType): bool
{
    return in_array(
        $dataType,
        [
            'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext',
            'enum', 'set',
        ],
        true
    );
}

/**
 * Identify temporal types so the write program can distinguish between literal
 * date/time defaults that need quoting and recognized SQL expressions like
 * `CURRENT_TIMESTAMP` that must stay raw.
 */
function mydump_write_is_temporal_type(string $dataType): bool
{
    return in_array($dataType, ['date', 'datetime', 'timestamp', 'time', 'year'], true);
}

/**
 * Emit write-side warnings to standard error after plan construction so the
 * user sees skipped views and other import-time limitations before statements
 * are executed, while still keeping the main success output easy to scan.
 */
function mydump_write_emit_warnings(array $warnings): void
{
    foreach (mydump_write_unique_values($warnings) as $warning) {
        fwrite(STDERR, $warning . PHP_EOL);
    }
}

/**
 * Return the canonical TSV header order for the write program so header
 * validation stays local to this half of the file and does not depend on the
 * read program's implementation details.
 */
function mydump_write_tsv_headers(): array
{
    return ['tv', 'table', 'eng', 'name', 'type', 'length', 'properties', 'index', 'collation', 'default', 'comment'];
}

/**
 * Deduplicate write-side values locally so warnings and plan metadata remain
 * stable without creating extra cross-program dependencies beyond parsing.
 */
function mydump_write_unique_values(array $values): array
{
    $seen = [];
    $result = [];

    foreach ($values as $value) {
        $text = trim((string) $value);
        if ($text === '') {
            continue;
        }

        $key = strtolower($text);
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $result[] = $text;
    }

    return $result;
}

/**
 * Quote one MySQL identifier for write-side DDL generation, ensuring that the
 * generated SQL remains correct for reserved words and special characters in
 * table, column, and index names.
 */
function mydump_write_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/**
 * Quote one SQL string literal using simple single-quote escaping so comments
 * and default values generated by the write program are emitted as valid SQL
 * without depending on a live PDO quote call.
 */
function mydump_write_quote_string(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}
