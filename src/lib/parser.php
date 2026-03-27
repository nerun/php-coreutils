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

function parseCommand($input) {
    $length = strlen($input);
    $current = '';
    $tokens = [];

    $inSingleQuote = false;
    $inDoubleQuote = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $input[$i];

        if ($char === '\\' && !$inSingleQuote) {
            $i++;
            if ($i < $length) {
                $current .= $input[$i];
            }
            continue;
        }

        if ($char === "'" && !$inDoubleQuote) {
            $inSingleQuote = !$inSingleQuote;
            continue;
        }

        if ($char === '"' && !$inSingleQuote) {
            $inDoubleQuote = !$inDoubleQuote;
            continue;
        }

        if ($char === ' ' && !$inSingleQuote && !$inDoubleQuote) {
            if ($current !== '') {
                $tokens[] = $current;
                $current = '';
            }
            continue;
        }

        $current .= $char;
    }

    if ($current !== '') {
        $tokens[] = $current;
    }

    $command = array_shift($tokens);

    $flags = [];
    $args = [];

    foreach ($tokens as $token) {
        if (strpos($token, '-') === 0 && strlen($token) > 1) {
            $flags = array_merge($flags, str_split(substr($token, 1)));
        } else {
            $args[] = $token;
        }
    }

    return [
        'command' => $command,
        'flags' => $flags,
        'args' => $args
    ];
}
