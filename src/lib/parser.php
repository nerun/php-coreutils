<?php

# PHP Coreutils
# A lightweight, pure-PHP implementation of classic Unix core utilities,
# designed for portability and environments without shell access.
#
# The MIT License
#
# Copyright (c) 2026 Daniel Dias Rodrigues
#
# Permission is hereby granted, free of charge, to any person obtaining a
# copy of this software and associated documentation files (the
# "Software"), to deal in the Software without restriction, including
# without limitation the rights to use, copy, modify, merge, publish,
# distribute, sublicense, and/or sell copies of the Software, and to
# permit persons to whom the Software is furnished to do so, subject to
# the following conditions:
#
# The above copyright notice and this permission notice shall be included
# in all copies or substantial portions of the Software.
#
# THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS
# OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
# MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
# IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
# CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT,
# TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE
# SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

require_once __DIR__ . '/errorHandling.php';
require_once __DIR__ . '/options.php';

/** Tokenize the supported quoting syntax; this does not execute or expand shell input. */
function coreutilsTokenize(string $input, bool $redirect = false): array
{
    $tokens = [];
    $operators = [];
    $current = '';
    $started = false;
    $quote = null;
    $length = strlen($input);
    if (strpos($input, "\0") !== false) {
        return [[], 'NUL bytes are not allowed'];
    }
    for ($i = 0; $i < $length; $i++) {
        $char = $input[$i];
        if ($char === '\\' && $quote !== "'") {
            if ($i + 1 === $length) {
                return [[], 'trailing escape character'];
            }
            $next = $input[$i + 1];
            if ($quote === '"' && !in_array($next, ['\\', '"', '$', chr(96), "\n"], true)) {
                $current .= $char;
                $started = true;
                continue;
            }
            $i++;
            if ($next !== "\n") {
                $current .= $next;
                $started = true;
            }
            continue;
        }
        if ($char === "'" || $char === '"') {
            if ($quote === null) {
                $quote = $char;
                $started = true;
                continue;
            }
            if ($quote === $char) {
                $quote = null;
                continue;
            }
        }
        if ($redirect && $quote === null && $char === '>') {
            if ($started) {
                $tokens[] = $current;
                $current = '';
                $started = false;
            }
            $operator = '>';
            if ($i + 1 < $length && $input[$i + 1] === '>') {
                $operator = '>>';
                $i++;
            }
            $operators[count($tokens)] = true;
            $tokens[] = $operator;
            continue;
        }
        if ($quote === null && strpos(" \t\r\n", $char) !== false) {
            if ($started) {
                $tokens[] = $current;
                $current = '';
                $started = false;
            }
            continue;
        }
        $current .= $char;
        $started = true;
    }
    if ($quote !== null) {
        return [[], 'unterminated quote'];
    }
    if ($started) {
        $tokens[] = $current;
    }
    return $redirect ? [$tokens, null, $operators] : [$tokens, null];
}

/** GNU echo accepts only leading clusters of n/e/E; other option-like words are text. */
function coreutilsParseEcho(array $tokens, array $operators): array
{
    $parsed = ['command' => 'echo', 'options' => [], 'args' => [], 'errors' => []];
    $words = [];
    for ($i = 1, $count = count($tokens); $i < $count; $i++) {
        if (!isset($operators[$i])) {
            $words[] = $tokens[$i];
            continue;
        }
        if (isset($parsed['redirect'])) {
            $parsed['errors'][] = coreutilsError('echo', 'invalid-redirection', 'only one output redirection is supported');
            return $parsed;
        }
        if ($i + 1 >= $count || isset($operators[$i + 1])) {
            $parsed['errors'][] = coreutilsError('echo', 'missing-redirect-path', 'output redirection requires a file');
            return $parsed;
        }
        $mode = $tokens[$i] === '>>' ? 'append' : 'overwrite';
        $parsed['redirect'] = ['path' => $tokens[++$i], 'mode' => $mode];
    }
    $posix = getenv('POSIXLY_CORRECT') !== false;
    $parsed['options']['posixly-correct'] = $posix;
    if (!$posix && count($words) === 1 && in_array($words[0], ['--help', '--version'], true)) {
        $parsed['options'][substr($words[0], 2)] = true;
        return $parsed;
    }
    $index = 0;
    if (!$posix || ($words[0] ?? null) === '-n') {
        while ($index < count($words)) {
            $word = $words[$index];
            if (strlen($word) < 2 || $word[0] !== '-' || strspn($word, 'neE', 1) !== strlen($word) - 1) {
                break;
            }
            foreach (str_split(substr($word, 1)) as $flag) {
                $name = ['n' => 'no-newline', 'e' => 'escapes', 'E' => 'literal'][$flag];
                unset($parsed['options'][$name]);
                $parsed['options'][$name] = true;
            }
            $index++;
        }
    }
    $parsed['args'] = array_slice($words, $index);
    return $parsed;
}

/** Return canonical input; find predicates and grep pattern sources retain repeated values. */
function parseCommand(string $input): array
{
    [$tokens, $error] = coreutilsTokenize($input);
    $command = array_shift($tokens) ?? '';
    $parsed = ['command' => $command, 'options' => [], 'args' => [], 'errors' => []];
    if ($error !== null || $command === '') {
        $parsed['errors'][] = coreutilsError('parser', 'syntax-error', $error ?? 'missing command');
        return $parsed;
    }
    if (str_starts_with($command, 'echo')) {
        [$echoTokens, , $operators] = coreutilsTokenize($input, true);
        if (($echoTokens[0] ?? null) === 'echo') {
            return coreutilsParseEcho($echoTokens, $operators);
        }
    }
    $definitions = coreutilsOptionDefinitions($command);
    if (!$definitions) {
        $parsed['errors'][] = coreutilsError($command, 'unknown-command', 'unsupported command');
        return $parsed;
    }
    $short = $long = [];
    foreach ($definitions as $name => [$s, $l, $takesValue]) {
        if ($s !== null) {
            $short[$s] = $name;
        }
        if ($l !== null) {
            $long[$l] = $name;
        }
    }
    $endOfOptions = false;
    $findPredicates = false;
    for ($i = 0, $count = count($tokens); $i < $count; $i++) {
        $token = $tokens[$i];
        if ($endOfOptions || $token === '' || $token === '-' || $token[0] !== '-') {
            if ($findPredicates) {
                $parsed['errors'][] = coreutilsError(
                    $command,
                    'unexpected-path',
                    'paths must precede -type and -name predicates',
                    $token
                );
                continue;
            }
            $parsed['args'][] = $token;
            // basename's second operand is a literal suffix, even if it starts with '-'.
            if ($command === 'basename') {
                $endOfOptions = true;
            }
            continue;
        }
        if ($token === '--') {
            $endOfOptions = true;
            continue;
        }
        $isLong = strncmp($token, '--', 2) === 0;
        $parts = $isLong ? explode('=', substr($token, 2), 2) : null;
        // find uses a multi-letter single-dash predicate, not a short-option cluster.
        $wholeShort = !$isLong && isset($short[substr($token, 1)]) && strlen($token) > 2;
        $spellings = $isLong ? [$parts[0]] : ($wholeShort ? [substr($token, 1)] : str_split(substr($token, 1)));
        foreach ($spellings as $j => $spelling) {
            $name = ($isLong ? $long : $short)[$spelling] ?? null;
            $label = ($isLong ? '--' : '-') . $spelling;
            if ($name === null) {
                $parsed['errors'][] = coreutilsError($command, 'invalid-option', "invalid option '$label'");
                break;
            }
            $takesValue = $definitions[$name][2];
            $attached = $isLong ? ($parts[1] ?? null)
                : (!$wholeShort && $takesValue && $j + 1 < count($spellings) ? substr($token, $j + 2) : null);
            if (!$takesValue && $attached !== null) {
                $parsed['errors'][] = coreutilsError($command, 'unexpected-value', "option '$label' takes no value");
                break;
            }
            $value = true;
            if ($takesValue) {
                $value = $attached;
                if ($value === null) {
                    if ($i + 1 >= $count || $tokens[$i + 1] === '--') {
                        $parsed['errors'][] = coreutilsError($command, 'missing-value', "option '$label' requires a value");
                        break;
                    }
                    $value = $tokens[++$i];
                }
            }
            if (($command === 'find' && in_array($name, ['type', 'name'], true))
                || ($command === 'grep' && in_array($name, ['regexp', 'file'], true))) {
                $findPredicates = $command === 'find';
                if (array_key_exists($name, $parsed['options'])) {
                    $previous = (array) $parsed['options'][$name];
                    $previous[] = $value;
                    $parsed['options'][$name] = $previous;
                } else {
                    $parsed['options'][$name] = $value;
                }
            } else {
                // Reinsert so mutually exclusive display options retain their last occurrence order.
                unset($parsed['options'][$name]);
                $parsed['options'][$name] = $value;
            }
            if ($takesValue) {
                break;
            }
        }
    }
    return $parsed;
}

/** Validate parsed or programmatically supplied input before any filesystem changes. */
function coreutilsValidateInput(string $command, array $input): array
{
    $errors = $input['errors'] ?? [];
    if (($input['command'] ?? $command) !== $command && !$errors) {
        $errors[] = coreutilsError($command, 'invalid-command', 'input belongs to a different command');
    }
    foreach (['flags', 'longFlags', 'flagsWithValue'] as $legacy) {
        if (array_key_exists($legacy, $input)) {
            $errors[] = coreutilsError($command, 'invalid-input', 'use canonical options or the current parseCommand()');
            break;
        }
    }
    $options = $input['options'] ?? [];
    $args = $input['args'] ?? [];
    if (!is_array($options) || !is_array($args)) {
        $errors[] = coreutilsError($command, 'invalid-input', 'options and args must be arrays');
        return [[], [], $errors];
    }
    $definitions = coreutilsOptionDefinitions($command);
    foreach ($options as $name => $value) {
        if (!isset($definitions[$name])) {
            $errors[] = coreutilsError($command, 'invalid-option', "invalid option '$name'");
        } elseif (($command === 'find' && in_array($name, ['type', 'name'], true))
            || ($command === 'grep' && in_array($name, ['regexp', 'file'], true))) {
            $values = is_array($value) ? $value : [$value];
            if (!$values) {
                $errors[] = coreutilsError($command, 'invalid-value', "option '$name' requires at least one value");
            }
            foreach ($values as $item) {
                if (!is_string($item) || strpos($item, "\0") !== false) {
                    $errors[] = coreutilsError($command, 'invalid-value', "invalid value for option '$name'");
                    break;
                }
            }
        } elseif ($definitions[$name][2] ? !is_string($value) : !is_bool($value)) {
            $errors[] = coreutilsError($command, 'invalid-value', "invalid value for option '$name'");
        }
    }
    $pathText = in_array($command, ['basename', 'dirname', 'echo'], true);
    $argumentIndex = 0;
    foreach ($args as $arg) {
        $patternText = $command === 'grep' && $argumentIndex++ === 0
            && !isset($options['regexp']) && !isset($options['file']);
        if ($pathText || $patternText) {
            if (!is_string($arg) || strpos($arg, "\0") !== false) {
                $errors[] = coreutilsError($command, 'invalid-path', 'operands must be strings without NUL bytes');
            }
            continue;
        }
        $error = coreutilsValidateLocalPath($command, $arg);
        if ($error !== null) {
            $errors[] = $error;
        }
    }
    return [$options, array_values($args), $errors];
}

/** Reuse filesystem-path validation for operands and echo's output target. */
function coreutilsValidateLocalPath(string $command, $path): ?array
{
    if (!is_string($path) || $path === '' || strpos($path, "\0") !== false) {
        return coreutilsError($command, 'invalid-path', 'paths must be nonempty strings without NUL bytes');
    }
    if (preg_match('~^[a-zA-Z][a-zA-Z0-9+.-]*://~', $path)) {
        return coreutilsError($command, 'invalid-path', 'only local filesystem paths are supported', $path);
    }
    if (DIRECTORY_SEPARATOR === '\\' && preg_match('~^[a-zA-Z]:(?![/\\\\])~', $path)) {
        return coreutilsError($command, 'invalid-path', 'drive-relative paths are not supported', $path);
    }
    return null;
}
