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
$rows = [];
$st_blocks = 0;

// ====== Auxiliary functions ======

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
        //$mtime = strftime("%b %d %Y %H:%M", filemtime($path));

        $formatter = new IntlDateFormatter(
            CURRENT_LOCALE,
            IntlDateFormatter::MEDIUM,
            IntlDateFormatter::SHORT,
            date_default_timezone_get(),
            IntlDateFormatter::GREGORIAN,
            "MMM dd yyyy HH:mm"
        );
        $mtime = $formatter->format(filemtime($path));
        $mtime = preg_replace('/^(\p{L}+)\./u', '$1', $mtime); // remove dot after month
        
        $human = in_array('h', $flags) || in_array('human-readable', $longFlags);
        $useSI = in_array('si', $longFlags);
                
        $size = ($human || $useSI)
            ? humanSize($stat['size'], $useSI)
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

    echo '<pre style="margin: 0;">';
    
    foreach ($rows as $r) {
        if ($longListing && is_array($r)) {
            $showOwner = !in_array('g', $flags); // -g hide owner
            $showGroup = !in_array('G', $flags); // -G hide group

            $format = "%-10s %2s";

            $args = [
                $r['perms'],
                $r['nlink']
            ];

            if ($showOwner) {
                $format .= " %-{$maxUser}s";
                $args[] = $r['user'];
            }

            if ($showGroup) {
                $format .= " %-{$maxGroup}s";
                $args[] = $r['group'];
            }

            $format .= " %{$maxSize}s %s  %s<br>";

            $args[] = $r['size'];
            $args[] = $r['date'];
            $args[] = $r['name'];

            printf($format, ...$args);
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

function humanSize($bytes, $useSI = false) {
    $base = $useSI ? 1000 : 1024;
    $units = $useSI ? ['','k','M','G','T','P','E'] : ['','K','M','G','T','P','E'];

    $i = 0;
    while ($bytes >= $base && $i < count($units) - 1) {
        $bytes /= $base;
        $i++;
    }

    // GNU-style rounding
    if ($bytes >= 10 || floor($bytes) == $bytes) {
        return sprintf("%.0f%s", $bytes, $units[$i]);
    }

    return sprintf("%.1f%s", $bytes, $units[$i]);
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
    $validOptions = ['a', 'g', 'G', 'h', 'l', 'o',
                     'all', 'group-directories-first', 'human-readable', 'si'];
    if(!validateOptions('ls', $validOptions, $flags, $longFlags)) return;
    
    global $rows;
    global $st_blocks;
    $files = [];
    $folders = [];
    $longListing = in_array('l', $flags);

    // Flag '-o' is like -l, but do not list group information
    // Replaces '-o' with '-l' and '-G', deletes '-o', reindex array $flags
    if (in_array('o', $flags)) {
        if (!$longListing) {
            $flags[] = 'l';
            $longListing = true;
        }
        if (!in_array('G', $flags)) {
            $flags[] = 'G';
        }
        $oKey = array_find_key($flags, function(string $value) { return $value == 'o'; });
        unset($flags[$oKey]);
        $flags = array_values($flags);
    }

    // Flag '-g' is like '-l', but do not list owner
    // Insert the '-l' flag into the array, if not, and set $longListing to true.
    if (in_array('g', $flags)) {
        if (!$longListing) {
            $flags[] = 'l';
            $longListing = true;
        }
    }
    
    if (empty($args)) {
        $folders['.'] = $_SESSION['cwd'];
    } else {
        foreach ($args as $item) {
            $rp = realpath($_SESSION['cwd'] . DIRECTORY_SEPARATOR . $item);

            if ($rp === false) {
                printError('ls', ERR_NO_SUCH_FILE, $item);
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
                
                $human = in_array('h', $flags) || in_array('human-readable', $longFlags);
                $useSI = in_array('si', $longFlags);
                
                if ($human || $useSI) {
                    $total = humanSize($total * 1024, $useSI);
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
    
    $rows = [];
    $st_blocks = 0;
}
