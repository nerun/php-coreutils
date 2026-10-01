<?php

function touchTimes(string $path): array
{
    clearstatcache(true, $path);
    $stat = stat($path);
    return [$stat['mtime'], $stat['atime']];
}

$tests['touch parses clustered flags attached values and long aliases'] = function () {
    same(['access' => true, 'modification' => true, 'no-create' => true], parseCommand('touch -amc file')['options']);
    same(['reference' => 'ref'], parseCommand('touch -rref file')['options']);
    same(['reference' => 'ref'], parseCommand('touch --reference=ref file')['options']);
    same(['reference' => 'ref'], parseCommand('touch --reference ref file')['options']);
    same(['timestamp' => '202609301200'], parseCommand('touch -t202609301200 file')['options']);
    same(['reference' => 'last'], parseCommand('touch -r first --reference=last file')['options']);
    same(['timestamp' => '202609301300'], parseCommand('touch -t202609301200 -t202609301300 file')['options']);
};

$tests['touch creates empty files and resolves quoted absolute and dash names'] = fn () => fixture(function ($base) {
    $result = _touch(parseCommand('touch "two words" -- -dash -'), $base);
    same(0, $result['status']);
    same([$base . '/two words', $base . '/-dash', $base . '/-'], $result['data']['created']);
    same([], $result['data']['updated']);
    same([], $result['data']['skipped']);
    same('', coreutilsText($result));
    foreach ($result['data']['created'] as $path) {
        check(is_file($path));
        same(0, filesize($path));
    }
    $result = _touch(['args' => [$base . '/absolute']], $base);
    same(0, $result['status']);
    same([$base . '/absolute'], $result['data']['created']);
});

$tests['touch updates existing files without truncating content or changing mode'] = fn () => fixture(function ($base) {
    $path = $base . '/file';
    $content = "keep\0this\ncontent";
    file_put_contents($path, $content);
    touch($path, 946684800, 946684900);
    $mode = modeOf($path);
    $before = time();
    $result = _touch(parseCommand('touch file'), $base);
    $after = time();
    same(0, $result['status']);
    same([], $result['data']['created']);
    same([$path], $result['data']['updated']);
    foreach (touchTimes($path) as $timestamp) {
        check($timestamp >= $before && $timestamp <= $after);
    }
    same($content, file_get_contents($path));
    same($mode, modeOf($path));
    same('', coreutilsText($result));
});

$tests['touch access modification and combined flags preserve the unselected time'] = fn () => fixture(function ($base) {
    touch($base . '/file', 946684800, 946684900);
    $stamp = '202402291234.56';
    $time = coreutilsTouchTimestamp($stamp);
    same(0, _touch(parseCommand('touch -a -t ' . $stamp . ' file'), $base)['status']);
    same([946684800, $time], touchTimes($base . '/file'));
    touch($base . '/file', 946684800, 946684900);
    same(0, _touch(parseCommand('touch -m -t ' . $stamp . ' file'), $base)['status']);
    same([$time, 946684900], touchTimes($base . '/file'));
    touch($base . '/file', 946684800, 946684900);
    same(0, _touch(parseCommand('touch -am -t ' . $stamp . ' file'), $base)['status']);
    same([$time, $time], touchTimes($base . '/file'));
    touch($base . '/file', 946684800, 946684900);
    same(0, _touch(['args' => ['file'], 'options' => [
        'access' => false, 'modification' => false, 'timestamp' => $stamp,
    ]], $base)['status']);
    same([$time, $time], touchTimes($base . '/file'));
});

$tests['touch single-time creation leaves the other time at creation time'] = fn () => fixture(function ($base) {
    $stamp = '202001020304.05';
    $time = coreutilsTouchTimestamp($stamp);
    foreach (['-a' => 1, '-m' => 0] as $flag => $index) {
        $before = time();
        same(0, _touch(['args' => [$flag], 'options' => [
            $flag === '-a' ? 'access' : 'modification' => true, 'timestamp' => $stamp,
        ]], $base)['status']);
        $after = time();
        $times = touchTimes($base . '/' . $flag);
        same($time, $times[$index]);
        check($times[1 - $index] >= $before && $times[1 - $index] <= $after);
    }
});

$tests['touch access and modification default to current time and repeated calls refresh metadata'] = fn () => fixture(function ($base) {
    foreach (['-a' => 1, '-m' => 0] as $flag => $index) {
        touch($base . '/file', 946684800, 946684900);
        $old = touchTimes($base . '/file');
        $before = time();
        same(0, _touch(parseCommand('touch ' . $flag . ' file'), $base)['status']);
        $after = time();
        $times = touchTimes($base . '/file');
        same($old[1 - $index], $times[1 - $index]);
        check($times[$index] >= $before && $times[$index] <= $after);
    }
    same(0, _touch(parseCommand('touch -t202001020304 file'), $base)['status']);
    same(0, _touch(parseCommand('touch -t202101020304 file'), $base)['status']);
    same([coreutilsTouchTimestamp('202101020304'), coreutilsTouchTimestamp('202101020304')], touchTimes($base . '/file'));
});

$tests['touch no-create skips absent files but still updates existing entries'] = fn () => fixture(function ($base) {
    touch($base . '/file', 946684800, 946684900);
    foreach (['-c', '--no-create'] as $option) {
        $result = _touch(parseCommand('touch missing ' . $option . ' -t202001020304 file absent/child'), $base);
        same(0, $result['status']);
        same([], $result['data']['created']);
        same([$base . '/file'], $result['data']['updated']);
        same([
            ['path' => $base . '/missing', 'reason' => 'not-found'],
            ['path' => $base . '/absent/child', 'reason' => 'not-found'],
        ], $result['data']['skipped']);
        check(!file_exists($base . '/missing'));
        check(!file_exists($base . '/absent'));
    }
});

$tests['touch copies distinct reference times and preserves unselected target times'] = fn () => fixture(function ($base) {
    touch($base . '/ref', 946684800, 946684900);
    $result = _touch(parseCommand('touch --reference=ref new'), $base);
    same(0, $result['status']);
    same([946684800, 946684900], touchTimes($base . '/new'));
    touch($base . '/new', 978307200, 978307300);
    same(0, _touch(parseCommand('touch -a -rref new'), $base)['status']);
    same([978307200, 946684900], touchTimes($base . '/new'));
    touch($base . '/new', 978307200, 978307300);
    same(0, _touch(parseCommand('touch -m --reference ref new'), $base)['status']);
    same([946684800, 978307300], touchTimes($base . '/new'));
    same([946684800, 946684900], touchTimes($base . '/ref'));
});

$tests['touch snapshots a reference before processing multiple operands including itself'] = fn () => fixture(function ($base) {
    touch($base . '/ref', 946684800, 946684900);
    $result = _touch(parseCommand('touch -r ref ref one two'), $base);
    same(0, $result['status']);
    same([$base . '/one', $base . '/two'], $result['data']['created']);
    same([$base . '/ref'], $result['data']['updated']);
    foreach (['ref', 'one', 'two'] as $name) {
        same([946684800, 946684900], touchTimes($base . '/' . $name));
    }
});

$tests['touch missing reference fails before modifying any operand even with no-create'] = fn () => fixture(function ($base) {
    touch($base . '/existing', 946684800, 946684900);
    foreach (['touch -r missing existing new', 'touch -c -r missing existing new'] as $command) {
        $result = _touch(parseCommand($command), $base);
        same(1, $result['status']);
        same([], $result['data']['created']);
        same([], $result['data']['updated']);
        same([], $result['data']['skipped']);
        same('filesystem-error', $result['errors'][0]['code']);
        same([946684800, 946684900], touchTimes($base . '/existing'));
        check(!file_exists($base . '/new'));
    }
});

$tests['touch parses year variants seconds epoch and leap years strictly'] = function () {
    same((new DateTimeImmutable('2024-02-29 12:34:56'))->getTimestamp(), coreutilsTouchTimestamp('202402291234.56'));
    same((new DateTimeImmutable('2024-02-29 12:34:00'))->getTimestamp(), coreutilsTouchTimestamp('2402291234'));
    same((new DateTimeImmutable('1969-12-31 23:59:59'))->getTimestamp(), coreutilsTouchTimestamp('6912312359.59'));
    same((new DateTimeImmutable('2068-01-01 00:00:00'))->getTimestamp(), coreutilsTouchTimestamp('6801010000'));
    same((new DateTimeImmutable(date('Y') . '-01-02 03:04:00'))->getTimestamp(), coreutilsTouchTimestamp('01020304'));
    foreach (['', '20240229123', '2024022912345', '202302291234', '202401321234',
        '202413011234', '202400011234', '202401001234', '202401012400',
        '202401011260', '202401011200.60', '202401011200.1', '202401011200.123',
        '2024-01-01', ' 202401011200', "202401011200\0"] as $stamp) {
        same(null, coreutilsTouchTimestamp($stamp));
    }
};

$tests['touch uses application timezone without changing timezone cwd locale umask or output'] = fn () => fixture(function ($base) {
    $timezone = date_default_timezone_get();
    $cwd = getcwd();
    $locale = setlocale(LC_ALL, 0);
    $mask = umask();
    date_default_timezone_set('America/Sao_Paulo');
    try {
        ob_start();
        $result = _touch(parseCommand('touch -t202401021234.56 file'), $base);
        $output = ob_get_clean();
        same('', $output);
        same(0, $result['status']);
        $expected = (new DateTimeImmutable('2024-01-02 12:34:56', new DateTimeZone('America/Sao_Paulo')))->getTimestamp();
        same([$expected, $expected], touchTimes($base . '/file'));
        same('America/Sao_Paulo', date_default_timezone_get());
        same($cwd, getcwd());
        same($locale, setlocale(LC_ALL, 0));
        same($mask, umask());
    } finally {
        date_default_timezone_set($timezone);
    }
});

$tests['touch invalid input and conflicting time sources cannot mutate files'] = fn () => fixture(function ($base) {
    touch($base . '/existing', 946684800, 946684900);
    foreach (['touch', 'touch -x new', 'touch --no-create=yes new', 'touch new -r',
        'touch new --reference', 'touch new -t', 'touch new -t --',
        'touch new -t202302291234', 'touch -r missing -t202401011200 new',
        'touch new ""', 'touch new file:///tmp/bad'] as $command) {
        same(2, _touch(parseCommand($command), $base)['status']);
        check(!file_exists($base . '/new'));
    }
    foreach ([['args' => ['new', null]], ['args' => ["bad\0name"]], ['args' => 'bad'],
        ['args' => ['new'], 'options' => ['access' => 'yes']],
        ['args' => ['new'], 'options' => ['reference' => false]],
        ['args' => ['new'], 'options' => ['reference' => '']],
        ['args' => ['new'], 'options' => ['reference' => 'file:///tmp/ref']],
        ['args' => ['new'], 'options' => ['reference' => "bad\0ref"]],
        ['args' => ['new'], 'options' => ['timestamp' => []]],
        ['args' => ['new'], 'options' => ['unknown' => true]],
        ['args' => ['new'], 'flags' => ['c']], parseCommand('ls')] as $input) {
        $result = _touch($input, $base);
        same(2, $result['status']);
        same([], $result['data']['created']);
        same([], $result['data']['updated']);
        check(!file_exists($base . '/new'));
    }
    same([946684800, 946684900], touchTimes($base . '/existing'));
});

$tests['touch help needs no filesystem access and invalid cwd prevents creation'] = function () {
    $result = _touch(parseCommand('touch --help'), '/does-not-exist');
    same(0, $result['status']);
    same(['created' => [], 'updated' => [], 'skipped' => []], $result['data']);
    check(strpos(coreutilsText($result), 'Usage: touch') === 0);
    check(strpos($result['help'], '--reference') !== false);
    same(1, _touch(parseCommand('touch new'), '/does-not-exist')['status']);
};

$tests['touch supports directories without creating missing parents'] = fn () => fixture(function ($base) {
    mkdir($base . '/directory');
    $stamp = '202001020304.05';
    $result = _touch(parseCommand('touch -t' . $stamp . ' directory absent/child good'), $base);
    same(1, $result['status']);
    same([$base . '/directory'], $result['data']['updated']);
    same([$base . '/good'], $result['data']['created']);
    same([coreutilsTouchTimestamp($stamp), coreutilsTouchTimestamp($stamp)], touchTimes($base . '/directory'));
    check(!file_exists($base . '/absent'));
    same('absent/child', $result['errors'][0]['path']);
});

$tests['touch follows file directory reference and dangling symbolic links'] = fn () => fixture(function ($base) {
    symlinkSupport($base);
    touch($base . '/file', 946684800, 946684900);
    mkdir($base . '/directory');
    symlink('file', $base . '/link');
    symlink('directory', $base . '/dir-link');
    symlink('missing', $base . '/broken');
    $result = _touch(parseCommand('touch -r link copy'), $base);
    same(0, $result['status']);
    same([946684800, 946684900], touchTimes($base . '/copy'));
    $stamp = '202001020304.05';
    $result = _touch(parseCommand('touch -t' . $stamp . ' link dir-link broken'), $base);
    same(0, $result['status']);
    same([$base . '/link', $base . '/dir-link'], $result['data']['updated']);
    same([$base . '/broken'], $result['data']['created']);
    foreach (['file', 'directory', 'missing'] as $name) {
        same([coreutilsTouchTimestamp($stamp), coreutilsTouchTimestamp($stamp)], touchTimes($base . '/' . $name));
    }
    foreach (['link' => 'file', 'dir-link' => 'directory', 'broken' => 'missing'] as $link => $target) {
        check(is_link($base . '/' . $link));
        same($target, readlink($base . '/' . $link));
    }
});

$tests['touch no-create leaves dangling links and their targets untouched'] = fn () => fixture(function ($base) {
    symlinkSupport($base);
    symlink('missing', $base . '/broken');
    $before = coreutilsLstat($base . '/broken');
    $result = _touch(parseCommand('touch -c broken'), $base);
    same(0, $result['status']);
    same([['path' => $base . '/broken', 'reason' => 'not-found']], $result['data']['skipped']);
    check(is_link($base . '/broken'));
    check(!file_exists($base . '/missing'));
    $after = coreutilsLstat($base . '/broken');
    same($before['mtime'], $after['mtime']);
    same($before['atime'], $after['atime']);
});

$tests['touch creation honors native umask'] = fn () => fixture(function ($base) {
    nativePermissionSupport($base);
    $mask = umask(0077);
    try {
        same(0, _touch(parseCommand('touch private'), $base)['status']);
        same(0600, modeOf($base . '/private'));
        same(0077, umask());
    } finally {
        umask($mask);
    }
});

$tests['touch no-create reports invalid traversal and symbolic link loops instead of skipping'] = fn () => fixture(function ($base) {
    touch($base . '/file');
    $result = _touch(parseCommand('touch -c file/child good'), $base);
    same(1, $result['status']);
    same('file/child', $result['errors'][0]['path']);
    same([['path' => $base . '/good', 'reason' => 'not-found']], $result['data']['skipped']);
    symlinkSupport($base);
    symlink('loop', $base . '/loop');
    $result = _touch(parseCommand('touch -c loop'), $base);
    same(1, $result['status']);
    same([], $result['data']['skipped']);
    check(is_link($base . '/loop'));
});

$tests['touch no-create does not hide permission errors'] = fn () => fixture(function ($base) {
    nativePermissionSupport($base);
    mkdir($base . '/locked');
    touch($base . '/locked/file');
    chmod($base . '/locked', 0000);
    try {
        clearstatcache(true, $base . '/locked/file');
        skipUnless(!coreutilsFsCall(fn () => file_exists($base . '/locked/file')), 'process can bypass directory permissions');
        $result = _touch(parseCommand('touch -c locked/file'), $base);
        same(1, $result['status']);
        same([], $result['data']['skipped']);
        same('locked/file', $result['errors'][0]['path']);
    } finally {
        chmod($base . '/locked', 0700);
    }
});

$tests['touch captures warnings restores handlers and escapes diagnostics in HTML'] = fn () => fixture(function ($base) {
    $called = false;
    set_error_handler(function () use (&$called) {
        $called = true;
        return true;
    });
    try {
        ob_start();
        $result = _touch(['args' => ['<tag>/file', 'good']], $base);
        same('', ob_get_clean());
        same(false, $called);
        same(1, $result['status']);
        check(strpos(coreutilsText($result), '<tag>') !== false);
        check(strpos(coreutilsHtml($result), '<tag>') === false);
        check(strpos(coreutilsHtml($result), '&lt;tag&gt;') !== false);
        trigger_error('touch handler probe', E_USER_WARNING);
        same(true, $called);
    } finally {
        restore_error_handler();
    }
});
