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
require_once __DIR__ . '/lib/locale.php';

function coreutilsSymbolicPerms(int $mode): string
{
    $types = [0140000 => 's', 0120000 => 'l', 0100000 => '-', 0060000 => 'b',
        0040000 => 'd', 0020000 => 'c', 0010000 => 'p'];
    $text = $types[$mode & 0170000] ?? '?';
    foreach ([[0400, 0200, 0100, 04000, 's', 'S'],
              [0040, 0020, 0010, 02000, 's', 'S'],
              [0004, 0002, 0001, 01000, 't', 'T']] as [$r, $w, $x, $special, $on, $off]) {
        $text .= ($mode & $r) ? 'r' : '-';
        $text .= ($mode & $w) ? 'w' : '-';
        $text .= ($mode & $special) ? (($mode & $x) ? $on : $off) : (($mode & $x) ? 'x' : '-');
    }
    return $text;
}

function coreutilsLsSettings(array $options): array
{
    $size = 'bytes';
    foreach ($options as $name => $enabled) {
        if ($enabled && ($name === 'human-readable' || $name === 'si')) {
            $size = $name;
        }
    }
    return [
        'long' => ($options['long'] ?? false) || ($options['omit-owner'] ?? false) || ($options['omit-group'] ?? false),
        'owner' => !($options['omit-owner'] ?? false),
        'group' => !(($options['omit-group'] ?? false) || ($options['no-group'] ?? false)),
        'size' => $size,
    ];
}

function coreutilsLsEntry(string $name, string $path, array $stat, array &$result): array
{
    $type = coreutilsSymbolicPerms($stat['mode']);
    $target = null;
    if ($type[0] === 'l') {
        $target = coreutilsFsCall(fn () => readlink($path), $warning);
        if ($target === false) {
            coreutilsAddError($result, coreutilsFsError('ls', 'read symbolic link', $name, $warning));
            $target = null;
        }
    }
    return [
        'name' => $name, 'path' => $path, 'permissions' => $type,
        'mode' => $stat['mode'], 'nlink' => $stat['nlink'],
        'uid' => $stat['uid'], 'gid' => $stat['gid'],
        'size' => $stat['size'], 'mtime' => $stat['mtime'],
        'blocks' => isset($stat['blocks']) && $stat['blocks'] >= 0 ? $stat['blocks'] : null,
        'target' => $target,
    ];
}

/** Return metadata with raw names and sizes. Presentation is handled by coreutilsText/Html. */
function ls(array $input, ?string $cwd = null, ?string $locale = null): array
{
    $result = coreutilsResult('ls', ['files' => [], 'directories' => []]);
    $result['locale'] = $locale;
    [$options, $args, $errors] = coreutilsValidateInput('ls', $input);
    $result['options'] = $options;
    foreach ($errors as $error) {
        coreutilsAddError($result, $error, 2);
    }
    if ($result['status'] !== 0) {
        return $result;
    }
    if ($options['help'] ?? false) {
        $result['help'] = coreutilsHelp('ls');
        return $result;
    }
    $base = coreutilsWorkingDirectory($cwd, $warning);
    if ($base === false) {
        coreutilsAddError($result, coreutilsError('ls', 'invalid-cwd', $warning));
        return $result;
    }
    $settings = coreutilsLsSettings($options);
    $collator = coreutilsCollator($locale);
    $compare = fn ($a, $b) => coreutilsCompare($a['name'], $b['name'], $collator);
    $directories = [];
    foreach ($args ?: ['.'] as $arg) {
        $path = coreutilsResolvePath($arg, $base);
        $stat = coreutilsLstat($path, $warning);
        if ($stat === false) {
            coreutilsAddError($result, coreutilsFsError('ls', 'access', $arg, $warning));
            continue;
        }
        $type = $stat['mode'] & 0170000;
        $last = substr($path, -1);
        $trailingSeparator = $last === '/' || (DIRECTORY_SEPARATOR === '\\' && $last === '\\');
        if ($trailingSeparator && !coreutilsIsDirectory($path)) {
            coreutilsAddError($result, coreutilsError('ls', 'not-directory', "not a directory: '$arg'", $arg));
            continue;
        }
        if ($type === 0040000 || ($type === 0120000
            && (!$settings['long'] || $trailingSeparator) && coreutilsIsDirectory($path))) {
            $directories[] = ['name' => $arg, 'path' => $path];
        } else {
            $result['data']['files'][] = coreutilsLsEntry($arg, $path, $stat, $result);
        }
    }
    usort($result['data']['files'], $compare);
    usort($directories, $compare);
    foreach ($directories as $directory) {
        $group = $directory + ['entries' => [], 'blocks' => 0, 'readable' => true];
        $items = coreutilsFsCall(fn () => scandir($directory['path'], SCANDIR_SORT_NONE), $warning);
        if ($items === false) {
            coreutilsAddError($result, coreutilsFsError('ls', 'open directory', $directory['name'], $warning));
            $group['blocks'] = null;
            $group['readable'] = false;
            $result['data']['directories'][] = $group;
            continue;
        }
        $items = array_values(array_filter($items, fn ($name) => ($options['all'] ?? false) || $name[0] !== '.'));
        usort($items, fn ($a, $b) => coreutilsCompare($a, $b, $collator));
        if ($options['group-directories-first'] ?? false) {
            $dirs = $files = [];
            foreach ($items as $name) {
                $full = $directory['path'] . DIRECTORY_SEPARATOR . $name;
                if (coreutilsIsDirectory($full)) {
                    $dirs[] = $name;
                } else {
                    $files[] = $name;
                }
            }
            $items = array_merge($dirs, $files);
        }
        foreach ($items as $name) {
            $path = $directory['path'] . DIRECTORY_SEPARATOR . $name;
            $stat = coreutilsLstat($path, $warning);
            if ($stat === false) {
                coreutilsAddError($result, coreutilsFsError('ls', 'access', $path, $warning));
                $group['blocks'] = null;
                continue;
            }
            $entry = coreutilsLsEntry($name, $path, $stat, $result);
            $group['entries'][] = $entry;
            $group['blocks'] = $group['blocks'] === null || $entry['blocks'] === null
                ? null : $group['blocks'] + $entry['blocks'];
        }
        $result['data']['directories'][] = $group;
    }
    return $result;
}
