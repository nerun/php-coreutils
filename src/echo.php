<?php

// PHP Coreutils - Copyright (c) 2026 Daniel Dias Rodrigues
// Distributed under the MIT License; see LICENSE.

require_once __DIR__ . '/lib/parser.php';
require_once __DIR__ . '/lib/writing.php';

/** Decode GNU echo's byte escapes; unknown escapes and a final backslash stay literal. */
function coreutilsEchoEscapes(string $text): array
{
    $output = '';
    $escapes = ['a' => "\x07", 'b' => "\x08", 'e' => "\x1b", 'f' => "\x0c",
        'n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\x0b", '\\' => '\\'];
    for ($i = 0, $length = strlen($text); $i < $length; $i++) {
        if ($text[$i] !== '\\' || $i + 1 === $length) {
            $output .= $text[$i];
            continue;
        }
        $char = $text[++$i];
        if ($char === 'c') {
            return [$output, true];
        }
        if (isset($escapes[$char])) {
            $output .= $escapes[$char];
            continue;
        }
        if ($char >= '0' && $char <= '7') {
            $digits = $char === '0' ? '' : $char;
            while (strlen($digits) < 3 && $i + 1 < $length && strpos('01234567', $text[$i + 1]) !== false) {
                $digits .= $text[++$i];
            }
            $output .= chr(($digits === '' ? 0 : octdec($digits)) & 255);
            continue;
        }
        if ($char === 'x') {
            $digits = '';
            while (strlen($digits) < 2 && $i + 1 < $length && strpos('0123456789abcdefABCDEF', $text[$i + 1]) !== false) {
                $digits .= $text[++$i];
            }
            if ($digits !== '') {
                $output .= chr(hexdec($digits));
                continue;
            }
        }
        $output .= '\\' . $char;
    }
    return [$output, false];
}

/** Generate GNU-style echo output or redirect its exact bytes without executing a shell. */
function _echo(array $input, ?string $cwd = null): array
{
    $result = coreutilsResult('echo', ['content' => '', 'redirect' => null]);
    [$options, $args, $errors] = coreutilsValidateInput('echo', $input);
    $options['posixly-correct'] = $options['posixly-correct'] ?? (getenv('POSIXLY_CORRECT') !== false);
    $result['options'] = $options;
    $redirect = $input['redirect'] ?? null;
    if ($redirect !== null) {
        if (!is_array($redirect) || count($redirect) !== 2 || !array_key_exists('path', $redirect)
            || !isset($redirect['mode']) || !in_array($redirect['mode'], ['overwrite', 'append'], true)) {
            $errors[] = coreutilsError('echo', 'invalid-redirection', 'redirect requires a path and an overwrite or append mode');
        } else {
            $error = coreutilsValidateLocalPath('echo', $redirect['path']);
            if ($error !== null) {
                $errors[] = $error;
            }
        }
    }
    foreach ($errors as $error) {
        coreutilsAddError($result, $error, 2);
    }
    if ($result['status'] !== 0) {
        return $result;
    }
    if ($options['help'] ?? false) {
        $result['help'] = coreutilsHelp('echo');
        $result['data']['content'] = $result['help'];
    } elseif ($options['version'] ?? false) {
        $result['data']['content'] = "echo (PHP Coreutils)\nCopyright (c) 2026 Daniel Dias Rodrigues\n"
            . "Distributed under the MIT License.\n";
    } else {
        $interpret = false;
        foreach ($options as $name => $enabled) {
            if ($enabled === true && in_array($name, ['escapes', 'literal'], true)) {
                $interpret = $name === 'escapes';
            }
        }
        $interpret = $interpret || $options['posixly-correct'];
        $content = implode(' ', $args);
        [$content, $stop] = $interpret ? coreutilsEchoEscapes($content) : [$content, false];
        $result['data']['content'] = $content . (!$stop && !($options['no-newline'] ?? false) ? "\n" : '');
    }
    if ($redirect === null) {
        return $result;
    }
    $result['data']['redirect'] = ['target' => $redirect['path'], 'path' => null,
        'mode' => $redirect['mode'], 'bytes' => 0, 'complete' => false];
    $base = coreutilsWorkingDirectory($cwd, $warning);
    if ($base === false) {
        coreutilsAddError($result, coreutilsError('echo', 'invalid-cwd', $warning));
        return $result;
    }
    $path = coreutilsResolvePath($redirect['path'], $base);
    $result['data']['redirect']['path'] = $path;
    $bytes = 0;
    $success = coreutilsWriteFile($path, $result['data']['content'], $redirect['mode'] === 'append', $bytes, $warning);
    $result['data']['redirect']['bytes'] = $bytes;
    $result['data']['redirect']['complete'] = $success;
    if (!$success) {
        coreutilsAddError($result, coreutilsFsError('echo', 'write', $redirect['path'], $warning));
    }
    return $result;
}
