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

require_once __DIR__ . '/parser.php';
require_once __DIR__ . '/filesystem.php';

/** Prove absence by listing an accessible parent; do not hide permission errors under -f. */
function coreutilsRemovalMissing(string $path): bool {
    $parent = dirname($path);
    if ($parent === $path) return false;
    if (coreutilsLstat($parent) === false) return coreutilsRemovalMissing($parent);
    if (!coreutilsIsDirectory($parent)) return false;
    $names = coreutilsFsCall(fn() => scandir($parent));
    return $names !== false && !in_array(basename($path), $names, true);
}

/** Refuse roots, dot operands, the explicit cwd and its ancestors. Final links stay links. */
function coreutilsRemovalProtected(string $path, string $cwd, bool $directory): bool {
    $name = basename($path);
    if ($path === '' || $name === '.' || $name === '..') return true;
    if (!$directory) return false;
    $canonical = coreutilsFsCall(fn() => realpath($path));
    if ($canonical === false) return true;
    if (dirname($canonical) === $canonical) return true;
    if (DIRECTORY_SEPARATOR === '\\') {
        $canonical = strtolower(str_replace('\\', '/', $canonical));
        $cwd = strtolower(str_replace('\\', '/', $cwd));
    }
    return $canonical === $cwd || strpos($cwd, rtrim($canonical, '/') . '/') === 0;
}

function coreutilsRemoveEntry(string $path, string $cwd, array &$result): void {
    $command = $result['command'];
    $separators = DIRECTORY_SEPARATOR === '\\' ? '/\\' : '/';
    $trimmed = rtrim($path, $separators);
    if ((DIRECTORY_SEPARATOR === '\\' && preg_match('/^[a-zA-Z]:$/', $trimmed))
        || $trimmed === '' || basename($trimmed) === '.' || basename($trimmed) === '..') {
        coreutilsAddError($result, coreutilsError($command, 'protected-path', "refusing to remove '$path'", $path));
        return;
    }
    $stat = coreutilsLstat($trimmed, $warning);
    if ($stat === false) {
        if ($command === 'rm' && ($result['options']['force'] ?? false) && coreutilsRemovalMissing($trimmed)) {
            $result['data']['skipped'][] = ['path' => $trimmed, 'reason' => 'not-found'];
        } else {
            coreutilsAddError($result, coreutilsFsError($command, 'stat', $path, $warning));
        }
        return;
    }
    $directory = ($stat['mode'] & 0170000) === 0040000;
    if (coreutilsRemovalProtected($trimmed, $cwd, $directory)) {
        coreutilsAddError($result, coreutilsError($command, 'protected-path', "refusing to remove root, working directory or its ancestor: '$path'", $path));
        return;
    }
    if (!$directory && ($command === 'rmdir' || $trimmed !== $path)) {
        coreutilsAddError($result, coreutilsError($command, 'not-directory', "not a real directory: '$path'", $path));
        return;
    }
    if ($directory && $command === 'rm') {
        if (!($result['options']['recursive'] ?? false)) {
            coreutilsAddError($result, coreutilsError($command, 'recursive-required', "cannot remove directory '$path'; use -r", $path));
            return;
        }
        $names = coreutilsFsCall(fn() => scandir($trimmed), $warning);
        if ($names === false) {
            coreutilsAddError($result, coreutilsFsError($command, 'read directory', $path, $warning));
            return;
        }
        $errorCount = count($result['errors']);
        foreach ($names as $name) {
            if ($name !== '.' && $name !== '..') coreutilsRemoveEntry($trimmed . DIRECTORY_SEPARATOR . $name, $cwd, $result);
        }
        if (count($result['errors']) !== $errorCount) return;
    }
    $success = $directory ? coreutilsFsCall(fn() => rmdir($trimmed), $warning)
        : coreutilsFsCall(fn() => unlink($trimmed), $warning);
    if (!$success) {
        coreutilsAddError($result, coreutilsFsError($command, 'remove', $path, $warning));
        return;
    }
    clearstatcache();
    $result['data']['removed'][] = $trimmed;
}

function coreutilsRemove(string $command, array $input, ?string $cwd): array {
    $result = coreutilsResult($command, ['removed' => [], 'skipped' => []]);
    [$options, $args, $errors] = coreutilsValidateInput($command, $input);
    $result['options'] = $options;
    foreach ($errors as $error) coreutilsAddError($result, $error, 2);
    if ($errors) return $result;
    if ($options['help'] ?? false) {
        $result['help'] = coreutilsHelp($command);
        return $result;
    }
    if (!$args) {
        if ($command !== 'rm' || !($options['force'] ?? false)) {
            coreutilsAddError($result, coreutilsError($command, 'missing-operand', 'at least one path is required'), 2);
        }
        return $result;
    }
    $cwd = coreutilsWorkingDirectory($cwd, $warning);
    if ($cwd === false) {
        coreutilsAddError($result, coreutilsError($command, 'invalid-cwd', $warning));
        return $result;
    }
    foreach ($args as $operand) {
        $path = coreutilsResolvePath($operand, $cwd);
        while (true) {
            $count = count($result['data']['removed']);
            coreutilsRemoveEntry($path, $cwd, $result);
            if ($command !== 'rmdir' || !($options['parents'] ?? false)
                || count($result['data']['removed']) === $count) break;
            $parent = dirname(rtrim($path, DIRECTORY_SEPARATOR === '\\' ? '/\\' : '/'));
            // Parent removal stops before the cwd or filesystem root, including aliases.
            if (coreutilsRemovalProtected($parent, $cwd, true)) break;
            $path = $parent;
        }
    }
    return $result;
}
