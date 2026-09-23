<?php
$tests['mv renames silently and returns structured paths'] = fn() => fixture(function ($base) {
    file_put_contents($base . '/old', 'content');
    ob_start();
    $result = mv(parseCommand('mv old new'), $base);
    same('', ob_get_clean());
    same(0, $result['status']);
    same('content', file_get_contents($base . '/new'));
    check(!file_exists($base . '/old'));
    same([['source' => $base . '/old', 'destination' => $base . '/new']], $result['data']['moved']);
    same('', coreutilsText($result));
});
$tests['mv moves multiple files and a populated directory'] = fn() => fixture(function ($base) {
    mkdir($base . '/out'); mkdir($base . '/tree');
    file_put_contents($base . '/tree/child', 'child'); file_put_contents($base . '/file', 'file');
    same(0, mv(parseCommand('mv file tree out'), $base)['status']);
    same('child', file_get_contents($base . '/out/tree/child'));
    same('file', file_get_contents($base . '/out/file'));
});
$tests['mv clobber precedence and verbose escaping'] = fn() => fixture(function ($base) {
    file_put_contents($base . '/a', 'new'); file_put_contents($base . '/b', 'old');
    $result = mv(parseCommand('mv -fn a b'), $base);
    same(0, $result['status']); same(1, count($result['data']['skipped']));
    same('old', file_get_contents($base . '/b'));
    same(0, mv(parseCommand('mv -nf a b'), $base)['status']);
    same('new', file_get_contents($base . '/b'));
    $result = mv(parseCommand('mv -v b "<new name>"'), $base);
    same(0, $result['status']); check(strpos(coreutilsText($result), ' -> ') !== false);
    check(strpos(coreutilsHtml($result), '&lt;new name&gt;') !== false);
});
$tests['mv default overwrites files and supports absolute paths'] = fn() => fixture(function ($base) {
    file_put_contents($base . '/a', 'a'); file_put_contents($base . '/b', 'b');
    same(0, mv(['args' => [$base . '/a', $base . '/b']], $base)['status']);
    same('a', file_get_contents($base . '/b'));
});
$tests['mv invalid input has no side effects and help needs no cwd'] = fn() => fixture(function ($base) {
    file_put_contents($base . '/a', 'a');
    foreach (['mv a', 'mv -z a b', 'mv a ""', 'mv a b https://example.com'] as $command) {
        same(2, mv(parseCommand($command), $base)['status']); check(file_exists($base . '/a'));
    }
    same(0, mv(parseCommand('mv --help'), '/missing')['status']);
    check(strpos(coreutilsText(mv(parseCommand('mv --help'))), 'Usage: mv') === 0);
});
$tests['mv errors preserve sources and continue independent operands'] = fn() => fixture(function ($base) {
    file_put_contents($base . '/a', 'a'); mkdir($base . '/out');
    foreach (['mv a a', 'mv a missing/b', 'mv a absent/', 'mv a missing out/file'] as $command) {
        same(1, mv(parseCommand($command), $base)['status']); check(file_exists($base . '/a'));
    }
    $result = mv(parseCommand('mv missing a out'), $base);
    same(1, $result['status']); same(1, count($result['data']['moved']));
    same('a', file_get_contents($base . '/out/a'));
});
$tests['mv rejects self descendants and preserves nonempty destinations'] = fn() => fixture(function ($base) {
    mkdir($base . '/tree'); mkdir($base . '/tree/sub'); file_put_contents($base . '/tree/a', 'a');
    same(1, mv(parseCommand('mv tree tree/sub'), $base)['status']);
    mkdir($base . '/out'); mkdir($base . '/out/tree'); file_put_contents($base . '/out/tree/b', 'b');
    same(1, mv(parseCommand('mv tree out'), $base)['status']);
    same('a', file_get_contents($base . '/tree/a')); same('b', file_get_contents($base . '/out/tree/b'));
});
$tests['mv preserves source links and no-clobber detects broken targets'] = fn() => fixture(function ($base) {
    symlinkSupport($base);
    symlink('missing', $base . '/broken');
    same(0, mv(parseCommand('mv broken renamed'), $base)['status']);
    same('missing', readlink($base . '/renamed'));
    file_put_contents($base . '/a', 'a');
    same(1, count(mv(parseCommand('mv -n a renamed'), $base)['data']['skipped']));
    same(0, mv(parseCommand('mv a renamed'), $base)['status']);
    check(!is_link($base . '/renamed')); same('a', file_get_contents($base . '/renamed'));
    mkdir($base . '/dir'); symlink('dir', $base . '/link');
    same(1, mv(parseCommand('mv link/ elsewhere'), $base)['status']);
    same(0, mv(parseCommand('mv link moved-link'), $base)['status']);
    check(is_dir($base . '/dir')); same('dir', readlink($base . '/moved-link'));
});
$tests['mv rejects descendant via link and accepts destination directory link'] = fn() => fixture(function ($base) {
    symlinkSupport($base); mkdir($base . '/tree'); mkdir($base . '/tree/sub'); symlink('tree/sub', $base . '/alias');
    same(1, mv(parseCommand('mv tree alias'), $base)['status']);
    file_put_contents($base . '/a', 'a'); same(0, mv(parseCommand('mv a alias'), $base)['status']);
    same('a', file_get_contents($base . '/tree/sub/a'));
});
$tests['mv refuses duplicate output names and supports --'] = fn() => fixture(function ($base) {
    mkdir($base . '/one'); mkdir($base . '/two'); mkdir($base . '/out');
    file_put_contents($base . '/one/a', 'one'); file_put_contents($base . '/two/a', 'two');
    same(1, mv(parseCommand('mv one/a two/a out'), $base)['status']);
    same('one', file_get_contents($base . '/out/a')); same('two', file_get_contents($base . '/two/a'));
    file_put_contents($base . '/-a', 'dash');
    same(0, mv(parseCommand('mv -- -a -b'), $base)['status']); same('dash', file_get_contents($base . '/-b'));
});
