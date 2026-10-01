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

function coreutilsHumanSize($bytes, bool $si = false): string
{
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

function coreutilsQuoteName(string $name): string
{
    if (!preg_match('/[\s\x00-\x1f\x7f"\'\\\\]/', $name)) {
        return $name;
    }
    return json_encode($name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

function coreutilsIdentity(int $id, bool $group, array &$cache): string
{
    if (isset($cache[$id])) {
        return $cache[$id];
    }
    $function = $group ? 'posix_getgrgid' : 'posix_getpwuid';
    $record = function_exists($function) ? coreutilsFsCall(fn () => $function($id)) : false;
    return $cache[$id] = $record === false ? (string) $id : (string) $record['name'];
}

function coreutilsFormatEntries(array $entries, array $settings, $formatter, array &$users, array &$groups): array
{
    $rows = [];
    $widths = [];
    foreach ($entries as $entry) {
        $name = coreutilsQuoteName($entry['name']);
        if (!$settings['long']) {
            $rows[] = [$name];
            continue;
        }
        $row = [$entry['permissions'], (string) $entry['nlink']];
        if ($settings['owner']) {
            $row[] = coreutilsIdentity($entry['uid'], false, $users);
        }
        if ($settings['group']) {
            $row[] = coreutilsIdentity($entry['gid'], true, $groups);
        }
        $row[] = $settings['size'] === 'bytes' ? (string) $entry['size']
            : coreutilsHumanSize($entry['size'], $settings['size'] === 'si');
        $row[] = coreutilsFormatDate($entry['mtime'], $formatter);
        if ($entry['target'] !== null) {
            $name .= ' -> ' . coreutilsQuoteName($entry['target']);
        }
        $row[] = $name;
        foreach ($row as $i => $cell) {
            if ($i !== count($row) - 1) {
                $widths[$i] = max($widths[$i] ?? 0, strlen($cell));
            }
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

/** Produce portable stat fields; PHP exposes timestamps only at whole-second precision. */
function coreutilsStatValues(array $entry, array &$users, array &$groups): array
{
    $name = coreutilsQuoteName($entry['name']);
    if ($entry['target'] !== null) {
        $name .= ' -> ' . coreutilsQuoteName($entry['target']);
    }
    return [
        '%' => '%', 'n' => $entry['name'], 'N' => $name, 's' => (string) $entry['size'],
        'a' => decoct($entry['mode'] & 07777), 'A' => $entry['permissions'],
        'f' => dechex($entry['mode']), 'F' => $entry['filetype'],
        'u' => (string) $entry['uid'], 'U' => coreutilsIdentity($entry['uid'], false, $users),
        'g' => (string) $entry['gid'], 'G' => coreutilsIdentity($entry['gid'], true, $groups),
        'h' => (string) $entry['nlink'], 'i' => (string) $entry['ino'],
        'd' => (string) $entry['dev'], 'D' => dechex($entry['dev']),
        'r' => (string) $entry['rdev'], 'R' => dechex($entry['rdev']),
        'b' => $entry['blocks'] === null ? '?' : (string) $entry['blocks'], 'B' => '512',
        'o' => $entry['blksize'] === null ? '?' : (string) $entry['blksize'],
        'x' => date('Y-m-d H:i:s O', $entry['atime']), 'X' => (string) $entry['atime'],
        'y' => date('Y-m-d H:i:s O', $entry['mtime']), 'Y' => (string) $entry['mtime'],
        'z' => date('Y-m-d H:i:s O', $entry['ctime']), 'Z' => (string) $entry['ctime'],
        'w' => '-', 'W' => '0',
    ];
}

function coreutilsStatText(array $result): string
{
    $users = $groups = [];
    $format = isset($result['options']['format']) ? coreutilsStatFormat($result['options']['format']) : null;
    $output = '';
    foreach ($result['data']['entries'] as $entry) {
        $values = coreutilsStatValues($entry, $users, $groups);
        if ($format !== null) {
            foreach ($format as $part) {
                if (is_string($part)) {
                    $output .= $part;
                    continue;
                }
                $value = $values[$part['code']];
                $numeric = strpos('abBdDfghiorRsuWXYZ', $part['code']) !== false;
                $padding = $part['flag'] === '0' && $numeric && $value !== '?' ? '0' : ' ';
                $side = $part['flag'] === '-' ? STR_PAD_RIGHT : STR_PAD_LEFT;
                // A minus sign precedes numeric zero padding, as in printf.
                if ($padding === '0' && $value !== '' && $value[0] === '-' && is_numeric($value)) {
                    $value = '-' . str_pad(substr($value, 1), max(0, $part['width'] - 1), '0', STR_PAD_LEFT);
                } else {
                    $value = str_pad($value, $part['width'], $padding, $side);
                }
                $output .= $value;
            }
            $output .= "\n";
            continue;
        }
        $output .= '  File: ' . $values['N'] . "\n"
            . '  Size: ' . $values['s'] . "\tBlocks: " . $values['b']
            . "\tIO Block: " . $values['o'] . '  ' . $values['F'] . "\n"
            . 'Device: ' . $values['D'] . 'h/' . $values['d'] . 'd'
            . "\tInode: " . $values['i'] . "  Links: " . $values['h'] . "\n"
            . 'Access: (' . str_pad($values['a'], 4, '0', STR_PAD_LEFT) . '/' . $values['A'] . ')'
            . '  Uid: (' . $values['u'] . '/' . $values['U'] . ')'
            . '  Gid: (' . $values['g'] . '/' . $values['G'] . ")\n"
            . 'Access: ' . $values['x'] . "\nModify: " . $values['y']
            . "\nChange: " . $values['z'] . "\n Birth: -\n";
    }
    return $output;
}

/** Format both diagnostics and output as plain text; the original result stays reusable. */
function coreutilsText(array $result): string
{
    $lines = array_column($result['errors'], 'message');
    if ($result['command'] === 'echo') {
        return ($lines ? implode("\n", $lines) . "\n" : '')
            . ($result['data']['redirect'] === null ? $result['data']['content'] : '');
    }
    if ($result['help'] !== null) {
        return implode("\n", $lines) . ($lines ? "\n" : '') . $result['help'];
    }
    if ($result['command'] === 'stat') {
        return ($lines ? implode("\n", $lines) . "\n" : '') . coreutilsStatText($result);
    }
    if ($result['command'] === 'chmod') {
        $report = null;
        foreach ($result['options'] as $name => $enabled) {
            if ($enabled === true && in_array($name, ['verbose', 'changes'], true)) {
                $report = $name;
            }
        }
        foreach ($result['data']['entries'] as $entry) {
            if ($report === null || ($report === 'changes' && $entry['changed'] !== true)) {
                continue;
            }
            $before = str_pad(decoct($entry['before']), 4, '0', STR_PAD_LEFT);
            $after = $entry['after'] === null ? '?' : str_pad(decoct($entry['after']), 4, '0', STR_PAD_LEFT);
            $lines[] = 'mode of ' . coreutilsQuoteName($entry['name']) . ($entry['changed'] === false
                ? ' retained as ' . $after : ' changed from ' . $before . ' to ' . $after);
        }
        return $lines ? implode("\n", $lines) . "\n" : '';
    }
    if (in_array($result['command'], ['cat', 'head', 'tail'], true)) {
        $output = $lines ? implode("\n", $lines) . "\n" : '';
        if ($result['data']['streamed']) {
            return $output;
        }
        foreach ($result['data']['entries'] as $index => $entry) {
            if ($result['data']['headers']) {
                $output .= ($index > 0 ? "\n" : '') . '==> ' . coreutilsQuoteName($entry['name']) . " <==\n";
            }
            $output .= $entry['content'];
        }
        return $output;
    }
    if (in_array($result['command'], ['basename', 'dirname'], true)) {
        $separator = ($result['options']['zero'] ?? false) ? "\0" : "\n";
        $values = array_column($result['data']['entries'], 'output');
        return ($lines ? implode("\n", $lines) . "\n" : '')
            . ($values ? implode($separator, $values) . $separator : '');
    }
    if ($result['command'] === 'ls') {
        $settings = coreutilsLsSettings($result['options']);
        $formatter = $settings['long'] ? coreutilsDateFormatter($result['locale'] ?? null) : null;
        $users = $groups = [];
        $files = $result['data']['files'];
        $directories = $result['data']['directories'];
        $lines = array_merge($lines, coreutilsFormatEntries($files, $settings, $formatter, $users, $groups));
        $headers = count($files) + count($directories) > 1;
        foreach ($directories as $index => $directory) {
            if ($files || $index > 0) {
                $lines[] = '';
            }
            if ($headers) {
                $lines[] = coreutilsQuoteName($directory['name']) . ':';
            }
            if (!$directory['readable']) {
                continue;
            }
            if ($settings['long']) {
                $blocks = $directory['blocks'];
                $total = $blocks === null ? '?' : ($settings['size'] === 'bytes'
                    ? (string) ceil($blocks / 2) : coreutilsHumanSize($blocks * 512, $settings['size'] === 'si'));
                $lines[] = 'total ' . $total;
            }
            $lines = array_merge($lines, coreutilsFormatEntries($directory['entries'], $settings, $formatter, $users, $groups));
        }
    }
    if ($result['command'] === 'pwd' && $result['data']['path'] !== null) {
        $lines[] = $result['data']['path'];
    }
    if ($result['command'] === 'find') {
        foreach ($result['data']['entries'] as $entry) {
            $lines[] = coreutilsQuoteName($entry['name']);
        }
    }
    if ($result['command'] === 'mv' && ($result['options']['verbose'] ?? false)) {
        foreach ($result['data']['moved'] as $move) {
            $lines[] = coreutilsQuoteName($move['source']) . ' -> ' . coreutilsQuoteName($move['destination']);
        }
    }
    if ($result['command'] === 'cp' && ($result['options']['verbose'] ?? false)) {
        foreach (array_merge($result['data']['created'], $result['data']['copied']) as $copy) {
            $lines[] = coreutilsQuoteName($copy['source']) . ' -> ' . coreutilsQuoteName($copy['destination']);
        }
    }
    if (in_array($result['command'], ['rm', 'rmdir'], true) && ($result['options']['verbose'] ?? false)) {
        foreach ($result['data']['removed'] as $path) {
            $lines[] = 'removed ' . coreutilsQuoteName($path);
        }
    }
    return $lines ? implode("\n", $lines) . "\n" : '';
}

/** Escape the complete presentation, including names, link targets and errors. */
function coreutilsHtml(array $result): string
{
    return '<pre style="margin: 0;">'
        . htmlspecialchars(coreutilsText($result), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
}
