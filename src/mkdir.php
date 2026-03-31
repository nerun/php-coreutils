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

function _mkdir($args = [], $longFlags = [], $flags = []) {
    $validOptions = ['p'];
    if(!validateOptions('mkdir', $validOptions, $flags, $longFlags)) return;
    
    $recursive = in_array('p', $flags);
    
    foreach ($args as $arg) {
        $old = umask(0002); // $old = old umask = 0022, umask sets to 0002
        if (!@mkdir($arg, 0775, $recursive)) {
            if (file_exists($arg)) {
                printError('mkdir', ERR_FILE_EXISTS, $arg);
            } else {
                printError('mkdir', ERR_CANNOT_CREATE, $arg);
            }
        }
        umask($old); // restores umask 0022
    }
}
