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

/** Parse [[CC]YY]MMDDhhmm[.ss] strictly in the application's timezone. */
function coreutilsTouchTimestamp(string $stamp): ?int
{
    if (!preg_match('/\A([0-9]{8}|[0-9]{10}|[0-9]{12})(?:\.([0-9]{2}))?\z/', $stamp, $parts)) {
        return null;
    }
    $digits = $parts[1];
    $seconds = $parts[2] ?? '00';
    if (strlen($digits) === 8) {
        $digits = date('Y') . $digits;
    } elseif (strlen($digits) === 10) {
        $digits = ((int) substr($digits, 0, 2) >= 69 ? '19' : '20') . $digits;
    }
    $digits .= $seconds;
    $date = DateTimeImmutable::createFromFormat('!YmdHis', $digits);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
        || $date->format('YmdHis') !== $digits) {
        return null;
    }
    return $date->getTimestamp();
}

/** Prove target absence for -c, following links without hiding access errors or loops. */
function coreutilsTouchMissing(string $path, int $depth = 0): bool
{
    if ($depth >= 40) {
        return false;
    }
    $stat = coreutilsLstat($path);
    if ($stat !== false) {
        if (($stat['mode'] & 0170000) !== 0120000) {
            return false;
        }
        $target = coreutilsFsCall(fn () => readlink($path));
        return $target !== false && $target !== ''
            && coreutilsTouchMissing(coreutilsResolvePath($target, dirname($path)), $depth + 1);
    }
    $parent = dirname($path);
    if ($parent === $path) {
        return false;
    }
    if (coreutilsLstat($parent) === false || !coreutilsIsDirectory($parent)) {
        return coreutilsTouchMissing($parent, $depth);
    }
    $names = coreutilsFsCall(fn () => scandir($parent));
    return $names !== false && !in_array(basename($path), $names, true);
}

/** Create empty files or update timestamps; never truncate content or change cwd. */
function _touch(array $input, ?string $cwd = null): array
{
    $result = coreutilsResult('touch', ['created' => [], 'updated' => [], 'skipped' => []]);
    [$options, $args, $errors] = coreutilsValidateInput('touch', $input);
    $result['options'] = $options;
    foreach ($errors as $error) {
        coreutilsAddError($result, $error, 2);
    }
    if ($result['status'] !== 0) {
        return $result;
    }
    if ($options['help'] ?? false) {
        $result['help'] = coreutilsHelp('touch');
        return $result;
    }
    if (!$args) {
        coreutilsAddError($result, coreutilsError('touch', 'missing-operand', 'missing file operand'), 2);
        return $result;
    }
    $hasReference = array_key_exists('reference', $options);
    $hasTimestamp = array_key_exists('timestamp', $options);
    if ($hasReference && $hasTimestamp) {
        coreutilsAddError($result, coreutilsError('touch', 'conflicting-options', 'cannot specify times from more than one source'), 2);
        return $result;
    }
    $now = time();
    $mtime = $atime = $now;
    if ($hasTimestamp) {
        $timestamp = coreutilsTouchTimestamp($options['timestamp']);
        if ($timestamp === null) {
            coreutilsAddError($result, coreutilsError('touch', 'invalid-timestamp', "invalid timestamp '{$options['timestamp']}'"), 2);
            return $result;
        }
        $mtime = $atime = $timestamp;
    }
    if ($hasReference) {
        [, , $errors] = coreutilsValidateInput('touch', ['args' => [$options['reference']]]);
        foreach ($errors as $error) {
            coreutilsAddError($result, $error, 2);
        }
        if ($result['status'] !== 0) {
            return $result;
        }
    }
    $base = coreutilsWorkingDirectory($cwd, $warning);
    if ($base === false) {
        coreutilsAddError($result, coreutilsError('touch', 'invalid-cwd', $warning));
        return $result;
    }
    if ($hasReference) {
        $reference = coreutilsResolvePath($options['reference'], $base);
        clearstatcache(true, $reference);
        $stat = coreutilsFsCall(fn () => stat($reference), $warning);
        if ($stat === false) {
            coreutilsAddError($result, coreutilsFsError('touch', 'read reference', $options['reference'], $warning));
            return $result;
        }
        $mtime = $stat['mtime'];
        $atime = $stat['atime'];
    }
    $access = $options['access'] ?? false;
    $modification = $options['modification'] ?? false;
    if (!$access && !$modification) {
        $access = $modification = true;
    }
    foreach ($args as $arg) {
        $path = coreutilsResolvePath($arg, $base);
        clearstatcache(true, $path);
        // stat() follows links, including a directory link, just like touch().
        $stat = coreutilsFsCall(fn () => stat($path), $warning);
        if ($stat === false && ($options['no-create'] ?? false)) {
            if (coreutilsTouchMissing($path)) {
                $result['data']['skipped'][] = ['path' => $path, 'reason' => 'not-found'];
            } else {
                coreutilsAddError($result, coreutilsFsError('touch', 'stat', $arg, $warning));
            }
            continue;
        }
        $newMtime = $modification ? $mtime : ($stat === false ? $now : $stat['mtime']);
        $newAtime = $access ? $atime : ($stat === false ? $now : $stat['atime']);
        $success = !$hasReference && !$hasTimestamp && $access && $modification
            ? coreutilsFsCall(fn () => touch($path), $warning)
            : coreutilsFsCall(fn () => touch($path, $newMtime, $newAtime), $warning);
        if (!$success) {
            coreutilsAddError($result, coreutilsFsError('touch', 'touch', $arg, $warning));
            continue;
        }
        clearstatcache(true, $path);
        $result['data'][$stat === false ? 'created' : 'updated'][] = $path;
    }
    return $result;
}
