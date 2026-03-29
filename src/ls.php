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

$rows = [];
$st_blocks = 0;
$validOptions = ['a', 'h', 'l', 'all', 'human-readable', 'group-directories-first'];
const ERR_NO_SUCH_FILE = 0;
const ERR_INVALID_OPTION = 1;

// ====== Auxiliary functions ======

function printError($error, $name) {
    switch ($error) {
        case 0:
            echo "<br>ls: cannot access '$name': No such file or directory<br>";
            break;
        case 1:
            echo "<br>ls: invalid option -- '$name'<br>";
            echo "Try 'ls --help' for more information.<br>";
            break;
    }
}

function printName($name, $path, $flags = [], $longFlags = []) {
    global $rows;
    global $st_blocks;
    
    if (strpos($name, ' ') !== false) {
        $name = "'$name'";
    }
    
    $longListing = in_array('l', $flags);

    if ($longListing) {
        $stat = stat($path);
        $user = posix_getpwuid($stat['uid'])['name'];
        $group = posix_getgrgid($stat['gid'])['name'];
        $mtime = strftime("%b %d %Y %H:%M", filemtime($path));
        
        $size = (in_array('h', $flags) || in_array('human-readable', $longFlags))
            ? humanSize($stat['size'])
            : $stat['size'];

        $rows[] = [
            'perms' => symbolicPerms($path),
            'nlink' => $stat['nlink'],
            'user' => $user,
            'group' => $group,
            'size' => $size,
            'date' => $mtime,
            'name' => $name
        ];
        
        $st_blocks += $stat['blocks'];
    } else {
       $rows[] = $name;
    }
}

function printList($flags = []) {
    global $rows;
    $longListing = in_array('l', $flags);
    
    if ($longListing) {
        $maxUser = $maxGroup = $maxSize = 0;
        
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            
            $maxUser = max($maxUser, strlen($r['user']));
            $maxGroup = max($maxGroup, strlen($r['group']));
            $maxSize = max($maxSize, strlen($r['size']));
        }
    }

    echo "<pre style=\"margin: 0;\">";
    
    foreach ($rows as $r) {
        if ($longListing && is_array($r)) {
            printf(
                "%-10s %2s %-{$maxUser}s %-{$maxGroup}s %{$maxSize}s %s  %s<br>",
                $r['perms'],
                $r['nlink'],
                $r['user'],
                $r['group'],
                $r['size'],
                $r['date'],
                $r['name']
            );
        } else {
            printf("%s<br>", $r);
        }
    }
    
    echo "</pre>";
}

function listDirectory($path, $flags = [], $longFlags = []) {
    $items = scandir($path);

    $showHidden = in_array('a', $flags) || in_array('all', $longFlags);

    $items = array_filter($items, function ($item) use ($showHidden) {
        return $showHidden || $item[0] !== '.';
    });

    usort($items, 'strcoll');

    if (in_array('group-directories-first', $longFlags)) {
        $dirs = [];
        $files = [];

        foreach ($items as $item) {
            $fullpath = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($fullpath)) {
                $dirs[] = $item;
            } else {
                $files[] = $item;
            }
        }

        $items = array_merge($dirs, $files);
    }

    foreach ($items as $item) {
        $fullpath = $path . DIRECTORY_SEPARATOR . $item;
        printName($item, $fullpath, $flags, $longFlags);
    }
}

function humanSize($bytes) {
    $units = ['', 'K', 'M', 'G', 'T', 'P'];

    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }

    return ($bytes >= 10)
        ? sprintf("%.0f%s", $bytes, $units[$i])
        : sprintf("%.1f%s", $bytes, $units[$i]);
}

function symbolicPerms($path){
    $perms = fileperms($path);
    $info = match ($perms & 0xF000) {
        0xC000 => 's', // socket
        0xA000 => 'l', // symbolic link
        0x8000 => '-', // regular file
        0x6000 => 'b', // block special
        0x4000 => 'd', // directory
        0x2000 => 'c', // character special
        0x1000 => 'p', // FIFO pipe
        default => 'u', // unknown
    };
    
    // Owner
    $info .= (($perms & 0x0100) ? 'r' : '-');
    $info .= (($perms & 0x0080) ? 'w' : '-');
    $info .= (($perms & 0x0040) ?
                (($perms & 0x0800) ? 's' : 'x' ) :
                (($perms & 0x0800) ? 'S' : '-'));
    
    // Group
    $info .= (($perms & 0x0020) ? 'r' : '-');
    $info .= (($perms & 0x0010) ? 'w' : '-');
    $info .= (($perms & 0x0008) ?
                (($perms & 0x0400) ? 's' : 'x' ) :
                (($perms & 0x0400) ? 'S' : '-'));
    
    // World
    $info .= (($perms & 0x0004) ? 'r' : '-');
    $info .= (($perms & 0x0002) ? 'w' : '-');
    $info .= (($perms & 0x0001) ?
                (($perms & 0x0200) ? 't' : 'x' ) :
                (($perms & 0x0200) ? 'T' : '-'));
    
    return $info;
}

// ====== Main logic ======

function ls($args = [], $longFlags = [], $flags = []) {
    global $rows;
    global $st_blocks;
    global $validOptions;
    $files = [];
    $folders = [];
    $longListing = in_array('l', $flags);
    $validSet = array_flip($validOptions);

    foreach (array_merge($flags, $longFlags) as $flag) {
        if (!isset($validSet[$flag])) {
            printError(ERR_INVALID_OPTION, $flag);
            return false;
        }
    }

    if (empty($args)) {
        $folders['.'] = $_SESSION['cwd'];
    } else {
        foreach ($args as $item) {
            $rp = realpath($_SESSION['cwd'] . DIRECTORY_SEPARATOR . $item);

            if ($rp === false) {
                printError(ERR_NO_SUCH_FILE, $item);
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
            printName($name, $path, $flags, $longFlags);
        }
    }

    if (!empty($folders)) {
        if (!empty($files)) {
            $rows[] = '';
        }

        $last = array_key_last($folders);

        foreach ($folders as $name => $path) {
            if (count($folders) + count($files) > 1) {
                $rows[] = "$name:";
                if ($longListing) {
                    $rows[] = "total __BLOCKS__";
                }
            }

            listDirectory($path, $flags, $longFlags);
            
            if ($longListing) {
                $total = $st_blocks / 2;
                if (in_array('h', $flags) || in_array('human-readable', $longFlags)) {
                    $total = humanSize($total * 1024);
                }

                foreach ($rows as &$row) {
                    if (is_string($row)) {
                        $row = str_replace('__BLOCKS__', $total, $row);
                    }
                }
                unset($row);

                $st_blocks = 0;
            }

            if ($name !== $last) {
                $rows[] = '';
            }
        }
    }
    
    printList($flags);
}
