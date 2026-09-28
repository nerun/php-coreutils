<?php

$tests['basename and dirname handle roots trailing slashes empty strings and dot components'] = function () {
    $cases = [
        ['', '', '.'], ['file', 'file', '.'], ['file/', 'file', '.'],
        ['/', '/', '/'], ['//', '/', '/'], ['////', '/', '/'],
        ['/file', 'file', '/'], ['//file//', 'file', '/'],
        ['/usr/bin/sort', 'sort', '/usr/bin'], ['a//b///', 'b', 'a'],
        ['a///b//c', 'c', 'a///b'], ['//a/b', 'b', '//a'],
        ['.', '.', '.'], ['..', '..', '.'], ['a/..', '..', 'a'],
        ['a/./b', 'b', 'a/.'], ['a/../b', 'b', 'a/..'],
        ['/usr/bin//.//', '.', '/usr/bin'],
    ];
    foreach ($cases as [$input, $name, $directory]) {
        foreach (['_basename' => $name, '_dirname' => $directory] as $function => $expected) {
            $result = $function(['args' => [$input]], '/missing/cwd');
            same(0, $result['status']);
            same([['input' => $input, 'output' => $expected]], $result['data']['entries']);
            same($expected . "\n", coreutilsText($result));
        }
    }
};

$tests['basename removes a literal suffix once without removing the entire name'] = function () {
    foreach ([
        ['a/file.txt', '.txt', 'file'], ['a/file.txt/', '.txt', 'file'],
        ['file.txt.txt', '.txt', 'file.txt'], ['.txt', '.txt', '.txt'],
        ['file', 'file', 'file'], ['file', 'longerfile', 'file'],
        ['file.TXT', '.txt', 'file.TXT'], ['file.txt', '', 'file.txt'],
        ['/', '/', '/'], ['foo/', 'o/', 'foo'], ['file.txt', '*.txt', 'file.txt'],
        ['a/relatório.txt', '.txt', 'relatório'], ['', '', ''],
    ] as [$path, $suffix, $expected]) {
        same($expected, _basename(['args' => [$path, $suffix]])['data']['entries'][0]['output']);
        same($expected, _basename(['args' => [$path], 'options' => ['suffix' => $suffix]])['data']['entries'][0]['output']);
    }
};

$tests['basename multiple mode and suffix aliases preserve operand order'] = function () {
    foreach (['basename -a a/one.txt b/two.txt', 'basename --multiple a/one.txt b/two.txt'] as $command) {
        same("one.txt\ntwo.txt\n", coreutilsText(_basename(parseCommand($command))));
    }
    foreach (['basename -s .txt', 'basename -s.txt', 'basename --suffix=.txt',
        'basename --suffix .txt', 'basename -as.txt', 'basename -s .bad --suffix=.txt'] as $prefix) {
        same("one\ntwo\n", coreutilsText(_basename(parseCommand($prefix . ' a/one.txt b/two.txt'))));
    }
    same("one\ntwo\n", coreutilsText(_basename([
        'args' => ['a/one.txt', 'b/two.txt'], 'options' => ['multiple' => false, 'suffix' => '.txt'],
    ])));
    same("one\ntwo\n", coreutilsText(_basename(parseCommand('basename -s "" a/one b/two'))));
    same(".txt\n", coreutilsText(_basename(parseCommand('basename .txt .txt'))));
};

$tests['basename stops option parsing at the first operand and supports dash names'] = function () {
    same("file\n", coreutilsText(_basename(parseCommand('basename file-z -z'))));
    same("file\n", coreutilsText(_basename(parseCommand('basename file --help'))));
    same("-z\n", coreutilsText(_basename(parseCommand('basename -- -z'))));
    same("one\n-z\n", coreutilsText(_basename(parseCommand('basename -a one -z'))));
    same("file\n", coreutilsText(_basename(parseCommand('basename -- file-- --'))));
    same("-folder\n", coreutilsText(_dirname(parseCommand('dirname -- -folder/file'))));
};

$tests['dirname supports multiple operands and both commands support NUL records'] = function () {
    same("a\nb\n.\n", coreutilsText(_dirname(parseCommand('dirname a/one b/two plain'))));
    foreach (['-z', '--zero'] as $option) {
        same("one\0two\0", coreutilsText(_basename(parseCommand('basename -a ' . $option . ' a/one b/two'))));
        same("a\0b\0", coreutilsText(_dirname(parseCommand('dirname ' . $option . ' a/one b/two'))));
    }
    same("one\0two\0", coreutilsText(_basename(parseCommand('basename -azs.txt a/one.txt b/two.txt'))));
    same("\0", coreutilsText(_basename(parseCommand('basename -z ""'))));
    same(".\0", coreutilsText(_dirname(parseCommand('dirname -z ""'))));
    same("a\nb\0", coreutilsText(_basename(['args' => ["dir/a\nb"], 'options' => ['zero' => true]])));
    same("a\nb\0", coreutilsText(_dirname(['args' => ["a\nb/file"], 'options' => ['zero' => true]])));
};

$tests['path text commands validate all input before returning entries'] = function () {
    foreach (['basename', 'basename -a', 'basename -s .txt', 'basename a b c',
        'basename -s', 'basename --suffix', 'basename --suffix -- a', 'basename -x a',
        'basename --multiple=yes a', 'dirname', 'dirname -s .txt a', 'dirname --zero=yes a'] as $command) {
        $parsed = parseCommand($command);
        $function = $parsed['command'] === 'basename' ? '_basename' : '_dirname';
        $result = $function($parsed);
        same(2, $result['status']);
        same([], $result['data']['entries']);
    }
    foreach (['_basename', '_dirname'] as $function) {
        foreach ([['args' => ['ok', null]], ['args' => ["bad\0name"]],
            ['args' => 'bad'], ['options' => ['zero' => 'yes']],
            ['options' => ['unknown' => true]], ['flags' => ['z']], parseCommand('ls')] as $input) {
            $result = $function($input);
            same(2, $result['status']);
            same([], $result['data']['entries']);
        }
    }
    same(2, _basename(['args' => ['x'], 'options' => ['suffix' => false]])['status']);
    same(2, _basename(['args' => ['x'], 'options' => ['suffix' => "x\0"]])['status']);
    same(2, _basename(['args' => ['x'], 'options' => ['suffix' => []]])['status']);
    // Empty strings and wrappers remain invalid for filesystem commands.
    same(2, ls(['args' => ['']])['status']);
    same(2, find(['args' => ['file:///tmp']])['status']);
};

$tests['path text commands are byte preserving and independent of filesystem and cwd'] = function () {
    $cwd = getcwd();
    $locale = setlocale(LC_ALL, 0);
    ob_start();
    $base = _basename(['args' => ['file:///missing/relatório.txt']], "bad\0cwd");
    $dir = _dirname(['args' => ['file:///missing/relatório.txt']], '/absent');
    same('', ob_get_clean());
    same('relatório.txt', $base['data']['entries'][0]['output']);
    same('file:///missing', $dir['data']['entries'][0]['output']);
    same("\xff.txt", _basename(['args' => ["a/\xff.txt"]])['data']['entries'][0]['output']);
    same('C:\\folder\\file', _basename(parseCommand("basename 'C:\\folder\\file'"))['data']['entries'][0]['output']);
    same('.', _dirname(['args' => ['C:\\folder\\file']])['data']['entries'][0]['output']);
    same('C:/folder', _dirname(['args' => ['C:/folder/file']])['data']['entries'][0]['output']);
    same($cwd, getcwd());
    same($locale, setlocale(LC_ALL, 0));
};

$tests['path text formatters preserve raw output and escape HTML'] = function () {
    foreach ([['_basename', 'dir/<b>two words</b>.txt', 'b>.txt'],
        ['_basename', 'dir/<tag & "name">', '<tag & "name">'],
        ['_dirname', '<tag & "name">/file', '<tag & "name">']] as [$function, $path, $expected]) {
        $result = $function(['args' => [$path]]);
        same($expected . "\n", coreutilsText($result));
        same($expected, $result['data']['entries'][0]['output']);
        check(strpos(coreutilsHtml($result), '<tag') === false);
        same('<pre style="margin: 0;">' . htmlspecialchars($expected . "\n", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>', coreutilsHtml($result));
    }
};

$tests['path text commands provide help without operands or valid cwd'] = function () {
    foreach (['basename' => '_basename', 'dirname' => '_dirname'] as $command => $function) {
        $result = $function(parseCommand($command . ' --help'), '/does-not-exist');
        same(0, $result['status']);
        same([], $result['data']['entries']);
        check(strpos(coreutilsText($result), 'Usage: ' . $command) === 0);
        check(strpos($result['help'], '--zero') !== false);
    }
};
