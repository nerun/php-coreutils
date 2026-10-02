<?php

// PHP Coreutils - Copyright (c) 2026 Daniel Dias Rodrigues
// Distributed under the MIT License; see LICENSE.

require_once __DIR__ . '/lib/searching.php';

/** Keep grep policy separate from file reading; conflicting matchers are input errors. */
function coreutilsGrepSettings(array $options, int $operands): array
{
    $matchers = [];
    foreach (['basic-regexp' => 'basic', 'extended-regexp' => 'extended', 'fixed-strings' => 'fixed'] as $name => $mode) {
        if ($options[$name] ?? false) {
            $matchers[] = $mode;
        }
    }
    if (count($matchers) > 1) {
        return ['error' => 'conflicting matchers specified'];
    }
    $settings = ['matcher' => $matchers[0] ?? 'basic', 'ignore-case' => false,
        'word' => $options['word-regexp'] ?? false, 'line' => $options['line-regexp'] ?? false,
        'mode' => ($options['count'] ?? false) ? 'count' : 'lines',
        'quiet' => ($options['quiet'] ?? false) || ($options['silent'] ?? false),
        'recursive' => null, 'filename' => $operands > 1, 'filename-policy' => null, 'implicit-root' => false,
        'binary' => 'binary', 'max-count' => null,
        'delimiter' => ($options['null-data'] ?? false) ? "\0" : "\n"];
    foreach ($options as $name => $value) {
        if ($value === false) {
            continue;
        }
        if ($name === 'ignore-case' || $name === 'no-ignore-case') {
            $settings['ignore-case'] = $name === 'ignore-case';
        } elseif ($name === 'files-with-matches' || $name === 'files-without-match') {
            $settings['mode'] = $name;
        } elseif ($name === 'recursive' || $name === 'dereference-recursive') {
            $settings['recursive'] = $name === 'recursive' ? 'physical' : 'dereference';
        } elseif ($name === 'text' || $name === 'binary-without-match' || $name === 'binary-files') {
            $settings['binary'] = $name === 'text' ? 'text' : ($name === 'binary-without-match' ? 'without-match' : $value);
        }
    }
    if (!in_array($settings['binary'], ['binary', 'text', 'without-match'], true)) {
        return ['error' => 'binary-files must be binary, text or without-match'];
    }
    if ($options['dereference-recursive'] ?? false) {
        $settings['recursive'] = 'dereference';
    }
    foreach ($options as $name => $value) {
        if ($value && ($name === 'with-filename' || $name === 'no-filename')) {
            $settings['filename'] = $name === 'with-filename';
            $settings['filename-policy'] = $settings['filename'];
        }
    }
    if (isset($options['max-count'])) {
        $count = coreutilsReadingCount($options['max-count']);
        if ($count === null || $count['sign'] === '-') {
            return ['error' => 'max count must be a nonnegative integer'];
        }
        $settings['max-count'] = $count['count'];
    }
    return $settings;
}

/** Search local regular files without printing; a callback can consume output incrementally. */
function grep(array $input, ?string $cwd = null, ?callable $write = null): array
{
    $result = coreutilsResult('grep', ['entries' => [], 'streamed' => $write !== null,
        'matched' => false, 'warnings' => [], 'settings' => []]);
    [$options, $args, $errors] = coreutilsValidateInput('grep', $input);
    $result['options'] = $options;
    foreach ($errors as $error) {
        coreutilsAddError($result, $error, 2);
    }
    if ($result['status'] !== 0) {
        return $result;
    }
    if ($options['help'] ?? false) {
        $result['help'] = coreutilsHelp('grep');
        return $result;
    }
    $patterns = [];
    if (isset($options['regexp']) || isset($options['file'])) {
        foreach ((array) ($options['regexp'] ?? []) as $pattern) {
            $patterns = array_merge($patterns, coreutilsGrepPatterns($pattern, false));
        }
    } elseif ($args) {
        $patterns = coreutilsGrepPatterns(array_shift($args), false);
    } else {
        coreutilsAddError($result, coreutilsError('grep', 'missing-pattern', 'missing search pattern'), 2);
    }
    $settings = coreutilsGrepSettings($options, count($args));
    if (isset($settings['error'])) {
        coreutilsAddError($result, coreutilsError('grep', 'invalid-option', $settings['error']), 2);
    }
    if (!$args && !isset($settings['error'])) {
        if ($settings['recursive'] !== null) {
            $args = ['.'];
            $settings['implicit-root'] = true;
        } else {
            coreutilsAddError($result, coreutilsError('grep', 'missing-operand', 'missing file operand; standard input is not supported'), 2);
        }
    }
    foreach ((array) ($options['file'] ?? []) as $file) {
        $error = coreutilsValidateLocalPath('grep', $file);
        if ($error !== null) {
            coreutilsAddError($result, $error, 2);
        }
    }
    if ($result['status'] !== 0) {
        return $result;
    }
    $result['data']['settings'] = $settings;
    // Compile command-line patterns before attempting filesystem access.
    $compiled = coreutilsGrepCompile($patterns, $settings, $result);
    if ($compiled === null) {
        return $result;
    }
    $base = coreutilsWorkingDirectory($cwd, $warning);
    if ($base === false) {
        coreutilsAddError($result, coreutilsError('grep', 'invalid-cwd', $warning), 2);
        return $result;
    }
    foreach ((array) ($options['file'] ?? []) as $file) {
        $path = coreutilsResolvePath($file, $base);
        $stat = null;
        $stream = coreutilsOpenReadingFile($path, $stat, $warning);
        if ($stream === false) {
            coreutilsAddError($result, coreutilsFsError('grep', 'read patterns', $file, $warning), 2);
            continue;
        }
        $filePatterns = [];
        try {
            $records = coreutilsGrepRecords($stream, $stat['size'], "\n", false, $warning);
            foreach ($records as $pattern) {
                $filePatterns[] = $pattern;
            }
            if ($records->getReturn() !== true) {
                coreutilsAddError($result, coreutilsFsError('grep', 'read patterns', $file, $warning), 2);
            }
        } finally {
            if (!coreutilsFsCall(fn () => fclose($stream), $warning)) {
                coreutilsAddError($result, coreutilsFsError('grep', 'close patterns', $file, $warning), 2);
            }
        }
        $extra = coreutilsGrepCompile($filePatterns, $settings, $result);
        if ($extra !== null) {
            $compiled = array_merge($compiled, $extra);
        }
    }
    if ($result['status'] !== 0) {
        return $result;
    }
    // As in GNU grep, -m0 needs no input traversal unless listing nonmatching files.
    if ($settings['max-count'] === 0 && $settings['mode'] !== 'files-without-match') {
        $result['status'] = 1;
        return $result;
    }
    $stopped = false;
    foreach (coreutilsGrepPaths($args, $base, $settings, $result) as $candidate) {
        $name = $candidate['name'];
        $path = $candidate['path'];
        $filename = $settings['filename-policy'] ?? ($settings['filename'] || $candidate['nested']);
        $stat = null;
        $stream = coreutilsOpenReadingFile($path, $stat, $warning);
        if ($stream === false) {
            coreutilsAddError($result, coreutilsFsError('grep', 'read', $name, $warning), 2);
            continue;
        }
        $entry = ['name' => $name, 'path' => $path, 'content' => $write === null ? '' : null,
            'bytes' => 0, 'complete' => true, 'matches' => 0, 'scanned' => 0, 'binary' => false];
        try {
            if ($settings['binary'] !== 'text' && $settings['delimiter'] !== "\0" && $settings['max-count'] !== 0) {
                $binary = coreutilsGrepBinary($stream, $stat['size'], $warning);
                if ($binary === null) {
                    $entry['complete'] = false;
                    coreutilsAddError($result, coreutilsFsError('grep', 'read', $name, $warning), 2);
                } else {
                    $entry['binary'] = $binary;
                }
            }
            if ($entry['complete'] && !($entry['binary'] && $settings['binary'] === 'without-match')
                && $settings['max-count'] !== 0) {
                $records = coreutilsGrepRecords($stream, $stat['size'], $settings['delimiter'], $entry['binary'], $warning);
                $finished = true;
                foreach ($records as $line) {
                    $entry['scanned']++;
                    $match = coreutilsGrepMatch($line, $compiled, $settings, $warning);
                    if ($match === null) {
                        $entry['complete'] = false;
                        coreutilsAddError($result, coreutilsError('grep', 'match-error', $warning, $name), 2);
                        $finished = false;
                        break;
                    }
                    if ($match === !($options['invert-match'] ?? false)) {
                        $entry['matches']++;
                        $result['data']['matched'] = true;
                        if (!$settings['quiet'] && $settings['mode'] === 'lines' && !$entry['binary']) {
                            $prefix = ($filename ? $name . ':' : '')
                                . (($options['line-number'] ?? false) ? $entry['scanned'] . ':' : '');
                            if (!coreutilsReadingOutput($prefix . $line . $settings['delimiter'], $write, $entry, $result)) {
                                $result['status'] = 2;
                                $stopped = true;
                                $finished = false;
                                break;
                            }
                        }
                        if ($settings['quiet'] || in_array($settings['mode'], ['files-with-matches', 'files-without-match'], true)
                            || ($settings['max-count'] !== null && $entry['matches'] >= $settings['max-count'])) {
                            $finished = false;
                            break;
                        }
                    }
                }
                if ($finished && $records->getReturn() !== true) {
                    $entry['complete'] = false;
                    coreutilsAddError($result, coreutilsFsError('grep', 'read', $name, $warning), 2);
                }
            }
            if (!$settings['quiet'] && !$stopped) {
                $output = '';
                if ($settings['mode'] === 'count') {
                    $output = ($filename ? $name . ':' : '') . $entry['matches'] . "\n";
                } elseif (($settings['mode'] === 'files-with-matches' && $entry['matches'] > 0)
                    || ($settings['mode'] === 'files-without-match' && $entry['matches'] === 0 && $entry['complete'])) {
                    $output = $name . "\n";
                } elseif ($settings['mode'] === 'lines' && $entry['binary'] && $entry['matches'] > 0) {
                    $output = 'grep: ' . $name . ": binary file matches\n";
                }
                if (!coreutilsReadingOutput($output, $write, $entry, $result)) {
                    $result['status'] = 2;
                    $stopped = true;
                }
            }
        } finally {
            if (!coreutilsFsCall(fn () => fclose($stream), $warning)) {
                $entry['complete'] = false;
                coreutilsAddError($result, coreutilsFsError('grep', 'close', $name, $warning), 2);
            }
        }
        $result['data']['entries'][] = $entry;
        if ($stopped || ($settings['quiet'] && $entry['matches'] > 0)) {
            break;
        }
    }
    if ($settings['quiet'] && $result['data']['matched'] && !$stopped) {
        $result['status'] = 0;
    } elseif ($result['status'] !== 2) {
        $result['status'] = $result['data']['matched'] ? 0 : 1;
    }
    return $result;
}
