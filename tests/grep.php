<?php

$tests['grep parser retains repeated pattern sources and accepts empty positional patterns'] = function () {
    same(['regexp' => ['a', 'b'], 'file' => ['one', 'two']], parseCommand('grep -ea -e b -f one --file=two data')['options']);
    same(['', 'file'], parseCommand('grep "" file')['args']);
    same(['-pattern', '-file'], parseCommand('grep -- -pattern -file')['args']);
    same(['ignore-case' => true, 'line-number' => true, 'max-count' => '2'], parseCommand('grep -inm2 word file')['options']);
    same('dereference', coreutilsGrepSettings(parseCommand('grep -Rr word file')['options'], 1)['recursive']);
};

$tests['grep help and invalid input need no filesystem access'] = function () {
    $result = grep(parseCommand('grep --help'), '/no-such-cwd');
    same(0, $result['status']);
    check(strpos(coreutilsText($result), 'Usage: grep') === 0);
    foreach (['grep', 'grep pattern', 'grep -m-1 pattern file', 'grep -m1.5 pattern file',
        'grep -m999999999999999999999 pattern file', 'grep -EF pattern file',
        'grep --binary-files=unknown pattern file', 'grep -o pattern file',
        'grep -e', 'grep -f', 'grep "[" file', 'grep -E "(?=word)" file'] as $command) {
        $result = grep(parseCommand($command), '/no-such-cwd');
        same(2, $result['status']);
        same([], $result['data']['entries']);
    }
    foreach ([['args' => ['word', '']], ['args' => ['word', 'php://memory']],
        ['args' => ['word', 123]], ['options' => ['regexp' => []], 'args' => ['file']],
        ['options' => ['regexp' => [123]], 'args' => ['file']],
        ['options' => ['regexp' => "nul\0"], 'args' => ['file']],
        ['options' => ['file' => ''], 'args' => ['file']],
        ['options' => ['max-count' => 1], 'args' => ['word', 'file']]] as $input) {
        same(2, grep($input, '/no-such-cwd')['status']);
    }
    same(2, grep(parseCommand('grep word file'), '/no-such-cwd')['status']);
};

$tests['grep selects records adds a final delimiter and numbers empty records correctly'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/file', "alpha\n\nbeta\nlast");
    $result = grep(parseCommand('grep -n a file'), $base);
    same(0, $result['status']);
    same("1:alpha\n3:beta\n4:last\n", coreutilsText($result));
    same(3, $result['data']['entries'][0]['matches']);
    same(4, $result['data']['entries'][0]['scanned']);
    same(true, $result['data']['matched']);
    same(true, $result['data']['entries'][0]['complete']);
    same("2:\n", coreutilsText(grep(parseCommand('grep -nx "" file'), $base)));
    same("alpha\n\nbeta\nlast\n", coreutilsText(grep(parseCommand('grep "" file'), $base)));
});

$tests['grep empty and unmatched files return status one without errors'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/empty', '');
    file_put_contents($base . '/file', "abc\n");
    foreach (['grep . empty', 'grep zzz file', 'grep -v . file'] as $command) {
        $result = grep(parseCommand($command), $base);
        same(1, $result['status']);
        same([], $result['errors']);
        same(false, $result['data']['matched']);
        same('', coreutilsText($result));
    }
    same("0\n", coreutilsText(grep(parseCommand('grep -c zzz file'), $base)));
    same(1, grep(parseCommand('grep -c zzz file'), $base)['status']);
});

$tests['grep BRE ERE fixed strings intervals groups and backreferences remain distinct'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/file', "a\naa\naaa\na+\nabc\na.a\nab\nabab\n");
    foreach (['grep "a\\+" file' => "a\naa\naaa\na+\nabc\na.a\nab\nabab\n",
        'grep "a+" file' => "a+\n", 'grep -E "^a{2,3}$" file' => "aa\naaa\n",
        'grep "^a\\{2,3\\}$" file' => "aa\naaa\n",
        'grep "^\\(ab\\)\\1$" file' => "abab\n", 'grep -E "^(ab)\\1$" file' => "abab\n",
        'grep -F "a.a" file' => "a.a\n", 'grep -E "a.a" file' => "aaa\na.a\nabab\n",
        'grep "^\\+" file' => ''] as $command => $expected) {
        same($expected, coreutilsText(grep(parseCommand($command), $base)));
    }
    file_put_contents($base . '/ten', "aaaaaaaaaa0\n");
    same("aaaaaaaaaa0\n", coreutilsText(grep(parseCommand('grep "\\(aaaaa\\)\\10" ten'), $base)));
});

$tests['grep escapes and POSIX brackets do not silently become Perl syntax'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/file', "123\nd\nabc\n_\n foo \n]a\n~\n");
    same("d\n", coreutilsText(grep(parseCommand('grep "\\d" file'), $base)));
    same("123\n", coreutilsText(grep(parseCommand('grep "[[:digit:]]" file'), $base)));
    same(" foo \n", coreutilsText(grep(parseCommand('grep "\\<foo\\>" file'), $base)));
    same("~\n", coreutilsText(grep(parseCommand('grep "\\~" file'), $base)));
    same(2, grep(parseCommand('grep -E "[[.a.]]" file'), $base)['status']);
    same(2, grep(parseCommand('grep -E "a++a" file'), $base)['status']);
    same(2, grep(parseCommand('grep -E "a{40000}" file'), $base)['status']);
    same(2, grep(parseCommand('grep "[[:word:]]" file'), $base)['status']);
    check(coreutilsText(grep(parseCommand('grep -s "[" file'), $base)) !== '');
});

$tests['grep fixed search supports patterns longer than PCRE limits'] = fn () => fixture(function ($base) {
    $text = str_repeat('a', 70000) . 'z';
    file_put_contents($base . '/file', $text . "\n");
    $result = grep(['args' => [$text, 'file'], 'options' => ['fixed-strings' => true, 'line-regexp' => true]], $base);
    same(0, $result['status']);
    same($text . "\n", coreutilsText($result));
});

$tests['grep case word whole line and inversion combine predictably'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/file', "word\nWORD\nsword\nword_1\nword!\n-word-\n");
    same("word\nWORD\nword!\n-word-\n", coreutilsText(grep(parseCommand('grep -iw word file'), $base)));
    same("word\nWORD\n", coreutilsText(grep(parseCommand('grep -ix word file'), $base)));
    same("word\nword!\n-word-\n", coreutilsText(grep(parseCommand('grep -i --no-ignore-case -w word file'), $base)));
    same("sword\nword_1\n", coreutilsText(grep(parseCommand('grep -ivw word file'), $base)));
    same("word\nWORD\nword!\n-word-\n", coreutilsText(grep(parseCommand('grep -Fiw word file'), $base)));
    same("word\nWORD\n", coreutilsText(grep(parseCommand('grep -Fiwx word file'), $base)));
});

$tests['grep multiple patterns use OR and inversion applies to their combined match'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/file', "alpha\nbeta\ngamma\n");
    same("alpha\nbeta\n", coreutilsText(grep(parseCommand('grep -e alpha -e beta file'), $base)));
    same("gamma\n", coreutilsText(grep(parseCommand('grep -v -e alpha -e beta file'), $base)));
    same("alpha\nbeta\n", coreutilsText(grep(['args' => ["alpha\nbeta", 'file']], $base)));
    same("alpha\nbeta\ngamma\n", coreutilsText(grep(['args' => ["alpha\n", 'file']], $base)));
    same("alpha\nbeta\n", coreutilsText(grep(['args' => ['file'], 'options' => ['regexp' => ['alpha', 'beta']]], $base)));
});

$tests['grep pattern files handle blank lines empty files repeated sources and CRLF literally'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/data', "alpha\nbeta\ngamma\n");
    file_put_contents($base . '/one', "alpha\n");
    file_put_contents($base . '/two', 'beta');
    file_put_contents($base . '/empty', '');
    file_put_contents($base . '/blank', "\n");
    file_put_contents($base . '/crlf', "alpha\r\n");
    same("alpha\nbeta\n", coreutilsText(grep(parseCommand('grep -f one -f two data'), $base)));
    same("alpha\ngamma\n", coreutilsText(grep(parseCommand('grep -f one -e gamma data'), $base)));
    same(1, grep(parseCommand('grep -f empty data'), $base)['status']);
    same("alpha\nbeta\ngamma\n", coreutilsText(grep(parseCommand('grep -vf empty data'), $base)));
    same("alpha\nbeta\ngamma\n", coreutilsText(grep(parseCommand('grep -f blank data'), $base)));
    same(1, grep(parseCommand('grep -f crlf data'), $base)['status']);
    same(2, grep(parseCommand('grep -f missing data'), $base)['status']);
    file_put_contents($base . '/bad', "[\n");
    $result = grep(parseCommand('grep -e "" -f bad data'), $base);
    same(2, $result['status']);
    same([], $result['data']['entries']);
});

$tests['grep filename headers counts lists and quiet have GNU output precedence'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/one', "a\na\n");
    file_put_contents($base . '/two', "b\n");
    same("one:1:a\none:2:a\n", coreutilsText(grep(parseCommand('grep -n a one two'), $base)));
    same("a\na\n", coreutilsText(grep(parseCommand('grep -h a one two'), $base)));
    same("one:a\none:a\n", coreutilsText(grep(parseCommand('grep -H a one'), $base)));
    same("one:2\ntwo:0\n", coreutilsText(grep(parseCommand('grep -c a one two'), $base)));
    same("one\n", coreutilsText(grep(parseCommand('grep -cl a one two'), $base)));
    same("one\n", coreutilsText(grep(parseCommand('grep -lc a one two'), $base)));
    same("two\n", coreutilsText(grep(parseCommand('grep -lL a one two'), $base)));
    same(0, grep(parseCommand('grep -L a one two'), $base)['status']);
    same(1, grep(parseCommand('grep -L a two'), $base)['status']);
    same("two\n", coreutilsText(grep(parseCommand('grep -L a two'), $base)));
    same('', coreutilsText(grep(parseCommand('grep -qcl a one two'), $base)));
});

$tests['grep max count resets for each file and inversion counts selected records'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/one', "a\nb\na\na\n");
    file_put_contents($base . '/two', "b\na\na\n");
    same("one:1:a\ntwo:2:a\n", coreutilsText(grep(parseCommand('grep -nm1 a one two'), $base)));
    same("2:b\n", coreutilsText(grep(parseCommand('grep -vnm1 a one'), $base)));
    same("one:1\ntwo:1\n", coreutilsText(grep(parseCommand('grep -cm+1 a one two'), $base)));
    same(1, grep(parseCommand('grep -m0 a missing'), $base)['status']);
    same([], grep(parseCommand('grep -m0 a missing'), $base)['errors']);
    same("one\n", coreutilsText(grep(parseCommand('grep -Lm0 a one'), $base)));
});

$tests['grep missing and unreadable input has status two while quiet success overrides earlier errors'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/file', "yes\n");
    $result = grep(parseCommand('grep yes missing file'), $base);
    same(2, $result['status']);
    same(true, $result['data']['matched']);
    same(1, count($result['errors']));
    check(strpos(coreutilsText($result), "file:yes\n") !== false);
    $result = grep(parseCommand('grep -qs yes missing file'), $base);
    same(0, $result['status']);
    same(1, count($result['errors']));
    same('', coreutilsText($result));
    same(2, grep(parseCommand('grep -qs no missing file'), $base)['status']);
    same(2, grep(parseCommand('grep -f missing -q yes file'), $base)['status']);
    same(0, grep(parseCommand('grep yes file'), $base)['status']);
});

$tests['grep binary handling classifies NUL files and text mode preserves bytes'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/binary', "hello\nabc\0def\nhello2\n");
    $result = grep(parseCommand('grep hello binary'), $base);
    same(0, $result['status']);
    same(true, $result['data']['entries'][0]['binary']);
    same("grep: binary: binary file matches\n", coreutilsText($result));
    same("hello\nhello2\n", coreutilsText(grep(parseCommand('grep -a hello binary'), $base)));
    same("abc\0def\n", coreutilsText(grep(parseCommand('grep -a "abc.def" binary'), $base)));
    same(1, grep(parseCommand('grep "abc.def" binary'), $base)['status']);
    same("1\n", coreutilsText(grep(parseCommand('grep -c "^def$" binary'), $base)));
    same(1, grep(parseCommand('grep -Iv hello binary'), $base)['status']);
    same("0\n", coreutilsText(grep(parseCommand('grep -Ic hello binary'), $base)));
    same("binary\n", coreutilsText(grep(parseCommand('grep -IL hello binary'), $base)));
});

$tests['grep NUL records support regex dots anchors numbering and an unterminated final record'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/file', "one\ntwo\0\0THREE\0last");
    same("1:one\ntwo\0", coreutilsText(grep(parseCommand('grep -zn "^one.two$" file'), $base)));
    same("2:\0", coreutilsText(grep(parseCommand('grep -znx "" file'), $base)));
    same("4:last\0", coreutilsText(grep(parseCommand('grep -zn last file'), $base)));
    same("one\ntwo\0THREE\0", coreutilsText(grep(parseCommand('grep -zE "one|THREE" file'), $base)));
});

$tests['grep block boundaries and long records preserve matches and line numbers'] = fn () => fixture(function ($base) {
    $first = str_repeat('x', 8190) . 'NEEDLE';
    $second = str_repeat('y', 20000) . 'NEEDLE';
    file_put_contents($base . '/file', $first . "\n\n" . $second);
    $result = grep(parseCommand('grep -Fn NEEDLE file'), $base);
    same("1:$first\n3:$second\n", coreutilsText($result));
    same(2, $result['data']['entries'][0]['matches']);
});

$tests['grep recursion includes hidden files skips nested links with r and follows them with R'] = fn () => fixture(function ($base) {
    mkdir($base . '/tree');
    mkdir($base . '/tree/sub');
    file_put_contents($base . '/tree/.hidden', "yes\n");
    file_put_contents($base . '/tree/sub/file', "yes\n");
    file_put_contents($base . '/outside', "yes\n");
    same("tree/.hidden:yes\ntree/sub/file:yes\n", coreutilsText(grep(parseCommand('grep -r yes tree'), $base)));
    same("yes\nyes\n", coreutilsText(grep(parseCommand('grep -rh yes tree'), $base)));
    same("yes\n", coreutilsText(grep(parseCommand('grep -r yes outside'), $base)));
    same(".hidden:yes\nsub/file:yes\n", coreutilsText(grep(parseCommand('grep -r yes'), $base . '/tree')));
    symlinkSupport($base);
    symlink('../outside', $base . '/tree/link');
    symlink('tree', $base . '/alias');
    same(2, count(grep(parseCommand('grep -r yes alias'), $base)['data']['entries']));
    $result = grep(parseCommand('grep -Rr yes tree'), $base);
    same(3, count($result['data']['entries']));
    check(strpos(coreutilsText($result), "tree/link:yes\n") !== false);
});

$tests['grep directory cycles warn without loops and dangling followed links remain errors'] = fn () => fixture(function ($base) {
    symlinkSupport($base);
    mkdir($base . '/tree');
    file_put_contents($base . '/tree/file', "yes\n");
    symlink('.', $base . '/tree/cycle');
    symlink('missing', $base . '/tree/broken');
    $result = grep(parseCommand('grep -R yes tree'), $base);
    same(2, $result['status']);
    same(1, count($result['data']['warnings']));
    same('directory-cycle', $result['data']['warnings'][0]['code']);
    same(1, count($result['data']['entries']));
    same(0, grep(parseCommand('grep -r yes tree'), $base)['status']);
    unlink($base . '/tree/broken');
    same(0, grep(parseCommand('grep -R yes tree'), $base)['status']);
});

$tests['grep explicit special files are refused while recursive searches skip them'] = fn () => fixture(function ($base) {
    skipUnless(function_exists('posix_mkfifo'), 'posix_mkfifo() unavailable');
    skipUnless(coreutilsFsCall(fn () => posix_mkfifo($base . '/fifo', 0600)), 'FIFO creation unavailable');
    same(2, grep(parseCommand('grep word fifo'), $base)['status']);
    same(1, grep(parseCommand('grep -r word .'), $base)['status']);
    same(2, grep(parseCommand('grep word .'), $base)['status']);
});

$tests['grep unreadable input reports real access errors and s suppresses only presentation'] = fn () => fixture(function ($base) {
    nativePermissionSupport($base);
    file_put_contents($base . '/denied', "word\n");
    chmod($base . '/denied', 0000);
    skipUnless(coreutilsFsCall(fn () => fopen($base . '/denied', 'rb')) === false, 'process bypasses permissions');
    $result = grep(parseCommand('grep -s word denied'), $base);
    same(2, $result['status']);
    same(1, count($result['errors']));
    same('', coreutilsText($result));
});

$tests['grep HTML escapes content filenames warnings and errors without changing results'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/file', "<script>word</script>\n");
    $result = grep(parseCommand('grep word file'), $base);
    same("<script>word</script>\n", coreutilsText($result));
    check(strpos(coreutilsHtml($result), '<script>') === false);
    check(strpos(coreutilsHtml($result), '&lt;script&gt;') !== false);
    $result = grep(['args' => ['word', '<missing>']], $base);
    check(strpos(coreutilsHtml($result), '<missing>') === false);
    check(strpos(coreutilsHtml($result), '&lt;missing&gt;') !== false);
});

$tests['grep callback output agrees with buffering and carries filename metadata'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/file', "yes\nno\nyes\n");
    $output = '';
    $metadata = [];
    $result = grep(parseCommand('grep -n yes file'), $base, function ($chunk, $entry) use (&$output, &$metadata) {
        $output .= $chunk;
        $metadata[] = $entry;
    });
    same(0, $result['status']);
    same("1:yes\n3:yes\n", $output);
    same('', coreutilsText($result));
    same(null, $result['data']['entries'][0]['content']);
    same(strlen($output), $result['data']['entries'][0]['bytes']);
    same(['name' => 'file', 'path' => $base . '/file'], $metadata[0]);
});

$tests['grep callback refusal returns status two stops later files and closes handles'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/file', "yes\nyes\n");
    $before = count(get_resources('stream'));
    $called = 0;
    $result = grep(parseCommand('grep yes file file'), $base, function () use (&$called) {
        $called++;
        return false;
    });
    same(2, $result['status']);
    same(1, $called);
    same(1, count($result['data']['entries']));
    same(false, $result['data']['entries'][0]['complete']);
    same(0, $result['data']['entries'][0]['bytes']);
    same($before, count(get_resources('stream')));
    try {
        grep(parseCommand('grep yes file'), $base, function () {
            throw new RuntimeException('callback probe');
        });
        check(false, 'callback exception should propagate');
    } catch (RuntimeException $error) {
        same('callback probe', $error->getMessage());
    }
    same($before, count(get_resources('stream')));
});

$tests['grep regex execution failures return status two without emitting PHP warnings'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/file', str_repeat('a', 200) . "b\n");
    $limit = ini_get('pcre.backtrack_limit');
    $jit = ini_get('pcre.jit');
    try {
        ini_set('pcre.backtrack_limit', '10');
        ini_set('pcre.jit', '0');
        $result = grep(parseCommand('grep -E "^(a+)+$" file'), $base);
        same(2, $result['status']);
        same('match-error', $result['errors'][0]['code']);
        same(false, $result['data']['entries'][0]['complete']);
    } finally {
        ini_set('pcre.backtrack_limit', $limit);
        ini_set('pcre.jit', $jit);
    }
});

$tests['grep deep recursion and repeated calls leave caller state intact'] = fn () => fixture(function ($base) {
    $path = $base;
    for ($depth = 0; $depth < 50; $depth++) {
        $path .= '/d';
        mkdir($path);
    }
    file_put_contents($path . '/file', "yes\n");
    $cwd = getcwd();
    $mask = umask();
    $locale = setlocale(LC_ALL, 0);
    ob_start();
    $result = grep(parseCommand('grep -rh yes .'), $base);
    same('', ob_get_clean());
    same("yes\n", coreutilsText($result));
    same(0, $result['status']);
    same(1, grep(parseCommand('grep -r absent .'), $base)['status']);
    same($cwd, getcwd());
    same($mask, umask());
    same($locale, setlocale(LC_ALL, 0));
});
