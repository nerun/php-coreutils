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

/* Usage:
 * '/lib/locale.php' is the correct path if the script that calls it is located in /src.
 * 
 * require_once __DIR__ . '/lib/locale.php';
 * 
 * $currentLocale = setAppLocale();
 * setlocale(LC_COLLATE, $currentLocale);
 * setlocale(LC_TIME, $currentLocale);
 *
 * or (avoid this):
 * setlocale(LC_ALL, $currentLocale);
 */

function setAppLocale($locale = null) {
    if ($locale === null && isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $locale = locale_accept_from_http($_SERVER['HTTP_ACCEPT_LANGUAGE']);
    }

    if ($locale) {
        $locale = str_replace('-', '_', $locale) . '.UTF-8';
    }

    if (!$locale || !setlocale(LC_COLLATE, $locale)) {
        $locale = 'C';
        setlocale(LC_COLLATE, $locale);
    }

    return $locale;
}
