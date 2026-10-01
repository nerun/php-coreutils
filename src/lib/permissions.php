<?php

// PHP Coreutils - Copyright (c) 2026 Daniel Dias Rodrigues
// Distributed under the MIT License; see LICENSE.

/** Compile octal/operator-octal or symbolic modes before touching any files. */
function coreutilsChmodMode(string $mode): ?array
{
    if (preg_match('/\A([+\-=]?)([0-7]+)\z/', $mode, $parts)) {
        $digits = ltrim($parts[2], '0');
        if (strlen($digits) > 4) {
            return null;
        }
        return ['numeric' => $digits === '' ? 0 : octdec($digits), 'operator' => $parts[1],
            'preserve' => $parts[1] === '' && strlen($parts[2]) <= 4];
    }
    $changes = [];
    foreach (explode(',', $mode) as $clause) {
        if (!preg_match('/\A([ugoa]*)([+\-=].*)\z/', $clause, $parts)) {
            return null;
        }
        $who = $parts[1];
        $rest = $parts[2];
        while ($rest !== '') {
            if (!preg_match('/\A([+\-=])([rwxXst]*|[ugo])(?=[+\-=]|\z)/', $rest, $operation)) {
                return null;
            }
            $changes[] = ['who' => $who, 'operator' => $operation[1], 'permissions' => $operation[2]];
            $rest = substr($rest, strlen($operation[0]));
        }
    }
    return $changes ? ['changes' => $changes] : null;
}

/** Apply changes in order; umask filters additions/removals with an omitted who. */
function coreutilsChmodApply(array $compiled, int $mode, bool $directory, int $mask): int
{
    $mode &= 07777;
    if (isset($compiled['numeric'])) {
        $bits = $compiled['numeric'];
        if ($compiled['operator'] === '+') {
            return $mode | $bits;
        }
        if ($compiled['operator'] === '-') {
            return $mode & ~$bits;
        }
        return $bits | ($directory && $compiled['preserve'] ? $mode & 06000 : 0);
    }
    foreach ($compiled['changes'] as $change) {
        $who = $change['who'];
        $permissions = $change['permissions'];
        $operator = $change['operator'];
        $selected = $who === '' || strpos($who, 'a') !== false ? 'ugo' : $who;
        $affected = $bits = 0;
        $copy = in_array($permissions, ['u', 'g', 'o'], true)
            ? ($mode >> ['u' => 6, 'g' => 3, 'o' => 0][$permissions]) & 7 : null;
        foreach (['u' => [6, 04000], 'g' => [3, 02000], 'o' => [0, 01000]] as $class => [$shift, $special]) {
            if (strpos($selected, $class) === false) {
                continue;
            }
            $affected |= 7 << $shift;
            if (!$directory || $class === 'o' || strpos($permissions, 's') !== false) {
                $affected |= $special;
            }
            $access = $copy ?? 0;
            if ($copy === null) {
                $access |= strpos($permissions, 'r') !== false ? 4 : 0;
                $access |= strpos($permissions, 'w') !== false ? 2 : 0;
                $access |= strpos($permissions, 'x') !== false
                    || (strpos($permissions, 'X') !== false && ($directory || ($mode & 0111))) ? 1 : 0;
            }
            $bits |= $access << $shift;
            if (strpos($permissions, $class === 'o' ? 't' : 's') !== false) {
                $bits |= $special;
            }
        }
        if ($who === '') {
            $bits &= ~($mask & 0777);
            if ($operator !== '=') {
                $affected &= ~($mask & 0777);
            }
        }
        $mode = $operator === '+' ? $mode | $bits
            : ($operator === '-' ? $mode & ~($bits & $affected) : ($mode & ~$affected) | $bits);
    }
    return $mode & 07777;
}
