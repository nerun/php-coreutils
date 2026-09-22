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

/** Locale helpers have no process-wide side effects. intl is optional. */
function coreutilsCollator(?string $locale) {
    if ($locale === null || $locale === 'C' || $locale === 'POSIX' || !class_exists('Collator')) {
        return null;
    }
    try {
        return new Collator($locale);
    } catch (Throwable $error) {
        return null;
    }
}

function coreutilsCompare(string $left, string $right, $collator = null): int {
    $comparison = $collator === null ? false : $collator->compare($left, $right);
    return $comparison === false ? strcmp($left, $right) : $comparison;
}

function coreutilsDateFormatter(?string $locale) {
    if (!class_exists('IntlDateFormatter')) return null;
    try {
        return new IntlDateFormatter(
            $locale === null || $locale === 'C' || $locale === 'POSIX' ? 'en_US_POSIX' : $locale,
            IntlDateFormatter::MEDIUM,
            IntlDateFormatter::SHORT,
            date_default_timezone_get(),
            IntlDateFormatter::GREGORIAN,
            'MMM dd yyyy HH:mm'
        );
    } catch (Throwable $error) {
        return null;
    }
}

function coreutilsFormatDate(int $timestamp, $formatter = null): string {
    if ($formatter !== null) {
        $formatted = $formatter->format($timestamp);
        if ($formatted !== false) {
            return preg_replace('/^(\p{L}+)\./u', '$1', $formatted);
        }
    }
    return (new DateTimeImmutable('@' . $timestamp))
        ->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('M d Y H:i');
}
