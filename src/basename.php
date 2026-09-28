<?php
// PHP Coreutils - Copyright (c) 2026 Daniel Dias Rodrigues
// Distributed under the MIT License; see LICENSE.

require_once __DIR__ . '/lib/parser.php';
require_once __DIR__ . '/lib/pathnames.php';

/** Strip directory components and an optional suffix; cwd is intentionally unused. */
function _basename(array $input, ?string $cwd = null): array {
    $result = coreutilsResult('basename', ['entries' => []]);
    [$options, $args, $errors] = coreutilsValidateInput('basename', $input);
    $result['options'] = $options;
    foreach ($errors as $error) coreutilsAddError($result, $error, 2);
    if ($result['status'] !== 0) return $result;
    if ($options['help'] ?? false) {
        $result['help'] = coreutilsHelp('basename');
        return $result;
    }
    $multiple = ($options['multiple'] ?? false) || array_key_exists('suffix', $options);
    if (!$args || (!$multiple && count($args) > 2)) {
        coreutilsAddError($result, coreutilsError('basename', 'invalid-operands',
            $args ? 'expected NAME [SUFFIX]; use -a for multiple names' : 'missing operand'), 2);
        return $result;
    }
    $suffix = $options['suffix'] ?? '';
    if (!$multiple && count($args) === 2) $suffix = array_pop($args);
    if (strpos($suffix, "\0") !== false) {
        coreutilsAddError($result, coreutilsError('basename', 'invalid-value',
            'suffix must not contain NUL bytes'), 2);
        return $result;
    }
    foreach ($args as $arg) {
        [$name] = coreutilsPathnameParts($arg);
        // Removing the suffix must not remove the entire basename.
        if ($suffix !== '' && strlen($suffix) < strlen($name) && str_ends_with($name, $suffix)) {
            $name = substr($name, 0, -strlen($suffix));
        }
        $result['data']['entries'][] = ['input' => $arg, 'output' => $name];
    }
    return $result;
}
