<?php

$tests['find parses single-dash predicates and long aliases'] = function () {
    foreach (['find . -type f,l', 'find --type=f,l .', 'find --type f,l .'] as $command) {
        $parsed = parseCommand($command);
        same([], $parsed['errors']);
        same(['.'], $parsed['args']);
        same(['type' => 'f,l'], $parsed['options']);
    }
    same('l', parseCommand('find -type f --type=d -type l')['options']['type']);
};
$tests['find rejects invalid predicates and types before traversal'] = function () {
    foreach (['find -type', 'find -type -- .', 'find -type ""', 'find -type x',
        'find -type f,', 'find -type ,f', 'find -type f,,l', 'find -type "f, l"',
        'find -type F', 'find -typef', 'find -name x', 'find -L'] as $command) {
        $result = find(parseCommand($command), '/does-not-exist');
        same(2, $result['status']);
        same([], $result['data']['entries']);
    }
    foreach ([['options' => ['type' => true]], ['options' => ['type' => "f\0"]],
        ['args' => ['']], ['args' => ['php://memory']], ['args' => ["bad\0name"]],
        parseCommand('ls')] as $input) {
        same(2, find($input)['status']);
    }
};
$tests['find defaults to dot and traverses hidden nested entries in order'] = fn() => fixture(function ($base) {
    mkdir($base . '/dir'); mkdir($base . '/dir/empty');
    file_put_contents($base . '/dir/item', 'x');
    file_put_contents($base . '/.hidden', 'x');
    $cwd = getcwd();
    ob_start();
    $result = find(parseCommand('find'), $base);
    same('', ob_get_clean());
    same($cwd, getcwd());
    same(0, $result['status']);
    $expected = ['.', './.hidden', './dir', './dir/empty', './dir/item'];
    $expected = array_map(fn($name) => str_replace('/', DIRECTORY_SEPARATOR, $name), $expected);
    same($expected, array_column($result['data']['entries'], 'name'));
    same(['d', 'f', 'd', 'd', 'f'], array_column($result['data']['entries'], 'type'));
    same(implode("\n", $expected) . "\n", coreutilsText($result));
    same(['./.hidden', './dir/item'], array_map(fn($entry) => str_replace(DIRECTORY_SEPARATOR, '/', $entry['name']),
        find(parseCommand('find -type f'), $base)['data']['entries']));
    same(3, count(find(parseCommand('find -type d'), $base)['data']['entries']));
    same([], find(parseCommand('find -type l'), $base)['data']['entries']);
    chdir($base);
    same($result['data'], find(parseCommand('find'))['data']);
});
$tests['find lists links including broken links without following directory cycles'] = fn() => fixture(function ($base) {
    symlinkSupport($base);
    mkdir($base . '/dir'); file_put_contents($base . '/file', 'x');
    symlink('../', $base . '/dir/cycle');
    symlink('missing', $base . '/broken'); symlink('file', $base . '/file-link');
    symlink('dir', $base . '/dir-link');
    same(7, count(find(parseCommand('find'), $base)['data']['entries']));
    foreach (['f' => 1, 'd' => 2, 'l' => 4, 'f,l' => 5, 'd,l' => 6, 'f,d,l' => 7, 'l,f,l' => 5] as $types => $count) {
        $result = find(['options' => ['type' => $types]], $base);
        same(0, $result['status']);
        same($count, count($result['data']['entries']));
    }
    $result = find(parseCommand('find dir-link broken -type l'), $base);
    same(['dir-link', 'broken'], array_column($result['data']['entries'], 'name'));
    same(1, find(parseCommand('find dir-link/'), $base)['status']);
});
$tests['find supports multiple roots absolute paths and partial failures'] = fn() => fixture(function ($base) {
    mkdir($base . '/empty'); file_put_contents($base . '/file', 'x');
    $result = find(['args' => ['missing', 'file', $base . '/empty']], $base);
    same(1, $result['status']); same(1, count($result['errors']));
    same(['file', $base . '/empty'], array_column($result['data']['entries'], 'name'));
    same(coreutilsResolvePath('file', $base), $result['data']['entries'][0]['path']);
    same(0, find(parseCommand('find empty/'), $base)['status']);
    same(1, find(parseCommand('find file/'), $base)['status']);
    same(1, find(parseCommand('find'), $base . '/missing')['status']);
    same(0, find(parseCommand('find file'), $base)['status']);
});
$tests['find handles quoted dash-prefixed names and escapes HTML'] = fn() => fixture(function ($base) {
    file_put_contents($base . '/-type', 'x');
    file_put_contents($base . '/two words', 'x');
    same(['-type'], array_column(find(parseCommand('find -- -type'), $base)['data']['entries'], 'name'));
    same('"two words"' . "\n", coreutilsText(find(parseCommand('find "two words"'), $base)));
    skipUnless(DIRECTORY_SEPARATOR !== '\\', 'Windows forbids angle brackets in filenames');
    file_put_contents($base . '/<tag>', 'x');
    $result = find(parseCommand('find "<tag>"'), $base);
    same('<tag>', $result['data']['entries'][0]['name']);
    check(strpos(coreutilsHtml($result), '&lt;tag&gt;') !== false);
    check(strpos(coreutilsHtml($result), '<tag>') === false);
});
$tests['find reports unreadable directories and continues other roots'] = fn() => fixture(function ($base) {
    mkdir($base . '/denied'); mkdir($base . '/good'); chmod($base . '/denied', 0000);
    skipUnless(coreutilsFsCall(fn() => scandir($base . '/denied')) === false, 'process can bypass filesystem permissions');
    $result = find(parseCommand('find denied good -type d'), $base);
    same(1, $result['status']);
    same(['denied', 'good'], array_column($result['data']['entries'], 'name'));
    same(1, count($result['errors']));
});
$tests['find help needs no filesystem access'] = function () {
    $result = find(parseCommand('find --help'), '/does-not-exist');
    same(0, $result['status']);
    check(strpos(coreutilsText($result), 'Usage: find') === 0);
};
