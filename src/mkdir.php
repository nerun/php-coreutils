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

/** Create missing parents with default permissions, retaining owner write/search access. */
function coreutilsMakeParents(string $path, array &$created, ?string &$warning): bool
{
    if (coreutilsIsDirectory($path)) {
        return true;
    }
    $parent = dirname($path);
    if ($parent !== $path && !coreutilsMakeParents($parent, $created, $warning)) {
        return false;
    }
    if (coreutilsIsDirectory($path)) {
        return true;
    }
    if (!coreutilsFsCall(fn () => mkdir($path, 0777), $warning)) {
        return coreutilsIsDirectory($path); // Another caller may have created the parent.
    }
    $created[] = $path;
    $stat = coreutilsLstat($path, $warning);
    if ($stat === false) {
        return false;
    }
    if (DIRECTORY_SEPARATOR !== '\\' && ($stat['mode'] & 0300) !== 0300) {
        $mode = ($stat['mode'] & 07777) | 0300;
        return coreutilsFsCall(fn () => chmod($path, $mode), $warning);
    }
    return true;
}

/** Return created/existing paths, errors and status. No output, chdir() or session access. */
function _mkdir(array $input, ?string $cwd = null): array
{
    $result = coreutilsResult('mkdir', ['created' => [], 'existing' => []]);
    [$options, $args, $errors] = coreutilsValidateInput('mkdir', $input);
    $result['options'] = $options;
    foreach ($errors as $error) {
        coreutilsAddError($result, $error, 2);
    }
    if ($result['status'] !== 0) {
        return $result;
    }
    if ($options['help'] ?? false) {
        $result['help'] = coreutilsHelp('mkdir');
        return $result;
    }
    if (!$args) {
        coreutilsAddError($result, coreutilsError('mkdir', 'missing-operand', 'missing directory operand'), 2);
        return $result;
    }
    $explicitMode = array_key_exists('mode', $options);
    if ($explicitMode && !preg_match('/\A[0-7]{3,4}\z/', $options['mode'])) {
        coreutilsAddError($result, coreutilsError('mkdir', 'invalid-mode', "invalid mode '{$options['mode']}'"), 2);
        return $result;
    }
    $mode = $explicitMode ? octdec($options['mode']) : 0777;
    $base = coreutilsWorkingDirectory($cwd, $warning);
    if ($base === false) {
        coreutilsAddError($result, coreutilsError('mkdir', 'invalid-cwd', $warning));
        return $result;
    }
    $recursive = $options['parents'] ?? false;
    foreach ($args as $arg) {
        $path = coreutilsResolvePath($arg, $base);
        if ($recursive && coreutilsIsDirectory($path)) {
            $result['data']['existing'][] = $path;
            continue;
        }
        if ($recursive && !coreutilsMakeParents(dirname($path), $result['data']['created'], $warning)) {
            coreutilsAddError($result, coreutilsFsError('mkdir', 'create directory', $arg, $warning));
            continue;
        }
        if (!coreutilsFsCall(fn () => mkdir($path, $mode), $warning)) {
            if ($recursive && coreutilsIsDirectory($path)) {
                $result['data']['existing'][] = $path;
            } else {
                coreutilsAddError($result, coreutilsFsError('mkdir', 'create directory', $arg, $warning));
            }
            continue;
        }
        $result['data']['created'][] = $path;
        // Only an explicit -m overrides the umask. Never chmod an existing directory.
        if ($explicitMode && !coreutilsFsCall(fn () => chmod($path, $mode), $warning)) {
            coreutilsAddError($result, coreutilsFsError('mkdir', 'set permissions on', $arg, $warning));
        }
    }
    return $result;
}
