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

define('ERR_NO_SUCH_FILE', 0);   // ls
define('ERR_INVALID_OPTION', 1); // ls, mkdir
define('ERR_FILE_EXISTS', 2);    // mkdir
define('ERR_CANNOT_CREATE', 3);  // mkdir

// ====== Auxiliary functions ======

function printError($command, $error, $name) {
    switch ($error) {
        case 0: // ls
            echo "$command: cannot access '$name': No such file or directory";
            break;
        case 1: // ls, mkdir
            echo "$command: invalid option -- '$name'";
            echo "<br>Try '$command --help' for more information.";
            break;
        case 2: // mkdir
            echo "$command: cannot create directory '$name': File exists";
            break;
        case 3: // mkdir
            echo "$command: cannot create directory '$name': Permission denied";
            break;
        default:
            echo '<span style="color:red">No such error!</span>';
            break;
    }
    
    echo '<br>';
}

function validateOptions($command, $validOptions, $flags, $longFlags) {
    $validSet = array_flip($validOptions);
    
    foreach (array_merge($flags, $longFlags) as $flag) {
        if (!isset($validSet[$flag])) {
            printError($command, ERR_INVALID_OPTION, $flag);
            return false;
        }
    }
    
    return true;
}
