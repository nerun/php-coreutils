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

require_once __DIR__ . '/../ls.php';

function coreutilsHumanSize($bytes, bool $si = false): string {
    $base = $si ? 1000 : 1024;
    $units = $si ? ['', 'k', 'M', 'G', 'T', 'P', 'E'] : ['', 'K', 'M', 'G', 'T', 'P', 'E'];
    $i = 0;
    while ($bytes >= $base && $i < count($units) - 1) {
        $bytes /= $base;
        $i++;
    }
    $digits = $i > 0 && $bytes < 10 ? 1 : 0;
    $factor = 10 ** $digits;
    $rounded = ceil($bytes * $factor) / $factor;
    if ($rounded >= $base && $i < count($units) - 1) {
        $rounded /= $base;
        $i++;
        $digits = 1;
    }
    // number_format keeps the decimal separator independent of LC_NUMERIC.
    return number_format($rounded, $digits, '.', '') . $units[$i];
}

function coreutilsQuoteName(string $name): string {
    if (!preg_match('/[\s\x00-\x1f\x7f"\'\\\\]/', $name)) return $name;
    return json_encode($name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

function coreutilsIdentity(int $id, bool $group, array &$cache): string {
    if (isset($cache[$id])) return $cache[$id];
    $function = $group ? 'posix_getgrgid' : 'posix_getpwuid';
    $record = function_exists($function) ? coreutilsFsCall(fn() => $function($id)) : false;
    return $cache[$id] = $record === false ? (string) $id : (string) $record['name'];
}

function coreutilsFormatEntries(array $entries, array $settings, $formatter, array &$users, array &$groups): array {
    $rows = [];
    $widths = [];
    foreach ($entries as $entry) {
        $name = coreutilsQuoteName($entry['name']);
        if (!$settings['long']) {
            $rows[] = [$name];
            continue;
        }
        $row = [$entry['permissions'], (string) $entry['nlink']];
        if ($settings['owner']) $row[] = coreutilsIdentity($entry['uid'], false, $users);
        if ($settings['group']) $row[] = coreutilsIdentity($entry['gid'], true, $groups);
        $row[] = $settings['size'] === 'bytes' ? (string) $entry['size']
            : coreutilsHumanSize($entry['size'], $settings['size'] === 'si');
        $row[] = coreutilsFormatDate($entry['mtime'], $formatter);
        if ($entry['target'] !== null) $name .= ' -> ' . coreutilsQuoteName($entry['target']);
        $row[] = $name;
        foreach ($row as $i => $cell) {
            if ($i !== count($row) - 1) $widths[$i] = max($widths[$i] ?? 0, strlen($cell));
        }
        $rows[] = $row;
    }
    $lines = [];
    foreach ($rows as $row) {
        foreach ($widths as $i => $width) {
            $right = $i === 1 || $i === count($row) - 3;
            $row[$i] = str_pad($row[$i], $width, ' ', $right ? STR_PAD_LEFT : STR_PAD_RIGHT);
        }
        $lines[] = implode(' ', $row);
    }
    return $lines;
}

/** Format both diagnostics and output as plain text; the original result stays reusable. */
function coreutilsText(array $result): string {
    $lines = array_column($result['errors'], 'message');
    if ($result['help'] !== null) return implode("\n", $lines) . ($lines ? "\n" : '') . $result['help'];
    if ($result['command'] === 'ls') {
        $settings = coreutilsLsSettings($result['options']);
        $formatter = $settings['long'] ? coreutilsDateFormatter($result['locale'] ?? null) : null;
        $users = $groups = [];
        $files = $result['data']['files'];
        $directories = $result['data']['directories'];
        $lines = array_merge($lines, coreutilsFormatEntries($files, $settings, $formatter, $users, $groups));
        $headers = count($files) + count($directories) > 1;
        foreach ($directories as $index => $directory) {
            if ($files || $index > 0) $lines[] = '';
            if ($headers) $lines[] = coreutilsQuoteName($directory['name']) . ':';
            if (!$directory['readable']) continue;
            if ($settings['long']) {
                $blocks = $directory['blocks'];
                $total = $blocks === null ? '?' : ($settings['size'] === 'bytes'
                    ? (string) ceil($blocks / 2) : coreutilsHumanSize($blocks * 512, $settings['size'] === 'si'));
                $lines[] = 'total ' . $total;
            }
            $lines = array_merge($lines, coreutilsFormatEntries($directory['entries'], $settings, $formatter, $users, $groups));
        }
    }
    return $lines ? implode("\n", $lines) . "\n" : '';
}

/** Escape the complete presentation, including names, link targets and errors. */
function coreutilsHtml(array $result): string {
    return '<pre style="margin: 0;">'
        . htmlspecialchars(coreutilsText($result), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
}
