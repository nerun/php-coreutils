<?php

// PHP Coreutils - Copyright (c) 2026 Daniel Dias Rodrigues
// Distributed under the MIT License; see LICENSE.

require_once __DIR__ . '/lib/parser.php';
require_once __DIR__ . '/lib/filesystem.php';
require_once __DIR__ . '/lib/permissions.php';

/** Record requested and observed modes separately; the host may clear special bits. */
function coreutilsChmodChange(string $name, string $path, array $stat, int $requested, array &$result): void
{
    $before = $stat['mode'] & 07777;
    if (!coreutilsFsCall(fn () => chmod($path, $requested), $warning)) {
        coreutilsAddError($result, coreutilsFsError('chmod', 'change permissions of', $name, $warning));
        return;
    }
    clearstatcache(true, $path);
    $after = coreutilsFsCall(fn () => stat($path), $warning);
    $result['data']['entries'][] = ['name' => $name, 'path' => $path, 'before' => $before,
        'requested' => $requested, 'after' => $after === false ? null : $after['mode'] & 07777,
        'changed' => $after === false ? null : ($after['mode'] & 07777) !== $before];
    if ($after === false) {
        coreutilsAddError($result, coreutilsFsError('chmod', 'verify permissions of', $name, $warning));
    }
}

/** Grant directory access before traversal; defer restrictions until children are done. */
function _chmod(array $input, ?string $cwd = null): array
{
    $result = coreutilsResult('chmod', ['entries' => [], 'skipped' => []]);
    [$options, $args, $errors] = coreutilsValidateInput('chmod', $input);
    $result['options'] = $options;
    foreach ($errors as $error) {
        coreutilsAddError($result, $error, 2);
    }
    if ($result['status'] !== 0) {
        return $result;
    }
    if ($options['help'] ?? false) {
        $result['help'] = coreutilsHelp('chmod');
        return $result;
    }
    $reference = $options['reference'] ?? null;
    $mode = $options['mode'] ?? null;
    if ($reference !== null && $mode !== null) {
        coreutilsAddError($result, coreutilsError('chmod', 'conflicting-options', 'mode and reference cannot be combined'), 2);
        return $result;
    }
    if ($reference === null && $mode === null) {
        $mode = array_shift($args);
    }
    $compiled = $mode === null ? null : coreutilsChmodMode($mode);
    if ($reference === null && $compiled === null) {
        coreutilsAddError($result, coreutilsError('chmod', 'invalid-mode', 'a valid octal or symbolic mode is required'), 2);
    }
    if (!$args) {
        coreutilsAddError($result, coreutilsError('chmod', 'missing-operand', 'missing file operand'), 2);
    }
    if ($reference !== null) {
        $error = coreutilsValidateLocalPath('chmod', $reference);
        if ($error !== null) {
            coreutilsAddError($result, $error, 2);
        }
    }
    if ($result['status'] !== 0) {
        return $result;
    }
    $base = coreutilsWorkingDirectory($cwd, $warning);
    if ($base === false) {
        coreutilsAddError($result, coreutilsError('chmod', 'invalid-cwd', $warning));
        return $result;
    }
    if ($reference !== null) {
        $path = coreutilsResolvePath($reference, $base);
        clearstatcache(true, $path);
        $stat = coreutilsFsCall(fn () => stat($path), $warning);
        if ($stat === false) {
            coreutilsAddError($result, coreutilsFsError('chmod', 'read reference', $reference, $warning));
            return $result;
        }
        $compiled = ['numeric' => $stat['mode'] & 07777, 'operator' => '=', 'preserve' => false];
    }
    $mask = umask();
    $recursive = $options['recursive'] ?? false;
    $separators = DIRECTORY_SEPARATOR === '\\' ? '/\\' : '/';
    foreach ($args as $arg) {
        $stack = [[$arg, coreutilsResolvePath($arg, $base), true, false, []]];
        while ($stack) {
            [$name, $path, $explicit, $apply, $ancestors] = array_pop($stack);
            $stat = coreutilsLstat($path, $warning);
            if ($stat === false) {
                coreutilsAddError($result, coreutilsFsError('chmod', 'stat', $name, $warning));
                continue;
            }
            if (($stat['mode'] & 0170000) === 0120000) {
                if (!$explicit) {
                    $result['data']['skipped'][] = ['path' => $path, 'reason' => 'symbolic-link'];
                    continue;
                }
                $stat = coreutilsFsCall(fn () => stat($path), $warning);
                if ($stat === false) {
                    coreutilsAddError($result, coreutilsFsError('chmod', 'dereference', $name, $warning));
                    continue;
                }
            }
            $directory = ($stat['mode'] & 0170000) === 0040000;
            if (!$directory && (substr($path, -1) === '/'
                || (DIRECTORY_SEPARATOR === '\\' && substr($path, -1) === '\\'))) {
                coreutilsAddError($result, coreutilsFsError('chmod', 'stat', $name, 'Not a directory'));
                continue;
            }
            $requested = coreutilsChmodApply($compiled, $stat['mode'], $directory, $mask);
            if ($recursive && $directory && !$apply) {
                $physical = coreutilsFsCall(fn () => realpath($path), $warning);
                if ($physical === false) {
                    coreutilsAddError($result, coreutilsFsError('chmod', 'resolve directory', $name, $warning));
                    continue;
                }
                if (dirname($physical) === $physical) {
                    coreutilsAddError($result, coreutilsError('chmod', 'protected-path', 'refusing to operate recursively on a filesystem root', $name));
                    continue;
                }
                if (isset($ancestors[$physical])) {
                    coreutilsAddError($result, coreutilsError('chmod', 'directory-cycle', 'directory traversal cycle', $name));
                    continue;
                }
                if (($requested & 0555) & ~($stat['mode'] & 0555)) {
                    coreutilsChmodChange($name, $path, $stat, $requested, $result);
                } else {
                    $stack[] = [$name, $path, $explicit, true, $ancestors];
                }
                $children = coreutilsFsCall(fn () => scandir($path, SCANDIR_SORT_ASCENDING), $warning);
                if ($children === false) {
                    coreutilsAddError($result, coreutilsFsError('chmod', 'read directory', $name, $warning));
                    continue;
                }
                $ancestors[$physical] = true;
                foreach (array_reverse($children) as $child) {
                    if ($child !== '.' && $child !== '..') {
                        $stack[] = [rtrim($name, $separators) . DIRECTORY_SEPARATOR . $child,
                            rtrim($path, $separators) . DIRECTORY_SEPARATOR . $child, false, false, $ancestors];
                    }
                }
                continue;
            }
            coreutilsChmodChange($name, $path, $stat, $requested, $result);
        }
    }
    return $result;
}
