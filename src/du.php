<?php

// PHP Coreutils - Copyright (c) 2026 Daniel Dias Rodrigues
// Distributed under the MIT License; see LICENSE.

require_once __DIR__ . '/lib/parser.php';
require_once __DIR__ . '/lib/filesystem.php';

/** Parse positive block sizes without overflowing PHP integers. */
function coreutilsDuBlockSize(string $value): ?array
{
    if ($value === 'human-readable' || $value === 'si') {
        return ['format' => $value, 'block-size' => 1, 'suffix' => ''];
    }
    if (!preg_match('/\A([0-9]*)([kKMGTPE]?)(i?B)?\z/', $value, $parts)
        || ($parts[1] === '' && ($parts[2] ?? '') === '')
        || (($parts[3] ?? '') !== '' && ($parts[2] ?? '') === '')) {
        return null;
    }
    $digits = ltrim($parts[1] === '' ? '1' : $parts[1], '0');
    $maximum = (string) PHP_INT_MAX;
    if ($digits === '' || strlen($digits) > strlen($maximum)
        || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
        return null;
    }
    $size = (int) $digits;
    $unit = strtoupper($parts[2] ?? '');
    $base = ($parts[3] ?? '') === 'B' ? 1000 : 1024;
    $power = $unit === '' ? 0 : strpos('KMGTPE', $unit) + 1;
    for ($i = 0; $i < $power; $i++) {
        if ($size > intdiv(PHP_INT_MAX, $base)) {
            return null;
        }
        $size *= $base;
    }
    $suffix = $parts[1] === '' ? ($unit === 'K' && $base === 1000 ? 'k' : $unit) . ($parts[3] ?? '') : '';
    return ['format' => 'blocks', 'block-size' => $size, 'suffix' => $suffix];
}

/** Preserve parser insertion order for mutually exclusive scaling and link options. */
function coreutilsDuSettings(array $options): array
{
    $settings = ['format' => 'blocks', 'block-size' => 1024, 'suffix' => '',
        'apparent' => ($options['apparent-size'] ?? false) || ($options['bytes'] ?? false),
        'links' => 'physical', 'max-depth' => null];
    foreach ($options as $name => $value) {
        if ($value === false) {
            continue;
        }
        if (in_array($name, ['human-readable', 'si', 'block-size', 'bytes', 'kibibytes', 'mebibytes'], true)) {
            $size = ['bytes' => '1', 'kibibytes' => '1024', 'mebibytes' => '1048576'][$name] ?? $value;
            if ($name === 'human-readable' || $name === 'si') {
                $size = $name;
            }
            $parsed = coreutilsDuBlockSize($size);
            if ($parsed === null) {
                return ['error' => 'block size must be positive and fit in a PHP integer'];
            }
            $settings = array_replace($settings, $parsed);
        }
        if (in_array($name, ['dereference', 'dereference-args', 'dereference-args-alias', 'no-dereference'], true)) {
            $settings['links'] = $name === 'dereference' ? 'logical'
                : ($name === 'no-dereference' ? 'physical' : 'arguments');
        }
    }
    if (isset($options['max-depth'])) {
        $digits = ltrim($options['max-depth'], '0');
        $maximum = (string) PHP_INT_MAX;
        if (!preg_match('/\A[0-9]+\z/', $options['max-depth'])
            || strlen($digits) > strlen($maximum)
            || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            return ['error' => 'max depth must be a nonnegative integer'];
        }
        $settings['max-depth'] = $digits === '' ? 0 : (int) $digits;
    }
    if ($options['summarize'] ?? false) {
        if (($options['all'] ?? false) || ($settings['max-depth'] !== null && $settings['max-depth'] !== 0)) {
            return ['error' => 'summarize cannot be combined with all or a nonzero max depth'];
        }
        $settings['max-depth'] = 0;
    }
    return $settings;
}

/** A null aggregate remains unknown; never wrap, round or substitute apparent size. */
function coreutilsDuSum(?int $left, ?int $right, string $name, array &$result): ?int
{
    if ($left === null || $right === null) {
        return null;
    }
    if ($left > PHP_INT_MAX - $right) {
        coreutilsAddError($result, coreutilsError('du', 'size-overflow', "size exceeds PHP integer range for '$name'", $name));
        return null;
    }
    return $left + $right;
}

/** Get bytes from metadata only, including sparse files and link objects. */
function coreutilsDuBytes(array $stat, bool $apparent, string $name, array &$result): ?int
{
    $type = $stat['mode'] & 0170000;
    if ($apparent && !in_array($type, [0100000, 0120000], true)) {
        return 0;
    }
    $value = $apparent ? ($stat['size'] ?? null) : ($stat['blocks'] ?? null);
    $factor = $apparent ? 1 : 512;
    if (!is_int($value) || $value < 0) {
        coreutilsAddError($result, coreutilsError(
            'du',
            'size-unavailable',
            "size metadata unavailable for '$name'" . ($apparent ? '' : '; use --apparent-size'),
            $name
        ));
        return null;
    }
    if ($value > intdiv(PHP_INT_MAX, $factor)) {
        coreutilsAddError($result, coreutilsError('du', 'size-overflow', "size exceeds PHP integer range for '$name'", $name));
        return null;
    }
    return $value * $factor;
}

/** Estimate disk usage with postorder traversal, inode deduplication and bounded call depth. */
function du(array $input, ?string $cwd = null): array
{
    $result = coreutilsResult('du', ['entries' => [], 'total' => 0, 'settings' => []]);
    [$options, $args, $errors] = coreutilsValidateInput('du', $input);
    $result['options'] = $options;
    foreach ($errors as $error) {
        coreutilsAddError($result, $error, 2);
    }
    if ($result['status'] !== 0) {
        return $result;
    }
    if ($options['help'] ?? false) {
        $result['help'] = coreutilsHelp('du');
        return $result;
    }
    $settings = coreutilsDuSettings($options);
    if (isset($settings['error'])) {
        coreutilsAddError($result, coreutilsError('du', 'invalid-value', $settings['error']), 2);
        return $result;
    }
    $result['data']['settings'] = $settings;
    $base = coreutilsWorkingDirectory($cwd, $warning);
    if ($base === false) {
        coreutilsAddError($result, coreutilsError('du', 'invalid-cwd', $warning));
        return $result;
    }
    $seen = $active = [];
    $separators = DIRECTORY_SEPARATOR === '\\' ? '/\\' : '/';
    foreach ($args ?: ['.'] as $arg) {
        $stack = [['name' => $arg, 'path' => coreutilsResolvePath($arg, $base), 'depth' => 0]];
        $rootDevice = null;
        while ($stack) {
            $index = count($stack) - 1;
            $frame = $stack[$index];
            if (!isset($frame['children'])) {
                $path = $frame['path'];
                $name = $frame['name'];
                $directoryOperand = strpos($separators, substr($path, -1)) !== false;
                $stat = coreutilsLstat($path, $warning);
                if ($stat !== false && ($stat['mode'] & 0170000) === 0120000
                    && ($directoryOperand || $settings['links'] === 'logical'
                        || ($settings['links'] === 'arguments' && $frame['depth'] === 0))) {
                    $stat = coreutilsFsCall(function () use ($path) {
                        clearstatcache(true, $path);
                        return stat($path);
                    }, $warning);
                }
                if ($stat === false) {
                    coreutilsAddError($result, coreutilsFsError('du', 'inspect', $name, $warning));
                    array_pop($stack);
                    continue;
                }
                $directory = ($stat['mode'] & 0170000) === 0040000;
                if (!$directory && $directoryOperand) {
                    coreutilsAddError($result, coreutilsFsError('du', 'inspect', $name, 'Not a directory'));
                    array_pop($stack);
                    continue;
                }
                if ($frame['depth'] === 0) {
                    $rootDevice = $stat['dev'];
                } elseif ($directory && ($options['one-file-system'] ?? false) && $stat['dev'] !== $rootDevice) {
                    array_pop($stack);
                    continue;
                }
                // Some portable filesystems return inode 0: do not merge unrelated entries.
                $key = isset($stat['ino'], $stat['dev']) && $stat['ino'] > 0 ? $stat['dev'] . ':' . $stat['ino'] : null;
                $activeKey = $key;
                if ($directory && $activeKey === null) {
                    $real = coreutilsFsCall(fn () => realpath($path), $warning);
                    if ($real === false) {
                        coreutilsAddError($result, coreutilsFsError('du', 'resolve directory', $name, $warning));
                        array_pop($stack);
                        continue;
                    }
                    $activeKey = 'path:' . $real;
                }
                if (($directory && isset($active[$activeKey]))
                    || (!($options['count-links'] ?? false) && $key !== null && isset($seen[$key]))) {
                    array_pop($stack);
                    continue;
                }
                if ($key !== null) {
                    $seen[$key] = true;
                }
                $frame['directory'] = $directory;
                $frame['active-key'] = $activeKey;
                $frame['bytes'] = coreutilsDuBytes($stat, $settings['apparent'], $name, $result);
                $frame['full'] = $frame['bytes'];
                $frame['type'] = [0100000 => 'f', 0040000 => 'd', 0120000 => 'l',
                    0010000 => 'p', 0020000 => 'c', 0060000 => 'b', 0140000 => 's'][$stat['mode'] & 0170000] ?? '?';
                $frame['children'] = [];
                $frame['next'] = 0;
                if ($directory) {
                    $active[$activeKey] = true;
                    $children = coreutilsFsCall(fn () => scandir($path, SCANDIR_SORT_ASCENDING), $warning);
                    if ($children === false) {
                        coreutilsAddError($result, coreutilsFsError('du', 'read directory', $name, $warning));
                    } else {
                        $frame['children'] = array_values(array_diff($children, ['.', '..']));
                    }
                }
                $stack[$index] = $frame;
            }
            if ($frame['next'] < count($frame['children'])) {
                $child = $frame['children'][$frame['next']];
                $stack[$index]['next']++;
                $stack[] = ['name' => rtrim($frame['name'], $separators) . DIRECTORY_SEPARATOR . $child,
                    'path' => rtrim($frame['path'], $separators) . DIRECTORY_SEPARATOR . $child,
                    'depth' => $frame['depth'] + 1];
                continue;
            }
            array_pop($stack);
            if ($frame['directory']) {
                unset($active[$frame['active-key']]);
            }
            if (($frame['directory'] || $frame['depth'] === 0 || ($options['all'] ?? false))
                && ($settings['max-depth'] === null || $frame['depth'] <= $settings['max-depth'])) {
                $result['data']['entries'][] = ['name' => $frame['name'], 'path' => $frame['path'],
                    'type' => $frame['type'], 'depth' => $frame['depth'], 'bytes' => $frame['bytes']];
            }
            if ($stack) {
                $parent = count($stack) - 1;
                $stack[$parent]['full'] = coreutilsDuSum($stack[$parent]['full'], $frame['full'], $frame['name'], $result);
                if (!$frame['directory'] || !($options['separate-dirs'] ?? false)) {
                    $stack[$parent]['bytes'] = coreutilsDuSum($stack[$parent]['bytes'], $frame['bytes'], $frame['name'], $result);
                }
            } else {
                $result['data']['total'] = coreutilsDuSum($result['data']['total'], $frame['full'], $arg, $result);
            }
        }
    }
    return $result;
}
