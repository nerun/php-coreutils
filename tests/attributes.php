<?php

$tests['attribute commands parse flags references formats aliases and double dash'] = function () {
    same(['recursive' => true, 'verbose' => true], parseCommand('chmod -Rv 755 file')['options']);
    same(['reference' => 'two words'], parseCommand('chmod --reference="two words" file')['options']);
    same(['-w', '-file'], parseCommand('chmod -- -w -file')['args']);
    same(['dereference' => true, 'format' => '%n %a'], parseCommand('stat -Lc "%n %a" file')['options']);
    same(['format' => '%s'], parseCommand('stat -c%a --format=%s file')['options']);
    same(['mode' => 'u+x'], coreutilsValidateInput('chmod', ['options' => ['mode' => 'u+x']])[0]);
};

$tests['chmod compiles numeric and symbolic grammar and rejects malformed modes'] = function () {
    foreach (['0', '755', '0755', '00755', '7777', '+110', '-6000', '=755', 'u+x', 'go-w',
        'a=rw', 'a+rwX', 'g=u', 'ug=o', 'u=rw,g=u,o=g', 'a+r-w+x', 'u=', '+', 'u-x+X'] as $mode) {
        check(coreutilsChmodMode($mode) !== null, 'valid mode: ' . $mode);
    }
    foreach (['', '888', '10000', '0x755', 'u', 'u+q', 'u+ru', 'u+ug', 'u=r w', 'u+x,',
        ',u+x', 'a+x,,g+w', 'user+x', 'u+755', '++755', '-6000,+755', "u+x\0"] as $mode) {
        same(null, coreutilsChmodMode($mode));
    }
};

$tests['chmod symbolic operations use current permissions umask copy and conditional execution'] = function () {
    foreach ([['u+x', 0644, false, 0022, 0744], ['go-w', 0666, false, 0022, 0644],
        ['a=rw', 0777, false, 0077, 0666], ['+rw', 0000, false, 0077, 0600],
        ['-w', 0777, false, 0022, 0577], ['=rw', 0777, false, 0022, 0644],
        ['=u', 0700, false, 0022, 0755], ['g=u', 0640, false, 0022, 0660],
        ['u=rw,g=u,o=g', 0000, false, 0022, 0666], ['a+X', 0644, false, 0022, 0644],
        ['a+X', 0644, true, 0022, 0755], ['a+X', 0744, false, 0022, 0755],
        ['u+x,a+X', 0600, false, 0022, 0711], ['a-x,a+X', 0755, false, 0022, 0644],
        ['u+t,g+t,o+s', 0644, false, 0022, 0644], ['a+s,+t', 0755, false, 0022, 07755]] as [$mode, $before, $directory, $mask, $expected]) {
        same($expected, coreutilsChmodApply(coreutilsChmodMode($mode), $before, $directory, $mask));
    }
};

$tests['chmod preserves directory setuid setgid unless explicitly changed'] = function () {
    foreach (['755', '0755', 'u=rwx,go=rx', 'a=rx'] as $mode) {
        $expected = $mode === 'a=rx' ? 06555 : 06755;
        same($expected, coreutilsChmodApply(coreutilsChmodMode($mode), 06777, true, 0022));
    }
    foreach (['00755', '=755', 'u-s,g-s,a=rx'] as $mode) {
        same(
            $mode === 'u-s,g-s,a=rx' ? 0555 : 0755,
            coreutilsChmodApply(coreutilsChmodMode($mode), 06777, true, 0022)
        );
    }
    same(0755, coreutilsChmodApply(coreutilsChmodMode('0755'), 06777, false, 0022));
};

$tests['chmod changes real permissions records observed modes and keeps contents'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/file', "keep\0contents");
    chmod($base . '/file', 0644);
    $result = _chmod(parseCommand('chmod 600 file'), $base);
    same(0, $result['status']);
    same(0600, modeOf($base . '/file'));
    same(['name' => 'file', 'path' => $base . '/file', 'before' => 0644, 'requested' => 0600,
        'after' => 0600, 'changed' => true], $result['data']['entries'][0]);
    same('', coreutilsText($result));
    same(0, _chmod(parseCommand('chmod u+x file'), $base)['status']);
    same(0700, modeOf($base . '/file'));
    same(0, _chmod(['args' => ['file'], 'options' => ['mode' => 'g=u']], $base)['status']);
    same(0770, modeOf($base . '/file'));
    same("keep\0contents", file_get_contents($base . '/file'));
});

$tests['chmod numeric modes ignore umask symbolic omitted who respects it and state stays intact'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/file', '');
    $mask = umask(0077);
    try {
        same(0, _chmod(parseCommand('chmod 666 file'), $base)['status']);
        same(0666, modeOf($base . '/file'));
        same(0, _chmod(parseCommand('chmod =rw file'), $base)['status']);
        same(0600, modeOf($base . '/file'));
        same(0077, umask());
        same(0, _chmod(parseCommand('chmod a+rw file'), $base)['status']);
        same(0666, modeOf($base . '/file'));
    } finally {
        umask($mask);
    }
});

$tests['chmod recursion includes hidden names processes children before parents and skips nested links'] = fn () => fixture(function ($base) {
    mkdir($base . '/tree');
    mkdir($base . '/tree/sub');
    file_put_contents($base . '/tree/.hidden', '');
    file_put_contents($base . '/tree/sub/file', '');
    $result = _chmod(parseCommand('chmod -R a= tree'), $base);
    same(0, $result['status']);
    same(['tree/.hidden', 'tree/sub/file', 'tree/sub', 'tree'], array_column($result['data']['entries'], 'name'));
    same(0000, modeOf($base . '/tree'));
    chmod($base . '/tree', 0700);
    chmod($base . '/tree/sub', 0700);
    same(0000, modeOf($base . '/tree/sub/file'));
    symlinkSupport($base);
    file_put_contents($base . '/outside', '');
    chmod($base . '/outside', 0600);
    symlink('../outside', $base . '/tree/link');
    symlink('.', $base . '/tree/cycle');
    symlink('absent', $base . '/tree/broken');
    $result = _chmod(parseCommand('chmod -R a+rwX tree'), $base);
    same(0, $result['status']);
    same(0600, modeOf($base . '/outside'));
    same(3, count($result['data']['skipped']));
    same(['symbolic-link'], array_values(array_unique(array_column($result['data']['skipped'], 'reason'))));
    same(0777, modeOf($base . '/tree'));
    same(0666, modeOf($base . '/tree/sub/file'));
});

$tests['chmod follows explicit links including directory trees but errors on dangling operands'] = fn () => fixture(function ($base) {
    symlinkSupport($base);
    mkdir($base . '/directory');
    file_put_contents($base . '/directory/file', '');
    symlink('directory', $base . '/link');
    symlink('absent', $base . '/broken');
    same(0, _chmod(parseCommand('chmod -R 700 link'), $base)['status']);
    same(0700, modeOf($base . '/directory/file'));
    same('directory', readlink($base . '/link'));
    same(1, _chmod(parseCommand('chmod 600 broken'), $base)['status']);
    check(!file_exists($base . '/absent'));
});

$tests['chmod recursion restores directory access before visiting descendants'] = fn () => fixture(function ($base) {
    mkdir($base . '/tree');
    mkdir($base . '/tree/sub');
    file_put_contents($base . '/tree/sub/file', 'keep');
    chmod($base . '/tree/sub', 0000);
    chmod($base . '/tree', 0000);
    $result = _chmod(parseCommand('chmod -R u+rwX tree'), $base);
    same(0, $result['status']);
    same(['tree', 'tree/sub', 'tree/sub/file'], array_column($result['data']['entries'], 'name'));
    same(0700, modeOf($base . '/tree'));
    same(0700, modeOf($base . '/tree/sub'));
    same('keep', file_get_contents($base . '/tree/sub/file'));
});

$tests['chmod reference copies dereferenced permission bits once and rejects invalid sources'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/source', '');
    file_put_contents($base . '/destination', 'keep');
    chmod($base . '/source', 0710);
    chmod($base . '/destination', 0600);
    same(0, _chmod(parseCommand('chmod --reference=source destination'), $base)['status']);
    same(0710, modeOf($base . '/destination'));
    same(1, _chmod(parseCommand('chmod --reference=absent destination'), $base)['status']);
    same(0710, modeOf($base . '/destination'));
    same(2, _chmod(['args' => ['destination'], 'options' => ['mode' => '600', 'reference' => 'source']], $base)['status']);
    same(2, _chmod(parseCommand('chmod --reference=file:///tmp/ref destination'), $base)['status']);
    symlinkSupport($base);
    symlink('source', $base . '/reference-link');
    same(0, _chmod(parseCommand('chmod --reference=reference-link destination'), $base)['status']);
    same(0710, modeOf($base . '/destination'));
});

$tests['chmod verbose changes precedence and actual unchanged reporting work'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/file', '');
    chmod($base . '/file', 0600);
    same('', coreutilsText(_chmod(parseCommand('chmod -c 600 file'), $base)));
    check(strpos(coreutilsText(_chmod(parseCommand('chmod -v 600 file'), $base)), 'retained as 0600') !== false);
    check(strpos(coreutilsText(_chmod(parseCommand('chmod -c 644 file'), $base)), 'changed from 0600 to 0644') !== false);
    same('', coreutilsText(_chmod(parseCommand('chmod -vc 644 file'), $base)));
    check(strpos(coreutilsText(_chmod(parseCommand('chmod -cv 644 file'), $base)), 'retained as 0644') !== false);
});

$tests['chmod invalid input cannot mutate files and filesystem errors continue later operands'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/file', 'keep');
    chmod($base . '/file', 0644);
    foreach (['chmod', 'chmod 600', 'chmod 888 file', 'chmod u+q file', 'chmod 600 file ""',
        'chmod 600 file file:///tmp/x', 'chmod -w file', 'chmod -x 600 file'] as $command) {
        same(2, _chmod(parseCommand($command), $base)['status']);
        same(0644, modeOf($base . '/file'));
    }
    foreach ([['options' => ['mode' => 600]], ['args' => ['600', null]],
        ['args' => ['600', 'file'], 'flags' => ['R']], ['args' => ['600', 'file'], 'options' => ['recursive' => 'yes']]] as $input) {
        same(2, _chmod($input, $base)['status']);
        same(0644, modeOf($base . '/file'));
    }
    $result = _chmod(parseCommand('chmod 600 absent file'), $base);
    same(1, $result['status']);
    same(0600, modeOf($base . '/file'));
    same('absent', $result['errors'][0]['path']);
    same(1, _chmod(parseCommand('chmod 777 file/'), $base)['status']);
    same(0600, modeOf($base . '/file'));
    same(1, _chmod(parseCommand('chmod -R 755 /'), $base)['status']);
    same(1, _chmod(parseCommand('chmod 600 file'), $base . '/missing')['status']);
    same(0, _chmod(parseCommand('chmod -- -w file'), $base)['status']);
    same(0400, modeOf($base . '/file'));
});

$tests['stat records native metadata independently of presentation and does not change file content'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/file', "abc\0");
    chmod($base . '/file', 0640);
    touch($base . '/file', 1600000000, 1500000000);
    clearstatcache(true, $base . '/file');
    $expected = lstat($base . '/file');
    $result = _stat(parseCommand('stat file'), $base);
    same(0, $result['status']);
    $entry = $result['data']['entries'][0];
    same('file', $entry['name']);
    same($base . '/file', $entry['path']);
    same('f', $entry['type']);
    same('regular file', $entry['filetype']);
    same('-rw-r-----', $entry['permissions']);
    same(null, $entry['birthtime']);
    same(null, $entry['target']);
    foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'rdev', 'size', 'atime', 'mtime', 'ctime'] as $key) {
        same($expected[$key], $entry[$key]);
    }
    check(strpos(coreutilsText($result), 'File: file') !== false);
    check(strpos(coreutilsText($result), '0640/-rw-r-----') !== false);
    same("abc\0", file_get_contents($base . '/file'));
});

$tests['stat format directives timestamps percent widths and empty formats have newline termination'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/file', 'abc');
    chmod($base . '/file', 0600);
    touch($base . '/file', 1600000000, 1500000000);
    $result = _stat(parseCommand('stat -c "%n|%s|%a|%A|%F|%X|%Y|%w|%W|%%" file'), $base);
    same(0, $result['status']);
    same("file|3|600|-rw-------|regular file|1500000000|1600000000|-|0|%\n", coreutilsText($result));
    same("0600|  3|file  \n", coreutilsText(_stat(parseCommand('stat -c "%04a|%3s|%-6n" file'), $base)));
    same(" file|00003\n", coreutilsText(_stat(parseCommand('stat -c "%05n|%05s" file'), $base)));
    same("\n\n", coreutilsText(_stat(['args' => ['file', 'file'], 'options' => ['format' => '']], $base)));
    same("file\\n\n", coreutilsText(_stat(['args' => ['file'], 'options' => ['format' => '%n\\n']], $base)));
    $entry = $result['data']['entries'][0];
    same(
        dechex($entry['mode']) . '|' . $entry['dev'] . '|' . dechex($entry['dev']) . '|'
        . $entry['ino'] . '|' . $entry['nlink'] . "|512\n",
        coreutilsText(_stat(['args' => ['file'], 'options' => ['format' => '%f|%d|%D|%i|%h|%B']], $base))
    );
    foreach (['%U', '%G', '%x', '%y', '%z', '%Z', '%r', '%R', '%o', '%b'] as $format) {
        same(0, _stat(['args' => ['file'], 'options' => ['format' => $format]], $base)['status']);
    }
});

$tests['stat distinguishes files directories links dangling links and explicit dereference'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/empty', '');
    mkdir($base . '/directory');
    $result = _stat(parseCommand('stat -c "%F" empty directory'), $base);
    same("regular empty file\ndirectory\n", coreutilsText($result));
    same(1, _stat(parseCommand('stat empty/'), $base)['status']);
    symlinkSupport($base);
    symlink('empty', $base . '/link');
    symlink('absent', $base . '/broken');
    symlink('directory', $base . '/dir-link');
    $result = _stat(parseCommand('stat link broken dir-link'), $base);
    same(0, $result['status']);
    same(['l', 'l', 'l'], array_column($result['data']['entries'], 'type'));
    same(['empty', 'absent', 'directory'], array_column($result['data']['entries'], 'target'));
    same("link -> empty\nbroken -> absent\n", coreutilsText(_stat(parseCommand('stat -c %N link broken'), $base)));
    $result = _stat(parseCommand('stat -L link broken dir-link'), $base);
    same(1, $result['status']);
    same(['f', 'd'], array_column($result['data']['entries'], 'type'));
    same([null, null], array_column($result['data']['entries'], 'target'));
    same('broken', $result['errors'][0]['path']);
    same('d', _stat(parseCommand('stat dir-link/'), $base)['data']['entries'][0]['type']);
});

$tests['stat accepts multiple absolute space and dash names and retains successes after errors'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/two words', 'a');
    file_put_contents($base . '/-file', 'b');
    $result = _stat(parseCommand('stat -c %n -- "two words" absent -file'), $base);
    same(1, $result['status']);
    same(['two words', '-file'], array_column($result['data']['entries'], 'name'));
    same(0, _stat(['args' => [$base . '/two words']], $base)['status']);
    same(1, _stat(parseCommand('stat "two words"'), $base . '/absent')['status']);
    same(0, _stat(parseCommand('stat "two words"'), $base)['status']);
});

$tests['stat validates operands and format grammar before filesystem access'] = function () {
    foreach (['stat', 'stat ""', 'stat file:///tmp/x', 'stat -x file', 'stat -c %Q file',
        'stat -c % file', 'stat -c %8193s file', 'stat -c %999999999999s file',
        'stat -c %.3Y file', 'stat -c %Ha file', 'stat --format'] as $command) {
        same(2, _stat(parseCommand($command), '/absent')['status']);
    }
    foreach ([['args' => [null]], ['args' => ["file\0"]], ['args' => ['file'], 'options' => ['format' => 1]],
        ['args' => ['file'], 'options' => ['format' => "bad\0"]], ['args' => ['file'], 'flags' => ['L']]] as $input) {
        same(2, _stat($input, '/absent')['status']);
    }
    check(coreutilsStatFormat('%8192s') !== null);
};

$tests['attribute command help needs no valid cwd and names are escaped only for HTML'] = fn () => fixture(function ($base) {
    foreach (['_chmod' => 'chmod', '_stat' => 'stat'] as $function => $command) {
        $result = $function(parseCommand($command . ' --help'), '/absent');
        same(0, $result['status']);
        check(strpos(coreutilsText($result), 'Usage: ' . $command) === 0);
    }
    file_put_contents($base . '/<name>', '');
    $stat = _stat(['args' => ['<name>'], 'options' => ['format' => '%n']], $base);
    same("<name>\n", coreutilsText($stat));
    check(strpos(coreutilsHtml($stat), '<name>') === false);
    check(strpos(coreutilsHtml($stat), '&lt;name&gt;') !== false);
    $chmod = _chmod(['args' => ['600', '<name>'], 'options' => ['verbose' => true]], $base);
    check(strpos(coreutilsHtml($chmod), '<name>') === false);
    same('<name>', $chmod['data']['entries'][0]['name']);
});

$tests['attribute commands do not alter process state emit output or leak warning handlers'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/file', '');
    $cwd = getcwd();
    $mask = umask();
    $locale = setlocale(LC_ALL, 0);
    $timezone = date_default_timezone_get();
    $streams = count(get_resources('stream'));
    ob_start();
    _chmod(parseCommand('chmod 600 file'), $base);
    _stat(parseCommand('stat file'), $base);
    same('', ob_get_clean());
    $called = false;
    set_error_handler(function () use (&$called) {
        $called = true;
        return true;
    });
    try {
        same(1, _chmod(parseCommand('chmod 600 absent'), $base)['status']);
        same(1, _stat(parseCommand('stat absent'), $base)['status']);
        same(false, $called);
        trigger_error('attributes handler probe', E_USER_WARNING);
        same(true, $called);
    } finally {
        restore_error_handler();
    }
    same($cwd, getcwd());
    same($mask, umask());
    same($locale, setlocale(LC_ALL, 0));
    same($timezone, date_default_timezone_get());
    same($streams, count(get_resources('stream')));
});

$tests['stat inspects FIFOs without opening them and chmod changes their modes'] = fn () => fixture(function ($base) {
    skipUnless(function_exists('posix_mkfifo'), 'posix_mkfifo() unavailable');
    skipUnless(coreutilsFsCall(fn () => posix_mkfifo($base . '/fifo', 0600)), 'FIFO creation unavailable');
    $result = _stat(parseCommand('stat fifo'), $base);
    same(0, $result['status']);
    same('p', $result['data']['entries'][0]['type']);
    same('fifo', $result['data']['entries'][0]['filetype']);
    same(0, _chmod(parseCommand('chmod 640 fifo'), $base)['status']);
    same(0640, modeOf($base . '/fifo'));
});
