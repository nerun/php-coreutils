<?php

// PHP Coreutils - Copyright (c) 2026 Daniel Dias Rodrigues
// Distributed under the MIT License; see LICENSE.

require_once __DIR__ . '/lib/parser.php';
require_once __DIR__ . '/lib/pathnames.php';

/** Strip the last path component; cwd is intentionally unused. */
function _dirname(array $input, ?string $cwd = null): array
{
    $result = coreutilsResult('dirname', ['entries' => []]);
    [$options, $args, $errors] = coreutilsValidateInput('dirname', $input);
    $result['options'] = $options;
    foreach ($errors as $error) {
        coreutilsAddError($result, $error, 2);
    }
    if ($result['status'] !== 0) {
        return $result;
    }
    if ($options['help'] ?? false) {
        $result['help'] = coreutilsHelp('dirname');
        return $result;
    }
    if (!$args) {
        coreutilsAddError($result, coreutilsError('dirname', 'missing-operand', 'missing operand'), 2);
        return $result;
    }
    foreach ($args as $arg) {
        [, $directory] = coreutilsPathnameParts($arg);
        $result['data']['entries'][] = ['input' => $arg, 'output' => $directory];
    }
    return $result;
}
