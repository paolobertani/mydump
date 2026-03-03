<?php

declare(strict_types=1);

function mydump_parse_cli(array $argv): array
{
    $tokens = $argv;
    array_shift($tokens);

    $command = null;
    if (isset($tokens[0])) {
        $head = strtolower((string) $tokens[0]);
        if ($head === 'dump' || $head === 'write') {
            $command = $head;
            array_shift($tokens);
        } elseif ($head === 'help') {
            $command = 'help';
            array_shift($tokens);
        }
    }

    $rawOptions = [];
    $positionals = [];
    $flags = ['run', 'help', '?'];

    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $token = (string) $tokens[$i];

        if ($token === '--') {
            for ($j = $i + 1; $j < $n; $j++) {
                $positionals[] = (string) $tokens[$j];
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
        } elseif (!in_array($name, $flags, true)) {
            $next = $tokens[$i + 1] ?? null;
            if ($next !== null && (string) $next !== '--' && !str_starts_with((string) $next, '-')) {
                $value = (string) $next;
                $i++;
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
        'db' => mydump_option_value($rawOptions, ['db', 'database']),
        'input' => mydump_option_value($rawOptions, ['i', 'input']),
        'output' => mydump_option_value($rawOptions, ['o', 'output']),
        'outputs' => mydump_option_values($rawOptions, ['o', 'output']),
        'json_output' => mydump_option_value($rawOptions, ['json']),
        'xlsx_output' => mydump_option_value($rawOptions, ['xlsx']),
        'run' => mydump_option_flag($rawOptions, ['run']),
        'help' => $command === 'help' || mydump_option_flag($rawOptions, ['help', '?']),
        'positionals' => $positionals,
    ];

    $hasDumpOutputArgs = !empty($options['outputs'])
        || !empty($options['json_output'])
        || !empty($options['xlsx_output']);

    $mode = null;
    if ($command === 'dump' || $command === 'write') {
        $mode = $command;
    }

    if ($mode === null && !$options['help']) {
        if ($hasDumpOutputArgs) {
            $mode = 'dump';
        } else {
            if (!empty($options['input'])) {
                $mode = 'write';
            } else {
                $firstDataFile = mydump_find_file_positional($positionals);
                if ($firstDataFile !== null) {
                    $mode = 'write';
                }
            }
        }
    }

    $input = null;
    if ($mode === 'write') {
        $input = $options['input'] ?: mydump_find_file_positional($positionals);
    }

    if ($mode === 'dump') {
        $collected = [];
        foreach ($options['outputs'] as $out) {
            $collected[] = $out;
        }
        if (!empty($options['json_output'])) {
            $collected[] = (string) $options['json_output'];
        }
        if (!empty($options['xlsx_output'])) {
            $collected[] = (string) $options['xlsx_output'];
        }

        // In explicit dump mode, allow positional output files too.
        if ($command === 'dump') {
            foreach (mydump_find_file_positionals($positionals) as $out) {
                $collected[] = $out;
            }
        }

        $options['outputs'] = mydump_unique_values($collected);
        if (empty($options['output']) && !empty($options['outputs'])) {
            $options['output'] = $options['outputs'][0];
        }
    }

    return [
        'mode' => $mode,
        'command' => $command,
        'options' => $options,
        'input' => $input,
    ];
}

function mydump_print_usage(): void
{
    $usage = <<<TXT
mydump - MySQL schema <-> JSON/XLSX

Usage:
  php mydump.php dump [output options] [connection options]
  php mydump.php write <input.xlsx|input.json|input.js> [connection options]

Shortcuts:
  php mydump.php -o schema.xlsx [connection options]      # same as dump
  php mydump.php schema.xlsx [connection options]         # same as write

Dump output options:
  -o, --output <file>     Output file; repeatable (use multiple times)
  --json <file>           Explicit JSON output file
  --xlsx <file>           Explicit XLSX output file

Examples:
  php mydump.php dump -o schema.json
  php mydump.php dump -o schema.json -o schema.xlsx
  php mydump.php dump --json schema.json --xlsx schema.xlsx

Connection options:
  -host <host>            MySQL host (default 127.0.0.1)
  -port <port>            MySQL port (default 3306)
  -user <user>            MySQL user (default root)
  -pass <password>        MySQL password
  -db <database>          Database name

Write mode options:
  --run                   Skip confirmation prompt before ALTER statements

General:
  -help, --help, help     Show this message
TXT;

    fwrite(STDOUT, $usage . PHP_EOL);
}

function mydump_prompt(string $label, ?string $default = null, bool $required = true): string
{
    while (true) {
        $suffix = $default !== null && $default !== '' ? " [{$default}]" : '';
        $line = mydump_readline($label . $suffix . ': ');
        if ($line === '' && $default !== null) {
            return $default;
        }
        if (!$required || $line !== '') {
            return $line;
        }
    }
}

function mydump_prompt_secret(string $label): string
{
    $line = '';

    $canHide = DIRECTORY_SEPARATOR !== '\\' && function_exists('shell_exec');
    if ($canHide) {
        @shell_exec('stty -echo');
    }

    try {
        $line = mydump_readline($label . ': ');
    } finally {
        if ($canHide) {
            @shell_exec('stty echo');
            fwrite(STDOUT, PHP_EOL);
        }
    }

    return $line;
}

function mydump_confirm(string $question, bool $defaultNo = true): bool
{
    $suffix = $defaultNo ? ' [y/N]: ' : ' [Y/n]: ';
    $line = strtolower(trim(mydump_readline($question . $suffix)));

    if ($line === '') {
        return !$defaultNo;
    }
    return in_array($line, ['y', 'yes'], true);
}

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

function mydump_option_flag(array $options, array $aliases): bool
{
    foreach ($aliases as $alias) {
        $key = strtolower($alias);
        if (!isset($options[$key])) {
            continue;
        }

        foreach ((array) $options[$key] as $value) {
            if (is_bool($value)) {
                if ($value) {
                    return true;
                }
                continue;
            }

            $text = strtolower((string) $value);
            if ($text === '' || $text === '1' || $text === 'true' || $text === 'yes' || $text === 'on') {
                return true;
            }
        }
    }

    return false;
}

function mydump_option_value(array $options, array $aliases): ?string
{
    $all = mydump_option_values($options, $aliases);
    if (empty($all)) {
        return null;
    }
    return $all[count($all) - 1];
}

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

            // Allow comma-separated output values in a single option.
            $parts = array_map('trim', explode(',', $text));
            foreach ($parts as $part) {
                if ($part !== '') {
                    $values[] = $part;
                }
            }
        }
    }
    return $values;
}

function mydump_find_file_positional(array $positionals): ?string
{
    $all = mydump_find_file_positionals($positionals);
    return $all[0] ?? null;
}

function mydump_find_file_positionals(array $positionals): array
{
    $files = [];
    foreach ($positionals as $value) {
        $candidate = (string) $value;
        if ($candidate === '') {
            continue;
        }
        $ext = strtolower((string) pathinfo($candidate, PATHINFO_EXTENSION));
        if (in_array($ext, ['xlsx', 'json', 'js'], true)) {
            $files[] = $candidate;
        }
    }
    return mydump_unique_values($files);
}

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
