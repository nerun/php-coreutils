<?php

// PHP Coreutils - Copyright (c) 2026 Daniel Dias Rodrigues
// Distributed under the MIT License; see LICENSE.

require_once __DIR__ . '/lib/reading.php';

/** Concatenate local files; an optional callback receives output blocks without buffering. */
function cat(array $input, ?string $cwd = null, ?callable $write = null): array
{
    return coreutilsReadFiles('cat', $input, $cwd, $write);
}
