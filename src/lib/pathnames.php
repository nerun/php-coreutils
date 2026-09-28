<?php
// PHP Coreutils - Copyright (c) 2026 Daniel Dias Rodrigues
// Distributed under the MIT License; see LICENSE.

/** Split Unix path text into basename and dirname without filesystem access. */
function coreutilsPathnameParts(string $path): array {
    $trimmed = rtrim($path, '/');
    if ($trimmed === '') return $path === '' ? ['', '.'] : ['/', '/'];
    $slash = strrpos($trimmed, '/');
    if ($slash === false) return [$trimmed, '.'];
    $name = substr($trimmed, $slash + 1);
    $directory = rtrim(substr($trimmed, 0, $slash), '/');
    return [$name, $directory === '' ? '/' : $directory];
}
