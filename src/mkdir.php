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

// ====== Bootstrap ======

require_once __DIR__ . '/lib/locale.php';
require_once __DIR__ . '/lib/errorHandling.php';

// ====== Auxiliary functions ======


// ====== Main logic ======

function _mkdir($input) {
    //$command = $input['command']; // always 'mkdir' here
    $flags = $input['flags'];
    $longFlags = $input['longFlags'];
    $flagsWithValue = $input['flagsWithValue'];
    $args = $input['args'];
    
    $validOptions = ['m', 'p', 'mode'];
    if (!validateOptions('mkdir', $validOptions, $flags, $longFlags, $flagsWithValue)) return;

    $recursive = in_array('p', $flags);
    
    $mode = $flagsWithValue['m'] ?? $flagsWithValue['mode'] ?? null;

    if ($mode !== null) {
        // accepts only octal, 3 or 4 digits, but GNU mkdir also accepts symbolic formats
        // (like u=rwx, g=rx, o=rx) — which has not yet been implemented.
        if (!preg_match('/^[0-7]{3,4}$/', $mode)) {
            printError('mkdir', ERR_INVALID_MODE, $mode);
        }

        $mode = octdec($mode);
    } else {
        $mode = 0775;
    }

    foreach ($args as $arg) {
        if (@mkdir($arg, $mode, $recursive)) {
            if (!chmod($arg, $mode)) {
                printError('mkdir', ERR_PERM_DENIED, $arg);
            }
        } else {
            // -p: if the directory already exists, it's not an error
            if ($recursive && is_dir($arg)) {
                continue;
            }

            if (file_exists($arg)) {
                printError('mkdir', ERR_FILE_EXISTS, $arg);
            } else {
                $parent = dirname($arg);

                if (!is_dir($parent)) {
                    printError('mkdir', ERR_NO_SUCH_PATH, $arg);
                } elseif (!is_writable($parent)) {
                    printError('mkdir', ERR_PERM_DENIED, $arg);
                } else {
                    // fallback (rare, but safe)
                    printError('mkdir', ERR_PERM_DENIED, $arg);
                }
            }
        }
    }
}
