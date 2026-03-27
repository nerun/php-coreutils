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

$currentLocale = setAppLocale();
setlocale(LC_COLLATE, $currentLocale);

// ====== Auxiliary functions ======

function printError($name) {
    echo "ls: cannot access '$name': No such file or directory<br>";
}

function printName($name) {
    if (strpos($name, ' ') !== false) {
        echo "'$name'";
    } else {
        echo $name;
    }
    echo "<br>";
}

function listDirectory($path, $flags = []) {
    $items = scandir($path);

    usort($items, 'strcoll');

    $showHidden = in_array('a', $flags);

    foreach ($items as $item) {
        if (!$showHidden && $item[0] === '.') continue;
        printName($item);
    }
}

// ====== Main logic ======

function ls($args = [], $flags = []) {
    $files = [];
    $folders = [];

    if (empty($args)) {
        $folders['.'] = $_SESSION['cwd'];
    } else {
        foreach ($args as $item) {
            $rp = realpath($_SESSION['cwd'] . DIRECTORY_SEPARATOR . $item);

            if ($rp === false) {
                printError($item);
                continue;
            }

            if (is_dir($rp)) {
                $folders[$item] = $rp;
            } else {
                $files[$item] = $rp;
            }
        }
    }

    if (!empty($files)) {
        foreach ($files as $name => $path) {
            printName($name);
        }
    }

    if (!empty($folders)) {
        if (!empty($files)) {
            echo "<br>";
        }

        $last = array_key_last($folders);

        foreach ($folders as $name => $path) {
            if (count($folders) > 1) {
                echo "$name:<br>";
            }

            listDirectory($path, $flags);

            if ($name !== $last) {
                echo "<br>";
            }
        }
    }
}
