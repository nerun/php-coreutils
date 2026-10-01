<?php

// PHP Coreutils - Copyright (c) 2026 Daniel Dias Rodrigues
// Distributed under the MIT License; see LICENSE.

require_once __DIR__ . '/filesystem.php';

/** Write to a regular local file; lock before truncating and account for short writes. */
function coreutilsWriteFile(string $path, string $content, bool $append, int &$bytes, ?string &$warning): bool
{
    $bytes = 0;
    clearstatcache(true, $path);
    $stat = coreutilsFsCall(fn () => stat($path));
    if ($stat !== false && ($stat['mode'] & 0170000) !== 0100000) {
        $warning = 'Not a regular file';
        return false;
    }
    $stream = coreutilsFsCall(fn () => fopen($path, $append ? 'ab' : 'cb'), $warning);
    if ($stream === false) {
        return false;
    }
    $success = false;
    try {
        $stat = coreutilsFsCall(fn () => fstat($stream), $warning);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
            $warning = $warning ?? 'Opened target is not a regular file';
        } elseif (coreutilsFsCall(fn () => flock($stream, LOCK_EX), $warning)
            && ($append || coreutilsFsCall(fn () => ftruncate($stream, 0), $warning))) {
            $success = true;
            $length = strlen($content);
            while ($bytes < $length) {
                $block = substr($content, $bytes, min(8192, $length - $bytes));
                $written = coreutilsFsCall(fn () => fwrite($stream, $block), $warning);
                if ($written === false || $written === 0) {
                    $warning = $warning ?? 'Unable to write the remaining output';
                    $success = false;
                    break;
                }
                $bytes += $written;
            }
            if ($success && !coreutilsFsCall(fn () => fflush($stream), $warning)) {
                $success = false;
            }
        }
    } finally {
        if (!coreutilsFsCall(fn () => fclose($stream), $closeWarning)) {
            $warning = $warning ?? $closeWarning;
            $success = false;
        }
        clearstatcache(true, $path);
    }
    return $success;
}
