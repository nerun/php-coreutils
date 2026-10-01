<?php

$tests['reading commands parse grouped options attached counts aliases and precedence'] = function () {
    same(['number' => true, 'squeeze-blank' => true, 'show-ends' => true, 'show-tabs' => true], parseCommand('cat -nsET file')['options']);
    foreach (['head', 'tail'] as $command) {
        same(['quiet' => true, 'lines' => '3'], parseCommand($command . ' -qn3 file')['options']);
        same(['bytes' => '+5'], parseCommand($command . ' --bytes=+5 file')['options']);
        same(['lines' => '-2'], parseCommand($command . ' --lines -2 file')['options']);
        same(['bytes' => '2', 'lines' => '1'], parseCommand($command . ' -n3 -c2 --lines=1 file')['options']);
    }
};

$tests['cat concatenates files byte for byte without separators or added newline'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/one', "a\0\xff\r\nlast");
    file_put_contents($base . '/two', "\tsecond\n");
    file_put_contents($base . '/empty', '');
    $result = cat(parseCommand('cat one empty two'), $base);
    same(0, $result['status']);
    same("a\0\xff\r\nlast\tsecond\n", coreutilsText($result));
    same(false, $result['data']['streamed']);
    same(false, $result['data']['headers']);
    same(['one', 'empty', 'two'], array_column($result['data']['entries'], 'name'));
    same([$base . '/one', $base . '/empty', $base . '/two'], array_column($result['data']['entries'], 'path'));
    same([true, true, true], array_column($result['data']['entries'], 'complete'));
    same(strlen("a\0\xff\r\nlast"), $result['data']['entries'][0]['bytes']);
    same("a\0\xff\r\nlast", file_get_contents($base . '/one'));
});

$tests['cat numbering and blank squeezing share logical lines across file boundaries'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/a', "alpha");
    file_put_contents($base . '/b', "beta\n\n\n");
    file_put_contents($base . '/c', "\ngamma\nlast");
    same("     1\talphabeta\n     2\t\n     3\t\n     4\t\n     5\tgamma\n     6\tlast",
        coreutilsText(cat(parseCommand('cat -n a b c'), $base)));
    same("     1\talphabeta\n\n\n\n     2\tgamma\n     3\tlast",
        coreutilsText(cat(parseCommand('cat -bn a b c'), $base)));
    same("     1\talphabeta\n\n     2\tgamma\n     3\tlast",
        coreutilsText(cat(parseCommand('cat -nbs a b c'), $base)));
    same("     1\talphabeta\n     2\t\n     3\tgamma\n     4\tlast",
        coreutilsText(cat(parseCommand('cat --number --squeeze-blank a b c'), $base)));
});

$tests['cat ends tabs CRLF and unterminated lines preserve the documented bytes'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/file', "\n\n\talpha\r\n\r\nend\t");
    same("$\n$\n^Ialpha^M$\n^M$\nend^I", coreutilsText(cat(parseCommand('cat -ET file'), $base)));
    same("$\n     1\t^Ialpha^M$\n     2\t^M$\n     3\tend^I",
        coreutilsText(cat(parseCommand('cat --show-ends --show-tabs --number-nonblank --squeeze-blank file'), $base)));
});

$tests['cat show-ends handles CRLF split across blocks files empty operands and final CR'] = fn () => fixture(function ($base) {
    $prefix = str_repeat('a', 8191);
    file_put_contents($base . '/a', $prefix . "\r");
    file_put_contents($base . '/empty', '');
    file_put_contents($base . '/b', "\nend\r");
    $input = parseCommand('cat -nE a empty b');
    $expected = "     1\t" . $prefix . "^M$\n     2\tend\r";
    same($expected, coreutilsText(cat($input, $base)));
    $output = '';
    $result = cat($input, $base, function ($block) use (&$output) {
        $output .= $block;
    });
    same(0, $result['status']);
    same($expected, $output);
    file_put_contents($base . '/a', "\r");
    same("     1\t\r", coreutilsText(cat(parseCommand('cat -nE a empty'), $base)));
    file_put_contents($base . '/b', "\n");
    same("     1\t^M$\n", coreutilsText(cat(parseCommand('cat -bnEs a empty b'), $base)));
});

$tests['head and tail default to ten lines per file and preserve missing final newline'] = fn () => fixture(function ($base) {
    $lines = [];
    for ($i = 1; $i <= 14; $i++) {
        $lines[] = 'line-' . $i;
    }
    foreach ([false, true] as $terminated) {
        file_put_contents($base . '/file', implode("\n", $lines) . ($terminated ? "\n" : ''));
        same(implode("\n", array_slice($lines, 0, 10)) . "\n", coreutilsText(head(parseCommand('head file'), $base)));
        same(implode("\n", array_slice($lines, -10)) . ($terminated ? "\n" : ''), coreutilsText(tail(parseCommand('tail file'), $base)));
    }
});

$tests['head selects first records and excludes last records with negative counts'] = fn () => fixture(function ($base) {
    foreach (["a\nb\nc\n", "a\nb\nc"] as $content) {
        file_put_contents($base . '/file', $content);
        foreach (['0' => '', '1' => "a\n", '2' => "a\nb\n", '+2' => "a\nb\n",
            '10' => $content, '-0' => $content, '-1' => "a\nb\n", '-2' => "a\n", '-3' => '', '-10' => ''] as $count => $expected) {
            $result = head(parseCommand('head -n ' . $count . ' file'), $base);
            same(0, $result['status']);
            same($expected, coreutilsText($result));
        }
    }
});

$tests['tail selects last records and one-based starts with plus counts'] = fn () => fixture(function ($base) {
    foreach (["a\nb\nc\n", "a\nb\nc"] as $content) {
        file_put_contents($base . '/file', $content);
        $end = substr($content, -1) === "\n" ? "\n" : '';
        foreach (['0' => '', '1' => 'c' . $end, '-1' => 'c' . $end, '2' => "b\nc" . $end,
            '10' => $content, '+0' => $content, '+1' => $content, '+2' => "b\nc" . $end,
            '+3' => 'c' . $end, '+4' => '', '+10' => ''] as $count => $expected) {
            $result = tail(parseCommand('tail --lines=' . $count . ' file'), $base);
            same(0, $result['status']);
            same($expected, coreutilsText($result));
        }
    }
});

$tests['head and tail byte selections preserve binary data and honor option order'] = fn () => fixture(function ($base) {
    $content = "a\0\xff\r\nbc\nend";
    file_put_contents($base . '/file', $content);
    foreach (['head -c3' => "a\0\xff", 'head -c0' => '', 'head -c-3' => "a\0\xff\r\nbc\n",
        'head -c-0' => $content, 'head -c99' => $content, 'head -c-99' => '',
        'tail -c3' => 'end', 'tail -c0' => '', 'tail -c-3' => 'end', 'tail -c+4' => "\r\nbc\nend",
        'tail -c+0' => $content, 'tail -c+1' => $content, 'tail -c+99' => '', 'tail -c99' => $content,
        'head -n1 -c2' => "a\0", 'head -c2 -n1' => "a\0\xff\r\n",
        'tail -n1 -c2' => 'nd', 'tail -c2 -n1' => 'end'] as $prefix => $expected) {
        $command = strtok($prefix, ' ');
        $result = $command(parseCommand($prefix . ' file'), $base);
        same(0, $result['status']);
        same($expected, coreutilsText($result));
    }
});

$tests['reading handles empty files blank records and single unterminated lines'] = fn () => fixture(function ($base) {
    foreach (['', "\n", "\n\n", 'only', "only\n", "a\n\n", "\r\n"] as $content) {
        file_put_contents($base . '/file', $content);
        foreach (['cat', 'head', 'tail'] as $command) {
            $result = $command(parseCommand($command . ' file'), $base);
            same(0, $result['status']);
            same($content, coreutilsText($result));
            same(true, $result['data']['entries'][0]['complete']);
        }
    }
    file_put_contents($base . '/file', "a\n\n");
    same("\n", coreutilsText(tail(parseCommand('tail -n1 file'), $base)));
    same("a\n", coreutilsText(head(parseCommand('head -n-1 file'), $base)));
});

$tests['head and tail support NUL records without altering embedded newlines'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/file', "a\nb\0c\0d");
    same("a\nb\0c\0", coreutilsText(head(parseCommand('head -zn2 file'), $base)));
    same("c\0d", coreutilsText(tail(parseCommand('tail --zero-terminated --lines=2 file'), $base)));
    same("a\nb\0c\0", coreutilsText(head(parseCommand('head -z -n-1 file'), $base)));
    same("c\0d", coreutilsText(tail(parseCommand('tail -z -n+2 file'), $base)));
    same("\nb\0c\0d", coreutilsText(tail(parseCommand('tail -z -c+2 file'), $base)));
});

$tests['reading preserves delimiters crossing blocks and very long lines'] = fn () => fixture(function ($base) {
    foreach ([8191, 8192, 8193, 16383, 16384, 16385] as $length) {
        $first = str_repeat('a', $length) . "\n";
        $last = str_repeat('z', $length);
        file_put_contents($base . '/file', $first . "middle\n" . $last);
        same($first, coreutilsText(head(parseCommand('head -n1 file'), $base)));
        same($last, coreutilsText(tail(parseCommand('tail -n1 file'), $base)));
        same($first . "middle\n", coreutilsText(head(parseCommand('head -n-1 file'), $base)));
        same($last, coreutilsText(tail(parseCommand('tail -n+3 file'), $base)));
        same("     1\t" . $first . "     2\tmiddle\n     3\t" . $last,
            coreutilsText(cat(parseCommand('cat -n file'), $base)));
    }
    file_put_contents($base . '/file', str_repeat("\n", 16390) . 'end');
    same("\nend", coreutilsText(tail(parseCommand('tail -n2 file'), $base)));
    same("\nend", coreutilsText(cat(parseCommand('cat -s file'), $base)));
});

$tests['head and tail format headers and honor quiet verbose and silent precedence'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/a', "one\n");
    file_put_contents($base . '/b', 'two');
    foreach (['head', 'tail'] as $command) {
        same("==> a <==\none\n\n==> b <==\ntwo", coreutilsText($command(parseCommand($command . ' a b'), $base)));
        same("one\ntwo", coreutilsText($command(parseCommand($command . ' -q a b'), $base)));
        same("one\ntwo", coreutilsText($command(parseCommand($command . ' --silent a b'), $base)));
        same("one\n", coreutilsText($command(parseCommand($command . ' -vq a'), $base)));
        same("==> a <==\none\n", coreutilsText($command(parseCommand($command . ' -qv a'), $base)));
        same("one\n", coreutilsText($command(parseCommand($command . ' --verbose --silent a'), $base)));
        same("==> a <==\none\n", coreutilsText($command(parseCommand($command . ' --silent --verbose a'), $base)));
    }
});

$tests['reading supports absolute dash space and symlink paths while rejecting directories'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/two words', 'spaces');
    file_put_contents($base . '/-name', 'dash');
    file_put_contents($base . '/-', 'literal');
    mkdir($base . '/directory');
    foreach (['cat', 'head', 'tail'] as $command) {
        same('spaces', coreutilsText($command(parseCommand($command . ' "two words"'), $base)));
        same('dashliteral', coreutilsText($command(parseCommand($command . ($command === 'cat' ? '' : ' -q') . ' -- -name -'), $base)));
        same('spaces', coreutilsText($command(['args' => [$base . '/two words']], $base)));
        same(1, $command(parseCommand($command . ' directory'), $base)['status']);
    }
    symlinkSupport($base);
    symlink('two words', $base . '/link');
    symlink('missing', $base . '/broken');
    symlink('directory', $base . '/dir-link');
    foreach (['cat', 'head', 'tail'] as $command) {
        same('spaces', coreutilsText($command(parseCommand($command . ' link'), $base)));
        same(1, $command(parseCommand($command . ' broken'), $base)['status']);
        same(1, $command(parseCommand($command . ' dir-link'), $base)['status']);
    }
});

$tests['reading rejects special files before opening them'] = fn () => fixture(function ($base) {
    skipUnless(function_exists('posix_mkfifo'), 'posix_mkfifo() unavailable');
    skipUnless(coreutilsFsCall(fn () => posix_mkfifo($base . '/fifo', 0600)), 'FIFO creation unavailable');
    foreach (['cat', 'head', 'tail'] as $command) {
        $result = $command(parseCommand($command . ' fifo'), $base);
        same(1, $result['status']);
        same([], $result['data']['entries']);
        check(strpos($result['errors'][0]['message'], 'Not a regular file') !== false);
    }
});

$tests['reading filesystem errors allow later operands and preserve earlier content'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/a', "one\n");
    file_put_contents($base . '/b', 'two');
    foreach (['cat', 'head', 'tail'] as $command) {
        $result = $command(parseCommand($command . ' a missing b'), $base);
        same(1, $result['status']);
        same(['a', 'b'], array_column($result['data']['entries'], 'name'));
        same(["one\n", 'two'], array_column($result['data']['entries'], 'content'));
        same('missing', $result['errors'][0]['path']);
        same('two', coreutilsText($command(parseCommand($command . ' b'), $base)));
    }
});

$tests['reading validates all input before reading or invoking output callbacks'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/file', 'keep');
    foreach (['cat', 'head', 'tail'] as $command) {
        $called = false;
        $write = function () use (&$called) {
            $called = true;
        };
        foreach (['', ' file ""', ' file file:///tmp/x', ' -x file', ' --help=yes file'] as $suffix) {
            same(2, $command(parseCommand($command . $suffix), $base, $write)['status']);
        }
        foreach ([['args' => 'bad'], ['args' => ['file', null]], ['args' => ["file\0"]],
            ['args' => ['file'], 'options' => ['unknown' => true]], ['args' => ['file'], 'flags' => ['n']], parseCommand('ls')] as $input) {
            same(2, $command($input, $base, $write)['status']);
        }
        same(false, $called);
    }
    foreach (['head', 'tail'] as $command) {
        foreach (['', '1K', '1.5', '1 2', '--1', '++2', '+-1', '999999999999999999999999999999', "2\0"] as $count) {
            $result = $command(['args' => ['file'], 'options' => ['lines' => $count]], $base);
            same(2, $result['status']);
            same([], $result['data']['entries']);
        }
        same(2, $command(['args' => ['file'], 'options' => ['lines' => 2]], $base)['status']);
        same(2, $command(parseCommand($command . ' -n file'), $base)['status']);
        same(2, $command(parseCommand($command . ' -c'), $base)['status']);
        same(2, $command(parseCommand($command . ' -nno -c1 file'), $base)['status']);
    }
    same(2, cat(['args' => ['file'], 'options' => ['number' => 1]], $base)['status']);
    same('keep', file_get_contents($base . '/file'));
});

$tests['reading count limits zero padding and help work without filesystem access'] = function () {
    same(['sign' => '', 'count' => PHP_INT_MAX], coreutilsReadingCount((string) PHP_INT_MAX));
    same(['sign' => '+', 'count' => 2], coreutilsReadingCount('+0002'));
    same(null, coreutilsReadingCount((string) PHP_INT_MAX . '0'));
    foreach (['cat', 'head', 'tail'] as $command) {
        $called = false;
        $result = $command(parseCommand($command . ' --help'), '/does-not-exist', function () use (&$called) {
            $called = true;
        });
        same(0, $result['status']);
        same([], $result['data']['entries']);
        same(false, $called);
        check(strpos(coreutilsText($result), 'Usage: ' . $command) === 0);
        same(1, $command(parseCommand($command . ' file'), '/does-not-exist')['status']);
    }
};

$tests['reading callbacks receive bounded blocks metadata and no buffered content or headers'] = fn () => fixture(function ($base) {
    $content = str_repeat('0123456789', 100000) . "\nlast\n";
    file_put_contents($base . '/large', $content);
    foreach (['cat large' => $content, 'head -c20000 large' => substr($content, 0, 20000),
        'tail -n1 large' => "last\n", 'head -n-1 large' => substr($content, 0, -5)] as $input => $expected) {
        $command = strtok($input, ' ');
        $bytes = 0;
        $hash = hash_init('sha256');
        $result = $command(parseCommand($input), $base, function ($block, $entry) use (&$bytes, $hash, $base) {
            check(strlen($block) <= 8192);
            same(['name' => 'large', 'path' => $base . '/large'], $entry);
            $bytes += strlen($block);
            hash_update($hash, $block);
        });
        same(0, $result['status']);
        same(hash('sha256', $expected), hash_final($hash));
        same(strlen($expected), $bytes);
        same($bytes, $result['data']['entries'][0]['bytes']);
        same(null, $result['data']['entries'][0]['content']);
        same(true, $result['data']['streamed']);
        same('', coreutilsText($result));
    }
});

$tests['streamed cat applies the same transformations and state as buffered cat'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/a', str_repeat('a', 9000) . "\n\n");
    file_put_contents($base . '/b', "\n\tend");
    foreach (['-n', '-bsET', '-ns'] as $options) {
        $input = parseCommand('cat ' . $options . ' a b');
        $output = '';
        $result = cat($input, $base, function ($block) use (&$output) {
            $output .= $block;
        });
        same(0, $result['status']);
        same(coreutilsText(cat($input, $base)), $output);
    }
});

$tests['reading callback refusal stops remaining files and exceptions close handles'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/a', str_repeat('a', 20000));
    file_put_contents($base . '/b', 'second');
    foreach (['cat', 'head', 'tail'] as $command) {
        $input = parseCommand($command . ($command === 'cat' ? '' : ' -c20000') . ' a b');
        $calls = 0;
        $result = $command($input, $base, function () use (&$calls) {
            return ++$calls < 2;
        });
        same(1, $result['status']);
        same(2, $calls);
        same(1, count($result['data']['entries']));
        same(8192, $result['data']['entries'][0]['bytes']);
        same(false, $result['data']['entries'][0]['complete']);
        same('output-error', $result['errors'][0]['code']);
        $before = count(get_resources('stream'));
        $failure = new RuntimeException('caller-owned failure');
        try {
            $command($input, $base, function () use ($failure) {
                throw $failure;
            });
            check(false, 'callback exception should propagate');
        } catch (RuntimeException $error) {
            same($failure, $error);
        }
        same($before, count(get_resources('stream')));
    }
});

$tests['reading leaves process state unchanged emits nothing and escapes HTML only in formatter'] = fn () => fixture(function ($base) {
    $content = "<script>&\"\xff\n";
    file_put_contents($base . '/<name>', $content);
    $cwd = getcwd();
    $locale = setlocale(LC_ALL, 0);
    $timezone = date_default_timezone_get();
    $mask = umask();
    foreach (['cat', 'head', 'tail'] as $command) {
        ob_start();
        $result = $command(['args' => ['<name>']], $base);
        same('', ob_get_clean());
        same(0, $result['status']);
        same($content, coreutilsText($result));
        same($content, $result['data']['entries'][0]['content']);
        check(strpos(coreutilsHtml($result), '<script>') === false);
        check(strpos(coreutilsHtml($result), '&lt;script&gt;') !== false);
        same($cwd, getcwd());
        same($locale, setlocale(LC_ALL, 0));
        same($timezone, date_default_timezone_get());
        same($mask, umask());
    }
    $result = head(parseCommand('head -v "<name>"'), $base);
    check(strpos(coreutilsHtml($result), '&lt;name&gt;') !== false);
});

$tests['reading warning capture restores the caller handler and denies inaccessible files'] = fn () => fixture(function ($base) {
    $called = false;
    set_error_handler(function () use (&$called) {
        $called = true;
        return true;
    });
    try {
        foreach (['cat', 'head', 'tail'] as $command) {
            same(1, $command(parseCommand($command . ' missing'), $base)['status']);
        }
        same(false, $called);
        trigger_error('reading handler probe', E_USER_WARNING);
        same(true, $called);
    } finally {
        restore_error_handler();
    }
    nativePermissionSupport($base);
    file_put_contents($base . '/locked', 'keep');
    chmod($base . '/locked', 0000);
    try {
        skipUnless(!coreutilsFsCall(fn () => is_readable($base . '/locked')), 'process can bypass file permissions');
        foreach (['cat', 'head', 'tail'] as $command) {
            $result = $command(parseCommand($command . ' locked'), $base);
            same(1, $result['status']);
            same([], $result['data']['entries']);
        }
    } finally {
        chmod($base . '/locked', 0600);
    }
});
