<?php

// PHP Coreutils - Copyright (c) 2026 Daniel Dias Rodrigues
// Distributed under the MIT License; see LICENSE.

require_once __DIR__ . '/ls.php';

/** Parse the supported GNU stat directives and bounded field widths once. */
function coreutilsStatFormat(string $format): ?array
{
    if (strpos($format, "\0") !== false) {
        return null;
    }
    $parts = [];
    for ($i = 0, $length = strlen($format); $i < $length;) {
        $percent = strpos($format, '%', $i);
        if ($percent === false) {
            $parts[] = substr($format, $i);
            break;
        }
        if ($percent > $i) {
            $parts[] = substr($format, $i, $percent - $i);
        }
        if (!preg_match('/\A%([-0]?)([0-9]*)([%aAbBdDfFgGhinoNrRsuUwWxXyYzZ])/', substr($format, $percent), $match)
            || strlen($match[2]) > 5 || (int) $match[2] > 8192) {
            return null;
        }
        $parts[] = ['flag' => $match[1], 'width' => (int) $match[2], 'code' => $match[3]];
        $i = $percent + strlen($match[0]);
    }
    return $parts;
}

/** Inspect entries without opening their contents; links themselves are the default. */
function _stat(array $input, ?string $cwd = null): array
{
    $result = coreutilsResult('stat', ['entries' => []]);
    [$options, $args, $errors] = coreutilsValidateInput('stat', $input);
    $result['options'] = $options;
    foreach ($errors as $error) {
        coreutilsAddError($result, $error, 2);
    }
    if ($result['status'] !== 0) {
        return $result;
    }
    if ($options['help'] ?? false) {
        $result['help'] = coreutilsHelp('stat');
        return $result;
    }
    if (!$args) {
        coreutilsAddError($result, coreutilsError('stat', 'missing-operand', 'missing file operand'), 2);
    }
    if (isset($options['format']) && coreutilsStatFormat($options['format']) === null) {
        coreutilsAddError($result, coreutilsError('stat', 'invalid-format', 'unsupported or incomplete format directive, NUL byte, or field width above 8192'), 2);
    }
    if ($result['status'] !== 0) {
        return $result;
    }
    $base = coreutilsWorkingDirectory($cwd, $warning);
    if ($base === false) {
        coreutilsAddError($result, coreutilsError('stat', 'invalid-cwd', $warning));
        return $result;
    }
    $types = [0100000 => ['f', 'regular file'], 0040000 => ['d', 'directory'],
        0120000 => ['l', 'symbolic link'], 0010000 => ['p', 'fifo'],
        0020000 => ['c', 'character special file'], 0060000 => ['b', 'block special file'],
        0140000 => ['s', 'socket']];
    foreach ($args as $arg) {
        $path = coreutilsResolvePath($arg, $base);
        $directoryOperand = substr($path, -1) === '/'
            || (DIRECTORY_SEPARATOR === '\\' && substr($path, -1) === '\\');
        $stat = ($options['dereference'] ?? false) || $directoryOperand
            ? coreutilsFsCall(function () use ($path) {
                clearstatcache(true, $path);
                return stat($path);
            }, $warning) : coreutilsLstat($path, $warning);
        if ($stat === false) {
            coreutilsAddError($result, coreutilsFsError('stat', 'stat', $arg, $warning));
            continue;
        }
        [$type, $description] = $types[$stat['mode'] & 0170000] ?? ['?', 'unknown'];
        if ($directoryOperand && $type !== 'd') {
            coreutilsAddError($result, coreutilsFsError('stat', 'stat', $arg, 'Not a directory'));
            continue;
        }
        $target = null;
        if ($type === 'l') {
            $target = coreutilsFsCall(fn () => readlink($path), $warning);
            if ($target === false) {
                coreutilsAddError($result, coreutilsFsError('stat', 'read symbolic link', $arg, $warning));
                $target = null;
            }
        }
        $entry = ['name' => $arg, 'path' => $path, 'type' => $type,
            'filetype' => $type === 'f' && $stat['size'] === 0 ? 'regular empty file' : $description,
            'permissions' => coreutilsSymbolicPerms($stat['mode']), 'target' => $target,
            'birthtime' => null];
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'rdev', 'size', 'atime', 'mtime', 'ctime'] as $key) {
            $entry[$key] = $stat[$key];
        }
        foreach (['blocks', 'blksize'] as $key) {
            $entry[$key] = isset($stat[$key]) && $stat[$key] >= 0 ? $stat[$key] : null;
        }
        $result['data']['entries'][] = $entry;
    }
    return $result;
}
