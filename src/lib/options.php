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
function coreutilsOptionDefinitions(string $command): array {
    $common = ['help' => [null, 'help', false]];
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
    return [];
}

function coreutilsHelp(string $command): string {
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
    return "Usage: mkdir [OPTION]... DIRECTORY...\n"
        . "Create directories.\n\n"
        . "  -p, --parents        create missing parents; accept existing directories\n"
        . "  -m, --mode=MODE      set the final directory mode (3 or 4 octal digits)\n"
        . "                       existing directories and parent modes are unaffected\n"
        . "      --help           show this help\n"
        . "      --               end options\n";
}
