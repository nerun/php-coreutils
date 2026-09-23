<?php
$tests['rm removes files silently and records absolute paths'] = fn() => fixture(function ($base) {
    file_put_contents($base . '/a', 'a'); file_put_contents($base . '/keep', 'keep');
    ob_start(); $result = rm(parseCommand('rm a'), $base); same('', ob_get_clean());
    same(0, $result['status']); same([$base . '/a'], $result['data']['removed']);
    same('', coreutilsText($result)); check(!file_exists($base . '/a')); same('keep', file_get_contents($base . '/keep'));
});
$tests['rm requires recursive for directories even with force'] = fn() => fixture(function ($base) {
    mkdir($base . '/dir'); file_put_contents($base . '/dir/a', 'a');
    foreach (['rm dir', 'rm -f dir'] as $command) same(1, rm(parseCommand($command), $base)['status']);
    same('a', file_get_contents($base . '/dir/a'));
});
$tests['rm recursive handles nested and hidden entries in child-first order'] = fn() => fixture(function ($base) {
    mkdir($base . '/dir'); mkdir($base . '/dir/sub'); mkdir($base . '/dir/empty');
    file_put_contents($base . '/dir/sub/a', 'a'); file_put_contents($base . '/dir/.hidden', 'h');
    $result = rm(parseCommand('rm -rv dir/'), $base); same(0, $result['status']);
    same(5, count($result['data']['removed'])); same($base . '/dir', end($result['data']['removed']));
    check(!file_exists($base . '/dir')); check(strpos(coreutilsText($result), 'removed ') === 0);
});
$tests['rm force ignores proven missing paths and no operands only'] = fn() => fixture(function ($base) {
    same(2, rm(parseCommand('rm'), $base)['status']); same(0, rm(parseCommand('rm -f'), '/missing')['status']);
    same(1, rm(parseCommand('rm missing'), $base)['status']);
    $result = rm(parseCommand('rm -f missing missing-parent/child'), $base);
    same(0, $result['status']); same(2, count($result['data']['skipped']));
    file_put_contents($base . '/file', 'x');
    same(1, rm(parseCommand('rm -f file/child'), $base)['status']); same('x', file_get_contents($base . '/file'));
});
$tests['removal validates all syntax before deleting anything and supports help'] = fn() => fixture(function ($base) {
    file_put_contents($base . '/a', 'a'); mkdir($base . '/empty');
    foreach (['rm' => 'rm', 'rmdir' => '_rmdir'] as $command => $function) {
        $operand = $command === 'rm' ? 'a' : 'empty';
        foreach ([' -z ' . $operand, ' ' . $operand . ' ""', ' ' . $operand . ' https://example.com'] as $suffix) {
            same(2, $function(parseCommand($command . $suffix), $base)['status']); check(file_exists($base . '/' . $operand));
        }
        same(2, $function(['args' => [$operand], 'options' => ['verbose' => 1]], $base)['status']);
        same(1, $function(parseCommand($command . ' ' . $operand), $base . '/absent')['status']);
        $result = $function(parseCommand($command . ' --help'), '/missing'); same(0, $result['status']);
        check(strpos(coreutilsText($result), 'Usage: ' . $command) === 0);
    }
});
$tests['removal continues after failures and preserves partial results'] = fn() => fixture(function ($base) {
    file_put_contents($base . '/a', 'a'); file_put_contents($base . '/b', 'b');
    $result = rm(parseCommand('rm a absent b'), $base); same(1, $result['status']); same(2, count($result['data']['removed']));
    mkdir($base . '/full'); file_put_contents($base . '/full/keep', 'keep'); mkdir($base . '/empty');
    $result = _rmdir(parseCommand('rmdir full empty'), $base); same(1, $result['status']); same([$base . '/empty'], $result['data']['removed']);
    same('keep', file_get_contents($base . '/full/keep'));
});
$tests['removal protects roots dots cwd and cwd ancestors before traversal'] = fn() => fixture(function ($base) {
    mkdir($base . '/sub'); file_put_contents($base . '/keep', 'keep');
    foreach (['rm -rf' => 'rm', 'rmdir' => '_rmdir'] as $command => $function) {
        foreach (['/', '.', '..', 'sub/..', $base, dirname($base)] as $path) {
            $result = $function(parseCommand($command . ' "' . $path . '"'), $base);
            same(1, $result['status']); same([], $result['data']['removed']);
        }
    }
    same('keep', file_get_contents($base . '/keep'));
});
$tests['rm never follows final symlinks including broken links and directory cycles'] = fn() => fixture(function ($base) {
    symlinkSupport($base); mkdir($base . '/keep'); file_put_contents($base . '/keep/a', 'a');
    symlink('keep', $base . '/link'); symlink('missing', $base . '/broken');
    same(1, rm(parseCommand('rm -rf link/'), $base)['status']); check(is_link($base . '/link'));
    same(0, rm(parseCommand('rm link broken'), $base)['status']); check(!is_link($base . '/link')); check(!is_link($base . '/broken'));
    mkdir($base . '/tree'); symlink('../keep', $base . '/tree/outside'); symlink('.', $base . '/tree/cycle');
    same(0, rm(parseCommand('rm -r tree'), $base)['status']); same('a', file_get_contents($base . '/keep/a'));
});
$tests['rmdir removes only empty real directories including trailing slash'] = fn() => fixture(function ($base) {
    mkdir($base . '/empty'); file_put_contents($base . '/file', 'keep');
    same(2, _rmdir(parseCommand('rmdir'), $base)['status']);
    same(1, _rmdir(parseCommand('rmdir file'), $base)['status']); same('keep', file_get_contents($base . '/file'));
    $result = _rmdir(parseCommand('rmdir empty/'), $base); same(0, $result['status']); same([$base . '/empty'], $result['data']['removed']);
});
$tests['rmdir rejects links with and without trailing slash'] = fn() => fixture(function ($base) {
    symlinkSupport($base); mkdir($base . '/empty'); symlink('empty', $base . '/link');
    foreach (['link', 'link/'] as $name) same(1, _rmdir(parseCommand('rmdir ' . $name), $base)['status']);
    check(is_dir($base . '/empty')); check(is_link($base . '/link'));
});
$tests['rmdir parents stops before cwd and reports a nonempty parent'] = fn() => fixture(function ($base) {
    mkdir($base . '/a'); mkdir($base . '/a/b'); mkdir($base . '/a/b/c');
    $result = _rmdir(parseCommand('rmdir -pv a/b/c'), $base); same(0, $result['status']);
    same([$base . '/a/b/c', $base . '/a/b', $base . '/a'], $result['data']['removed']); check(is_dir($base));
    mkdir($base . '/a'); mkdir($base . '/a/b'); file_put_contents($base . '/a/keep', 'keep');
    $result = _rmdir(parseCommand('rmdir -p a/b'), $base); same(1, $result['status']); same([$base . '/a/b'], $result['data']['removed']);
    same('keep', file_get_contents($base . '/a/keep'));
});
$tests['removal supports dash names absolute operands escaped output and default cwd'] = fn() => fixture(function ($base) {
    $cwd = getcwd(); $locale = setlocale(LC_ALL, 0); $mask = umask();
    file_put_contents($base . '/-a', 'a'); same(0, rm(parseCommand('rm -- -a'), $base)['status']);
    mkdir($base . '/<empty>'); $result = _rmdir(parseCommand('rmdir -v "<empty>"'), $base);
    same(0, $result['status']); check(strpos(coreutilsHtml($result), '&lt;empty&gt;') !== false);
    file_put_contents($base . '/absolute', 'a'); same(0, rm(['args' => [$base . '/absolute']], $base)['status']);
    mkdir($base . '/empty'); chdir($base);
    try { same(0, _rmdir(parseCommand('rmdir empty'))['status']); } finally { chdir($cwd); }
    same($cwd, getcwd()); same($locale, setlocale(LC_ALL, 0)); same($mask, umask());
});
