<?php

$tests['find parses single-dash predicates and long aliases'] = function () {
    foreach (['find . -type f,l', 'find . --type=f,l', 'find . --type f,l'] as $command) {
        $parsed = parseCommand($command);
        same([], $parsed['errors']);
        same(['.'], $parsed['args']);
        same(['type' => 'f,l'], $parsed['options']);
    }
    same(['f', 'd', 'l'], parseCommand('find -type f --type=d -type l')['options']['type']);
};
$tests['find rejects invalid predicates and types before traversal'] = function () {
    foreach (['find -type', 'find -type -- .', 'find -type ""', 'find -type x',
        'find -type f,', 'find -type ,f', 'find -type f,,l', 'find -type "f, l"',
        'find -type F', 'find -typef', 'find -name', 'find -name --', 'find -L'] as $command) {
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
$tests['find defaults to dot and traverses hidden nested entries in order'] = fn () => fixture(function ($base) {
    mkdir($base . '/dir');
    mkdir($base . '/dir/empty');
    file_put_contents($base . '/dir/item', 'x');
    file_put_contents($base . '/.hidden', 'x');
    $cwd = getcwd();
    ob_start();
    $result = find(parseCommand('find'), $base);
    same('', ob_get_clean());
    same($cwd, getcwd());
    same(0, $result['status']);
    $expected = ['.', './.hidden', './dir', './dir/empty', './dir/item'];
    $expected = array_map(fn ($name) => str_replace('/', DIRECTORY_SEPARATOR, $name), $expected);
    same($expected, array_column($result['data']['entries'], 'name'));
    same(['d', 'f', 'd', 'd', 'f'], array_column($result['data']['entries'], 'type'));
    same(implode("\n", $expected) . "\n", coreutilsText($result));
    same(['./.hidden', './dir/item'], array_map(
        fn ($entry) => str_replace(DIRECTORY_SEPARATOR, '/', $entry['name']),
        find(parseCommand('find -type f'), $base)['data']['entries']
    ));
    same(3, count(find(parseCommand('find -type d'), $base)['data']['entries']));
    same([], find(parseCommand('find -type l'), $base)['data']['entries']);
    chdir($base);
    same($result['data'], find(parseCommand('find'))['data']);
});
$tests['find lists links including broken links without following directory cycles'] = fn () => fixture(function ($base) {
    symlinkSupport($base);
    mkdir($base . '/dir');
    file_put_contents($base . '/file', 'x');
    symlink('../', $base . '/dir/cycle');
    symlink('missing', $base . '/broken');
    symlink('file', $base . '/file-link');
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
$tests['find supports multiple roots absolute paths and partial failures'] = fn () => fixture(function ($base) {
    mkdir($base . '/empty');
    file_put_contents($base . '/file', 'x');
    $result = find(['args' => ['missing', 'file', $base . '/empty']], $base);
    same(1, $result['status']);
    same(1, count($result['errors']));
    same(['file', $base . '/empty'], array_column($result['data']['entries'], 'name'));
    same(coreutilsResolvePath('file', $base), $result['data']['entries'][0]['path']);
    same(0, find(parseCommand('find empty/'), $base)['status']);
    same(1, find(parseCommand('find file/'), $base)['status']);
    same(1, find(parseCommand('find'), $base . '/missing')['status']);
    same(0, find(parseCommand('find file'), $base)['status']);
});
$tests['find handles quoted dash-prefixed names and escapes HTML'] = fn () => fixture(function ($base) {
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
$tests['find reports unreadable directories and continues other roots'] = fn () => fixture(function ($base) {
    mkdir($base . '/denied');
    mkdir($base . '/good');
    chmod($base . '/denied', 0000);
    skipUnless(coreutilsFsCall(fn () => scandir($base . '/denied')) === false, 'process can bypass filesystem permissions');
    $result = find(parseCommand('find denied good -type d'), $base);
    same(1, $result['status']);
    same(['denied', 'good'], array_column($result['data']['entries'], 'name'));
    same(1, count($result['errors']));
});
$tests['find help needs no filesystem access'] = function () {
    $result = find(parseCommand('find --help'), '/does-not-exist');
    same(0, $result['status']);
    check(strpos(coreutilsText($result), 'Usage: find') === 0);
    check(strpos($result['help'], '-name') !== false);
    check(strpos($result['help'], 'combined with AND') !== false);
    check(strpos($result['help'], 'last type option wins') === false);
};

$tests['find requires all starting paths before predicates'] = function () {
    foreach (['find -type d TESTE-c', 'find first -name "*" second',
        'find -type f -- -folder', 'find -name x . -type f',
        'find --type=f,l .', 'find --name=x .'] as $command) {
        $result = find(parseCommand($command), '/does-not-exist');
        same(2, $result['status']);
        same('unexpected-path', $result['errors'][0]['code']);
        same([], $result['data']['entries']);
    }
    foreach (['find a b -type d', 'find -type d', 'find -name "*" -type f',
        'find ./-folder -type f', 'find -- -folder', 'find . -type f --'] as $command) {
        same([], parseCommand($command)['errors']);
    }
    // Other commands still allow operands and options to be interspersed.
    same(['first', 'second'], parseCommand('ls first -l second')['args']);
    same(['logical' => true, 'physical' => true], parseCommand('pwd -LP')['options']);
};

$tests['find intersects repeated types instead of overwriting or joining them'] = fn () => fixture(function ($base) {
    mkdir($base . '/dir');
    file_put_contents($base . '/file', 'x');
    foreach (['find . -type f,d -type f', 'find . --type=f,d --type=f'] as $command) {
        $result = find(parseCommand($command), $base);
        same(0, $result['status']);
        same(['f'], array_column($result['data']['entries'], 'type'));
    }
    foreach (['find . -type f -type d', 'find . -type d -type f -type f'] as $command) {
        $result = find(parseCommand($command), $base);
        same(0, $result['status']);
        same([], $result['data']['entries']);
    }
    same(2, find(parseCommand('find . -type x -type f'), $base)['status']);
    same(2, find(parseCommand('find . -type f -type ""'), $base)['status']);
    symlinkSupport($base);
    symlink('file', $base . '/alias');
    same(['l'], array_column(find(parseCommand('find . -type f,l -type l'), $base)['data']['entries'], 'type'));
    same(['l', 'f'], array_column(find(parseCommand('find . -type f,l'), $base)['data']['entries'], 'type'));
});

$tests['find name matches basenames and traverses nonmatching directories'] = fn () => fixture(function ($base) {
    mkdir($base . '/unmatched');
    mkdir($base . '/folder.php');
    foreach (['.hidden.php', 'test1.php', 'test2.php', 'test3.txt', 'UPPER.PHP'] as $file) {
        file_put_contents($base . '/unmatched/' . $file, 'x');
    }
    $expected = ['.hidden.php', 'test1.php', 'test2.php'];
    foreach (['find . -type f -name "*.php"', 'find . -name "*.php" -type f',
        'find . --name="*.php" --type=f'] as $command) {
        $result = find(parseCommand($command), $base);
        same(0, $result['status']);
        same($expected, array_map(fn ($entry) => basename($entry['name']), $result['data']['entries']));
    }
    same(4, count(find(parseCommand('find . -name "*.php"'), $base)['data']['entries']));
    same([], find(parseCommand('find . -name "unmatched/*.php"'), $base)['data']['entries']);
    same([], find(parseCommand('find . -name ""'), $base)['data']['entries']);
    same(['.'], array_column(find(parseCommand('find . -name .'), $base)['data']['entries'], 'name'));
    same(['unmatched/'], array_column(find(parseCommand('find unmatched/ -name unmatched'), $base)['data']['entries'], 'name'));
});

$tests['find name supports wildcards sets escapes and repeated predicates'] = fn () => fixture(function ($base) {
    foreach (['test1.php', 'test2.php', 'testa.php', 'other.php', 'two words', '[tag]'] as $file) {
        file_put_contents($base . '/' . $file, 'x');
    }
    $matches = function ($command) use ($base) {
        $result = find(parseCommand($command), $base);
        same(0, $result['status']);
        return array_map(fn ($entry) => basename($entry['name']), $result['data']['entries']);
    };
    same(['test1.php', 'test2.php', 'testa.php'], $matches('find . -name "test?.php"'));
    same(['test1.php', 'test2.php'], $matches('find . -name "test[1-2].php"'));
    same(['testa.php'], $matches('find . -name "test[!0-9].php"'));
    same(['two words'], $matches('find . -name "two words"'));
    same(['[tag]'], $matches("find . -name '\\[tag\\]'"));
    same(['test1.php', 'test2.php', 'testa.php'], $matches('find . -name "*.php" --name="test*"'));
    same([], $matches('find . -name "*.php" -name "*.txt"'));
    same(['*.php', 'test*'], parseCommand('find . -name "*.php" -name "test*"')['options']['name']);
});

$tests['find name tests link names including broken links without following them'] = fn () => fixture(function ($base) {
    symlinkSupport($base);
    mkdir($base . '/real');
    file_put_contents($base . '/real/target.php', 'x');
    symlink('real', $base . '/alias.php');
    symlink('missing', $base . '/broken.php');
    symlink('real/target.php', $base . '/other.txt');
    $result = find(parseCommand('find . -type l -name "*.php"'), $base);
    same(0, $result['status']);
    same(['alias.php', 'broken.php'], array_map(fn ($entry) => basename($entry['name']), $result['data']['entries']));
    same(3, count(find(parseCommand('find . -name "*.php"'), $base)['data']['entries']));
});

$tests['find canonical predicates accept strings and nonempty arrays only'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/test.php', 'x');
    $input = ['options' => ['type' => ['f,d', 'f'], 'name' => ['*.php', 'test*']]];
    same(
        find(parseCommand('find -type f,d -name "*.php" -type f -name "test*"'), $base)['data'],
        find($input, $base)['data']
    );
    foreach (['type', 'name'] as $option) {
        foreach ([[], [null], [['f']], ['f', true], false, null, 1, "x\0y", ["x\0y"]] as $value) {
            $result = find(['options' => [$option => $value]], '/does-not-exist');
            same(2, $result['status']);
            same([], $result['data']['entries']);
        }
    }
});
