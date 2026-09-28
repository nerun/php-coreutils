<?php
// PHP Coreutils - Copyright (c) 2026 Daniel Dias Rodrigues
// Distributed under the MIT License; see LICENSE.

require_once __DIR__ . '/lib/parser.php';
require_once __DIR__ . '/lib/filesystem.php';

/** Return the working directory without printing or changing process state. */
function pwd(array $input, ?string $cwd = null): array {
    $result = coreutilsResult('pwd', ['path' => null]);
    [$options, $args, $errors] = coreutilsValidateInput('pwd', $input);
    $result['options'] = $options;
    foreach ($errors as $error) coreutilsAddError($result, $error, 2);
    if ($result['status'] !== 0) return $result;
    if ($options['help'] ?? false) {
        $result['help'] = coreutilsHelp('pwd');
        return $result;
    }
    if ($args) {
        coreutilsAddError($result, coreutilsError('pwd', 'unexpected-operand',
            'this command does not accept operands'), 2);
        return $result;
    }
    $logical = false;
    foreach ($options as $name => $enabled) {
        if ($enabled && ($name === 'logical' || $name === 'physical')) {
            $logical = $name === 'logical';
        }
    }

    $directory = $cwd ?? coreutilsFsCall(fn() => getcwd(), $warning);
    if ($directory === false) {
        coreutilsAddError($result, coreutilsError('pwd', 'invalid-cwd',
            $warning ?? 'Cannot determine working directory'));
        return $result;
    }
    // Refresh cached resolutions when a link has changed between calls.
    clearstatcache(true);
    $physical = coreutilsWorkingDirectory($directory, $warning);
    if ($physical === false) {
        coreutilsAddError($result, coreutilsError('pwd', 'invalid-cwd', $warning));
        return $result;
    }
    $result['data']['path'] = $physical;
    if (!$logical) return $result;

    // An explicit application cwd takes precedence over the process environment.
    $candidate = $cwd ?? getenv('PWD');
    if (!is_string($candidate) || $candidate === '' || strpos($candidate, "\0") !== false) return $result;
    $windows = DIRECTORY_SEPARATOR === '\\';
    $absolute = $windows
        ? preg_match('~^(?:[a-zA-Z]:[/\\\\]|[/\\\\]{2}[^/\\\\]+[/\\\\][^/\\\\]+)~', $candidate)
        : $candidate[0] === '/';
    if (!$absolute) return $result;
    $components = explode('/', $windows ? str_replace('\\', '/', $candidate) : $candidate);
    if (in_array('.', $components, true) || in_array('..', $components, true)) return $result;
    $resolved = coreutilsFsCall(fn() => realpath($candidate));
    if ($resolved !== false && ($windows ? strcasecmp($resolved, $physical) === 0 : $resolved === $physical)) {
        $result['data']['path'] = $candidate;
    }
    return $result;
}
