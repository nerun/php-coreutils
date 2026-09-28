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

require_once __DIR__ . '/lib/parser.php';
require_once __DIR__ . '/lib/filesystem.php';

/** Copy local files or recursively copy directories; never prints output. */
function cp(array $input, ?string $cwd = null): array
{
    $result = coreutilsResult('cp', ['copied' => [], 'created' => [], 'skipped' => []]);
    [$options, $args, $errors] = coreutilsValidateInput('cp', $input);
    $result['options'] = $options;
    foreach ($errors as $error) {
        coreutilsAddError($result, $error, 2);
    }
    if ($errors) {
        return $result;
    }
    if ($options['help'] ?? false) {
        $result['help'] = coreutilsHelp('cp');
        return $result;
    }
    if (count($args) < 2) {
        coreutilsAddError($result, coreutilsError('cp', 'missing-operand', 'source and destination are required'), 2);
        return $result;
    }
    $cwd = coreutilsWorkingDirectory($cwd, $warning);
    if ($cwd === false) {
        coreutilsAddError($result, coreutilsError('cp', 'invalid-cwd', $warning));
        return $result;
    }
    $separators = DIRECTORY_SEPARATOR === '\\' ? '/\\' : '/';
    $destination = coreutilsResolvePath(array_pop($args), $cwd);
    $directory = coreutilsIsDirectory($destination);
    if (!$directory && (count($args) > 1 || rtrim($destination, $separators) !== $destination)) {
        coreutilsAddError($result, coreutilsError('cp', 'not-directory', "target '$destination' must be an existing directory", $destination));
        return $result;
    }
    $written = [];
    foreach ($args as $operand) {
        $raw = coreutilsResolvePath($operand, $cwd);
        $source = rtrim($raw, $separators);
        $name = basename($source);
        // Keep the final entry unambiguous; never silently dereference a slash-suffixed link.
        if ($source === '' || $name === '.' || $name === '..') {
            coreutilsAddError($result, coreutilsError('cp', 'invalid-source', "use a named source entry instead of '$operand'", $operand));
            continue;
        }
        if ($raw !== $source) {
            $stat = coreutilsLstat($source);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0040000) {
                coreutilsAddError($result, coreutilsError('cp', 'invalid-source', "trailing separator requires a real directory: '$operand'", $operand));
                continue;
            }
        }
        $target = $directory ? rtrim($destination, $separators) . DIRECTORY_SEPARATOR . $name : $destination;
        coreutilsCopyEntry($source, $target, $result, $written);
    }
    return $result;
}

/** Separator-aware comparison of canonical paths, including Windows case folding. */
function coreutilsCopyWithin(string $path, string $directory): bool
{
    if (DIRECTORY_SEPARATOR === '\\') {
        $path = strtolower(str_replace('\\', '/', $path));
        $directory = strtolower(str_replace('\\', '/', $directory));
    }
    $directory = rtrim($directory, '/');
    return $path === $directory || strpos($path, $directory . '/') === 0;
}

function coreutilsCopyEntry(string $source, string $target, array &$result, array &$written): void
{
    $recursive = $result['options']['recursive'] ?? false;
    $stat = coreutilsLstat($source, $warning);
    if ($stat === false) {
        coreutilsAddError($result, coreutilsFsError('cp', 'stat', $source, $warning));
        return;
    }
    $type = $stat['mode'] & 0170000;
    // Ordinary cp follows a source link; recursive cp reproduces the link itself.
    if ($type === 0120000 && !$recursive) {
        $stat = coreutilsFsCall(fn () => stat($source), $warning);
        if ($stat === false) {
            coreutilsAddError($result, coreutilsFsError('cp', 'stat link target', $source, $warning));
            return;
        }
        $type = $stat['mode'] & 0170000;
    }
    if (!in_array($type, [0100000, 0040000, 0120000], true)) {
        coreutilsAddError($result, coreutilsError('cp', 'unsupported-type', "special files are not supported: '$source'", $source));
        return;
    }
    if ($type === 0040000 && !$recursive) {
        coreutilsAddError($result, coreutilsError('cp', 'recursive-required', "omitting directory '$source'; use -r", $source));
        return;
    }
    $parent = coreutilsFsCall(fn () => realpath(dirname($target)));
    if ($parent === false || !coreutilsIsDirectory($parent)) {
        coreutilsAddError($result, coreutilsError('cp', 'invalid-parent', "destination parent does not exist: '$target'", $target));
        return;
    }
    $key = $parent . DIRECTORY_SEPARATOR . basename($target);
    if (DIRECTORY_SEPARATOR === '\\') {
        $key = strtolower($key);
    }
    $targetStat = coreutilsLstat($target);
    $targetType = $targetStat === false ? null : $targetStat['mode'] & 0170000;
    $canonical = coreutilsFsCall(fn () => realpath($source));
    $sameInode = $targetStat !== false && $stat['ino'] !== 0
        && $stat['ino'] === $targetStat['ino'] && $stat['dev'] === $targetStat['dev'];
    if ($sameInode || ($canonical !== false && coreutilsCopyWithin($key, $canonical)
        && ($type === 0040000 || coreutilsCopyWithin($canonical, $key)))) {
        coreutilsAddError($result, coreutilsError('cp', 'self-copy', "cannot copy '$source' onto itself or into itself", $source));
        return;
    }
    $pair = ['source' => $source, 'destination' => $target];
    // Existing directories are merged even with -n; their existing leaves are skipped.
    if (($result['options']['no-clobber'] ?? false) && $targetStat !== false
        && !($type === 0040000 && $targetType === 0040000)) {
        $result['data']['skipped'][] = $pair + ['reason' => 'destination-exists'];
        return;
    }
    if (isset($written[$key]) && !($type === 0040000 && $targetType === 0040000)) {
        coreutilsAddError($result, coreutilsError('cp', 'duplicate-target', "refusing to overwrite an earlier destination '$target'", $target));
        return;
    }
    // Do not write through destination symlinks or open special files (e.g. FIFOs).
    if ($targetStat !== false && ($targetType !== $type || $targetType === 0120000)) {
        coreutilsAddError($result, coreutilsError('cp', 'type-mismatch', "cannot replace destination type at '$target'", $target));
        return;
    }
    if ($type === 0040000) {
        $entries = coreutilsFsCall(fn () => scandir($source), $warning);
        if ($entries === false) {
            coreutilsAddError($result, coreutilsFsError('cp', 'read directory', $source, $warning));
            return;
        }
        if ($targetStat === false) {
            if (!coreutilsFsCall(fn () => mkdir($target, 0777), $warning)) {
                coreutilsAddError($result, coreutilsFsError('cp', 'create directory', $target, $warning));
                return;
            }
            $result['data']['created'][] = $pair;
        }
        foreach ($entries as $name) {
            if ($name !== '.' && $name !== '..') {
                coreutilsCopyEntry($source . DIRECTORY_SEPARATOR . $name, $target . DIRECTORY_SEPARATOR . $name, $result, $written);
            }
        }
        return;
    }
    if ($type === 0120000) {
        $link = coreutilsFsCall(fn () => readlink($source), $warning);
        if ($link === false) {
            coreutilsAddError($result, coreutilsFsError('cp', 'read link', $source, $warning));
            return;
        }
        if (!function_exists('symlink')) {
            coreutilsAddError($result, coreutilsError('cp', 'unsupported-link', 'symlink() is unavailable', $source));
            return;
        }
        $success = coreutilsFsCall(fn () => symlink($link, $target), $warning);
    } else {
        $success = coreutilsFsCall(fn () => copy($source, $target), $warning);
    }
    if (!$success) {
        coreutilsAddError($result, coreutilsFsError('cp', 'copy to ' . $target, $source, $warning));
        return;
    }
    clearstatcache();
    $written[$key] = true;
    $result['data']['copied'][] = $pair;
}
