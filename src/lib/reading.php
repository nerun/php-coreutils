<?php

// PHP Coreutils - Copyright (c) 2026 Daniel Dias Rodrigues
// Distributed under the MIT License; see LICENSE.

require_once __DIR__ . '/parser.php';
require_once __DIR__ . '/filesystem.php';

/** Validate decimal counts without allowing integer overflow or size suffixes. */
function coreutilsReadingCount(string $value): ?array
{
    if (!preg_match('/\A([+-]?)([0-9]+)\z/', $value, $parts)) {
        return null;
    }
    $digits = ltrim($parts[2], '0');
    $maximum = (string) PHP_INT_MAX;
    if (strlen($digits) > strlen($maximum)
        || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
        return null;
    }
    return ['sign' => $parts[1], 'count' => $digits === '' ? 0 : (int) $digits];
}

/** Open only local regular files, following links and verifying the opened handle. */
function coreutilsOpenReadingFile(string $path, ?array &$stat, ?string &$warning)
{
    clearstatcache(true, $path);
    $stat = coreutilsFsCall(fn () => stat($path), $warning);
    if ($stat === false) {
        $stat = null;
        return false;
    }
    if (($stat['mode'] & 0170000) !== 0100000) {
        $warning = 'Not a regular file';
        return false;
    }
    $stream = coreutilsFsCall(fn () => fopen($path, 'rb'), $warning);
    if ($stream === false) {
        return false;
    }
    $opened = coreutilsFsCall(fn () => fstat($stream), $warning);
    if ($opened === false || ($opened['mode'] & 0170000) !== 0100000
        || !is_int($opened['size']) || $opened['size'] < 0) {
        $warning = $warning ?? 'Opened file has unsupported type or size';
        coreutilsFsCall(fn () => fclose($stream));
        return false;
    }
    $stat = $opened;
    return $stream;
}

/** Read one bounded block; an unexpected EOF indicates a changed/truncated file. */
function coreutilsReadingChunk($stream, int $length, ?string &$warning)
{
    $chunk = coreutilsFsCall(fn () => fread($stream, $length), $warning);
    if ($chunk === '') {
        $warning = 'File ended before the selected range could be read';
        return false;
    }
    return $chunk;
}

/** Locate the boundary after COUNT records without buffering entire lines. */
function coreutilsReadingForward($stream, int $size, int $count, string $delimiter, ?string &$warning)
{
    if ($count === 0) {
        return 0;
    }
    if (coreutilsFsCall(fn () => fseek($stream, 0), $warning) !== 0) {
        return false;
    }
    $offset = 0;
    while ($offset < $size) {
        $chunk = coreutilsReadingChunk($stream, min(8192, $size - $offset), $warning);
        if ($chunk === false) {
            return false;
        }
        $position = 0;
        while (($position = strpos($chunk, $delimiter, $position)) !== false) {
            $position++;
            if (--$count === 0) {
                return $offset + $position;
            }
        }
        $offset += strlen($chunk);
    }
    return $size;
}

/** Find the start of the last COUNT records, scanning backwards in bounded blocks. */
function coreutilsReadingBackward($stream, int $size, int $count, string $delimiter, ?string &$warning)
{
    if ($count === 0) {
        return $size;
    }
    $offset = $size;
    while ($offset > 0) {
        $length = min(8192, $offset);
        $offset -= $length;
        if (coreutilsFsCall(fn () => fseek($stream, $offset), $warning) !== 0) {
            return false;
        }
        $chunk = '';
        // fread() can return a short block; complete it before scanning backwards.
        while (strlen($chunk) < $length) {
            $part = coreutilsReadingChunk($stream, $length - strlen($chunk), $warning);
            if ($part === false) {
                return false;
            }
            $chunk .= $part;
        }
        for ($i = $length - 1; $i >= 0; $i--) {
            if ($chunk[$i] === $delimiter && $offset + $i !== $size - 1 && --$count === 0) {
                return $offset + $i + 1;
            }
        }
    }
    return 0;
}

/** Share selection boundaries between head and tail; ranges are [start, end). */
function coreutilsReadingRange($stream, int $size, array $selection, ?string &$warning)
{
    $count = $selection['count'];
    $mode = $selection['mode'];
    if ($selection['unit'] === 'bytes') {
        if ($mode === 'first') {
            return [0, min($size, $count)];
        }
        if ($mode === 'exclude') {
            return [0, max(0, $size - $count)];
        }
        if ($mode === 'from') {
            return [min($size, max(0, $count - 1)), $size];
        }
        return [max(0, $size - $count), $size];
    }
    $delimiter = $selection['delimiter'];
    $boundary = in_array($mode, ['last', 'exclude'], true)
        ? coreutilsReadingBackward($stream, $size, $count, $delimiter, $warning)
        : coreutilsReadingForward($stream, $size, $mode === 'from' ? max(0, $count - 1) : $count, $delimiter, $warning);
    if ($boundary === false) {
        return false;
    }
    return in_array($mode, ['first', 'exclude'], true) ? [0, $boundary] : [$boundary, $size];
}

/** Apply cat's transformations while retaining logical-line state across blocks/files. */
function coreutilsCatChunk(string $chunk, array $options, array &$state, bool $final = false): string
{
    if (!($options['number'] ?? false) && !($options['number-nonblank'] ?? false)
        && !($options['squeeze-blank'] ?? false) && !($options['show-ends'] ?? false)
        && !($options['show-tabs'] ?? false)) {
        return $chunk;
    }
    $chunk = $state['pending'] . $chunk;
    $state['pending'] = '';
    if (!$final && ($options['show-ends'] ?? false) && substr($chunk, -1) === "\r") {
        $state['pending'] = "\r";
        $chunk = substr($chunk, 0, -1);
    }
    $output = '';
    $length = strlen($chunk);
    for ($offset = 0; $offset < $length;) {
        $end = strpos($chunk, "\n", $offset);
        $terminated = $end !== false;
        $end = $terminated ? $end + 1 : $length;
        $part = substr($chunk, $offset, $end - $offset);
        $blank = $state['line-start'] && $part === "\n";
        if (!($blank && $state['blank'] && ($options['squeeze-blank'] ?? false))) {
            $number = ($options['number-nonblank'] ?? false) ? !$blank : ($options['number'] ?? false);
            if ($state['line-start'] && $number) {
                $output .= str_pad((string) $state['number']++, 6, ' ', STR_PAD_LEFT) . "\t";
            }
            if ($options['show-tabs'] ?? false) {
                $part = str_replace("\t", '^I', $part);
            }
            if ($terminated && ($options['show-ends'] ?? false)) {
                $part = substr($part, -2) === "\r\n" ? substr($part, 0, -2) . "^M$\n"
                    : substr($part, 0, -1) . "$\n";
            }
            $output .= $part;
        }
        $state['blank'] = $blank;
        $state['line-start'] = $terminated;
        $offset = $end;
    }
    return $output;
}

/** Header policy is shared with the formatter; last enabled quiet/verbose alias wins. */
function coreutilsReadingHeaders(string $command, array $options, int $operands): bool
{
    if ($command === 'cat') {
        return false;
    }
    $headers = $operands > 1;
    foreach ($options as $name => $enabled) {
        if ($enabled === true && in_array($name, ['quiet', 'silent', 'verbose'], true)) {
            $headers = $name === 'verbose';
        }
    }
    return $headers;
}

/** Store or deliver one output block; a refused block stops further delivery. */
function coreutilsReadingOutput(string $chunk, ?callable $write, array &$entry, array &$result): bool
{
    if ($chunk === '') {
        return true;
    }
    if ($write === null) {
        $entry['content'] .= $chunk;
    } elseif ($write($chunk, ['name' => $entry['name'], 'path' => $entry['path']]) === false) {
        $entry['complete'] = false;
        coreutilsAddError($result, coreutilsError($result['command'], 'output-error', 'output callback refused a block', $entry['name']));
        return false;
    }
    $entry['bytes'] += strlen($chunk);
    return true;
}

/** Read once, returning selected bytes or passing blocks to a caller-owned sink. */
function coreutilsReadFiles(string $command, array $input, ?string $cwd, ?callable $write): array
{
    $result = coreutilsResult($command, ['entries' => [], 'streamed' => $write !== null, 'headers' => false]);
    [$options, $args, $errors] = coreutilsValidateInput($command, $input);
    $result['options'] = $options;
    foreach ($errors as $error) {
        coreutilsAddError($result, $error, 2);
    }
    if ($result['status'] !== 0) {
        return $result;
    }
    if ($options['help'] ?? false) {
        $result['help'] = coreutilsHelp($command);
        return $result;
    }
    if (!$args) {
        coreutilsAddError($result, coreutilsError($command, 'missing-operand', 'missing file operand'), 2);
        return $result;
    }
    $selection = ['unit' => 'lines', 'count' => 10, 'mode' => $command === 'head' ? 'first' : 'last',
        'delimiter' => ($options['zero-terminated'] ?? false) ? "\0" : "\n"];
    foreach ($options as $name => $value) {
        if ($command === 'cat' || !in_array($name, ['lines', 'bytes'], true)) {
            continue;
        }
        $count = coreutilsReadingCount($value);
        if ($count === null) {
            coreutilsAddError($result, coreutilsError($command, 'invalid-count', "invalid $name count '$value'"), 2);
            continue;
        }
        $selection['unit'] = $name;
        $selection['count'] = $count['count'];
        $selection['mode'] = $command === 'head' ? ($count['sign'] === '-' ? 'exclude' : 'first')
            : ($count['sign'] === '+' ? 'from' : 'last');
    }
    if ($result['status'] !== 0) {
        return $result;
    }
    $base = coreutilsWorkingDirectory($cwd, $warning);
    if ($base === false) {
        coreutilsAddError($result, coreutilsError($command, 'invalid-cwd', $warning));
        return $result;
    }
    $result['data']['headers'] = coreutilsReadingHeaders($command, $options, count($args));
    $catState = ['line-start' => true, 'blank' => false, 'number' => 1, 'pending' => ''];
    $stopped = false;
    foreach ($args as $arg) {
        $path = coreutilsResolvePath($arg, $base);
        $stat = null;
        $stream = coreutilsOpenReadingFile($path, $stat, $warning);
        if ($stream === false) {
            coreutilsAddError($result, coreutilsFsError($command, 'read', $arg, $warning));
            continue;
        }
        $entry = ['name' => $arg, 'path' => $path, 'content' => $write === null ? '' : null,
            'bytes' => 0, 'complete' => false];
        $stopped = false;
        try {
            $range = $command === 'cat' ? [0, $stat['size']]
                : coreutilsReadingRange($stream, $stat['size'], $selection, $warning);
            if ($range === false || coreutilsFsCall(fn () => fseek($stream, $range[0]), $warning) !== 0) {
                coreutilsAddError($result, coreutilsFsError($command, 'read', $arg, $warning));
            } else {
                $remaining = $range[1] - $range[0];
                $entry['complete'] = true;
                while ($remaining > 0) {
                    $chunk = coreutilsReadingChunk($stream, min(8192, $remaining), $warning);
                    if ($chunk === false) {
                        $entry['complete'] = false;
                        coreutilsAddError($result, coreutilsFsError($command, 'read', $arg, $warning));
                        break;
                    }
                    $remaining -= strlen($chunk);
                    if ($command === 'cat') {
                        $chunk = coreutilsCatChunk($chunk, $options, $catState);
                    }
                    if (!coreutilsReadingOutput($chunk, $write, $entry, $result)) {
                        $stopped = true;
                        break;
                    }
                }
            }
        } finally {
            if (!coreutilsFsCall(fn () => fclose($stream), $warning)) {
                $entry['complete'] = false;
                coreutilsAddError($result, coreutilsFsError($command, 'close', $arg, $warning));
            }
        }
        $result['data']['entries'][] = $entry;
        if ($stopped) {
            break;
        }
    }
    if ($command === 'cat' && !$stopped && $catState['pending'] !== '' && $result['data']['entries']) {
        $index = count($result['data']['entries']) - 1;
        $chunk = coreutilsCatChunk('', $options, $catState, true);
        coreutilsReadingOutput($chunk, $write, $result['data']['entries'][$index], $result);
    }
    return $result;
}
