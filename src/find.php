<?php

// PHP Coreutils - Copyright (c) 2026 Daniel Dias Rodrigues
// Distributed under the MIT License; see LICENSE.

require_once __DIR__ . '/lib/parser.php';
require_once __DIR__ . '/lib/filesystem.php';

/** List matching entries without output, changing cwd or traversing symbolic links. */
function find(array $input, ?string $cwd = null): array
{
    $result = coreutilsResult('find', ['entries' => []]);
    [$options, $args, $errors] = coreutilsValidateInput('find', $input);
    $result['options'] = $options;
    foreach ($errors as $error) {
        coreutilsAddError($result, $error, 2);
    }
    if ($result['status'] !== 0) {
        return $result;
    }
    if ($options['help'] ?? false) {
        $result['help'] = coreutilsHelp('find');
        return $result;
    }
    $types = isset($options['type']) ? explode(',', $options['type']) : [];
    foreach ($types as $type) {
        if (!in_array($type, ['f', 'd', 'l'], true)) {
            coreutilsAddError($result, coreutilsError(
                'find',
                'invalid-type',
                'type must be f, d, l or a comma-separated list of these types'
            ), 2);
            return $result;
        }
    }
    $base = coreutilsWorkingDirectory($cwd, $warning);
    if ($base === false) {
        coreutilsAddError($result, coreutilsError('find', 'invalid-cwd', $warning));
        return $result;
    }
    $separators = DIRECTORY_SEPARATOR === '\\' ? '/\\' : '/';
    $typeCodes = [0100000 => 'f', 0040000 => 'd', 0120000 => 'l',
        0010000 => 'p', 0020000 => 'c', 0060000 => 'b', 0140000 => 's'];
    foreach ($args ?: ['.'] as $arg) {
        $stack = [[$arg, coreutilsResolvePath($arg, $base)]];
        // An explicit stack avoids PHP call-stack growth on deep directory trees.
        while ($stack) {
            [$name, $path] = array_pop($stack);
            $probe = rtrim($path, $separators);
            if ($probe === '' || (DIRECTORY_SEPARATOR === '\\' && strlen($probe) === 2 && $probe[1] === ':')) {
                $probe = $path;
            }
            $stat = coreutilsLstat($probe, $warning);
            if ($stat === false) {
                coreutilsAddError($result, coreutilsFsError('find', 'inspect', $name, $warning));
                continue;
            }
            $type = $typeCodes[$stat['mode'] & 0170000] ?? '?';
            if ($probe !== $path && $type !== 'd') {
                coreutilsAddError($result, coreutilsError(
                    'find',
                    'invalid-path',
                    "cannot inspect '$name': trailing separator requires a real directory",
                    $name
                ));
                continue;
            }
            if (!$types || in_array($type, $types, true)) {
                $result['data']['entries'][] = ['name' => $name, 'path' => $path, 'type' => $type];
            }
            if ($type !== 'd') {
                continue;
            }
            $children = coreutilsFsCall(fn () => scandir($path, SCANDIR_SORT_ASCENDING), $warning);
            if ($children === false) {
                coreutilsAddError($result, coreutilsFsError('find', 'read directory', $name, $warning));
                continue;
            }
            foreach (array_reverse($children) as $child) {
                if ($child === '.' || $child === '..') {
                    continue;
                }
                $stack[] = [rtrim($name, $separators) . DIRECTORY_SEPARATOR . $child,
                    rtrim($path, $separators) . DIRECTORY_SEPARATOR . $child];
            }
        }
    }
    return $result;
}
