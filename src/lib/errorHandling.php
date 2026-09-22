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

/** Build an error without producing output. */
function coreutilsError(string $command, string $code, string $message, ?string $path = null): array {
    return ['code' => $code, 'message' => $command . ': ' . $message, 'path' => $path];
}

function coreutilsResult(string $command, array $data = []): array {
    return [
        'command' => $command,
        'status' => 0,
        'data' => $data,
        'errors' => [],
        'options' => [],
        'help' => null,
    ];
}

function coreutilsAddError(array &$result, array $error, int $status = 1): void {
    $result['errors'][] = $error;
    $result['status'] = max($result['status'], $status);
}

/** Capture filesystem warnings locally; always restore the caller's handler. */
function coreutilsFsCall(callable $operation, ?string &$warning = null) {
    $warning = null;
    set_error_handler(function ($severity, $message) use (&$warning) {
        $warning = $message;
        return true;
    }, E_WARNING);
    try {
        return $operation();
    } finally {
        restore_error_handler();
    }
}

function coreutilsFsError(string $command, string $operation, string $path, ?string $warning): array {
    // Keep the actual diagnostic; do not label every failure "Permission denied".
    $detail = $warning === null ? 'Operation failed' : preg_replace('/^[^:]+\(\):\s*/', '', $warning);
    return coreutilsError($command, 'filesystem-error',
        "cannot $operation '$path': $detail", $path);
}
