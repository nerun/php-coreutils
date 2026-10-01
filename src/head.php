<?php

// PHP Coreutils - Copyright (c) 2026 Daniel Dias Rodrigues
// Distributed under the MIT License; see LICENSE.

require_once __DIR__ . '/lib/reading.php';

/** Read the beginning of local files, defaulting to ten lines per file. */
function head(array $input, ?string $cwd = null, ?callable $write = null): array
{
    return coreutilsReadFiles('head', $input, $cwd, $write);
}
