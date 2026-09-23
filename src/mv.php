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

/** Rename local entries without dereferencing source links or copying across devices. */
function mv(array $input, ?string $cwd = null): array {
    $result = coreutilsResult('mv', ['moved' => [], 'skipped' => []]);
    [$options, $args, $errors] = coreutilsValidateInput('mv', $input);
    $result['options'] = $options;
    foreach ($errors as $error) coreutilsAddError($result, $error, 2);
    if ($errors) return $result;
    if ($options['help'] ?? false) {
        $result['help'] = coreutilsHelp('mv');
        return $result;
    }
    if (count($args) < 2) {
        coreutilsAddError($result, coreutilsError('mv', 'missing-operand', 'source and destination are required'), 2);
        return $result;
    }
    $cwd = coreutilsWorkingDirectory($cwd, $warning);
    if ($cwd === false) {
        coreutilsAddError($result, coreutilsError('mv', 'invalid-cwd', $warning));
        return $result;
    }
    $noClobber = false;
    foreach ($options as $name => $enabled) {
        if ($enabled && ($name === 'force' || $name === 'no-clobber')) $noClobber = $name === 'no-clobber';
    }
    $separators = DIRECTORY_SEPARATOR === '\\' ? '/\\' : '/';
    $destination = coreutilsResolvePath(array_pop($args), $cwd);
    $directory = coreutilsIsDirectory($destination);
    if (!$directory && (count($args) > 1 || rtrim($destination, $separators) !== $destination)) {
        coreutilsAddError($result, coreutilsError('mv', 'not-directory', "target '$destination' must be an existing directory", $destination));
        return $result;
    }
    $written = [];
    foreach ($args as $operand) {
        $raw = coreutilsResolvePath($operand, $cwd);
        $source = rtrim($raw, $separators);
        $name = basename($source);
        $stat = coreutilsLstat($source, $warning);
        if ($stat === false) {
            coreutilsAddError($result, coreutilsFsError('mv', 'stat', $operand, $warning));
            continue;
        }
        $isDirectory = ($stat['mode'] & 0170000) === 0040000;
        if ($source === '' || $name === '.' || $name === '..'
            || ($raw !== $source && !$isDirectory)) {
            coreutilsAddError($result, coreutilsError('mv', 'invalid-source', "cannot move '$operand': invalid source or trailing separator", $operand));
            continue;
        }
        $target = $directory ? rtrim($destination, $separators) . DIRECTORY_SEPARATOR . $name : $destination;
        $parent = coreutilsFsCall(fn() => realpath(dirname($target)));
        $sourceParent = coreutilsFsCall(fn() => realpath(dirname($source)));
        if ($parent === false || !coreutilsIsDirectory($parent)) {
            coreutilsAddError($result, coreutilsError('mv', 'invalid-parent', "destination parent does not exist: '$target'", $target));
            continue;
        }
        $targetKey = $parent . DIRECTORY_SEPARATOR . basename($target);
        $sourceKey = $sourceParent . DIRECTORY_SEPARATOR . $name;
        $targetStat = coreutilsLstat($target);
        $sameInode = $targetStat !== false && $stat['ino'] !== 0
            && $stat['ino'] === $targetStat['ino'] && $stat['dev'] === $targetStat['dev'];
        if ($sourceKey === $targetKey || $sameInode) {
            coreutilsAddError($result, coreutilsError('mv', 'same-file', "'$operand' and '$target' are the same file", $operand));
            continue;
        }
        if ($noClobber && $targetStat !== false) {
            $result['data']['skipped'][] = ['source' => $source, 'destination' => $target, 'reason' => 'destination-exists'];
            continue;
        }
        if (isset($written[$targetKey])) {
            coreutilsAddError($result, coreutilsError('mv', 'duplicate-target', "refusing to overwrite an earlier destination '$target'", $target));
            continue;
        }
        if ($isDirectory) {
            $canonical = coreutilsFsCall(fn() => realpath($source));
            $prefix = rtrim((string) $canonical, $separators) . DIRECTORY_SEPARATOR;
            $comparisonParent = $parent . DIRECTORY_SEPARATOR;
            if (DIRECTORY_SEPARATOR === '\\') {
                $prefix = strtolower($prefix);
                $comparisonParent = strtolower($comparisonParent);
            }
            if ($canonical === false || strpos($comparisonParent, $prefix) === 0) {
                coreutilsAddError($result, coreutilsError('mv', 'self-move', "cannot move '$operand' into itself", $operand));
                continue;
            }
        }
        if ($targetStat !== false && $isDirectory !== (($targetStat['mode'] & 0170000) === 0040000)) {
            coreutilsAddError($result, coreutilsError('mv', 'type-mismatch', "cannot replace '$target' with a different entry type", $target));
            continue;
        }
        $parentStat = coreutilsFsCall(fn() => stat($parent));
        if ($parentStat === false || $stat['dev'] !== $parentStat['dev']) {
            coreutilsAddError($result, coreutilsError('mv', 'cross-device', "cannot move '$operand': cross-filesystem moves are not supported", $operand));
            continue;
        }
        if (!coreutilsFsCall(fn() => rename($source, $target), $warning)) {
            coreutilsAddError($result, coreutilsFsError('mv', 'move to ' . $target, $operand, $warning));
            continue;
        }
        clearstatcache();
        $written[$targetKey] = true;
        $result['data']['moved'][] = ['source' => $source, 'destination' => $target];
    }
    return $result;
}
