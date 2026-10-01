<?php

$tests['du parses aliases values clusters and double dash'] = function () {
    same(['all' => true, 'human-readable' => true, 'total' => true, 'max-depth' => '2'], parseCommand('du -ahcd2 tree')['options']);
    same(['block-size' => '1KiB', 'max-depth' => '0'], parseCommand('du --block-size=1KiB --max-depth 0 tree')['options']);
    same(['-file'], parseCommand('du -- -file')['args']);
    same('arguments', coreutilsDuSettings(parseCommand('du -H')['options'])['links']);
    same('physical', coreutilsDuSettings(parseCommand('du -DLP')['options'])['links']);
    same('logical', coreutilsDuSettings(parseCommand('du -PL')['options'])['links']);
};

$tests['du scaling is ordered apparent size persists and sizes reject overflow'] = function () {
    same('human-readable', coreutilsDuSettings(parseCommand('du -bh')['options'])['format']);
    same(true, coreutilsDuSettings(parseCommand('du -bh')['options'])['apparent']);
    same(1, coreutilsDuSettings(parseCommand('du -hb')['options'])['block-size']);
    same(1024, coreutilsDuSettings(parseCommand('du -B1 -k')['options'])['block-size']);
    same(1048576, coreutilsDuSettings(parseCommand('du -m')['options'])['block-size']);
    same('si', coreutilsDuSettings(parseCommand('du -h --si')['options'])['format']);
    foreach (['0', '', '-1', '+1', '1.5K', '1Q', '1B', '1 K', '1Kjunk', '999999999999999999999999'] as $size) {
        same(null, coreutilsDuBlockSize($size));
    }
    foreach (['1' => 1, 'K' => 1024, '2M' => 2097152, '3KB' => 3000, 'KiB' => 1024] as $size => $expected) {
        same($expected, coreutilsDuBlockSize((string) $size)['block-size']);
    }
    same('K', coreutilsDuBlockSize('K')['suffix']);
    same('', coreutilsDuBlockSize('1K')['suffix']);
    same('kB', coreutilsDuBlockSize('KB')['suffix']);
};

$tests['du validates before filesystem access and help needs no cwd'] = function () {
    foreach (['du -d-1', 'du -d1.5', 'du -d9999999999999999999999', 'du -B0', 'du -sa', 'du -sd1', 'du --unknown'] as $command) {
        $result = du(parseCommand($command), '/missing-cwd');
        same(2, $result['status']);
        same([], $result['data']['entries']);
        check(coreutilsText($result) !== '');
    }
    foreach ([['args' => ['']], ['args' => ["nul\0"]], ['args' => ['php://memory']],
        ['args' => [123]], ['options' => ['max-depth' => 1]], ['options' => ['all' => 'yes']],
        ['command' => 'ls'], ['options' => ['unknown' => true]]] as $input) {
        same(2, du($input, '/missing-cwd')['status']);
    }
    $result = du(parseCommand('du --help'), '/missing-cwd');
    same(0, $result['status']);
    check(strpos(coreutilsText($result), 'Usage: du') === 0);
    check(strpos(coreutilsText($result), '--max-depth') !== false);
    same(1, du(parseCommand('du'), '/missing-cwd')['status']);
    same(2, du(parseCommand('du -c -B0'))['status']);
    check(strpos(coreutilsText(du(parseCommand('du -c -B0'))), 'total') === false);
};

$tests['du sums hidden and nested files postorder with a default starting path'] = fn () => fixture(function ($base) {
    mkdir($base . '/tree');
    mkdir($base . '/tree/sub');
    mkdir($base . '/tree/empty');
    file_put_contents($base . '/tree/.hidden', 'abc');
    file_put_contents($base . '/tree/sub/file', '12345');
    $result = du(parseCommand('du -b tree'), $base);
    same(0, $result['status']);
    same(['tree/empty', 'tree/sub', 'tree'], array_column($result['data']['entries'], 'name'));
    same([0, 5, 8], array_column($result['data']['entries'], 'bytes'));
    same([1, 1, 0], array_column($result['data']['entries'], 'depth'));
    same(8, $result['data']['total']);
    same(8, du(parseCommand('du -bs'), $base . '/tree')['data']['total']);
    same(['.'], array_column(du(parseCommand('du -bs'), $base . '/tree')['data']['entries'], 'name'));
    same(0, du(parseCommand('du -bs tree tree'), $base)['status']);
    same(['tree'], array_column(du(parseCommand('du -bs tree tree'), $base)['data']['entries'], 'name'));
});

$tests['du all depth and summarize select output without pruning counts'] = fn () => fixture(function ($base) {
    mkdir($base . '/tree');
    mkdir($base . '/tree/sub');
    mkdir($base . '/tree/sub/deep');
    file_put_contents($base . '/tree/a', '12');
    file_put_contents($base . '/tree/sub/deep/f', '1234567');
    $result = du(parseCommand('du -bad1 tree'), $base);
    same(['tree/a', 'tree/sub', 'tree'], array_column($result['data']['entries'], 'name'));
    same([2, 7, 9], array_column($result['data']['entries'], 'bytes'));
    same(9, $result['data']['total']);
    same(['tree'], array_column(du(parseCommand('du -bad0 tree'), $base)['data']['entries'], 'name'));
    same(['tree'], array_column(du(parseCommand('du -bsd0 tree'), $base)['data']['entries'], 'name'));
    same([7, 7, 9], array_column(du(parseCommand('du -b tree'), $base)['data']['entries'], 'bytes'));
});

$tests['du allocated counts use stat blocks including directory allocation'] = fn () => fixture(function ($base) {
    mkdir($base . '/tree');
    file_put_contents($base . '/tree/file', 'small');
    $file = lstat($base . '/tree/file');
    $directory = lstat($base . '/tree');
    skipUnless(isset($file['blocks'], $directory['blocks']) && $file['blocks'] >= 0 && $directory['blocks'] >= 0,
        'allocated block metadata is unavailable');
    $result = du(parseCommand('du -ac tree'), $base);
    same(0, $result['status']);
    same($file['blocks'] * 512, $result['data']['entries'][0]['bytes']);
    same(($file['blocks'] + $directory['blocks']) * 512, $result['data']['total']);
    same((string) (intdiv($result['data']['total'], 1024) + ($result['data']['total'] % 1024 ? 1 : 0)) . "\ttotal\n",
        substr(coreutilsText($result), strrpos(rtrim(coreutilsText($result)), "\n") + 1));
});

$tests['du sparse apparent size does not require reading contents'] = fn () => fixture(function ($base) {
    $stream = fopen($base . '/sparse', 'wb');
    fseek($stream, 16 * 1024 * 1024);
    fwrite($stream, 'x');
    fclose($stream);
    $result = du(parseCommand('du -b sparse'), $base);
    same(0, $result['status']);
    same(16 * 1024 * 1024 + 1, $result['data']['total']);
    chmod($base . '/sparse', 0000);
    same(16 * 1024 * 1024 + 1, du(parseCommand('du -b sparse'), $base)['data']['total']);
});

$tests['du counts hard links once across operands unless count links is selected'] = fn () => fixture(function ($base) {
    skipUnless(function_exists('link'), 'link() unavailable');
    file_put_contents($base . '/first', 'hello');
    skipUnless(coreutilsFsCall(fn () => link($base . '/first', $base . '/second')), 'hard links unavailable');
    $result = du(parseCommand('du -bc first second first'), $base);
    same(0, $result['status']);
    same(['first'], array_column($result['data']['entries'], 'name'));
    same(5, $result['data']['total']);
    $result = du(parseCommand('du -bl first second first'), $base);
    same(['first', 'second', 'first'], array_column($result['data']['entries'], 'name'));
    same(15, $result['data']['total']);
});

$tests['du symlinks default to link sizes and dereferencing accepts dangling errors'] = fn () => fixture(function ($base) {
    symlinkSupport($base);
    mkdir($base . '/tree');
    file_put_contents($base . '/tree/file', '12345');
    symlink('tree', $base . '/alias');
    symlink('absent', $base . '/broken');
    same(4, du(parseCommand('du -b alias'), $base)['data']['total']);
    same(6, du(parseCommand('du -b broken'), $base)['data']['total']);
    same(5, du(parseCommand('du -bD alias'), $base)['data']['total']);
    same(5, du(parseCommand('du -bH alias'), $base)['data']['total']);
    same(5, du(parseCommand('du -bL alias'), $base)['data']['total']);
    same(5, du(parseCommand('du -b alias/'), $base)['data']['total']);
    $result = du(parseCommand('du -bL broken tree'), $base);
    same(1, $result['status']);
    same(5, $result['data']['total']);
    same('broken', $result['errors'][0]['path']);
});

$tests['du dereference args does not follow nested links and cycles terminate with count links'] = fn () => fixture(function ($base) {
    symlinkSupport($base);
    mkdir($base . '/tree');
    file_put_contents($base . '/target', '123456789');
    symlink('../target', $base . '/tree/link');
    symlink('tree', $base . '/alias');
    same(9, du(parseCommand('du -bD alias'), $base)['data']['total']);
    same(9, du(parseCommand('du -bL alias'), $base)['data']['total']);
    symlink('.', $base . '/tree/cycle');
    $result = du(parseCommand('du -blaL tree'), $base);
    same(0, $result['status']);
    same(['tree/link', 'tree'], array_column($result['data']['entries'], 'name'));
    same(9, $result['data']['total']);
    symlink('loop', $base . '/loop');
    same(1, du(parseCommand('du -bL loop'), $base)['status']);
});

$tests['du separate dirs excludes subdirectory sizes but grand total remains inclusive'] = fn () => fixture(function ($base) {
    mkdir($base . '/tree');
    mkdir($base . '/tree/sub');
    file_put_contents($base . '/tree/file', '12');
    file_put_contents($base . '/tree/sub/file', '12345');
    $result = du(parseCommand('du -bSc tree'), $base);
    same([5, 2], array_column($result['data']['entries'], 'bytes'));
    same(7, $result['data']['total']);
    same("5\ttree/sub\n2\ttree\n7\ttotal\n", coreutilsText($result));
});

$tests['du file operands and failures retain successful results without leaking state'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/file', '123');
    $result = du(parseCommand('du -bc missing file file/'), $base);
    same(1, $result['status']);
    same(['file'], array_column($result['data']['entries'], 'name'));
    same(3, $result['data']['total']);
    same(2, count($result['errors']));
    same(0, du(parseCommand('du -b file'), $base)['status']);
    same(3, du(parseCommand('du -b file'), $base)['data']['total']);
});

$tests['du null rows preserve raw names and HTML escapes the entire presentation'] = fn () => fixture(function ($base) {
    skipUnless(DIRECTORY_SEPARATOR !== '\\', 'Windows filename restrictions');
    $name = "<tag>\nfile";
    file_put_contents($base . '/' . $name, '123');
    $result = du(['args' => [$name], 'options' => ['bytes' => true, 'null' => true, 'total' => true]], $base);
    same("3\t" . $name . "\0" . "3\ttotal\0", coreutilsText($result));
    same($name, $result['data']['entries'][0]['name']);
    check(strpos(coreutilsHtml($result), '<tag>') === false);
    check(strpos(coreutilsHtml($result), '&lt;tag&gt;') !== false);
    check(strpos(coreutilsHtml(du(['args' => ['<missing>']], $base)), '<missing>') === false);
});

$tests['du display rounds integers exactly and reuses human size formatting'] = function () {
    same('2', coreutilsDuSizeText(1025, coreutilsDuBlockSize('1024')));
    same('0K', coreutilsDuSizeText(0, coreutilsDuBlockSize('K')));
    same('2K', coreutilsDuSizeText(1025, coreutilsDuBlockSize('K')));
    same('2kB', coreutilsDuSizeText(1001, coreutilsDuBlockSize('KB')));
    same('1.1K', coreutilsDuSizeText(1025, coreutilsDuBlockSize('human-readable')));
    same('1.1k', coreutilsDuSizeText(1001, coreutilsDuBlockSize('si')));
    same((string) PHP_INT_MAX, coreutilsDuSizeText(PHP_INT_MAX, coreutilsDuBlockSize('1')));
    same('?', coreutilsDuSizeText(null, coreutilsDuBlockSize('1')));
};

$tests['du unavailable and overflowing metadata stays unknown instead of becoming apparent size'] = function () {
    $result = coreutilsResult('du');
    same(null, coreutilsDuBytes(['mode' => 0100000, 'size' => 123], false, 'file', $result));
    same(1, $result['status']);
    same('size-unavailable', $result['errors'][0]['code']);
    same(123, coreutilsDuBytes(['mode' => 0100000, 'size' => 123], true, 'file', $result));
    same(0, coreutilsDuBytes(['mode' => 0040000, 'size' => 4096], true, 'tree', $result));
    same(null, coreutilsDuBytes(['mode' => 0100000, 'blocks' => PHP_INT_MAX], false, 'file', $result));
    same(null, coreutilsDuSum(PHP_INT_MAX, 1, 'tree', $result));
    same(null, coreutilsDuSum(null, 1, 'tree', $result));
    same(30, coreutilsDuSum(10, 20, 'tree', $result));
};

$tests['du unreadable directories retain known own allocation and continue siblings'] = fn () => fixture(function ($base) {
    mkdir($base . '/denied');
    file_put_contents($base . '/denied/inside', 'invisible');
    chmod($base . '/denied', 0000);
    skipUnless(coreutilsFsCall(fn () => scandir($base . '/denied')) === false, 'process bypasses directory permissions');
    file_put_contents($base . '/visible', 'yes');
    $result = du(parseCommand('du -bc denied visible'), $base);
    same(1, $result['status']);
    same(['denied', 'visible'], array_column($result['data']['entries'], 'name'));
    same(3, $result['data']['total']);
});

$tests['du special files are inspected without opening or blocking'] = fn () => fixture(function ($base) {
    skipUnless(function_exists('posix_mkfifo'), 'posix_mkfifo() unavailable');
    skipUnless(coreutilsFsCall(fn () => posix_mkfifo($base . '/fifo', 0600)), 'FIFO creation unavailable');
    $result = du(parseCommand('du -b fifo'), $base);
    same(0, $result['status']);
    same('p', $result['data']['entries'][0]['type']);
    same(0, $result['data']['total']);
});

$tests['du one filesystem skips followed descendant directories on other devices'] = fn () => fixture(function ($base) {
    symlinkSupport($base);
    $device = lstat($base)['dev'];
    $other = null;
    foreach (['/dev/shm', '/proc', '/dev'] as $candidate) {
        $stat = coreutilsLstat($candidate);
        if ($stat !== false && ($stat['mode'] & 0170000) === 0040000 && $stat['dev'] !== $device) {
            $other = $candidate;
            break;
        }
    }
    skipUnless($other !== null, 'no directory on another device is available');
    mkdir($base . '/tree');
    file_put_contents($base . '/tree/file', 'abc');
    symlink($other, $base . '/tree/other-device');
    $result = du(parseCommand('du -baLx tree'), $base);
    same(0, $result['status']);
    same(['tree/file', 'tree'], array_column($result['data']['entries'], 'name'));
    same(3, $result['data']['total']);
});

$tests['du deep trees use iterative traversal and do not change process settings'] = fn () => fixture(function ($base) {
    $path = $base;
    for ($depth = 0; $depth < 80; $depth++) {
        $path .= '/d';
        mkdir($path);
    }
    file_put_contents($path . '/f', '123');
    $cwd = getcwd();
    $mask = umask();
    $locale = setlocale(LC_ALL, 0);
    ob_start();
    $result = du(parseCommand('du -bs'), $base);
    same('', ob_get_clean());
    same(0, $result['status']);
    same(3, $result['data']['total']);
    same($cwd, getcwd());
    same($mask, umask());
    same($locale, setlocale(LC_ALL, 0));
});
