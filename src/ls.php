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

function ls($args = [], $flags = []) {
    $files = array();
    $folders = array();
    
    if (empty($args)) {
        $folders['.'] = $_SESSION['cwd'];
    } else {
        foreach ($args as $item_to_scan) {
            $rp = realpath($_SESSION['cwd'] . DIRECTORY_SEPARATOR . $item_to_scan);
            // realpath() resolve path -> if exist return -> string
            //                         -> if not   return -> bool(false)
            if (is_dir($rp)) {
                $folders[$item_to_scan] = $rp;
            } else {
                $files[$item_to_scan] = $rp;
            }
        }
    }
    
    function pathFalse($path, $name) {
        if ($path === false) {
            echo "ls: cannot access '$name': No such file or directory<br>";
            return true;
        }
        return false;
    }
    
    function printName($name) {
        if (strpos($name, ' ') !== false) {
            echo "'" . $name . "'";
        } else {
            echo $name;
        }
        echo "<br>";
    }

    if(!empty($files)){
        foreach ($files as $name => $path) {
            if (pathFalse($path, $name)) continue;
            printName($name);
        }
    }

    if(!empty($folders)) {
        if(!empty($files)){
            echo "<br>";
        }
        
        $last = array_key_last($folders);

        foreach ($folders as $name => $path) {
            if (pathFalse($path, $name)) continue;

            if (!($name === '.' && count($folders) === 1)) echo "$name:<br>";

            $files = scandir($path);

            foreach ($files as $file) {
                if ($file != '.' && $file != '..') printName($file);
            }
            
            if ($name !== $last) echo "<br>";
        }
    }
}
