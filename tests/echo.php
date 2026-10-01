<?php

function echoEnvironment(?string $value, callable $test): void
{
    $previous = getenv('POSIXLY_CORRECT');
    putenv($value === null ? 'POSIXLY_CORRECT' : 'POSIXLY_CORRECT=' . $value);
    try {
        $test();
    } finally {
        putenv($previous === false ? 'POSIXLY_CORRECT' : 'POSIXLY_CORRECT=' . $previous);
    }
}

$tests['echo joins empty space and URL operands without needing a filesystem or cwd'] = function () {
    echoEnvironment(null, function () {
        foreach (['echo' => "\n", 'echo ""' => "\n", 'echo "" ""' => " \n",
            'echo one "two words" ""' => "one two words \n", 'echo file:///absent' => "file:///absent\n",
            'echo "Olá, mundo!"' => "Olá, mundo!\n"] as $input => $expected) {
            $result = _echo(parseCommand($input), '/does-not-exist');
            same(0, $result['status']);
            same($expected, coreutilsText($result));
            same($expected, $result['data']['content']);
            same(null, $result['data']['redirect']);
        }
    });
};

$tests['echo follows GNU leading option clusters unknown option text and literal double dash'] = function () {
    echoEnvironment(null, function () {
        foreach (['echo -n' => '', 'echo -nn hello' => 'hello', 'echo -n hello -n' => 'hello -n',
            'echo -- hello' => "-- hello\n", 'echo -unknown -n' => "-unknown -n\n",
            'echo -enx hello' => "-enx hello\n", 'echo --no-newline hello' => "--no-newline hello\n",
            'echo -n --help' => '--help', 'echo --help hello' => "--help hello\n",
            'echo hello --version' => "hello --version\n", 'echo "" -n' => " -n\n",
            'echo -E -e "a\\nb"' => "a\nb\n", 'echo -eE "a\\nb"' => "a\\nb\n",
            'echo -Ee "a\\nb"' => "a\nb\n", 'echo -e -E -e "a\\nb"' => "a\nb\n"] as $input => $expected) {
            same($expected, coreutilsText(_echo(parseCommand($input))));
        }
    });
};

$tests['echo decodes GNU control octal and hex escapes including NUL and unknown sequences'] = function () {
    $cases = [
        '\\a\\b\\e\\f\\n\\r\\t\\v\\\\' => "\x07\x08\x1b\x0c\n\r\t\x0b\\",
        '\\0' => "\0", '\\0000' => "\0", '\\0123' => 'S', '\\123' => 'S',
        '\\0777' => "\xff", '\\777' => "\xff", '\\01234' => 'S4', '\\1234' => 'S4',
        '\\08' => "\0" . '8', '\\x4' => "\x04", '\\x41' => 'A', '\\x414' => 'A4',
        '\\xFF' => "\xff", '\\x4Z' => "\x04Z", '\\x' => '\\x', '\\xZZ' => '\\xZZ',
        '\\u0041\\U00000041\\q\\8' => '\\u0041\\U00000041\\q\\8', 'last\\' => 'last\\',
    ];
    foreach ($cases as $input => $expected) {
        $result = _echo(['args' => [$input], 'options' => ['escapes' => true, 'posixly-correct' => false]], '/missing');
        same(0, $result['status']);
        same($expected . "\n", coreutilsText($result));
    }
};

$tests['echo c escape stops later arguments separators and the final newline'] = function () {
    foreach ([['first\\cignored', 'later'], ['first', '\\cignored', 'later'], ['\\c', 'later']] as $args) {
        $expected = $args[0] === 'first\\cignored' ? 'first' : ($args[0] === 'first' ? 'first ' : '');
        same($expected, coreutilsText(_echo(['args' => $args, 'options' => ['escapes' => true, 'posixly-correct' => false]])));
    }
    same("first\\cignored later\n", coreutilsText(_echo([
        'args' => ['first\\cignored', 'later'], 'options' => ['posixly-correct' => false],
    ])));
};

$tests['echo POSIXLY_CORRECT changes option recognition and always enables escapes'] = function () {
    echoEnvironment('', function () {
        foreach (['echo -ne "a\\nb"' => "-ne a\nb\n", 'echo -n -E "a\\nb"' => "a\nb",
            'echo -e hello' => "-e hello\n", 'echo --help' => "--help\n",
            'echo --version' => "--version\n", 'echo -n -e hello' => 'hello'] as $input => $expected) {
            $parsed = parseCommand($input);
            same(true, $parsed['options']['posixly-correct']);
            same($expected, coreutilsText(_echo($parsed)));
        }
    });
    echoEnvironment(null, function () {
        $parsed = parseCommand('echo -E "a\\nb"');
        echoEnvironment('1', function () use ($parsed) {
            same("a\\nb\n", coreutilsText(_echo($parsed)));
        });
    });
};

$tests['echo recognizes only unquoted unescaped greater-than operators including adjacent tokens'] = function () {
    echoEnvironment(null, function () {
        foreach (['echo "a > b"', "echo 'a > b'", 'echo a\\ \\>\\ b'] as $input) {
            $result = _echo(parseCommand($input), '/missing');
            same(0, $result['status']);
            same("a > b\n", coreutilsText($result));
            same(null, $result['data']['redirect']);
        }
        same(['path' => 'two words.txt', 'mode' => 'overwrite'], parseCommand('echo "phrase">"two words.txt"')['redirect']);
        same(['path' => '-n', 'mode' => 'append'], parseCommand('echo phrase>>-n')['redirect']);
        same(['path' => 'file', 'mode' => 'overwrite'], parseCommand('echo>file')['redirect']);
        same(['path' => 'file', 'mode' => 'append'], parseCommand('echo>>file')['redirect']);
        same('echo>file', parseCommand('"echo>file"')['command']);
        same(['text', 'after'], parseCommand('echo text > file after')['args']);
        same(['a>b'], parseCommand('echo a">"b')['args']);
        same(['>>'], parseCommand('echo \\>\\>')['args']);
        same(['path' => '>', 'mode' => 'overwrite'], parseCommand('echo text > ">"')['redirect']);
        same(['text>file'], parseCommand('cat text>file')['args']);
        same(['text>file'], parseCommand('head text>file')['args']);
    });
};

$tests['echo overwrite append and no-newline redirection write exact bytes silently'] = fn () => fixture(function ($base) {
    echoEnvironment(null, function () use ($base) {
        $result = _echo(parseCommand('echo "first phrase" > file.txt'), $base);
        same(0, $result['status']);
        same("first phrase\n", file_get_contents($base . '/file.txt'));
        same('', coreutilsText($result));
        same(['target' => 'file.txt', 'path' => $base . '/file.txt', 'mode' => 'overwrite',
            'bytes' => strlen("first phrase\n"), 'complete' => true], $result['data']['redirect']);
        same(0, _echo(parseCommand('echo -n "second phrase" >> file.txt'), $base)['status']);
        same("first phrase\nsecond phrase", file_get_contents($base . '/file.txt'));
        same(0, _echo(parseCommand('echo -e "\\nthird\\tline\\0\\xff" >> file.txt'), $base)['status']);
        same("first phrase\nsecond phrase\nthird\tline\0\xff\n", file_get_contents($base . '/file.txt'));
        same(0, _echo(parseCommand('echo replacement >file.txt'), $base)['status']);
        same("replacement\n", file_get_contents($base . '/file.txt'));
        same(0, _echo(parseCommand('echo -n >file.txt'), $base)['status']);
        same('', file_get_contents($base . '/file.txt'));
        same(0, _echo(parseCommand('echo -n >>file.txt'), $base)['status']);
        same('', file_get_contents($base . '/file.txt'));
    });
});

$tests['echo redirection placement quoting absolute paths and canonical arrays use the same cwd'] = fn () => fixture(function ($base) {
    echoEnvironment(null, function () use ($base) {
        same(0, _echo(parseCommand('echo >"two words" -n phrase tail'), $base)['status']);
        same('phrase tail', file_get_contents($base . '/two words'));
        same(0, _echo(['args' => ['https://example.com', ''], 'options' => ['no-newline' => true],
            'redirect' => ['path' => $base . '/absolute', 'mode' => 'append']], $base)['status']);
        same('https://example.com ', file_get_contents($base . '/absolute'));
        same(0, _echo(parseCommand('echo text >-name'), $base)['status']);
        same("text\n", file_get_contents($base . '/-name'));
    });
});

$tests['echo invalid syntax operands and redirect targets cannot truncate existing files'] = fn () => fixture(function ($base) {
    echoEnvironment(null, function () use ($base) {
        file_put_contents($base . '/keep', 'preserve');
        foreach (['echo text >', 'echo text >>', 'echo text >>>keep', 'echo text >keep >>new',
            'echo text >keep >new', 'echo "unterminated >keep', 'echo text > ""', 'echo text >file:///tmp/new'] as $input) {
            same(2, _echo(parseCommand($input), $base)['status']);
            same('preserve', file_get_contents($base . '/keep'));
            check(!file_exists($base . '/new'));
        }
        $target = ['path' => 'keep', 'mode' => 'overwrite'];
        foreach ([['args' => [null]], ['args' => ["bad\0text"]], ['args' => 'bad'],
            ['options' => ['escapes' => 'yes']], ['options' => ['unknown' => true]],
            ['flags' => ['n']], ['command' => 'cat']] as $input) {
            $input['redirect'] = $target;
            same(2, _echo($input, $base)['status']);
            same('preserve', file_get_contents($base . '/keep'));
        }
        foreach (['bad', [], ['path' => 'keep'], ['path' => 'keep', 'mode' => 'invalid'],
            ['path' => 'keep', 'mode' => false], ['path' => 'keep', 'mode' => 'overwrite', 'extra' => true],
            ['path' => null, 'mode' => 'overwrite'], ['path' => "keep\0", 'mode' => 'overwrite']] as $redirect) {
            same(2, _echo(['args' => ['replacement'], 'redirect' => $redirect], $base)['status']);
            same('preserve', file_get_contents($base . '/keep'));
        }
    });
});

$tests['echo filesystem failures are reusable errors and do not print generated text'] = fn () => fixture(function ($base) {
    echoEnvironment(null, function () use ($base) {
        mkdir($base . '/directory');
        foreach (['echo hello >missing/file', 'echo hello >directory'] as $input) {
            $result = _echo(parseCommand($input), $base);
            same(1, $result['status']);
            same(false, $result['data']['redirect']['complete']);
            same(0, $result['data']['redirect']['bytes']);
            check(strpos(coreutilsText($result), 'echo: cannot write') === 0);
            check(substr(coreutilsText($result), -6) !== "hello\n");
        }
        check(!file_exists($base . '/missing'));
        $result = _echo(parseCommand('echo hello >file'), $base . '/missing');
        same(1, $result['status']);
        same(null, $result['data']['redirect']['path']);
        check(!file_exists($base . '/file'));
        same(0, _echo(parseCommand('echo hello >file'), $base)['status']);
        same("hello\n", file_get_contents($base . '/file'));
    });
});

$tests['echo redirection follows links creates dangling targets and preserves permission bits'] = fn () => fixture(function ($base) {
    symlinkSupport($base);
    file_put_contents($base . '/file', 'old');
    $mode = modeOf($base . '/file');
    symlink('file', $base . '/link');
    symlink('missing', $base . '/broken');
    echoEnvironment(null, function () use ($base, $mode) {
        same(0, _echo(parseCommand('echo replace >link'), $base)['status']);
        same("replace\n", file_get_contents($base . '/file'));
        same($mode, modeOf($base . '/file'));
        same('file', readlink($base . '/link'));
        same(0, _echo(parseCommand('echo create >>broken'), $base)['status']);
        same("create\n", file_get_contents($base . '/missing'));
        same('missing', readlink($base . '/broken'));
    });
});

$tests['echo redirect writes large and empty outputs and honors native umask'] = fn () => fixture(function ($base) {
    $content = str_repeat('0123456789', 20000);
    $result = _echo(['args' => [$content], 'options' => ['no-newline' => true],
        'redirect' => ['path' => 'large', 'mode' => 'overwrite']], $base);
    same(0, $result['status']);
    same(strlen($content), $result['data']['redirect']['bytes']);
    same($content, file_get_contents($base . '/large'));
    nativePermissionSupport($base);
    $mask = umask(0077);
    try {
        same(0, _echo(['args' => [], 'options' => ['no-newline' => true],
            'redirect' => ['path' => 'private', 'mode' => 'append']], $base)['status']);
        same(0600, modeOf($base . '/private'));
    } finally {
        umask($mask);
    }
});

$tests['echo refuses FIFO targets before opening and reports inaccessible files'] = fn () => fixture(function ($base) {
    skipUnless(function_exists('posix_mkfifo'), 'posix_mkfifo() unavailable');
    skipUnless(coreutilsFsCall(fn () => posix_mkfifo($base . '/fifo', 0600)), 'FIFO creation unavailable');
    foreach (['overwrite', 'append'] as $mode) {
        $result = _echo(['args' => ['text'], 'redirect' => ['path' => 'fifo', 'mode' => $mode]], $base);
        same(1, $result['status']);
        check(strpos($result['errors'][0]['message'], 'Not a regular file') !== false);
    }
    nativePermissionSupport($base);
    file_put_contents($base . '/locked', 'keep');
    chmod($base . '/locked', 0000);
    try {
        skipUnless(!coreutilsFsCall(fn () => is_writable($base . '/locked')), 'process can bypass file permissions');
        same(1, _echo(['args' => ['text'], 'redirect' => ['path' => 'locked', 'mode' => 'overwrite']], $base)['status']);
    } finally {
        chmod($base . '/locked', 0600);
    }
    same('keep', file_get_contents($base . '/locked'));
});

$tests['echo help version and redirected help identify PHP Coreutils without needing cwd for plain output'] = fn () => fixture(function ($base) {
    echoEnvironment(null, function () use ($base) {
        $result = _echo(parseCommand('echo --help'), '/missing');
        same(0, $result['status']);
        check(strpos(coreutilsText($result), 'Usage: echo') === 0);
        check(strpos(coreutilsText(_echo(parseCommand('echo --version'), '/missing')), 'echo (PHP Coreutils)') === 0);
        $result = _echo(parseCommand('echo --help >help.txt'), $base);
        same(0, $result['status']);
        same(coreutilsHelp('echo'), file_get_contents($base . '/help.txt'));
        same('', coreutilsText($result));
    });
});

$tests['echo preserves process state captures warnings and escapes only the HTML presentation'] = fn () => fixture(function ($base) {
    $cwd = getcwd();
    $locale = setlocale(LC_ALL, 0);
    $timezone = date_default_timezone_get();
    $mask = umask();
    $environment = getenv('POSIXLY_CORRECT');
    $before = count(get_resources('stream'));
    ob_start();
    $result = _echo(['args' => ['<script>&"'], 'options' => ['no-newline' => true, 'posixly-correct' => false]], '/missing');
    same('', ob_get_clean());
    same('<script>&"', coreutilsText($result));
    check(strpos(coreutilsHtml($result), '<script>') === false);
    check(strpos(coreutilsHtml($result), '&lt;script&gt;') !== false);
    $called = false;
    set_error_handler(function () use (&$called) {
        $called = true;
        return true;
    });
    try {
        $failed = _echo(['args' => ['text'], 'redirect' => ['path' => '<missing>/file', 'mode' => 'overwrite']], $base);
        same(1, $failed['status']);
        same(false, $called);
        check(strpos(coreutilsHtml($failed), '<missing>') === false);
        trigger_error('echo handler probe', E_USER_WARNING);
        same(true, $called);
    } finally {
        restore_error_handler();
    }
    same($before, count(get_resources('stream')));
    same($cwd, getcwd());
    same($locale, setlocale(LC_ALL, 0));
    same($timezone, date_default_timezone_get());
    same($mask, umask());
    same($environment, getenv('POSIXLY_CORRECT'));
});
