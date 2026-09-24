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

/** Canonical name => [short spelling, long spelling, requires a value]. */
function coreutilsOptionDefinitions(string $command): array
{
    $common = ['help' => [null, 'help', false]];
    if ($command === 'find') {
        return ['type' => ['type', 'type', true]] + $common;
    }
    if ($command === 'ls') {
        return [
            'all' => ['a', 'all', false],
            'long' => ['l', null, false],
            'omit-owner' => ['g', null, false],
            'omit-group' => ['o', null, false],
            'no-group' => ['G', null, false],
            'human-readable' => ['h', 'human-readable', false],
            'si' => [null, 'si', false],
            'group-directories-first' => [null, 'group-directories-first', false],
        ] + $common;
    }
    if ($command === 'mkdir') {
        return [
            'parents' => ['p', 'parents', false],
            'mode' => ['m', 'mode', true],
        ] + $common;
    }
    if ($command === 'rm') {
        return [
            'recursive' => ['r', 'recursive', false],
            'force' => ['f', 'force', false],
            'verbose' => ['v', 'verbose', false],
        ] + $common;
    }
    if ($command === 'rmdir') {
        return [
            'parents' => ['p', 'parents', false],
            'verbose' => ['v', 'verbose', false],
        ] + $common;
    }
    if ($command === 'cp') {
        return [
            'recursive' => ['r', 'recursive', false],
            'no-clobber' => ['n', 'no-clobber', false],
            'verbose' => ['v', 'verbose', false],
        ] + $common;
    }
    if ($command === 'mv') {
        return [
            'force' => ['f', 'force', false],
            'no-clobber' => ['n', 'no-clobber', false],
            'verbose' => ['v', 'verbose', false],
        ] + $common;
    }
    return [];
}

function coreutilsHelp(string $command): string
{
    if ($command === 'find') {
        return "Usage: find [PATH]... [-type TYPES]\n"
            . "Recursively list entries, including starting paths and hidden names.\n"
            . "The default path is .; symbolic links are not traversed.\n\n"
            . "  -type, --type TYPES  f: regular files, d: directories, l: symbolic links\n"
            . "                       comma-separated types match any listed type (f,l)\n"
            . "                       the last type option wins\n"
            . "      --help           show this help\n"
            . "      --               end options\n";
    }
    if ($command === 'ls') {
        return "Usage: ls [OPTION]... [FILE]...\n"
            . "List files; list the current directory when FILE is omitted.\n\n"
            . "  -a, --all                  include names beginning with .\n"
            . "  -l                         use a long listing\n"
            . "  -g                         long listing without owner\n"
            . "  -o                         long listing without group\n"
            . "  -G                         omit group in a long listing\n"
            . "  -h, --human-readable       scale sizes by 1024\n"
            . "      --si                   scale sizes by 1000\n"
            . "      --group-directories-first\n"
            . "      --help                 show this help\n"
            . "      --                     end options\n";
    }
    if ($command === 'rm') {
        return "Usage: rm [OPTION]... FILE...\n"
            . "Remove entries; source links themselves are removed.\n\n"
            . "  -r, --recursive    remove directories and their contents\n"
            . "  -f, --force        ignore missing entries and missing operands\n"
            . "  -v, --verbose      report removed entries\n"
            . "      --help         show this help\n"
            . "      --             end options\n";
    }
    if ($command === 'rmdir') {
        return "Usage: rmdir [OPTION]... DIRECTORY...\n"
            . "Remove empty real directories only.\n\n"
            . "  -p, --parents      also remove empty parents; stop before cwd or root\n"
            . "  -v, --verbose      report removed directories\n"
            . "      --help         show this help\n"
            . "      --             end options\n";
    }
    if ($command === 'cp') {
        return "Usage: cp [OPTION]... SOURCE... DESTINATION\n"
            . "Copy files; multiple sources require an existing destination directory.\n\n"
            . "  -r, --recursive     copy directories and preserve source symbolic links\n"
            . "  -n, --no-clobber    skip existing destination files\n"
            . "  -v, --verbose      report copied entries and created directories\n"
            . "      --help         show this help\n"
            . "      --             end options\n";
    }
    if ($command === 'mv') {
        return "Usage: mv [OPTION]... SOURCE... DESTINATION\n"
            . "Rename or move files, directories and symbolic links on the same filesystem.\n\n"
            . "  -f, --force          replace existing files (default)\n"
            . "  -n, --no-clobber     skip existing destinations\n"
            . "  -v, --verbose       report completed moves\n"
            . "      --help          show this help\n"
            . "      --              end options\n"
            . "The last -f or -n wins. Multiple sources require an existing directory.\n";
    }
    return "Usage: mkdir [OPTION]... DIRECTORY...\n"
        . "Create directories.\n\n"
        . "  -p, --parents        create missing parents; accept existing directories\n"
        . "  -m, --mode=MODE      set the final directory mode (3 or 4 octal digits)\n"
        . "                       existing directories and parent modes are unaffected\n"
        . "      --help           show this help\n"
        . "      --               end options\n";
}
