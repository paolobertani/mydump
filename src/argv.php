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
        }
    }

    $rawOptions = [];
    $positionals = [];
    $flags = ['run', 'help', 'h', '?'];

    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $token = (string) $tokens[$i];

        if ($token === '--') {
            for ($j = $i + 1; $j < $n; $j++) {
                $positionals[] = (string) $tokens[$j];
            }
            break;
        }

        if ($token === '' || $token === '-') {
            $positionals[] = $token;
            continue;
        }

        if ($token[0] !== '-') {
            $positionals[] = $token;
            continue;
        }

        $trimmed = ltrim($token, '-');
        if ($trimmed === '') {
            $positionals[] = $token;
            continue;
        }

        $name = $trimmed;
        $value = true;
        if (str_contains($trimmed, '=')) {
            [$name, $value] = explode('=', $trimmed, 2);
        } elseif (!in_array(strtolower($trimmed), $flags, true)) {
            $next = $tokens[$i + 1] ?? null;
            if ($next !== null && (string) $next !== '--') {
                $value = (string) $next;
                $i++;
            } else {
                $value = null;
            }
        }

        $rawOptions[strtolower($name)] = $value;
    }

    $options = [
        'host' => mydump_option_value($rawOptions, ['host', 'h']),
        'port' => mydump_option_value($rawOptions, ['port', 'p']),
        'user' => mydump_option_value($rawOptions, ['user', 'u']),
        'pass' => mydump_option_value($rawOptions, ['pass', 'password']),
        'db' => mydump_option_value($rawOptions, ['db', 'database']),
        'output' => mydump_option_value($rawOptions, ['o', 'output']),
        'run' => mydump_option_flag($rawOptions, ['run']),
        'help' => mydump_option_flag($rawOptions, ['help', 'h', '?']),
        'positionals' => $positionals,
    ];

    $mode = $command;
    if ($mode === null) {
        if (!empty($options['output'])) {
            $mode = 'dump';
        } else {
            $firstDataFile = mydump_find_file_positional($positionals);
            if ($firstDataFile !== null) {
                $mode = 'write';
            }
        }
    }

    $input = null;
    if ($mode === 'write') {
        $input = mydump_find_file_positional($positionals);
    }
    if ($mode === 'dump' && empty($options['output'])) {
        $maybeOutput = mydump_find_file_positional($positionals);
        if ($maybeOutput !== null) {
            $options['output'] = $maybeOutput;
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
mydump - MySQL structure <-> JSON/XLSX

Usage:
  php mydump.php dump -o output.xlsx [options]
  php mydump.php dump -o output.json [options]
  php mydump.php write input.xlsx [options]
  php mydump.php write input.json [options]

Shortcuts:
  php mydump.php -o output.xlsx [options]      # same as dump
  php mydump.php input.xlsx [options]          # same as write

Options:
  -host <host>        MySQL host (default 127.0.0.1)
  -port <port>        MySQL port (default 3306)
  -user <user>        MySQL user (default root)
  -pass <password>    MySQL password
  -db <database>      Database name
  -o <file>           Output file in dump mode (.xlsx/.json/.js)
  --run               In write mode, skip confirmation prompt before ALTER
  -help               Show this message
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
        if (array_key_exists($key, $options)) {
            $value = $options[$key];
            if (is_bool($value)) {
                return $value;
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
    foreach ($aliases as $alias) {
        $key = strtolower($alias);
        if (array_key_exists($key, $options)) {
            $value = $options[$key];
            if ($value === true || $value === null) {
                return null;
            }
            return (string) $value;
        }
    }
    return null;
}

function mydump_find_file_positional(array $positionals): ?string
{
    foreach ($positionals as $value) {
        $candidate = (string) $value;
        if ($candidate === '') {
            continue;
        }
        $ext = strtolower((string) pathinfo($candidate, PATHINFO_EXTENSION));
        if (in_array($ext, ['xlsx', 'json', 'js'], true)) {
            return $candidate;
        }
    }
    return null;
}
