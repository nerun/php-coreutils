<?php
$tests['cp copies binary contents without output or source changes'] = fn() => fixture(function ($base) {
    $bytes = "a\0b\xff\n";
    file_put_contents($base . '/source', $bytes);
    ob_start(); $result = cp(parseCommand('cp source target'), $base); same('', ob_get_clean());
    same(0, $result['status']); same($bytes, file_get_contents($base . '/source'));
    same($bytes, file_get_contents($base . '/target'));
    same([['source' => $base . '/source', 'destination' => $base . '/target']], $result['data']['copied']);
    same('', coreutilsText($result));
});
$tests['cp overwrites by default and skips with no-clobber'] = fn() => fixture(function ($base) {
    file_put_contents($base . '/a', 'new'); file_put_contents($base . '/b', 'old');
    $result = cp(parseCommand('cp --no-clobber a b'), $base);
    same(0, $result['status']); same(1, count($result['data']['skipped'])); same('old', file_get_contents($base . '/b'));
    same(0, cp(parseCommand('cp a b'), $base)['status']); same('new', file_get_contents($base . '/b'));
    same('new', file_get_contents($base . '/a'));
});
$tests['cp multiple sources require a directory and continue after errors'] = fn() => fixture(function ($base) {
    file_put_contents($base . '/a', 'a'); file_put_contents($base . '/b', 'b'); mkdir($base . '/out');
    same(1, cp(parseCommand('cp a b missing'), $base)['status']); check(!file_exists($base . '/missing'));
    $result = cp(parseCommand('cp a absent b out'), $base);
    same(1, $result['status']); same(2, count($result['data']['copied']));
    same('a', file_get_contents($base . '/out/a')); same('b', file_get_contents($base . '/out/b'));
});
$tests['cp validates all input before writing and help ignores cwd'] = fn() => fixture(function ($base) {
    file_put_contents($base . '/a', 'a');
    foreach (['cp a', 'cp -z a b', 'cp a ""', 'cp a b https://example.com', 'cp a "unterminated'] as $command) {
        same(2, cp(parseCommand($command), $base)['status']); check(!file_exists($base . '/b'));
    }
    same(2, cp(['args' => ['a', 'b'], 'options' => ['recursive' => 1]], $base)['status']);
    same(1, cp(parseCommand('cp a b'), $base . '/missing')['status']);
    $result = cp(parseCommand('cp --help'), '/nonexistent'); same(0, $result['status']);
    check(strpos(coreutilsText($result), 'Usage: cp') === 0);
});
$tests['cp recursive copies hidden files and empty directories'] = fn() => fixture(function ($base) {
    mkdir($base . '/tree'); mkdir($base . '/tree/empty'); mkdir($base . '/tree/sub');
    file_put_contents($base . '/tree/.hidden', 'hidden'); file_put_contents($base . '/tree/sub/file', 'data');
    same(1, cp(parseCommand('cp tree out'), $base)['status']); check(!file_exists($base . '/out'));
    $result = cp(parseCommand('cp --recursive tree/ out'), $base); same(0, $result['status']);
    same(3, count($result['data']['created'])); same(2, count($result['data']['copied']));
    check(is_dir($base . '/out/empty')); same('hidden', file_get_contents($base . '/out/.hidden'));
    same('data', file_get_contents($base . '/out/sub/file'));
    check(is_dir($base . '/tree/empty'));
});
$tests['cp merges directories and no-clobber skips only existing leaves'] = fn() => fixture(function ($base) {
    mkdir($base . '/tree'); mkdir($base . '/out'); mkdir($base . '/out/tree');
    file_put_contents($base . '/tree/a', 'new'); file_put_contents($base . '/tree/b', 'b');
    file_put_contents($base . '/out/tree/a', 'old'); file_put_contents($base . '/out/tree/keep', 'keep');
    $result = cp(parseCommand('cp -rnv tree out'), $base); same(0, $result['status']);
    same(1, count($result['data']['skipped'])); same('old', file_get_contents($base . '/out/tree/a'));
    same('b', file_get_contents($base . '/out/tree/b')); same('keep', file_get_contents($base . '/out/tree/keep'));
    same(0, cp(parseCommand('cp -r tree out'), $base)['status']); same('new', file_get_contents($base . '/out/tree/a'));
});
$tests['cp rejects self-copy and descendant destinations without mutation'] = fn() => fixture(function ($base) {
    file_put_contents($base . '/a', 'original'); mkdir($base . '/tree'); mkdir($base . '/tree/sub');
    same(1, cp(parseCommand('cp a a'), $base)['status']); same('original', file_get_contents($base . '/a'));
    foreach (['cp -r tree tree', 'cp -r tree tree/sub', 'cp -r tree tree/new', 'cp -r . out'] as $command) {
        same(1, cp(parseCommand($command), $base)['status']);
    }
    check(!file_exists($base . '/tree/tree')); check(!file_exists($base . '/tree/new')); check(!file_exists($base . '/out'));
});
$tests['cp detects same-file hard links'] = fn() => fixture(function ($base) {
    file_put_contents($base . '/a', 'keep');
    skipUnless(function_exists('link') && coreutilsFsCall(fn() => link($base . '/a', $base . '/alias')), 'hard links unavailable');
    same(1, cp(parseCommand('cp a alias'), $base)['status']); same('keep', file_get_contents($base . '/a'));
});
$tests['cp ordinary follows file links but recursive preserves links and cycles'] = fn() => fixture(function ($base) {
    symlinkSupport($base); file_put_contents($base . '/file', 'content'); symlink('file', $base . '/link');
    same(0, cp(parseCommand('cp link copy'), $base)['status']); check(!is_link($base . '/copy'));
    same('content', file_get_contents($base . '/copy'));
    mkdir($base . '/tree'); symlink('missing', $base . '/tree/broken'); symlink('.', $base . '/tree/cycle');
    same(0, cp(parseCommand('cp -r tree out'), $base)['status']);
    same('missing', readlink($base . '/out/broken')); same('.', readlink($base . '/out/cycle'));
    same(0, cp(parseCommand('cp -r link duplicate-link'), $base)['status']); same('file', readlink($base . '/duplicate-link'));
    same(1, cp(parseCommand('cp tree/broken missing-copy'), $base)['status']); check(!file_exists($base . '/missing-copy'));
});
$tests['cp refuses destination links and supports no-clobber for broken links'] = fn() => fixture(function ($base) {
    symlinkSupport($base); file_put_contents($base . '/a', 'new'); file_put_contents($base . '/b', 'old');
    symlink('b', $base . '/target'); symlink('missing', $base . '/broken');
    same(1, cp(parseCommand('cp a target'), $base)['status']); same('old', file_get_contents($base . '/b'));
    same(1, cp(parseCommand('cp a broken'), $base)['status']); check(!file_exists($base . '/missing'));
    $result = cp(parseCommand('cp -n a broken'), $base); same(0, $result['status']); same(1, count($result['data']['skipped']));
});
$tests['cp blocks recursion through destination aliases and does not traverse nested target links'] = fn() => fixture(function ($base) {
    symlinkSupport($base); mkdir($base . '/tree'); mkdir($base . '/tree/sub'); symlink('tree/sub', $base . '/alias');
    same(1, cp(parseCommand('cp -r tree alias'), $base)['status']); check(!file_exists($base . '/tree/sub/tree'));
    mkdir($base . '/out'); mkdir($base . '/out/tree'); mkdir($base . '/elsewhere');
    symlink('../../elsewhere', $base . '/out/tree/sub'); file_put_contents($base . '/tree/sub/a', 'a');
    same(1, cp(parseCommand('cp -r tree out'), $base)['status']); check(!file_exists($base . '/elsewhere/a'));
    same(1, cp(parseCommand('cp -r alias/ copy'), $base)['status']);
});
$tests['cp rejects type collisions, missing parents and special files'] = fn() => fixture(function ($base) {
    mkdir($base . '/tree'); mkdir($base . '/out'); mkdir($base . '/out/a'); file_put_contents($base . '/a', 'a');
    same(1, cp(parseCommand('cp a out'), $base)['status']); check(is_dir($base . '/out/a'));
    same(1, cp(parseCommand('cp -r tree a'), $base)['status']); same('a', file_get_contents($base . '/a'));
    same(1, cp(parseCommand('cp a missing/file'), $base)['status']);
    same(1, cp(parseCommand('cp a missing/'), $base)['status']);
    if (function_exists('posix_mkfifo') && coreutilsFsCall(fn() => posix_mkfifo($base . '/fifo', 0600))) {
        same(1, cp(parseCommand('cp fifo copy'), $base)['status']);
        same(1, cp(parseCommand('cp a fifo'), $base)['status']);
    }
});
$tests['cp handles absolute paths, dash names, cwd and escaped verbose text'] = fn() => fixture(function ($base) {
    file_put_contents($base . '/-a', 'a');
    $cwd = getcwd(); $locale = setlocale(LC_ALL, 0); $mask = umask();
    $result = cp(parseCommand('cp -v -- -a "<new name>"'), $base); same(0, $result['status']);
    check(strpos(coreutilsHtml($result), '&lt;new name&gt;') !== false);
    check(strpos(coreutilsText($result), ' -> ') !== false);
    same(0, cp(['args' => [$base . '/-a', $base . '/absolute']], $base)['status']);
    chdir($base); try { same(0, cp(parseCommand('cp -- -a default-cwd'))['status']); } finally { chdir($cwd); }
    same($cwd, getcwd()); same($locale, setlocale(LC_ALL, 0)); same($mask, umask());
});
$tests['cp refuses overwriting an earlier copy with a second source of the same name'] = fn() => fixture(function ($base) {
    mkdir($base . '/one'); mkdir($base . '/two'); mkdir($base . '/out');
    file_put_contents($base . '/one/a', 'one'); file_put_contents($base . '/two/a', 'two');
    same(1, cp(parseCommand('cp one/a two/a out'), $base)['status']); same('one', file_get_contents($base . '/out/a'));
    same('one', file_get_contents($base . '/one/a')); same('two', file_get_contents($base . '/two/a'));
});
