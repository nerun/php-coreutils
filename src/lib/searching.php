<?php

// PHP Coreutils - Copyright (c) 2026 Daniel Dias Rodrigues
// Distributed under the MIT License; see LICENSE.

require_once __DIR__ . '/reading.php';

/** Translate a byte-oriented GNU BRE/ERE subset; never expose PCRE-only operators. */
function coreutilsGrepExpression(string $pattern, bool $extended, ?string &$warning): ?string
{
    $warning = null;
    $output = '';
    $depth = 0;
    $start = true;
    $repeat = false;
    for ($i = 0, $length = strlen($pattern); $i < $length; $i++) {
        $char = $pattern[$i];
        if ($char === '[') {
            $class = '[';
            $j = $i + 1;
            if ($j < $length && $pattern[$j] === '^') {
                $class .= '^';
                $j++;
            }
            if ($j < $length && $pattern[$j] === ']') {
                $class .= ']';
                $j++;
            }
            for (; $j < $length && $pattern[$j] !== ']'; $j++) {
                if ($pattern[$j] === '[' && $j + 1 < $length && strpos('.=:', $pattern[$j + 1]) !== false) {
                    if ($pattern[$j + 1] !== ':') {
                        $warning = 'collating symbols and equivalence classes are not supported';
                        return null;
                    }
                    $end = strpos($pattern, ':]', $j + 2);
                    if ($end === false) {
                        break;
                    }
                    $name = substr($pattern, $j + 2, $end - $j - 2);
                    if (!in_array($name, ['alnum', 'alpha', 'blank', 'cntrl', 'digit', 'graph',
                        'lower', 'print', 'punct', 'space', 'upper', 'xdigit'], true)) {
                        $warning = 'unsupported POSIX character class';
                        return null;
                    }
                    $class .= substr($pattern, $j, $end + 2 - $j);
                    $j = $end + 1;
                } else {
                    // In GNU bracket expressions a backslash is an ordinary member.
                    $class .= $pattern[$j] === '\\' ? '\\\\' : $pattern[$j];
                }
            }
            if ($j >= $length || $pattern[$j] !== ']') {
                $warning = 'unclosed bracket expression';
                return null;
            }
            $output .= $class . ']';
            $i = $j;
            $start = $repeat = false;
            continue;
        }
        $escaped = false;
        if ($char === '\\') {
            if (++$i === $length) {
                $warning = 'trailing backslash in pattern';
                return null;
            }
            $char = $pattern[$i];
            $escaped = true;
            $special = ['w' => '[A-Za-z0-9_]', 'W' => '[^A-Za-z0-9_]',
                's' => '[[:space:]]', 'S' => '[^[:space:]]', 'b' => '\\b', 'B' => '\\B',
                '<' => '(?<![A-Za-z0-9_])(?=[A-Za-z0-9_])',
                '>' => '(?<=[A-Za-z0-9_])(?![A-Za-z0-9_])', '`' => '\\A', "'" => '\\z'];
            if (isset($special[$char])) {
                $output .= $special[$char];
                $start = $repeat = false;
                continue;
            }
            if ($char >= '1' && $char <= '9') {
                $output .= '\\g{' . $char . '}';
                $start = $repeat = false;
                continue;
            }
        }
        $operator = strpos('()+?|{}', $char) !== false && ($extended ? !$escaped : $escaped);
        if ($operator && $char === '(') {
            if ($extended && $i + 1 < $length && strpos('?*', $pattern[$i + 1]) !== false) {
                $warning = 'Perl group extensions are not supported';
                return null;
            }
            $output .= '(';
            $depth++;
            $start = true;
            $repeat = false;
        } elseif ($operator && $char === ')') {
            if ($depth === 0) {
                $warning = 'unmatched closing parenthesis';
                return null;
            } else {
                $output .= ')';
                $depth--;
            }
            $start = $repeat = false;
        } elseif ($operator && $char === '|') {
            $output .= '|';
            $start = true;
            $repeat = false;
        } elseif ($operator && $char === '{') {
            $tail = substr($pattern, $i + 1);
            $interval = $extended ? '/\A([0-9]*)(,([0-9]*))?\}/' : '/\A([0-9]*)(,([0-9]*))?\\\\\}/';
            if (!preg_match($interval, $tail, $parts) || ($parts[1] === '' && !isset($parts[2]))) {
                if ($extended && !preg_match('/\A[0-9,]/', $tail)) {
                    $output .= '\\{';
                    $start = $repeat = false;
                    continue;
                }
                $warning = 'invalid repetition interval';
                return null;
            }
            $minimum = $parts[1] === '' ? 0 : (int) $parts[1];
            $maximum = isset($parts[2]) ? (($parts[3] ?? '') === '' ? null : (int) $parts[3]) : $minimum;
            if ($start || $repeat || $minimum > 32767 || ($maximum !== null && ($maximum < $minimum || $maximum > 32767))) {
                $warning = 'invalid or excessive repetition interval';
                return null;
            }
            $output .= '{' . $minimum . (isset($parts[2]) ? ',' . ($maximum ?? '') : '') . '}';
            $i += strlen($parts[0]);
            $repeat = true;
        } elseif (($operator && strpos('+?', $char) !== false) || (!$escaped && $char === '*')) {
            if ($start && !$extended) {
                $output .= preg_quote($char);
                $start = $repeat = false;
            } elseif ($start || $repeat) {
                $warning = 'a repetition operator must follow a single expression';
                return null;
            } else {
                $output .= $char;
                $repeat = true;
            }
        } elseif (!$escaped && $char === '^' && ($extended || $start)) {
            $output .= '^';
        } elseif (!$escaped && $char === '$' && ($extended || $i + 1 === $length
            || substr($pattern, $i + 1, 2) === '\\)' || substr($pattern, $i + 1, 2) === '\\|')) {
            $output .= '$';
            $start = $repeat = false;
        } elseif (!$escaped && $char === '.') {
            $output .= '.';
            $start = $repeat = false;
        } else {
            $output .= preg_quote($char);
            $start = $repeat = false;
        }
    }
    if ($depth !== 0) {
        $warning = 'unclosed parenthesis';
        return null;
    }
    return $output;
}

/** Compile once, validating even patterns that a previous empty pattern would mask. */
function coreutilsGrepCompile(array $patterns, array $settings, array &$result): ?array
{
    $compiled = [];
    foreach ($patterns as $pattern) {
        if (strpos($pattern, "\0") !== false) {
            coreutilsAddError($result, coreutilsError('grep', 'invalid-pattern', 'NUL bytes in patterns are not supported'), 2);
            return null;
        }
        if ($settings['matcher'] === 'fixed') {
            $compiled[] = $settings['ignore-case'] ? strtr($pattern, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz') : $pattern;
            continue;
        }
        $expression = coreutilsGrepExpression($pattern, $settings['matcher'] === 'extended', $warning);
        if ($expression === null) {
            coreutilsAddError($result, coreutilsError('grep', 'invalid-pattern', $warning), 2);
            return null;
        }
        if ($settings['line']) {
            $expression = '\\A(?:' . $expression . ')\\z';
        } elseif ($settings['word']) {
            $expression = '(?<![A-Za-z0-9_])(?:' . $expression . ')(?![A-Za-z0-9_])';
        }
        $regex = '~' . str_replace('~', '\\~', $expression) . '~sD' . ($settings['ignore-case'] ? 'i' : '');
        if (coreutilsFsCall(fn () => preg_match($regex, ''), $warning) === false) {
            coreutilsAddError($result, coreutilsError('grep', 'invalid-pattern', $warning ?? preg_last_error_msg()), 2);
            return null;
        }
        $compiled[] = $regex;
    }
    return $compiled;
}

/** Match any pattern, using literal byte search for -F and ASCII word boundaries for -w. */
function coreutilsGrepMatch(string $line, array $patterns, array $settings, ?string &$warning): ?bool
{
    $warning = null;
    if ($settings['matcher'] === 'fixed' && $settings['ignore-case']) {
        $line = strtr($line, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }
    foreach ($patterns as $pattern) {
        if ($settings['matcher'] !== 'fixed') {
            $match = coreutilsFsCall(fn () => preg_match($pattern, $line), $warning);
            if ($match === false) {
                $warning = $warning ?? preg_last_error_msg();
                return null;
            }
            if ($match === 1) {
                return true;
            }
            continue;
        }
        if ($settings['line']) {
            if ($line === $pattern) {
                return true;
            }
            continue;
        }
        $offset = 0;
        while (($position = strpos($line, $pattern, $offset)) !== false) {
            $end = $position + strlen($pattern);
            $word = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_';
            if (!$settings['word'] || (($position === 0 || strpos($word, $line[$position - 1]) === false)
                && ($end === strlen($line) || strpos($word, $line[$end]) === false))) {
                return true;
            }
            if ($position === strlen($line)) {
                break;
            }
            $offset = $position + 1;
        }
    }
    return false;
}

/** Scan records with bounded blocks; memory grows with the longest record, not file size. */
function coreutilsGrepRecords($stream, int $size, string $delimiter, bool $binary, ?string &$warning): Generator
{
    $pending = '';
    while ($size > 0) {
        $chunk = coreutilsReadingChunk($stream, min(8192, $size), $warning);
        if ($chunk === false) {
            return false;
        }
        $size -= strlen($chunk);
        $parts = explode($delimiter, $binary ? str_replace("\0", "\n", $chunk) : $chunk);
        $last = count($parts) - 1;
        foreach ($parts as $index => $part) {
            $pending .= $part;
            if ($index !== $last) {
                yield $pending;
                $pending = '';
            }
        }
    }
    if ($pending !== '') {
        yield $pending;
    }
    return true;
}

/** Classify the whole file before emitting output, so later NUL bytes cannot retract it. */
function coreutilsGrepBinary($stream, int $size, ?string &$warning): ?bool
{
    $binary = false;
    while ($size > 0) {
        $chunk = coreutilsReadingChunk($stream, min(8192, $size), $warning);
        if ($chunk === false) {
            return null;
        }
        $size -= strlen($chunk);
        if (strpos($chunk, "\0") !== false) {
            $binary = true;
            break;
        }
    }
    if (coreutilsFsCall(fn () => fseek($stream, 0), $warning) !== 0) {
        return null;
    }
    return $binary;
}

/** Split newline-separated patterns; a final pattern-file newline adds no empty pattern. */
function coreutilsGrepPatterns(string $text, bool $file): array
{
    if ($file && $text === '') {
        return [];
    }
    if ($file && substr($text, -1) === "\n") {
        $text = substr($text, 0, -1);
    }
    return explode("\n", $text);
}

/** Yield regular-file candidates; explicit links follow, nested links follow only with -R. */
function coreutilsGrepPaths(array $args, string $base, array $settings, array &$result): Generator
{
    $separators = DIRECTORY_SEPARATOR === '\\' ? '/\\' : '/';
    foreach ($args as $arg) {
        $stack = [['name' => $arg, 'path' => coreutilsResolvePath($arg, $base), 'nested' => false]];
        $active = [];
        while ($stack) {
            $entry = array_pop($stack);
            if (isset($entry['leave'])) {
                unset($active[$entry['leave']]);
                continue;
            }
            $path = $entry['path'];
            $stat = coreutilsLstat($path, $warning);
            if ($stat !== false && ($stat['mode'] & 0170000) === 0120000) {
                if ($entry['nested'] && $settings['recursive'] !== 'dereference') {
                    continue;
                }
                $stat = coreutilsFsCall(function () use ($path) {
                    clearstatcache(true, $path);
                    return stat($path);
                }, $warning);
            }
            if ($stat === false) {
                coreutilsAddError($result, coreutilsFsError('grep', 'inspect', $entry['name'], $warning), 2);
                continue;
            }
            $type = $stat['mode'] & 0170000;
            if ($type === 0040000 && $settings['recursive'] !== null) {
                $identity = coreutilsFsCall(fn () => realpath($path), $warning);
                if ($identity === false) {
                    coreutilsAddError($result, coreutilsFsError('grep', 'resolve directory', $entry['name'], $warning), 2);
                    continue;
                }
                if (isset($active[$identity])) {
                    // Keep cycle diagnostics separate from filesystem errors, as GNU grep does.
                    $result['data']['warnings'][] = coreutilsError(
                        'grep',
                        'directory-cycle',
                        "recursive directory loop at '" . $entry['name'] . "'",
                        $entry['name']
                    );
                    continue;
                }
                $children = coreutilsFsCall(fn () => scandir($path, SCANDIR_SORT_ASCENDING), $warning);
                if ($children === false) {
                    coreutilsAddError($result, coreutilsFsError('grep', 'read directory', $entry['name'], $warning), 2);
                    continue;
                }
                $active[$identity] = true;
                $stack[] = ['leave' => $identity];
                foreach (array_reverse($children) as $child) {
                    if ($child !== '.' && $child !== '..') {
                        $parent = $entry['name'] === '.' && $settings['implicit-root'] ? ''
                            : rtrim($entry['name'], $separators) . DIRECTORY_SEPARATOR;
                        $stack[] = ['name' => $parent . $child,
                            'path' => rtrim($path, $separators) . DIRECTORY_SEPARATOR . $child, 'nested' => true];
                    }
                }
            } elseif ($entry['nested'] && $type !== 0100000) {
                // Recursive searches skip devices, sockets and FIFOs without opening them.
                continue;
            } else {
                yield $entry;
            }
        }
    }
}
