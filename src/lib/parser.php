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
function coreutilsTokenize(string $input): array {
    $tokens = [];
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
    return [$tokens, null];
}

/** Return command, canonical options, operands and syntax errors. Last alias wins. */
function parseCommand(string $input): array {
    [$tokens, $error] = coreutilsTokenize($input);
    $command = array_shift($tokens) ?? '';
    $parsed = ['command' => $command, 'options' => [], 'args' => [], 'errors' => []];
    if ($error !== null || $command === '') {
        $parsed['errors'][] = coreutilsError('parser', 'syntax-error', $error ?? 'missing command');
        return $parsed;
    }
    $definitions = coreutilsOptionDefinitions($command);
    if (!$definitions) {
        $parsed['errors'][] = coreutilsError($command, 'unknown-command', 'unsupported command');
        return $parsed;
    }
    $short = $long = [];
    foreach ($definitions as $name => [$s, $l, $takesValue]) {
        if ($s !== null) $short[$s] = $name;
        if ($l !== null) $long[$l] = $name;
    }
    $endOfOptions = false;
    for ($i = 0, $count = count($tokens); $i < $count; $i++) {
        $token = $tokens[$i];
        if ($endOfOptions || $token === '' || $token === '-' || $token[0] !== '-') {
            $parsed['args'][] = $token;
            continue;
        }
        if ($token === '--') {
            $endOfOptions = true;
            continue;
        }
        $isLong = strncmp($token, '--', 2) === 0;
        $parts = $isLong ? explode('=', substr($token, 2), 2) : null;
        $spellings = $isLong ? [$parts[0]] : str_split(substr($token, 1));
        foreach ($spellings as $j => $spelling) {
            $name = ($isLong ? $long : $short)[$spelling] ?? null;
            $label = ($isLong ? '--' : '-') . $spelling;
            if ($name === null) {
                $parsed['errors'][] = coreutilsError($command, 'invalid-option', "invalid option '$label'");
                break;
            }
            $takesValue = $definitions[$name][2];
            $attached = $isLong ? ($parts[1] ?? null)
                : ($takesValue && $j + 1 < count($spellings) ? substr($token, $j + 2) : null);
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
            // Reinsert so mutually exclusive display options retain their last occurrence order.
            unset($parsed['options'][$name]);
            $parsed['options'][$name] = $value;
            if ($takesValue) break;
        }
    }
    return $parsed;
}

/** Validate parsed or programmatically supplied input before any filesystem changes. */
function coreutilsValidateInput(string $command, array $input): array {
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
        } elseif ($definitions[$name][2] ? !is_string($value) : !is_bool($value)) {
            $errors[] = coreutilsError($command, 'invalid-value', "invalid value for option '$name'");
        }
    }
    foreach ($args as $arg) {
        if (!is_string($arg) || $arg === '' || strpos($arg, "\0") !== false) {
            $errors[] = coreutilsError($command, 'invalid-path', 'paths must be nonempty strings without NUL bytes');
        } elseif (preg_match('~^[a-zA-Z][a-zA-Z0-9+.-]*://~', $arg)) {
            $errors[] = coreutilsError($command, 'invalid-path', 'only local filesystem paths are supported', $arg);
        } elseif (DIRECTORY_SEPARATOR === '\\' && preg_match('~^[a-zA-Z]:(?![/\\\\])~', $arg)) {
            $errors[] = coreutilsError($command, 'invalid-path', 'drive-relative paths are not supported', $arg);
        }
    }
    return [$options, array_values($args), $errors];
}
