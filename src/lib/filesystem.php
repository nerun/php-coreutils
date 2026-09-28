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

require_once __DIR__ . '/errorHandling.php';

function coreutilsWorkingDirectory(?string $cwd, ?string &$warning = null)
{
    if ($cwd === null) {
        $cwd = getcwd();
    }
    if ($cwd === false || $cwd === '' || strpos($cwd, "\0") !== false
        || preg_match('~^[a-zA-Z][a-zA-Z0-9+.-]*://~', $cwd)) {
        $warning = 'Invalid working directory';
        return false;
    }
    $resolved = coreutilsFsCall(fn () => realpath($cwd), $warning);
    if ($resolved === false || !coreutilsFsCall(fn () => is_dir($resolved), $warning)) {
        $warning = $warning ?? 'Working directory does not exist or is inaccessible';
        return false;
    }
    return $resolved;
}

/** Preserve the final component and .. semantics: realpath() would dereference links. */
function coreutilsResolvePath(string $path, string $cwd): string
{
    if (DIRECTORY_SEPARATOR === '\\') {
        if (preg_match('~^[a-zA-Z]:[/\\\\]|^[/\\\\]{2}~', $path)) {
            return $path;
        }
        if ($path[0] === '/' || $path[0] === '\\') {
            // A root-relative Windows path belongs to the drive/share of the explicit cwd.
            preg_match('~^(?:[a-zA-Z]:|[/\\\\]{2}[^/\\\\]+[/\\\\][^/\\\\]+)~', $cwd, $root);
            return ($root[0] ?? '') . $path;
        }
    } elseif ($path[0] === '/') {
        return $path;
    }
    return rtrim($cwd, DIRECTORY_SEPARATOR === '\\' ? '/\\' : '/') . DIRECTORY_SEPARATOR . $path;
}

function coreutilsLstat(string $path, ?string &$warning = null)
{
    clearstatcache(true, $path);
    return coreutilsFsCall(fn () => lstat($path), $warning);
}

function coreutilsIsDirectory(string $path): bool
{
    clearstatcache(true, $path);
    return coreutilsFsCall(fn () => is_dir($path));
}
