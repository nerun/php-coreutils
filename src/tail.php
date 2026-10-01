<?php

// PHP Coreutils - Copyright (c) 2026 Daniel Dias Rodrigues
// Distributed under the MIT License; see LICENSE.

require_once __DIR__ . '/lib/reading.php';

/** Read the end of local files, defaulting to ten lines and scanning backwards. */
function tail(array $input, ?string $cwd = null, ?callable $write = null): array
{
    return coreutilsReadFiles('tail', $input, $cwd, $write);
}
